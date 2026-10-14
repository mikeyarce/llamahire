<?php
/** Migration checks against the WPJM Playground blueprint's existing dataset. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_dir( '/migration-results' ) ) {
	exit( 'Run with scripts/test-wpjm-playground.mjs in the isolated Playground.' );
}

use LlamaHire\Jobs;
use LlamaHire\WPJM_Import_Command;

$checks = 0;
$assert = static function ( $condition, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'Migration check failed: ' . $label );
	}
};
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$assert( defined( 'JOB_MANAGER_VERSION' ), 'Real WPJM plugin is active' );
$ids = WPJM_Import_Command::source_ids( 0, 500 );
$assert( 173 === count( $ids ), 'Expected blueprint has 173 listings' );
$snapshot = static function ( $source_id ) {
	global $wpdb;
	$terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id", $source_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached read-only fingerprint of the isolated source snapshot.
	return hash( 'sha256', wp_json_encode( array( get_post( $source_id )->to_array(), get_post_meta( $source_id ), $terms ) ) );
};
$before = array();
$plans = array();
$source_statuses = array();
$review_counts = array();
foreach ( $ids as $source_id ) {
	$before[ $source_id ] = $snapshot( $source_id );
	$plans[ $source_id ] = WPJM_Import_Command::plan( $source_id );
	$assert( ! is_wp_error( $plans[ $source_id ] ), 'Source has valid ownership' );
	$source_status = get_post_status( $source_id );
	$source_statuses[ $source_status ] = ( $source_statuses[ $source_status ] ?? 0 ) + 1;
	foreach ( $plans[ $source_id ]['warnings'] as $warning ) {
		$review_counts[ $warning ] = ( $review_counts[ $warning ] ?? 0 ) + 1;
	}
}
$count_copies = static function () {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Jobs::POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached test count in the disposable SQLite instance.
};
$mail_count = 0;
add_filter( 'pre_wp_mail', static function () use ( &$mail_count ) { ++$mail_count; return true; } );
$command = new WPJM_Import_Command();
$initial_count = $count_copies();
$command( array(), array( 'limit' => '500' ) );
$assert( $initial_count === $count_copies(), 'Preview creates no job copies' );
foreach ( $ids as $source_id ) {
	$assert( $before[ $source_id ] === $snapshot( $source_id ), 'Preview preserves the source' );
}
$command( array(), array( 'commit' => true, 'limit' => '100' ) );
$assert( $initial_count + 100 === $count_copies(), 'First batch creates exactly 100 drafts' );
$command( array(), array( 'commit' => true, 'limit' => '100', 'after' => (string) $ids[99] ) );
$assert( $initial_count + 173 === $count_copies(), 'Second batch creates remaining 73 drafts' );
$assert( array() === WPJM_Import_Command::source_ids( end( $ids ), 100 ), 'Final cursor has no remaining source jobs' );
$closed = 0;
$external = 0;
$salary = 0;
foreach ( $ids as $source_id ) {
	$copy_id = WPJM_Import_Command::destination_id( $source_id );
	$source = get_post( $source_id );
	$copy = get_post( $copy_id );
	$assert( $copy && 'draft' === $copy->post_status, 'Copy remains a draft' );
	$assert( $copy->post_author === $source->post_author && $copy->post_date === $source->post_date && $copy->post_date_gmt === $source->post_date_gmt, 'Ownership and dates preserved' );
	$assert( sanitize_text_field( $source->post_title ) === $copy->post_title && wp_kses_post( $source->post_content ) === $copy->post_content && wp_kses_post( $source->post_excerpt ) === $copy->post_excerpt, 'Title and content preserved' );
	$meta = Jobs::get_meta( $copy_id );
	$expected = $plans[ $source_id ]['meta'];
	$expected['job_identifier'] = $meta['job_identifier'];
	$assert( $expected === $meta && '' !== $meta['job_identifier'], 'All mapped fields and new identifier saved' );
	$assert( $meta['organization_name'] === sanitize_text_field( get_post_meta( $source_id, '_company_name', true ) ) && $meta['location'] === sanitize_text_field( get_post_meta( $source_id, '_job_location', true ) ), 'Company and location match original fields' );
	$assert( get_post_meta( $copy_id, Jobs::META_CLOSED, true ) === $meta['closed'] && get_post_meta( $copy_id, Jobs::META_EXPIRY, true ) === $meta['listing_expires'], 'Discovery fields agree with saved model' );
	foreach ( array( Jobs::TYPE_TAXONOMY => 'types', Jobs::DEPARTMENT_TAXONOMY => 'categories' ) as $destination_taxonomy => $field ) {
		$actual = wp_get_object_terms( $copy_id, $destination_taxonomy, array( 'fields' => 'slugs' ) );
		$wanted = array_map( static function ( $term ) { return $term->slug; }, $plans[ $source_id ][ $field ] );
		sort( $actual );
		sort( $wanted );
		$assert( $actual === $wanted, 'Mapped taxonomy assignments preserved' );
	}
	$assert( get_post_thumbnail_id( $source_id ) === get_post_thumbnail_id( $copy_id ), 'Featured image reference preserved' );
	$assert( $before[ $source_id ] === $snapshot( $source_id ), 'Import preserves original post, metadata, and terms' );
	$closed += '1' === $meta['closed'] ? 1 : 0;
	$external += 'internal' !== $meta['application_method'] ? 1 : 0;
	$salary += '' !== $meta['salary_min'] ? 1 : 0;
}
$command( array(), array( 'commit' => true, 'limit' => '500' ) );
$assert( $initial_count + 173 === $count_copies(), 'Full retry produces no duplicates' );
$assert( 0 === $mail_count && ! get_option( WPJM_Import_Command::LOCK ), 'No migration mail and lock released' );
$report = array(
	'checks' => $checks, 'source_jobs' => count( $ids ), 'imported_drafts' => $count_copies() - $initial_count,
	'source_statuses' => $source_statuses, 'closed_drafts' => $closed,
	'external_application_routes' => $external, 'numeric_salaries' => $salary,
	'review_warnings' => $review_counts, 'php' => PHP_VERSION,
	'wordpress' => get_bloginfo( 'version' ), 'wpjm' => JOB_MANAGER_VERSION,
	'source_preserved' => true, 'retry_duplicates' => 0,
);
file_put_contents( '/migration-results/report.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Explicit temporary evidence mount in the isolated Playground.
WP_CLI::success( sprintf( '%d checks passed for %d real blueprint jobs.', $checks, count( $ids ) ) );
