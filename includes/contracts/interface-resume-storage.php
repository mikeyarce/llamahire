<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Private resume storage and authorized delivery contract.
 */
interface Resume_Storage {
	/**
	 * Store one validated browser upload.
	 *
	 * @param array $file   One normalized $_FILES item.
	 * @param int   $job_id Job post ID.
	 * @return array|\WP_Error Opaque token and display name, or an error.
	 */
	public function store_upload( array $file, $job_id );

	/**
	 * Delete a previously stored opaque token.
	 *
	 * @param string $token Storage token returned by store_upload().
	 * @return bool
	 */
	public function delete( $token );

	/**
	 * Determine whether an application has an available resume.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	public function has_resume( $application_id );

	/**
	 * Stream an application's resume and terminate on success.
	 *
	 * The caller must authorize the request before invoking this method.
	 *
	 * @param int $application_id Application ID.
	 * @return \WP_Error Returns only when the resume cannot be streamed.
	 */
	public function stream( $application_id );

	/**
	 * Inspect storage safety and writability without exposing its path.
	 *
	 * @return array{available:bool,outside_webroot:bool}
	 */
	public function health();
}
