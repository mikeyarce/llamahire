<?php
/** Remove every disposable record and file created by the browser suite. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

global $wpdb;

$table = \LlamaHire\Applications::table();
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, resume_path FROM {$table} WHERE email = %s", 'browser-test@example.test' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
$store = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::RESUME_STORAGE );
$repo  = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
foreach ( $rows as $row ) {
	if ( $row->resume_path ) {
		$store->delete( $row->resume_path );
	}
	$repo->delete( $row->id );
}

$job_id = absint( get_option( 'llamahire_e2e_job_id' ) );
if ( $job_id ) {
	wp_delete_post( $job_id, true );
}
delete_option( 'llamahire_e2e_job_id' );
$privacy_page_id = absint( get_option( 'llamahire_e2e_privacy_page_id' ) );
if ( $privacy_page_id ) {
	wp_delete_post( $privacy_page_id, true );
}
delete_option( 'llamahire_e2e_privacy_page_id' );
$settings = \LlamaHire\Settings::get();
$careers_page = \LlamaHire\Settings::public_page( $settings['careers_page_id'] );
if ( $careers_page && 'LlamaHire E2E Careers' === $careers_page->post_title ) {
	wp_delete_post( $careers_page->ID, true );
	$settings['careers_page_id'] = 0;
	update_option( \LlamaHire\Settings::OPTION, $settings, false );
}
WP_CLI::success( 'LlamaHire browser fixtures removed.' );
