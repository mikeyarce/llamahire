<?php
/** Focused importer integration coverage in the disposable wp-env site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run through WP-CLI in the disposable test environment.' );
}

use LlamaHire\Jobs;
use LlamaHire\WPJM_Import_Command;

$assert = static function ( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'Failed: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};
$source_id = 0;
$destination_id = 0;
$source_owner = 0;
$source_terms = array();
$destination_terms = array();
$original_user = get_current_user_id();
$mail_count = 0;
$mail_stub = static function () use ( &$mail_count ) {
	++$mail_count;
	return true;
};
add_filter( 'pre_wp_mail', $mail_stub );
try {
	wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
	$source_owner = wp_insert_user( array( 'user_login' => 'migration-owner-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$assert( ! is_wp_error( $source_owner ) && ! user_can( $source_owner, 'edit_others_llamahire_jobs' ), 'Source owner has no board-manager permissions' );
	register_post_type( 'job_listing', array( 'public' => true ) );
	register_taxonomy( 'job_listing_category', 'job_listing' );
	register_taxonomy( 'job_listing_type', 'job_listing' );
	foreach ( array( 'job_listing_category', 'job_listing_type' ) as $taxonomy ) {
		$term = wp_insert_term( 'Migration test ' . $taxonomy, $taxonomy, array( 'slug' => 'migration-test-' . $taxonomy ) );
		$source_terms[ $taxonomy ] = (int) $term['term_id'];
	}
	$source_id = wp_insert_post( wp_slash( array(
		'post_type' => 'job_listing', 'post_status' => 'publish',
		'post_author' => $source_owner, 'post_title' => 'Migration test job',
		'post_content' => '<p>Work with C:\\Tools and O\'Brien.</p>',
		'post_date' => '2025-01-02 12:00:00',
	) ), true );
	$assert( ! is_wp_error( $source_id ), 'Source fixture created' );
	$source_meta = array(
		'_company_name' => 'Example C:\\Company', '_company_website' => 'https://example.com/',
		'_company_tagline' => 'Build things', '_job_location' => 'Vancouver',
		'_remote_position' => '1', '_filled' => '1', '_featured' => '1',
		'_application' => 'https://example.com/apply', '_job_expires' => '2027-01-02',
		'_job_salary' => '85000', '_job_salary_currency' => 'CAD', '_job_salary_unit' => 'year',
	);
	foreach ( $source_meta as $key => $value ) {
		update_post_meta( $source_id, $key, wp_slash( $value ) );
	}
	foreach ( $source_terms as $taxonomy => $term_id ) {
		wp_set_object_terms( $source_id, array( $term_id ), $taxonomy );
	}
	$before = get_post( $source_id )->to_array();
	$meta_before = get_post_meta( $source_id );
	$roles_before = get_userdata( get_current_user_id() )->roles;
	$command = new WPJM_Import_Command();
	$options = array( 'after' => (string) ( $source_id - 1 ), 'limit' => '1' );
	$command( array(), $options );
	$assert( ! WPJM_Import_Command::destination_id( $source_id ) && ! get_option( WPJM_Import_Command::LOCK ), 'Preview writes no job or lock' );
	foreach ( array( 'job_listing_type', 'job_listing_category' ) as $taxonomy ) {
		unregister_taxonomy( $taxonomy );
	}
	$plan = WPJM_Import_Command::plan( $source_id );
	$assert( '0' === $plan['meta']['featured'] && in_array( 'featured_requires_review', $plan['warnings'], true ), 'Legacy featured status requires fresh board approval' );
	$assert( count( $plan['types'] ) === 1 && count( $plan['categories'] ) === 1, 'Source terms readable with WPJM deactivated' );
	$assert( $plan['meta']['salary_min'] === 85000.0 && $plan['meta']['salary_currency'] === 'CAD' && $plan['meta']['salary_unit'] === 'YEAR', 'Numeric salary maps without guessing currency or unit' );
	$assert( '' === $plan['meta']['address_country'] && '' === $plan['meta']['address_locality'], 'Missing source address does not inherit board defaults' );
	$command( array(), $options + array( 'commit' => true ) );
	$destination_id = WPJM_Import_Command::destination_id( $source_id );
	$destination = get_post( $destination_id );
	$assert( $destination && 'draft' === $destination->post_status && $destination->post_author === $before['post_author'], 'Import creates a draft with existing ownership' );
	$assert( $destination->post_content === $before['post_content'] && $destination->post_date === $before['post_date'], 'Content backslashes, apostrophes, and dates survive import' );
	$meta = Jobs::get_meta( $destination_id );
	$assert( $meta['organization_name'] === $source_meta['_company_name'] && 'remote' === $meta['workplace'] && '1' === $meta['closed'], 'Company text and lifecycle facts map' );
	$assert( '0' === $meta['featured'] && '0' === get_post_meta( $destination_id, Jobs::META_FEATURED, true ) && in_array( 'featured_requires_review', get_post_meta( $destination_id, '_llamahire_wpjm_review', true ), true ), 'Imported draft and discovery index remain unfeatured with a review flag' );
	$assert( $meta['job_identifier'] && 'external_url' === $meta['application_method'] && $meta['application_target'] === $source_meta['_application'], 'Identifier and application link survive' );
	$assert( get_post_meta( $destination_id, Jobs::META_LOCATION, true ) === Jobs::query_location( $meta ) && '1' === get_post_meta( $destination_id, Jobs::META_CLOSED, true ), 'Discovery fields synchronize from imported model' );
	foreach ( array( Jobs::TYPE_TAXONOMY, Jobs::DEPARTMENT_TAXONOMY ) as $taxonomy ) {
		$terms = wp_get_object_terms( $destination_id, $taxonomy );
		$assert( count( $terms ) === 1 && 0 === strpos( $terms[0]->name, 'Migration test ' ), 'Destination term labels preserved: ' . $taxonomy );
		$destination_terms[ $taxonomy ] = $terms[0]->term_id;
	}
	$assert( get_post( $source_id )->to_array() === $before && get_post_meta( $source_id ) === $meta_before, 'Source post and metadata remain unchanged' );
	$assert( 0 === $mail_count && get_userdata( get_current_user_id() )->roles === $roles_before, 'No migration mail or role changes' );
	$assert( ! get_option( WPJM_Import_Command::LOCK ) && false !== has_action( 'transition_post_status', array( LlamaHire\Employer_Notifications::class, 'status_changed' ) ), 'Lock released and moderation callback restored' );
	wp_update_post( array( 'ID' => $destination_id, 'post_title' => 'Reviewed draft' ) );
	$meta['featured'] = '1';
	update_post_meta( $destination_id, Jobs::META_KEY, wp_slash( $meta ) );
	$command( array(), $options + array( 'commit' => true ) );
	$assert( WPJM_Import_Command::destination_id( $source_id ) === $destination_id && 'Reviewed draft' === get_the_title( $destination_id ), 'Repeat import skips reviewed draft' );
	$assert( '1' === Jobs::get_meta( $destination_id )['featured'], 'Repeat import preserves subsequent board approval' );
	wp_trash_post( $destination_id );
	$command( array(), $options + array( 'commit' => true ) );
	$assert( 'trash' === get_post_status( $destination_id ) && WPJM_Import_Command::destination_id( $source_id ) === $destination_id, 'Repeat import does not restore trashed copies' );
	$fail_completion = static function ( $check, $object_id, $meta_key, $meta_value ) {
		return WPJM_Import_Command::STATE_META === $meta_key && 'complete' === $meta_value ? false : $check;
	};
	add_filter( 'update_post_metadata', $fail_completion, 10, 4 );
	try {
		$copy = new ReflectionMethod( WPJM_Import_Command::class, 'copy' );
		$copy->setAccessible( true );
		$failed_copy = $copy->invoke( null, $source_id, WPJM_Import_Command::plan( $source_id ) );
		$assert( is_wp_error( $failed_copy ) && WPJM_Import_Command::destination_id( $source_id ) === $destination_id, 'Failed completion removes the new copy and preserves earlier work' );
	} finally {
		remove_filter( 'update_post_metadata', $fail_completion, 10 );
	}
	update_post_meta( $destination_id, WPJM_Import_Command::STATE_META, 'incomplete' );
	$interrupted = WP_CLI::runcommand( 'llamahire import wpjm --user=admin --commit --after=' . ( $source_id - 1 ) . ' --limit=1', array( 'return' => 'all', 'exit_error' => false ) );
	$assert( 1 === $interrupted->return_code && false !== strpos( $interrupted->stdout, 'incomplete_copy_requires_review' ), 'Interrupted copies stop a real CLI retry for review' );
	update_post_meta( $destination_id, WPJM_Import_Command::STATE_META, 'complete' );
	$unauthorized = WP_CLI::runcommand( 'llamahire import wpjm', array( 'return' => 'all', 'exit_error' => false ) );
	$assert( 1 === $unauthorized->return_code && false !== strpos( $unauthorized->stderr, 'Select a board manager' ), 'Real CLI rejects users without board capabilities' );
	update_post_meta( $source_id, '_job_salary', 'Competitive' );
	update_post_meta( $source_id, '_application', 'invalid' );
	update_post_meta( $source_id, '_job_expires', '2027-02-31' );
	$plan = WPJM_Import_Command::plan( $source_id );
	$assert( '' === $plan['meta']['salary_min'] && in_array( 'salary_requires_review', $plan['warnings'], true ), 'Free-text salary is flagged without fabricated numbers' );
	$assert( in_array( 'expiry_requires_review', $plan['warnings'], true ) && in_array( 'application_method_requires_review', $plan['warnings'], true ), 'Invalid expiry and application routing flagged' );
	update_post_meta( $source_id, '_application', 'hiring@example.com' );
	wp_update_post( array( 'ID' => $source_id, 'post_status' => 'expired' ) );
	update_post_meta( $source_id, '_filled', '0' );
	$plan = WPJM_Import_Command::plan( $source_id );
	$assert( 'external_email' === $plan['meta']['application_method'] && '1' === $plan['meta']['closed'], 'Email routing and expired closure map' );
	update_post_meta( $source_id, '_featured', '0' );
	$plan = WPJM_Import_Command::plan( $source_id );
	$assert( '0' === $plan['meta']['featured'] && ! in_array( 'featured_requires_review', $plan['warnings'], true ), 'Unfeatured sources stay unfeatured without a promotion review flag' );
	wp_update_post( array( 'ID' => $source_id, 'post_author' => 0 ) );
	$assert( is_wp_error( WPJM_Import_Command::plan( $source_id ) ), 'Guest ownership requires explicit review' );
	wp_trash_post( $source_id );
	$assert( ! WPJM_Import_Command::source_ids( $source_id - 1, 1 ), 'Trashed source jobs excluded' );
} finally {
	remove_filter( 'pre_wp_mail', $mail_stub );
	foreach ( array( $source_id, $destination_id ) as $post_id ) {
		if ( $post_id && ! is_wp_error( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( $source_terms as $taxonomy => $term_id ) {
		register_taxonomy( $taxonomy, 'job_listing' );
		wp_delete_term( $term_id, $taxonomy );
	}
	foreach ( $destination_terms as $taxonomy => $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}
	if ( $source_owner && ! is_wp_error( $source_owner ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $source_owner );
	}
	wp_set_current_user( $original_user );
}
WP_CLI::success( 'WPJM importer integration checks passed.' );
