<?php
/** Real WordPress publication/period checks. Fixtures contain no candidate records. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local site required.' ); }

use LlamaHire\Job_Publication;
use LlamaHire\Jobs;
use LlamaHire\Listing_Store;
use LlamaHire\Service_IDs;

$count = 0;
$assert = static function ( $value, $name ) use ( &$count ) { ++$count; if ( ! $value ) { throw new RuntimeException( $name ); } };
$provider = new class implements \LlamaHire\Contracts\Listing_Policy {
	public $jobs = array();
	public $invalid = false;
	public function evaluate( array $context ) {
		if ( $this->invalid ) { return array( 'eligible' => 'true' ); }
		return $this->jobs[ $context['site_id'] ][ $context['job_id'] ] ?? null;
	}
};
$register = static function ( $providers ) use ( $provider ) { $providers['publication_fixture'] = $provider; return $providers; };
add_filter( 'llamahire_listing_policies', $register );
$site = get_current_blog_id();
$actor = get_current_user_id();
$users = array(); $jobs = array(); $mails = 0;
$mail = static function ( $result ) use ( &$mails ) { ++$mails; return true; };
add_filter( 'pre_wp_mail', $mail );
$services = \LlamaHire\Plugin::instance()->services();
$lifecycle = $services->get( Service_IDs::JOB_LIFECYCLE );
$admin = wp_create_user( 'listing-admin-' . wp_generate_uuid4(), wp_generate_password(), wp_generate_uuid4() . '@example.test' );
$owner = wp_create_user( 'listing-owner-' . wp_generate_uuid4(), wp_generate_password(), wp_generate_uuid4() . '@example.test' );
$users = array( $admin, $owner );
( new WP_User( $admin ) )->set_role( 'administrator' );
( new WP_User( $owner ) )->set_role( \LlamaHire\Capabilities::EMPLOYER_ROLE );
$make = static function ( $paid = false ) use ( $provider, $site, $owner, &$jobs ) {
	$id = wp_insert_post( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Fictional publication fixture', 'post_content' => 'A public fictional listing used only by the disposable test.', 'post_author' => $owner ) );
	$jobs[] = $id;
	Jobs::set_meta( $id, array( 'organization_name' => 'Fictional listing organization', 'workplace' => 'remote', 'applicant_countries' => 'US', 'application_method' => 'internal', 'application_target' => 'listing@example.test' ) );
	$provider->jobs[ $site ][ $id ] = array( 'eligible' => $paid, 'period_required' => true, 'period' => array( 'id' => wp_generate_uuid4(), 'predecessor' => '', 'days' => 30 ) );
	return $id;
};

try {
	// Approval first does not publish or start duration; a verified policy then converges.
	wp_set_current_user( $admin ); $job = $make(); $mails = 0;
	wp_update_post( array( 'ID' => $job, 'post_status' => 'publish' ) );
	$assert( 'pending' === get_post_status( $job ) && $lifecycle->context( $job )['approved'], 'Approval was not retained independently of payment.' );
	$assert( null === $lifecycle->period( $job ) && '' === Jobs::get_meta( $job )['listing_expires'] && 0 === $mails, 'Unpaid approval started a period or notified publication.' );
	$assert( ! $lifecycle->context( $job )['owner_eligible'], 'A role alone established verified employer enrollment.' );
	update_user_meta( $owner, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_APPROVED );
	$assert( $lifecycle->context( $job )['owner_eligible'], 'Verified approved employer enrollment was not exposed.' );
	$provider->jobs[ $site ][ $job ]['eligible'] = true;
	wp_set_current_user( 0 );
	$assert( true === $lifecycle->reconcile( $job ), 'Verified payment did not publish an approved job.' );
	$first = $lifecycle->period( $job );
	$assert( is_array( $first ) && 'publish' === get_post_status( $job ) && Jobs::is_open( $job ) && 1 === $mails, 'Committed publication was not available or notified exactly once.' );
	$assert( $first['expires'] === Jobs::get_meta( $job )['listing_expires'] && current_datetime()->modify( '+30 days' )->format( 'Y-m-d' ) === $first['expires'], 'Canonical expiry differs from the saved job model.' );
	$assert( true === $lifecycle->reconcile( $job ) && $first === $lifecycle->period( $job ) && 1 === $mails, 'Repeated reconciliation restarted duration or notifications.' );

	// Canonical dates survive administrator metadata writes and manual close/reopen.
	wp_set_current_user( $admin );
	Jobs::set_meta( $job, array( 'listing_expires' => '2099-12-31', 'closed' => '1' ) );
	$assert( ! Jobs::is_open( $job ) && $first['expires'] === Jobs::get_meta( $job )['listing_expires'], 'Closing or editing reset the paid period.' );
	Jobs::set_meta( $job, array( 'closed' => '0' ) );
	$assert( Jobs::is_open( $job ) && $first === $lifecycle->period( $job ), 'Reopening changed the original period.' );
	$meta = Jobs::get_meta( $job ); $meta['listing_expires'] = '2099-01-01';
	update_post_meta( $job, Jobs::META_KEY, $meta ); update_post_meta( $job, Jobs::META_EXPIRY, '2099-01-01' );
	$assert( $first['expires'] === Jobs::get_meta( $job )['listing_expires'] && $first['expires'] === get_post_meta( $job, Jobs::META_EXPIRY, true ), 'Native metadata bypass changed canonical expiry.' );
	$assert( ! delete_post_meta( $job, Jobs::META_KEY ) && ! delete_post_meta( $job, Jobs::META_EXPIRY ) && $first['expires'] === Jobs::get_meta( $job )['listing_expires'], 'Deleting metadata removed the canonical expiry.' );
	$schema = $services->get( Service_IDs::SCHEMA_BUILDER )->build( $job );
	$assert( ! empty( $schema['validThrough'] ) && 0 === strpos( $schema['validThrough'], $first['expires'] ), 'JobPosting validity differs from the paid expiry.' );

	// Payment first cannot supply moderation. Explicit operator approval completes it.
	$paid_first = $make( true ); wp_set_current_user( 0 );
	$assert( is_wp_error( $lifecycle->reconcile( $paid_first ) ) && 'draft' === get_post_status( $paid_first ) && null === $lifecycle->period( $paid_first ), 'Payment bypassed moderation or started before publication.' );
	wp_set_current_user( $owner );
	$assert( is_wp_error( $lifecycle->approve( $paid_first ) ), 'Employer granted its own moderation approval.' );
	wp_set_current_user( $admin );
	$assert( true === $lifecycle->approve( $paid_first ) && 'publish' === get_post_status( $paid_first ), 'Operator approval did not complete payment-first publication.' );

	// Core's direct publisher also passes the gate; no unchecked public content remains.
	$unpaid = $make(); wp_set_current_user( 0 );
	$before_mail = $mails; wp_publish_post( $unpaid );
	$assert( 'pending' === get_post_status( $unpaid ) && null === $lifecycle->period( $unpaid ) && $before_mail === $mails, 'Direct WordPress publishing bypassed policy.' );
	$provider->jobs[ $site ][ $job ]['eligible'] = false;
	$assert( ! Jobs::is_open( $job ) && array() === $services->get( Service_IDs::SCHEMA_BUILDER )->build( $job ), 'Revoked eligibility still accepts applications or emits schema.' );
	foreach ( array( 'all', 'ids', 'id=>parent' ) as $fields ) {
		$query = new WP_Query( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'publish', 'post__in' => array( $job ), 'fields' => $fields, 'posts_per_page' => 10 ) );
		$assert( array() === $query->posts, 'A public query exposed a revoked listing.' );
	}
	$request = new WP_REST_Request( 'GET', '/wp/v2/' . Jobs::POST_TYPE . '/' . $job );
	$assert( 404 === rest_do_request( $request )->get_status(), 'The public job REST endpoint exposed a revoked listing.' );
	$assert( false !== strpos( \LlamaHire\Blocks::render_form( array( 'jobId' => $job ) ), 'Applications are closed' ), 'Revoked listing still renders an application form.' );
	$assert( is_wp_error( $lifecycle->reconcile( $job ) ) && 'pending' === get_post_status( $job ), 'Reconciliation did not privatize revoked publication.' );

	// A failed period commit rolls back usage and both saved expiry representations.
	wp_set_current_user( $admin ); $failed = $make();
	wp_update_post( array( 'ID' => $failed, 'post_status' => 'publish' ) );
	$provider->jobs[ $site ][ $failed ]['eligible'] = true;
	$before_mail = $mails;
	$fail_commit = static function ( $sql ) { return 'COMMIT' === trim( $sql ) ? 'SELECT * FROM llamahire_missing_period_commit_fixture' : $sql; };
	add_filter( 'query', $fail_commit );
	try { $result = $lifecycle->reconcile( $failed ); }
	finally { remove_filter( 'query', $fail_commit ); }
	$assert( is_wp_error( $result ) && 'pending' === get_post_status( $failed ) && null === $lifecycle->period( $failed ), 'Failed commit exposed a job or consumed a period.' );
	$assert( '' === Jobs::get_meta( $failed )['listing_expires'] && $mails === $before_mail, 'Failed period commit leaked expiry or sent mail.' );
	$assert( (bool) wp_next_scheduled( Job_Publication::RETRY, array( $failed ) ), 'Failed storage did not schedule bounded recovery.' );
	$assert( true === $lifecycle->reconcile( $failed ) && $mails === $before_mail + 1, 'Retry did not converge once storage recovered.' );

	// A different purchase cannot extend an active period; an old key cannot replace a newer one.
	$provider->jobs[ $site ][ $failed ]['period']['predecessor'] = $provider->jobs[ $site ][ $failed ]['period']['id'];
	$provider->jobs[ $site ][ $failed ]['period']['id'] = wp_generate_uuid4();
	$active_period = $lifecycle->period( $failed );
	$assert( is_wp_error( $lifecycle->reconcile( $failed ) ) && $active_period === $lifecycle->period( $failed ), 'A second purchase extended an active period.' );
	// Scheduled approval starts duration only when WordPress actually publishes.
	wp_set_current_user( $admin ); $scheduled = $make( true );
	$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	wp_update_post( array( 'ID' => $scheduled, 'edit_date' => true, 'post_status' => 'future', 'post_date' => get_date_from_gmt( $future ), 'post_date_gmt' => $future ) );
	$assert( 'future' === get_post_status( $scheduled ) && $lifecycle->context( $scheduled )['approved'] && null === $lifecycle->period( $scheduled ), 'Scheduled approval started duration before publication.' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( $lifecycle->reconcile( $scheduled ) ) && 'future' === get_post_status( $scheduled ), 'Payment published a scheduled listing early.' );
	global $wpdb;
	$past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
	$wpdb->update( $wpdb->posts, array( 'post_date' => get_date_from_gmt( $past ), 'post_date_gmt' => $past ), array( 'ID' => $scheduled ) );
	clean_post_cache( $scheduled );
	check_and_publish_future_post( $scheduled );
	$assert( 'publish' === get_post_status( $scheduled ) && Jobs::is_open( $scheduled ) && abs( time() - strtotime( $lifecycle->period( $scheduled )['started_at'] . ' UTC' ) ) < 10, 'Core scheduled publication did not start a current period.' );

	// Content changes need fresh moderation; a new owner cannot inherit paid usage.
	wp_update_post( array( 'ID' => $scheduled, 'post_title' => 'Fictional revised listing' ) );
	$assert( ! $lifecycle->context( $scheduled )['approved'] && ! Jobs::is_open( $scheduled ), 'Unapproved content revision remained public.' );
	wp_set_current_user( $admin ); $scheduled_period = $lifecycle->period( $scheduled );
	$assert( true === $lifecycle->approve( $scheduled ) && $scheduled_period === $lifecycle->period( $scheduled ), 'Fresh moderation reset an existing period.' );
	wp_update_post( array( 'ID' => $scheduled, 'post_author' => $admin ) );
	$assert( ! Jobs::is_open( $scheduled ) && is_wp_error( $lifecycle->approve( $scheduled ) ) && $scheduled_period === $lifecycle->period( $scheduled ), 'Ownership transfer reused the previous owner period.' );
	$provider->jobs[ $site ][ $scheduled ]['period']['predecessor'] = $provider->jobs[ $site ][ $scheduled ]['period']['id'];
	$provider->jobs[ $site ][ $scheduled ]['period']['id'] = wp_generate_uuid4();
	$assert( true === $lifecycle->reconcile( $scheduled ) && $admin === $lifecycle->period( $scheduled )['owner_id'], 'A separately approved new owner could not start a new period.' );

	// Simulate elapsed calendar time in the internal Free test repository only.
	$renewal = $make( true ); $lifecycle->approve( $renewal );
	$old_offer = $provider->jobs[ $site ][ $renewal ]['period'];
	$old_period = $lifecycle->period( $renewal );
	$yesterday = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
	$wpdb->update( Listing_Store::table( 'periods' ), array( 'expires' => $yesterday ), array( 'period_key' => $old_period['period_key'] ) );
	$assert( ! Jobs::is_open( $renewal ) && is_wp_error( $lifecycle->reconcile( $renewal ) ), 'An expired period remained available.' );
	$provider->jobs[ $site ][ $renewal ]['period'] = array( 'id' => wp_generate_uuid4(), 'predecessor' => $old_offer['id'], 'days' => 7 );
	$assert( true === $lifecycle->reconcile( $renewal ) && 7 === $lifecycle->period( $renewal )['days'], 'A new purchase could not renew an expired approved listing.' );
	$new_period = $lifecycle->period( $renewal );
	$provider->jobs[ $site ][ $renewal ]['period'] = $old_offer;
	$assert( is_wp_error( $lifecycle->reconcile( $renewal ) ) && $new_period === $lifecycle->period( $renewal ), 'Replayed old usage replaced the current period.' );

	// Leases serialize callers and stale releases cannot remove their successor.
	$locked = $make( true );
	$token = \LlamaHire\Listing_Lock::acquire( $locked );
	$assert( $token && ! \LlamaHire\Listing_Lock::acquire( $locked ) && is_wp_error( $lifecycle->reconcile( $locked ) ), 'A concurrent publisher bypassed the lease.' );
	$expired = ( time() - 1 ) . ':' . wp_generate_uuid4();
	update_option( 'llamahire_listing_lock_' . $locked, $expired, false );
	$successor = \LlamaHire\Listing_Lock::acquire( $locked );
	\LlamaHire\Listing_Lock::release( $locked, $token );
	$assert( $successor && \LlamaHire\Listing_Lock::owns( $locked, $successor ), 'Stale release removed a successor lease.' );
	\LlamaHire\Listing_Lock::release( $locked, $successor );

	// Public storage failure is opaque and restores the caller's error policy.
	$fail_read = static function ( $sql ) { return false !== strpos( $sql, 'SELECT * FROM `' . Listing_Store::table( 'states' ) . '`' ) ? 'SELECT * FROM llamahire_missing_listing_read_fixture' : $sql; };
	$previous_suppression = $wpdb->suppress_errors( false );
	add_filter( 'query', $fail_read ); ob_start();
	try { $read_result = $lifecycle->context( $paid_first ); $output = ob_get_contents(); }
	finally { ob_end_clean(); remove_filter( 'query', $fail_read ); $restored = $wpdb->suppress_errors( $previous_suppression ); }
	$assert( is_wp_error( $read_result ) && '' === $output && false === $restored, 'Storage failure leaked output or changed caller error handling.' );

	// Unmanaged approval is explicit and cannot silently leave an approved draft.
	$unmanaged = $make(); unset( $provider->jobs[ $site ][ $unmanaged ] );
	$assert( is_wp_error( $lifecycle->approve( $unmanaged ) ) && 'draft' === get_post_status( $unmanaged ), 'Unmanaged approval unexpectedly mutated the job.' );
	$provider->invalid = true;
	$assert( ! Jobs::is_open( $paid_first ) && is_wp_error( $lifecycle->reconcile( $paid_first ) ), 'Malformed provider state did not fail closed.' );
	$provider->invalid = false;

	echo 'Listing publication assertions passed: ' . $count . ".\n";
} finally {
	$provider->invalid = false; $provider->jobs = array();
	wp_set_current_user( $admin );
	global $wpdb;
	foreach ( $jobs as $id ) {
		wp_clear_scheduled_hook( Job_Publication::RETRY, array( $id ) );
		$wpdb->delete( Listing_Store::table( 'periods' ), array( 'job_id' => $id ) );
		$wpdb->delete( Listing_Store::table( 'states' ), array( 'job_id' => $id ) );
		wp_delete_post( $id, true );
	}
	remove_filter( 'pre_wp_mail', $mail );
	remove_filter( 'llamahire_listing_policies', $register );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	wp_set_current_user( $actor );
}
