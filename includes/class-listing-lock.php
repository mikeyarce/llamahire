<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Portable option-row compare-and-swap for a bounded publication lease.

/** Serialize local publication without holding a transaction across WordPress hooks. */
final class Listing_Lock {
	private static function name( $job_id ) { return 'llamahire_listing_lock_' . $job_id; }

	public static function acquire( $job_id ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$name = self::name( $job_id );
			$token = ( time() + 300 ) . ':' . wp_generate_uuid4();
			if ( add_option( $name, $token, '', false ) ) { return $token; }
			$old = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( '' !== $wpdb->last_error || ! is_string( $old ) || ! preg_match( '/^([0-9]+):[a-f0-9-]{36}$/D', $old, $matches ) || (int) $matches[1] >= time() ) { return false; }
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $token, $name, $old ) );
			wp_cache_delete( $name, 'options' );
			return 1 === $changed ? $token : false;
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Never release a lease that has since been replaced by another request. */
	public static function release( $job_id, $token ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$name = self::name( $job_id );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $token ) );
			wp_cache_delete( $name, 'options' );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function owns( $job_id, $token ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::name( $job_id ) ) );
			return '' === $wpdb->last_error && is_string( $current ) && hash_equals( $token, $current ) && (int) strtok( $current, ':' ) >= time();
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private function __construct() {}
}
