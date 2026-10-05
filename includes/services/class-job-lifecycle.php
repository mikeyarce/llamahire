<?php
namespace LlamaHire\Services;

use LlamaHire\Job_Publication;
use LlamaHire\Listing_Rules;
use LlamaHire\Listing_Store;

defined( 'ABSPATH' ) || exit;

/** Public service adapter; policy and storage implementations remain internal. */
final class Job_Lifecycle implements \LlamaHire\Contracts\Job_Lifecycle {
	public function context( $job_id ) { return Job_Publication::context( $job_id ); }
	public function approve( $job_id ) { return Job_Publication::approve( $job_id ); }
	public function reconcile( $job_id ) { return Job_Publication::reconcile( $job_id ); }
	public function suspend( $job_id ) { return Job_Publication::suspend( $job_id ); }
	public function period( $job_id, $period_key = '' ) {
		$id = Listing_Rules::id( $job_id );
		if ( ! $id || ! is_string( $period_key ) || ( '' !== $period_key && ! preg_match( '/^[a-z][a-z0-9_-]{0,63}:[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $period_key ) ) ) { return Listing_Store::failure(); }
		$row = Listing_Store::period( $id, $period_key );
		if ( ! $row || is_wp_error( $row ) ) { return $row; }
		return array_merge( $row, array( 'site_id' => get_current_blog_id(), 'job_id' => (int) $row['job_id'], 'owner_id' => (int) $row['owner_id'], 'days' => (int) $row['days'] ) );
	}
}
