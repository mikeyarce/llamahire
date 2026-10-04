<?php
use LlamaHire\Listing_Rules;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-listing-rules.php';

final class ListingRulesTest extends TestCase {
	private function decision() {
		return array( 'eligible' => true, 'period_required' => true, 'period' => array( 'id' => '12345678-1234-4234-8234-123456789abc', 'predecessor' => '', 'days' => 30 ) );
	}

	public function test_unmanaged_policy_and_unpaid_managed_policy_are_distinct() {
		$this->assertNull( Listing_Rules::decision( null, 'payments' ) );
		$waiting = array( 'eligible' => false, 'period_required' => true, 'period' => null );
		$this->assertSame( $waiting, Listing_Rules::decision( $waiting, 'payments' ) );
	}

	public function test_period_identity_is_namespaced_and_binds_its_predecessor() {
		$value = $this->decision();
		$value['period']['predecessor'] = '87654321-1234-4234-8234-123456789abc';
		$first = Listing_Rules::decision( $value, 'payments' );
		$other = Listing_Rules::decision( $value, 'another_provider' );
		$this->assertSame( 'payments:12345678-1234-4234-8234-123456789abc', $first['period']['key'] );
		$this->assertSame( 'payments:87654321-1234-4234-8234-123456789abc', $first['period']['previous_key'] );
		$this->assertNotSame( $first['period']['key'], $other['period']['key'] );
	}

	/** @dataProvider malformedDecisions */
	public function test_malformed_decisions_never_become_an_allow( $change, $provider = 'payments' ) {
		$value = array_replace_recursive( $this->decision(), $change );
		$this->expectException( InvalidArgumentException::class );
		Listing_Rules::decision( $value, $provider );
	}

	public function malformedDecisions() {
		return array(
			'boolean coercion' => array( array( 'eligible' => 'true' ) ),
			'period flag coercion' => array( array( 'period_required' => 1 ) ),
			'unknown fields' => array( array( 'payload' => array() ) ),
			'no required period' => array( array( 'period' => null ) ),
			'period without ownership' => array( array( 'period_required' => false ) ),
			'zero duration' => array( array( 'period' => array( 'days' => 0 ) ) ),
			'excess duration' => array( array( 'period' => array( 'days' => 3651 ) ) ),
			'fractional duration' => array( array( 'period' => array( 'days' => 1.5 ) ) ),
			'text duration' => array( array( 'period' => array( 'days' => '30' ) ) ),
			'arbitrary reference' => array( array( 'period' => array( 'id' => 'provider-secret-value' ) ) ),
			'self predecessor' => array( array( 'period' => array( 'predecessor' => '12345678-1234-4234-8234-123456789abc' ) ) ),
			'foreign namespace in predecessor' => array( array( 'period' => array( 'predecessor' => 'another:12345678-1234-4234-8234-123456789abc' ) ) ),
			'unbounded provider name' => array( array(), str_repeat( 'a', 65 ) ),
		);
	}

	/** @dataProvider calendarCases */
	public function test_expiry_uses_local_calendar_dates_across_dst_and_date_boundaries( $utc, $timezone, $days, $date ) {
		$this->assertSame( $date, Listing_Rules::expiry( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ), new DateTimeZone( $timezone ), $days ) );
	}

	public function calendarCases() {
		return array(
			'spring DST' => array( '2026-03-08 06:30:00', 'America/New_York', 1, '2026-03-09' ),
			'fall DST' => array( '2026-11-01 05:30:00', 'America/New_York', 1, '2026-11-02' ),
			'previous local date' => array( '2026-01-01 01:00:00', 'America/Los_Angeles', 1, '2026-01-01' ),
			'non-hour offset' => array( '2026-01-01 19:00:00', 'Asia/Kathmandu', 30, '2026-02-01' ),
			'leap year' => array( '2028-02-28 23:00:00', 'UTC', 1, '2028-02-29' ),
		);
	}

	public function test_invalid_ids_cannot_alias_another_owner_or_site() {
		foreach ( array( 0, -1, true, false, null, 1.2, '1.2', '-1', '9999999999999999999999999999' ) as $id ) { $this->assertNull( Listing_Rules::id( $id ) ); }
		$this->assertSame( 12, Listing_Rules::id( '12' ) );
	}
}
