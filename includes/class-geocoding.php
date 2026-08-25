<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Derives coordinates asynchronously from saved public job addresses.
 */
final class Geocoding {
	const META_LATITUDE     = '_llamahire_latitude';
	const META_LONGITUDE    = '_llamahire_longitude';
	const META_COORDINATES  = '_llamahire_coordinates';
	const META_HASH         = '_llamahire_geocode_hash';
	const META_STATUS       = '_llamahire_geocode_status';
	const META_ATTEMPTS     = '_llamahire_geocode_attempts';
	const META_NEXT_ATTEMPT = '_llamahire_geocode_next_attempt';
	const HOOK              = 'llamahire_geocode_job';
	const ENDPOINT          = 'https://maps.googleapis.com/maps/api/geocode/json';
	const MAX_ATTEMPTS      = 3;
	const CACHE_PREFIX      = 'llamahire_geocode_';

	public static function register() {
		add_action( 'added_post_meta', array( __CLASS__, 'job_meta_saved' ), 20, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'job_meta_saved' ), 20, 4 );
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 3 );
	}

	public static function job_meta_saved( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( Jobs::META_KEY !== $meta_key || Jobs::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		self::sync( $post_id, Jobs::sanitize_meta( $meta_value ) );
	}

	/**
	 * Queue a lookup when the saved physical address changes.
	 *
	 * @param int   $post_id Job post ID.
	 * @param array $meta    Sanitized job metadata.
	 * @return void
	 */
	public static function sync( $post_id, array $meta ) {
		$post_id = absint( $post_id );
		$hash    = self::address_hash( $meta );
		$saved   = (string) get_post_meta( $post_id, self::META_HASH, true );

		if ( ! $hash ) {
			self::clear( $post_id, 'not_applicable', $saved );
			return;
		}
		if ( $saved && hash_equals( $saved, $hash ) ) {
			return;
		}
		if ( $saved ) {
			self::unschedule( $post_id, $saved );
		}

		// Coordinates for a previous address must never survive an address change.
		delete_post_meta( $post_id, self::META_LATITUDE );
		delete_post_meta( $post_id, self::META_LONGITUDE );
		delete_post_meta( $post_id, self::META_COORDINATES );
		delete_post_meta( $post_id, self::META_HASH );
		delete_post_meta( $post_id, self::META_ATTEMPTS );
		delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );

		if ( ! self::api_key() ) {
			update_post_meta( $post_id, self::META_STATUS, 'disabled' );
			return;
		}

		update_post_meta( $post_id, self::META_HASH, $hash );
		update_post_meta( $post_id, self::META_STATUS, 'queued' );
		update_post_meta(
			$post_id,
			self::META_COORDINATES,
			array(
				'hash'   => $hash,
				'status' => 'queued',
			)
		);
		self::schedule( $post_id, $hash, 1, time() + 1 );
	}

	/**
	 * Process one queued lookup and apply bounded retry/backoff on provider errors.
	 *
	 * @param int    $post_id Job post ID.
	 * @param string $hash    Address hash captured when the event was queued.
	 * @param int    $attempt One-based attempt number.
	 * @return void
	 */
	public static function process( $post_id, $hash, $attempt = 1 ) {
		$post_id = absint( $post_id );
		$hash    = strtolower( sanitize_text_field( (string) $hash ) );
		$attempt = min( self::MAX_ATTEMPTS, max( 1, absint( $attempt ) ) );
		if ( ! $post_id || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || Jobs::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		self::unschedule( $post_id, $hash );
		$meta = Jobs::get_meta( $post_id );
		if ( $hash !== self::address_hash( $meta ) || $hash !== (string) get_post_meta( $post_id, self::META_HASH, true ) ) {
			return;
		}
		$api_key = self::api_key();
		if ( ! $api_key ) {
			update_post_meta( $post_id, self::META_STATUS, 'disabled' );
			delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );
			return;
		}

		$cached = get_transient( self::CACHE_PREFIX . substr( $hash, 0, 32 ) );
		if ( is_array( $cached ) && self::apply_cached( $post_id, $hash, $cached ) ) {
			return;
		}
		if ( ! self::hash_is_current( $post_id, $hash ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_ATTEMPTS, $attempt );
		delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );
		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'address' => Jobs::full_location_label( $meta ),
					'key'     => $api_key,
				),
				self::ENDPOINT
			),
			array(
				'timeout'     => 3,
				'redirection' => 0,
			)
		);
		if ( ! self::hash_is_current( $post_id, $hash ) ) {
			return;
		}
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::handle_error( $post_id, $hash, $attempt );
			return;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = sanitize_key( $body['status'] ?? '' );
		if ( 'zero_results' === $status ) {
			$cached = array( 'status' => 'not_found' );
			set_transient( self::CACHE_PREFIX . substr( $hash, 0, 32 ), $cached, DAY_IN_SECONDS );
			self::apply_cached( $post_id, $hash, $cached );
			return;
		}

		$location  = $body['results'][0]['geometry']['location'] ?? array();
		$latitude  = self::coordinate( $location['lat'] ?? null, -90, 90 );
		$longitude = self::coordinate( $location['lng'] ?? null, -180, 180 );
		if ( 'ok' !== $status || null === $latitude || null === $longitude ) {
			self::handle_error( $post_id, $hash, $attempt );
			return;
		}

		$cached = array(
			'status'    => 'success',
			'latitude'  => $latitude,
			'longitude' => $longitude,
		);
		set_transient( self::CACHE_PREFIX . substr( $hash, 0, 32 ), $cached, 30 * DAY_IN_SECONDS );
		self::apply_cached( $post_id, $hash, $cached );
	}

	public static function coordinates( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_COORDINATES, true );
		$hash   = is_array( $stored ) ? (string) ( $stored['hash'] ?? '' ) : '';
		if ( ! $hash || 'success' !== ( $stored['status'] ?? '' ) || ! self::hash_is_current( $post_id, $hash ) ) {
			return array();
		}
		$latitude  = self::coordinate( $stored['latitude'] ?? null, -90, 90 );
		$longitude = self::coordinate( $stored['longitude'] ?? null, -180, 180 );

		return null === $latitude || null === $longitude ? array() : array( 'latitude' => (float) $latitude, 'longitude' => (float) $longitude );
	}

	private static function apply_cached( $post_id, $hash, array $cached ) {
		if ( ! self::hash_is_current( $post_id, $hash ) ) {
			return false;
		}
		$previous = get_post_meta( $post_id, self::META_COORDINATES, true );
		if ( ! is_array( $previous ) || $hash !== (string) ( $previous['hash'] ?? '' ) ) {
			return false;
		}
		$status = sanitize_key( $cached['status'] ?? '' );
		$stored = array(
			'hash'   => $hash,
			'status' => $status,
		);
		if ( 'success' === $status ) {
			$latitude  = self::coordinate( $cached['latitude'] ?? null, -90, 90 );
			$longitude = self::coordinate( $cached['longitude'] ?? null, -180, 180 );
			if ( null === $latitude || null === $longitude ) {
				return false;
			}
			$stored['latitude']  = $latitude;
			$stored['longitude'] = $longitude;
		} elseif ( 'not_found' !== $status ) {
			return false;
		}

		$updated = update_post_meta( $post_id, self::META_COORDINATES, $stored, $previous );
		if ( false === $updated && $stored !== get_post_meta( $post_id, self::META_COORDINATES, true ) ) {
			return false;
		}
		if ( ! self::hash_is_current( $post_id, $hash ) ) {
			delete_post_meta( $post_id, self::META_COORDINATES, $stored );
			return false;
		}
		update_post_meta( $post_id, self::META_STATUS, $status );
		delete_post_meta( $post_id, self::META_ATTEMPTS );
		delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );

		return true;
	}

	private static function handle_error( $post_id, $hash, $attempt ) {
		update_post_meta( $post_id, self::META_STATUS, 'error' );
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );
			return;
		}
		$next_attempt = $attempt + 1;
		$delay        = 5 * MINUTE_IN_SECONDS * ( 2 ** ( $attempt - 1 ) );
		self::schedule( $post_id, $hash, $next_attempt, time() + $delay );
	}

	private static function schedule( $post_id, $hash, $attempt, $timestamp ) {
		$args = array( absint( $post_id ), (string) $hash, absint( $attempt ) );
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( max( time() + 1, absint( $timestamp ) ), self::HOOK, $args, true );
		if ( is_wp_error( $scheduled ) || ! $scheduled ) {
			update_post_meta( $post_id, self::META_STATUS, 'error' );
			delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );
			return false;
		}
		update_post_meta( $post_id, self::META_NEXT_ATTEMPT, max( time() + 1, absint( $timestamp ) ) );
		return true;
	}

	private static function unschedule( $post_id, $hash, $only_attempt = 0 ) {
		$attempts = $only_attempt ? array( absint( $only_attempt ) ) : range( 1, self::MAX_ATTEMPTS );
		foreach ( $attempts as $attempt ) {
			$args = array( absint( $post_id ), (string) $hash, $attempt );
			while ( $timestamp = wp_next_scheduled( self::HOOK, $args ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Each matching single event must be removed.
				wp_unschedule_event( $timestamp, self::HOOK, $args );
			}
		}
	}

	private static function api_key() {
		return trim( (string) ( Settings::get()['google_geocoding_api_key'] ?? '' ) );
	}

	private static function hash_is_current( $post_id, $hash ) {
		return $hash === (string) get_post_meta( $post_id, self::META_HASH, true ) && $hash === self::address_hash( Jobs::get_meta( $post_id ) );
	}

	private static function address_hash( array $meta ) {
		if ( 'remote' === $meta['workplace'] || empty( $meta['address_locality'] ) || empty( $meta['address_country'] ) ) {
			return '';
		}
		$address = array( $meta['address_street'], $meta['address_locality'], $meta['address_region'], $meta['postal_code'], $meta['address_country'] );

		return hash( 'sha256', wp_json_encode( $address ) );
	}

	private static function coordinate( $value, $minimum, $maximum ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		if ( ! is_finite( $value ) || $value < $minimum || $value > $maximum ) {
			return null;
		}

		return number_format( $value, 6, '.', '' );
	}

	private static function clear( $post_id, $status, $saved_hash = '' ) {
		if ( $saved_hash ) {
			self::unschedule( $post_id, $saved_hash );
		}
		delete_post_meta( $post_id, self::META_LATITUDE );
		delete_post_meta( $post_id, self::META_LONGITUDE );
		delete_post_meta( $post_id, self::META_COORDINATES );
		delete_post_meta( $post_id, self::META_HASH );
		delete_post_meta( $post_id, self::META_ATTEMPTS );
		delete_post_meta( $post_id, self::META_NEXT_ATTEMPT );
		update_post_meta( $post_id, self::META_STATUS, sanitize_key( $status ) );
	}

	private function __construct() {}
}
