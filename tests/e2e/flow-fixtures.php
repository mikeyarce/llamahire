<?php
/** Independent browser-flow fixtures. Never run against the Studio site. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// This helper installs a test transport, so restrict it to the disposable site.
if ( 'http://localhost:8897' !== untrailingslashit( home_url() ) ) {
	WP_CLI::error( 'Flow fixtures require the isolated wp-env site on port 8897.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$option   = 'llamahire_e2e_flows';
$registry = get_option( $option, array() );
$fixture_action   = $args[0] ?? 'setup';
$repo     = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
$transport = 'llamahire-e2e-transport.php';

if ( 'access' === $fixture_action ) {
	// Generate nonces for the attacking browser's real session. This ensures
	// permission tests reach ownership checks rather than merely failing a nonce.
	wp_set_current_user( $registry['users']['other'] );
	$_COOKIE[ LOGGED_IN_COOKIE ] = rawurldecode( $args[1] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Reproduce the isolated browser session in CLI only.
	echo wp_json_encode( array(
		'resume' => \LlamaHire\Applications::resume_url( $registry['candidate'] ),
		'export' => html_entity_decode( wp_nonce_url( admin_url( 'admin-post.php?action=llamahire_export' ), 'llamahire_export' ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8' ),
		'valid_session' => WP_Session_Tokens::get_instance( $registry['users']['other'] )->verify( wp_get_session_token() ),
		'erase_nonce' => wp_create_nonce( 'llamahire_erase_application_' . $registry['candidate'] ),
	) );
	return;
}
if ( 'track-authored' === $fixture_action ) {
	$authored_post = get_post( absint( $args[1] ) );
	if ( ! $authored_post || \LlamaHire\Jobs::POST_TYPE !== $authored_post->post_type || $registry['authored_title'] !== $authored_post->post_title ) {
		WP_CLI::error( 'Only the synthetic browser-authored job can be registered.' );
	}
	$registry['jobs']['authored'] = $authored_post->ID;
	update_option( $option, $registry, false );
	return;
}
if ( 'state' === $fixture_action ) {
	$jobs = array();
	foreach ( $registry['jobs'] as $key => $record_id ) {
		$jobs[ $key ] = array( 'status' => get_post_status( $record_id ), 'meta' => \LlamaHire\Jobs::get_meta( $record_id ) );
	}
	$user = get_user_by( 'email', $registry['registration_email'] );
	echo wp_json_encode( array(
		'jobs' => $jobs,
		'applications' => \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_QUERY )->search( array( 'job_ids' => array_values( $registry['jobs'] ), 'per_page' => 100 ) ),
		'candidate' => $repo->find( $registry['candidate'] ),
		'verification' => get_option( 'llamahire_e2e_verification_url', '' ),
		'registration_user' => $user ? $user->user_login : '',
		'registration_status' => $user ? get_user_meta( $user->ID, \LlamaHire\Employer_Registration::STATUS_META, true ) : '',
		'mail_count' => (int) get_option( 'llamahire_e2e_mail_count', 0 ),
		'spam_checks' => (int) get_option( 'llamahire_e2e_spam_checks', 0 ),
	) );
	return;
}
if ( in_array( $fixture_action, array( 'mail-fail', 'mail-success' ), true ) ) {
	update_option( 'llamahire_e2e_mail_mode', 'mail-fail' === $fixture_action ? 'fail' : 'success', false );
	return;
}

if ( $registry ) {
	global $wpdb;
	// Include drafts created through the browser, without touching other employers.
	$job_ids = array_values( $registry['jobs'] );
	if ( ! empty( $registry['authored_title'] ) ) {
		$job_ids = array_merge( $job_ids, get_posts( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'title' => $registry['authored_title'], 'post_status' => array( 'publish', 'draft', 'pending', 'trash', 'auto-draft' ), 'fields' => 'ids', 'posts_per_page' => 100 ) ) );
	}
	foreach ( $registry['users'] as $user_id ) {
		$job_ids = array_merge( $job_ids, get_posts( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'pending', 'trash', 'auto-draft' ), 'author' => $user_id, 'fields' => 'ids', 'posts_per_page' => 100 ) ) );
	}
	foreach ( array_unique( $job_ids ) as $job_id ) {
		$table = \LlamaHire\Applications::table();
		$record_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_id = %d", $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Disposable fixture cleanup uses a trusted table name.
		foreach ( $record_ids as $record_id ) {
			\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::CANDIDATE_DATA )->erase( $record_id );
		}
		$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Disposable fixture cleanup.
		wp_delete_post( $job_id, true );
	}
	foreach ( $registry['pages'] as $record_id ) {
		wp_delete_post( $record_id, true );
	}
	$registered = get_user_by( 'email', $registry['registration_email'] );
	foreach ( array_unique( array_merge( $registry['users'], $registered ? array( $registered->ID ) : array() ) ) as $record_id ) {
		wp_delete_user( $record_id );
	}
	foreach ( $registry['options'] as $key => $value ) {
		if ( false === $value ) {
			delete_option( $key );
		} else {
			update_option( $key, $value, false );
		}
	}
}
deactivate_plugins( $transport );
if ( file_exists( WP_PLUGIN_DIR . '/' . $transport ) ) {
	wp_delete_file( WP_PLUGIN_DIR . '/' . $transport );
}
foreach ( array( $option, 'llamahire_e2e_verification_url', 'llamahire_e2e_mail_mode', 'llamahire_e2e_mail_count', 'llamahire_e2e_spam_checks' ) as $key ) {
	delete_option( $key );
}
if ( 'cleanup' === $fixture_action ) {
	return;
}

$suffix = strtolower( wp_generate_password( 8, false, false ) );
$registry = array( 'jobs' => array(), 'users' => array(), 'pages' => array(), 'options' => array(), 'registration_email' => 'flow-register-' . $suffix . '@example.test', 'authored_title' => 'Flow Admin ' . $suffix );
foreach ( array( \LlamaHire\Settings::OPTION, \LlamaHire\Setup::OPTION ) as $key ) {
	$registry['options'][ $key ] = get_option( $key, false );
}
// Save ownership before creating data so an interrupted setup can be cleaned.
update_option( $option, $registry, false );
$save = static function () use ( &$registry, $option ) { update_option( $option, $registry, false ); };
$settings = \LlamaHire\Settings::defaults();
$settings['site_mode'] = 'company' === ( $args[1] ?? '' ) ? 'company' : 'job_board';
$settings['name'] = 'Flow Board';
$settings['notification_email'] = 'flow-inbox@example.test';
$settings['email_sender_email'] = 'flow-sender@example.test';
$settings['application_phone'] = 'optional';
$settings['application_resume'] = 'optional';
$settings['application_letter'] = 'optional';
foreach ( array( 'employer_registration' => 'llamahire_employer_registration', 'submit_job' => 'llamahire_submit_job', 'my_jobs' => 'llamahire_my_jobs', 'employer_account' => 'llamahire_employer_account' ) as $key => $shortcode ) {
	$record_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Flow ' . $key . ' ' . $suffix, 'post_content' => '[' . $shortcode . ']' ), true );
	if ( is_wp_error( $record_id ) ) { WP_CLI::error( $record_id->get_error_message() ); }
	$registry['pages'][ $key ] = $record_id;
	$settings[ $key . '_page_id' ] = $record_id;
	$save();
}
update_option( \LlamaHire\Settings::OPTION, $settings, false );
update_option( \LlamaHire\Setup::OPTION, array( 'version' => \LlamaHire\Setup::VERSION, 'status' => 'completed' ), false );
foreach ( array( 'owner', 'other' ) as $key ) {
	$record_id = wp_create_user( 'flow-' . $key . '-' . $suffix, 'Flow-test-password-123', 'flow-' . $key . '-' . $suffix . '@example.test' );
	if ( is_wp_error( $record_id ) ) { WP_CLI::error( $record_id->get_error_message() ); }
	$user = get_userdata( $record_id );
	$user->set_role( \LlamaHire\Capabilities::EMPLOYER_ROLE );
	update_user_meta( $record_id, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_APPROVED );
	$registry['users'][ $key ] = $record_id;
	$save();
}
foreach ( array( 'open', 'closed', 'expired', 'deadline', 'draft', 'pending', 'changes', 'decline', 'renew', 'url', 'email' ) as $key ) {
	$record_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => in_array( $key, array( 'pending', 'changes', 'decline' ), true ) ? 'pending' : ( 'draft' === $key ? 'draft' : 'publish' ), 'post_author' => $registry['users']['owner'], 'post_title' => 'Flow ' . $key . ' ' . $suffix, 'post_content' => 'A synthetic browser role.' ), true );
	if ( is_wp_error( $record_id ) ) { WP_CLI::error( $record_id->get_error_message() ); }
	$registry['jobs'][ $key ] = $record_id;
	$save();
	\LlamaHire\Jobs::set_meta( $record_id, array(
		'organization_name' => 'Flow Employer', 'organization_url' => 'https://example.test/',
		'workplace' => 'remote', 'applicant_countries' => 'CA', 'employment_type' => 'full_time',
		'closed' => 'closed' === $key ? '1' : '0',
		'deadline' => wp_date( 'Y-m-d', strtotime( 'deadline' === $key ? '-2 days' : '+30 days' ) ),
		'listing_expires' => in_array( $key, array( 'expired', 'renew' ), true ) ? wp_date( 'Y-m-d', strtotime( 'expired' === $key ? '-2 days' : '+2 days' ) ) : '',
		'application_method' => 'url' === $key ? 'external_url' : ( 'email' === $key ? 'external_email' : 'internal' ),
		'application_target' => 'url' === $key ? 'https://example.test/apply' : ( 'email' === $key ? 'jobs@example.test' : 'flow-inbox@example.test' ),
	) );
}
$registry['candidate'] = $repo->create( array( 'job_id' => $registry['jobs']['open'], 'name' => 'Flow Private Candidate', 'email' => 'flow-private-' . $suffix . '@example.test' ) );
if ( is_wp_error( $registry['candidate'] ) ) { WP_CLI::error( $registry['candidate']->get_error_message() ); }
$save();
if ( ! copy( __DIR__ . '/flow-transport.php', WP_PLUGIN_DIR . '/' . $transport ) ) {
	WP_CLI::error( 'Cannot install disposable mail transport.' );
}
$result = activate_plugin( $transport );
if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Disposable CLI setup only.
$output = $registry;
unset( $output['options'] ); // Saved settings are cleanup state, not browser input.
foreach ( $registry['jobs'] as $key => $record_id ) { $output['urls'][ $key ] = get_permalink( $record_id ); }
foreach ( $registry['pages'] as $key => $record_id ) { $output['pages'][ $key ] = get_permalink( $record_id ); }
foreach ( $registry['users'] as $key => $record_id ) { $output['users'][ $key ] = get_userdata( $record_id )->user_login; }
echo wp_json_encode( $output );
