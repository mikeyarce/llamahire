<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional messages for the administrator-approved employer workflow.
 */
final class Employer_Notifications {
	const EXPIRING_HOOK = 'llamahire_send_expiring_listing_notices';
	const EXPIRING_META = '_llamahire_expiring_notice_sent_for';

	public static function register() {
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( self::EXPIRING_HOOK, array( __CLASS__, 'send_expiring_notices' ) );
		if ( ! wp_next_scheduled( self::EXPIRING_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRING_HOOK );
		}
	}

	public static function send_expiring_notices() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return 0;
		}
		$sent = 0;
		for ( $page = 1; $page <= 50; ++$page ) {
			$query = new \WP_Query(
				array(
					'post_type'      => Jobs::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => Jobs::closing_soon_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! $query->posts ) {
				break;
			}
			foreach ( $query->posts as $job_id ) {
				if ( ! Jobs::listing_expires_soon( $job_id ) ) {
					continue;
				}
				$expiry = Jobs::get_meta( $job_id )['listing_expires'];
				if ( $expiry === get_post_meta( $job_id, self::EXPIRING_META, true ) ) {
					continue;
				}
				if ( self::send_expiring_notice( $job_id, $expiry ) ) {
					update_post_meta( $job_id, self::EXPIRING_META, $expiry );
					Audit_Log::record( 'job_expiry_notice_sent', $job_id, 0, '', '', 0 );
					++$sent;
				}
			}
			if ( $page >= $query->max_num_pages ) {
				break;
			}
		}
		return $sent;
	}

	private static function send_expiring_notice( $job_id, $expiry ) {
		$job  = get_post( absint( $job_id ) );
		$user = $job ? get_userdata( $job->post_author ) : false;
		if ( ! $job || ! $user || ! in_array( Capabilities::EMPLOYER_ROLE, (array) $user->roles, true ) || ! is_email( $user->user_email ) ) {
			return false;
		}
		$settings  = Settings::get();
		$dashboard = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		$can_renew = 0 < Settings::listing_duration_days( $settings['listing_duration_days'] );
		if ( $can_renew ) {
			/* translators: 1: job title, 2: formatted expiration date, 3: employer dashboard URL. */
			$template = __( "Your listing “%1\$s” expires on %2\$s. Renew it before then to keep it open, or let it expire and relist it for moderation later.\n\nManage your jobs: %3\$s", 'llamahire' );
		} else {
			/* translators: 1: job title, 2: formatted expiration date, 3: employer dashboard URL. */
			$template = __( "Your listing “%1\$s” expires on %2\$s. Review it before then, or let it expire and relist it for moderation later.\n\nManage your jobs: %3\$s", 'llamahire' );
		}
		$message   = sprintf(
			$template,
			$job->post_title,
			wp_date( get_option( 'date_format' ), strtotime( $expiry ) ),
			$dashboard
		);
		$result = wp_mail( $user->user_email, __( 'Your job listing expires soon', 'llamahire' ), $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional lifecycle notice for a single employer and listing.
		do_action( 'llamahire_job_expiring_notification_sent', $result, $job->ID, $expiry );
		return $result;
	}

	public static function job_submitted( $job_id, $updated = false ) {
		$job = get_post( absint( $job_id ) );
		if ( ! $job || Jobs::POST_TYPE !== $job->post_type ) {
			return false;
		}
		$settings = Settings::get();
		$recipient = sanitize_email( apply_filters( 'llamahire_board_moderation_email', $settings['notification_email'], $job ) );
		if ( ! is_email( $recipient ) ) {
			return false;
		}
		$company = Jobs::get_meta( $job->ID )['organization_name'];
		$subject = $updated ? __( 'Job listing resubmitted for review', 'llamahire' ) : __( 'New job listing awaiting review', 'llamahire' );
		$message = sprintf(
			/* translators: 1: job title, 2: company, 3: submitter email, 4: moderation URL. */
			__( "Job: %1\$s\nCompany: %2\$s\nSubmitted by: %3\$s\n\nReview listing: %4\$s", 'llamahire' ),
			$job->post_title,
			$company,
			get_the_author_meta( 'user_email', $job->post_author ),
			admin_url( 'post.php?post=' . $job->ID . '&action=edit' )
		);
		$result = wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional moderation notice for a submitted listing.
		do_action( 'llamahire_job_moderation_notification_sent', $result, $job->ID, $updated );
		return $result;
	}

	public static function status_changed( $new_status, $old_status, $post ) {
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type || $new_status === $old_status || ! in_array( $new_status, array( 'publish', 'draft', 'trash' ), true ) || ! current_user_can( 'edit_others_llamahire_jobs' ) ) {
			return;
		}
		$user = get_userdata( $post->post_author );
		if ( ! $user || ! in_array( Capabilities::EMPLOYER_ROLE, (array) $user->roles, true ) ) {
			return;
		}
		$states = array(
			'publish' => array( __( 'Your job listing was approved', 'llamahire' ), __( 'approved and published', 'llamahire' ) ),
			'draft'   => array( __( 'Your job listing needs changes', 'llamahire' ), __( 'returned to draft for changes', 'llamahire' ) ),
			'trash'   => array( __( 'Your job listing was declined', 'llamahire' ), __( 'declined by the job-board operator', 'llamahire' ) ),
		);
		$settings = Settings::get();
		$dashboard = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		$message = sprintf(
			/* translators: 1: job title, 2: moderation result, 3: employer dashboard URL. */
			__( "Your listing “%1\$s” was %2\$s.\n\nManage your jobs: %3\$s", 'llamahire' ),
			$post->post_title,
			$states[ $new_status ][1],
			$dashboard
		);
		$result = wp_mail( $user->user_email, $states[ $new_status ][0], $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional status notice for a single employer and listing.
		do_action( 'llamahire_employer_moderation_notification_sent', $result, $post->ID, $new_status, $old_status );
	}

	private function __construct() {}
}
