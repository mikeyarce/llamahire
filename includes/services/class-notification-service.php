<?php
namespace LlamaHire\Services;

use LlamaHire\Contracts\Notification_Service as Notification_Service_Contract;
use LlamaHire\Settings;
use LlamaHire\Jobs;

defined( 'ABSPATH' ) || exit;

final class Notification_Service implements Notification_Service_Contract {
	public function preview( array $application, $job_id ) {
		$settings = Settings::get();
		$meta     = absint( $job_id ) ? Jobs::get_meta( $job_id ) : array();
		$job_recipient = 'internal' === ( $meta['application_method'] ?? 'internal' ) ? sanitize_email( $meta['application_target'] ?? '' ) : '';
		$to       = sanitize_email( $job_recipient ?: ( $settings['notification_email'] ?: get_option( 'admin_email' ) ) );
		$job      = absint( $job_id ) ? get_the_title( $job_id ) : '';
		$job      = sanitize_text_field( $job ?: __( 'Sample role', 'llamahire' ) );
		$name     = sanitize_text_field( $application['name'] ?? '' );
		$email    = sanitize_email( $application['email'] ?? '' );
		$context  = array(
			'{candidate_name}'  => $name ?: __( 'Alex Candidate', 'llamahire' ),
			'{job_title}'       => $job,
			'{site_name}'       => $settings['name'] ?: get_bloginfo( 'name' ),
			'{site_url}'        => home_url( '/' ),
			'{applications_url}' => \LlamaHire\Admin::applications_url(),
		);
		$headers = $this->headers();
		return array(
			'employer' => array(
				'to'      => $to,
				'subject' => $this->render( $settings['employer_email_subject'], $context, true ),
				'message' => $this->render( $settings['employer_email_body'], $context, false ),
				'headers' => $headers,
			),
			'candidate' => array(
				'to'      => $email ?: 'alex@example.test',
				'subject' => $this->render( $settings['candidate_email_subject'], $context, true ),
				'message' => $this->render( $settings['candidate_email_body'], $context, false ),
				'headers' => $headers,
			),
		);
	}

	public function application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) ) {
		$messages = $this->preview( $application, $job_id );

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
			$message = $messages['employer'];
			$results['employer'] = wp_mail( $message['to'], $message['subject'], $message['message'], $message['headers'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional application notice to the configured hiring inbox.
		}
		if ( in_array( 'candidate', $channels, true ) ) {
			$message = $messages['candidate'];
			$results['candidate'] = wp_mail( $message['to'], $message['subject'], $message['message'], $message['headers'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional acknowledgement to the applicant.
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

	public function test_delivery( $to ) {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return array( 'success' => false, 'error_codes' => array( 'invalid_recipient' ) );
		}
		$errors   = array();
		$listener = static function ( $error ) use ( &$errors ) {
			if ( is_wp_error( $error ) ) {
				$errors[] = sanitize_key( $error->get_error_code() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$success = wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One administrator-initiated delivery test to the configured inbox.
			$to,
			__( 'LlamaHire email delivery test', 'llamahire' ),
			__( "WordPress accepted this test message from LlamaHire. Receiving it confirms that the configured sender and your site's mail transport can deliver to this address.", 'llamahire' ),
			$this->headers()
		);
		remove_action( 'wp_mail_failed', $listener );
		if ( ! $success && ! $errors ) {
			$errors[] = 'wp_mail_false';
		}
		return array(
			'success'     => (bool) $success,
			'error_codes' => array_values( array_unique( array_filter( $errors ) ) ),
		);
	}

	private function headers() {
		$settings = Settings::get();
		$name     = str_replace( array( '<', '>', '"', "\r", "\n" ), '', sanitize_text_field( $settings['email_sender_name'] ) );
		$email    = sanitize_email( $settings['email_sender_email'] );
		$headers  = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $name && is_email( $email ) ) {
			$headers[] = 'From: ' . $name . ' <' . $email . '>';
		}
		return $headers;
	}

	private function render( $template, array $context, $subject ) {
		$value = strtr( (string) $template, $context );
		return $subject ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
	}
}
