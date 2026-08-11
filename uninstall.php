<?php
/**
 * LlamaHire uninstall routine.
 *
 * Data is retained by default to prevent accidental loss. Site owners may opt in
 * to full removal with: define( 'LLAMAHIRE_REMOVE_DATA', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-capabilities.php';
$remove_data = defined( 'LLAMAHIRE_REMOVE_DATA' ) && true === LLAMAHIRE_REMOVE_DATA;

if ( $remove_data ) {
	require_once __DIR__ . '/includes/contracts/interface-resume-storage.php';
	require_once __DIR__ . '/includes/services/class-resume-storage.php';
	require_once __DIR__ . '/includes/services/class-vip-acl-resume-storage.php';
	require_once __DIR__ . '/includes/class-uninstaller.php';
}

$uninstall_current_site = static function () use ( $remove_data ) {
	wp_clear_scheduled_hook( 'llamahire_cleanup_expired_applications' );
	wp_clear_scheduled_hook( 'llamahire_send_expiring_listing_notices' );

	if ( $remove_data ) {
		\LlamaHire\Uninstaller::remove_data();
	}
	\LlamaHire\Capabilities::remove();
};

if ( ! is_multisite() ) {
	$uninstall_current_site();
	return;
}

$offset = 0;
do {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 100,
			'offset' => $offset,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		try {
			$uninstall_current_site();
		} finally {
			restore_current_blog();
		}
	}
	$offset += count( $site_ids );
} while ( 100 === count( $site_ids ) );
