<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the core application notifications.
 */
interface Notification_Service {
	/**
	 * Notify the hiring inbox and candidate about a stored application.
	 *
	 * @param array $application Stored application data, including its ID.
	 * @param int   $job_id      Job post ID.
	 * @param string[] $channels Channels to attempt: employer and/or candidate.
	 * @return array{employer:bool,candidate:bool,error_codes:array}
	 */
	public function application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) );
}
