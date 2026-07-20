<?php
/**
 * Create deterministic fixtures for the browser integration suite.
 *
 * Run with WP-CLI inside the disposable wp-env site.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

global $wpdb;

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) {
	WP_CLI::error( 'The wp-env administrator account is missing.' );
}
wp_set_password( 'password', $admin->ID );

$email = 'browser-test@example.test';
$table = \LlamaHire\Applications::table();
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, resume_path FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
$store = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::RESUME_STORAGE );
$repo  = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
foreach ( $rows as $row ) {
	if ( $row->resume_path ) {
		$store->delete( $row->resume_path );
	}
	$repo->delete( $row->id );
}

$existing = get_posts(
	array(
		'post_type'      => \LlamaHire\Jobs::POST_TYPE,
		'post_status'    => 'any',
		'name'           => 'llamahire-e2e-job',
		'fields'         => 'ids',
		'posts_per_page' => -1,
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( $post_id, true );
}

foreach ( array( 'llamahire-e2e-careers', 'llamahire-e2e-privacy' ) as $page_slug ) {
	$existing_page = get_page_by_path( $page_slug );
	if ( $existing_page ) {
		wp_delete_post( $existing_page->ID, true );
	}
}

$privacy_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'llamahire-e2e-privacy',
		'post_title'   => 'LlamaHire E2E Privacy',
		'post_content' => '<!-- wp:paragraph --><p>Candidate privacy policy fixture.</p><!-- /wp:paragraph -->',
	)
);
if ( is_wp_error( $privacy_page_id ) ) {
	WP_CLI::error( $privacy_page_id->get_error_message() );
}
update_option( 'llamahire_e2e_privacy_page_id', $privacy_page_id, false );

update_option(
	\LlamaHire\Settings::OPTION,
	array(
		'name'             => 'LlamaHire CI Employer',
		'website'          => home_url( '/' ),
		'logo'             => '',
		'default_country'    => 'CA',
		'default_currency'   => 'CAD',
		'notification_email' => get_option( 'admin_email' ),
		'privacy_text'       => 'Candidate information is used only to review this application.',
		'privacy_page_id'    => $privacy_page_id,
		'careers_page_id'    => 0,
	)
);
update_option( \LlamaHire\Setup::OPTION, \LlamaHire\Setup::defaults(), false );

$job_id = wp_insert_post(
	array(
		'post_type'    => \LlamaHire\Jobs::POST_TYPE,
		'post_status'  => 'publish',
		'post_name'    => 'llamahire-e2e-job',
		'post_title'   => 'LlamaHire Browser Test Role',
		'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">About the role</h2><!-- /wp:heading --><!-- wp:paragraph --><p>A disposable role used by continuous integration.</p><!-- /wp:paragraph -->',
		'post_excerpt' => 'A disposable browser-test role.',
	)
);

if ( is_wp_error( $job_id ) ) {
	WP_CLI::error( $job_id->get_error_message() );
}

\LlamaHire\Jobs::set_meta(
	$job_id,
	array(
		'employment_type'  => 'FULL_TIME',
		'workplace'        => 'hybrid',
		'address_street'   => '1285 W Pender St',
		'address_locality' => 'Vancouver',
		'address_region'   => 'BC',
		'postal_code'      => 'V6E 4B1',
		'address_country'  => 'CA',
		'salary_min'       => 90000,
		'salary_max'       => 110000,
		'salary_currency'  => 'CAD',
		'salary_unit'      => 'YEAR',
		'deadline'         => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
		'organization_name'=> 'LlamaHire CI Employer',
		'organization_url' => home_url( '/' ),
	)
);

update_option( 'llamahire_e2e_job_id', $job_id, false );
flush_rewrite_rules();
WP_CLI::success( 'LlamaHire browser fixtures created.' );
