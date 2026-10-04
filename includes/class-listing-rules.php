<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/** Pure validation and calendar arithmetic for publication policy boundaries. */
final class Listing_Rules {
	/** Refuse aliasing invalid identities to another site, user or job. */
	public static function id( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $id ? null : $id;
	}

	/** Stable provider names and non-secret period references have independent namespaces. */
	public static function name( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $value );
	}

	/** UUID references prevent accidental inclusion of secrets or unbounded provider data. */
	public static function uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value );
	}

	/** Normalize one decision without coercing truthy strings or silently dropping fields. */
	public static function decision( $value, $provider ) {
		if ( null === $value ) { return null; }
		if ( ! self::name( $provider ) || ! is_array( $value ) || array_diff( array_keys( $value ), array( 'eligible', 'period_required', 'period' ) ) || ! is_bool( $value['eligible'] ?? null ) || ! is_bool( $value['period_required'] ?? null ) || ! array_key_exists( 'period', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid listing policy.' );
		}
		$period = $value['period'];
		if ( null === $period ) {
			if ( $value['eligible'] && $value['period_required'] ) { throw new \InvalidArgumentException( 'A listing period is required.' ); }
			return $value;
		}
		if ( ! $value['period_required'] || ! is_array( $period ) || array_diff( array_keys( $period ), array( 'id', 'predecessor', 'days' ) ) || ! self::uuid( $period['id'] ?? null ) || ! is_string( $period['predecessor'] ?? null ) || ( '' !== $period['predecessor'] && ! self::uuid( $period['predecessor'] ) ) || ! is_int( $period['days'] ?? null ) || $period['days'] < 1 || $period['days'] > 3650 || $period['id'] === $period['predecessor'] ) {
			throw new \InvalidArgumentException( 'Invalid listing period.' );
		}
		$value['period']['key'] = $provider . ':' . $period['id'];
		$value['period']['previous_key'] = '' === $period['predecessor'] ? '' : $provider . ':' . $period['predecessor'];
		return $value;
	}

	/** Calendar dates follow the site's timezone, including DST and non-hour offsets. */
	public static function expiry( \DateTimeImmutable $started, \DateTimeZone $timezone, $days ) {
		if ( ! is_int( $days ) || $days < 1 || $days > 3650 ) { throw new \InvalidArgumentException( 'Invalid listing duration.' ); }
		return $started->setTimezone( $timezone )->setTime( 0, 0 )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	private function __construct() {}
}
