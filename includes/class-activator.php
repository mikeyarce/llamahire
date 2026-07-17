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

	private static function install_current_site() {
		Migrations::run();
		Capabilities::install();
		Jobs::register();
		flush_rewrite_rules();
	}
}
