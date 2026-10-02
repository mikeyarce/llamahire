<?php
/**
 * Verify full uninstall cleanup across a real WordPress multisite network.
 *
 * Run only in the disposable .wp-env.multisite.json environment.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() ) {
	exit( 'Run this file with WP-CLI in the disposable multisite environment.' );
}

$assert = static function ( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'Failed: ' . $label );
	}
};

$network = get_network();
$sites   = get_sites( array( 'fields' => 'ids', 'number' => 2 ) );
if ( count( $sites ) < 2 ) {
	$site_id = wp_insert_site(
		array(
			'domain'     => $network->domain,
			'path'       => '/llamahire-uninstall-test/',
			'network_id' => $network->id,
			'user_id'    => 1,
			'title'      => 'LlamaHire Uninstall Test',
		)
	);
	$assert( ! is_wp_error( $site_id ), 'A second disposable site can be created' );
	$sites[] = (int) $site_id;
}

$sites = array_map( 'absint', array_slice( array_values( array_unique( $sites ) ), 0, 2 ) );
foreach ( $sites as $site_id ) {
	switch_to_blog( $site_id );
	try {
		\LlamaHire\Activator::activate( false );
		if ( ! wp_next_scheduled( \LlamaHire\Applications::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', \LlamaHire\Applications::RETENTION_HOOK );
		}
		if ( ! wp_next_scheduled( \LlamaHire\Employer_Notifications::EXPIRING_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', \LlamaHire\Employer_Notifications::EXPIRING_HOOK );
		}
		foreach ( array( 'reporting_enabled', 'reactivated', 'site_snapshot' ) as $event ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, \LlamaHire\Telemetry::HOOK, array( $event ) );
		}
		update_option( 'llamahire_telemetry_installation_id', wp_generate_uuid4(), false );
		update_option( 'llamahire_telemetry_registered', true, false );
		set_transient( 'llamahire_telemetry_retry_after', true, DAY_IN_SECONDS );
		update_option( 'llamahire_email_diagnostics', array( 'test' => $site_id ), false );
		wp_insert_post(
			array(
				'post_type'   => \LlamaHire\Jobs::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Uninstall network fixture ' . $site_id,
			)
		);
		wp_insert_term( 'Uninstall department ' . $site_id, \LlamaHire\Jobs::DEPARTMENT_TAXONOMY, array( 'slug' => 'uninstall-department-' . $site_id ) );
		wp_insert_term( 'Uninstall job type ' . $site_id, \LlamaHire\Jobs::TYPE_TAXONOMY, array( 'slug' => 'uninstall-job-type-' . $site_id ) );

		global $wpdb;
		$applications_table = $wpdb->prefix . 'llamahire_applications';
		$assert( $applications_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $applications_table ) ) ), 'The applications table exists before uninstall on site ' . $site_id );
		$assert( get_role( 'administrator' )->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ), 'LlamaHire capabilities exist before uninstall on site ' . $site_id );
	} finally {
		restore_current_blog();
	}
}

define( 'LLAMAHIRE_REMOVE_DATA', true );
define( 'WP_UNINSTALL_PLUGIN', plugin_basename( LLAMAHIRE_FILE ) );
require LLAMAHIRE_PATH . 'uninstall.php';

foreach ( $sites as $site_id ) {
	switch_to_blog( $site_id );
	try {
		global $wpdb;
		$applications_table = $wpdb->prefix . 'llamahire_applications';
		$audit_table        = $wpdb->prefix . 'llamahire_audit_log';
		$notes_table        = $wpdb->prefix . 'llamahire_application_notes';
		$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $applications_table ) ) ), 'The applications table is removed from site ' . $site_id );
		$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) ), 'The audit table is removed from site ' . $site_id );
		$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $notes_table ) ) ), 'The notes table is removed from site ' . $site_id );
		foreach ( array( 'reporting_enabled', 'reactivated', 'site_snapshot' ) as $event ) {
			$assert( ! wp_next_scheduled( \LlamaHire\Telemetry::HOOK, array( $event ) ), 'Reporting tasks are removed from site ' . $site_id );
		}
		$assert( false === get_option( 'llamahire_telemetry_installation_id', false ) && false === get_option( 'llamahire_telemetry_registered', false ) && false === get_transient( 'llamahire_telemetry_retry_after' ), 'Reporting state is removed from site ' . $site_id );
		$assert( false === get_option( 'llamahire_email_diagnostics', false ), 'Plugin options are removed from site ' . $site_id );
		$assert( ! wp_next_scheduled( \LlamaHire\Applications::RETENTION_HOOK ) && ! wp_next_scheduled( \LlamaHire\Employer_Notifications::EXPIRING_HOOK ), 'Scheduled tasks are removed from site ' . $site_id );
		$assert( ! get_role( 'administrator' )->has_cap( \LlamaHire\Capabilities::VIEW_APPLICATIONS ) && ! get_role( \LlamaHire\Capabilities::EMPLOYER_ROLE ) && ! get_role( \LlamaHire\Capabilities::HIRING_MANAGER_ROLE ), 'Plugin roles and capabilities are removed from site ' . $site_id );
		$assert( array() === get_posts( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ) ), 'Job posts are removed from site ' . $site_id );
		$assert( array() === get_terms( array( 'taxonomy' => \LlamaHire\Jobs::DEPARTMENT_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ), 'Department terms are removed from site ' . $site_id );
		$assert( array() === get_terms( array( 'taxonomy' => \LlamaHire\Jobs::TYPE_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ), 'Job type terms are removed from site ' . $site_id );
	} finally {
		restore_current_blog();
	}
}

WP_CLI::success( 'LlamaHire full uninstall cleaned two multisite sites.' );
