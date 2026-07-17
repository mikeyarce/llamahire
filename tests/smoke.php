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
$application_id = 0;

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
	$sanitized_settings = \LlamaHire\Settings::sanitize( array( 'name' => 'Example Employer', 'default_country' => 'ca', 'default_currency' => 'cad' ) );
	$assert( 'CA' === $sanitized_settings['default_country'] && 'CAD' === $sanitized_settings['default_currency'], 'Organization defaults normalize country and currency codes' );
	$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'llamahire/jobs-directory' ), 'Jobs Directory block is registered' );
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
	$assert( \LlamaHire\Jobs::is_open( $job_id ), 'Published job is open for applications' );
	$job_meta = \LlamaHire\Jobs::get_meta( $job_id );
	$assert( ! empty( $job_meta['job_identifier'] ) && 'CA' === $job_meta['address_country'], 'Job model preserves a stable identifier and structured address' );
	$schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
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
	$assert( 200 === $rest_response->get_status() && 'remote' === $rest_saved['workplace'] && 'remote' === get_post_meta( $job_id, \LlamaHire\Jobs::META_WORKPLACE, true ), 'Block editor REST saves persist and synchronize query metadata' );
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
	$assert( array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ) && false !== strpos( \LlamaHire\Blocks::render_form( array( 'jobId' => $job_id ) ), 'closed' ), 'Closed jobs suppress schema and applications' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'closed' => '0', 'deadline' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ) );
	$assert( ! \LlamaHire\Jobs::is_open( $job_id ) && array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ), 'Expired jobs suppress active JobPosting markup' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) );

	$directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"featuredOnly":false,"perPage":12} /-->' );
	$assert( false !== strpos( $directory, 'LlamaHire Smoke Test Role' ), 'Directory renders the open job' );

	$form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . ',"heading":"Apply now"} /-->' );
	$assert( false !== strpos( $form, 'llamahire_apply' ) && false !== strpos( $form, 'enctype="multipart/form-data"' ), 'Application form renders for the job' );
	$assert( false !== strpos( $form, 'name="submission_key"' ), 'Application form includes an idempotency key' );

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
}
