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
WP_CLI::success( 'LlamaHire browser fixtures removed.' );
