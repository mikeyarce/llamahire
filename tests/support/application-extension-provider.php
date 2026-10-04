<?php
/** A real local extension used only by the disposable browser contract suite. */
final class LlamaHire_Application_Extension_Fixture implements \LlamaHire\Contracts\Application_Extension {
	public function render( array $context, array $input, array $errors ) {
		$value = isset( $input['answer'] ) && is_string( $input['answer'] ) ? $input['answer'] : '';
		$error = $errors['answer'] ?? $errors['version'] ?? '';
		return '<label for="fixture-answer">Portfolio reference<input id="fixture-answer" name="llamahire_extensions[fixture][answer]" value="' . esc_attr( $value ) . '" aria-describedby="fixture-answer-error" aria-invalid="' . ( $error ? 'true' : 'false' ) . '"></label><p id="fixture-answer-error" data-llamahire-field-error>' . esc_html( $error ) . '</p><input type="hidden" name="llamahire_extensions[fixture][version]" value="current">';
	}
	public function prepare( array $context, array $input ) {
		if ( array_diff( array_keys( $input ), array( 'answer', 'version' ) ) || 'current' !== ( $input['version'] ?? '' ) ) { return new WP_Error( 'version', 'Reload the current questions before applying.' ); }
		if ( ! isset( $input['answer'] ) || ! is_string( $input['answer'] ) || strlen( $input['answer'] ) > 200 || ! filter_var( $input['answer'], FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $input['answer'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) { return new WP_Error( 'answer', 'Enter a complete HTTP or HTTPS portfolio URL.' ); }
		return array( 'title' => 'Historical extra answers', 'fields' => array( array( 'label' => 'Original portfolio question', 'value' => $input['answer'], 'type' => 'url' ) ) );
	}
	public function tables() { global $wpdb; return array( $wpdb->prefix . 'llamahire_extension_fixture' ); }
	public function persist( $application_id, array $context, array $prepared ) {
		global $wpdb;
		$written = $wpdb->insert( $this->tables()[0], array( 'application_id' => $application_id, 'snapshot' => wp_json_encode( $prepared ) ) );
		$registry = get_option( 'llamahire_e2e_flows' );
		return 1 === $written && empty( $registry['extension_fail'] );
	}
	public function review( $application_id ) {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot FROM %i WHERE application_id = %d', $this->tables()[0], $application_id ) );
		return $json ? json_decode( $json, true ) : array( 'title' => 'Historical extra answers', 'fields' => array() );
	}
}
add_filter( 'llamahire_application_extensions', static function ( $extensions, $context ) {
	$registry = get_option( 'llamahire_e2e_flows', array() );
	if ( ! empty( $registry['application_extensions'] ) && in_array( $context['job_id'], array_values( $registry['jobs'] ), true ) ) { $extensions['fixture'] = new LlamaHire_Application_Extension_Fixture(); }
	return $extensions;
}, 10, 2 );
