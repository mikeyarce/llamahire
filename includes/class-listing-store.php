<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Internal listing repository; publication must observe current database state.

/** Site-local approval state and immutable periods, independent of provider billing data. */
final class Listing_Store {
	private static $writing_expiry = false;

	public static function table( $kind ) {
		global $wpdb;
		return $wpdb->prefix . 'llamahire_listing_' . $kind;
	}

	/** Internal metadata guards allow only the period coordinator to write its expiry. */
	public static function writing_expiry() { return self::$writing_expiry; }

	public static function state( $job_id ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d', self::table( 'states' ), $job_id ), ARRAY_A );
			return '' === $wpdb->last_error ? $row : self::failure();
		} catch ( \Throwable $error ) {
			return self::failure();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Compare-and-swap prevents an older approval from replacing a concurrent one. */
	public static function approve( $job_id, $owner_id, $fingerprint, $actor_id ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$state = self::state( $job_id );
			if ( is_wp_error( $state ) ) { return $state; }
			if ( ! $state ) {
				$inserted = $wpdb->insert( self::table( 'states' ), array( 'job_id' => $job_id, 'owner_id' => $owner_id ) );
				if ( false === $inserted ) { return self::failure(); }
				$state = self::state( $job_id );
				if ( ! $state || is_wp_error( $state ) ) { return self::failure(); }
			}
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET owner_id = %d, approval_hash = %s, approved_by = %d, approved_at = %s, revision = revision + 1 WHERE job_id = %d AND revision = %d', self::table( 'states' ), $owner_id, $fingerprint, $actor_id, current_time( 'mysql', true ), $job_id, $state['revision'] ) );
			return 1 === $result ? true : self::failure();
		} catch ( \Throwable $error ) {
			return self::failure();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function withdraw( $job_id ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			return false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET approval_hash = '', approved_by = 0, approved_at = NULL, revision = revision + 1 WHERE job_id = %d", self::table( 'states' ), $job_id ) );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** The current period is a pointer; previous periods remain immutable and inspectable. */
	public static function period( $job_id, $key = '' ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			if ( '' === $key ) {
				$state = self::state( $job_id );
				if ( is_wp_error( $state ) ) { return $state; }
				$key = $state['current_period'] ?? '';
				if ( '' === $key ) { return null; }
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d AND period_key = %s', self::table( 'periods' ), $job_id, $key ), ARRAY_A );
			return '' === $wpdb->last_error ? $row : self::failure();
		} catch ( \Throwable $error ) {
			return self::failure();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Called only after the actual post status write. Public availability stays closed
	 * until this transaction commits. Failure must return the post to private status.
	 */
	public static function start( $job_id, $owner_id, array $offer ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		$transaction = false;
		try {
			foreach ( array( $wpdb->postmeta, self::table( 'states' ), self::table( 'periods' ) ) as $table ) {
				$metadata = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( ! $metadata || $metadata->Name !== $table || 'innodb' !== strtolower( (string) $metadata->Engine ) ) { return self::failure(); }
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::failure(); }
			$transaction = true;
			$state = self::state( $job_id );
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT post_author, post_status FROM %i WHERE ID = %d', $wpdb->posts, $job_id ) );
			if ( ! $job || 'publish' !== $job->post_status || (int) $job->post_author !== $owner_id || ! $state || is_wp_error( $state ) || (int) $state['owner_id'] !== $owner_id || '' === $state['approval_hash'] ) { return self::failure(); }
			$current = self::period( $job_id );
			$existing = self::period( $job_id, $offer['key'] );
			if ( is_wp_error( $current ) || is_wp_error( $existing ) ) { return self::failure(); }
			if ( $existing ) {
				if ( $state['current_period'] !== $offer['key'] || (int) $existing['owner_id'] !== $owner_id || (int) $existing['days'] !== $offer['days'] || $existing['previous_key'] !== $offer['previous_key'] ) { return self::failure(); }
				$period = $existing;
			} else {
				if ( $state['current_period'] !== $offer['previous_key'] || ( $current && (int) $current['owner_id'] === $owner_id && $current['expires'] >= current_time( 'Y-m-d' ) ) ) { return self::failure(); }
				$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				$period = array( 'period_key' => $offer['key'], 'previous_key' => $offer['previous_key'], 'job_id' => $job_id, 'owner_id' => $owner_id, 'days' => $offer['days'], 'started_at' => $now->format( 'Y-m-d H:i:s' ), 'expires' => Listing_Rules::expiry( $now, wp_timezone(), $offer['days'] ) );
				if ( false === $wpdb->insert( self::table( 'periods' ), $period ) ) { return self::failure(); }
			}
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET current_period = %s, revision = revision + 1 WHERE job_id = %d AND revision = %d AND owner_id = %d AND current_period = %s', self::table( 'states' ), $offer['key'], $job_id, $state['revision'], $owner_id, $state['current_period'] ) );
			if ( 1 !== $changed ) { return self::failure(); }
			self::$writing_expiry = true;
			$meta = Jobs::get_meta( $job_id );
			$meta['listing_expires'] = $period['expires'];
			update_post_meta( $job_id, Jobs::META_KEY, $meta );
			update_post_meta( $job_id, Jobs::META_EXPIRY, $period['expires'] );
			// Verify durable values, not metadata cache entries populated before commit.
			$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $wpdb->postmeta, $job_id, Jobs::META_KEY ) );
			if ( '' !== $wpdb->last_error ) { return self::failure(); }
			$saved = maybe_unserialize( $saved );
			$mirror = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $wpdb->postmeta, $job_id, Jobs::META_EXPIRY ) );
			if ( '' !== $wpdb->last_error || ! is_array( $saved ) || ( $saved['listing_expires'] ?? '' ) !== $period['expires'] || $mirror !== $period['expires'] || false === $wpdb->query( 'COMMIT' ) ) { return self::failure(); }
			$transaction = false;
			return $period;
		} catch ( \Throwable $error ) {
			return self::failure();
		} finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			self::$writing_expiry = false;
			wp_cache_delete( $job_id, 'post_meta' );
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function failure() { return new \WP_Error( 'llamahire_listing_storage', __( 'Listing publication is temporarily unavailable. Try again later.', 'llamahire' ) ); }
	private function __construct() {}
}
