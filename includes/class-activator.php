<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Activator {
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install_current_site();
				restore_current_blog();
			}
			return;
		}
		self::install_current_site();
	}

	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				wp_clear_scheduled_hook( Applications::RETENTION_HOOK );
				restore_current_blog();
			}
			flush_rewrite_rules();
			return;
		}
		wp_clear_scheduled_hook( Applications::RETENTION_HOOK );
		flush_rewrite_rules();
	}

	private static function install_current_site() {
		Migrations::run();
		Capabilities::install();
		Setup::mark_pending();
		Jobs::register();
		flush_rewrite_rules();
	}
}
