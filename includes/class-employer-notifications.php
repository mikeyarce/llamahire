<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional messages for the administrator-approved employer workflow.
 */
final class Employer_Notifications {
	public static function register() {
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
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
		$result = wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
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
		$result = wp_mail( $user->user_email, $states[ $new_status ][0], $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		do_action( 'llamahire_employer_moderation_notification_sent', $result, $post->ID, $new_status, $old_status );
	}

	private function __construct() {}
}
