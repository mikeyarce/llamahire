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
	$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $job_id ), array( '%d' ) );
	wp_delete_post( $job_id, true );
}
delete_option( 'llamahire_e2e_job_id' );
$employer_user_id = absint( get_option( 'llamahire_e2e_employer_user_id' ) );
if ( $employer_user_id ) {
	$employer_jobs = get_posts( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'any', 'author' => $employer_user_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
	foreach ( $employer_jobs as $employer_job_id ) {
		$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $employer_job_id ), array( '%d' ) );
		foreach ( get_children( array( 'post_parent' => $employer_job_id, 'post_type' => 'attachment', 'fields' => 'ids', 'numberposts' => -1 ) ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		wp_delete_post( $employer_job_id, true );
	}
	wp_delete_user( $employer_user_id );
}
delete_option( 'llamahire_e2e_employer_user_id' );
$privacy_page_id = absint( get_option( 'llamahire_e2e_privacy_page_id' ) );
if ( $privacy_page_id ) {
	wp_delete_post( $privacy_page_id, true );
}
delete_option( 'llamahire_e2e_privacy_page_id' );
foreach ( array( 'llamahire_e2e_patterns_page_id', 'llamahire_e2e_department_page_id' ) as $page_option ) {
	$page_id = absint( get_option( $page_option ) );
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	delete_option( $page_option );
}
$department_term_id = absint( get_option( 'llamahire_e2e_department_term_id' ) );
if ( $department_term_id ) {
	wp_delete_term( $department_term_id, 'llamahire_department' );
}
delete_option( 'llamahire_e2e_department_term_id' );
$settings = \LlamaHire\Settings::get();
foreach ( array( 'submit_job_page_id', 'my_jobs_page_id' ) as $portal_page_key ) {
	$portal_page = \LlamaHire\Settings::public_page( $settings[ $portal_page_key ] );
	if ( $portal_page && in_array( $portal_page->post_title, array( 'Submit a Job', 'My Jobs' ), true ) ) {
		wp_delete_post( $portal_page->ID, true );
	}
	$settings[ $portal_page_key ] = 0;
}
$settings['site_mode'] = \LlamaHire\Settings::SITE_MODE_COMPANY;
$careers_page = \LlamaHire\Settings::public_page( $settings['careers_page_id'] );
if ( $careers_page && 'LlamaHire E2E Careers' === $careers_page->post_title ) {
	wp_delete_post( $careers_page->ID, true );
	$settings['careers_page_id'] = 0;
}
update_option( \LlamaHire\Settings::OPTION, $settings, false );
WP_CLI::success( 'LlamaHire browser fixtures removed.' );
