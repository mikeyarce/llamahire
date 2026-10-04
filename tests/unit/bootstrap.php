<?php
define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return $GLOBALS['unit_user_id']; }
function get_current_blog_id() { return $GLOBALS['unit_site_id']; }
function user_can( $id, $capability ) { return ! empty( $GLOBALS['unit_users'][ $id ]['caps'][ $capability ] ); }
function get_userdata( $id ) { return isset( $GLOBALS['unit_users'][ $id ] ) ? (object) $GLOBALS['unit_users'][ $id ] : false; }
function get_post( $id ) { return $GLOBALS['unit_jobs'][ $id ] ?? null; }
function get_option( $key, $default = false ) { return $GLOBALS['unit_options'][ $key ] ?? $default; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function get_bloginfo( $key ) { return 'Unit site'; }
function __( $text, $domain = '' ) { return $text; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function get_site_icon_url() { return ''; }
$root = dirname( __DIR__, 2 );
foreach ( array( 'contracts/interface-application-repository.php', 'contracts/interface-extension-access.php', 'class-capabilities.php', 'class-jobs.php', 'class-settings.php', 'class-ownership.php', 'services/class-extension-access.php' ) as $file ) {
	require_once $root . '/includes/' . $file;
}
