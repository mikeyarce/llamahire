<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership-aware context and candidate authorization for extensions.
 */
interface Extension_Access {
	/**
	 * Return the current site ID and normalized company/job_board mode.
	 *
	 * @return array
	 */
	public function site_context();

	/**
	 * Return numeric site/job/owner context only for an authorized job manager.
	 *
	 * @param int $job_id Job ID.
	 * @param int $user_id User ID, or zero for the current user.
	 * @return array|null Null when unavailable or unauthorized.
	 */
	public function job_context( $job_id, $user_id = 0 );

	/**
	 * Check ownership-aware management permission, including moderated employer jobs.
	 *
	 * @param int $job_id Job ID.
	 * @param int $user_id User ID, or zero for the current user.
	 * @return bool
	 */
	public function can_manage_job( $job_id, $user_id = 0 );

	/**
	 * Check a documented granular candidate capability and job ownership.
	 *
	 * @param int $application_id Application ID.
	 * @param string $capability A Free candidate-data capability constant.
	 * @param int $user_id User ID, or zero for the current user.
	 * @return bool
	 */
	public function can_access_application( $application_id, $capability, $user_id = 0 );

	/**
	 * Return a repository/query author scope, or null when access is denied.
	 *
	 * @param string $capability A Free candidate-data capability constant.
	 * @param int $user_id User ID, or zero for the current user.
	 * @return array|null author_id is zero only for an authorized board-wide manager.
	 */
	public function application_scope( $capability, $user_id = 0 );
}
