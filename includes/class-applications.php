<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Applications {
	const RETENTION_HOOK = 'llamahire_cleanup_expired_applications';
	const MAX_NAME_LENGTH = 190;
	const MAX_EMAIL_LENGTH = 190;
	const MAX_PHONE_LENGTH = 50;
	const MAX_COVER_LETTER_LENGTH = 10000;

	public static function workflow_statuses() {
		return array(
			'new'          => __( 'New', 'llamahire' ),
			'reviewing'    => __( 'Reviewing', 'llamahire' ),
			'interviewing' => __( 'Interviewing', 'llamahire' ),
			'offer'        => __( 'Offer', 'llamahire' ),
			'hired'        => __( 'Hired', 'llamahire' ),
			'rejected'     => __( 'Rejected', 'llamahire' ),
		);
	}

	public static function pipeline_statuses() {
		return array_diff_key( self::workflow_statuses(), array( 'rejected' => true ) );
	}

	public static function status_label( $status ) {
		$statuses = self::workflow_statuses();
		return $statuses[ $status ] ?? ucwords( str_replace( '_', ' ', sanitize_key( $status ) ) );
	}

	public static function register() {
		add_action( 'admin_post_nopriv_llamahire_apply', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_llamahire_apply', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_llamahire_resume', array( __CLASS__, 'download_resume' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
		add_action( self::RETENTION_HOOK, array( __CLASS__, 'run_retention_cleanup' ) );
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	public static function run_retention_cleanup() {
		return Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->cleanup_expired();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'llamahire_applications';
	}

	public static function submit() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		$nonce  = sanitize_text_field( wp_unslash( $_POST['llamahire_nonce'] ?? '' ) );
		if ( ! $job_id || ! wp_verify_nonce( $nonce, 'llamahire_apply_' . $job_id ) || Jobs::POST_TYPE !== get_post_type( $job_id ) || 'publish' !== get_post_status( $job_id ) || ! Jobs::is_open( $job_id ) ) {
			self::redirect( $job_id, 'invalid' );
		}
		$job_meta = Jobs::get_meta( $job_id );
		if ( 'internal' !== $job_meta['application_method'] ) {
			self::redirect( $job_id, 'invalid' );
		}

		// Honeypot: real candidates never see or fill this field.
		if ( ! empty( $_POST['company_website'] ) ) {
			self::redirect( $job_id, 'success' );
		}

		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone  = self::sanitize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		$letter = sanitize_textarea_field( wp_unslash( $_POST['cover_letter'] ?? '' ) );
		$fields = Settings::application_fields();
		if ( 'hidden' === $fields['phone'] ) {
			$phone = '';
		}
		if ( 'hidden' === $fields['cover_letter'] ) {
			$letter = '';
		}
		$submission_key = strtolower( sanitize_text_field( wp_unslash( $_POST['submission_key'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $submission_key ) ) {
			$submission_key = wp_generate_uuid4();
		}
		$file = 'hidden' !== $fields['resume'] && isset( $_FILES['resume'] ) && is_array( $_FILES['resume'] ) ? $_FILES['resume'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$validation_error = self::application_validation_error( compact( 'name', 'email', 'phone', 'letter' ), ! empty( $file['name'] ), $fields );
		if ( $validation_error ) {
			self::redirect( $job_id, $validation_error );
		}
		$repository  = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$duplicate_id = $repository->find_duplicate( $job_id, $email );
		if ( $duplicate_id && 'preserve' === self::duplicate_policy( $job_id, $email, $duplicate_id ) ) {
			do_action( 'llamahire_duplicate_application_ignored', $duplicate_id, $job_id );
			self::redirect( $job_id, 'duplicate' );
		}
		if ( ! self::consume_submission_limit( $job_id ) ) {
			self::redirect( $job_id, 'rate_limited' );
		}

		$storage = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE );
		$resume  = $storage->store_upload( $file, $job_id );
		if ( is_wp_error( $resume ) ) {
			self::redirect( $job_id, $resume->get_error_code() );
		}

		$application = array(
			'job_id'       => $job_id,
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'cover_letter' => $letter,
			'resume_token' => $resume['token'],
			'resume_name'  => $resume['name'],
			'status'       => 'new',
			'submission_key' => $submission_key,
		);
		$creation       = $repository->create_once( $application );
		if ( is_wp_error( $creation ) ) {
			if ( $resume['token'] ) {
				$storage->delete( $resume['token'] );
			}
			self::redirect( $job_id, 'error' );
		}
		$application_id = $creation['id'];
		if ( ! $creation['created'] ) {
			if ( $resume['token'] ) {
				$storage->delete( $resume['token'] );
			}
			do_action( 'llamahire_duplicate_submission_ignored', $application_id, $job_id );
			if ( 'job_email' === ( $creation['reason'] ?? '' ) ) {
				do_action( 'llamahire_duplicate_application_ignored', $application_id, $job_id );
				self::redirect( $job_id, 'duplicate' );
			}
			self::redirect( $job_id, 'success' );
		}

		$application['id'] = $application_id;
		$notification = Plugin::instance()->services()->get( Service_IDs::NOTIFICATIONS )->application_received( $application, $job_id );
		$recorded = $repository->record_notification_result( $application_id, $notification );
		if ( is_wp_error( $recorded ) || ! $recorded ) {
			do_action( 'llamahire_notification_result_not_recorded', $application_id );
		}
		self::redirect( $job_id, 'success' );
	}

	public static function required_fields_are_valid( array $values, $has_resume, array $fields = array() ) {
		return '' === self::application_validation_error( $values, $has_resume, $fields );
	}

	public static function application_validation_error( array $values, $has_resume, array $fields = array() ) {
		$fields = $fields ? $fields : Settings::application_fields();
		if ( empty( $values['name'] ) || ! is_email( $values['email'] ?? '' ) ) {
			return 'required';
		}
		if ( self::text_length( $values['name'] ) > self::MAX_NAME_LENGTH || self::text_length( $values['email'] ) > self::MAX_EMAIL_LENGTH ) {
			return 'invalid_fields';
		}
		if ( 'required' === ( $fields['phone'] ?? 'optional' ) && empty( $values['phone'] ) ) {
			return 'required';
		}
		if ( ! empty( $values['phone'] ) && ! self::phone_is_valid( $values['phone'] ) ) {
			return 'invalid_phone';
		}
		if ( 'required' === ( $fields['cover_letter'] ?? 'optional' ) && empty( $values['letter'] ) ) {
			return 'required';
		}
		if ( self::text_length( $values['letter'] ?? '' ) > self::MAX_COVER_LETTER_LENGTH ) {
			return 'invalid_fields';
		}
		return ( 'required' !== ( $fields['resume'] ?? 'optional' ) || $has_resume ) ? '' : 'required';
	}

	public static function sanitize_phone( $value ) {
		$phone = trim( sanitize_text_field( $value ) );
		return preg_replace( '/\s+/u', ' ', $phone );
	}

	public static function phone_is_valid( $value ) {
		$phone = self::sanitize_phone( $value );
		if ( '' === $phone ) {
			return true;
		}
		return self::text_length( $phone ) <= self::MAX_PHONE_LENGTH
			&& 1 === preg_match( '/^\+?[\d\s().\/-]*\d[\d\s().\/-]*(?:\s*(?:x|ext\.?|extension|#)\s*\d+)?$/i', $phone );
	}

	public static function candidate_key( $job_id, $email ) {
		$job_id = absint( $job_id );
		$email  = strtolower( sanitize_email( $email ) );
		return $job_id && is_email( $email ) ? hash( 'sha256', $job_id . '|' . $email ) : '';
	}

	public static function duplicate_policy( $job_id, $email, $existing_id = 0 ) {
		/**
		 * Filters how a repeated job/email application is handled.
		 *
		 * Return "preserve" to keep the original application or "allow" to create
		 * another record. Submission-key retries always remain idempotent.
		 *
		 * @param string $policy      Duplicate policy.
		 * @param int    $job_id      Job post ID.
		 * @param string $email       Sanitized candidate email.
		 * @param int    $existing_id Canonical existing application ID, if known.
		 */
		$policy = sanitize_key( apply_filters( 'llamahire_duplicate_application_policy', 'preserve', absint( $job_id ), strtolower( sanitize_email( $email ) ), absint( $existing_id ) ) );
		return in_array( $policy, array( 'preserve', 'allow' ), true ) ? $policy : 'preserve';
	}

	public static function download_resume() {
		$id = absint( $_GET['application'] ?? 0 );
		check_admin_referer( 'llamahire_resume_' . $id );
		if ( ! Ownership::user_can_access_application( $id, Capabilities::DOWNLOAD_RESUMES ) ) {
			wp_die( esc_html__( 'You cannot access this resume.', 'llamahire' ), 403 );
		}
		$application = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $id );
		if ( $application ) {
			Audit_Log::record( 'application_resume_accessed', $application->job_id, $id );
		}
		$result = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE )->stream( $id );
		wp_die( esc_html( $result->get_error_message() ), 404 );
	}

	public static function site_health_tests( $tests ) {
		$tests['direct']['llamahire_resume_storage'] = array( 'label' => __( 'LlamaHire private resume storage', 'llamahire' ), 'test' => array( __CLASS__, 'resume_storage_health' ) );
		return $tests;
	}

	public static function resume_storage_health() {
		$health = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE )->health();
		$good   = $health['available'] && $health['outside_webroot'];
		if ( $good ) {
			$description = __( 'Resume storage is writable and located outside the public WordPress web root.', 'llamahire' );
		} elseif ( $health['available'] ) {
			$description = __( 'Resume storage is writable, but the host may require an explicit server rule because the private directory is inside the web root.', 'llamahire' );
		} else {
			$description = __( 'WordPress cannot write to the configured private resume directory.', 'llamahire' );
		}
		return array(
			'label'       => $good ? __( 'Resumes use private storage outside the web root', 'llamahire' ) : __( 'Resume storage needs attention', 'llamahire' ),
			'status'      => $good ? 'good' : ( $health['available'] ? 'recommended' : 'critical' ),
			'badge'       => array( 'label' => __( 'LlamaHire', 'llamahire' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'llamahire_resume_storage',
		);
	}

	public static function consume_submission_limit( $job_id, $client = '' ) {
		$job_id = absint( $job_id );
		if ( ! $job_id ) {
			return false;
		}
		if ( '' === $client ) {
			$client = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		}
		$window = max( MINUTE_IN_SECONDS, absint( apply_filters( 'llamahire_submission_rate_window', HOUR_IN_SECONDS, $job_id ) ) );
		$limits = array(
			'client' => max( 0, absint( apply_filters( 'llamahire_submission_rate_limit', 5, $job_id ) ) ),
			'job'    => max( 0, absint( apply_filters( 'llamahire_job_submission_rate_limit', 100, $job_id ) ) ),
		);
		$identifiers = array(
			'client' => hash_hmac( 'sha256', (string) $client, wp_salt( 'nonce' ) ),
			'job'    => 'all',
		);
		foreach ( $limits as $scope => $limit ) {
			if ( 0 === $limit ) {
				continue;
			}
			$key   = 'llamahire_rate_' . md5( get_current_blog_id() . '|' . $job_id . '|' . $scope . '|' . $identifiers[ $scope ] );
			$count = absint( get_transient( $key ) );
			if ( $count >= $limit ) {
				return false;
			}
			set_transient( $key, $count + 1, $window );
		}
		return true;
	}

	private static function redirect( $job_id, $result ) {
		$url = wp_get_referer() ?: get_permalink( $job_id );
		wp_safe_redirect( add_query_arg( 'application', sanitize_key( $result ), $url ) . '#llamahire-application' );
		exit;
	}

	private static function text_length( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
