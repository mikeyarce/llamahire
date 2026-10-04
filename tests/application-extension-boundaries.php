<?php
/** Real WordPress provider-boundary assertions. No candidate values are printed. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local site required.' ); }
$assert = static function ( $condition, $code ) { if ( ! $condition ) { throw new RuntimeException( $code ); } };
$provider = new class implements \LlamaHire\Contracts\Application_Extension {
	public $prepares = 0;
	public $reviews = 0;
	public $throws = false;
	public $omit = false;
	public function render( array $context, array $input, array $errors ) { return ''; }
	public function prepare( array $context, array $input ) { $this->prepares++; if ( $this->throws ) { throw new RuntimeException( 'PRIVATE_EXCEPTION_DETAIL' ); } return $this->omit ? null : array( 'accepted' => true ); }
	public function tables() { return array(); }
	public function persist( $id, array $context, array $prepared ) { return true; }
	public function review( $id ) { $this->reviews++; return array( 'title' => 'Boundary answers', 'fields' => array( array( 'label' => 'Historical label', 'value' => '<script>fixture</script>', 'type' => 'url' ) ) ); }
};
$owner = wp_create_user( 'extension-owner-' . wp_generate_uuid4(), wp_generate_password(), 'extension-owner-' . wp_generate_uuid4() . '@example.test' );
$user = new WP_User( $owner );
$user->set_role( 'administrator' );
$job = wp_insert_post( array( 'post_type' => 'llamahire_job', 'post_status' => 'draft', 'post_title' => 'Extension boundary fixture', 'post_author' => $owner ) );
$repository = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
$id = $repository->create( array( 'job_id' => $job, 'name' => 'Fictional boundary fixture', 'email' => 'extension-boundary@example.test' ) );
$register = static function ( $providers, $context ) use ( $job, $provider ) { if ( $context['job_id'] === $job ) { $providers['fixture'] = $provider; } return $providers; };
add_filter( 'llamahire_application_extensions', $register, 10, 2 );
try {
	foreach ( array( 'scalar', array( 'unknown' => array() ), array( 'fixture' => 'scalar' ), array( 'fixture' => array( 'answer' => str_repeat( 'a', 131073 ) ) ) ) as $input ) {
		$assert( is_wp_error( \LlamaHire\Application_Extensions::prepare( $job, $input ) ), 'Malformed/oversized payload was accepted.' );
	}
	$assert( 0 === $provider->prepares, 'Malformed payload reached provider validation.' );
	$assert( array( 'fixture' => array( 'accepted' => true ) ) === \LlamaHire\Application_Extensions::prepare( $job, array( 'fixture' => array() ) ), 'Validated provider output was not preserved.' );
	$provider->omit = true;
	$assert( array() === \LlamaHire\Application_Extensions::prepare( $job, array() ), 'Provider without required data did not preserve ordinary creation.' );
	$provider->omit = false;
	$provider->throws = true;
	$error = \LlamaHire\Application_Extensions::prepare( $job, array( 'fixture' => array() ) );
	$assert( is_wp_error( $error ) && false === strpos( $error->get_error_message(), 'PRIVATE_EXCEPTION_DETAIL' ), 'Validation exception was not safely contained.' );
	wp_set_current_user( 0 );
	$assert( array() === \LlamaHire\Application_Extensions::review( $id ) && 0 === $provider->reviews, 'Anonymous review invoked a provider.' );
	wp_set_current_user( $owner );
	$html = \LlamaHire\Application_Extensions::render_review( $id );
	$assert( 1 === $provider->reviews && false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ) && false === strpos( $html, 'href=' ), 'Historical values were not escaped as plaintext.' );
	echo "Extension payload, exception, authorization and escaping boundaries passed.\n";
} finally {
	remove_filter( 'llamahire_application_extensions', $register, 10 );
	wp_set_current_user( 0 );
	$repository->delete( $id );
	wp_delete_post( $job, true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $owner );
}
