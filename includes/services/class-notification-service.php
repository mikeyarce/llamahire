<?php
namespace LlamaHire\Services;

use LlamaHire\Contracts\Notification_Service as Notification_Service_Contract;
use LlamaHire\Settings;

defined( 'ABSPATH' ) || exit;

final class Notification_Service implements Notification_Service_Contract {
	public function application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) ) {
		$settings = Settings::get();
		$to       = sanitize_email( $settings['notification_email'] ?: get_option( 'admin_email' ) );
		$job      = get_the_title( $job_id );
		$name     = sanitize_text_field( $application['name'] ?? '' );
		$email    = sanitize_email( $application['email'] ?? '' );

		/**
		 * Fires before LlamaHire sends core application notifications.
		 *
		 * @param array $application Stored application data.
		 * @param int   $job_id      Job post ID.
		 */
		do_action( 'llamahire_before_application_notifications', $application, $job_id );

		$channels = array_intersect( array( 'employer', 'candidate' ), $channels );
		$errors   = array();
		$listener = static function ( $error ) use ( &$errors ) {
			if ( is_wp_error( $error ) ) {
				$errors[] = sanitize_key( $error->get_error_code() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$results = array( 'employer' => false, 'candidate' => false, 'error_codes' => array() );
		if ( in_array( 'employer', $channels, true ) ) {
			$results['employer'] = wp_mail(
				$to,
				/* translators: %s: job title. */
				sprintf( __( 'New application for %s', 'llamahire' ), $job ),
				/* translators: 1: candidate name, 2: job title, 3: applications admin URL. */
				sprintf( __( "%1\$s applied for %2\$s.\n\nReview applications: %3\$s", 'llamahire' ), $name, $job, admin_url( 'admin.php?page=llamahire-applications' ) )
			);
		}
		if ( in_array( 'candidate', $channels, true ) ) {
			$results['candidate'] = wp_mail(
				$email,
				/* translators: %s: job title. */
				sprintf( __( 'We received your application for %s', 'llamahire' ), $job ),
				/* translators: 1: candidate name, 2: job title, 3: site name. */
				sprintf( __( "Hi %1\$s,\n\nThanks for applying for %2\$s. We received your application and will be in touch if your experience matches what we are looking for.\n\n%3\$s", 'llamahire' ), $name, $job, get_bloginfo( 'name' ) )
			);
		}
		remove_action( 'wp_mail_failed', $listener );
		$results['error_codes'] = array_values( array_unique( array_filter( $errors ) ) );

		/**
		 * Fires after LlamaHire attempts core application notifications.
		 *
		 * @param array $results     Boolean result for each recipient type.
		 * @param array $application Stored application data.
		 * @param int   $job_id      Job post ID.
		 */
		do_action( 'llamahire_application_notifications_sent', $results, $application, $job_id );
		return $results;
	}
}
