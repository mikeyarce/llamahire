<?php
namespace LlamaHire\Services;

use LlamaHire\Capabilities;
use LlamaHire\Contracts\Application_Repository;
use LlamaHire\Ownership;
use LlamaHire\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Default adapter for Free's ownership boundary; concrete implementation is internal.
 */
final class Extension_Access implements \LlamaHire\Contracts\Extension_Access {
	private $repository;

	public function __construct( Application_Repository $repository ) {
		$this->repository = $repository;
	}

	/** @internal Bind the final registered repository before the registry locks. */
	public function set_repository( Application_Repository $repository ) {
		$this->repository = $repository;
	}

	public function site_context() {
		return array( 'site_id' => get_current_blog_id(), 'mode' => Settings::site_mode() );
	}

	public function job_context( $job_id, $user_id = 0 ) {
		if ( ! $this->can_manage_job( $job_id, $user_id ) ) {
			return null;
		}
		$job = get_post( absint( $job_id ) );
		if ( ! $job ) {
			return null;
		}
		return array_merge( $this->site_context(), array( 'job_id' => (int) $job->ID, 'owner_id' => (int) $job->post_author ) );
	}

	public function can_manage_job( $job_id, $user_id = 0 ) {
		return Ownership::user_can_manage_job( $job_id, $user_id );
	}

	public function can_access_application( $application_id, $capability, $user_id = 0 ) {
		if ( null === $this->application_scope( $capability, $user_id ) ) {
			return false;
		}
		$application = $this->repository->find( absint( $application_id ) );
		return $application && $this->can_manage_job( $application->job_id, $user_id );
	}

	public function application_scope( $capability, $user_id = 0 ) {
		$allowed = array( Capabilities::VIEW_APPLICATIONS, Capabilities::MANAGE_APPLICATIONS, Capabilities::EXPORT_APPLICATIONS, Capabilities::DOWNLOAD_RESUMES, Capabilities::RETRY_NOTIFICATIONS, Capabilities::ERASE_APPLICATIONS );
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id || ! in_array( $capability, $allowed, true ) || ! user_can( $user_id, $capability ) ) {
			return null;
		}
		return array( 'author_id' => user_can( $user_id, 'edit_others_llamahire_jobs' ) ? 0 : $user_id );
	}
}
