<?php
/** Development-only simulated expiry for registered fictional listings. @package LlamaHire */
namespace LlamaHire\Tools;

use LlamaHire\Jobs;
use LlamaHire\Listing_Lock;
use LlamaHire\Listing_Store;

defined( 'ABSPATH' ) || exit;
defined( 'WP_CLI' ) && WP_CLI || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Free-owned fixture storage; never included in release archives.

final class Publication_Fixture {
	/** Explicit opt-in; only this site's registered fixture-owned job may change. */
	public static function expire( $job_id, $confirmed ) {
		if ( true !== $confirmed || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! is_int( $job_id ) || $job_id < 1 ) { return self::error(); }
		$registry = get_option( Fixtures_Command::OPTION ); $job = get_post( $job_id );
		if ( ! is_array( $registry ) || Fixtures_Command::OWNER !== ( $registry['owner'] ?? '' ) || ! in_array( $job_id, $registry['jobs'] ?? array(), true ) || Fixtures_Command::OWNER !== get_post_meta( $job_id, Fixtures_Command::META, true ) || ! $job || Jobs::POST_TYPE !== $job->post_type ) { return self::error(); }
		$token = Listing_Lock::acquire( $job_id );
		if ( ! $token ) { return self::error(); }
		global $wpdb; $previous = $wpdb->suppress_errors(); $transaction = false;
		try {
			$period = Listing_Store::period( $job_id );
			if ( ! is_array( $period ) || (int) $period['owner_id'] !== (int) $job->post_author ) { return self::error(); }
			foreach ( array( Listing_Store::table( 'periods' ), $wpdb->postmeta ) as $table ) {
				$metadata = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( ! $metadata || 'innodb' !== strtolower( (string) $metadata->Engine ) ) { return self::error(); }
			}
			if ( ! Listing_Lock::owns( $job_id, $token ) || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error(); }
			$transaction = true;
			$expires = current_datetime()->modify( '-1 day' );
			$started = $expires->setTime( 0, 0 )->modify( '-' . (int) $period['days'] . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) );
			if ( false === $wpdb->update( Listing_Store::table( 'periods' ), array( 'expires' => $expires->format( 'Y-m-d' ), 'started_at' => $started->format( 'Y-m-d H:i:s' ) ), array( 'period_key' => $period['period_key'], 'job_id' => $job_id ) ) ) { return self::error(); }
			$meta = Jobs::get_meta( $job_id ); $meta['listing_expires'] = $expires->format( 'Y-m-d' );
			update_post_meta( $job_id, Jobs::META_KEY, $meta );
			update_post_meta( $job_id, Jobs::META_EXPIRY, $meta['listing_expires'] );
			if ( '' !== $wpdb->last_error || Jobs::get_meta( $job_id )['listing_expires'] !== $meta['listing_expires'] || get_post_meta( $job_id, Jobs::META_EXPIRY, true ) !== $meta['listing_expires'] || false === $wpdb->query( 'COMMIT' ) ) { return self::error(); }
			$transaction = false; return true;
		} catch ( \Throwable $error ) { return self::error(); }
		finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			clean_post_cache( $job_id ); $wpdb->suppress_errors( $previous ); Listing_Lock::release( $job_id, $token );
		}
	}

	private static function error() { return new \WP_Error( 'fixture_expiry', 'Expiry simulation requires --yes, a local/development site, and a registered fixture job with an owned listing period and healthy storage.' ); }
	private function __construct() {}
}
