<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Concurrency-safe counters for public request throttling.
 */
final class Rate_Limiter {
	const LOCK_PREFIX = 'llamahire_rate_lock_';
	const LOCK_TTL    = 30;

	/**
	 * Atomically consume one allowance from a transient-backed counter.
	 *
	 * A short database option lock serializes the transient read/write pair. A
	 * contending request fails closed instead of sharing the same allowance.
	 *
	 * @param string $key    Transient key.
	 * @param int    $limit  Maximum requests in the window; zero disables it.
	 * @param int    $window Counter lifetime in seconds.
	 * @return bool Whether the request is allowed.
	 */
	public static function consume( $key, $limit, $window ) {
		global $wpdb;

		$key    = sanitize_key( $key );
		$limit  = absint( $limit );
		$window = max( 1, absint( $window ) );
		if ( ! $key || ! $limit ) {
			return true;
		}

		$lock  = self::LOCK_PREFIX . md5( $key );
		$now   = time();
		$token = $now . ':' . wp_generate_uuid4();
		if ( ! add_option( $lock, $token, '', false ) ) {
			$previous  = (string) get_option( $lock, '' );
			$locked_at = absint( strtok( $previous, ':' ) );
			if ( ! $locked_at || $now - $locked_at <= self::LOCK_TTL ) {
				return false;
			}

			$claimed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Ownership-token compare-and-swap is required to reclaim a crashed request's short-lived lock safely.
				$wpdb->options,
				array( 'option_value' => $token ),
				array(
					'option_name'  => $lock,
					'option_value' => $previous,
				),
				array( '%s' ),
				array( '%s', '%s' )
			);
			wp_cache_delete( $lock, 'options' );
			if ( 1 !== $claimed ) {
				return false;
			}
		}

		try {
			$count = absint( get_transient( $key ) );
			if ( $count >= $limit ) {
				return false;
			}

			return (bool) set_transient( $key, $count + 1, $window );
		} finally {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Ownership-token comparison prevents one request from releasing another request's lock.
				$wpdb->options,
				array(
					'option_name'  => $lock,
					'option_value' => $token,
				),
				array( '%s', '%s' )
			);
			wp_cache_delete( $lock, 'options' );
		}
	}

	private function __construct() {}
}
