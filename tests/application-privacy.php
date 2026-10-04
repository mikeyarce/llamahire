<?php
/** Public privacy authorization against real WordPress and portable storage. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local site required.' ); }
use LlamaHire\Capabilities;
use LlamaHire\Service_IDs;
$services = \LlamaHire\Plugin::instance()->services();
$privacy = $services->get( Service_IDs::APPLICATION_PRIVACY );
$repository = $services->get( Service_IDs::APPLICATION_REPOSITORY );
$access = $services->get( Service_IDs::EXTENSION_ACCESS );
$count = 0; $users = array(); $jobs = array(); $applications = array(); $actor = get_current_user_id();
$assert = static function ( $condition, $label ) use ( &$count ) { ++$count; if ( ! $condition ) { throw new RuntimeException( $label ); } };
$mail = static function () { return true; }; add_filter( 'pre_wp_mail', $mail );
try {
	foreach ( array( 'administrator', Capabilities::EMPLOYER_ROLE, Capabilities::EMPLOYER_ROLE, 'subscriber' ) as $role ) {
		$id = wp_create_user( 'privacy-fixture-' . wp_generate_uuid4(), wp_generate_password(), wp_generate_uuid4() . '@example.test' );
		$assert( ! is_wp_error( $id ), 'Privacy fixture user failed.' );
		( new WP_User( $id ) )->set_role( $role ); $users[] = $id;
	}
	$email = wp_generate_uuid4() . '@example.test';
	foreach ( array( $users[1], $users[2], $users[1] ) as $owner ) {
		$job = wp_insert_post( array( 'post_type' => 'llamahire_job', 'post_status' => 'draft', 'post_title' => 'Fictional privacy listing', 'post_author' => $owner ) );
		$jobs[] = $job;
		$id = $repository->create( array( 'job_id' => $job, 'name' => 'Fictional candidate', 'email' => $email, 'cover_letter' => 'Fictional private material' ) );
		$assert( is_int( $id ) && $id > 0, 'Privacy fixture application failed.' ); $applications[] = $id;
	}
	wp_delete_post( $jobs[2], true );
	wp_set_current_user( 0 );
	$assert( is_wp_error( $privacy->references( $email, 'export' ) ), 'Anonymous privacy read was accepted.' );
	wp_set_current_user( $users[3] );
	$user = new WP_User( $users[3] ); $user->add_cap( 'manage_options' );
	$assert( is_wp_error( $privacy->references( $email, 'export' ) ), 'Core privacy capability bypassed Free granular permission.' );
	wp_set_current_user( $users[1] );
	$assert( is_wp_error( $privacy->references( $email, 'export' ) ), 'Free export capability bypassed WordPress privacy permission.' );
	$user = new WP_User( $users[1] ); $user->add_cap( 'manage_options' ); $user->add_cap( 'erase_others_personal_data' ); $user->add_cap( Capabilities::ERASE_APPLICATIONS );
	wp_set_current_user( 0 ); wp_set_current_user( $users[1] );
	$expected = array( array( 'application_id' => $applications[0], 'job_id' => $jobs[0] ) );
	$assert( $expected === $privacy->references( strtoupper( $email ), 'export' )['items'], 'Owner privacy export crossed a job boundary.' );
	$assert( $expected === $privacy->references( $email, 'erase' )['items'], 'Owner privacy erasure acquired foreign or orphan records.' );
	wp_set_current_user( $users[0] );
	$all = $privacy->references( $email, 'erase' );
	$assert( 3 === count( $all['items'] ) && true === $all['done'] && get_current_blog_id() === $all['site_id'], 'Board privacy operator could not reach exact-identity orphan references.' );
	$assert( ! $access->can_access_application( $applications[2], Capabilities::VIEW_APPLICATIONS ), 'Privacy exception weakened ordinary missing-job review.' );
	$assert( false === strpos( wp_json_encode( $all ), $email ) && false === strpos( wp_json_encode( $all ), 'Fictional candidate' ), 'Privacy references exposed candidate values.' );
	$assert( array() === $privacy->references( 'different-' . $email, 'export' )['items'], 'Privacy lookup accepted a partial email match.' );
	$assert( array() === $privacy->references( $email, 'export', 2 )['items'], 'Privacy pagination repeated the first page.' );
	$failure = static function ( $sql ) { return 0 === strpos( $sql, 'SELECT applications.id, applications.job_id,' ) ? 'SELECT * FROM llamahire_missing_privacy_fixture' : $sql; };
	add_filter( 'query', $failure );
	try { $failed = $privacy->references( $email, 'export' ); }
	finally { remove_filter( 'query', $failure ); }
	$assert( is_wp_error( $failed ), 'A failed database read became a successful empty privacy export.' );
	$assert( null !== $repository->find( $applications[0] ), 'A reference lookup mutated Free candidate data.' );
	echo 'Application privacy assertions passed: ' . $count . ".\n";
} finally {
	foreach ( $applications as $id ) { $services->get( Service_IDs::CANDIDATE_DATA )->erase( $id ); }
	foreach ( $jobs as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	wp_set_current_user( $actor ); remove_filter( 'pre_wp_mail', $mail );
}
