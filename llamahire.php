<?php
/**
 * Plugin Name:       LlamaHire – Job Board & Careers
 * Plugin URI:        https://llamahire.com/
 * Description:       Modern hiring for WordPress: publish jobs, build a careers page, and collect applications.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            LlamaHire
 * Author URI:        https://mikeyarce.com/
 * Text Domain:       llamahire
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LLAMAHIRE_VERSION', '0.1.0' );
define( 'LLAMAHIRE_API_VERSION', '1.0.0-alpha.14' );
define( 'LLAMAHIRE_SCHEMA_VERSION', '12' );
define( 'LLAMAHIRE_CAPABILITIES_VERSION', '7' );
// Public PostHog ingestion token. Events still require a site's explicit opt-in.
define( 'LLAMAHIRE_POSTHOG_PROJECT_TOKEN', 'phc_yPt8yzcqcxvMHYfDGwipeDR5uehGk9VfvUjEujCYuEj' );
define( 'LLAMAHIRE_POSTHOG_HOST', 'https://us.i.posthog.com' );
define( 'LLAMAHIRE_FILE', __FILE__ );
define( 'LLAMAHIRE_PATH', plugin_dir_path( __FILE__ ) );
define( 'LLAMAHIRE_URL', plugin_dir_url( __FILE__ ) );

require_once LLAMAHIRE_PATH . 'includes/class-plugin.php';
require_once LLAMAHIRE_PATH . 'includes/class-activator.php';

register_activation_hook( __FILE__, array( 'LlamaHire\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LlamaHire\\Activator', 'deactivate' ) );

LlamaHire\Plugin::instance()->boot();

// Development fixture commands are intentionally excluded from release ZIPs.
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( LLAMAHIRE_PATH . 'tools/class-fixtures-command.php' ) ) {
	require_once LLAMAHIRE_PATH . 'tools/class-fixtures-command.php';
	WP_CLI::add_command( 'llamahire fixtures', 'LlamaHire\\Tools\\Fixtures_Command' );
}
