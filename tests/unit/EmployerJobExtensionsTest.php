<?php
use LlamaHire\Contracts\Extension_Access;
use LlamaHire\Employer_Job_Extensions;

final class EmployerJobExtensionsTest extends PHPUnit\Framework\TestCase {
	private $access;
	private $summaries;
	private $context = array( 'site_id' => 3, 'mode' => 'job_board', 'job_id' => 11, 'owner_id' => 7 );

	protected function setUp(): void {
		$GLOBALS['unit_filters'] = array();
		$this->access = $this->createMock( Extension_Access::class );
		$this->summaries = new Employer_Job_Extensions( $this->access );
	}

	protected function tearDown(): void {
		$GLOBALS['unit_filters'] = array();
	}

	public function test_denied_job_never_calls_extension_or_reads_its_payment_data(): void {
		$this->access->expects( $this->once() )->method( 'job_context' )->with( 11 )->willReturn( null );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = function () { $this->fail( 'Denied job reached extension callback.' ); };
		$this->assertSame( array(), $this->summaries->items( 11 ) );
	}

	public function test_company_mode_does_not_call_board_extension(): void {
		$this->access->method( 'job_context' )->willReturn( array_merge( $this->context, array( 'mode' => 'company' ) ) );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = function () { $this->fail( 'Company job reached board callback.' ); };
		$this->assertSame( array(), $this->summaries->items( 11 ) );
	}

	public function test_callback_gets_authorized_context_and_cannot_add_arbitrary_output_fields(): void {
		$this->access->method( 'job_context' )->willReturn( $this->context );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = function ( $items, $context ) {
			$this->assertSame( array(), $items );
			$this->assertSame( $this->context, $context );
			return array( array( 'label' => ' Awaiting payment ', 'detail' => 'Review before paying', 'private_reference' => 'must-not-render', 'action' => array( 'label' => 'Review payment', 'url' => 'https://example.test/payment?job=11', 'onclick' => 'must-not-render' ) ) );
		};
		$this->assertSame( array( array( 'label' => 'Awaiting payment', 'detail' => 'Review before paying', 'action' => array( 'label' => 'Review payment', 'url' => 'https://example.test/payment?job=11' ) ) ), $this->summaries->items( 11 ) );
	}

	public function test_no_extension_preserves_empty_presentation(): void {
		$this->access->method( 'job_context' )->willReturn( $this->context );
		$this->assertSame( array(), $this->summaries->items( 11 ) );
	}

	/** @dataProvider invalid_results */
	public function test_malformed_callback_results_fail_closed( $value ): void {
		$this->access->method( 'job_context' )->willReturn( $this->context );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = static function () use ( $value ) { return $value; };
		$this->assertSame( array(), $this->summaries->items( 11 ) );
	}

	public function invalid_results(): array {
		return array( array( null ), array( false ), array( 'unexpected' ), array( new stdClass() ), array( array( null, array( 'label' => array( 'invalid' ) ), array( 'label' => '   ' ) ) ) );
	}

	public function test_items_and_unicode_text_are_bounded(): void {
		$this->access->method( 'job_context' )->willReturn( $this->context );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = static function () {
			return array_fill( 0, 10, array( 'label' => str_repeat( 'é', 81 ), 'detail' => str_repeat( '界', 241 ), 'action' => array( 'label' => str_repeat( 'é', 81 ), 'url' => 'https://example.test/' ) ) );
		};
		$items = $this->summaries->items( 11 );
		$this->assertCount( 3, $items );
		$this->assertSame( str_repeat( 'é', 80 ), $items[0]['label'] );
		$this->assertSame( str_repeat( '界', 240 ), $items[0]['detail'] );
		$this->assertSame( str_repeat( 'é', 80 ), $items[0]['action']['label'] );
	}

	/** @dataProvider unsafe_urls */
	public function test_unsafe_actions_are_omitted_without_hiding_status( $url ): void {
		$this->access->method( 'job_context' )->willReturn( $this->context );
		$GLOBALS['unit_filters']['llamahire_employer_job_summaries'] = static function () use ( $url ) {
			return array( array( 'label' => 'Awaiting payment', 'action' => array( 'label' => 'Review payment', 'url' => $url ) ) );
		};
		$this->assertSame( array( array( 'label' => 'Awaiting payment', 'detail' => '' ) ), $this->summaries->items( 11 ) );
	}

	public function unsafe_urls(): array {
		return array( array( 'javascript:alert(1)' ), array( 'data:text/html,unsafe' ), array( 'mailto:person@example.test' ), array( '//example.test/payment' ), array( '/payment' ), array( 'https://user:password@example.test/' ), array( "https://example.test/\nunsafe" ), array( array() ), array( 'https://example.test/' . str_repeat( 'x', 2048 ) ) );
	}
}
