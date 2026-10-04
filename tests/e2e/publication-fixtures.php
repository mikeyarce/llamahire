<?php
/** Provider inputs only; moderation is performed in the native WordPress editor. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local site required.' ); }
$fixture_action = $args[0] ?? '';
$registry = get_option( 'llamahire_e2e_flows', array() );
if ( 'setup' === $fixture_action ) {
	if ( $registry ) { throw new RuntimeException( 'Clean existing fixtures before publication setup.' ); }
	ob_start(); require __DIR__ . '/flow-fixtures.php'; ob_end_clean();
	$registry = get_option( 'llamahire_e2e_flows' );
	$registry['publication'] = wp_generate_uuid4();
	update_option( 'llamahire_e2e_flows', $registry, false );
	echo wp_json_encode( array( 'job' => $registry['jobs']['draft'], 'url' => add_query_arg( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'p' => $registry['jobs']['draft'] ), home_url( '/' ) ) ) );
} elseif ( 'cleanup' === $fixture_action ) {
	global $wpdb;
	if ( ! empty( $registry['publication'] ) ) {
		$id = $registry['jobs']['draft'];
		wp_clear_scheduled_hook( \LlamaHire\Job_Publication::RETRY, array( $id ) );
		foreach ( array( 'states', 'periods' ) as $kind ) { $wpdb->delete( \LlamaHire\Listing_Store::table( $kind ), array( 'job_id' => $id ) ); }
		unset( $registry['publication'] ); update_option( 'llamahire_e2e_flows', $registry, false );
	}
	require __DIR__ . '/flow-fixtures.php';
} else {
	if ( empty( $registry['publication'] ) ) { throw new RuntimeException( 'Publication fixture missing.' ); }
	$id = $registry['jobs']['draft'];
	$lifecycle = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::JOB_LIFECYCLE );
	if ( in_array( $fixture_action, array( 'pay', 'revoke' ), true ) ) {
		$registry['publication_paid'] = 'pay' === $fixture_action;
		update_option( 'llamahire_e2e_flows', $registry, false );
		wp_set_current_user( 0 ); $lifecycle->reconcile( $id );
	} elseif ( 'state' !== $fixture_action ) { throw new RuntimeException( 'Unknown publication fixture action.' ); }
	$context = $lifecycle->context( $id );
	echo wp_json_encode( array( 'status' => $context['status'], 'approved' => $context['approved'], 'expiry' => $context['listing_expires'], 'period' => $lifecycle->period( $id ) ) );
}
