<?php
/**
 * LlamaHire uninstall routine.
 *
 * Data is retained by default to prevent accidental loss. Site owners may opt in
 * to full removal with: define( 'LLAMAHIRE_REMOVE_DATA', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-capabilities.php';
$llamahire_remove_data = defined( 'LLAMAHIRE_REMOVE_DATA' ) && true === LLAMAHIRE_REMOVE_DATA;

if ( $llamahire_remove_data ) {
	require_once __DIR__ . '/includes/contracts/interface-resume-storage.php';
	require_once __DIR__ . '/includes/services/class-resume-storage.php';
	require_once __DIR__ . '/includes/services/class-vip-acl-resume-storage.php';
	require_once __DIR__ . '/includes/class-uninstaller.php';
}

$llamahire_uninstall_current_site = static function () use ( $llamahire_remove_data ) {
	wp_clear_scheduled_hook( 'llamahire_cleanup_expired_applications' );
	wp_clear_scheduled_hook( 'llamahire_send_expiring_listing_notices' );

	if ( $llamahire_remove_data ) {
		\LlamaHire\Uninstaller::remove_data();
	}
	\LlamaHire\Capabilities::remove();
};

if ( ! is_multisite() ) {
	$llamahire_uninstall_current_site();
	return;
}

$llamahire_uninstall_offset = 0;
do {
	$llamahire_uninstall_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 100,
			'offset' => $llamahire_uninstall_offset,
		)
	);
	foreach ( $llamahire_uninstall_site_ids as $llamahire_uninstall_site_id ) {
		switch_to_blog( $llamahire_uninstall_site_id );
		try {
			$llamahire_uninstall_current_site();
		} finally {
			restore_current_blog();
		}
	}
	$llamahire_uninstall_offset += count( $llamahire_uninstall_site_ids );
} while ( 100 === count( $llamahire_uninstall_site_ids ) );
