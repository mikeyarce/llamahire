<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates candidate-record and private-resume lifecycle operations.
 */
interface Candidate_Data_Lifecycle {
	/**
	 * Remove a bounded set of applications older than the configured retention period.
	 *
	 * @param int      $limit Maximum records to inspect.
	 * @param int|null $now   Optional UTC timestamp for deterministic tests.
	 * @return array{examined:int,erased:int,failed:int}
	 */
	public function cleanup_expired( $limit = 250, $now = null );

	/**
	 * Permanently erase one application and its private resume.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|\WP_Error
	 */
	public function erase( $application_id );

	/**
	 * Delete only the resume attached to an application.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|\WP_Error
	 */
	public function delete_resume( $application_id );

	/**
	 * Validate and replace an application's private resume.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $file           One normalized $_FILES item.
	 * @return bool|\WP_Error
	 */
	public function replace_resume( $application_id, array $file );
}
