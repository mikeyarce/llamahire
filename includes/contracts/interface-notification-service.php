<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the core application notifications.
 */
interface Notification_Service {
	/**
	 * Compose the configured employer and candidate messages without sending them.
	 *
	 * @param array $application Application or safe preview data.
	 * @param int   $job_id      Job post ID, or zero for a preview.
	 * @return array{employer:array,candidate:array}
	 */
	public function preview( array $application, $job_id );

	/**
	 * Notify the hiring inbox and candidate about a stored application.
	 *
	 * @param array $application Stored application data, including its ID.
	 * @param int   $job_id      Job post ID.
	 * @param string[] $channels Channels to attempt: employer and/or candidate.
	 * @return array{employer:bool,candidate:bool,error_codes:array}
	 */
	public function application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) );

	/**
	 * Send a candidate-free diagnostic message through WordPress mail.
	 *
	 * @param string $to Diagnostic recipient.
	 * @return array{success:bool,error_codes:array}
	 */
	public function test_delivery( $to );
}
