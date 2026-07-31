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

$employer = get_user_by( 'login', 'llamahire-employer' );
if ( ! $employer ) {
	$employer_id = wp_create_user( 'llamahire-employer', 'password', 'llamahire-employer@example.test' );
	if ( is_wp_error( $employer_id ) ) {
		WP_CLI::error( $employer_id->get_error_message() );
	}
	$employer = get_userdata( $employer_id );
}
wp_set_password( 'password', $employer->ID );
$employer->set_role( \LlamaHire\Capabilities::EMPLOYER_ROLE );
update_option( 'llamahire_e2e_employer_user_id', $employer->ID, false );

$employer_jobs = get_posts( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'any', 'author' => $employer->ID, 'fields' => 'ids', 'posts_per_page' => -1 ) );
foreach ( $employer_jobs as $employer_job_id ) {
	$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $employer_job_id ), array( '%d' ) );
	foreach ( get_children( array( 'post_parent' => $employer_job_id, 'post_type' => 'attachment', 'fields' => 'ids', 'numberposts' => -1 ) ) as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	wp_delete_post( $employer_job_id, true );
}

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
	$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $post_id ), array( '%d' ) );
	wp_delete_post( $post_id, true );
}

foreach ( array( 'llamahire-e2e-careers', 'llamahire-e2e-privacy', 'llamahire-e2e-patterns', 'llamahire-e2e-department', 'submit-a-job', 'my-jobs' ) as $page_slug ) {
	$existing_page = get_page_by_path( $page_slug );
	if ( $existing_page ) {
		wp_delete_post( $existing_page->ID, true );
	}
}
$old_department_term_id = absint( get_option( 'llamahire_e2e_department_term_id' ) );
if ( $old_department_term_id ) {
	wp_delete_term( $old_department_term_id, 'llamahire_department' );
}
delete_option( 'llamahire_e2e_department_term_id' );

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
		'email_sender_name'  => 'LlamaHire Hiring',
		'email_sender_email' => 'jobs@example.test',
		'employer_email_subject' => 'New candidate: {candidate_name} for {job_title}',
		'employer_email_body'    => "{candidate_name} applied for {job_title}.\n\nReview: {applications_url}",
		'candidate_email_subject' => 'Application received for {job_title}',
		'candidate_email_body'    => "Hello {candidate_name},\n\nThanks for applying to {site_name}.\n\n{site_url}",
		'privacy_text'       => 'Candidate information is used only to review this application.',
		'privacy_page_id'    => $privacy_page_id,
		'careers_page_id'    => 0,
		'application_phone'  => 'required',
		'application_resume' => 'required',
		'application_letter' => 'required',
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
		'featured'         => '1',
		'organization_name'=> 'LlamaHire CI Employer',
		'organization_url' => home_url( '/' ),
	)
);

$department_term = wp_insert_term( 'LlamaHire E2E Engineering', 'llamahire_department', array( 'slug' => 'llamahire-e2e-engineering' ) );
if ( is_wp_error( $department_term ) ) {
	WP_CLI::error( $department_term->get_error_message() );
}
$department_term_id = (int) $department_term['term_id'];
wp_set_object_terms( $job_id, $department_term_id, 'llamahire_department' );
update_option( 'llamahire_e2e_department_term_id', $department_term_id, false );

$pattern_registry = WP_Block_Patterns_Registry::get_instance();
$hero_pattern     = $pattern_registry->get_registered( 'llamahire/careers-hero' );
$featured_pattern = $pattern_registry->get_registered( 'llamahire/featured-jobs' );
$patterns_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'llamahire-e2e-patterns',
		'post_title'   => 'LlamaHire E2E Patterns',
		'post_content' => $hero_pattern['content'] . "\n" . $featured_pattern['content'],
	)
);
if ( is_wp_error( $patterns_page_id ) ) {
	WP_CLI::error( $patterns_page_id->get_error_message() );
}
update_option( 'llamahire_e2e_patterns_page_id', $patterns_page_id, false );

$department_pattern = $pattern_registry->get_registered( 'llamahire/department-landing-page' );
$department_blocks  = parse_blocks( $department_pattern['content'] );
$set_department = static function ( &$blocks ) use ( &$set_department ) {
	foreach ( $blocks as &$block ) {
		if ( 'llamahire/jobs-directory' === $block['blockName'] ) {
			$block['attrs']['department'] = 'llamahire-e2e-engineering';
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$set_department( $block['innerBlocks'] );
		}
	}
};
$set_department( $department_blocks );
$department_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'llamahire-e2e-department',
		'post_title'   => 'LlamaHire E2E Department',
		'post_content' => serialize_blocks( $department_blocks ),
	)
);
if ( is_wp_error( $department_page_id ) ) {
	WP_CLI::error( $department_page_id->get_error_message() );
}
update_option( 'llamahire_e2e_department_page_id', $department_page_id, false );

update_option( 'llamahire_e2e_job_id', $job_id, false );
flush_rewrite_rules();
WP_CLI::success( 'LlamaHire browser fixtures created.' );
