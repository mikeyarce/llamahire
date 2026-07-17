<?php
/**
 * Plugin Name:       LlamaHire – Job Board & Careers Plugin for WordPress
 * Description:       Modern hiring for WordPress: publish jobs, build a careers page, and collect applications.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            LlamaHire
 * Text Domain:       llamahire
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LLAMAHIRE_VERSION', '0.1.0' );
define( 'LLAMAHIRE_API_VERSION', '1.0.0-alpha.4' );
define( 'LLAMAHIRE_SCHEMA_VERSION', '5' );
define( 'LLAMAHIRE_CAPABILITIES_VERSION', '2' );
define( 'LLAMAHIRE_FILE', __FILE__ );
define( 'LLAMAHIRE_PATH', plugin_dir_path( __FILE__ ) );
define( 'LLAMAHIRE_URL', plugin_dir_url( __FILE__ ) );

require_once LLAMAHIRE_PATH . 'includes/class-plugin.php';
require_once LLAMAHIRE_PATH . 'includes/class-activator.php';

register_activation_hook( __FILE__, array( 'LlamaHire\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

LlamaHire\Plugin::instance()->boot();
