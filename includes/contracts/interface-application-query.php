<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded read model for candidate applications.
 */
interface Application_Query {
	/**
	 * Search applications with stable pagination.
	 *
	 * @param array $arguments Statuses, candidate/email search, jobs, notification states, received dates, stage_changed_before, sorting, author scope, and pagination.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function search( array $arguments = array() );

	/**
	 * Count applications by status.
	 *
	 * @return array<string,int>
	 */
	public function counts( array $arguments = array() );

	/**
	 * Fetch a bounded recent-applicant list.
	 *
	 * @param int $limit Maximum rows, capped by the implementation.
	 * @param array $arguments Optional author_id filter.
	 * @return array
	 */
	public function recent( $limit = 5, array $arguments = array() );

	/**
	 * Count applications for a bounded set of jobs.
	 *
	 * @param int[] $job_ids Job post IDs.
	 * @param array $arguments Optional author_id filter.
	 * @return array<int,int> Counts keyed by job ID.
	 */
	public function counts_by_job( array $job_ids, array $arguments = array() );

	/**
	 * Count each workflow status for a bounded set of jobs.
	 *
	 * @return array<int,array<string,int>> Counts keyed by job ID and status.
	 */
	public function counts_by_job_and_status( array $job_ids, array $arguments = array() );

	/**
	 * Iterate export rows in bounded batches.
	 *
	 * @param array $arguments Optional Applications DataViews filters and author scope.
	 * @return \Generator
	 */
	public function export_rows( array $arguments = array() );
}
