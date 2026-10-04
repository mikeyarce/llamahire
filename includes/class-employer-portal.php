<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Account-required, front-end job submission and author-scoped job management.
 */
final class Employer_Portal {
	public static function register() {
		add_shortcode( 'llamahire_submit_job', array( __CLASS__, 'submit_job_shortcode' ) );
		add_shortcode( 'llamahire_my_jobs', array( __CLASS__, 'my_jobs_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_filter( 'the_content', array( __CLASS__, 'preview_controls' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_pages' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- Only the restricted frontend Employer role is affected; administrators are explicitly excluded.
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin_access' ), 1 );
	}

	public static function my_jobs_url( array $arguments = array() ) {
		$settings = Settings::get();
		$url      = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		return $arguments ? add_query_arg( $arguments, $url ) : $url;
	}

	public static function is_frontend_employer( $user = null ) {
		$user = $user instanceof \WP_User ? $user : wp_get_current_user();
		return $user->exists() && in_array( Capabilities::EMPLOYER_ROLE, (array) $user->roles, true ) && ! $user->has_cap( 'manage_options' );
	}

	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		return $user instanceof \WP_User && self::is_frontend_employer( $user ) ? self::my_jobs_url() : $redirect_to;
	}

	public static function show_admin_bar( $show ) {
		return self::is_frontend_employer() ? false : $show;
	}

	public static function restrict_admin_access() {
		if ( ! self::is_frontend_employer() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$script = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) );
		if ( in_array( $script, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		$arguments = array();
		// Preserve useful context from legacy candidate links while moving the account to the frontend portal.
		if ( 'llamahire-applications' === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect context.
			$arguments['employer_view'] = 'applications';
			$application_id = absint( $_GET['application'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect context.
			$job_id         = absint( $_GET['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect context.
			if ( $application_id && Ownership::user_can_access_application( $application_id, Capabilities::VIEW_APPLICATIONS ) ) {
				$arguments['application_id'] = $application_id;
			}
			if ( $job_id && Ownership::user_can_manage_job( $job_id ) ) {
				$arguments['job_id'] = $job_id;
			}
		}
		wp_safe_redirect( self::my_jobs_url( $arguments ) );
		exit;
	}

	public static function settings_updated( $old_value, $new_value ) {
		if ( Settings::SITE_MODE_JOB_BOARD === Settings::sanitize_site_mode( $new_value['site_mode'] ?? '' ) ) {
			self::ensure_pages( (array) $new_value );
		}
	}

	public static function maybe_ensure_pages() {
		if ( current_user_can( 'manage_options' ) && Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() ) {
			self::ensure_pages( Settings::get() );
		}
	}

	private static function ensure_pages( array $settings ) {
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'submit_job_page_id' => array( __( 'Submit a Job', 'llamahire' ), '[llamahire_submit_job]' ), 'my_jobs_page_id' => array( __( 'My Jobs', 'llamahire' ), '[llamahire_my_jobs]' ) ) as $key => $page ) {
			if ( Settings::public_page( $settings[ $key ] ?? 0 ) ) {
				continue;
			}
			$existing = get_page_by_path( sanitize_title( $page[0] ), OBJECT, 'page' );
			$shortcode_tag = trim( $page[1], '[]' );
			$page_id = $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, $shortcode_tag ) ? $existing->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page[0], 'post_content' => $page[1] ) );
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$settings[ $key ] = absint( $page_id );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
		}
	}

	public static function handle_request() {
		$action = sanitize_key( wp_unslash( $_POST['llamahire_employer_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The selected handler verifies its action-specific nonce before processing data.
		if ( ! in_array( $action, array( 'save_job', 'submit_preview_job', 'renew_job', 'relist_job', 'duplicate_job', 'close_job', 'delete_job' ), true ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			wp_die( esc_html__( 'You cannot manage job listings.', 'llamahire' ), 403 );
		}
		if ( 'save_job' === $action ) {
			self::save_job();
		}
		if ( 'submit_preview_job' === $action ) {
			self::submit_preview_job();
		}
		if ( 'renew_job' === $action ) {
			self::renew_job();
		}
		if ( 'relist_job' === $action ) {
			self::relist_job();
		}
		if ( 'duplicate_job' === $action ) {
			self::duplicate_job();
		}
		if ( 'close_job' === $action ) {
			self::close_job();
		}
		if ( 'delete_job' === $action ) {
			self::delete_job();
		}
	}

	public static function preview_controls( $content ) {
		if ( is_admin() || ! is_preview() || ! is_singular( Jobs::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || false !== strpos( $content, 'llamahire-job-preview' ) ) {
			return $content;
		}
		$job_id = get_the_ID();
		$job    = get_post( $job_id );
		if ( ! $job || 'draft' !== $job->post_status || (int) $job->post_author !== get_current_user_id() || ! Ownership::user_can_manage_job( $job_id ) ) {
			return $content;
		}
		$settings   = Settings::get();
		$edit_url   = Settings::public_page( $settings['submit_job_page_id'] ) ? add_query_arg( 'job_id', $job_id, get_permalink( $settings['submit_job_page_id'] ) ) : '';
		$my_jobs_url = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		ob_start();
		?>
		<section class="llamahire-job-preview" aria-labelledby="llamahire-job-preview-title">
			<nav class="llamahire-job-preview__breadcrumb" aria-label="<?php esc_attr_e( 'Job preview', 'llamahire' ); ?>"><a href="<?php echo esc_url( $my_jobs_url ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to My Jobs', 'llamahire' ); ?></a></nav>
			<div class="llamahire-job-preview__body">
				<div class="llamahire-job-preview__copy">
					<strong class="llamahire-job-preview__label"><?php esc_html_e( 'Preview', 'llamahire' ); ?></strong>
					<h2 id="llamahire-job-preview-title"><?php esc_html_e( 'Review your job listing', 'llamahire' ); ?></h2>
					<p><?php esc_html_e( 'This is how your listing will appear to candidates. It is still a draft and is not public yet.', 'llamahire' ); ?></p>
				</div>
				<div class="llamahire-job-preview__actions">
					<form method="post"><input type="hidden" name="llamahire_employer_action" value="submit_preview_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>"><?php wp_nonce_field( 'llamahire_employer_submit_preview_' . $job_id, 'llamahire_employer_nonce' ); ?><button type="submit"><?php esc_html_e( 'Submit for review', 'llamahire' ); ?></button></form>
					<?php if ( $edit_url ) : ?><a class="llamahire-job-preview__edit" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Continue editing', 'llamahire' ); ?></a><?php endif; ?>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean() . $content;
	}

	private static function save_job() {
		check_admin_referer( 'llamahire_employer_save_job', 'llamahire_employer_nonce' );
		$job_id     = absint( $_POST['job_id'] ?? 0 );
		$intent     = sanitize_key( self::posted_text( 'job_intent', 'submit' ) );
		$intent     = in_array( $intent, array( 'draft', 'preview', 'submit' ), true ) ? $intent : 'submit';
		if ( $job_id && ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'You cannot edit this job listing.', 'llamahire' ), 403 );
		}
		$title        = self::posted_text( 'job_title' );
		$content      = wp_kses_post( wp_unslash( $_POST['job_description'] ?? '' ) );
		$excerpt      = sanitize_textarea_field( wp_unslash( $_POST['job_excerpt'] ?? '' ) );
		$method       = sanitize_key( self::posted_text( 'application_method', 'internal' ) );
		$target_raw   = self::posted_text( 'application_target' );
		$employment_types = Jobs::employment_types();
		$employment_type  = sanitize_title( self::posted_text( 'employment_type' ) );
		$employment_type  = isset( $employment_types[ $employment_type ] ) ? $employment_type : '';
		$current_meta = $job_id ? Jobs::get_meta( $job_id ) : Jobs::defaults();
		$target_invalid = '' !== $target_raw && '' === Jobs::sanitize_application_target( $method, $target_raw );
		$target_error   = $target_invalid ? ( 'external_url' === $method ? 'application_url' : 'application_email' ) : '';
		$method_to_save = $target_invalid ? $current_meta['application_method'] : $method;
		$target_to_save = $target_invalid ? $current_meta['application_target'] : $target_raw;
		$meta         = Jobs::sanitize_meta(
			array_merge(
				$current_meta,
				array(
					'organization_name'    => self::posted_text( 'organization_name' ),
					'organization_tagline' => self::posted_text( 'organization_tagline' ),
					'organization_url'     => self::posted_text( 'organization_url' ),
					// The old free-form display location is retained as a compatibility fallback,
					// but new employer submissions use the structured location fields below.
					'location'             => $current_meta['location'],
					'workplace'            => self::posted_text( 'workplace', 'onsite' ),
					'employment_type'      => $employment_type,
					'address_street'       => self::posted_text( 'address_street' ),
					'address_locality'     => self::posted_text( 'address_locality' ),
					'address_region'       => self::posted_text( 'address_region' ),
					'postal_code'          => self::posted_text( 'postal_code' ),
					'address_country'      => self::posted_text( 'address_country' ),
					'applicant_countries'  => self::posted_text( 'applicant_countries' ),
					'salary_min'           => self::posted_text( 'salary_min' ),
					'salary_max'           => self::posted_text( 'salary_max' ),
					'salary_currency'      => self::posted_text( 'salary_currency' ),
					'salary_unit'          => self::posted_text( 'salary_unit', 'YEAR' ),
					'deadline'             => self::posted_text( 'deadline' ),
					'application_method'   => $method_to_save,
					'application_target'   => $target_to_save,
					'closed'               => '0',
				)
			)
		);
		if ( ! $title ) {
			self::redirect( array( 'job_error' => 'title', 'job_id' => $job_id ) );
		}
		$logo_validation = self::validate_logo_upload();
		$postarr = array(
			'ID'           => $job_id,
			'post_type'    => Jobs::POST_TYPE,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
		);
		if ( ! $job_id ) {
			$postarr['post_author'] = get_current_user_id();
		}
		$saved_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $saved_id ) ) {
			self::redirect( array( 'job_error' => 'save', 'job_id' => $job_id ) );
		}
		Jobs::set_meta( $saved_id, $meta );
		$department_ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['department_ids'] ?? array() ) ) ) );
		wp_set_object_terms( $saved_id, $department_ids, Jobs::DEPARTMENT_TAXONOMY );
		wp_set_object_terms( $saved_id, $employment_type ? array( $employment_type ) : array(), Jobs::TYPE_TAXONOMY );
		if ( ! empty( $_POST['remove_organization_logo'] ) ) {
			Jobs::set_meta( $saved_id, array( 'organization_logo' => '', 'organization_logo_id' => 0 ) );
		}
		if ( is_wp_error( $logo_validation ) ) {
			self::redirect( array( 'job_error' => 'logo', 'job_id' => $saved_id ) );
		}
		if ( true === $logo_validation ) {
			$attachment_id = self::store_logo_upload( $saved_id );
			if ( is_wp_error( $attachment_id ) ) {
				self::redirect( array( 'job_error' => 'logo', 'job_id' => $saved_id ) );
			}
			Jobs::set_meta( $saved_id, array( 'organization_logo' => wp_get_attachment_url( $attachment_id ), 'organization_logo_id' => $attachment_id ) );
		}
		if ( $target_error ) {
			self::redirect( array( 'job_error' => $target_error, 'job_id' => $saved_id ) );
		}
		$error = self::submission_error( $intent, $title, $content, $meta );
		if ( $error ) {
			self::redirect( array( 'job_error' => $error, 'job_id' => $saved_id ) );
		}
		if ( 'submit' === $intent ) {
			$was_submitted = self::job_was_submitted( $saved_id );
			if ( self::listing_limit_reached( $saved_id ) ) {
				self::redirect( array( 'job_error' => 'listing_limit', 'job_id' => $saved_id ) );
			}
			if ( ! $meta['listing_expires'] ) {
				$duration = Settings::listing_duration_days( Settings::get()['listing_duration_days'] );
				if ( $duration ) {
					$meta['listing_expires'] = current_datetime()->modify( '+' . $duration . ' days' )->format( 'Y-m-d' );
					Jobs::set_meta( $saved_id, array( 'listing_expires' => $meta['listing_expires'] ) );
				}
			}
			wp_update_post( array( 'ID' => $saved_id, 'post_status' => 'pending' ) );
			Employer_Notifications::job_submitted( $saved_id, $was_submitted );
			Audit_Log::record( $was_submitted ? 'job_resubmitted' : 'job_submitted', $saved_id );
		}
		if ( 'preview' === $intent ) {
			wp_safe_redirect( get_preview_post_link( $saved_id ) );
			exit;
		}
		$settings = Settings::get();
		$url = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : ( wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'submit' === $intent ? 'job_submitted' : 'job_draft_saved', 1, $url ) );
		exit;
	}

	private static function submit_preview_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_submit_preview_' . $job_id, 'llamahire_employer_nonce' );
		$job = get_post( $job_id );
		if ( ! $job || Jobs::POST_TYPE !== $job->post_type || 'draft' !== $job->post_status || ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'This job listing cannot be submitted.', 'llamahire' ), 403 );
		}
		$meta  = Jobs::get_meta( $job_id );
		$error = self::saved_submission_error( $job, $meta );
		if ( $error ) {
			self::job_editor_redirect( $job_id, array( 'job_error' => $error ) );
		}
		if ( self::listing_limit_reached( $job_id ) ) {
			self::job_editor_redirect( $job_id, array( 'job_error' => 'listing_limit' ) );
		}
		if ( ! $meta['listing_expires'] ) {
			$duration = Settings::listing_duration_days( Settings::get()['listing_duration_days'] );
			if ( $duration ) {
				$meta['listing_expires'] = current_datetime()->modify( '+' . $duration . ' days' )->format( 'Y-m-d' );
				Jobs::set_meta( $job_id, array( 'listing_expires' => $meta['listing_expires'] ) );
			}
		}
		$was_submitted = self::job_was_submitted( $job_id );
		wp_update_post( array( 'ID' => $job_id, 'post_status' => 'pending' ) );
		Employer_Notifications::job_submitted( $job_id, $was_submitted );
		Audit_Log::record( $was_submitted ? 'job_resubmitted' : 'job_submitted', $job_id );
		self::my_jobs_redirect( array( 'job_submitted' => 1 ) );
	}

	private static function job_was_submitted( $job_id ) {
		$history = Audit_Log::search(
			array(
				'job_id'      => absint( $job_id ),
				'event_types' => array( 'job_submitted', 'job_resubmitted' ),
				'per_page'    => 1,
			)
		);
		return ! empty( $history['total'] );
	}

	private static function saved_submission_error( $job, array $meta ) {
		if ( ! $job->post_title ) {
			return 'title';
		}
		if ( ! trim( wp_strip_all_tags( $job->post_content ) ) || ! $meta['organization_name'] ) {
			return 'required';
		}
		if ( ! $meta['application_target'] ) {
			return 'external_url' === $meta['application_method'] ? 'application_url' : 'application_email';
		}
		if ( $meta['deadline'] && $meta['deadline'] < current_time( 'Y-m-d' ) ) {
			return 'deadline';
		}
		if ( 'remote' === $meta['workplace'] ) {
			return $meta['applicant_countries'] ? '' : 'location';
		}
		return $meta['address_locality'] && $meta['address_country'] ? '' : 'location';
	}

	private static function submission_error( $intent, $title, $content, array $meta ) {
		if ( ! $title ) {
			return 'title';
		}
		if ( 'draft' === $intent ) {
			return '';
		}
		if ( ! trim( wp_strip_all_tags( $content ) ) || ! $meta['organization_name'] ) {
			return 'required';
		}
		if ( ! $meta['application_target'] ) {
			return 'external_url' === $meta['application_method'] ? 'application_url' : 'application_email';
		}
		$raw_deadline = self::posted_text( 'deadline' );
		if ( $raw_deadline && ( ! $meta['deadline'] || $meta['deadline'] < current_time( 'Y-m-d' ) ) ) {
			return 'deadline';
		}
		$raw_salary = array_filter(
			array(
				self::posted_text( 'salary_min' ),
				self::posted_text( 'salary_max' ),
			)
		);
		if ( $raw_salary && '' === $meta['salary_min'] && '' === $meta['salary_max'] ) {
			return 'salary';
		}
		if ( 'remote' === $meta['workplace'] ) {
			return $meta['applicant_countries'] ? '' : 'location';
		}
		return $meta['address_locality'] && $meta['address_country'] ? '' : 'location';
	}

	private static function posted_text( $key, $default = '' ) {
		$value = $_POST[ $key ] ?? $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called only after the save-job nonce is verified; sanitized below.
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * Return true for an upload, false for no upload, or an error for an unsafe file.
	 */
	private static function validate_logo_upload() {
		if ( empty( $_FILES['organization_logo'] ) || ! is_array( $_FILES['organization_logo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The save-job nonce is verified before this helper is called.
			return false;
		}
		$file = $_FILES['organization_logo']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified by the caller and the file is validated below.
		$error = absint( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return false;
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || empty( $file['name'] ) || absint( $file['size'] ?? 0 ) > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'llamahire_logo_upload', __( 'Upload a JPG, PNG, GIF, or WebP image smaller than 2 MB.', 'llamahire' ) );
		}
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), self::logo_mimes() );
		return ! empty( $checked['ext'] ) && ! empty( $checked['type'] ) ? true : new \WP_Error( 'llamahire_logo_type', __( 'Upload a valid JPG, PNG, GIF, or WebP image.', 'llamahire' ) );
	}

	private static function store_logo_upload( $job_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		return media_handle_upload( 'organization_logo', absint( $job_id ), array(), array( 'test_form' => false, 'mimes' => self::logo_mimes() ) );
	}

	private static function logo_mimes() {
		return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
	}

	private static function close_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_close_job_' . $job_id, 'llamahire_employer_nonce' );
		if ( ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'You cannot close this job listing.', 'llamahire' ), 403 );
		}
		Jobs::set_meta( $job_id, array( 'closed' => '1' ) );
		Audit_Log::record( 'job_closed', $job_id );
		self::my_jobs_redirect( array( 'job_closed' => 1 ) );
	}

	private static function renew_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_renew_job_' . $job_id, 'llamahire_employer_nonce' );
		$duration = Settings::listing_duration_days( Settings::get()['listing_duration_days'] );
		if ( ! Ownership::user_can_manage_job( $job_id ) || ! $duration || ! Jobs::listing_expires_soon( $job_id ) ) {
			wp_die( esc_html__( 'This job listing cannot be renewed.', 'llamahire' ), 403 );
		}
		$meta       = Jobs::get_meta( $job_id );
		$new_expiry = \DateTimeImmutable::createFromFormat( '!Y-m-d', $meta['listing_expires'], wp_timezone() )->modify( '+' . $duration . ' days' )->format( 'Y-m-d' );
		Jobs::set_meta( $job_id, array( 'listing_expires' => $new_expiry ) );
		Audit_Log::record( 'job_renewed', $job_id );
		self::my_jobs_redirect( array( 'job_renewed' => 1 ) );
	}

	private static function relist_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_relist_job_' . $job_id, 'llamahire_employer_nonce' );
		$post = get_post( $job_id );
		$meta = Jobs::get_meta( $job_id );
		if ( ! Ownership::user_can_manage_job( $job_id ) || ! $post || 'publish' !== $post->post_status || '1' === $meta['closed'] || Jobs::is_open( $job_id ) ) {
			wp_die( esc_html__( 'This job listing cannot be relisted.', 'llamahire' ), 403 );
		}
		if ( self::listing_limit_reached( $job_id ) ) {
			self::my_jobs_redirect( array( 'job_action_error' => 'listing_limit' ) );
		}
		$updates = array( 'closed' => '0', 'featured' => '0', 'listing_expires' => '' );
		if ( $meta['deadline'] && $meta['deadline'] < current_time( 'Y-m-d' ) ) {
			$updates['deadline'] = '';
		}
		Jobs::set_meta( $job_id, $updates );
		wp_update_post( array( 'ID' => $job_id, 'post_status' => 'draft' ) );
		Audit_Log::record( 'job_relist_started', $job_id );
		self::job_editor_redirect( $job_id, array( 'job_relisting' => 1 ) );
	}

	private static function duplicate_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_duplicate_job_' . $job_id, 'llamahire_employer_nonce' );
		if ( ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'You cannot duplicate this job listing.', 'llamahire' ), 403 );
		}
		$new_id = Jobs::duplicate_as_draft( $job_id, get_current_user_id() );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html__( 'The job listing could not be duplicated.', 'llamahire' ), 500 );
		}
		Audit_Log::record( 'job_duplicated', $new_id, 0, (string) $job_id, 'draft' );
		self::job_editor_redirect( $new_id, array( 'job_duplicated' => 1 ) );
	}

	private static function delete_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_delete_job_' . $job_id, 'llamahire_employer_nonce' );
		if ( ! Ownership::user_can_manage_job( $job_id ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm_delete_job'] ?? '' ) ) ) {
			wp_die( esc_html__( 'You cannot delete this job listing.', 'llamahire' ), 403 );
		}
		Audit_Log::record( 'job_deleted', $job_id );
		if ( ! wp_trash_post( $job_id ) ) {
			wp_die( esc_html__( 'The job listing could not be deleted.', 'llamahire' ), 500 );
		}
		self::my_jobs_redirect( array( 'job_deleted' => 1 ) );
	}

	private static function my_jobs_redirect( array $args ) {
		$settings = Settings::get();
		$url = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private static function job_editor_redirect( $job_id, array $args = array() ) {
		$settings = Settings::get();
		$url = Settings::public_page( $settings['submit_job_page_id'] ) ? get_permalink( $settings['submit_job_page_id'] ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( array_merge( array( 'job_id' => absint( $job_id ) ), $args ), $url ) );
		exit;
	}

	private static function redirect( array $args ) {
		$settings = Settings::get();
		$url = Settings::public_page( $settings['submit_job_page_id'] ) ? get_permalink( $settings['submit_job_page_id'] ) : self::current_url();
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'job_error', 'job_submitted', 'job_draft_saved', 'job_closed', 'job_relisting', 'job_duplicated' ), $url ) ) );
		exit;
	}

	private static function access_message() {
		if ( ! is_user_logged_in() ) {
			/* translators: 1: sign-in URL, 2: employer-registration URL. */
			return '<p class="llamahire-notice">' . wp_kses_post( sprintf( __( 'Please <a href="%1$s">sign in</a> or <a href="%2$s">register as an employer</a> to manage job listings.', 'llamahire' ), esc_url( wp_login_url( self::current_url() ) ), esc_url( Employer_Registration::registration_url() ) ) ) . '</p>';
		}
		return '<p class="llamahire-notice">' . esc_html__( 'Your account cannot manage job listings. Contact the job-board operator.', 'llamahire' ) . '</p>';
	}

	public static function submit_job_shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			return self::access_message();
		}
		$job_id = absint( $_GET['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only portal selection; ownership is checked below.
		if ( $job_id && ! Ownership::user_can_manage_job( $job_id ) ) {
			return '<p class="llamahire-notice">' . esc_html__( 'Job listing not found.', 'llamahire' ) . '</p>';
		}
		$post = $job_id ? get_post( $job_id ) : null;
		$meta = $job_id ? Jobs::get_meta( $job_id ) : self::latest_employer_defaults();
		$error = sanitize_key( wp_unslash( $_GET['job_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice code.
		$relisting = ! empty( $_GET['job_relisting'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice code.
		$duplicated = ! empty( $_GET['job_duplicated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice code.
		$errors = array(
			'title'    => __( 'Add a job title before saving this draft.', 'llamahire' ),
			'required' => __( 'Complete the job description, company, and application destination before previewing or submitting.', 'llamahire' ),
			'application_email' => __( 'Enter a valid application or notification email address.', 'llamahire' ),
			'application_url' => __( 'Enter a complete application website URL beginning with http:// or https://.', 'llamahire' ),
			'location' => __( 'Add a city and country for an on-site or hybrid job, or eligible applicant countries for a remote job.', 'llamahire' ),
			'salary'   => __( 'Enter a positive salary range with the maximum not lower than the minimum.', 'llamahire' ),
			'deadline' => __( 'Enter today or a future application deadline in the displayed date format.', 'llamahire' ),
			'logo'     => __( 'Upload a valid JPG, PNG, GIF, or WebP logo smaller than 2 MB.', 'llamahire' ),
			'save'     => __( 'The job listing could not be saved. Please try again.', 'llamahire' ),
			'listing_limit' => __( 'You have reached this job board’s active-listing limit. Save this job as a draft, or close an active listing before submitting it for review.', 'llamahire' ),
		);
		$departments = get_terms( array( 'taxonomy' => Jobs::DEPARTMENT_TAXONOMY, 'hide_empty' => false ) );
		$departments = is_wp_error( $departments ) ? array() : $departments;
		$selected_departments = $job_id ? wp_get_object_terms( $job_id, Jobs::DEPARTMENT_TAXONOMY, array( 'fields' => 'ids' ) ) : array();
		$selected_departments = is_wp_error( $selected_departments ) ? array() : array_map( 'absint', $selected_departments );
		$employment_types = Jobs::employment_types();
		$department_labels = Jobs::department_labels();
		wp_enqueue_style( 'llamahire' );
		wp_enqueue_script( 'llamahire-employer-portal', LLAMAHIRE_URL . 'assets/js/employer-portal.js', array(), (string) filemtime( LLAMAHIRE_PATH . 'assets/js/employer-portal.js' ), true );
		ob_start();
		?>
		<div class="llamahire-employer-portal"><h2><?php echo $job_id ? esc_html__( 'Edit job listing', 'llamahire' ) : esc_html__( 'Create job listing', 'llamahire' ); ?></h2>
		<p><?php esc_html_e( 'Save an incomplete draft at any time. Preview and submission check every required public job detail.', 'llamahire' ); ?></p>
		<?php if ( $relisting ) : ?><div class="llamahire-notice is-info" role="status"><?php esc_html_e( 'This expired listing is now a draft. Review its dates and details, then submit it for moderation when ready.', 'llamahire' ); ?></div><?php elseif ( $duplicated ) : ?><div class="llamahire-notice is-info" role="status"><?php esc_html_e( 'A fresh draft was created without the original deadline, expiration, reference, or featured status.', 'llamahire' ); ?></div><?php endif; ?>
		<?php self::render_listing_policy_summary( $job_id, $meta ); ?>
		<?php if ( $error ) : ?><div class="llamahire-notice is-error" role="alert"><?php echo esc_html( $errors[ $error ] ?? $errors['required'] ); ?></div><?php endif; ?>
		<form method="post" enctype="multipart/form-data">
			<input type="hidden" name="llamahire_employer_action" value="save_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>"><?php wp_nonce_field( 'llamahire_employer_save_job', 'llamahire_employer_nonce' ); ?>
			<fieldset><legend><?php esc_html_e( 'Role', 'llamahire' ); ?></legend>
				<label><?php esc_html_e( 'Job title', 'llamahire' ); ?> *<input name="job_title" required value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>"></label>
				<label><?php esc_html_e( 'Short summary', 'llamahire' ); ?><textarea name="job_excerpt" rows="3"><?php echo esc_textarea( $post ? $post->post_excerpt : '' ); ?></textarea><small><?php esc_html_e( 'A concise introduction used on job cards and near the job title.', 'llamahire' ); ?></small></label>
				<label><?php esc_html_e( 'Job description', 'llamahire' ); ?> *<textarea name="job_description" rows="10" required><?php echo esc_textarea( $post ? $post->post_content : '' ); ?></textarea></label>
				<?php if ( $employment_types ) : ?><label><?php esc_html_e( 'Employment type', 'llamahire' ); ?><select name="employment_type"><option value=""><?php esc_html_e( 'Select a job type', 'llamahire' ); ?></option><?php foreach ( $employment_types as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['employment_type'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><?php elseif ( current_user_can( 'manage_llamahire_job_types' ) ) : ?><p class="llamahire-employer-portal__help"><?php esc_html_e( 'Create job types in the Jobs menu before assigning an employment type.', 'llamahire' ); ?></p><?php endif; ?>
				<?php if ( $departments ) : ?><fieldset class="llamahire-employer-portal__choices"><legend><?php echo esc_html( $department_labels['plural'] ); ?></legend><?php foreach ( $departments as $department ) : ?><label><input type="checkbox" name="department_ids[]" value="<?php echo esc_attr( $department->term_id ); ?>" <?php checked( in_array( (int) $department->term_id, $selected_departments, true ) ); ?>> <?php echo esc_html( $department->name ); ?></label><?php endforeach; ?><?php if ( Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() ) : ?><small><?php esc_html_e( 'Job categories are managed by the job-board operator and shared across employers.', 'llamahire' ); ?></small><?php endif; ?></fieldset><?php endif; ?>
			</fieldset>
			<fieldset class="llamahire-employer-portal__location" data-llamahire-location-fields data-physical-help="<?php esc_attr_e( 'Add the place where employees will work. City and country are required.', 'llamahire' ); ?>" data-remote-help="<?php esc_attr_e( 'Add the countries where candidates are eligible to work remotely.', 'llamahire' ); ?>"><legend><?php esc_html_e( 'Location', 'llamahire' ); ?></legend>
				<label><?php esc_html_e( 'Location type', 'llamahire' ); ?><select name="workplace"><?php foreach ( array( 'onsite' => __( 'On-site', 'llamahire' ), 'hybrid' => __( 'Hybrid', 'llamahire' ), 'remote' => __( 'Remote', 'llamahire' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['workplace'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<p class="llamahire-employer-portal__help" data-llamahire-location-help aria-live="polite"><?php echo esc_html( 'remote' === $meta['workplace'] ? __( 'Add the countries where candidates are eligible to work remotely.', 'llamahire' ) : __( 'Add the place where employees will work. City and country are required.', 'llamahire' ) ); ?></p>
				<div class="llamahire-employer-portal__location-group" data-llamahire-physical-location <?php echo 'remote' === $meta['workplace'] ? 'hidden' : ''; ?>>
					<label><?php esc_html_e( 'City', 'llamahire' ); ?> *<input name="address_locality" value="<?php echo esc_attr( $meta['address_locality'] ); ?>" <?php echo 'remote' === $meta['workplace'] ? '' : 'required'; ?>></label>
					<label><?php esc_html_e( 'State, province, or region', 'llamahire' ); ?><input name="address_region" value="<?php echo esc_attr( $meta['address_region'] ); ?>"></label>
					<label><?php esc_html_e( 'Country', 'llamahire' ); ?> *<?php Settings::country_select( 'llamahire-employer-country', 'address_country', $meta['address_country'] ); ?></label>
					<label><?php esc_html_e( 'Street address', 'llamahire' ); ?><input name="address_street" value="<?php echo esc_attr( $meta['address_street'] ); ?>"></label>
					<label><?php esc_html_e( 'Postal code', 'llamahire' ); ?><input name="postal_code" value="<?php echo esc_attr( $meta['postal_code'] ); ?>"></label>
				</div>
				<div class="llamahire-employer-portal__location-group" data-llamahire-remote-location <?php echo 'remote' === $meta['workplace'] ? '' : 'hidden'; ?>>
					<label><?php esc_html_e( 'Eligible applicant countries', 'llamahire' ); ?> *<input name="applicant_countries" value="<?php echo esc_attr( $meta['applicant_countries'] ); ?>" placeholder="CA, US" <?php echo 'remote' === $meta['workplace'] ? 'required' : ''; ?>><small><?php esc_html_e( 'Enter two-letter country codes separated by commas.', 'llamahire' ); ?></small></label>
				</div>
			</fieldset>
			<fieldset><legend><?php esc_html_e( 'Compensation and timing', 'llamahire' ); ?></legend>
				<div class="llamahire-employer-portal__columns"><label><?php esc_html_e( 'Minimum salary', 'llamahire' ); ?><input type="number" min="0.01" step="0.01" name="salary_min" value="<?php echo esc_attr( $meta['salary_min'] ); ?>"></label><label><?php esc_html_e( 'Maximum salary', 'llamahire' ); ?><input type="number" min="0.01" step="0.01" name="salary_max" value="<?php echo esc_attr( $meta['salary_max'] ); ?>"></label></div>
				<label><?php esc_html_e( 'Currency', 'llamahire' ); ?><?php Settings::currency_select( 'llamahire-employer-currency', 'salary_currency', $meta['salary_currency'] ); ?></label>
				<label><?php esc_html_e( 'Pay period', 'llamahire' ); ?><select name="salary_unit"><?php foreach ( array( 'HOUR' => __( 'Hour', 'llamahire' ), 'DAY' => __( 'Day', 'llamahire' ), 'WEEK' => __( 'Week', 'llamahire' ), 'MONTH' => __( 'Month', 'llamahire' ), 'YEAR' => __( 'Year', 'llamahire' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['salary_unit'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Application deadline', 'llamahire' ); ?><input type="date" name="deadline" value="<?php echo esc_attr( $meta['deadline'] ); ?>"><small><?php esc_html_e( 'Applications close at the end of this date in the site timezone.', 'llamahire' ); ?></small></label>
			</fieldset>
			<fieldset><legend><?php esc_html_e( 'Company', 'llamahire' ); ?></legend>
				<label><?php esc_html_e( 'Company name', 'llamahire' ); ?> *<input name="organization_name" required value="<?php echo esc_attr( $meta['organization_name'] ); ?>"></label>
				<label><?php esc_html_e( 'Company tagline', 'llamahire' ); ?><input name="organization_tagline" value="<?php echo esc_attr( $meta['organization_tagline'] ); ?>"></label>
				<label><?php esc_html_e( 'Company website', 'llamahire' ); ?><input type="url" name="organization_url" value="<?php echo esc_attr( $meta['organization_url'] ); ?>"></label>
				<label><?php esc_html_e( 'Company logo', 'llamahire' ); ?><?php if ( $meta['organization_logo'] ) : ?><img class="llamahire-employer-portal__logo" src="<?php echo esc_url( $meta['organization_logo'] ); ?>" alt=""><?php endif; ?><input type="file" name="organization_logo" accept="image/jpeg,image/png,image/gif,image/webp"><small><?php esc_html_e( 'JPG, PNG, GIF, or WebP. Maximum 2 MB. Uploads are stored in the WordPress Media Library.', 'llamahire' ); ?></small></label>
				<?php if ( $meta['organization_logo'] ) : ?><label class="llamahire-employer-portal__check"><input type="checkbox" name="remove_organization_logo" value="1"> <?php esc_html_e( 'Remove the current company logo from this listing', 'llamahire' ); ?></label><?php endif; ?>
			</fieldset>
			<fieldset class="llamahire-employer-portal__applications" data-internal-label="<?php esc_attr_e( 'Notification email', 'llamahire' ); ?>" data-internal-help="<?php esc_attr_e( 'Candidate notifications from the LlamaHire form will be sent to this inbox.', 'llamahire' ); ?>" data-external-email-label="<?php esc_attr_e( 'Application email', 'llamahire' ); ?>" data-external-email-help="<?php esc_attr_e( 'Candidates will apply by email to this address.', 'llamahire' ); ?>" data-external-url-label="<?php esc_attr_e( 'Application website URL', 'llamahire' ); ?>" data-external-url-help="<?php esc_attr_e( 'Candidates will be sent to this website to apply.', 'llamahire' ); ?>"><legend><?php esc_html_e( 'Applications', 'llamahire' ); ?></legend>
				<label for="llamahire-application-method"><?php esc_html_e( 'How candidates apply', 'llamahire' ); ?><select id="llamahire-application-method" name="application_method"><option value="internal" <?php selected( $meta['application_method'], 'internal' ); ?>><?php esc_html_e( 'LlamaHire application form', 'llamahire' ); ?></option><option value="external_url" <?php selected( $meta['application_method'], 'external_url' ); ?>><?php esc_html_e( 'Application website', 'llamahire' ); ?></option><option value="external_email" <?php selected( $meta['application_method'], 'external_email' ); ?>><?php esc_html_e( 'Application email', 'llamahire' ); ?></option></select></label>
				<label for="llamahire-application-target"><span><span data-llamahire-application-target-label><?php echo esc_html( self::application_target_label( $meta['application_method'] ) ); ?></span> *</span><input id="llamahire-application-target" type="<?php echo esc_attr( 'external_url' === $meta['application_method'] ? 'url' : 'email' ); ?>" inputmode="<?php echo esc_attr( 'external_url' === $meta['application_method'] ? 'url' : 'email' ); ?>" autocomplete="<?php echo esc_attr( 'external_url' === $meta['application_method'] ? 'url' : 'email' ); ?>" name="application_target" required value="<?php echo esc_attr( $meta['application_target'] ); ?>"><small data-llamahire-application-target-help aria-live="polite"><?php echo esc_html( self::application_target_help( $meta['application_method'] ) ); ?></small></label>
			</fieldset>
			<div class="llamahire-employer-portal__actions"><button type="submit" name="job_intent" value="draft" formnovalidate><?php esc_html_e( 'Save draft', 'llamahire' ); ?></button><button type="submit" name="job_intent" value="preview"><?php esc_html_e( 'Save and preview', 'llamahire' ); ?></button><button type="submit" name="job_intent" value="submit"><?php esc_html_e( 'Submit for review', 'llamahire' ); ?></button></div>
		</form></div>
		<?php
		return ob_get_clean();
	}

	public static function my_jobs_shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			return self::access_message();
		}
		if ( 'applications' === sanitize_key( wp_unslash( $_GET['employer_view'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only portal routing.
			return Employer_Applications::render();
		}
		$state = self::my_jobs_state();
		$query = new \WP_Query( self::my_jobs_query_args( $state ) );
		if ( 1 < $state['page'] && 0 === (int) $query->post_count && 0 < (int) $query->max_num_pages ) {
			$state['page'] = (int) $query->max_num_pages;
			$query = new \WP_Query( self::my_jobs_query_args( $state ) );
		}
		$jobs = $query->posts;
		$application_counts = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->counts_by_job( wp_list_pluck( $jobs, 'ID' ), Ownership::query_arguments() );
		wp_enqueue_style( 'llamahire' );
		ob_start();
		$settings = Settings::get();
		$submit_url = Settings::public_page( $settings['submit_job_page_id'] ) ? get_permalink( $settings['submit_job_page_id'] ) : self::current_url();
		$account_url = Settings::public_page( $settings['employer_account_page_id'] ) ? get_permalink( $settings['employer_account_page_id'] ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notices.
		?>
		<div class="llamahire-employer-portal">
			<?php if ( $account_url ) : ?><nav class="llamahire-employer-portal__account" aria-label="<?php esc_attr_e( 'Employer account', 'llamahire' ); ?>"><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Account', 'llamahire' ); ?></a></nav><?php endif; ?>
			<?php if ( ! empty( $_GET['job_submitted'] ) ) : ?>
				<div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your job was submitted for moderation.', 'llamahire' ); ?></div>
			<?php elseif ( ! empty( $_GET['job_draft_saved'] ) ) : ?>
				<div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your draft was saved. It is not awaiting review yet.', 'llamahire' ); ?></div>
			<?php elseif ( ! empty( $_GET['job_renewed'] ) ) : ?>
				<div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your listing was renewed. Its application deadline is unchanged.', 'llamahire' ); ?></div>
			<?php elseif ( ! empty( $_GET['job_closed'] ) ) : ?>
				<div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your job listing was closed.', 'llamahire' ); ?></div>
			<?php elseif ( ! empty( $_GET['job_deleted'] ) ) : ?>
				<div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your job listing was deleted.', 'llamahire' ); ?></div>
			<?php elseif ( 'listing_limit' === sanitize_key( wp_unslash( $_GET['job_action_error'] ?? '' ) ) ) : ?>
				<div class="llamahire-notice is-error" role="alert"><?php esc_html_e( 'Relisting would exceed this board’s active-listing limit. Close another active listing first.', 'llamahire' ); ?></div>
			<?php endif; ?>
			<?php self::render_listing_policy_summary(); ?>
			<p><a class="llamahire-button" href="<?php echo esc_url( $submit_url ); ?>"><?php esc_html_e( 'Create job listing', 'llamahire' ); ?></a></p>
			<form class="llamahire-my-jobs-filters" method="get" action="<?php echo esc_url( get_permalink() ); ?>" role="search" aria-label="<?php esc_attr_e( 'Filter your jobs', 'llamahire' ); ?>">
				<label><span><?php esc_html_e( 'Search', 'llamahire' ); ?></span><input type="search" name="my_jobs_search" value="<?php echo esc_attr( $state['search'] ); ?>" placeholder="<?php esc_attr_e( 'Job title or keyword', 'llamahire' ); ?>"></label>
				<label><span><?php esc_html_e( 'Status', 'llamahire' ); ?></span><select name="my_jobs_status"><?php foreach ( self::my_jobs_status_options() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state['status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<button type="submit"><?php esc_html_e( 'Filter jobs', 'llamahire' ); ?></button>
				<?php if ( $state['search'] || 'all' !== $state['status'] ) : ?><a href="<?php echo esc_url( get_permalink() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?>
			</form>
			<p class="llamahire-my-jobs-results" role="status">
				<?php
				if ( $query->found_posts ) {
					$first = ( ( $state['page'] - 1 ) * $query->query_vars['posts_per_page'] ) + 1;
					$last  = min( $query->found_posts, $first + $query->post_count - 1 );
					/* translators: 1: first visible job number, 2: last visible job number, 3: total matching jobs. */
					echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s jobs', 'llamahire' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $query->found_posts ) ) );
				} else {
					esc_html_e( 'No matching jobs', 'llamahire' );
				}
				?>
			</p>
			<?php if ( $jobs ) : ?>
				<div class="llamahire-my-jobs-table"><table>
					<thead><tr><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Status', 'llamahire' ); ?></th><th><?php esc_html_e( 'Actions', 'llamahire' ); ?></th></tr></thead>
					<tbody>
						<?php $extensions = new Employer_Job_Extensions( Plugin::instance()->services()->get( Service_IDs::EXTENSION_ACCESS ) ); ?>
						<?php foreach ( $jobs as $job ) : $meta = Jobs::get_meta( $job->ID ); ?>
							<tr>
								<td><?php echo esc_html( $job->post_title ); ?></td>
								<td><strong><?php echo esc_html( self::job_status_label( $job, $meta ) ); ?></strong><?php $detail = self::job_status_detail( $job, $meta ); if ( $detail ) : ?><small class="llamahire-employer-portal__status-detail"><?php echo esc_html( $detail ); ?></small><?php endif; ?><?php $extensions->render( $job->ID ); ?></td>
								<td><?php self::render_job_actions( $job, $meta, $submit_url, (int) ( $application_counts[ $job->ID ] ?? 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php self::render_my_jobs_pagination( $query, $state ); ?>
			<?php else : ?>
				<?php if ( $state['search'] || 'all' !== $state['status'] ) : ?><p><?php esc_html_e( 'No jobs match those filters. Try another search or clear the filters.', 'llamahire' ); ?></p><?php else : ?><p><?php esc_html_e( 'You have not created any job listings yet.', 'llamahire' ); ?></p><?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return ob_get_clean();
	}

	private static function my_jobs_state() {
		$status  = sanitize_key( wp_unslash( $_GET['my_jobs_status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard filters.
		$options = self::my_jobs_status_options();
		return array(
			'search' => sanitize_text_field( wp_unslash( $_GET['my_jobs_search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard filters.
			'status' => isset( $options[ $status ] ) ? $status : 'all',
			'page'   => max( 1, absint( $_GET['my_jobs_page'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard pagination.
		);
	}

	private static function my_jobs_status_options() {
		return array(
			'all'       => __( 'All statuses', 'llamahire' ),
			'published' => __( 'Published', 'llamahire' ),
			'pending'   => __( 'Awaiting review', 'llamahire' ),
			'draft'     => __( 'Draft', 'llamahire' ),
			'closed'    => __( 'Closed', 'llamahire' ),
			'expired'   => __( 'Expired', 'llamahire' ),
		);
	}

	private static function my_jobs_query_args( array $state ) {
		$args = array(
			'post_type'      => Jobs::POST_TYPE,
			'post_status'    => array( 'pending', 'publish', 'draft' ),
			'author'         => get_current_user_id(),
			'posts_per_page' => 25,
			'paged'          => $state['page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			's'              => $state['search'],
		);
		if ( 'pending' === $state['status'] || 'draft' === $state['status'] ) {
			$args['post_status'] = $state['status'];
		} elseif ( 'published' === $state['status'] ) {
			$args['post_status'] = 'publish';
			$args['meta_query']  = Jobs::open_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'closed' === $state['status'] ) {
			$args['post_status'] = 'publish';
			$args['meta_query']  = array( array( 'key' => Jobs::META_CLOSED, 'value' => '1' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'expired' === $state['status'] ) {
			$today = current_time( 'Y-m-d' );
			$args['post_status'] = 'publish';
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array( 'key' => Jobs::META_CLOSED, 'value' => '1', 'compare' => '!=' ),
				array(
					'relation' => 'OR',
					array( 'key' => Jobs::META_DEADLINE, 'value' => $today, 'compare' => '<', 'type' => 'DATE' ),
					array( 'key' => Jobs::META_EXPIRY, 'value' => $today, 'compare' => '<', 'type' => 'DATE' ),
				),
			);
		}
		return $args;
	}

	private static function render_my_jobs_pagination( \WP_Query $query, array $state ) {
		if ( 2 > (int) $query->max_num_pages ) {
			return;
		}
		$args = array();
		if ( $state['search'] ) {
			$args['my_jobs_search'] = $state['search'];
		}
		if ( 'all' !== $state['status'] ) {
			$args['my_jobs_status'] = $state['status'];
		}
		$base  = str_replace( '999999999', '%#%', add_query_arg( array_merge( $args, array( 'my_jobs_page' => 999999999 ) ), get_permalink() ) );
		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $state['page'],
				'total'     => $query->max_num_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'llamahire' ),
				'next_text' => __( 'Next', 'llamahire' ),
			)
		);
		if ( $links ) {
			echo '<nav class="llamahire-pagination" aria-label="' . esc_attr__( 'My Jobs pages', 'llamahire' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	private static function job_status_label( $job, array $meta ) {
		if ( '1' === $meta['closed'] ) {
			return __( 'Closed', 'llamahire' );
		}
		if ( 'publish' === $job->post_status && ( ( ! empty( $meta['deadline'] ) && $meta['deadline'] < current_time( 'Y-m-d' ) ) || ( ! empty( $meta['listing_expires'] ) && $meta['listing_expires'] < current_time( 'Y-m-d' ) ) ) ) {
			return __( 'Expired', 'llamahire' );
		}
		$labels = array(
			'draft'   => __( 'Draft', 'llamahire' ),
			'pending' => __( 'Awaiting review', 'llamahire' ),
			'publish' => __( 'Published', 'llamahire' ),
		);
		return $labels[ $job->post_status ] ?? ucfirst( $job->post_status );
	}

	private static function job_status_detail( $job, array $meta ) {
		$uses_deadline = $meta['deadline'] && ( ! $meta['listing_expires'] || $meta['deadline'] <= $meta['listing_expires'] );
		$date = $uses_deadline ? $meta['deadline'] : $meta['listing_expires'];
		if ( ! $date || ! in_array( $job->post_status, array( 'publish', 'pending' ), true ) ) {
			return '';
		}
		$formatted = wp_date( get_option( 'date_format' ), strtotime( $date ) );
		if ( self::job_is_expired( $job, $meta ) ) {
			/* translators: %s: formatted date. */
			return $uses_deadline ? sprintf( __( 'Applications closed %s', 'llamahire' ), $formatted ) : sprintf( __( 'Listing expired %s', 'llamahire' ), $formatted );
		}
		/* translators: %s: formatted date. */
		return $uses_deadline ? sprintf( __( 'Applications close %s', 'llamahire' ), $formatted ) : sprintf( __( 'Listing expires %s', 'llamahire' ), $formatted );
	}

	private static function job_is_expired( $job, array $meta ) {
		return 'publish' === $job->post_status && '1' !== $meta['closed'] && ! Jobs::is_open( $job->ID );
	}

	private static function render_job_actions( $job, array $meta, $submit_url, $application_count = 0 ) {
		$edit_url = add_query_arg( 'job_id', $job->ID, $submit_url );
		$application_count_label = sprintf(
			/* translators: %s: Number of applications for a job. */
			_n( '%s application', '%s applications', $application_count, 'llamahire' ),
			number_format_i18n( $application_count )
		);
		?>
		<div class="llamahire-employer-portal__job-actions">
			<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></a>
			<a href="<?php echo esc_url( get_preview_post_link( $job ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'llamahire' ); ?></a>
			<?php if ( 'internal' === $meta['application_method'] ) : ?><a href="<?php echo esc_url( Employer_Applications::url( array( 'job_id' => $job->ID ) ) ); ?>"><?php echo esc_html( $application_count_label ); ?></a><?php endif; ?>
			<?php if ( Jobs::listing_expires_soon( $job->ID ) && Settings::listing_duration_days( Settings::get()['listing_duration_days'] ) ) : ?>
				<form method="post"><input type="hidden" name="llamahire_employer_action" value="renew_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_renew_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><button type="submit" class="llamahire-link-button"><?php esc_html_e( 'Renew', 'llamahire' ); ?></button></form>
			<?php endif; ?>
			<?php if ( self::job_is_expired( $job, $meta ) ) : ?>
				<form method="post"><input type="hidden" name="llamahire_employer_action" value="relist_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_relist_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><button type="submit" class="llamahire-link-button"><?php esc_html_e( 'Relist', 'llamahire' ); ?></button></form>
			<?php endif; ?>
			<form method="post"><input type="hidden" name="llamahire_employer_action" value="duplicate_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_duplicate_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><button type="submit" class="llamahire-link-button"><?php esc_html_e( 'Duplicate', 'llamahire' ); ?></button></form>
			<?php if ( 'publish' === $job->post_status && Jobs::is_open( $job->ID ) ) : ?>
				<form method="post"><input type="hidden" name="llamahire_employer_action" value="close_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_close_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><button type="submit" class="llamahire-link-button"><?php esc_html_e( 'Close', 'llamahire' ); ?></button></form>
			<?php endif; ?>
			<details class="llamahire-employer-portal__delete-menu"><summary class="llamahire-link-button"><?php esc_html_e( 'Delete', 'llamahire' ); ?></summary><form class="llamahire-employer-portal__delete-panel" method="post"><input type="hidden" name="llamahire_employer_action" value="delete_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_delete_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><strong><?php esc_html_e( 'Delete this listing?', 'llamahire' ); ?></strong><label><input type="checkbox" name="confirm_delete_job" value="1" required> <span><?php esc_html_e( 'Move this listing to the trash and remove it from My Jobs.', 'llamahire' ); ?></span></label><button type="submit"><?php esc_html_e( 'Confirm delete', 'llamahire' ); ?></button></form></details>
		</div>
		<?php
	}

	private static function latest_employer_defaults() {
		$user_id = get_current_user_id();
		$defaults = Jobs::defaults();
		$latest = get_posts( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => array( 'pending', 'publish', 'draft' ), 'author' => $user_id, 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( $latest ) {
			$previous = Jobs::get_meta( $latest[0]->ID );
			foreach ( array( 'organization_name', 'organization_tagline', 'organization_url', 'organization_logo', 'organization_logo_id', 'application_method', 'application_target' ) as $key ) {
				$defaults[ $key ] = $previous[ $key ];
			}
		}
		$account_company = sanitize_text_field( get_user_meta( $user_id, Employer_Registration::COMPANY_META, true ) );
		if ( $account_company ) {
			$defaults['organization_name'] = $account_company;
		}
		return $defaults;
	}

	public static function active_listing_count( $user_id, $exclude_job_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}
		$args = array(
			'post_type'              => Jobs::POST_TYPE,
			'author'                 => $user_id,
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$pending_args                = $args;
		$pending_args['post_status'] = 'pending';
		$pending_query               = new \WP_Query( $pending_args );
		$published_args                = $args;
		$published_args['post_status'] = 'publish';
		$published_args['meta_query']  = Jobs::open_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery -- Active published listings require the indexed lifecycle mirrors maintained by Jobs::set_meta().
		$published_query               = new \WP_Query( $published_args );
		$count                         = (int) $pending_query->found_posts + (int) $published_query->found_posts;
		$exclude_job_id                = absint( $exclude_job_id );
		if ( $exclude_job_id ) {
			$excluded_job = get_post( $exclude_job_id );
			if ( $excluded_job && Jobs::POST_TYPE === $excluded_job->post_type && $user_id === (int) $excluded_job->post_author && ( 'pending' === $excluded_job->post_status || ( 'publish' === $excluded_job->post_status && Jobs::is_open( $exclude_job_id ) ) ) ) {
				--$count;
			}
		}
		return max( 0, $count );
	}

	private static function listing_limit_reached( $exclude_job_id = 0 ) {
		if ( current_user_can( 'edit_others_llamahire_jobs' ) ) {
			return false;
		}
		$limit = absint( Settings::get()['active_listing_limit'] );
		return $limit && self::active_listing_count( get_current_user_id(), $exclude_job_id ) >= $limit;
	}

	private static function render_listing_policy_summary( $job_id = 0, array $meta = array() ) {
		$settings = Settings::get();
		$limit    = absint( $settings['active_listing_limit'] );
		$duration = Settings::listing_duration_days( $settings['listing_duration_days'] );
		if ( ! $limit && ! $duration && empty( $meta['listing_expires'] ) ) {
			return;
		}
		$parts = array();
		if ( $limit ) {
			$count = self::active_listing_count( get_current_user_id() );
			/* translators: 1: active listing count, 2: maximum active listings. */
			$parts[] = sprintf( __( '%1$d of %2$d active listings used.', 'llamahire' ), $count, $limit );
		}
		if ( ! empty( $meta['listing_expires'] ) ) {
			/* translators: %s: formatted listing-expiration date. */
			$parts[] = sprintf( __( 'This listing expires on %s.', 'llamahire' ), wp_date( get_option( 'date_format' ), strtotime( $meta['listing_expires'] ) ) );
		} elseif ( $duration ) {
			/* translators: %d: default listing duration in days. */
			$parts[] = sprintf( _n( 'New submissions remain active for %d day after submission.', 'New submissions remain active for %d days after submission.', $duration, 'llamahire' ), $duration );
		}
		if ( $parts ) {
			echo '<p class="llamahire-employer-portal__policy">' . esc_html( implode( ' ', $parts ) ) . '</p>';
		}
	}

	private static function application_target_label( $method ) {
		if ( 'external_url' === $method ) {
			return __( 'Application website URL', 'llamahire' );
		}
		return 'external_email' === $method ? __( 'Application email', 'llamahire' ) : __( 'Notification email', 'llamahire' );
	}

	private static function application_target_help( $method ) {
		if ( 'external_url' === $method ) {
			return __( 'Candidates will be sent to this website to apply.', 'llamahire' );
		}
		return 'external_email' === $method ? __( 'Candidates will apply by email to this address.', 'llamahire' ) : __( 'Candidate notifications from the LlamaHire form will be sent to this inbox.', 'llamahire' );
	}

	private static function current_url() {
		return home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
	}

	private function __construct() {}
}
