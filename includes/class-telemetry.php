<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, site-level usage reporting. Never handles candidate or visitor data.
 */
final class Telemetry {
	const HOOK       = 'llamahire_send_telemetry';
	const REGISTERED = 'llamahire_telemetry_registered';
	const SNAPSHOT_INTERVAL = 15 * DAY_IN_SECONDS;

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'on_settings_update' ), 10, 2 );
		add_action( 'added_option', array( __CLASS__, 'on_option_added' ), 10, 2 );
		if ( self::is_enabled() && ! get_option( self::REGISTERED, false ) ) {
			self::queue( 'reporting_enabled' );
		}
		if ( self::is_enabled() ) {
			self::queue_snapshot();
		}
	}

	public static function field( $name, $value, $id ) {
		?>
		<label for="<?php echo esc_attr( $id ); ?>"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1, $value ); ?>> <?php esc_html_e( 'Share LlamaHire installation information with its developer', 'llamahire' ); ?></label>
		<p class="description"><?php esc_html_e( 'If enabled, LlamaHire sends this site’s URL, a random installation ID, site mode, and LlamaHire, WordPress, and PHP versions to PostHog when reporting is enabled or the plugin is reactivated. Every 15 days it also sends job counts by status, active plugin names and versions, active theme name and version, locale, and multisite status. No applicant, employer account, visitor, or page-view data is sent. You can turn this off in Settings at any time.', 'llamahire' ); ?></p>
		<?php
	}

	public static function on_option_added( $option, $value ) {
		if ( Settings::OPTION === $option && ! empty( $value['usage_reporting'] ) ) {
			self::queue( 'reporting_enabled' );
			self::queue_snapshot();
		}
	}

	public static function on_settings_update( $old_value, $value ) {
		$was_enabled = ! empty( $old_value['usage_reporting'] );
		$is_enabled  = ! empty( $value['usage_reporting'] );
		if ( $was_enabled && ! $is_enabled ) {
			wp_clear_scheduled_hook( self::HOOK, array( 'reporting_enabled' ) );
			wp_clear_scheduled_hook( self::HOOK, array( 'reactivated' ) );
			wp_clear_scheduled_hook( self::HOOK, array( 'site_snapshot' ) );
			delete_transient( 'llamahire_telemetry_retry_after' );
		} elseif ( ! $was_enabled && $is_enabled ) {
			self::queue( 'reporting_enabled' );
			self::queue_snapshot();
		}
	}

	public static function on_activation() {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::queue( get_option( self::REGISTERED, false ) ? 'reactivated' : 'reporting_enabled' );
		self::queue_snapshot();
	}

	private static function is_enabled() {
		return ! empty( Settings::get()['usage_reporting'] );
	}

	private static function queue( $event ) {
		if ( ! self::project_token() || get_transient( 'llamahire_telemetry_retry_after' ) || wp_next_scheduled( self::HOOK, array( $event ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $event ) );
	}

	private static function queue_snapshot( $delay = MINUTE_IN_SECONDS ) {
		if ( ! self::project_token() || wp_next_scheduled( self::HOOK, array( 'site_snapshot' ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, array( 'site_snapshot' ) );
	}

	private static function project_token() {
		return defined( 'LLAMAHIRE_POSTHOG_PROJECT_TOKEN' ) ? sanitize_text_field( LLAMAHIRE_POSTHOG_PROJECT_TOKEN ) : '';
	}

	public static function send( $event ) {
		if ( ! in_array( $event, array( 'reporting_enabled', 'reactivated', 'site_snapshot' ), true ) || ! self::is_enabled() ) {
			return;
		}
		$token = self::project_token();
		$host  = defined( 'LLAMAHIRE_POSTHOG_HOST' ) ? LLAMAHIRE_POSTHOG_HOST : 'https://us.i.posthog.com';
		if ( ! $token || ! in_array( $host, array( 'https://us.i.posthog.com', 'https://eu.i.posthog.com' ), true ) ) {
			return;
		}
		if ( 'site_snapshot' === $event ) {
			self::queue_snapshot( self::SNAPSHOT_INTERVAL );
		}
		$id = get_option( 'llamahire_telemetry_installation_id', '' );
		if ( ! $id ) {
			$id = wp_generate_uuid4();
			if ( ! add_option( 'llamahire_telemetry_installation_id', $id, '', false ) ) {
				$id = get_option( 'llamahire_telemetry_installation_id', '' );
				if ( ! $id ) {
					return;
				}
			}
		}
		$properties = array(
			'$process_person_profile' => false,
			'site_url'       => home_url( '/' ),
			'site_mode'      => Settings::site_mode(),
			'plugin_version' => LLAMAHIRE_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
		if ( 'site_snapshot' === $event ) {
			$properties = array_merge( $properties, self::snapshot_properties() );
		}
		$response = wp_remote_post(
			$host . '/i/v0/e/',
			array(
				'timeout' => 2,
				'redirection' => 0,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body' => wp_json_encode(
					array(
						'api_key'     => $token,
						'event'       => 'llamahire_' . $event,
						'distinct_id' => $id,
						'properties'  => $properties,
					)
				),
			)
		);
		if ( ! is_wp_error( $response ) && 200 <= wp_remote_retrieve_response_code( $response ) && 300 > wp_remote_retrieve_response_code( $response ) ) {
			if ( 'reporting_enabled' === $event ) {
				update_option( self::REGISTERED, true, false );
			}
			delete_transient( 'llamahire_telemetry_retry_after' );
		} else {
			set_transient( 'llamahire_telemetry_retry_after', true, DAY_IN_SECONDS );
		}
	}

	private static function snapshot_properties() {
		$counts = wp_count_posts( Jobs::POST_TYPE );
		$theme  = wp_get_theme();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$plugins = array();
		foreach ( array_unique( $active ) as $file ) {
			if ( isset( $installed[ $file ] ) ) {
				$plugins[] = array(
				'name'    => $installed[ $file ]['Name'],
				'version' => $installed[ $file ]['Version'],
				);
			}
		}
		usort( $plugins, static function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		return array(
			'jobs_published' => (int) ( $counts->publish ?? 0 ),
			'jobs_draft'     => (int) ( $counts->draft ?? 0 ),
			'jobs_pending'   => (int) ( $counts->pending ?? 0 ),
			'active_plugins' => $plugins,
			'active_theme'   => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
			'locale'         => get_locale(),
			'is_multisite'   => is_multisite(),
		);
	}

	private function __construct() {}
}
