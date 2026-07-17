<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Builds structured data for a job.
 */
interface Schema_Builder {
	/**
	 * Build a JobPosting entity.
	 *
	 * @param int $job_id Job post ID.
	 * @return array Empty when the job must not emit JobPosting markup.
	 */
	public function build( $job_id );
}
