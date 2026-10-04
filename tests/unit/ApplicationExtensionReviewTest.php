<?php
use LlamaHire\Application_Extensions;

final class ApplicationExtensionReviewTest extends PHPUnit\Framework\TestCase {
	public function test_historical_labels_and_multiline_answers_remain_plain_data(): void {
		$result = Application_Extensions::normalize_review( array( 'title' => 'Original form', 'fields' => array( array( 'label' => 'Original question', 'value' => "First line\nSecond line <script>" ) ) ) );
		$this->assertSame( 'Original question', $result['fields'][0]['label'] );
		$this->assertSame( "First line\nSecond line <script>", $result['fields'][0]['value'] );
		$this->assertSame( '', $result['fields'][0]['url'] );
	}

	/** @dataProvider urls */
	public function test_only_absolute_http_urls_become_links( $value, $allowed ): void {
		$result = Application_Extensions::normalize_review( array( 'title' => 'Answers', 'fields' => array( array( 'label' => 'Portfolio', 'value' => $value, 'type' => 'url' ) ) ) );
		$this->assertSame( $allowed ? $value : '', $result['fields'][0]['url'] );
	}

	public function urls(): array {
		return array( array( 'https://example.test/work', true ), array( 'http://example.test/work', true ), array( 'javascript:alert(1)', false ), array( 'data:text/html,unsafe', false ), array( '//example.test/work', false ), array( '/relative', false ), array( "https://example.test/\nunsafe", false ) );
	}

	/** @dataProvider malformed */
	public function test_malformed_or_unbounded_review_data_is_rejected( $section ): void {
		$this->expectException( UnexpectedValueException::class );
		Application_Extensions::normalize_review( $section );
	}

	public function malformed(): array {
		return array(
			array( 'not a section' ),
			array( array( 'title' => str_repeat( 'a', 201 ), 'fields' => array() ) ),
			array( array( 'title' => 'Answers', 'fields' => array_fill( 0, 11, array( 'label' => 'Q', 'value' => 'A' ) ) ) ),
			array( array( 'title' => 'Answers', 'fields' => array( array( 'label' => 'Q', 'value' => str_repeat( 'é', 2049 ) ) ) ) ),
			array( array( 'title' => 'Answers', 'fields' => array( array( 'label' => array(), 'value' => 'A' ) ) ) ),
			array( array( 'title' => 'Answers', 'fields' => array( array( 'label' => 'Q', 'value' => "\xff" ) ) ) ),
		);
	}

	public function test_empty_answers_and_absent_provider_have_distinct_states(): void {
		$this->assertNull( Application_Extensions::normalize_review( null ) );
		$this->assertSame( array(), Application_Extensions::normalize_review( array( 'title' => 'Answers', 'fields' => array() ) )['fields'] );
	}
}
