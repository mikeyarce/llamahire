<?php
/**
 * LlamaHire uninstall routine.
 *
 * Data is retained by default to prevent accidental loss. Site owners may opt in
 * to full removal with: define( 'LLAMAHIRE_REMOVE_DATA', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-capabilities.php';
\LlamaHire\Capabilities::remove();

if ( ! defined( 'LLAMAHIRE_REMOVE_DATA' ) || true !== LLAMAHIRE_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}llamahire_applications" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'llamahire_db_version' );
delete_option( 'llamahire_schema_version' );
delete_option( 'llamahire_organization' );
