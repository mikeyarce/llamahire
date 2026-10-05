<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class SEO {
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'schema' ), 20 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
	}

	public static function schema() {
		if ( ! is_singular( Jobs::POST_TYPE ) ) { return; }
		$data = Plugin::instance()->services()->get( Service_IDs::SCHEMA_BUILDER )->build( get_queried_object_id() );
		if ( ! $data ) { return; }
		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON hex encoding prevents values from breaking out of the script element.
	}

	public static function title( $parts ) {
		if ( is_post_type_archive( Jobs::POST_TYPE ) ) { $parts['title'] = __( 'Open positions', 'llamahire' ); }
		return $parts;
	}
}
