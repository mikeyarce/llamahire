<?php
/** Temporary data for the focused review-fix browser regressions in wp-env. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
$option = 'llamahire_e2e_review_fixes';
$registry = get_option( $option, array() );
$repo = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
if ( $registry ) {
	global $wpdb;
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . \LlamaHire\Applications::table() . ' WHERE job_id = %d', $registry['job'] ) ) as $id ) {
		$repo->delete( $id );
	}
	$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $registry['job'] ) );
	wp_delete_post( $registry['job'], true );
	if ( false === $registry['settings'] ) { delete_option( \LlamaHire\Settings::OPTION ); }
	else { update_option( \LlamaHire\Settings::OPTION, $registry['settings'], false ); }
	delete_option( $option );
}
if ( 'cleanup' === ( $args[0] ?? '' ) ) { return; }
$original = get_option( \LlamaHire\Settings::OPTION, false );
$settings = \LlamaHire\Settings::get();
$settings['site_mode'] = 'company';
$settings['anti_spam_provider'] = 'none';
$settings['application_phone'] = 'hidden';
$settings['application_resume'] = 'hidden';
$settings['application_letter'] = 'hidden';
$settings['google_geocoding_api_key'] = '';
update_option( \LlamaHire\Settings::OPTION, $settings, false );
$job = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_title' => 'Pagination regression fixture', 'post_status' => 'publish', 'post_content' => 'Synthetic browser regression fixture.' ) );
update_option( $option, array( 'job' => $job, 'settings' => $original ), false );
\LlamaHire\Jobs::set_meta( $job, array( 'closed' => '0', 'deadline' => '', 'listing_expires' => '', 'application_method' => 'internal' ) );
for ( $i = 0; $i < 101; $i++ ) {
	$repo->create( array( 'job_id' => $job, 'name' => 'Pagination Fixture ' . $i, 'email' => 'page-fixture-' . $i . '@example.test', 'status' => 0 === $i ? 'offer' : 'new' ) );
}
echo wp_json_encode( array( 'job' => $job, 'url' => get_permalink( $job ) ) );
