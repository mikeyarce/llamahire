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
$application_id = 0;
$privacy_page_id = 0;
$original_settings = get_option( \LlamaHire\Settings::OPTION, false );
$original_setup    = get_option( \LlamaHire\Setup::OPTION, false );
$original_legacy_settings = get_option( 'llamahire_settings', false );

try {
	$assert( defined( 'LLAMAHIRE_API_VERSION' ) && '1.0.0-alpha.4' === LLAMAHIRE_API_VERSION, 'Public API version is declared' );
	$assert( 1 === did_action( 'llamahire_ready' ), 'Public ready action fired once' );
	$services = \LlamaHire\Plugin::instance()->services();
	$assert( $services instanceof \LlamaHire\Contracts\Service_Container, 'Public service container is available' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY ) instanceof \LlamaHire\Contracts\Application_Repository, 'Application repository satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY ) instanceof \LlamaHire\Contracts\Application_Query, 'Application query satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS ) instanceof \LlamaHire\Contracts\Notification_Service, 'Notification service satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) instanceof \LlamaHire\Contracts\Resume_Storage, 'Resume storage satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER ) instanceof \LlamaHire\Contracts\Schema_Builder, 'Schema builder satisfies its public contract' );
	$locked = false;
	try {
		$services->set( 'llamahire.smoke_test', new stdClass() );
	} catch ( LogicException $exception ) {
		$locked = true;
	}
	$assert( $locked, 'Service container is immutable after initialization' );
	$assert( LLAMAHIRE_SCHEMA_VERSION === (string) get_option( \LlamaHire\Migrations::OPTION ), 'Database schema is at the declared version' );
	$assert( LLAMAHIRE_CAPABILITIES_VERSION === (string) get_option( \LlamaHire\Capabilities::OPTION ), 'Capability grants are at the declared version' );
	$administrator = get_role( 'administrator' );
	$subscriber    = get_role( 'subscriber' );
	$assert( $administrator && $administrator->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && $administrator->has_cap( \LlamaHire\Capabilities::RETRY_NOTIFICATIONS ) && $administrator->has_cap( 'publish_llamahire_jobs' ), 'Administrators receive candidate and job capabilities' );
	$assert( ! $subscriber || ( ! $subscriber->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! $subscriber->has_cap( 'edit_llamahire_jobs' ) ), 'Subscribers receive no hiring capabilities by default' );

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
	$sanitized_settings = \LlamaHire\Settings::sanitize( array( 'name' => 'Example Employer', 'default_locality' => ' Vancouver ', 'default_region' => ' bc ', 'default_country' => 'ca', 'default_currency' => 'cad', 'notification_email' => 'hiring@example.test', 'privacy_text' => ' Candidate data is used only for hiring. ', 'privacy_page_id' => $privacy_page_id ) );
	$assert( 'Vancouver' === $sanitized_settings['default_locality'] && 'bc' === $sanitized_settings['default_region'] && 'CA' === $sanitized_settings['default_country'] && 'CAD' === $sanitized_settings['default_currency'] && 'hiring@example.test' === $sanitized_settings['notification_email'] && 'Candidate data is used only for hiring.' === $sanitized_settings['privacy_text'] && $privacy_page_id === $sanitized_settings['privacy_page_id'], 'Setup defaults normalize organization, privacy, and hiring inbox values' );
	$invalid_settings = \LlamaHire\Settings::sanitize( array( 'name' => 'Example Employer', 'default_currency' => 'dollars', 'notification_email' => 'not-an-email' ) );
	$assert( '' === $invalid_settings['default_currency'] && '' === $invalid_settings['notification_email'], 'Invalid setup currency and hiring inbox values fail safe' );
	update_option( 'llamahire_settings', array( 'notification_email' => 'legacy@example.test' ), false );
	update_option( \LlamaHire\Settings::OPTION, array( 'name' => 'Legacy Employer' ), false );
	$assert( 'legacy@example.test' === \LlamaHire\Settings::get()['notification_email'], 'Legacy hiring inbox remains available until canonical settings are saved' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
	$assert( 'hiring@example.test' === \LlamaHire\Settings::get()['notification_email'], 'Canonical organization settings own the hiring inbox' );
	delete_option( \LlamaHire\Setup::OPTION );
	\LlamaHire\Setup::mark_pending();
	$assert( 'pending' === \LlamaHire\Setup::state()['status'], 'First activation queues the setup flow' );
	update_option( \LlamaHire\Setup::OPTION, array( 'version' => \LlamaHire\Setup::VERSION, 'status' => 'completed' ), false );
	\LlamaHire\Setup::mark_pending();
	$assert( 'completed' === \LlamaHire\Setup::state()['status'], 'Reactivation preserves completed setup state' );
	$job_defaults = \LlamaHire\Jobs::defaults();
	$assert( 'Vancouver' === $job_defaults['address_locality'] && 'bc' === $job_defaults['address_region'] && 'CA' === $job_defaults['address_country'] && 'CAD' === $job_defaults['salary_currency'], 'New jobs inherit configured location and currency defaults' );
	$careers_content = \LlamaHire\Setup::careers_page_content();
	$assert( has_block( 'llamahire/job-search', $careers_content ) && has_block( 'llamahire/job-filters', $careers_content ) && has_block( 'llamahire/jobs-directory', $careers_content ), 'Generated Careers pages compose Search, Filters, and Jobs Directory blocks' );
	$invalid_job_meta = \LlamaHire\Jobs::sanitize_meta( array( 'deadline' => '2026-99-99', 'salary_min' => 120000, 'salary_max' => 90000, 'salary_currency' => 'dollars' ) );
	$assert( '' === $invalid_job_meta['deadline'] && '' === $invalid_job_meta['salary_min'] && '' === $invalid_job_meta['salary_max'] && '' === $invalid_job_meta['salary_currency'], 'Invalid dates, reversed salary ranges, and invalid currencies fail safe' );
	$non_positive_salary = \LlamaHire\Jobs::sanitize_meta( array( 'salary_min' => -1, 'salary_max' => 0, 'salary_currency' => 'USD' ) );
	$assert( '' === $non_positive_salary['salary_min'] && '' === $non_positive_salary['salary_max'], 'Non-positive salary boundaries are omitted' );
	$assert( \LlamaHire\Jobs::valid_date( '2028-02-29' ) && ! \LlamaHire\Jobs::valid_date( '2027-02-29' ), 'Application deadlines require a real calendar date' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/jobs-directory' ), 'Jobs Directory block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/job-search' ), 'Job Search block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/job-filters' ), 'Job Filters block is registered' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/application-form' ), 'Application Form block is registered' );
	$assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Applications table exists' );
	$index_names = array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' ) );
	$assert( in_array( 'submission_key', $index_names, true ), 'Submission keys have a unique database index' );

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
	delete_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT );
	delete_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION );
	update_option( \LlamaHire\Migrations::OPTION, '5', false );
	$assert( \LlamaHire\Migrations::run() && 'FULL_TIME' === get_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT, true ) && false !== strpos( get_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION, true ), 'Vancouver' ), 'Schema migration 6 backfills normalized employment and location filters' );
	$assert( \LlamaHire\Jobs::is_open( $job_id ), 'Published job is open for applications' );
	$job_meta = \LlamaHire\Jobs::get_meta( $job_id );
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
	$paginated_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":1} /-->' );
	$assert( false !== strpos( $paginated_directory, '2 open roles' ) && false !== strpos( $paginated_directory, 'job_page=2' ) && false !== strpos( $paginated_directory, 'Job results pages' ), 'Directory pagination preserves query state and exposes navigation semantics' );
	wp_delete_post( $filter_job_id, true );
	$filter_job_id = 0;
	unset( $_GET['job_search'] );

	$form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . ',"heading":"Apply now"} /-->' );
	$assert( false !== strpos( $form, 'llamahire_apply' ) && false !== strpos( $form, 'enctype="multipart/form-data"' ), 'Application form renders for the job' );
	$assert( false !== strpos( $form, 'name="submission_key"' ), 'Application form includes an idempotency key' );
	$assert( false !== strpos( $form, 'Candidate data is used only for hiring.' ), 'Application form explains how candidate information is used' );
	$assert( false !== strpos( $form, 'aria-describedby="llamahire-resume-help"' ) && false !== strpos( $form, 'aria-describedby="llamahire-application-privacy"' ), 'Application form associates upload help and privacy disclosure with their controls' );
	$_GET['application'] = 'required';
	$error_form = \LlamaHire\Blocks::render_form( array( 'jobId' => $job_id ) );
	unset( $_GET['application'] );
	$assert( false !== strpos( $error_form, 'role="alert"' ), 'Application errors use an assertive accessible announcement' );
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
	$assert( ! property_exists( $application, 'resume_path' ), 'Public application records do not expose private storage paths' );
	$query = $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY );
	$results = $query->search( array( 'job_id' => $job_id, 'status' => 'reviewing', 'per_page' => 1 ) );
	$assert( 1 === $results['total'] && 1 === count( $results['items'] ), 'Application query filters and paginates results' );
	$exported = iterator_to_array( $query->export_rows( array( 'job_id' => $job_id ) ) );
	$assert( 1 === count( $exported ) && 'Smoke Test Candidate' === $exported[0]['name'], 'Application export streams bounded rows' );
	$health = $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE )->health();
	$assert( $health['available'], 'Private resume storage is available' );

	$idempotency_key = wp_generate_uuid4();
	$idempotent_data = array( 'job_id' => $job_id, 'name' => 'Idempotent Candidate', 'email' => 'idempotent@example.test', 'submission_key' => $idempotency_key );
	$first = $repository->create_once( $idempotent_data );
	$second = $repository->create_once( $idempotent_data );
	$assert( ! is_wp_error( $first ) && $first['created'], 'First keyed application creates a record' );
	$assert( ! is_wp_error( $second ) && ! $second['created'] && $first['id'] === $second['id'], 'Repeated submission key resolves to the original application' );
	$repository->delete( $first['id'] );

	$mail_recipients = array();
	$mail_capture    = static function ( $return, $attributes ) use ( &$mail_recipients ) {
		$mail_recipients[] = $attributes['to'];
		return true;
	};
	add_filter( 'pre_wp_mail', $mail_capture, 10, 2 );
	$services->get( \LlamaHire\Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $job_id, array( 'employer' ) );
	remove_filter( 'pre_wp_mail', $mail_capture );
	$assert( array( 'hiring@example.test' ) === $mail_recipients, 'Employer notifications use the canonical setup hiring inbox' );

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
	if ( $privacy_page_id ) {
		wp_delete_post( $privacy_page_id, true );
	}
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
