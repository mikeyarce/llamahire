<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence contract for candidate applications.
 */
interface Application_Repository {
	/**
	 * Store an application.
	 *
	 * @param array $application Validated application fields.
	 * @return int|\WP_Error Application ID or an error.
	 */
	public function create( array $application );

	/**
	 * Store an application once for a unique submission key.
	 *
	 * @param array $application Validated application fields.
	 * @return array|\WP_Error Array containing id and created, or an error.
	 */
	public function create_once( array $application );

	/**
	 * Find one application.
	 *
	 * @param int $application_id Application ID.
	 * @return object|null
	 */
	public function find( $application_id );

	/**
	 * Update allowed application fields.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $changes        Fields to update.
	 * @return bool|\WP_Error
	 */
	public function update( $application_id, array $changes );

	/**
	 * Delete one application record.
	 *
	 * File deletion remains the responsibility of the storage service.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	public function delete( $application_id );

	/**
	 * Persist a notification attempt without storing mail error messages.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $result         Notification service result.
	 * @return bool|\WP_Error
	 */
	public function record_notification_result( $application_id, array $result );
}
