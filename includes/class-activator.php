<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Activator {
	public static function activate( $network_wide = false ) {
		Jobs::register_taxonomies();
		if ( is_multisite() && $network_wide ) {
			self::for_each_site(
				static function () {
					self::install_current_site();
				}
			);
			return;
		}
		self::install_current_site();
	}

	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site(
				static function () {
					wp_clear_scheduled_hook( Telemetry::HOOK, array( 'reporting_enabled' ) );
					wp_clear_scheduled_hook( Telemetry::HOOK, array( 'reactivated' ) );
					wp_clear_scheduled_hook( Telemetry::HOOK, array( 'site_snapshot' ) );
					wp_clear_scheduled_hook( Applications::RETENTION_HOOK );
					wp_clear_scheduled_hook( Employer_Notifications::EXPIRING_HOOK );
					wp_clear_scheduled_hook( Geocoding::HOOK );
					wp_clear_scheduled_hook( Migrations::CONTINUE_HOOK );
					wp_unschedule_hook( 'llamahire_reconcile_listing' );
					delete_option( 'rewrite_rules' );
				}
			);
			return;
		}
		wp_clear_scheduled_hook( Telemetry::HOOK, array( 'reporting_enabled' ) );
		wp_clear_scheduled_hook( Telemetry::HOOK, array( 'reactivated' ) );
		wp_clear_scheduled_hook( Telemetry::HOOK, array( 'site_snapshot' ) );
		wp_clear_scheduled_hook( Applications::RETENTION_HOOK );
		wp_clear_scheduled_hook( Employer_Notifications::EXPIRING_HOOK );
		wp_clear_scheduled_hook( Geocoding::HOOK );
		wp_clear_scheduled_hook( Migrations::CONTINUE_HOOK );
		wp_unschedule_hook( 'llamahire_reconcile_listing' );
		delete_option( 'rewrite_rules' );
	}

	private static function for_each_site( $callback ) {
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
				$callback();
				restore_current_blog();
			}
			$offset += count( $site_ids );
		} while ( 100 === count( $site_ids ) );
	}

	private static function install_current_site() {
		Migrations::run();
		Capabilities::install();
		Setup::mark_pending();
		Telemetry::on_activation();
		delete_option( 'rewrite_rules' );
	}
}
