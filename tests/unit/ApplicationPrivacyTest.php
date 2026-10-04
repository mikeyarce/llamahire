<?php
use LlamaHire\Capabilities;
use LlamaHire\Service_IDs;

final class ApplicationPrivacyTest extends PHPUnit\Framework\TestCase {
	private $access;
	private $query;
	private $privacy;

	protected function setUp(): void {
		$GLOBALS['unit_user_id'] = 7;
		$GLOBALS['unit_site_id'] = 3;
		$GLOBALS['unit_users'] = array( 7 => array( 'caps' => array( 'export_others_personal_data' => true, 'erase_others_personal_data' => true ) ) );
		$GLOBALS['unit_jobs'] = array( 11 => (object) array( 'ID' => 11, 'post_author' => 7 ) );
		$GLOBALS['wpdb'] = new class {
			public $last_error = '';
			public $suppressed = false;
			public function suppress_errors( $value = true ) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
		};
		$this->access = $this->createMock( \LlamaHire\Contracts\Extension_Access::class );
		$this->query = $this->createMock( \LlamaHire\Contracts\Application_Query::class );
		$services = new \LlamaHire\Service_Container();
		$services->set( Service_IDs::EXTENSION_ACCESS, $this->access );
		$services->set( Service_IDs::APPLICATION_QUERY, $this->query );
		$this->privacy = new \LlamaHire\Services\Application_Privacy( $services );
	}

	public function test_core_and_granular_privacy_permissions_are_both_required() {
		$this->query->expects( $this->never() )->method( 'search' );
		$GLOBALS['unit_users'][7]['caps']['export_others_personal_data'] = false;
		$this->assertInstanceOf( WP_Error::class, $this->privacy->references( 'fictional@example.test', 'export' ) );
		$GLOBALS['unit_users'][7]['caps']['export_others_personal_data'] = true;
		$this->access->method( 'application_scope' )->with( Capabilities::EXPORT_APPLICATIONS )->willReturn( null );
		$this->assertInstanceOf( WP_Error::class, $this->privacy->references( 'fictional@example.test', 'export' ) );
	}

	public function test_invalid_identity_operation_and_page_never_query() {
		$this->query->expects( $this->never() )->method( 'search' );
		foreach ( array( array( 'fictional', 'export', 1 ), array( 'fictional@example.test', 'view', 1 ), array( 'fictional@example.test', 'erase', 0 ), array( 'fictional@example.test', 'erase', true ), array( 'fictional@example.test', 'erase', '1' ) ) as $args ) { $this->assertInstanceOf( WP_Error::class, $this->privacy->references( ...$args ) ); }
	}

	public function test_exact_identity_scope_and_record_access_return_only_numeric_references() {
		$this->access->method( 'application_scope' )->willReturn( array( 'author_id' => 7 ) );
		$this->access->expects( $this->once() )->method( 'can_access_application' )->with( 20, Capabilities::EXPORT_APPLICATIONS )->willReturn( true );
		$this->query->expects( $this->once() )->method( 'search' )->with( array( 'search' => 'fictional@example.test', 'author_id' => 7, 'page' => 2, 'per_page' => 100, 'orderby' => 'received', 'order' => 'asc' ) )->willReturn( array( 'items' => array( (object) array( 'id' => '20', 'job_id' => '11', 'email' => 'fictional@example.test', 'name' => 'Fictional private value' ) ) ) );
		$this->assertSame( array( 'site_id' => 3, 'items' => array( array( 'application_id' => 20, 'job_id' => 11 ) ), 'done' => true ), $this->privacy->references( 'FICTIONAL@EXAMPLE.TEST', 'export', 2 ) );
		$this->assertFalse( $GLOBALS['wpdb']->suppressed );
	}

	public function test_orphan_permission_is_exclusive_to_board_wide_privacy_scope() {
		$this->access->method( 'application_scope' )->willReturnOnConsecutiveCalls( array( 'author_id' => 7 ), array( 'author_id' => 0 ) );
		$this->query->method( 'search' )->willReturn( array( 'items' => array( (object) array( 'id' => 20, 'job_id' => 999, 'email' => 'fictional@example.test' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->privacy->references( 'fictional@example.test', 'erase' ) );
		$this->assertSame( array( array( 'application_id' => 20, 'job_id' => 999 ) ), $this->privacy->references( 'fictional@example.test', 'erase' )['items'] );
	}

	public function test_overbroad_custom_query_and_denied_records_fail_closed() {
		$this->access->method( 'application_scope' )->willReturn( array( 'author_id' => 7 ) );
		$this->access->method( 'can_access_application' )->willReturn( false );
		$this->query->method( 'search' )->willReturnOnConsecutiveCalls( array( 'items' => array( (object) array( 'id' => 20, 'job_id' => 11, 'email' => 'another@example.test' ) ) ), array( 'items' => array( (object) array( 'id' => 20, 'job_id' => 11, 'email' => 'fictional@example.test' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->privacy->references( 'fictional@example.test', 'export' ) );
		$this->assertInstanceOf( WP_Error::class, $this->privacy->references( 'fictional@example.test', 'export' ) );
	}

	public function test_full_batches_require_another_page_and_never_trust_total_counts() {
		$this->access->method( 'application_scope' )->willReturn( array( 'author_id' => 0 ) );
		$this->access->method( 'can_access_application' )->willReturn( true );
		$items = array();
		for ( $id = 1; $id <= 100; ++$id ) { $items[] = (object) array( 'id' => $id, 'job_id' => 11, 'email' => 'fictional@example.test' ); }
		$this->query->method( 'search' )->willReturnOnConsecutiveCalls( array( 'items' => $items, 'pages' => 1 ), array( 'items' => array() ) );
		$first = $this->privacy->references( 'fictional@example.test', 'export' );
		$this->assertFalse( $first['done'] ); $this->assertCount( 100, $first['items'] );
		$this->assertTrue( $this->privacy->references( 'fictional@example.test', 'export', 2 )['done'] );
	}

	public function test_database_failure_is_not_an_empty_successful_export() {
		$this->access->method( 'application_scope' )->willReturn( array( 'author_id' => 0 ) );
		$this->query->method( 'search' )->willReturnCallback( static function () { $GLOBALS['wpdb']->last_error = 'Fictional database failure'; return array( 'items' => array() ); } );
		$this->assertSame( 'llamahire_privacy_unavailable', $this->privacy->references( 'fictional@example.test', 'export' )->get_error_code() );
		$this->assertFalse( $GLOBALS['wpdb']->suppressed );
	}
}
