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
	 * @param array $arguments Status, search, job_id, page, and per_page.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function search( array $arguments = array() );

	/**
	 * Count applications by status.
	 *
	 * @return array<string,int>
	 */
	public function counts();

	/**
	 * Fetch a bounded recent-applicant list.
	 *
	 * @param int $limit Maximum rows, capped by the implementation.
	 * @return array
	 */
	public function recent( $limit = 5 );

	/**
	 * Iterate export rows in bounded batches.
	 *
	 * @param array $arguments Optional status and job_id filters.
	 * @return \Generator
	 */
	public function export_rows( array $arguments = array() );
}
