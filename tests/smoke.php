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
$job_type_term_ids = array();
$application_id = 0;
$bulk_application_id = 0;
$retention_application_id = 0;
$retention_resume_path = '';
$privacy_application_id = 0;
$privacy_resume_path = '';
$privacy_page_id = 0;
$employer_user_ids = array();
$employer_job_ids = array();
$employer_application_ids = array();
$registration_user_id = 0;
$lifecycle_duplicate_id = 0;
$activation_migration_job_id = 0;
$activation_migration_term_id = 0;
$uninstall_local_path = '';
$uninstall_attachment_id = 0;
$original_settings = get_option( \LlamaHire\Settings::OPTION, false );
$original_setup    = get_option( \LlamaHire\Setup::OPTION, false );
$original_legacy_settings = get_option( 'llamahire_settings', false );
$original_current_user_id = get_current_user_id();

require_once LLAMAHIRE_PATH . 'includes/class-uninstaller.php';

try {
	$assert( defined( 'LLAMAHIRE_API_VERSION' ) && '1.0.0-alpha.13' === LLAMAHIRE_API_VERSION, 'Public API version is declared' );
	$assert( 1 === did_action( 'llamahire_ready' ), 'Public ready action fired once' );
	$services = \LlamaHire\Plugin::instance()->services();
	$assert( $services instanceof \LlamaHire\Contracts\Service_Container, 'Public service container is available' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY ) instanceof \LlamaHire\Contracts\Application_Repository, 'Application repository satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY ) instanceof \LlamaHire\Contracts\Application_Query, 'Application query satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::NOTIFICATIONS ) instanceof \LlamaHire\Contracts\Notification_Service, 'Notification service satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) instanceof \LlamaHire\Contracts\Resume_Storage, 'Resume storage satisfies its public contract' );
	$assert( \LlamaHire\Services\Resume_Storage::class === get_class( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) ), 'Private outside-webroot resume storage is the default driver' );
	$vip_storage        = new \LlamaHire\Services\VIP_ACL_Resume_Storage();
	$vip_storage_health = $vip_storage->health();
	$assert( 'vip_acl' === $vip_storage_health['driver'] && ! $vip_storage_health['available'] && ! $vip_storage_health['protected'], 'VIP ACL resume storage fails closed when platform access controls are unavailable' );
	$vip_path_method = new ReflectionMethod( \LlamaHire\Services\VIP_ACL_Resume_Storage::class, 'is_private_path' );
	$vip_path_method->setAccessible( true );
	$assert( $vip_path_method->invoke( $vip_storage, '/wp-content/uploads/llamahire-private/resume.pdf' ) && ! $vip_path_method->invoke( $vip_storage, '/wp-content/uploads/resume.pdf' ), 'VIP ACL driver limits its deny rule to the dedicated private resume prefix' );
	$assert( $services->get( \LlamaHire\Service_IDs::CANDIDATE_DATA ) instanceof \LlamaHire\Contracts\Candidate_Data_Lifecycle, 'Candidate-data lifecycle satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER ) instanceof \LlamaHire\Contracts\Schema_Builder, 'Schema builder satisfies its public contract' );
	$assert( $services->get( \LlamaHire\Service_IDs::EXTENSION_ACCESS ) instanceof \LlamaHire\Contracts\Extension_Access, 'Extension access satisfies its public contract' );
	$locked = false;
	try {
		$services->set( 'llamahire.smoke_test', new stdClass() );
	} catch ( LogicException $exception ) {
		$locked = true;
	}
	$assert( $locked, 'Service container is immutable after initialization' );
	require __DIR__ . '/review-regressions.php';
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
	$assert( $employer && $employer->has_cap( 'edit_llamahire_jobs' ) && $employer->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! $employer->has_cap( 'edit_others_llamahire_jobs' ) && ! $employer->has_cap( 'edit_published_llamahire_jobs' ) && ! $employer->has_cap( 'delete_published_llamahire_jobs' ) && ! $employer->has_cap( 'publish_llamahire_jobs' ) && ! $employer->has_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS ), 'Employers use the moderated frontend workflow without core published-job, board-wide ownership, publication, or erasure powers' );
	$hiring_manager = get_role( \LlamaHire\Capabilities::HIRING_MANAGER_ROLE );
	$assert( $hiring_manager && $hiring_manager->has_cap( 'edit_others_llamahire_jobs' ) && $hiring_manager->has_cap( 'publish_llamahire_jobs' ) && $hiring_manager->has_cap( \LlamaHire\Capabilities::MANAGE_APPLICATIONS ) && $hiring_manager->has_cap( \LlamaHire\Capabilities::EXPORT_APPLICATIONS ) && $hiring_manager->has_cap( \LlamaHire\Capabilities::DOWNLOAD_RESUMES ) && ! $hiring_manager->has_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS ) && ! $hiring_manager->has_cap( 'manage_options' ), 'Hiring Managers operate site-wide jobs and candidate workflows without site settings or permanent erasure access' );

	$assert( post_type_exists( 'llamahire_job' ), 'Job post type is registered' );
	$assert( taxonomy_exists( 'llamahire_department' ), 'Department taxonomy is registered' );
	$assert( taxonomy_exists( \LlamaHire\Jobs::TYPE_TAXONOMY ), 'Operator-managed job type taxonomy is registered' );
	$job_type = get_post_type_object( 'llamahire_job' );
	$department_type = get_taxonomy( 'llamahire_department' );
	$employment_type_taxonomy = get_taxonomy( \LlamaHire\Jobs::TYPE_TAXONOMY );
	$assert( 'edit_llamahire_jobs' === $job_type->cap->edit_posts && 'edit_llamahire_job' === $job_type->cap->edit_post, 'Job post type maps dedicated capabilities' );
	$empty_job = new WP_Post( (object) array( 'ID' => 0, 'post_type' => \LlamaHire\Jobs::POST_TYPE ) );
	$assert( empty( $job_type->template ) && 'Add job title' === apply_filters( 'enter_title_here', 'Add title', $empty_job ), 'New jobs start with an empty block canvas and a job-specific title prompt' );
	$untitled_filter_job = (object) array( 'ID' => 1806, 'post_title' => '' );
	$titled_filter_job   = (object) array( 'ID' => 1807, 'post_title' => 'Platform Engineer' );
	$assert( 'Untitled job #1806' === \LlamaHire\Admin::application_filter_job_label( $untitled_filter_job ) && 'Platform Engineer' === \LlamaHire\Admin::application_filter_job_label( $titled_filter_job ), 'Admin job filters provide a stable label for untitled jobs without changing titled jobs' );
	$assert( 'manage_llamahire_departments' === $department_type->cap->manage_terms, 'Department taxonomy maps dedicated capabilities' );
	$assert( 'manage_llamahire_job_types' === $employment_type_taxonomy->cap->manage_terms && $employer->has_cap( 'assign_llamahire_job_types' ) && ! $employer->has_cap( 'manage_llamahire_job_types' ), 'Job types are operator-managed while employers can assign existing types' );
	$assert( false === $employment_type_taxonomy->meta_box_cb, 'Job types use the single-value employment dropdown instead of a duplicate taxonomy meta box' );
	$registered_job_meta = get_registered_meta_keys( 'post', 'llamahire_job' );
	$assert( isset( $registered_job_meta[ \LlamaHire\Jobs::META_KEY ] ) && ! empty( $registered_job_meta[ \LlamaHire\Jobs::META_KEY ]['show_in_rest'] ), 'Structured job settings are registered for the block editor' );
	$privacy_page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LlamaHire Smoke Privacy', 'post_content' => 'Privacy fixture.' ) );
	$assert( ! is_wp_error( $privacy_page_id ) && $privacy_page_id > 0, 'A published candidate privacy page can be selected' );
	$sanitized_settings = \LlamaHire\Settings::sanitize( array( 'site_mode' => 'company', 'name' => 'Example Employer', 'website' => 'https://employer.example.test/', 'default_locality' => ' Vancouver ', 'default_region' => ' bc ', 'default_country' => 'ca', 'default_currency' => 'cad', 'notification_email' => 'hiring@example.test', 'email_sender_name' => ' Example Hiring ', 'email_sender_email' => 'jobs@example.test', 'employer_email_subject' => 'Applicant {candidate_name}: {job_title}', 'employer_email_body' => "{candidate_name} applied.\n\nOpen {applications_url}", 'candidate_email_subject' => 'Application received: {job_title}', 'candidate_email_body' => "Hello {candidate_name},\n\nThank you from {site_name}.\n{site_url}", 'privacy_text' => ' Candidate data is used only for hiring. ', 'privacy_page_id' => $privacy_page_id, 'application_phone' => 'required', 'application_resume' => 'required', 'application_letter' => 'hidden' ) );
	$assert( 'Vancouver' === $sanitized_settings['default_locality'] && 'bc' === $sanitized_settings['default_region'] && 'CA' === $sanitized_settings['default_country'] && 'CAD' === $sanitized_settings['default_currency'] && '' === $sanitized_settings['google_geocoding_api_key'] && 'hiring@example.test' === $sanitized_settings['notification_email'] && 'Candidate data is used only for hiring.' === $sanitized_settings['privacy_text'] && $privacy_page_id === $sanitized_settings['privacy_page_id'] && 365 === $sanitized_settings['retention_days'], 'Setup defaults normalize organization, geocoding, privacy, retention, and hiring inbox values' );
	$assert( 0 === $sanitized_settings['usage_reporting'] && 1 === \LlamaHire\Settings::sanitize( array( 'usage_reporting' => '1' ) )['usage_reporting'], 'Usage reporting requires an explicit saved opt-in' );
	$telemetry_requests = array();
	$telemetry_interceptor = static function ( $preempt, $arguments, $url ) use ( &$telemetry_requests ) {
		if ( 'https://us.i.posthog.com/i/v0/e/' === $url ) {
			$telemetry_requests[] = json_decode( $arguments['body'], true );
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{}' );
		}
		return $preempt;
	};
	add_filter( 'pre_http_request', $telemetry_interceptor, 10, 3 );
	$telemetry_original_id = get_option( 'llamahire_telemetry_installation_id', false );
	$telemetry_original_registered = get_option( \LlamaHire\Telemetry::REGISTERED, false );
	$telemetry_original_retry = get_transient( 'llamahire_telemetry_retry_after' );
	try {
		$telemetry_settings = \LlamaHire\Settings::get();
		$telemetry_settings['usage_reporting'] = 1;
		update_option( \LlamaHire\Settings::OPTION, $telemetry_settings, false );
		$assert( (bool) wp_next_scheduled( \LlamaHire\Telemetry::HOOK, array( 'site_snapshot' ) ), 'Opting in schedules a site snapshot' );
		\LlamaHire\Telemetry::send( 'site_snapshot' );
		$telemetry_payload = end( $telemetry_requests );
		$telemetry_properties = $telemetry_payload['properties'] ?? array();
		$base_properties = array( '$process_person_profile', 'site_url', 'site_mode', 'plugin_version', 'wp_version', 'php_version' );
		$expected_snapshot_properties = array( 'jobs_published', 'jobs_draft', 'jobs_pending', 'active_plugins', 'active_theme', 'locale', 'is_multisite' );
		$assert( 'llamahire_site_snapshot' === ( $telemetry_payload['event'] ?? '' ) && count( array_merge( $base_properties, $expected_snapshot_properties ) ) === count( $telemetry_properties ) && count( $expected_snapshot_properties ) === count( array_intersect( $expected_snapshot_properties, array_keys( $telemetry_properties ) ) ) && is_int( $telemetry_properties['jobs_published'] ?? null ), 'Site snapshot sends only aggregate jobs and disclosed system fields' );
		\LlamaHire\Telemetry::send( 'reporting_enabled' );
		$assert( true === get_option( \LlamaHire\Telemetry::REGISTERED ) && $telemetry_payload['distinct_id'] === end( $telemetry_requests )['distinct_id'], 'Reporting-enabled delivery records registration and preserves the installation ID' );
		$sent_before_invalid_event = count( $telemetry_requests );
		\LlamaHire\Telemetry::send( 'unrecognized_event' );
		$assert( $sent_before_invalid_event === count( $telemetry_requests ), 'Unknown reporting event names are not delivered' );
		$telemetry_settings['usage_reporting'] = 0;
		update_option( \LlamaHire\Settings::OPTION, $telemetry_settings, false );
		$sent_before_opt_out = count( $telemetry_requests );
		\LlamaHire\Telemetry::send( 'site_snapshot' );
		$assert( ! wp_next_scheduled( \LlamaHire\Telemetry::HOOK, array( 'site_snapshot' ) ) && $sent_before_opt_out === count( $telemetry_requests ), 'Opting out clears the snapshot schedule and blocks delivery' );
	} finally {
		remove_filter( 'pre_http_request', $telemetry_interceptor, 10 );
		if ( false === $telemetry_original_id ) {
			delete_option( 'llamahire_telemetry_installation_id' );
		}
		if ( false === $telemetry_original_registered ) {
			delete_option( \LlamaHire\Telemetry::REGISTERED );
		} else {
			update_option( \LlamaHire\Telemetry::REGISTERED, $telemetry_original_registered, false );
		}
		if ( false !== $telemetry_original_retry ) {
			set_transient( 'llamahire_telemetry_retry_after', $telemetry_original_retry, DAY_IN_SECONDS );
		}
		if ( false === $original_settings ) {
			delete_option( \LlamaHire\Settings::OPTION );
		} else {
			update_option( \LlamaHire\Settings::OPTION, $original_settings, false );
		}
	}
	$geocoding_settings = \LlamaHire\Settings::sanitize( array( 'google_geocoding_api_key' => ' test-geocoding-key ' ) );
	$assert( 'test-geocoding-key' === $geocoding_settings['google_geocoding_api_key'], 'Google geocoding credentials are normalized without enabling the optional service by default' );
	$preserve_setup_settings = new ReflectionMethod( \LlamaHire\Setup::class, 'preserve_unmanaged_settings' );
	$preserve_setup_settings->setAccessible( true );
	$preserved_setup_input = $preserve_setup_settings->invoke( null, array( 'name' => 'Updated setup name' ), array_merge( $sanitized_settings, array( 'google_geocoding_api_key' => 'preserved-geocoding-key' ) ) );
	$assert( 'preserved-geocoding-key' === $preserved_setup_input['google_geocoding_api_key'] && 'Updated setup name' === $preserved_setup_input['name'], 'Saving setup preserves the separately managed geocoding credential while accepting setup-owned fields' );
	$assert( 'job_board' === \LlamaHire\Settings::sanitize_site_mode( 'job_board' ) && 'company' === \LlamaHire\Settings::sanitize_site_mode( 'marketplace' ), 'Site purpose accepts the documented job-board mode and fails unknown values to company mode' );
	$assert( isset( \LlamaHire\Settings::country_options()['CA'] ) && isset( \LlamaHire\Settings::currency_options()['CAD'] ), 'Country and currency selectors include normalized ISO options' );
	$job_board_settings = \LlamaHire\Settings::sanitize( array( 'site_mode' => 'job_board', 'name' => 'Hamilton Job Board', 'website' => 'https://unrelated.example.test/', 'employer_approval' => 'automatic', 'employer_policy_text' => 'Only accurate listings are allowed.', 'active_listing_limit' => 7, 'listing_duration_days' => 60 ) );
	$assert( home_url( '/' ) === $job_board_settings['website'], 'Job-board mode uses the known WordPress site URL instead of asking for a duplicate board website' );
	$assert( 'automatic' === $job_board_settings['employer_approval'] && 'Only accurate listings are allowed.' === $job_board_settings['employer_policy_text'] && 'manual' === \LlamaHire\Settings::employer_approval( 'unknown' ), 'Employer registration settings allow automatic approval and fail unknown approval policies to operator review' );
	$assert( 7 === $job_board_settings['active_listing_limit'] && 60 === $job_board_settings['listing_duration_days'] && 30 === \LlamaHire\Settings::listing_duration_days( 31 ), 'Listing policy settings normalize an active-listing allowance and an allow-listed default duration' );
	$anti_spam_settings = \LlamaHire\Settings::sanitize( array( 'site_mode' => 'job_board', 'anti_spam_provider' => 'turnstile', 'anti_spam_site_key' => ' test-site-key ', 'anti_spam_secret_key' => ' test-secret-key ', 'anti_spam_registration' => 1, 'anti_spam_applications' => 1 ) );
	$assert( 'turnstile' === $anti_spam_settings['anti_spam_provider'] && 'test-site-key' === $anti_spam_settings['anti_spam_site_key'] && 'test-secret-key' === $anti_spam_settings['anti_spam_secret_key'] && 1 === $anti_spam_settings['anti_spam_registration'] && 'none' === \LlamaHire\Anti_Spam::sanitize_provider( 'unknown' ), 'Spam-protection settings allow supported providers and fail unknown providers closed to disabled' );
	$anti_spam_config = array_intersect_key( $anti_spam_settings, array_flip( array( 'anti_spam_provider', 'anti_spam_site_key', 'anti_spam_secret_key', 'anti_spam_registration', 'anti_spam_applications' ) ) );
	$assert( false !== strpos( \LlamaHire\Settings::default_privacy_text( 'job_board' ), 'job-board operator' ), 'Job-board mode has candidate privacy copy that names both data recipients' );
	$assert( null === \LlamaHire\Settings::public_page( 0 ), 'An unset public-page setting never resolves to the current front-end page' );
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
	$editor_organization = \LlamaHire\Jobs::editor_organization();
	$assert( array( 'name', 'website', 'site_mode' ) === array_keys( $editor_organization ) && ! isset( $editor_organization['google_geocoding_api_key'], $editor_organization['anti_spam_secret_key'] ), 'Job-editor configuration exposes only the non-sensitive organization fields it consumes' );
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
	$invalid_job_meta = \LlamaHire\Jobs::sanitize_meta( array( 'deadline' => '2026-99-99', 'listing_expires' => 'not-a-date', 'salary_min' => 120000, 'salary_max' => 90000, 'salary_currency' => 'dollars' ) );
	$assert( '' === $invalid_job_meta['deadline'] && '' === $invalid_job_meta['listing_expires'] && '' === $invalid_job_meta['salary_min'] && '' === $invalid_job_meta['salary_max'] && '' === $invalid_job_meta['salary_currency'], 'Invalid dates, reversed salary ranges, and invalid currencies fail safe' );
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
	$discovery_blocks = array_map( static function ( $name ) { return WP_Block_Type_Registry::get_instance()->get_registered( $name ); }, array( 'llamahire/job-search', 'llamahire/job-filters', 'llamahire/jobs-directory' ) );
	$assert( ! array_filter( $discovery_blocks, static function ( $block_type ) { return empty( $block_type->supports['interactivity'] ); } ), 'Job discovery blocks declare Interactivity API support' );
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
	$assert( in_array( 'notification_created', $index_names, true ), 'Notification filters have a composite status and received-date index' );
	$notes_table = \LlamaHire\Application_Notes::table();
	$migration_application_ids = array();
	$migration_fixture_prefix  = strtolower( wp_generate_password( 8, false, false ) );
	for ( $migration_index = 0; $migration_index <= \LlamaHire\Migrations::BATCH_SIZE; $migration_index++ ) {
		$wpdb->insert(
			$table,
			array(
				'job_id'    => 0,
				'name'      => 'Migration Fixture',
				'email'     => $migration_fixture_prefix . '-' . $migration_index . '@example.test',
				'notes'     => 'Legacy migration note ' . $migration_index,
				'created_at'=> current_time( 'mysql', true ),
				'updated_at'=> current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$migration_application_ids[] = (int) $wpdb->insert_id;
	}
	update_option( \LlamaHire\Migrations::OPTION, '10', false );
	$migration_first_batch = \LlamaHire\Migrations::run();
	$assert( ! $migration_first_batch && '11' === (string) get_option( \LlamaHire\Migrations::OPTION ) && get_option( \LlamaHire\Migrations::CURSOR_PREFIX . '12' ) && wp_next_scheduled( \LlamaHire\Migrations::CONTINUE_HOOK ), 'Application-note migration stops after one bounded batch and schedules a resumable continuation' );
	$migration_passes = 0;
	do {
		$migration_complete = \LlamaHire\Migrations::run();
		++$migration_passes;
	} while ( ! $migration_complete && $migration_passes < 5 );
	$placeholders = implode( ', ', array_fill( 0, count( $migration_application_ids ), '%d' ) );
	$migrated_note_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notes_table} WHERE application_id IN ({$placeholders}) AND is_legacy = 1", $migration_application_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated from a bounded integer fixture list and all values are prepared.
	$assert( $migration_complete && count( $migration_application_ids ) === $migrated_note_count && LLAMAHIRE_SCHEMA_VERSION === (string) get_option( \LlamaHire\Migrations::OPTION ) && false === get_option( \LlamaHire\Migrations::CURSOR_PREFIX . '12', false ), 'Resumed migration preserves every legacy note and advances the schema only after all batches complete' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$notes_table} WHERE application_id IN ({$placeholders})", $migration_application_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated from a bounded integer fixture list and all values are prepared.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $migration_application_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated from a bounded integer fixture list and all values are prepared.
	$assert( $notes_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notes_table ) ), 'Private application notes table exists' );
	$activation_migration_job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Activation migration fixture' ) );
	$assert( $activation_migration_job_id > 0, 'Activation migration fixture can be created' );
	unregister_taxonomy( \LlamaHire\Jobs::TYPE_TAXONOMY );
	\LlamaHire\Jobs::set_meta( $activation_migration_job_id, array( 'employment_type' => 'activation_contract' ) );
	update_option( \LlamaHire\Migrations::OPTION, '9', false );
	$assert( ! \LlamaHire\Migrations::run() && '9' === (string) get_option( \LlamaHire\Migrations::OPTION ) && false === get_option( \LlamaHire\Migrations::CURSOR_PREFIX . '10', false ), 'A failed job-type conversion leaves its schema version and cursor unchanged for a safe retry' );
	\LlamaHire\Activator::activate();
	$activation_migration_term    = get_term_by( 'slug', 'activation_contract', \LlamaHire\Jobs::TYPE_TAXONOMY );
	$activation_migration_term_id = $activation_migration_term instanceof WP_Term ? (int) $activation_migration_term->term_id : 0;
	$assert( taxonomy_exists( \LlamaHire\Jobs::TYPE_TAXONOMY ) && LLAMAHIRE_SCHEMA_VERSION === (string) get_option( \LlamaHire\Migrations::OPTION ) && $activation_migration_term_id && has_term( $activation_migration_term_id, \LlamaHire\Jobs::TYPE_TAXONOMY, $activation_migration_job_id ), 'Activation registers job taxonomies before migrations and retries the complete employment-type conversion' );
	wp_delete_post( $activation_migration_job_id, true );
	wp_delete_term( $activation_migration_term_id, \LlamaHire\Jobs::TYPE_TAXONOMY );
	$activation_migration_job_id = 0;
	$activation_migration_term_id = 0;

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

	foreach ( array( 'full_time' => 'Full time', 'part_time' => 'Part time' ) as $slug => $name ) {
		$term = wp_insert_term( $name, \LlamaHire\Jobs::TYPE_TAXONOMY, array( 'slug' => $slug ) );
		if ( ! is_wp_error( $term ) ) {
			$job_type_term_ids[] = (int) $term['term_id'];
		}
	}
	\LlamaHire\Jobs::set_meta(
		$job_id,
		array(
			'location'        => 'Vancouver, BC',
			'address_street'  => '1285 W Pender St',
			'address_locality'=> 'Vancouver',
			'address_region'  => 'BC',
			'postal_code'     => 'V6E 4B1',
			'address_country' => 'CA',
			'employment_type' => 'full_time',
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
			'application_method' => 'internal',
			'application_target' => 'private-hiring@example.test',
		)
	);
	$department_term = wp_insert_term( 'Engineering', 'llamahire_department', array( 'slug' => 'engineering' ) );
	$department_term_id = is_wp_error( $department_term ) ? 0 : (int) $department_term['term_id'];
	$assert( $department_term_id && ! is_wp_error( wp_set_object_terms( $job_id, $department_term_id, 'llamahire_department' ) ), 'Published jobs can be assigned to a department used by landing pages' );
	wp_set_object_terms( $job_id, array( 'full_time' ), \LlamaHire\Jobs::TYPE_TAXONOMY );
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
	$assert( \LlamaHire\Migrations::run() && 'full_time' === get_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT, true ) && false !== strpos( get_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION, true ), 'Vancouver' ), 'Schema migrations backfill normalized employment and location filters and convert job types to terms' );
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
	$assert( false !== strpos( $details_html, 'llamahire-job-facts' ) && false !== strpos( $details_html, 'Company' ) && false !== strpos( $details_html, 'Employment' ) && false !== strpos( $details_html, 'LlamaHire Test Employer' ) && false !== strpos( $details_html, '1285 W Pender St' ) && false !== strpos( $details_html, $job_meta['job_identifier'] ), 'Single Job Details renders structured candidate-facing facts supplied through job context' );
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
	$assert( false !== strpos( $automatic_single_job_content, 'llamahire-job-facts is-compact has-last-row-3' ) && false !== strpos( $automatic_single_job_content, 'class="is-posted"' ) && false !== strpos( $automatic_single_job_content, 'class="is-deadline"' ) && false === strpos( $automatic_single_job_content, 'llamahire-job-fact-icon' ) && false === strpos( $automatic_single_job_content, '1285 W Pender St' ), 'Automatic single-job facts use balanced text-only cells with scannable date labels and no street address' );
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
	$geocode_requests = 0;
	update_option( \LlamaHire\Settings::OPTION, array_merge( $sanitized_settings, array( 'google_geocoding_api_key' => 'test-geocoding-key' ) ), false );
	$geocode_mock = static function ( $preempt, $args, $url ) use ( &$geocode_requests ) {
		if ( 0 !== strpos( $url, \LlamaHire\Geocoding::ENDPOINT ) ) {
			return $preempt;
		}
		++$geocode_requests;
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'status' => 'OK', 'results' => array( array( 'geometry' => array( 'location' => array( 'lat' => 49.288711, 'lng' => -123.120703 ) ) ) ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $geocode_mock, 10, 3 );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'postal_code' => 'V6E 4B2' ) );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => 91000 ) );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => 90000 ) );
	$geocode_hash = get_post_meta( $job_id, \LlamaHire\Geocoding::META_HASH, true );
	delete_transient( \LlamaHire\Geocoding::CACHE_PREFIX . substr( $geocode_hash, 0, 32 ) );
	$assert( 0 === $geocode_requests && 'queued' === get_post_meta( $job_id, \LlamaHire\Geocoding::META_STATUS, true ) && wp_next_scheduled( \LlamaHire\Geocoding::HOOK, array( $job_id, $geocode_hash, 1 ) ), 'Saving a changed physical address queues geocoding without blocking the save or duplicating work on unrelated updates' );
	\LlamaHire\Geocoding::process( $job_id, $geocode_hash, 1 );
	remove_filter( 'pre_http_request', $geocode_mock, 10 );
	$coordinates = \LlamaHire\Geocoding::coordinates( $job_id );
	$assert( 1 === $geocode_requests && 49.288711 === ( $coordinates['latitude'] ?? null ) && -123.120703 === ( $coordinates['longitude'] ?? null ) && 'success' === get_post_meta( $job_id, \LlamaHire\Geocoding::META_STATUS, true ) && false !== get_transient( \LlamaHire\Geocoding::CACHE_PREFIX . substr( $geocode_hash, 0, 32 ) ), 'The queued lookup stores validated coordinates and a shared address-hash cache' );
	$geocode_cache_key = \LlamaHire\Geocoding::CACHE_PREFIX . substr( $geocode_hash, 0, 32 );
	$stale_geocode_cache = get_transient( $geocode_cache_key );
	$change_address_during_cache_read = static function ( $preempt ) use ( $job_id, $stale_geocode_cache ) {
		\LlamaHire\Jobs::set_meta( $job_id, array( 'postal_code' => 'V6E 4B9' ) );
		return $stale_geocode_cache;
	};
	add_filter( 'pre_transient_' . $geocode_cache_key, $change_address_during_cache_read );
	\LlamaHire\Geocoding::process( $job_id, $geocode_hash, 1 );
	remove_filter( 'pre_transient_' . $geocode_cache_key, $change_address_during_cache_read );
	$new_geocode_hash = get_post_meta( $job_id, \LlamaHire\Geocoding::META_HASH, true );
	$assert( $new_geocode_hash !== $geocode_hash && array() === \LlamaHire\Geocoding::coordinates( $job_id ) && 'queued' === get_post_meta( $job_id, \LlamaHire\Geocoding::META_STATUS, true ), 'A cached lookup cannot stale-write coordinates after the job address changes concurrently' );
	set_transient( \LlamaHire\Geocoding::CACHE_PREFIX . substr( $new_geocode_hash, 0, 32 ), $stale_geocode_cache, DAY_IN_SECONDS );
	\LlamaHire\Geocoding::process( $job_id, $new_geocode_hash, 1 );
	$coordinates = \LlamaHire\Geocoding::coordinates( $job_id );
	$assert( 49.288711 === ( $coordinates['latitude'] ?? null ) && -123.120703 === ( $coordinates['longitude'] ?? null ), 'Address-bound cached coordinates remain available after the current hash is validated' );
	$schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$posts_sitemap = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
	$job_sitemap_urls = $posts_sitemap->get_url_list( 1, \LlamaHire\Jobs::POST_TYPE );
	$job_sitemap_entry = current( array_filter( $job_sitemap_urls, static function ( $url ) use ( $job_id ) { return get_permalink( $job_id ) === $url['loc']; } ) );
	$assert( $job_sitemap_entry && get_post_modified_time( DATE_W3C, true, $job_id ) === $job_sitemap_entry['lastmod'], 'Published jobs appear in the XML sitemap with an accurate modification time' );
	$assert( 'JobPosting' === ( $schema['@type'] ?? '' ) && 'FULL_TIME' === ( $schema['employmentType'] ?? '' ) && 90000.0 === ( $schema['baseSalary']['value']['minValue'] ?? null ) && 'YEAR' === ( $schema['baseSalary']['value']['unitText'] ?? '' ), 'Schema builder exposes a supported job type plus the employer-provided salary range and pay unit' );
	$assert( 'CA' === ( $schema['jobLocation']['address']['addressCountry'] ?? '' ) && 'Vancouver' === ( $schema['jobLocation']['address']['addressLocality'] ?? '' ), 'Schema builder emits a complete physical location' );
	$assert( 49.288711 === ( $schema['jobLocation']['geo']['latitude'] ?? null ) && -123.120703 === ( $schema['jobLocation']['geo']['longitude'] ?? null ), 'Schema builder adds validated derived coordinates to the same physical job location' );
	$assert( 'LlamaHire Test Employer' === ( $schema['hiringOrganization']['name'] ?? '' ) && $job_meta['job_identifier'] === ( $schema['identifier']['value'] ?? '' ), 'Schema builder emits the hiring organization and stable identifier' );
	$assert( false === isset( $schema['jobLocationType'] ), 'Hybrid jobs are not incorrectly marked as fully remote' );
	$assert( false !== strpos( $schema['validThrough'] ?? '', $job_meta['deadline'] ), 'Schema expiry uses the visible application deadline' );
	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$original_user_id = get_current_user_id();
	wp_set_current_user( 0 );
	$public_rest_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/llamahire_job/' . $job_id ) );
	$public_rest_data     = $public_rest_response->get_data();
	$public_rest_meta     = $public_rest_data['meta'][ \LlamaHire\Jobs::META_KEY ] ?? array();
	$assert( 200 === $public_rest_response->get_status() && '' === ( $public_rest_meta['application_target'] ?? null ), 'Public job REST responses redact the private internal-application recipient' );
	wp_set_current_user( (int) $admin_ids[0] );
	$rest_meta = \LlamaHire\Jobs::get_meta( $job_id );
	$rest_meta['workplace'] = 'remote';
	$rest_meta['applicant_countries'] = 'US, CA';
	$rest_request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $job_id );
	$rest_request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => $rest_meta ) );
	$rest_response = rest_do_request( $rest_request );
	$rest_response_data = $rest_response->get_data();
	wp_set_current_user( $original_user_id );
	$rest_saved = \LlamaHire\Jobs::get_meta( $job_id );
	$assert( 200 === $rest_response->get_status() && 'private-hiring@example.test' === ( $rest_response_data['meta'][ \LlamaHire\Jobs::META_KEY ]['application_target'] ?? '' ) && 'private-hiring@example.test' === $rest_saved['application_target'] && 'remote' === $rest_saved['workplace'] && 'remote' === get_post_meta( $job_id, \LlamaHire\Jobs::META_WORKPLACE, true ) && 'full_time' === get_post_meta( $job_id, \LlamaHire\Jobs::META_EMPLOYMENT, true ) && 'US, CA' === get_post_meta( $job_id, \LlamaHire\Jobs::META_LOCATION, true ), 'Authorized block-editor REST saves retain private routing while synchronizing query metadata' );
	$assert( array() === \LlamaHire\Geocoding::coordinates( $job_id ) && 'not_applicable' === get_post_meta( $job_id, \LlamaHire\Geocoding::META_STATUS, true ), 'Switching a job to fully remote removes stale physical coordinates without contacting the provider' );
	$remote_schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$assert( 'TELECOMMUTE' === ( $remote_schema['jobLocationType'] ?? '' ) && 2 === count( $remote_schema['applicantLocationRequirements'] ?? array() ), 'Fully remote schema includes eligible applicant countries' );
	$assert( false === isset( $remote_schema['jobLocation'] ), 'Fully remote schema does not claim a physical reporting location' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'workplace' => 'hybrid' ) );
	update_option( \LlamaHire\Settings::OPTION, array_merge( $sanitized_settings, array( 'google_geocoding_api_key' => 'test-geocoding-key' ) ), false );
	$geocode_error_requests = 0;
	$geocode_error_mock = static function ( $preempt, $args, $url ) use ( &$geocode_error_requests ) {
		if ( 0 !== strpos( $url, \LlamaHire\Geocoding::ENDPOINT ) ) {
			return $preempt;
		}
		++$geocode_error_requests;
		return new WP_Error( 'llamahire_geocoding_fixture', 'Provider unavailable.' );
	};
	add_filter( 'pre_http_request', $geocode_error_mock, 10, 3 );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'postal_code' => 'V6E 4B3' ) );
	$failed_geocode_hash = get_post_meta( $job_id, \LlamaHire\Geocoding::META_HASH, true );
	\LlamaHire\Geocoding::process( $job_id, $failed_geocode_hash, \LlamaHire\Geocoding::MAX_ATTEMPTS );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'salary_min' => 90500 ) );
	remove_filter( 'pre_http_request', $geocode_error_mock, 10 );
	$assert( 1 === $geocode_error_requests && 'V6E 4B3' === \LlamaHire\Jobs::get_meta( $job_id )['postal_code'] && array() === \LlamaHire\Geocoding::coordinates( $job_id ) && 'error' === get_post_meta( $job_id, \LlamaHire\Geocoding::META_STATUS, true ) && $failed_geocode_hash === get_post_meta( $job_id, \LlamaHire\Geocoding::META_HASH, true ) && \LlamaHire\Geocoding::MAX_ATTEMPTS === (int) get_post_meta( $job_id, \LlamaHire\Geocoding::META_ATTEMPTS, true ), 'A terminal geocoding failure persists its address hash and attempt state so unrelated saves do not repeat the provider request or retain stale coordinates' );
	update_option( \LlamaHire\Settings::OPTION, $sanitized_settings, false );
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
	$assert( isset( \LlamaHire\Jobs::post_states( array(), get_post( $job_id ) )['llamahire_closed'] ), 'Manually closed jobs are identified as closed in the job list' );
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
	$assert( isset( \LlamaHire\Jobs::post_states( array(), get_post( $job_id ) )['llamahire_expired'] ), 'Deadline-ended jobs are identified as expired in the job list' );
	$assert( ! \LlamaHire\Jobs::is_open( $job_id ) && array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ), 'Expired jobs suppress active JobPosting markup' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'listing_expires' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ) );
	$assert( ! \LlamaHire\Jobs::is_open( $job_id ) && array() === $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id ), 'Listing expiration independently closes applications and suppresses active JobPosting markup' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'listing_expires' => gmdate( 'Y-m-d', strtotime( '+15 days' ) ) ) );
	$expiry_schema = $services->get( \LlamaHire\Service_IDs::SCHEMA_BUILDER )->build( $job_id );
	$assert( false !== strpos( $expiry_schema['validThrough'] ?? '', gmdate( 'Y-m-d', strtotime( '+15 days' ) ) ), 'Schema availability uses the earlier listing expiration when it precedes the application deadline' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'listing_expires' => '' ) );
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
	$assert( false !== strpos( $search_block, 'data-llamahire-query-form' ) && false !== strpos( $search_block, 'data-wp-on--input="actions.debounce"' ) && false !== strpos( $filters_block, 'data-wp-on--change="actions.update"' ) && false !== strpos( $filters_block, 'llamahire-query-submit' ), 'Job discovery forms expose automatic Interactivity API updates while retaining their submit fallback' );
	$empty_text_search = do_blocks( '<!-- wp:llamahire/job-search {"label":"  ","placeholder":"","buttonLabel":""} /-->' );
	$empty_text_filters = do_blocks( '<!-- wp:llamahire/job-filters {"buttonLabel":""} /-->' );
	$assert( false !== strpos( $empty_text_search, '>Search jobs</span>' ) && false !== strpos( $empty_text_search, 'placeholder="Job title or keyword"' ) && false !== strpos( $empty_text_search, '>Search</button>' ) && false !== strpos( $empty_text_filters, '>Apply filters</button>' ), 'Empty customizable query labels retain accessible translated defaults' );
	$_GET['employment_type'] = 'full_time';
	$_GET['location'] = 'Vancouver';
	$_GET['featured'] = '1';
	$directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"featuredOnly":false,"perPage":12} /-->' );
	$assert( false !== strpos( $directory, 'LlamaHire Smoke Test Role' ), 'Directory combines keyword, employment, workplace, location, and featured filters' );
	$assert( false !== strpos( $directory, 'data-llamahire-location-menu' ) && false !== strpos( $directory, 'data-llamahire-location-search' ) && false !== strpos( $directory, 'name="location[]"' ) && false !== strpos( $directory, 'Vancouver, BC, CA' ), 'Directory offers a searchable location menu populated from open job locations' );
	$assert( false !== strpos( $directory, '1 open role' ) && false !== strpos( $directory, 'Clear filters' ), 'Directory reports matching results and offers a clear action' );
	$feed_url = \LlamaHire\Job_Feed::url();
	parse_str( wp_parse_url( $feed_url, PHP_URL_QUERY ) ?: '', $feed_query_args );
	$feed_query = new WP_Query( \LlamaHire\Job_Feed::query_args( \LlamaHire\Job_Feed::state() ) );
	$assert( false !== strpos( $directory, 'Subscribe to these jobs (RSS)' ) && false !== strpos( $directory, '(opens in a new tab)' ) && false !== strpos( $directory, 'target="_blank"' ) && false !== strpos( $directory, esc_url( $feed_url ) ), 'Directory exposes an explicit new-tab RSS subscription link for the current result state' );
	$assert( 'LlamaHire Smoke' === ( $feed_query_args['job_search'] ?? '' ) && 'full_time' === ( $feed_query_args['employment_type'] ?? '' ) && 'hybrid' === ( $feed_query_args['workplace'] ?? '' ) && 'Vancouver' === ( $feed_query_args['location'] ?? '' ) && '1' === ( $feed_query_args['featured'] ?? '' ) && in_array( $job_id, wp_list_pluck( $feed_query->posts, 'ID' ), true ), 'Job feed URLs and queries preserve sanitized search, employment, workplace, location, and featured filters' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'listing_expires' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ) );
	$expired_feed_query = new WP_Query( \LlamaHire\Job_Feed::query_args( \LlamaHire\Job_Feed::state() ) );
	$assert( ! in_array( $job_id, wp_list_pluck( $expired_feed_query->posts, 'ID' ), true ), 'Job feeds exclude listings that are no longer open' );
	\LlamaHire\Jobs::set_meta( $job_id, array( 'listing_expires' => '' ) );
	$assert( false !== strpos( $directory, 'data-wp-router-region="llamahire-job-results"' ) && false !== strpos( $directory, 'llamahire-active-filter' ) && false !== strpos( $directory, 'data-wp-on--click="actions.remove"' ), 'Directory renders an interactive results region and removable active-filter chips' );
	$_GET['employment_type'] = 'full_time,part_time';
	$_GET['workplace'] = array( 'hybrid', 'remote' );
	$_GET['location'] = 'Vancouver, BC, CA|Toronto, ON, CA';
	$multi_state = \LlamaHire\Blocks::query_state();
	$multi_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"featuredOnly":false,"perPage":12} /-->' );
	$assert( array( 'full_time', 'part_time' ) === $multi_state['employment_type'] && array( 'hybrid', 'remote' ) === $multi_state['workplace'] && array( 'Vancouver, BC, CA', 'Toronto, ON, CA' ) === $multi_state['location'], 'Directory accepts multiple operator-managed job types, workplaces, and locations' );
	$assert( false !== strpos( $multi_directory, 'LlamaHire Smoke Test Role' ), 'Directory applies OR matching within multi-value filters' );
	$assert( false !== strpos( $multi_directory, 'name="employment_type[]"' ) && false !== strpos( $multi_directory, 'name="location[]"' ), 'Directory renders multi-value job type and location checkbox controls' );
	$assert( false !== strpos( $multi_directory, 'Location · 2' ), 'Directory summarizes the number of selected locations' );
	$job_type_options = \LlamaHire\Jobs::employment_types();
	$assert( false !== strpos( $multi_directory, $job_type_options['full_time'] ) && false !== strpos( $multi_directory, $job_type_options['part_time'] ), 'Directory renders operator-managed job type labels' );
	$_GET['employment_type'] = 'part_time';
	unset( $_GET['location'] );
	$empty_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":12} /-->' );
	$assert( false !== strpos( $empty_directory, 'No matching open roles' ) && false !== strpos( $empty_directory, 'Clear filters' ), 'Directory provides a recoverable filtered empty state' );
	unset( $_GET['employment_type'], $_GET['location'], $_GET['featured'], $_GET['workplace'] );
	$filter_job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'LlamaHire Smoke Pagination Role', 'post_content' => 'A second open role for pagination coverage.' ) );
	\LlamaHire\Jobs::set_meta( $filter_job_id, array_merge( \LlamaHire\Jobs::get_meta( $job_id ), array( 'featured' => '0' ) ) );
	$active_job_search = $_GET['job_search'] ?? '';
	unset( $_GET['job_search'] );
	$department_directory = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":true,"department":"engineering","perPage":12} /-->' );
	$_GET['job_search'] = $active_job_search;
	$assert( false !== strpos( $department_directory, 'LlamaHire Smoke Test Role' ) && false === strpos( $department_directory, 'LlamaHire Smoke Pagination Role' ) && false !== strpos( $department_directory, 'name="department" value="engineering"' ) && false !== strpos( $department_directory, 'department=engineering' ) && false === strpos( $department_directory, '<select name="department"' ) && false === strpos( $department_directory, 'Clear filters' ), 'A fixed department directory limits results and preserves its department in the RSS link without presenting a misleading clear action' );
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
	update_option( \LlamaHire\Settings::OPTION, array_merge( $sanitized_settings, $anti_spam_config ), false );
	$protected_form = do_blocks( '<!-- wp:llamahire/application-form {"jobId":' . (int) $job_id . '} /-->' );
	$assert( false !== strpos( $protected_form, 'cf-turnstile' ) && false !== strpos( $protected_form, 'data-action="job_application"' ), 'Configured Turnstile protection renders on public candidate applications' );
	$allow_anti_spam = static function () { return true; };
	add_filter( 'llamahire_anti_spam_pre_verify', $allow_anti_spam );
	$assert( true === \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION ), 'Extensions can provide a successful anti-spam verification result without exposing provider credentials' );
	remove_filter( 'llamahire_anti_spam_pre_verify', $allow_anti_spam );
	$deny_anti_spam = static function () { return false; };
	add_filter( 'llamahire_anti_spam_pre_verify', $deny_anti_spam );
	$assert( is_wp_error( \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION ) ), 'Failed anti-spam verification blocks a protected public form' );
	remove_filter( 'llamahire_anti_spam_pre_verify', $deny_anti_spam );
	$original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Disposable test controls the simulated provider request.
	$_POST['cf-turnstile-response'] = 'smoke-provider-token'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Disposable test controls the simulated provider request.
	$provider_body = array();
	$provider_response = static function () use ( &$provider_body ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $provider_body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $provider_response );
	$provider_body = array( 'success' => true, 'action' => \LlamaHire\Anti_Spam::CONTEXT_APPLICATION, 'hostname' => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$assert( true === \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION ), 'Anti-spam verification accepts an exact action and site hostname' );
	$provider_body = array( 'success' => true, 'hostname' => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$missing_action = \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION );
	$assert( is_wp_error( $missing_action ) && 'anti_spam_action' === $missing_action->get_error_code(), 'Turnstile verification fails closed when the expected action is missing' );
	$provider_body = array( 'success' => true, 'action' => \LlamaHire\Anti_Spam::CONTEXT_APPLICATION, 'hostname' => 'unrelated.example.test' );
	$wrong_hostname = \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION );
	$assert( is_wp_error( $wrong_hostname ) && 'anti_spam_hostname' === $wrong_hostname->get_error_code(), 'Anti-spam verification rejects a token issued for another hostname' );
	$mapped_hostname = static function ( $hostnames ) { $hostnames[] = 'mapped.example.test'; return $hostnames; };
	add_filter( 'llamahire_anti_spam_allowed_hostnames', $mapped_hostname );
	$provider_body = array( 'success' => true, 'action' => \LlamaHire\Anti_Spam::CONTEXT_APPLICATION, 'hostname' => 'MAPPED.EXAMPLE.TEST.' );
	$assert( true === \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION ), 'Mapped domains can extend exact anti-spam hostname validation' );
	remove_filter( 'llamahire_anti_spam_allowed_hostnames', $mapped_hostname );
	$_POST['cf-turnstile-response'] = str_repeat( 'x', 2049 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Disposable test controls the simulated provider request.
	$oversized_token = \LlamaHire\Anti_Spam::verify( \LlamaHire\Anti_Spam::CONTEXT_APPLICATION );
	$assert( is_wp_error( $oversized_token ) && 'anti_spam_missing' === $oversized_token->get_error_code(), 'Oversized anti-spam tokens are rejected before provider verification' );
	remove_filter( 'pre_http_request', $provider_response );
	$_POST = $original_post;
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
	$assert( false !== strpos( $duplicate_form, 'role="status"' ) && false !== strpos( $duplicate_form, 'Thanks! Your application has been received.' ) && false === strpos( $duplicate_form, 'already have your application' ) && false === strpos( $duplicate_form, '<form method="post"' ), 'Legacy duplicate result URLs reveal no more applicant state than the generic success response' );
	$client_limit = static function () { return 1; };
	$job_limit    = static function () { return 0; };
	add_filter( 'llamahire_submission_rate_limit', $client_limit );
	add_filter( 'llamahire_job_submission_rate_limit', $job_limit );
	$rate_identity = hash_hmac( 'sha256', 'smoke-test-client', wp_salt( 'nonce' ) );
	$rate_key      = 'llamahire_rate_' . md5( get_current_blog_id() . '|' . $job_id . '|client|' . $rate_identity );
	$rate_lock     = \LlamaHire\Rate_Limiter::LOCK_PREFIX . md5( $rate_key );
	add_option( $rate_lock, time() . ':concurrent-fixture', '', false );
	$assert( ! \LlamaHire\Applications::consume_submission_limit( $job_id, 'smoke-test-client' ), 'A concurrent submission holding the same counter lock fails closed instead of sharing an allowance' );
	delete_option( $rate_lock );
	delete_transient( $rate_key );
	$assert( \LlamaHire\Applications::consume_submission_limit( $job_id, 'smoke-test-client' ) && ! \LlamaHire\Applications::consume_submission_limit( $job_id, 'smoke-test-client' ), 'Repeated client submissions are rate limited' );
	remove_filter( 'llamahire_submission_rate_limit', $client_limit );
	remove_filter( 'llamahire_job_submission_rate_limit', $job_limit );
	$csv_method = new ReflectionMethod( \LlamaHire\Admin::class, 'safe_csv_value' );
	$csv_method->setAccessible( true );
	$assert( "' =SUM(A1:A2)" === $csv_method->invoke( null, ' =SUM(A1:A2)' ) && "'\n@SUM(A1:A2)" === $csv_method->invoke( null, "\n@SUM(A1:A2)" ), 'CSV export neutralizes formulas after leading whitespace' );
	$assert( array( 'ID', 'Job', 'Name', 'Email', 'Phone', 'Cover letter', 'Status', 'Received' ) === \LlamaHire\Admin::EXPORT_COLUMNS, 'The CSV export contract fixes the column set and their order' );
	$assert( "'\xEF\xBB\xBF=SUM(A1:A2)" === $csv_method->invoke( null, "\xEF\xBB\xBF=SUM(A1:A2)" ), 'CSV export neutralizes formulas after a leading byte-order mark' );
	$signature_method = new ReflectionMethod( get_class( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) ), 'validate_signature' );
	$signature_method->setAccessible( true );
	$invalid_resume = wp_tempnam( 'llamahire-invalid-resume.pdf' );
	file_put_contents( $invalid_resume, 'not a pdf' );
	$assert( is_wp_error( $signature_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ), $invalid_resume, 'pdf' ) ), 'Resume content must match the allowed file signature' );
	wp_delete_file( $invalid_resume );
	$allowed_mimes_method = new ReflectionMethod( get_class( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) ), 'allowed_mimes' );
	$allowed_mimes_method->setAccessible( true );
	$assert( ! isset( $allowed_mimes_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) )['doc'] ), 'Legacy DOC uploads are disabled unless a trusted scanner explicitly opts in' );
	$delivery_mimes_method = new ReflectionMethod( get_class( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) ), 'delivery_mimes' );
	$delivery_mimes_method->setAccessible( true );
	$assert( 'application/msword' === $delivery_mimes_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) )['doc'], 'Existing legacy DOC resumes retain their MIME type and filename extension when downloaded' );
	$allow_legacy_doc = static function () { return true; };
	add_filter( 'llamahire_allow_legacy_doc_uploads', $allow_legacy_doc );
	$assert( isset( $allowed_mimes_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ) )['doc'] ), 'Trusted security integrations can explicitly enable legacy DOC uploads' );
	remove_filter( 'llamahire_allow_legacy_doc_uploads', $allow_legacy_doc );
	$invalid_docx = wp_tempnam( 'llamahire-invalid-resume.docx' );
	file_put_contents( $invalid_docx, "PK\x03\x04invalid office container" );
	$assert( is_wp_error( $signature_method->invoke( $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE ), $invalid_docx, 'docx' ) ), 'DOCX uploads fail closed unless the full Office container can be inspected' );
	wp_delete_file( $invalid_docx );
	$local_storage = $services->get( \LlamaHire\Service_IDs::RESUME_STORAGE );
	$local_directory_method = new ReflectionMethod( get_class( $local_storage ), 'directory' );
	$local_directory_method->setAccessible( true );
	$local_directory = $local_directory_method->invoke( $local_storage, true );
	$uninstall_local_path = trailingslashit( $local_directory ) . wp_generate_uuid4() . '.pdf';
	file_put_contents( $uninstall_local_path, '%PDF-1.4 uninstall cleanup fixture' );
	$assert( \LlamaHire\Uninstaller::delete_resume_token( $uninstall_local_path, $local_storage, $vip_storage ) && ! file_exists( $uninstall_local_path ), 'Full-uninstall cleanup deletes managed local resume tokens before database references are removed' );
	$uninstall_local_path = '';
	$vip_directory_method = new ReflectionMethod( \LlamaHire\Services\VIP_ACL_Resume_Storage::class, 'directory' );
	$vip_directory_method->setAccessible( true );
	$vip_directory = $vip_directory_method->invoke( $vip_storage, true );
	$vip_fixture_path = trailingslashit( $vip_directory ) . wp_generate_uuid4() . '.pdf';
	file_put_contents( $vip_fixture_path, '%PDF-1.4 VIP uninstall cleanup fixture' );
	$uninstall_attachment_id = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'Private uninstall fixture', 'post_status' => 'inherit' ), $vip_fixture_path );
	update_attached_file( $uninstall_attachment_id, $vip_fixture_path );
	update_post_meta( $uninstall_attachment_id, \LlamaHire\Services\VIP_ACL_Resume_Storage::MARKER_META, '1' );
	$assert( \LlamaHire\Uninstaller::delete_resume_token( \LlamaHire\Services\VIP_ACL_Resume_Storage::TOKEN_PREFIX . $uninstall_attachment_id, $local_storage, $vip_storage ) && ! get_post( $uninstall_attachment_id ) && ! file_exists( $vip_fixture_path ), 'Full-uninstall cleanup deletes marked VIP resume attachments before database references are removed' );
	$uninstall_attachment_id = 0;
	$custom_deleted_token = '';
	$custom_cleanup       = static function ( $deleted, $token, $site_id ) use ( &$custom_deleted_token ) {
		if ( 'custom:smoke-token' !== $token ) {
			return $deleted;
		}
		$custom_deleted_token = $token;

		return get_current_blog_id() === $site_id;
	};
	add_filter( 'llamahire_uninstall_delete_resume_token', $custom_cleanup, 10, 3 );
	$assert( \LlamaHire\Uninstaller::delete_resume_token( 'custom:smoke-token', $local_storage, $vip_storage ) && 'custom:smoke-token' === $custom_deleted_token, 'Custom resume drivers can delete opaque tokens during full uninstall' );
	remove_filter( 'llamahire_uninstall_delete_resume_token', $custom_cleanup, 10 );
	$assert( ! \LlamaHire\Uninstaller::delete_resume_token( 'custom:unhandled', $local_storage, $vip_storage ), 'Full uninstall retains database references when no driver handles an opaque resume token' );

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
			'cover_letter' => 'Smoke test cover letter.',
			'status' => 'new',
		)
	);
	$assert( ! is_wp_error( $application_id ) && $application_id > 0, 'Application repository stores an application' );
	$application = $repository->find( $application_id );
	$assert( $application && 'new' === $application->status, 'Application repository retrieves the stored application' );
	$assert( true === $repository->update( $application_id, array( 'status' => 'reviewing', 'notes' => 'Smoke test note' ) ), 'Application repository updates allowed fields' );
	$application = $repository->find( $application_id );
	$private_notes = \LlamaHire\Application_Notes::for_application( $application_id );
	$assert( 'reviewing' === $application->status && 1 === count( $private_notes ) && 'Smoke test note' === $private_notes[0]->body, 'Application repository appends status and private-note updates without overwriting history' );
	$assert( true === $repository->update( $application_id, array( 'status' => 'interviewing' ) ), 'Application repository accepts the expanded hiring pipeline' );
	$application = $repository->find( $application_id );
	$assert( 'interviewing' === $application->status && ! empty( $application->stage_changed_at ), 'Application repository tracks the current stage and when it changed' );
	$application_history = \LlamaHire\Audit_Log::search( array( 'application_id' => $application_id ) );
	$assert( 3 === $application_history['total'] && 'application_status_changed' === $application_history['items'][0]->event_type && 'reviewing' === $application_history['items'][0]->from_state && 'interviewing' === $application_history['items'][0]->to_state, 'Application status changes and private-note additions create content-free audit events' );
	$activity_date = substr( $application_history['items'][0]->created_at, 0, 10 );
	$activity_results = \LlamaHire\Audit_Log::search(
		array(
			'event_types'    => array( 'application_status_changed' ),
			'job_ids'        => array( $job_id ),
			'search'         => get_the_title( $job_id ),
			'occurred_after' => $activity_date . ' 00:00:00',
			'occurred_before'=> $activity_date . ' 23:59:59',
			'orderby'        => 'event',
			'order'          => 'asc',
		)
	);
	$assert( 2 === $activity_results['total'] && ! property_exists( $activity_results['items'][0], 'name' ) && ! property_exists( $activity_results['items'][0], 'email' ), 'Activity DataViews queries filter and sort privacy-safe audit records without candidate data' );
	$assert( ! property_exists( $application, 'resume_path' ), 'Public application records do not expose private storage paths' );
	$assert( \LlamaHire\Applications::resume_is_previewable( 'candidate.pdf' ) && ! \LlamaHire\Applications::resume_is_previewable( 'candidate.docx' ) && false === strpos( \LlamaHire\Applications::resume_url( $application_id ), '&amp;' ) && false !== strpos( \LlamaHire\Applications::resume_url( $application_id, true ), 'preview=1' ), 'Private resume actions expose raw nonce URLs and limit browser preview to PDF files' );
	$query = $services->get( \LlamaHire\Service_IDs::APPLICATION_QUERY );
	$results = $query->search( array( 'job_id' => $job_id, 'status' => 'interviewing', 'per_page' => 1 ) );
	$assert( 1 === $results['total'] && 1 === count( $results['items'] ), 'Application query filters and paginates results' );
	$stage_before_future = $query->search( array( 'job_id' => $job_id, 'status' => 'interviewing', 'stage_changed_before' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'per_page' => 1 ) );
	$stage_before_past = $query->search( array( 'job_id' => $job_id, 'status' => 'interviewing', 'stage_changed_before' => '2000-01-01 00:00:00', 'per_page' => 1 ) );
	$assert( 1 === $stage_before_future['total'] && 0 === $stage_before_past['total'], 'Application query filters candidates by time in their current stage' );
	$assert( array() === array_intersect( array( 'phone', 'cover_letter', 'resume_name', 'has_resume', 'notes', 'notification_attempts', 'employer_notified_at', 'candidate_notified_at', 'notification_error_code' ), array_keys( get_object_vars( $results['items'][0] ) ) ), 'Application list projections omit private detail and large candidate fields' );
	$bounded_application_args = \LlamaHire\REST_API::application_query_arguments( array( 'job_ids' => array_merge( range( 1, 150 ), array( 1 ) ), 'statuses' => array( 'new', 'new', 'invalid' ), 'notification_statuses' => array( 'failed', 'failed', 'invalid' ) ) );
	$bounded_activity_args = \LlamaHire\REST_API::activity_query_arguments( array( 'job_ids' => array_merge( range( 1, 150 ), array( 1 ) ), 'actor_ids' => array_merge( range( 1, 150 ), array( 1 ) ), 'event_types' => array( 'job_submitted', 'job_submitted', 'invalid' ) ) );
	$assert( 100 === count( $bounded_application_args['job_ids'] ) && array( 'new' ) === $bounded_application_args['statuses'] && array( 'failed' ) === $bounded_application_args['notification_statuses'] && 100 === count( $bounded_activity_args['job_ids'] ) && 100 === count( $bounded_activity_args['actor_ids'] ) && array( 'job_submitted' ) === $bounded_activity_args['event_types'], 'REST list filters are deduplicated, allow-listed, and bounded before query construction' );
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
	$detail_request = new WP_REST_Request( 'GET', '/llamahire/v1/applications/' . $application_id );
	$detail_response = rest_do_request( $detail_request );
	$detail_data = $detail_response->get_data();
	$assert( 200 === $detail_response->get_status() && 'Smoke test cover letter.' === $detail_data['cover_letter'] && 'Smoke test note' === $detail_data['private_notes'][0]['body'] && 3 === count( $detail_data['activity'] ) && ! isset( $detail_data['candidate'], $detail_data['email'] ) && null === $detail_data['resume'], 'Authorized inline review details expose only the selected application materials, append-only notes, review fields, and bounded activity' );
	$detail_update_request = new WP_REST_Request( 'POST', '/llamahire/v1/applications/' . $application_id );
	$detail_update_request->set_param( 'status', 'reviewing' );
	$detail_update_response = rest_do_request( $detail_update_request );
	$detail_update_data = $detail_update_response->get_data();
	$assert( 200 === $detail_update_response->get_status() && 'reviewing' === $detail_update_data['status'] && 4 === count( $detail_update_data['activity'] ), 'Authorized inline review saves status independently and refreshes bounded activity history' );
	$note_request = new WP_REST_Request( 'POST', '/llamahire/v1/applications/' . $application_id . '/notes' );
	$note_request->set_param( 'note', 'Updated inline review note' );
	$note_response = rest_do_request( $note_request );
	$note_data = $note_response->get_data();
	$assert( 200 === $note_response->get_status() && 2 === count( $note_data['private_notes'] ) && 'Updated inline review note' === $note_data['private_notes'][0]['body'] && 'Smoke test note' === $note_data['private_notes'][1]['body'] && 5 === count( $note_data['activity'] ), 'Authorized inline review appends a private note, preserves earlier notes, and returns bounded activity for the history modal' );
	wp_set_current_user( 0 );
	$denied_detail_response = rest_do_request( $detail_request );
	$denied_note_response = rest_do_request( $note_request );
	$assert( 404 === $denied_detail_response->get_status() && 404 === $denied_note_response->get_status(), 'Inline application review details and note creation do not reveal inaccessible records' );
	wp_set_current_user( (int) reset( $filter_admin_ids ) );
	$activity_request = new WP_REST_Request( 'GET', '/llamahire/v1/activity' );
	$activity_request->set_param( 'event_types', array( 'application_status_changed' ) );
	$activity_request->set_param( 'job_ids', array( $job_id ) );
	$activity_request->set_param( 'per_page', 1 );
	$activity_response = rest_do_request( $activity_request );
	$activity_data = $activity_response->get_data();
	$assert( 200 === $activity_response->get_status() && 1 === count( $activity_data['items'] ) && ! isset( $activity_data['items'][0]['candidate'], $activity_data['items'][0]['email'] ), 'Authorized Activity REST responses expose linked operational context without candidate identity' );
	ob_start();
	\LlamaHire\Admin::activity_page();
	$activity_page_html = ob_get_clean();
	$assert( false !== strpos( $activity_page_html, 'llamahire-activity-root' ) && wp_script_is( 'llamahire-admin-activity', 'enqueued' ) && wp_style_is( 'llamahire-admin-activity', 'enqueued' ), 'Activity mounts its compiled read-only DataViews interface and WordPress component styles' );
	ob_start();
	\LlamaHire\Admin::job_column( 'llamahire_status', $job_id );
	$job_hiring_column_html = ob_get_clean();
	wp_set_current_user( $original_user_id );
	$assert( false !== strpos( $job_hiring_column_html, '1 application' ) && false !== strpos( $job_hiring_column_html, 'job_id=' . $job_id ), 'Job list links application counts directly to the filtered recruiter inbox' );
	wp_set_current_user( (int) reset( $filter_admin_ids ) );
	$bulk_application_id = $repository->create( array( 'job_id' => $job_id, 'name' => 'Bulk Status Candidate', 'email' => 'bulk-status@example.test', 'status' => 'new' ) );
	$assert( ! is_wp_error( $bulk_application_id ), 'A second application is available for bulk workflow checks' );
	$bulk_request = new WP_REST_Request( 'POST', '/llamahire/v1/applications/bulk-status' );
	$bulk_request->set_param( 'application_ids', array( $application_id, $bulk_application_id ) );
	$bulk_request->set_param( 'status', 'offer' );
	$bulk_response = rest_do_request( $bulk_request );
	$bulk_data = $bulk_response->get_data();
	$assert( 200 === $bulk_response->get_status() && 2 === $bulk_data['updated'] && 'offer' === $repository->find( $application_id )->status && 'offer' === $repository->find( $bulk_application_id )->status, 'Authorized bulk status changes update every selected application through the repository contract' );
	wp_set_current_user( 0 );
	$denied_bulk_response = rest_do_request( $bulk_request );
	$assert( 401 === $denied_bulk_response->get_status(), 'Bulk status changes require the application-management capability' );
	$repository->update( $application_id, array( 'status' => 'interviewing' ) );
	$repository->update( $bulk_application_id, array( 'status' => 'new' ) );
	wp_set_current_user( $original_user_id );
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
	$assert( 1 === \LlamaHire\Employer_Portal::active_listing_count( $employer_user_ids[0] ) && 0 === \LlamaHire\Employer_Portal::active_listing_count( $employer_user_ids[0], $employer_job_ids[0] ), 'Pending and published employer listings consume the active allowance while the current listing can be excluded during resubmission' );
	wp_set_current_user( $employer_user_ids[0] );
	$scoped_results = $query->search( array_merge( array( 'per_page' => 20 ), \LlamaHire\Ownership::query_arguments() ) );
	$scoped_counts = $query->counts( \LlamaHire\Ownership::query_arguments() );
	$scoped_recent = $query->recent( 5, \LlamaHire\Ownership::query_arguments() );
	$scoped_export = iterator_to_array( $query->export_rows( \LlamaHire\Ownership::query_arguments() ) );
	$assert( 1 === $scoped_results['total'] && 1 === array_sum( array_intersect_key( $scoped_counts, array_flip( array_keys( \LlamaHire\Applications::workflow_statuses() ) ) ) ) && 1 === count( $scoped_recent ) && 1 === count( $scoped_export ), 'Employer application lists, counts, recent rows, and exports are scoped to authored jobs' );
	$assert( \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[0], \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[1], \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'Employer ownership checks allow own candidates and deny another company candidate' );
	$extension_access = $services->get( \LlamaHire\Service_IDs::EXTENSION_ACCESS );
	$assert( $extension_access->can_access_application( $employer_application_ids[0], \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! $extension_access->can_access_application( $employer_application_ids[1], \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'Public extension authorization preserves candidate ownership' );
	$assert( array( 'author_id' => $employer_user_ids[0] ) === $extension_access->application_scope( \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'Public extension query scope preserves author ownership' );
	$assert( null === $extension_access->job_context( $employer_job_ids[1] ) && $employer_user_ids[0] === $extension_access->job_context( $employer_job_ids[0] )['owner_id'], 'Public extension job context is restricted to authorized owners' );
	$assert( ! $extension_access->can_access_application( $employer_application_ids[0], 'manage_options' ), 'Public extension access rejects unrelated capabilities' );
	$scoped_erasure_user = get_userdata( $employer_user_ids[0] );
	$scoped_erasure_user->add_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS );
	$assert( \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[0], \LlamaHire\Capabilities::ERASE_APPLICATIONS ) && ! \LlamaHire\Ownership::user_can_access_application( $employer_application_ids[1], \LlamaHire\Capabilities::ERASE_APPLICATIONS ), 'Candidate-data erasure capability remains scoped to applications owned by the employer' );
	$scoped_erasure_user->remove_cap( \LlamaHire\Capabilities::ERASE_APPLICATIONS );
	$ownership_bulk_request = new WP_REST_Request( 'POST', '/llamahire/v1/applications/bulk-status' );
	$ownership_bulk_request->set_param( 'application_ids', $employer_application_ids );
	$ownership_bulk_request->set_param( 'status', 'hired' );
	$ownership_bulk_response = rest_do_request( $ownership_bulk_request );
	$assert( 403 === $ownership_bulk_response->get_status() && 'new' === $repository->find( $employer_application_ids[0] )->status, 'Bulk status changes reject a mixed-ownership selection before changing an employer-owned application' );
	$scoped_audit = \LlamaHire\Audit_Log::search( \LlamaHire\Ownership::query_arguments() );
	$assert( $scoped_audit['total'] >= 1 && ! array_filter( $scoped_audit['items'], static function ( $event ) use ( $employer_job_ids ) { return (int) $event->job_id === (int) $employer_job_ids[1]; } ), 'Employer audit history includes owned jobs and excludes another company’s events' );
	$board_settings = array_merge(
		$sanitized_settings,
		$anti_spam_config,
		array(
			'site_mode'               => \LlamaHire\Settings::SITE_MODE_JOB_BOARD,
			'employer_account_page_id' => $privacy_page_id,
			'employer_policy_text'    => 'I agree to follow the {listing_policy}.',
			'employer_policy_page_id' => $privacy_page_id,
		)
	);
	update_option( \LlamaHire\Settings::OPTION, $board_settings, false );
	wp_set_current_user( 0 );
	$registration_form = do_shortcode( '[llamahire_employer_registration]' );
	$assert( false !== strpos( $registration_form, 'name="company_name"' ) && false !== strpos( $registration_form, 'name="password"' ) && false !== strpos( $registration_form, 'name="accept_policy"' ) && false !== strpos( $registration_form, 'minlength="12"' ), 'Employer registration collects company and account details with explicit policy consent and a strong-password baseline' );
	$assert( false !== strpos( $registration_form, 'cf-turnstile' ) && false !== strpos( $registration_form, 'data-action="employer_registration"' ), 'Configured Turnstile protection renders at the public employer-account perimeter' );
	$assert( false !== strpos( $registration_form, 'href="' . esc_url( get_permalink( $privacy_page_id ) ) . '"' ) && false !== strpos( $registration_form, '>listing rules</a>' ) && false === strpos( $registration_form, '{listing_policy}' ), 'Employer registration links the agreement text to the selected listing-policy page' );
	$registration_user_id = wp_insert_user( array( 'user_login' => 'llamahire-registration-smoke', 'user_email' => 'registration@example.test', 'user_pass' => wp_generate_password( 24 ), 'display_name' => 'Registration Smoke' ) );
	$assert( ! is_wp_error( $registration_user_id ), 'Employer registration test account can be created' );
	$registration_user = get_userdata( $registration_user_id );
	$registration_user->set_role( '' );
	update_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_EMAIL );
	update_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::COMPANY_META, 'Registration Company' );
	$registration_mail = array();
	$capture_registration_mail = static function ( $return, $attributes ) use ( &$registration_mail ) {
		$registration_mail[] = $attributes;
		return true;
	};
	add_filter( 'pre_wp_mail', $capture_registration_mail, 10, 2 );
	$send_verification = new ReflectionMethod( \LlamaHire\Employer_Registration::class, 'send_verification' );
	$send_verification->setAccessible( true );
	$send_verification->invoke( null, $registration_user );
	remove_filter( 'pre_wp_mail', $capture_registration_mail );
	preg_match( '/[?&]token=([^&\s]+)/', $registration_mail[0]['message'], $verification_match );
	$stored_verification_hash = get_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::TOKEN_META, true );
	$assert( 1 === count( $registration_mail ) && $registration_user->user_email === $registration_mail[0]['to'] && ! empty( $verification_match[1] ) && $verification_match[1] !== $stored_verification_hash && absint( get_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::TOKEN_EXPIRY_META, true ) ) > time(), 'Employer verification sends a time-limited link while storing only a one-way token hash' );
	$registration_mail = array();
	add_filter( 'pre_wp_mail', $capture_registration_mail, 10, 2 );
	$verification_result = \LlamaHire\Employer_Registration::verify_token( $registration_user_id, rawurldecode( $verification_match[1] ) );
	remove_filter( 'pre_wp_mail', $capture_registration_mail );
	$assert( 'awaiting_approval' === $verification_result && \LlamaHire\Employer_Registration::STATUS_APPROVAL === get_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, true ) && 1 === count( $registration_mail ) && 'hiring@example.test' === $registration_mail[0]['to'], 'A valid one-use email token advances manual registrations to operator approval and notifies the board inbox' );
	wp_set_current_user( $board_manager_id );
	$pending_employer_column = \LlamaHire\Employer_Registration::user_column( '', 'llamahire_employer_status', $registration_user_id );
	$assert( 1 <= \LlamaHire\Employer_Registration::pending_count() && false !== strpos( \LlamaHire\Employer_Registration::pending_url(), 'llamahire_employer_status=pending_approval' ) && false !== strpos( $pending_employer_column, 'Approve employer' ), 'Operators get a filtered employer-approval queue with a visible approval action' );
	wp_set_current_user( 0 );
	$assert( 'invalid_link' === \LlamaHire\Employer_Registration::verify_token( $registration_user_id, rawurldecode( $verification_match[1] ) ), 'An employer verification token cannot be reused' );
	$registration_mail = array();
	add_filter( 'pre_wp_mail', $capture_registration_mail, 10, 2 );
	$assert( \LlamaHire\Employer_Registration::approve( $registration_user_id ), 'A verified employer can be approved' );
	remove_filter( 'pre_wp_mail', $capture_registration_mail );
	$registration_user = get_userdata( $registration_user_id );
	$assert( in_array( \LlamaHire\Capabilities::EMPLOYER_ROLE, $registration_user->roles, true ) && \LlamaHire\Employer_Registration::STATUS_APPROVED === get_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, true ), 'Approval grants only the existing restricted Employer role and records the approved state' );
	$assert( 2 === count( $registration_mail ) && $registration_user->user_email === $registration_mail[0]['to'] && 'hiring@example.test' === $registration_mail[1]['to'], 'Employer approval notifies both the employer and board operator' );
	update_option( \LlamaHire\Settings::OPTION, array_merge( $board_settings, array( 'employer_approval' => 'automatic' ) ), false );
	$registration_user->set_role( '' );
	update_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_EMAIL );
	$registration_mail = array();
	add_filter( 'pre_wp_mail', $capture_registration_mail, 10, 2 );
	$send_verification->invoke( null, $registration_user );
	preg_match( '/[?&]token=([^&\s]+)/', $registration_mail[0]['message'], $automatic_verification_match );
	$automatic_verification_result = \LlamaHire\Employer_Registration::verify_token( $registration_user_id, rawurldecode( $automatic_verification_match[1] ?? '' ) );
	remove_filter( 'pre_wp_mail', $capture_registration_mail );
	$registration_user = get_userdata( $registration_user_id );
	$assert( 'approved' === $automatic_verification_result && in_array( \LlamaHire\Capabilities::EMPLOYER_ROLE, $registration_user->roles, true ) && 3 === count( $registration_mail ), 'Automatic approval grants the restricted Employer role immediately after valid email verification and sends employer and operator notices' );
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
	update_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_APPROVAL );
	ob_start();
	\LlamaHire\Admin_Workspaces::render_dashboard();
	$board_dashboard = ob_get_clean();
	$assert( false !== strpos( $board_dashboard, 'llamahire_job_state=open' ) && false !== strpos( $board_dashboard, 'post_status=pending' ) && false !== strpos( $board_dashboard, 'users.php?role=llamahire_employer' ) && false !== strpos( $board_dashboard, 'page=llamahire-applications' ), 'Board summary metrics link to live listings, awaiting review, employers, and applications' );
	$assert( false !== strpos( $board_dashboard, 'employer account is awaiting approval' ) && false !== strpos( $board_dashboard, 'llamahire_employer_status=pending_approval' ) && false !== strpos( $board_dashboard, 'job listing is waiting for review' ), 'The board dashboard separates employer-account approvals from submitted job moderation' );
	update_user_meta( $registration_user_id, \LlamaHire\Employer_Registration::STATUS_META, \LlamaHire\Employer_Registration::STATUS_APPROVED );
	$assert( false !== strpos( $board_dashboard, 'llamahire_job_state=closing-soon' ), 'The dashboard links its deadline action to the matching closing-soon job view' );
	$assert( false !== strpos( $board_dashboard, 'job_id=' . $employer_job_ids[0] ) && false !== strpos( $board_dashboard, '>1</strong>' ) && false !== strpos( $board_dashboard, '>application</span>' ), 'Active listings show one linked all-status application total per job' );
	$assert( false !== strpos( $board_dashboard, 'View all listings' ) && false !== strpos( $board_dashboard, 'View all activity' ) && false !== strpos( $board_dashboard, 'llamahire-activity-link' ), 'Dashboard collection links name their destinations and recent activity links to its affected record' );
	$assert( false !== strpos( $board_dashboard, 'notification_statuses=pending,partial,failed' ), 'The email-attention card links to the inbox pre-filtered to pending, partial, and failed notifications' );
	$job_state_count = new ReflectionMethod( \LlamaHire\Admin::class, 'job_state_count' );
	$job_state_count->setAccessible( true );
	$expiring_job_count = new ReflectionMethod( \LlamaHire\Admin_Workspaces::class, 'expiring_job_count' );
	$expiring_job_count->setAccessible( true );
	foreach ( array( 0, $employer_user_ids[0] ) as $parity_scope ) {
		$assert(
			\LlamaHire\Jobs::open_count( $parity_scope ) === $job_state_count->invoke( null, 'open', $parity_scope )
			&& $expiring_job_count->invoke( null, $parity_scope ) === $job_state_count->invoke( null, 'closing-soon', $parity_scope ),
			'Dashboard open-job and closing-soon counts match their filtered job-list views for board-wide and author scopes'
		);
	}
	$_GET['post_status'] = 'pending';
	$job_views = \LlamaHire\Admin::job_views( array() );
	unset( $_GET['post_status'] );
	$assert( false !== strpos( $job_views['llamahire_pending'], 'Awaiting review' ) && false !== strpos( $job_views['llamahire_pending'], 'class="current" aria-current="page"' ), 'The job list always identifies the awaiting-review filter, including its active state' );
	$assert( isset( $job_views['llamahire_open'], $job_views['llamahire_closing-soon'], $job_views['llamahire_closed'], $job_views['llamahire_expired'] ) && false !== strpos( $job_views['llamahire_closing-soon'], 'Closing soon' ) && false !== strpos( $job_views['llamahire_closed'], 'Closed' ) && false !== strpos( $job_views['llamahire_expired'], 'Expired' ), 'The job list exposes distinct live, closing-soon, manually closed, and expired listing views' );
	ob_start();
	\LlamaHire\Admin::job_column( 'llamahire_status', $employer_job_ids[1] );
	$pending_job_column = ob_get_clean();
	$assert( false !== strpos( $pending_job_column, 'Approve and publish' ) && false !== strpos( $pending_job_column, 'Request changes' ) && false !== strpos( $pending_job_column, 'Decline' ), 'Pending job rows expose explicit moderation outcomes instead of relying on the generic editor publish control' );
	wp_set_current_user( $employer_user_ids[0] );
	$published_title = get_the_title( $employer_job_ids[0] );
	$published_rest_request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $employer_job_ids[0] );
	$published_rest_request->set_param( 'title', 'Employer REST moderation bypass' );
	$published_rest_response = rest_do_request( $published_rest_request );
	$assert( 403 === $published_rest_response->get_status() && $published_title === get_the_title( $employer_job_ids[0] ) && ! current_user_can( 'edit_post', $employer_job_ids[0] ) && \LlamaHire\Ownership::user_can_manage_job( $employer_job_ids[0] ), 'Employers retain ownership-scoped portal access while core REST rejects direct edits to published jobs' );
	update_user_meta( $employer_user_ids[0], \LlamaHire\Employer_Registration::COMPANY_META, 'Account Company Override' );
	$_GET['job_id'] = $employer_job_ids[0];
	$employer_form = do_shortcode( '[llamahire_submit_job]' );
	unset( $_GET['job_id'] );
	$employer_jobs = do_shortcode( '[llamahire_my_jobs]' );
	$employer_account = do_shortcode( '[llamahire_employer_account]' );
	$assert( false !== strpos( $employer_form, 'Employer one job' ) && false !== strpos( $employer_form, 'Submit for review' ) && false !== strpos( $employer_jobs, 'Employer one job' ) && false === strpos( $employer_jobs, 'Employer two job' ), 'Employer portal supports editing and lists only the signed-in author’s jobs' );
	$assert( false !== strpos( $employer_jobs, '>Account</a>' ) && false !== strpos( $employer_account, 'name="contact_name"' ) && false !== strpos( $employer_account, 'Account Company Override' ) && false !== strpos( $employer_account, '>Change password</a>' ) && false !== strpos( $employer_account, '>Sign out</a>' ), 'Employers can reach a frontend account form, password recovery, and sign-out controls from My Jobs' );
	$assert( false !== strpos( $employer_jobs, '1 application' ) && false !== strpos( $employer_jobs, 'employer_view=applications' ), 'Internal-application jobs link to the ownership-scoped frontend candidate workspace' );
	$_GET['employer_view'] = 'applications';
	$employer_applications = do_shortcode( '[llamahire_my_jobs]' );
	unset( $_GET['employer_view'] );
	$assert( false !== strpos( $employer_applications, 'Employer one candidate' ) && false === strpos( $employer_applications, 'Employer two candidate' ) && false !== strpos( $employer_applications, 'Back to My Jobs' ), 'The frontend Applications workspace lists only candidates belonging to the signed-in employer' );
	$_GET['employer_view']  = 'applications';
	$_GET['application_id'] = $employer_application_ids[1];
	$other_employer_application = do_shortcode( '[llamahire_my_jobs]' );
	unset( $_GET['employer_view'], $_GET['application_id'] );
	$assert( false === strpos( $other_employer_application, 'id="llamahire-employer-application-title"' ) && false === strpos( $other_employer_application, 'Employer two candidate' ), 'Changing the frontend application ID cannot reveal another employer’s candidate detail' );
	$employer_user = get_userdata( $employer_user_ids[0] );
	$assert( \LlamaHire\Employer_Portal::is_frontend_employer( $employer_user ) && ! \LlamaHire\Employer_Portal::show_admin_bar( true ) && \LlamaHire\Employer_Portal::my_jobs_url() === \LlamaHire\Employer_Portal::login_redirect( admin_url(), admin_url(), $employer_user ), 'Employer accounts are identified as frontend-only, hide the admin bar, and sign in to My Jobs' );
	$_GET['my_jobs_search'] = 'Employer one';
	$_GET['my_jobs_status'] = 'published';
	$filtered_employer_jobs = do_shortcode( '[llamahire_my_jobs]' );
	unset( $_GET['my_jobs_search'], $_GET['my_jobs_status'] );
	$assert( false !== strpos( $filtered_employer_jobs, 'name="my_jobs_search" value="Employer one"' ), 'My Jobs preserves the owned-listing search query' );
	$assert( false !== strpos( $filtered_employer_jobs, 'value="published"' ) && false !== strpos( $filtered_employer_jobs, "selected='selected'>Published</option>" ), 'My Jobs preserves the selected lifecycle filter' );
	$assert( false !== strpos( $filtered_employer_jobs, 'Showing 1–1 of 1 jobs' ), 'My Jobs reports the matching owned-listing range' );
	$my_jobs_query_args = new ReflectionMethod( \LlamaHire\Employer_Portal::class, 'my_jobs_query_args' );
	$my_jobs_query_args->setAccessible( true );
	$dashboard_query = $my_jobs_query_args->invoke( null, array( 'search' => '', 'status' => 'all', 'page' => 3 ) );
	$assert( 25 === $dashboard_query['posts_per_page'] && 3 === $dashboard_query['paged'] && $employer_user_ids[0] === $dashboard_query['author'], 'My Jobs uses bounded server-side pagination scoped to the signed-in employer' );
	$assert( false !== strpos( $employer_form, 'name="job_excerpt"' ) && false !== strpos( $employer_form, 'name="address_locality"' ) && false !== strpos( $employer_form, 'name="applicant_countries"' ) && false !== strpos( $employer_form, 'name="salary_min"' ) && false !== strpos( $employer_form, 'name="deadline"' ), 'Employer submissions expose the complete public job and schema field model' );
	$assert( false !== strpos( $employer_form, 'Location type' ) && false !== strpos( $employer_form, 'data-llamahire-physical-location' ) && false !== strpos( $employer_form, 'data-llamahire-remote-location' ) && false === strpos( $employer_form, 'name="location"' ), 'Employer location entry adapts to physical or remote work without exposing the legacy duplicate display-location field' );
	$assert( 'Legacy Place' === \LlamaHire\Jobs::query_location( array( 'workplace' => 'onsite', 'location' => 'Legacy Place' ) ) && 'Hamilton Ontario CA' === \LlamaHire\Jobs::query_location( array( 'workplace' => 'hybrid', 'location' => 'Old label', 'address_locality' => 'Hamilton', 'address_region' => 'Ontario', 'address_country' => 'CA' ) ) && 'CA, US' === \LlamaHire\Jobs::query_location( array( 'workplace' => 'remote', 'address_locality' => 'Old office', 'applicant_countries' => 'CA, US' ) ), 'Location search uses structured fields for current listings while retaining a legacy fallback' );
	$assert( false !== strpos( $employer_form, 'value="draft" formnovalidate' ) && false !== strpos( $employer_form, 'value="preview"' ) && false !== strpos( $employer_form, 'value="submit"' ), 'Employer submissions provide distinct draft, preview, and submit-for-review intents' );
	$new_job_form = do_shortcode( '[llamahire_submit_job]' );
	$assert( false !== strpos( $new_job_form, 'name="organization_name" required value="Account Company Override"' ) && false === strpos( $new_job_form, 'name="organization_name" required value="Employer one Company"' ) && false !== strpos( $employer_jobs, '>Published</strong>' ) && false !== strpos( $employer_jobs, '>Preview</a>' ) && false !== strpos( $employer_jobs, '>Delete</summary>' ), 'New submissions use the Account company ahead of an older listing while retaining My Jobs status, preview, and confirmed deletion controls' );
	$lifecycle_expiry = current_datetime()->modify( '+3 days' )->format( 'Y-m-d' );
	\LlamaHire\Jobs::set_meta( $employer_job_ids[0], array( 'deadline' => current_datetime()->modify( '+30 days' )->format( 'Y-m-d' ), 'listing_expires' => $lifecycle_expiry, 'featured' => '1', 'job_identifier' => 'EMPLOYER-ONE' ) );
	$assert( \LlamaHire\Jobs::listing_expires_soon( $employer_job_ids[0] ), 'A published listing whose expiration is the next closing event is renewable during the seven-day window' );
	$expiring_mail = array();
	$capture_expiring_mail = static function ( $return, $attributes ) use ( &$expiring_mail ) {
		$expiring_mail[] = $attributes;
		return true;
	};
	add_filter( 'pre_wp_mail', $capture_expiring_mail, 10, 2 );
	$first_expiring_run = \LlamaHire\Employer_Notifications::send_expiring_notices();
	$second_expiring_run = \LlamaHire\Employer_Notifications::send_expiring_notices();
	remove_filter( 'pre_wp_mail', $capture_expiring_mail );
	$employer_jobs = do_shortcode( '[llamahire_my_jobs]' );
	$assert( 1 <= $first_expiring_run && 0 === $second_expiring_run && 1 === count( array_filter( $expiring_mail, static function ( $mail ) use ( $employer_one ) { return $employer_one->user_email === $mail['to']; } ) ), 'The daily lifecycle task sends one employer reminder per saved expiration date and does not repeat it' );
	$assert( false !== strpos( $employer_jobs, 'Listing expires' ) && false !== strpos( $employer_jobs, 'value="renew_job"' ) && false !== strpos( $employer_jobs, '>Renew</button>' ) && false !== strpos( $employer_jobs, 'value="duplicate_job"' ), 'My Jobs explains the effective expiration and offers renewal plus duplication for an eligible published listing' );
	$lifecycle_duplicate_id = \LlamaHire\Jobs::duplicate_as_draft( $employer_job_ids[0], $employer_user_ids[0] );
	$assert( ! is_wp_error( $lifecycle_duplicate_id ), 'Employer duplication can create a fresh draft' );
	$duplicate_meta = \LlamaHire\Jobs::get_meta( $lifecycle_duplicate_id );
	$assert( 'draft' === get_post_status( $lifecycle_duplicate_id ) && $employer_user_ids[0] === (int) get_post_field( 'post_author', $lifecycle_duplicate_id ) && '' === $duplicate_meta['deadline'] && '' === $duplicate_meta['listing_expires'] && '0' === $duplicate_meta['featured'] && '' === $duplicate_meta['job_identifier'], 'Employer duplication preserves ownership and content while clearing dates, expiration, featured state, and the external reference' );
	\LlamaHire\Jobs::set_meta( $employer_job_ids[0], array( 'listing_expires' => current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ) ) );
	$expired_employer_jobs = do_shortcode( '[llamahire_my_jobs]' );
	$assert( false !== strpos( $expired_employer_jobs, '>Expired</strong>' ) && false !== strpos( $expired_employer_jobs, 'value="relist_job"' ) && false === strpos( $expired_employer_jobs, 'value="renew_job"' ), 'Expired listings switch from renewal to moderation-backed relisting in My Jobs' );
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
	$assert( $privacy_erasure['items_removed'] && ! $privacy_erasure['items_retained'] && $privacy_erasure['done'] && ! file_exists( $privacy_resume_path ) && ! $repository->find( $privacy_application_id ) && array() === \LlamaHire\Application_Notes::for_application( $privacy_application_id ), 'WordPress personal-data erasure removes the matching application, note history, and private resume together' );
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

	$uninstall_department = wp_insert_term( 'Uninstall Department Smoke', 'llamahire_department', array( 'slug' => 'uninstall-department-smoke' ) );
	$uninstall_job_type   = wp_insert_term( 'Uninstall Job Type Smoke', \LlamaHire\Jobs::TYPE_TAXONOMY, array( 'slug' => 'uninstall-job-type-smoke' ) );
	$assert( ! is_wp_error( $uninstall_department ) && ! is_wp_error( $uninstall_job_type ), 'Uninstall taxonomy fixtures can be created' );
	unregister_taxonomy( 'llamahire_department' );
	unregister_taxonomy( \LlamaHire\Jobs::TYPE_TAXONOMY );
	$delete_job_terms = new ReflectionMethod( \LlamaHire\Uninstaller::class, 'delete_job_terms' );
	$delete_job_terms->setAccessible( true );
	$delete_job_terms->invoke( null );
	$remaining_uninstall_terms = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (%s, %s)",
			'llamahire_department',
			\LlamaHire\Jobs::TYPE_TAXONOMY
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall regression verifies plugin-owned taxonomy rows after the runtime taxonomy registry is unavailable.
	$assert( 0 === $remaining_uninstall_terms && ! taxonomy_exists( 'llamahire_department' ) && ! taxonomy_exists( \LlamaHire\Jobs::TYPE_TAXONOMY ), 'Full uninstall removes taxonomy data even though the plugin runtime is inactive' );

	WP_CLI::success( count( $checks ) . ' LlamaHire smoke checks passed.' );
} finally {
	if ( $activation_migration_job_id || $activation_migration_term_id ) {
		if ( ! taxonomy_exists( \LlamaHire\Jobs::TYPE_TAXONOMY ) ) {
			\LlamaHire\Jobs::register_taxonomies();
		}
		if ( $activation_migration_job_id ) {
			wp_delete_post( $activation_migration_job_id, true );
		}
		if ( $activation_migration_term_id ) {
			wp_delete_term( $activation_migration_term_id, \LlamaHire\Jobs::TYPE_TAXONOMY );
		}
	}
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
	if ( $uninstall_local_path && file_exists( $uninstall_local_path ) ) {
		wp_delete_file( $uninstall_local_path );
	}
	if ( $uninstall_attachment_id ) {
		wp_delete_attachment( $uninstall_attachment_id, true );
	}
	if ( $application_id ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY )->delete( $application_id );
	}
	if ( $bulk_application_id && ! is_wp_error( $bulk_application_id ) ) {
		\LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY )->delete( $bulk_application_id );
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
	foreach ( $job_type_term_ids as $job_type_term_id ) {
		wp_delete_term( $job_type_term_id, \LlamaHire\Jobs::TYPE_TAXONOMY );
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
	if ( $lifecycle_duplicate_id && ! is_wp_error( $lifecycle_duplicate_id ) ) {
		wp_delete_post( $lifecycle_duplicate_id, true );
	}
	foreach ( $employer_user_ids as $employer_user_id ) {
		wp_delete_user( $employer_user_id );
	}
	if ( $registration_user_id && ! is_wp_error( $registration_user_id ) ) {
		wp_delete_user( $registration_user_id );
	}
	foreach ( array_filter( array_merge( array( $job_id, $sitemap_job_id, $filter_job_id, $lifecycle_duplicate_id ), $employer_job_ids ) ) as $audit_job_id ) {
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
