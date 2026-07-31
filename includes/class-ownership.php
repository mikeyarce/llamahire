<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Central authorization boundary for multi-employer job and candidate data.
 */
final class Ownership {
	/**
	 * Return the current user's required job-author scope, or zero for board-wide managers.
	 */
	public static function current_author_scope() {
		return current_user_can( 'edit_others_llamahire_jobs' ) ? 0 : get_current_user_id();
	}

	public static function query_arguments() {
		$author_id = self::current_author_scope();
		return $author_id ? array( 'author_id' => $author_id ) : array();
	}

	public static function user_can_manage_job( $job_id, $user_id = 0 ) {
		$job = get_post( absint( $job_id ) );
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $job || Jobs::POST_TYPE !== $job->post_type || ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'edit_others_llamahire_jobs' ) ) {
			return user_can( $user_id, 'edit_post', $job->ID );
		}
		return (int) $job->post_author === $user_id && user_can( $user_id, 'edit_post', $job->ID );
	}

	public static function user_can_access_application( $application_id, $capability, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $capability ) ) {
			return false;
		}
		$application = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( absint( $application_id ) );
		return $application && self::user_can_manage_job( $application->job_id, $user_id );
	}

	private function __construct() {}
}
