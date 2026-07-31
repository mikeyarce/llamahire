<?php
/**
 * Disposable WP-CLI smoke test for the LlamaHire core workflow.
 *
 * Run with: wp eval-file wp-content/plugins/llamahire/tests/smoke.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

$checks = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	$checks[] = array( (bool) $condition, $label );
	if ( ! $condition ) {
		throw new RuntimeException( 'Failed: ' . $label );
	}
};

global $wpdb;
$table = $wpdb->prefix . 'llamahire_applications';
$job_id = 0;
$sitemap_job_id = 0;
$filter_job_id = 0;
$department_term_id = 0;
$application_id = 0;
$retention_application_id = 0;
$retention_resume_path = '';
$privacy_application_id = 0;
$privacy_resume_path = '';
$privacy_page_id = 0;
$employer_user_ids = array();
$employer_job_ids = array();
$employer_application_ids = array();
$original_settings = get_option( \LlamaHire\Settings::OPTION, false );
$original_setup    = get_option( \LlamaHire\Setup::OPTION, false );
$original_legacy_settings = get_option( 'llamahire_settings', false );
$original_current_user_id = get_current_user_id();

try {
	$assert( defined( 'LLAMAHIRE_API_VERSION' ) && '1.0.0-alpha.8' === LLAMAHIRE_API_VERSION, 'Public API version is declared' );
	$assert( 1 === did_action( 'llamahire_ready' ), 'Public ready action fired once' );
	$services = \LlamaHire\Plugin::instance()->services();
	$assert( $services instanceof \LlamaHire\Contracts\Service_Container, 'Public service container is available' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY ) instanceof \LlamaHire\Contracts\Application_Repository, 'Application repository satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY ) instanceof \LlamaHire\Contracts\Application_Query, 'Application query satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS ) instanceof \LlamaHire\Contracts\Notification_Service, 'Notification service satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) instanceof \LlamaHire\Contracts\Resume_Storage, 'Resume storage satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::CANDIDATE_DATA ) instanceof \LlamaHire\Contracts\Candidate_Data_Lifecycle, 'Candidate-data lifecycle satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER ) instanceof \LlamaHire\Contracts\Schema_Builder, 'Schema builder satisfies its public contract' );
	$locked = false;
	try {
		$services->set( 'llamahire.smoke_test', new stdClass() );
	} catch ( LogicException $exception ) {
		$locked = true;
	}
	$assert( $locked, 'Service container is immutable after initialization' );
	$assert( LLAMAHIRE_SCHEMA_VERSION === (string) get_option( \LlamaHire\Migrations::OPTION ), 'Database schema is at the declared version' );
	$audit_table = \LlamaHire\Audit_Log::table();
	$assert( $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ), 'Privacy-safe audit table is installed' );
	$audit_columns = $wpdb->get_col( "DESCRIBE {$audit_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	$assert( array() === array_intersect( array( 'name', 'email', 'phone', 'cover_letter', 'notes', 'resume_path', 'resume_name', 'ip_address', 'user_agent' ), $audit_columns ), 'Audit schema cannot copy candidate content, resume identifiers, or request fingerprints' );
	$assert( LLAMAHIRE_CAPABILITIES_VERSION === (string) get_option( \LlamaHire\Capabilities::OPTION ), 'Capability grants are at the declared version' );
	$administrator = get_role( 'administrator' );
	$subscriber    = get_role( 'subscriber' );
	$employer      = get_role( \LlamaHire\Capabilities::EMPLOYER_ROLE );
	$assert( $administrator && $administrator->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && $administrator->has_cap( \LlamaHire\Capabilities::RETRY_NOTIFICATIONS ) && $administrator->has_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS ) && $administrator->has_cap( 'publish_llamahire_jobs' ), 'Administrators receive candidate and job capabilities' );
	$assert( ! $subscriber || ( ! $subscriber->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! $subscriber->has_cap( 'edit_llamahire_jobs' ) ), 'Subscribers receive no hiring capabilities by default' );
	$assert( $employer && $employer->has_cap( 'edit_llamahire_jobs' ) && $employer->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! $employer->has_cap( 'edit_others_llamahire_jobs' ) && ! $employer->has_cap( 'publish_llamahire_jobs' ) && ! $employer->has_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS ), 'Employers manage their pending jobs and candidates without board-wide publication, ownership, or erasure powers' );

	$assert( post_type_exists( 'llamahire_job' ), 'Job post type is registered' );
	$assert( taxonomy_exists( 'llamahire_department' ), 'Department taxonomy is registered' );
	$job_type = get_post_type_object( 'llamahire_job' );
	$department_type = get_taxonomy( 'llamahire_department' );
	$assert( 'edit_llamahire_jobs' === $job_type->cap->edit_posts && 'edit_llamahire_job' === $job_type->cap->edit_post, 'Job post type maps dedicated capabilities' );
	$assert( 'manage_llamahire_departments' === $department_type->cap->manage_terms, 'Department taxonomy maps dedicated capabilities' );
	$registered_job_meta = get_registered_meta_keys( 'post', 'llamahire_job' );
	$assert( isset( $registered_job_meta[ \LlamaHire\Jobs::META_KEY ] ) && ! empty( $registered_job_meta[ \LlamaHire\Jobs::META_KEY ]['show_in_rest'] ), 'Structured job settings are registered for the block editor' );
	$privacy_page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LlamaHire Smoke Privacy', 'post_content' => 'Privacy fixture.' ) );
	$assert( ! is_wp_error( $privacy_page_id ) && $privacy_page_id > 0, 'A published candidate privacy page can be selected' );
	$sanitized_settings = \LlamaHire\Settings::sanitize( array( 'site_mode' => 'company', 'name' => 'Example Employer', 'website' => 'https://employer.example.test/', 'default_locality' => ' Vancouver ', 'default_region' => ' bc ', 'default_country' => 'ca', 'default_currency' => 'cad', 'notification_email' => 'hiring@example.test', 'email_sender_name' => ' Example Hiring ', 'email_sender_email' => 'jobs@example.test', 'employer_email_subject' => 'Applicant {candidate_name}: {job_title}', 'employer_email_body' => "{candidate_name} applied.\n\nOpen {applications_url}", 'candidate_email_subject' => 'Application received: {job_title}', 'candidate_email_body' => "Hello {candidate_name},\n\nThank you from {site_name}.\n{site_url}", 'privacy_text' => ' Candidate data is used only for hiring. ', 'privacy_page_id' => $privacy_page_id, 'application_phone' => 'required', 'application_resume' => 'required', 'application_letter' => 'hidden' ) );
	$assert( 'Vancouver' === $sanitized_settings['default_locality'] && 'bc' === $sanitized_settings['default_region'] && 'CA' === $sanitized_settings['default_country'] && 'CAD' === $sanitized_settings['default_currency'] && 'hiring@example.test' === $sanitized_settings['notification_email'] && 'Candidate data is used only for hiring.' === $sanitized_settings['privacy_text'] && $privacy_page_id === $sanitized_settings['privacy_page_id'] && 365 === $sanitized_settings['retention_days'], 'Setup defaults normalize organization, privacy, retention, and hiring inbox values' );
	$assert( 'job_board' === \LlamaHire\Settings::sanitize_site_mode( 'job_board' ) && 'company' === \LlamaHire\Settings::sanitize_site_mode( 'marketplace' ), 'Site purpose accepts the documented job-board mode and fails unknown values to company mode' );
	$assert( isset( \LlamaHire\Settings::country_options()['CA'] ) && isset( \LlamaHire\Settings::currency_options()['CAD'] ), 'Country and currency selectors include normalized ISO options' );
	$job_board_settings = \LlamaHire\Settings::sanitize( array( 'site_mode' => 'job_board', 'name' => 'Hamilton Job Board', 'website' => 'https://unrelated.example.test/' ) );
	$assert( home_url( '/' ) === $job_board_settings['website'], 'Job-board mode uses the known WordPress site URL instead of asking for a duplicate board website' );
	$assert( false !== strpos( \LlamaHire\Settings::default_privacy_text( 'job_board' ), 'job-board operator' ), 'Job-board mode has candidate privacy copy that names both data recipients' );
	$assert( 'required' === $sanitized_settings['application_phone'] && 'required' === $sanitized_settings['application_resume'] && 'hidden' === $sanitized_settings['application_letter'], 'Application field modes accept required and hidden states' );
	$assert( 'Example Hiring' === $sanitized_settings['email_sender_name'] && 'jobs@example.test' === $sanitized_settings['email_sender_email'] && false !== strpos( $sanitized_settings['candidate_email_body'], '{site_name}' ), 'Email sender and plain-text templates are normalized without removing supported placeholders' );
	$invalid_settings = \LlamaHire\Settings::sanitize( array( 'name' => 'Example Employer', 'default_currency' => 'dollars', 'notification_email' => 'not-an-email' ) );
	$assert( '' === $invalid_settings['default_currency'] && '' === $invalid_settings['notification_email'] && 'optional' === $invalid_settings['application_phone'] && 'optional' === $invalid_settings['application_resume'] && 'optional' === $invalid_settings['application_letter'], 'Invalid setup values fail safe and omitted application fields preserve compatible defaults' );
	$assert( 365 === \LlamaHire\Settings::sanitize_retention_days( 12 ) && 0 === \LlamaHire\Settings::sanitize_retention_days( 0 ) && 730 === \LlamaHire\Settings::sanitize_retention_days( 730 ), 'Retention periods use the documented allowlist and fail to a one-year default' );
	$assert( wp_next_scheduled( \LlamaHire\Applications::RETENTION_HOOK ), 'Daily candidate-retention cleanup is scheduled' );
	update_option( 'llamahire_settings', array( 'notification_email' => 'legacy@example.test' ), false );
	update_option( \LlamaHire\Settings::OPTION, array( 'name' => 'Legacy Employer' ), false );
	$assert( 'legacy@example.test' === \LlamaHire\Settings::get()['notification_email'], 'Legacy hiring inbox remains available until canonical settings are saved' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
	$assert( 'hiring@example.test' === \LlamaHire\Settings::get()['notification_email'] && 'company' === \LlamaHire\Settings::site_mode(), 'Canonical organization settings own the hiring inbox and default to company mode' );
	delete_option( \LlamaHire\Setup::OPTION );
	$assert( 'skipped' === \LlamaHire\Setup::state()['status'], 'Existing installations without setup state are not forced into first-run onboarding' );
	\LlamaHire\Setup::mark_pending();
	$assert( 'pending' === \LlamaHire\Setup::state()['status'], 'First activation queues the setup flow' );
	update_option( \LlamaHire\Setup::OPTION, array( 'version' => \LlamaHire\Setup::VERSION, 'status' => 'completed' ), false );
	\LlamaHire\Setup::mark_pending();
	$assert( 'completed' === \LlamaHire\Setup::state()['status'] && \LlamaHire\Setup::is_complete(), 'Reactivation preserves completed setup state and hides completed onboarding' );
	$job_defaults = \LlamaHire\Jobs::defaults();
	$assert( 'Vancouver' === $job_defaults['address_locality'] && 'bc' === $job_defaults['address_region'] && 'CA' === $job_defaults['address_country'] && 'CAD' === $job_defaults['salary_currency'], 'New jobs inherit configured location and currency defaults' );
	$careers_content = \LlamaHire\Setup::careers_page_content();
	$assert( has_block( 'llamahire/job-search', $careers_content ) && has_block( 'llamahire/job-filters', $careers_content ) && has_block( 'llamahire/jobs-directory', $careers_content ), 'Generated Careers pages compose Search, Filters, and Jobs Directory blocks' );
	$compatible_jobs_page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Compatible jobs directory fixture', 'post_content' => $careers_content ), true );
	$assert( ! is_wp_error( $compatible_jobs_page_id ) && \LlamaHire\Setup::public_jobs_page( $compatible_jobs_page_id ) && null === \LlamaHire\Setup::public_jobs_page( $privacy_page_id ), 'Setup accepts only published pages containing the LlamaHire Jobs Directory block' );
	if ( ! is_wp_error( $compatible_jobs_page_id ) ) {
		wp_delete_post( $compatible_jobs_page_id, true );
	}
	$invalid_job_meta = \LlamaHire\Jobs::sanitize_meta( array( 'deadline' => '2026-99-99', 'salary_min' => 120000, 'salary_max' => 90000, 'salary_currency' => 'dollars' ) );
	$assert( '' === $invalid_job_meta['deadline'] && '' === $invalid_job_meta['salary_min'] && '' === $invalid_job_meta['salary_max'] && '' === $invalid_job_meta['salary_currency'], 'Invalid dates, reversed salary ranges, and invalid currencies fail safe' );
	$non_positive_salary = \LlamaHire\Jobs::sanitize_meta( array( 'salary_min' => -1, 'salary_max' => 0, 'salary_currency' => 'USD' ) );
	$assert( '' === $non_positive_salary['salary_min'] && '' === $non_positive_salary['salary_max'], 'Non-positive salary boundaries are omitted' );
	$assert( \LlamaHire\Jobs::valid_date( '2028-02-29' ) && ! \LlamaHire\Jobs::valid_date( '2027-02-29' ), 'Application deadlines require a real calendar date' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/jobs-directory' ), 'Jobs Directory block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/job-search' ), 'Job Search block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/job-filters' ), 'Job Filters block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/job-card' ), 'Job Card block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/featured-jobs' ), 'Featured Jobs block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/single-job-details' ), 'Single Job Details block is registered' );
	$job_card_type = WP_Block_Type_Registry::get_instance()->get_registered( 'llamahire/job-card' );
	$assert( in_array( 'llamahire/jobId', $job_card_type->uses_context, true ) && ! isset( $job_card_type->attributes['jobId'] ), 'Job Card consumes job context without a manual job ID attribute' );
	$job_details_type = WP_Block_Type_Registry::get_instance()->get_registered( 'llamahire/single-job-details' );
	$assert( in_array( 'llamahire/jobId', $job_details_type->uses_context, true ) && ! isset( $job_details_type->attributes['jobId'] ), 'Single Job Details consumes job context without a manual job ID attribute' );
	$pattern_registry = WP_Block_Patterns_Registry::get_instance();
	$pattern_names = array( 'llamahire/careers-page', 'llamahire/careers-hero', 'llamahire/featured-jobs', 'llamahire/department-landing-page' );
	$registered_patterns = array_filter( $pattern_names, static function ( $pattern_name ) use ( $pattern_registry ) { return $pattern_registry->is_registered( $pattern_name ); } );
	$assert( count( $pattern_names ) === count( $registered_patterns ) && WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'llamahire' ), 'LlamaHire pattern category and all four patterns are registered' );
	if ( function_exists( 'register_block_template' ) ) {
		$template_registry = WP_Block_Templates_Registry::get_instance();
		$single_template   = $template_registry->get_registered( 'llamahire//single-llamahire_job' );
		$archive_template  = $template_registry->get_registered( 'llamahire//archive-llamahire_job' );
		$taxonomy_template = $template_registry->get_registered( 'llamahire//taxonomy-llamahire_department' );
		$assert( $single_template && $archive_template && $taxonomy_template && has_block( 'core/post-content', $single_template->content ) && has_block( 'llamahire/jobs-directory', $archive_template->content ) && has_block( 'llamahire/jobs-directory', $taxonomy_template->content ), 'Native single-job, jobs-archive, and department block templates are registered on supported WordPress versions' );
	} else {
		$assert( is_readable( LLAMAHIRE_PATH . 'templates/single-llamahire_job.php' ) && is_readable( LLAMAHIRE_PATH . 'templates/archive-llamahire_job.php' ), 'Theme template assets remain available while older WordPress versions use the normal theme hierarchy' );
	}
	$featured_pattern = $pattern_registry->get_registered( 'llamahire/featured-jobs' );
	$department_pattern = $pattern_registry->get_registered( 'llamahire/department-landing-page' );
	$assert( has_block( 'llamahire/featured-jobs', $featured_pattern['content'] ) && has_block( 'llamahire/jobs-directory', $department_pattern['content'] ) && has_block( 'core/heading', $department_pattern['content'] ), 'Patterns contain the expected editable core and LlamaHire blocks' );
	$variation_names = array();
	foreach ( array( 'llamahire/jobs-directory', 'llamahire/job-filters', 'llamahire/featured-jobs', 'llamahire/single-job-details' ) as $variation_block_name ) {
		$variation_names = array_merge( $variation_names, wp_list_pluck( WP_Block_Type_Registry::get_instance()->get_registered( $variation_block_name )->get_variations(), 'name' ) );
	}
	$assert( ! array_diff( array( 'llamahire-results-only', 'llamahire-location-work-style', 'llamahire-compact-featured-jobs', 'llamahire-essential-job-details' ), $variation_names ), 'Purposeful directory, filter, featured-job, and job-details variations are registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/application-form' ), 'Application Form block is registered' );
	$assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Applications table exists' );
	$index_names = array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' ) );
	$assert( in_array( 'submission_key', $index_names, true ), 'Submission keys have a unique database index' );
	$assert( in_array( 'candidate_key', $index_names, true ), 'Canonical job and candidate identities have a unique database index' );

	$job_id = wp_insert_post(
		array(
			'post_type'    => 'llamahire_job',
			'post_status'  => 'publish',
			'post_title'   => 'LlamaHire Smoke Test Role',
			'post_content' => '<!-- wp:paragraph --><p>A temporary validation role.</p><!-- /wp:paragraph -->',
			'post_excerpt' => 'A temporary role used for validation.',
		)
	);
	$assert( ! is_wp_error( $job_id ) && $job_id > 0, 'A job can be published' );

	\LlamaHire\Jobs::set_meta(
		$job_id,
		array(
			'location'        => 'Vancouver, BC',
			'address_street'  => '1285 W Pender St',
			'address_locality'=> 'Vancouver',
			'address_region'  => 'BC',
			'postal_code'     => 'V6E 4B1',
			'address_country' => 'CA',
			'employment_type' => 'FULL_TIME',
			'workplace'       => 'hybrid',
			'salary_min'      => 90000,
			'salary_max'      => 110000,
			'salary_currency' => 'CAD',
			'salary_unit'     => 'YEAR',
			'deadline'        => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'featured'        => '1',
			'closed'          => '0',
			'organization_name' => 'LlamaHire Test Employer',
			'organization_url'  => 'https://example.test/',
		)
	);
	$department_term = wp_insert_term( 'Engineering', 'llamahire_department', array( 'slug' => 'engineering' ) );
	$department_term_id = is_wp_error( $department_term ) ? 0 : (int) $department_term['term_id'];
	$assert( $department_term_id && ! is_wp_error( wp_set_object_terms( $job_id, $department_term_id, 'llamahire_department' ) ), 'Published jobs can be assigned to a department used by landing pages' );
	delete_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT );
	delete_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION );
	$legacy_duplicate_ids = array();
	foreach ( array( 'Legacy Original', 'Legacy Repeat' ) as $legacy_name ) {
		$wpdb->insert(
			$table,
			array(
				'job_id'    => $job_id,
				'name'      => $legacy_name,
				'email'     => 'legacy-duplicate@example.test',
				'created_at'=> current_time( 'mysql', true ),
				'updated_at'=> current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		$legacy_duplicate_ids[] = (int) $wpdb->insert_id;
	}
	update_option( \LlamaHire\Migrations::OPTION, '5', false );
	$assert( \LlamaHire\Migrations::run() && 'FULL_TIME' === get_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT, true ) && false !== strpos( get_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION, true ), 'Vancouver' ), 'Schema migration 6 backfills normalized employment and location filters' );
	$legacy_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, candidate_key FROM {$table} WHERE email = %s ORDER BY id ASC", 'legacy-duplicate@example.test' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$assert( 2 === count( $legacy_rows ) && ! empty( $legacy_rows[0]->candidate_key ) && empty( $legacy_rows[1]->candidate_key ), 'Schema migration 7 preserves legacy duplicate rows and assigns only the original canonical key' );
	foreach ( $legacy_duplicate_ids as $legacy_duplicate_id ) {
		$wpdb->delete( $table, array( 'id' => $legacy_duplicate_id ), array( '%d' ) );
	}
	$assert( \LlamaHire\Jobs::is_open( $job_id ), 'Published job is open for applications' );
	$job_meta = \LlamaHire\Jobs::get_meta( $job_id );
	$contextual_details = new WP_Block(
		array(
			'blockName'    => 'llamahire/single-job-details',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array( 'llamahire/jobId' => $job_id )
	);
	$details_html = $contextual_details->render();
	$assert( false !== strpos( $details_html, 'llamahire-job-facts' ) && false !== strpos( $details_html, 'Company' ) && false !== strpos( $details_html, 'Employment type' ) && false !== strpos( $details_html, 'LlamaHire Test Employer' ) && false !== strpos( $details_html, '1285 W Pender St' ) && false !== strpos( $details_html, $job_meta['job_identifier'] ), 'Single Job Details renders structured candidate-facing facts supplied through job context' );
	$custom_details = new WP_Block(
		array(
			'blockName'    => 'llamahire/single-job-details',
			'attrs'        => array( 'showOrganization' => false, 'showLocation' => false, 'showWorkplace' => false, 'showEmploymentType' => false, 'showSalary' => false, 'showPostedDate' => false, 'showDeadline' => false ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array( 'llamahire/jobId' => $job_id )
	);
	$custom_details_html = $custom_details->render();
	$assert( false !== strpos( $custom_details_html, 'Job reference' ) && false !== strpos( $custom_details_html, $job_meta['job_identifier'] ) && false === strpos( $custom_details_html, 'Company' ) && false === strpos( $custom_details_html, 'Location' ), 'Single Job Details visibility controls select individual facts' );
	$assert( '' === do_blocks( '<!-- wp:llamahire/single-job-details /-->' ), 'Single Job Details emits no orphaned front-end placeholder without job context' );
	$original_job_content = get_post_field( 'post_content', $job_id );
	wp_update_post(
		array(
			'ID'           => $job_id,
			'post_content' => '<!-- wp:llamahire/single-job-details /--><!-- wp:paragraph --><p>Single details fixture.</p><!-- /wp:paragraph --><!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->',
		)
	);
	global $wp_query, $wp_the_query;
	$original_wp_query     = $wp_query;
	$original_wp_the_query = $wp_the_query;
	$single_job_query      = new WP_Query( array( 'p' => $job_id, 'post_type' => \LlamaHire\Jobs::POST_TYPE ) );
	$wp_query              = $single_job_query;
	$wp_the_query          = $single_job_query;
	$single_job_query->the_post();
	$single_job_content = apply_filters( 'the_content', get_the_content() );
	wp_update_post(
		array(
			'ID'           => $job_id,
			'post_content' => '<!-- wp:paragraph --><p>Automatic single-job fixture.</p><!-- /wp:paragraph -->',
		)
	);
	$automatic_single_job_content = \LlamaHire\Blocks::single_job_content( '<p>Automatic single-job fixture.</p>' );
	$wp_query           = $original_wp_query;
	$wp_the_query       = $original_wp_the_query;
	wp_reset_postdata();
	wp_update_post( array( 'ID' => $job_id, 'post_content' => $original_job_content ) );
	$assert( 1 === substr_count( $single_job_content, 'llamahire-job-facts' ), 'An inserted Single Job Details block replaces the automatic compatibility panel without duplication' );
	$assert( false !== strpos( $automatic_single_job_content, 'llamahire-single-layout' ) && false !== strpos( $automatic_single_job_content, 'llamahire-single-apply' ), 'Automatic single-job content uses the editorial two-column detail and application layout' );
	$assert( false !== strpos( $automatic_single_job_content, 'llamahire-job-facts is-compact has-last-row-3' ) && false !== strpos( $automatic_single_job_content, 'class="is-posted"' ) && false !== strpos( $automatic_single_job_content, 'class="is-deadline"' ) && false === strpos( $automatic_single_job_content, '1285 W Pender St' ), 'Automatic single-job facts use a balanced compact grid with scannable date labels and no street address' );
	$assert( false !== strpos( $automatic_single_job_content, 'Drag and drop a file here' ) && false !== strpos( $automatic_single_job_content, 'Enter your full name' ), 'Automatic application form renders the friendly upload control and field guidance' );
	$contextual_card = new WP_Block(
		array(
			'blockName'    => 'llamahire/job-card',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array( 'llamahire/jobId' => $job_id )
	);
	$card_html = $contextual_card->render();
	$assert( false !== strpos( $card_html, 'LlamaHire Smoke Test Role' ) && false !== strpos( $card_html, 'llamahire-job-card' ) && false !== strpos( $card_html, 'Featured' ), 'Job Card renders the open job supplied through block context' );
	$custom_card = new WP_Block(
		array(
			'blockName'    => 'llamahire/job-card',
			'attrs'        => array( 'showExcerpt' => false, 'showFeaturedBadge' => false, 'showSalary' => true, 'linkLabel' => 'See opening', 'headingLevel' => 4 ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array( 'llamahire/jobId' => $job_id )
	);
	$custom_card_html = $custom_card->render();
	$assert( false === strpos( $custom_card_html, 'A temporary role used for validation.' ) && false === strpos( $custom_card_html, 'llamahire-badge' ) && false !== strpos( $custom_card_html, '<h4>' ) && false !== strpos( $custom_card_html, 'See opening' ) && false !== strpos( $custom_card_html, 'llamahire-card-salary' ), 'Job Card display controls change shared card markup' );
	$featured_jobs = do_blocks( '<!-- wp:llamahire/featured-jobs {"heading":"Highlighted openings","perPage":3} /-->' );
	$assert( false !== strpos( $featured_jobs, 'Highlighted openings' ) && false !== strpos( $featured_jobs, 'LlamaHire Smoke Test Role' ), 'Featured Jobs renders open featured roles with an editor-configurable heading' );
	$application_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	$assert( false !== strpos( $application_form, 'Candidate data is used only for hiring.' ) && false !== strpos( $application_form, get_permalink( $privacy_page_id ) ), 'Application forms show configured privacy text and the selected policy link' );
	$assert( ! empty( $job_meta['job_identifier'] ) && 'CA' === $job_meta['address_country'], 'Job model preserves a stable identifier and structured address' );
	$schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$posts_sitemap = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
	$job_sitemap_urls = $posts_sitemap->get_url_list( 1, \LlamaHire\Jobs::POST_TYPE );
	$job_sitemap_entry = current( array_filter( $job_sitemap_urls, static function ( $url ) use ( $job_id ) { return get_permalink( $job_id ) === $url['loc']; } ) );
	$assert( $job_sitemap_entry && get_post_modified_time( DATE_W3C, true, $job_id ) === $job_sitemap_entry['lastmod'], 'Published jobs appear in the XML sitemap with an accurate modification time' );
	$assert( 'JobPosting' === ( $schema['@type'] ?? '' ) && 90000.0 === ( $schema['baseSalary']['value']['minValue'] ?? null ) && 'YEAR' === ( $schema['baseSalary']['value']['unitText'] ?? '' ), 'Schema builder exposes employer-provided salary range and pay unit' );
	$assert( 'CA' === ( $schema['jobLocation']['address']['addressCountry'] ?? '' ) && 'Vancouver' === ( $schema['jobLocation']['address']['addressLocality'] ?? '' ), 'Schema builder emits a complete physical location' );
	$assert( 'LlamaHire Test Employer' === ( $schema['hiringOrganization']['name'] ?? '' ) && $job_meta['job_identifier'] === ( $schema['identifier']['value'] ?? '' ), 'Schema builder emits the hiring organization and stable identifier' );
	$assert( false === isset( $schema['jobLocationType'] ), 'Hybrid jobs are not incorrectly marked as fully remote' );
	$assert( false !== strpos( $schema['validThrough'] ?? '', $job_meta['deadline'] ), 'Schema expiry uses the visible application deadline' );
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$original_user_id = get_current_user_id();
	wp_set_current_user( (int) $admin_ids[0] );
	$rest_meta = \LlamaHire\Jobs::get_meta( $job_id );
	$rest_meta['workplace'] = 'remote';
	$rest_meta['applicant_countries'] = 'US, CA';
	$rest_request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $job_id );
	$rest_request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => $rest_meta ) );
	$rest_response = rest_do_request( $rest_request );
	wp_set_current_user( $original_user_id );
	$rest_saved = \LlamaHire\Jobs::get_meta( $job_id );
	$assert( 200 === $rest_response->get_status() && 'remote' === $rest_saved['workplace'] && 'remote' === get_post_meta( $job_id, \LlamaHire\Jobs::META_WORKPLACE, true ) && 'FULL_TIME' === get_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT, true ) && false !== strpos( get_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION, true ), 'Vancouver' ), 'Block editor REST saves persist and synchronize query metadata' );
	$remote_schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$assert( 'TELECOMMUTE' === ( $remote_schema['jobLocationType'] ?? '' ) && 2 === count( $remote_schema['applicantLocationRequirements'] ?? array() ), 'Fully remote schema includes eligible applicant countries' );
	$assert( false === isset( $remote_schema['jobLocation'] ), 'Fully remote schema does not claim a physical reporting location' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'workplace' => 'hybrid' ) );
	$assert( false !== strpos( \LlamaHire\Jobs::salary_label( \LlamaHire\Jobs::get_meta( $job_id ) ), '/ year' ), 'Visible salary includes the same pay unit as schema' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => 95000, 'salary_max' => 95000 ) );
	$exact_salary_schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$assert( 95000.0 === ( $exact_salary_schema['baseSalary']['value']['value'] ?? null ) && ! isset( $exact_salary_schema['baseSalary']['value']['minValue'] ), 'Exact salary emits value instead of a range' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => '', 'salary_max' => '' ) );
	$no_salary_schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$assert( ! isset( $no_salary_schema['baseSalary'] ), 'Unknown salary is omitted instead of inferred' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => 90000, 'salary_max' => 110000, 'address_country' => '' ) );
	$assert( array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ), 'Incomplete physical location suppresses invalid JobPosting markup' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'address_country' => 'CA', 'closed' => '1' ) );
	$assert( '' === $contextual_card->render(), 'Job Card suppresses jobs that are no longer open' );
	$assert( false !== strpos( $contextual_details->render(), $job_meta['job_identifier'] ), 'Single Job Details remains available for a closed historical job' );
	$limit_empty_featured_query = static function ( $query ) use ( $job_id ) {
		if ( \LlamaHire\Jobs::POST_TYPE === $query->get( 'post_type' ) ) {
			$query->set( 'post__in', array( $job_id ) );
		}
	};
	add_action( 'pre_get_posts', $limit_empty_featured_query );
	$empty_featured_jobs = do_blocks( '<!-- wp:llamahire/featured-jobs /-->' );
	remove_action( 'pre_get_posts', $limit_empty_featured_query );
	$assert( false !== strpos( $empty_featured_jobs, 'No featured roles yet' ), 'Featured Jobs provides a helpful empty state when no featured role is open' );
	$closed_sitemap_urls = $posts_sitemap->get_url_list( 1, \LlamaHire\Jobs::POST_TYPE );
	$closed_url_retained = (bool) array_filter( $closed_sitemap_urls, static function ( $url ) use ( $job_id ) { return get_permalink( $job_id ) === $url['loc']; } );
	$assert( array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ) && false !== strpos( \LlamaHire\Blocks::render_form( array( 'jobId' => $job_id ) ), 'closed' ) && $closed_url_retained, 'Closed jobs retain their historical sitemap URL while suppressing active schema and applications' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'closed' => '0', 'deadline' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ) );
	$assert( ! \LlamaHire\Jobs::is_open( $job_id ) && array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ), 'Expired jobs suppress active JobPosting markup' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) );
	$sitemap_job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Disposable Sitemap Role', 'post_content' => 'Temporary sitemap lifecycle fixture.' ) );
	$sitemap_job_url = get_permalink( $sitemap_job_id );
	wp_delete_post( $sitemap_job_id, true );
	$sitemap_job_id = 0;
	$deleted_sitemap_urls = $posts_sitemap->get_url_list( 1, \LlamaHire\Jobs::POST_TYPE );
	$assert( ! array_filter( $deleted_sitemap_urls, static function ( $url ) use ( $sitemap_job_url ) { return $sitemap_job_url === $url['loc']; } ), 'Deleted jobs are removed from the XML sitemap' );

	$_GET['job_search'] = 'LlamaHire Smoke';
	$_GET['workplace'] = 'hybrid';
	$search_block = do_blocks( '<!-- wp:llamahire/job-search /-->' );
	$filters_block = do_blocks( '<!-- wp:llamahire/job-filters /-->' );
	$assert( false !== strpos( $search_block, 'name="workplace" value="hybrid"' ) && false !== strpos( $filters_block, 'name="job_search" value="LlamaHire Smoke"' ), 'Composable search and filter forms preserve each other\'s URL state' );
	$empty_text_search = do_blocks( '<!-- wp:llamahire/job-search {"label":"  ","placeholder":"","buttonLabel":""} /-->' );
	$empty_text_filters = do_blocks( '<!-- wp:llamahire/job-filters {"buttonLabel":""} /-->' );
	$assert( false !== strpos( $empty_text_search, '>Search jobs</span>' ) && false !== strpos( $empty_text_search, 'placeholder="Job title or keyword"' ) && false !== strpos( $empty_text_search, '>Search</button>' ) && false !== strpos( $empty_text_filters, '>Apply filters</button>' ), 'Empty customizable query labels retain accessible translated defaults' );
	$_GET['employment_type'] = 'full_time';
	$_GET['location'] = 'Vancouver';
	$_GET['featured'] = '1';
	$directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"featuredOnly":false,"perPage":12} /-->' );
	$assert( false !== strpos( $directory, 'LlamaHire Smoke Test Role' ), 'Directory combines keyword, employment, workplace, location, and featured filters' );
	$assert( false !== strpos( $directory, '1 open role' ) && false !== strpos( $directory, 'Clear filters' ), 'Directory reports matching results and offers a clear action' );
	$_GET['employment_type'] = 'part_time';
	$empty_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":12} /-->' );
	$assert( false !== strpos( $empty_directory, 'No matching open roles' ) && false !== strpos( $empty_directory, 'Clear filters' ), 'Directory provides a recoverable filtered empty state' );
	unset( $_GET['employment_type'], $_GET['location'], $_GET['featured'], $_GET['workplace'] );
	$filter_job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'LlamaHire Smoke Pagination Role', 'post_content' => 'A second open role for pagination coverage.' ) );
	\LlamaHire\Jobs::set_meta( $filter_job_id, array_merge( \LlamaHire\Jobs::get_meta( $job_id ), array( 'featured' => '0' ) ) );
	$active_job_search = $_GET['job_search'] ?? '';
	unset( $_GET['job_search'] );
	$department_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"department":"engineering","perPage":12} /-->' );
	$_GET['job_search'] = $active_job_search;
	$assert( false !== strpos( $department_directory, 'LlamaHire Smoke Test Role' ) && false === strpos( $department_directory, 'LlamaHire Smoke Pagination Role' ) && false !== strpos( $department_directory, 'name="department" value="engineering"' ) && false === strpos( $department_directory, '<select name="department"' ) && false === strpos( $department_directory, 'Clear filters' ), 'A fixed department directory limits results and preserves its department without presenting a misleading clear action' );
	$paginated_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":1} /-->' );
	$assert( false !== strpos( $paginated_directory, '2 open roles' ) && false !== strpos( $paginated_directory, 'job_page=2' ) && false !== strpos( $paginated_directory, 'Job results pages' ), 'Directory pagination preserves query state and exposes navigation semantics' );
	$_GET['job_page'] = '999';
	$recovered_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":1} /-->' );
	$assert( false !== strpos( $recovered_directory, '2 open roles' ) && false !== strpos( $recovered_directory, 'llamahire-job-card' ) && false === strpos( $recovered_directory, 'No matching open roles' ), 'Out-of-range directory pages recover to the final populated page' );
	unset( $_GET['job_page'] );
	wp_delete_post( $filter_job_id, true );
	$filter_job_id = 0;
	unset( $_GET['job_search'] );

	$form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . ',"heading":"Apply now"} /-->' );
	$assert( false !== strpos( $form, 'llamahire_apply' ) && false !== strpos( $form, 'method="post"' ) && false !== strpos( $form, 'enctype="multipart/form-data"' ), 'Application form retains its no-JavaScript multipart POST path' );
	$assert( false !== strpos( $form, 'name="submission_key"' ), 'Application form includes an idempotency key' );
	$assert( false !== strpos( $form, 'Candidate data is used only for hiring.' ), 'Application form explains how candidate information is used' );
	$assert( false !== strpos( $form, 'aria-describedby="llamahire-resume-help"' ) && false !== strpos( $form, 'aria-describedby="llamahire-application-privacy"' ), 'Application form associates upload help and privacy disclosure with their controls' );
	$assert( false !== strpos( $form, 'name="phone" inputmode="tel" maxlength="50"' ) && false === strpos( $form, 'llamahire-phone-help' ), 'Phone input provides a mobile keyboard and storage safeguard without prescriptive format guidance' );
	$assert( false !== strpos( $form, 'scheduled for deletion from this site after 365 days' ), 'Application privacy disclosure states the configured live-site retention period' );
	$assert( false !== strpos( $form, 'data-upload-feedback hidden' ) && false !== strpos( $form, 'role="status"' ) && false !== strpos( $form, '<progress data-upload-progress value="0" max="100" aria-label="Resume upload progress">' ), 'Application form includes hidden accessible upload status and progress semantics for progressive enhancement' );
	$assert( preg_match( '/name="phone"[^>]*required/', $form ) && preg_match( '/name="resume"[^>]*required/', $form ) && false === strpos( $form, 'name="cover_letter"' ), 'Application form renders configured required fields and omits hidden fields' );
	$optional_settings = array_merge( $sanitized_settings, array( 'application_phone' => 'hidden', 'application_resume' => 'optional', 'application_letter' => 'optional' ) );
	update_option( \LlamaHire\Settings::OPTION, $optional_settings, false );
	$optional_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	$assert( false === strpos( $optional_form, 'name="phone"' ) && preg_match( '/name="resume"[^>]*>/', $optional_form ) && ! preg_match( '/name="resume"[^>]*required/', $optional_form ) && false !== strpos( $optional_form, 'name="cover_letter"' ), 'Application form renders optional fields without browser-required attributes' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
	$valid_candidate = array( 'name' => 'Candidate', 'email' => 'candidate@example.test', 'phone' => '555-0100', 'letter' => '' );
	$assert( \LlamaHire\Applications::required_fields_are_valid( $valid_candidate, false, array( 'phone' => 'required', 'resume' => 'hidden', 'cover_letter' => 'optional' ) ), 'Server validation accepts a complete configured application without a hidden resume' );
	$assert( ! \LlamaHire\Applications::required_fields_are_valid( array_merge( $valid_candidate, array( 'phone' => '' ) ), false, array( 'phone' => 'required', 'resume' => 'optional', 'cover_letter' => 'optional' ) ), 'Server validation rejects a missing configured phone field' );
	$assert( ! \LlamaHire\Applications::required_fields_are_valid( array_merge( $valid_candidate, array( 'phone' => 'abcd' ) ), false, array( 'phone' => 'optional', 'resume' => 'optional', 'cover_letter' => 'optional' ) ), 'Server validation rejects a non-empty phone field without a valid phone number' );
	$assert( \LlamaHire\Applications::phone_is_valid( '+1 (604) 555-0100 ext. 42' ) && \LlamaHire\Applications::phone_is_valid( '1' ) && \LlamaHire\Applications::phone_is_valid( '604.555/0100' ) && '+1 (604) 555-0100 ext. 42' === \LlamaHire\Applications::sanitize_phone( "  +1 (604)  555-0100 ext. 42  " ), 'Phone validation accepts common punctuation, extensions, and any digit count while normalizing whitespace' );
	$assert( 'invalid_fields' === \LlamaHire\Applications::application_validation_error( array_merge( $valid_candidate, array( 'name' => str_repeat( 'n', 191 ) ) ), false, array( 'phone' => 'optional', 'resume' => 'optional', 'cover_letter' => 'optional' ) ), 'Server validation rejects candidate text longer than its storage limit' );
	$assert( ! \LlamaHire\Applications::required_fields_are_valid( $valid_candidate, false, array( 'phone' => 'optional', 'resume' => 'required', 'cover_letter' => 'required' ) ), 'Server validation rejects missing configured resume and cover-letter fields' );
	$_GET['application'] = 'required';
	$error_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	unset( $_GET['application'] );
	$assert( false !== strpos( $error_form, 'role="alert"' ), 'Application errors use an assertive accessible announcement' );
	$_GET['application'] = 'invalid_phone';
	$invalid_phone_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	unset( $_GET['application'] );
	$assert( false !== strpos( $invalid_phone_form, 'Not a valid phone number.' ) && false === strpos( $invalid_phone_form, '7 to 15 digits' ), 'Application form uses concise invalid-phone feedback without a digit-count rule' );
	$_GET['application'] = 'duplicate';
	$duplicate_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	unset( $_GET['application'] );
	$assert( false !== strpos( $duplicate_form, 'role="status"' ) && false !== strpos( $duplicate_form, 'We already have your application for this role.' ) && false === strpos( $duplicate_form, '<form method="post"' ), 'Duplicate applications receive a neutral success state without another form' );
	$client_limit = static function () { return 1; };
	$job_limit    = static function () { return 0; };
	add_filter( 'llamahire_submission_rate_limit', $client_limit );
	add_filter( 'llamahire_job_submission_rate_limit', $job_limit );
	$assert( \LlamaHire\Applications::consume_submission_limit( $job_id, 'smoke-test-client' ) && ! \LlamaHire\Applications::consume_submission_limit( $job_id, 'smoke-test-client' ), 'Repeated client submissions are rate limited' );
	remove_filter( 'llamahire_submission_rate_limit', $client_limit );
	remove_filter( 'llamahire_job_submission_rate_limit', $job_limit );
	$csv_method = new ReflectionMethod( \LlamaHire\Admin::class, 'safe_csv_value' );
	$csv_method->setAccessible( true );
	$assert( "' =SUM(A1:A2)" === $csv_method->invoke( null, ' =SUM(A1:A2)' ) && "'\n@SUM(A1:A2)" === $csv_method->invoke( null, "\n@SUM(A1:A2)" ), 'CSV export neutralizes formulas after leading whitespace' );
	$signature_method = new ReflectionMethod( get_class( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) ), 'validate_signature' );
	$signature_method->setAccessible( true );
	$invalid_resume = wp_tempnam( 'llamahire-invalid-resume.pdf' );
	file_put_contents( $invalid_resume, 'not a pdf' );
	$assert( is_wp_error( $signature_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ), $invalid_resume, 'pdf' ) ), 'Resume content must match the allowed file signature' );
	wp_delete_file( $invalid_resume );

	$repository     = $services->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
	$invalid_repository_application = $repository->create(
		array(
			'job_id' => $job_id,
			'name'   => 'Invalid Phone Candidate',
			'email'  => 'invalid-phone@example.test',
			'phone'  => 'abcd',
		)
	);
	$assert( is_wp_error( $invalid_repository_application ), 'Application repository defensively rejects invalid phone data from non-form callers' );
	$application_id = $repository->create(
		array(
			'job_id' => $job_id,
			'name'   => 'Smoke Test Candidate',
			'email'  => 'candidate@example.test',
			'phone'  => '555-0100',
			'status' => 'new',
		)
	);
	$assert( ! is_wp_error( $application_id ) && $application_id > 0, 'Application repository stores an application' );
	$application = $repository->find( $application_id );
	$assert( $application && 'new' === $application->status, 'Application repository retrieves the stored application' );
	$assert( true === $repository->update( $application_id, array( 'status' => 'reviewing', 'notes' => 'Smoke test note' ) ), 'Application repository updates allowed fields' );
	$application = $repository->find( $application_id );
	$assert( 'reviewing' === $application->status && 'Smoke test note' === $application->notes, 'Application repository persists status and notes' );
	$assert( true === $repository->update( $application_id, array( 'status' => 'interviewing' ) ), 'Application repository accepts the expanded hiring pipeline' );
	$application = $repository->find( $application_id );
	$assert( 'interviewing' === $application->status && ! empty( $application->stage_changed_at ), 'Application repository tracks the current stage and when it changed' );
	$application_history = \LlamaHire\Audit_Log::search( array( 'application_id' => $application_id ) );
	$assert( 2 === $application_history['total'] && 'application_status_changed' === $application_history['items'][0]->event_type && 'reviewing' === $application_history['items'][0]->from_state && 'interviewing' === $application_history['items'][0]->to_state, 'Application status changes create content-free audit events' );
	$assert( ! property_exists( $application, 'resume_path' ), 'Public application records do not expose private storage paths' );
	$query = $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY );
	$results = $query->search( array( 'job_id' => $job_id, 'status' => 'interviewing', 'per_page' => 1 ) );
	$assert( 1 === $results['total'] && 1 === count( $results['items'] ), 'Application query filters and paginates results' );
	$received_date = substr( $application->created_at, 0, 10 );
	$dataview_results = $query->search(
		array(
			'candidate'             => 'Smoke Test',
			'email'                 => 'candidate@example',
			'job_ids'               => array( $job_id ),
			'statuses'              => array( 'interviewing' ),
			'notification_statuses' => array( $application->notification_status ),
			'received_after'        => $received_date . ' 00:00:00',
			'received_before'       => $received_date . ' 23:59:59',
			'orderby'               => 'candidate',
			'order'                 => 'asc',
		)
	);
	$assert( 1 === $dataview_results['total'] && (int) $application_id === (int) $dataview_results['items'][0]->id, 'Applications DataViews query supports every visible column filter and an allow-listed sort' );
	$job_application_counts = $query->counts_by_job( array( $job_id, $privacy_page_id ) );
	$assert( 1 === $job_application_counts[ $job_id ] && 0 === $job_application_counts[ $privacy_page_id ], 'Application query returns bounded per-job counts including zero-result jobs' );
	$job_status_counts = $query->counts_by_job_and_status( array( $job_id, $privacy_page_id ) );
	$assert( 1 === $job_status_counts[ $job_id ]['interviewing'] && 0 === array_sum( $job_status_counts[ $privacy_page_id ] ), 'Application query returns per-stage job counts for the hiring dashboard' );
	$exported = iterator_to_array( $query->export_rows( array( 'job_id' => $job_id, 'status' => 'interviewing', 'search' => 'candidate@example.test' ) ) );
	$assert( 1 === count( $exported ) && 'Smoke Test Candidate' === $exported[0]['name'], 'Application export streams bounded rows using the active job, status, and candidate filters' );
	$original_get = $_GET;
	$original_user_id = get_current_user_id();
	$filter_admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	wp_set_current_user( (int) reset( $filter_admin_ids ) );
	$_GET = array( 'job_id' => $job_id, 'status' => 'interviewing', 's' => 'candidate@example.test' );
	ob_start();
	\LlamaHire\Admin::applications_page();
	$filtered_applications_html = ob_get_clean();
	$_GET = $original_get;
	$assert( false !== strpos( $filtered_applications_html, 'llamahire-applications-root' ) && wp_script_is( 'llamahire-admin-applications', 'enqueued' ) && wp_style_is( 'llamahire-admin-applications', 'enqueued' ), 'Recruiter inbox mounts the compiled DataViews interface and its WordPress component styles' );
	ob_start();
	\LlamaHire\Admin::job_column( 'llamahire_status', $job_id );
	$job_hiring_column_html = ob_get_clean();
	wp_set_current_user( $original_user_id );
	$assert( false !== strpos( $job_hiring_column_html, '1 application' ) && false !== strpos( $job_hiring_column_html, 'job_id=' . $job_id ), 'Job list links application counts directly to the filtered recruiter inbox' );
	$administrator_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	$board_manager_id = (int) reset( $administrator_ids );
	$assert( $board_manager_id > 0, 'Isolation fixtures have an explicit board manager account' );
	foreach ( array( 'one', 'two' ) as $suffix ) {
		$user_id = wp_insert_user( array( 'user_login' => 'llamahire-smoke-employer-' . $suffix . '-' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'employer-' . $suffix . '-' . wp_generate_password( 6, false ) . '@example.test', 'role' => \LlamaHire\Capabilities::EMPLOYER_ROLE ) );
		$employer_user_ids[] = $user_id;
		$owned_job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'pending', 'post_title' => 'Employer ' . $suffix . ' job', 'post_content' => 'Owned job.', 'post_author' => $user_id ) );
		$employer_job_ids[] = $owned_job_id;
		\LlamaHire\Jobs::set_meta( $owned_job_id, array( 'organization_name' => 'Employer ' . $suffix . ' Company', 'organization_url' => 'https://example.test/', 'application_method' => 'internal', 'application_target' => 'jobs-' . $suffix . '@example.test' ) );
		$owned_application_id = $repository->create( array( 'job_id' => $owned_job_id, 'name' => 'Employer ' . $suffix . ' candidate', 'email' => 'candidate-' . $suffix . '@example.test' ) );
		$employer_application_ids[] = $owned_application_id;
	}
	\LlamaHire\Audit_Log::record( 'job_submitted', $employer_job_ids[0], 0, '', '', $employer_user_ids[0] );
	\LlamaHire\Audit_Log::record( 'job_submitted', $employer_job_ids[1], 0, '', '', $employer_user_ids[1] );
	wp_set_current_user( $employer_user_ids[0] );
	$scoped_results = $query->search( array_merge( array( 'per_page' => 20 ), \LlamaHire\Ownership::query_arguments() ) );
	$scoped_counts = $query->counts( \LlamaHire\Ownership::query_arguments() );
	$scoped_recent = $query->recent( 5, \LlamaHire\Ownership::query_arguments() );
	$scoped_export = iterator_to_array( $query->export_rows( \LlamaHire\Ownership::query_arguments() ) );
	$assert( 1 === $scoped_results['total'] && 1 === array_sum( array_intersect_key( $scoped_counts, array_flip( array_keys( \LlamaHire\Applications::workflow_statuses() ) ) ) ) && 1 === count( $scoped_recent ) && 1 === count( $scoped_export ), 'Employer application lists, counts, recent rows, and exports are scoped to authored jobs' );
	$assert( \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[0], \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[1], \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'Employer ownership checks allow own candidates and deny another company candidate' );
	$scoped_audit = \LlamaHire\Audit_Log::search( \LlamaHire\Ownership::query_arguments() );
	$assert( $scoped_audit['total'] >= 1 && ! array_filter( $scoped_audit['items'], static function ( $event ) use ( $employer_job_ids ) { return (int) $event->job_id === (int) $employer_job_ids[1]; } ), 'Employer audit history includes owned jobs and excludes another company’s events' );
	$board_settings = array_merge( $sanitized_settings, array( 'site_mode' => \LlamaHire\Settings::SITE_MODE_JOB_BOARD ) );
	update_option( \LlamaHire\Settings::OPTION, $board_settings, false );
	$moderation_mail = array();
	$capture_moderation_mail = static function ( $return, $attributes ) use ( &$moderation_mail ) {
		$moderation_mail[] = $attributes;
		return true;
	};
	wp_set_current_user( $board_manager_id );
	add_filter( 'pre_wp_mail', $capture_moderation_mail, 10, 2 );
	\LlamaHire\Employer_Notifications::job_submitted( $employer_job_ids[0] );
	wp_update_post( array( 'ID' => $employer_job_ids[0], 'post_status' => 'publish' ) );
	remove_filter( 'pre_wp_mail', $capture_moderation_mail );
	$employer_one = get_userdata( $employer_user_ids[0] );
	$assert( 2 === count( $moderation_mail ) && 'hiring@example.test' === $moderation_mail[0]['to'] && $employer_one->user_email === $moderation_mail[1]['to'], 'Submission notifies the board inbox and approval notifies the owning employer' );
	$assert( false !== strpos( $moderation_mail[0]['message'], admin_url( 'post.php?post=' . $employer_job_ids[0] ) ) && false !== strpos( $moderation_mail[1]['message'], 'approved and published' ), 'Moderation emails contain the review destination and a clear employer outcome' );
	ob_start();
	\LlamaHire\Admin_Workspaces::render_dashboard();
	$board_dashboard = ob_get_clean();
	$assert( false !== strpos( $board_dashboard, 'llamahire_job_state=open' ) && false !== strpos( $board_dashboard, 'post_status=pending' ) && false !== strpos( $board_dashboard, 'users.php?role=llamahire_employer' ) && false !== strpos( $board_dashboard, 'page=llamahire-applications' ), 'Board summary metrics link to live listings, awaiting review, employers, and applications' );
	$assert( false !== strpos( $board_dashboard, 'job_id=' . $employer_job_ids[0] ) && false !== strpos( $board_dashboard, '>1</strong>' ) && false !== strpos( $board_dashboard, '>application</span>' ), 'Active listings show one linked all-status application total per job' );
	$assert( false !== strpos( $board_dashboard, 'View all listings' ) && false !== strpos( $board_dashboard, 'View all activity' ) && false !== strpos( $board_dashboard, 'llamahire-activity-link' ), 'Dashboard collection links name their destinations and recent activity links to its affected record' );
	wp_set_current_user( $employer_user_ids[0] );
	$_GET['job_id'] = $employer_job_ids[0];
	$employer_form = do_shortcode( '[llamahire_submit_job]' );
	unset( $_GET['job_id'] );
	$employer_jobs = do_shortcode( '[llamahire_my_jobs]' );
	$assert( false !== strpos( $employer_form, 'Employer one job' ) && false !== strpos( $employer_form, 'Submit for review' ) && false !== strpos( $employer_jobs, 'Employer one job' ) && false === strpos( $employer_jobs, 'Employer two job' ), 'Employer portal supports editing and lists only the signed-in author’s jobs' );
	$new_job_form = do_shortcode( '[llamahire_submit_job]' );
	$assert( false !== strpos( $new_job_form, 'Employer one Company' ) && false !== strpos( $employer_jobs, '>Preview</a>' ) && false !== strpos( $employer_jobs, '>Delete</summary>' ), 'New submissions prefill the employer’s latest company details and My Jobs provides preview and confirmed deletion controls' );
	wp_set_current_user( $board_manager_id );
	$assert( \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[0], \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[1], \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'Board managers retain authorized board-wide candidate access' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'application_method' => 'external_url', 'application_target' => 'https://apply.example.test/role' ) );
	$external_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	$assert( false !== strpos( $external_form, 'Apply on the employer website' ) && false !== strpos( $external_form, 'https://apply.example.test/role' ) && false === strpos( $external_form, 'llamahire_apply' ), 'Per-job external application routing replaces the internal candidate form' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'application_method' => 'internal', 'application_target' => '' ) );
	$health = $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE )->health();
	$assert( $health['available'], 'Private resume storage is available' );
	$storage = $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE );
	$directory_method = new ReflectionMethod( get_class( $storage ), 'directory' );
	$directory_method->setAccessible( true );
	$resume_directory = $directory_method->invoke( $storage, true );
	$assert( ! is_wp_error( $resume_directory ), 'Candidate lifecycle tests can use the protected resume directory' );
	$retention_resume_path = trailingslashit( $resume_directory ) . wp_unique_filename( $resume_directory, 'retention-smoke.pdf' );
	file_put_contents( $retention_resume_path, "%PDF-1.4\n%%EOF\n" );
	$retention_application_id = $repository->create(
		array(
			'job_id'       => $job_id,
			'name'         => 'Retention Candidate',
			'email'        => 'retention@example.test',
			'resume_token' => $retention_resume_path,
			'resume_name'  => 'retention-smoke.pdf',
		)
	);
	$lifecycle = $services->get( \LlamaHire\Service_IDs::CANDIDATE_DATA );
	$assert( true === $lifecycle->delete_resume( $retention_application_id ) && ! file_exists( $retention_resume_path ) && ! $repository->find( $retention_application_id )->has_resume, 'Resume-only erasure removes the private file while preserving the application' );
	$resume_history = \LlamaHire\Audit_Log::search( array( 'application_id' => $retention_application_id ) );
	$assert( 1 === $resume_history['total'] && 'application_resume_deleted' === $resume_history['items'][0]->event_type, 'Resume deletion creates an audit event without retaining its filename or path' );
	$retention_resume_path = trailingslashit( $resume_directory ) . wp_unique_filename( $resume_directory, 'expired-retention-smoke.pdf' );
	file_put_contents( $retention_resume_path, "%PDF-1.4\n%%EOF\n" );
	$wpdb->update( $table, array( 'resume_path' => $retention_resume_path, 'resume_name' => 'expired-retention-smoke.pdf', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $retention_application_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	$retention_settings = $sanitized_settings;
	$retention_settings['retention_days'] = 30;
	update_option( \LlamaHire\Settings::OPTION, $retention_settings, false );
	$cleanup = $lifecycle->cleanup_expired( 25, time() );
	$assert( 1 === $cleanup['erased'] && 0 === $cleanup['failed'] && ! file_exists( $retention_resume_path ) && ! $repository->find( $retention_application_id ) && $repository->find( $application_id ), 'Scheduled retention erases expired records and resumes without touching current applications' );
	$erasure_history = \LlamaHire\Audit_Log::search( array( 'application_id' => $retention_application_id ) );
	$assert( 2 === $erasure_history['total'] && 'application_erased' === $erasure_history['items'][0]->event_type && false === strpos( wp_json_encode( $erasure_history['items'] ), 'retention@example.test' ), 'Application erasure leaves only privacy-safe operational history' );
	$retention_application_id = 0;
	$retention_resume_path = '';
	$manual_resume_path = trailingslashit( $resume_directory ) . wp_unique_filename( $resume_directory, 'manual-erasure-smoke.pdf' );
	file_put_contents( $manual_resume_path, "%PDF-1.4\n%%EOF\n" );
	$manual_application_id = $repository->create( array( 'job_id' => $job_id, 'name' => 'Erasure Candidate', 'email' => 'erasure@example.test', 'resume_token' => $manual_resume_path, 'resume_name' => 'manual-erasure-smoke.pdf' ) );
	$assert( true === $lifecycle->erase( $manual_application_id ) && ! file_exists( $manual_resume_path ) && ! $repository->find( $manual_application_id ), 'Manual erasure permanently removes both the application and its private resume' );

	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$assert( isset( $exporters['llamahire-applications'], $erasers['llamahire-applications'] ) && is_callable( $exporters['llamahire-applications']['callback'] ) && is_callable( $erasers['llamahire-applications']['callback'] ), 'WordPress privacy tools register LlamaHire export and erasure callbacks' );
	$privacy_resume_path = trailingslashit( $resume_directory ) . wp_unique_filename( $resume_directory, 'privacy-export-smoke.pdf' );
	file_put_contents( $privacy_resume_path, "%PDF-1.4\n%%EOF\n" );
	$privacy_application_id = $repository->create(
		array(
			'job_id'       => $job_id,
			'name'         => 'Privacy Candidate',
			'email'        => 'privacy-candidate@example.test',
			'phone'        => '555-0117',
			'cover_letter' => 'Privacy export cover letter.',
			'resume_token' => $privacy_resume_path,
			'resume_name'  => 'privacy-export-smoke.pdf',
			'status'       => 'reviewing',
		)
	);
	$repository->update( $privacy_application_id, array( 'notes' => 'Privacy export hiring note.' ) );
	$privacy_export = call_user_func( $exporters['llamahire-applications']['callback'], 'PRIVACY-CANDIDATE@EXAMPLE.TEST', 1 );
	$privacy_export_json = wp_json_encode( $privacy_export['data'] );
	$assert( $privacy_export['done'] && 1 === count( $privacy_export['data'] ) && false !== strpos( $privacy_export_json, 'Privacy export cover letter.' ) && false !== strpos( $privacy_export_json, 'Privacy export hiring note.' ) && false !== strpos( $privacy_export_json, 'privacy-export-smoke.pdf' ), 'Personal-data export returns every stored candidate field for the exact email identity' );
	$assert( false === strpos( $privacy_export_json, $privacy_resume_path ), 'Personal-data export never exposes a private resume storage token' );
	$empty_privacy_export = call_user_func( $exporters['llamahire-applications']['callback'], 'nobody@example.test', 1 );
	$assert( $empty_privacy_export['done'] && array() === $empty_privacy_export['data'], 'Personal-data export completes cleanly when no application matches' );
	$privacy_erasure = call_user_func( $erasers['llamahire-applications']['callback'], 'PRIVACY-CANDIDATE@EXAMPLE.TEST', 1 );
	$assert( $privacy_erasure['items_removed'] && ! $privacy_erasure['items_retained'] && $privacy_erasure['done'] && ! file_exists( $privacy_resume_path ) && ! $repository->find( $privacy_application_id ), 'WordPress personal-data erasure removes the matching application and private resume together' );
	$privacy_application_id = 0;
	$privacy_resume_path = '';

	$idempotency_key = wp_generate_uuid4();
	$idempotent_data = array( 'job_id' => $job_id, 'name' => 'Idempotent Candidate', 'email' => 'idempotent@example.test', 'submission_key' => $idempotency_key );
	$first = $repository->create_once( $idempotent_data );
	$second = $repository->create_once( $idempotent_data );
	$assert( ! is_wp_error( $first ) && $first['created'], 'First keyed application creates a record' );
	$assert( ! is_wp_error( $second ) && ! $second['created'] && $first['id'] === $second['id'], 'Repeated submission key resolves to the original application' );
	$repository->delete( $first['id'] );

	$duplicate_email = 'duplicate-policy@example.test';
	$original_duplicate = $repository->create_once( array( 'job_id' => $job_id, 'name' => 'Original Candidate', 'email' => $duplicate_email, 'submission_key' => wp_generate_uuid4() ) );
	$repeated_duplicate = $repository->create_once( array( 'job_id' => $job_id, 'name' => 'Replacement Candidate', 'email' => strtoupper( $duplicate_email ), 'submission_key' => wp_generate_uuid4() ) );
	$assert( $original_duplicate['created'] && ! $repeated_duplicate['created'] && 'job_email' === $repeated_duplicate['reason'] && $original_duplicate['id'] === $repeated_duplicate['id'], 'The default job and email policy preserves the original application case-insensitively' );
	$assert( $original_duplicate['id'] === $repository->find_duplicate( $job_id, $duplicate_email ), 'The repository returns the canonical application for duplicate checks' );
	$allow_duplicates = static function () { return 'allow'; };
	add_filter( 'llamahire_duplicate_application_policy', $allow_duplicates );
	$allowed_duplicate = $repository->create_once( array( 'job_id' => $job_id, 'name' => 'Allowed Candidate', 'email' => $duplicate_email, 'submission_key' => wp_generate_uuid4() ) );
	remove_filter( 'llamahire_duplicate_application_policy', $allow_duplicates );
	$assert( $allowed_duplicate['created'] && $allowed_duplicate['id'] !== $original_duplicate['id'], 'The duplicate-policy filter can allow a future resubmission workflow' );
	$repository->delete( $allowed_duplicate['id'] );
	$repository->delete( $original_duplicate['id'] );

	$mail_recipients = array();
	$mail_messages   = array();
	$mail_capture    = static function ( $return, $attributes ) use ( &$mail_recipients, &$mail_messages ) {
		$mail_recipients[] = $attributes['to'];
		$mail_messages[] = $attributes;
		return true;
	};
	add_filter( 'pre_wp_mail', $mail_capture, 10, 2 );
	$services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $job_id, array( 'employer' ) );
	remove_filter( 'pre_wp_mail', $mail_capture );
	$assert( array( 'hiring@example.test' ) === $mail_recipients, 'Employer notifications use the canonical setup hiring inbox' );
	$assert( 'Applicant Smoke Test Candidate: LlamaHire Smoke Test Role' === $mail_messages[0]['subject'] && false !== strpos( $mail_messages[0]['message'], \LlamaHire\Admin::applications_url() ) && in_array( 'From: Example Hiring <jobs@example.test>', $mail_messages[0]['headers'], true ) && in_array( 'Content-Type: text/plain; charset=UTF-8', $mail_messages[0]['headers'], true ), 'Configured templates render safe placeholders and an explicit plain-text sender' );
	$preview = $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->preview( array( 'name' => 'Preview Candidate', 'email' => 'preview@example.test' ), $job_id );
	$assert( 'Application received: LlamaHire Smoke Test Role' === $preview['candidate']['subject'] && false !== strpos( $preview['candidate']['message'], 'Example Employer' ) && false !== strpos( $preview['candidate']['message'], home_url( '/' ) ), 'Notification previews use saved templates and the known careers-site URL without sending' );
	add_filter( 'pre_wp_mail', $mail_capture, 10, 2 );
	$test_delivery = $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->test_delivery( 'diagnostic@example.test' );
	remove_filter( 'pre_wp_mail', $mail_capture );
	$assert( $test_delivery['success'] && 'diagnostic@example.test' === end( $mail_recipients ) && 'LlamaHire email delivery test' === end( $mail_messages )['subject'], 'Diagnostic email uses the configured sender without candidate content' );
	$assert( 'good' === \LlamaHire\Settings::email_configuration_health()['status'], 'Site Health reports a valid hiring inbox and sender configuration' );

	$mail_failure = static function () { return false; };
	add_filter( 'pre_wp_mail', $mail_failure );
	$notification = $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $job_id );
	remove_filter( 'pre_wp_mail', $mail_failure );
	$assert( ! $notification['employer'] && ! $notification['candidate'], 'Mail failures are returned without throwing' );
	$repository->record_notification_result( $application_id, $notification );
	$application = $repository->find( $application_id );
	$assert( 'failed' === $application->notification_status && 1 === (int) $application->notification_attempts, 'Failed notification attempt is persisted' );
	$mail_success = static function () { return true; };
	add_filter( 'pre_wp_mail', $mail_success );
	$employer_result = $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $job_id, array( 'employer' ) );
	$repository->record_notification_result( $application_id, $employer_result );
	$application = $repository->find( $application_id );
	$assert( 'partial' === $application->notification_status && $application->employer_notified_at && ! $application->candidate_notified_at, 'Partial retry preserves channel-level delivery state' );
	$candidate_result = $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $job_id, array( 'candidate' ) );
	remove_filter( 'pre_wp_mail', $mail_success );
	$repository->record_notification_result( $application_id, $candidate_result );
	$application = $repository->find( $application_id );
	$assert( 'sent' === $application->notification_status && 3 === (int) $application->notification_attempts, 'Missing-channel retry reaches Sent without losing prior success' );

	update_option( 'llamahire_db_version', '0.1.0' );
	delete_option( \LlamaHire\Migrations::OPTION );
	$assert( \LlamaHire\Migrations::run(), 'Database migration runner can replay idempotently' );
	$assert( null !== $repository->find( $application_id ), 'Schema replay preserves existing application data' );
	$assert( false === get_option( 'llamahire_db_version', false ), 'Legacy database version option is retired' );
	update_option( \LlamaHire\Migrations::OPTION, '999', false );
	$assert( \LlamaHire\Migrations::run() && '999' === (string) get_option( \LlamaHire\Migrations::OPTION ), 'Older code never downgrades a newer database schema' );
	update_option( \LlamaHire\Migrations::OPTION, LLAMAHIRE_SCHEMA_VERSION, false );

	WP_CLI::success( count( $checks ) . ' LlamaHire smoke checks passed.' );
} finally {
	if ( $retention_application_id ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::CANDIDATE_DATA )->erase( $retention_application_id );
	}
	if ( $retention_resume_path && file_exists( $retention_resume_path ) ) {
		wp_delete_file( $retention_resume_path );
	}
	if ( $privacy_application_id ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::CANDIDATE_DATA )->erase( $privacy_application_id );
	}
	if ( $privacy_resume_path && file_exists( $privacy_resume_path ) ) {
		wp_delete_file( $privacy_resume_path );
	}
	if ( $application_id ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY )->delete( $application_id );
	}
	if ( $job_id ) {
		wp_delete_post( $job_id, true );
	}
	if ( $sitemap_job_id ) {
		wp_delete_post( $sitemap_job_id, true );
	}
	if ( $filter_job_id ) {
		wp_delete_post( $filter_job_id, true );
	}
	if ( $department_term_id ) {
		wp_delete_term( $department_term_id, 'llamahire_department' );
	}
	if ( $privacy_page_id ) {
		wp_delete_post( $privacy_page_id, true );
	}
	foreach ( $employer_application_ids as $employer_application_id ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY )->delete( $employer_application_id );
	}
	foreach ( $employer_job_ids as $employer_job_id ) {
		wp_delete_post( $employer_job_id, true );
	}
	foreach ( $employer_user_ids as $employer_user_id ) {
		wp_delete_user( $employer_user_id );
	}
	foreach ( array_filter( array_merge( array( $job_id, $sitemap_job_id, $filter_job_id ), $employer_job_ids ) ) as $audit_job_id ) {
		$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $audit_job_id ), array( '%d' ) );
	}
	wp_set_current_user( $original_current_user_id );
	if ( false === $original_settings ) {
		delete_option( \LlamaHire\Settings::OPTION );
	} else {
		update_option( \LlamaHire\Settings::OPTION, $original_settings, false );
	}
	if ( false === $original_setup ) {
		delete_option( \LlamaHire\Setup::OPTION );
	} else {
		update_option( \LlamaHire\Setup::OPTION, $original_setup, false );
	}
	if ( false === $original_legacy_settings ) {
		delete_option( 'llamahire_settings' );
	} else {
		update_option( 'llamahire_settings', $original_legacy_settings, false );
	}
}
