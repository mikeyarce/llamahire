<?php
/** Commands for the isolated application extension browser suite. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable local site required.' ); }
global $wpdb;
$fixture_action = $args[0] ?? '';
$registry = get_option( 'llamahire_e2e_flows', array() );
$table = $wpdb->prefix . 'llamahire_extension_fixture';
if ( 'cleanup' === $fixture_action ) {
	if ( ! empty( $registry['application_extensions'] ) ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); }
	require __DIR__ . '/flow-fixtures.php';
} elseif ( 'setup' === $fixture_action ) {
	if ( $registry || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Clean existing fixtures before setting up extensions.' ); }
	ob_start();
	require __DIR__ . '/flow-fixtures.php';
	$output = json_decode( ob_get_clean(), true );
	$registry = get_option( 'llamahire_e2e_flows' );
	$registry['application_extensions'] = true;
	$block = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Extension block fixture', 'post_content' => '<!-- wp:llamahire/application-form {"jobId":' . (int) $registry['jobs']['open'] . '} /-->' ) );
	$registry['pages']['extension_block'] = $block;
	$output['block_url'] = get_permalink( $block );
	update_option( 'llamahire_e2e_flows', $registry, false );
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (application_id bigint NOT NULL, snapshot longtext NOT NULL, PRIMARY KEY (application_id))', $table ) );
	echo wp_json_encode( $output );
} elseif ( 'fail' === $fixture_action || 'recover' === $fixture_action ) {
	$registry['extension_fail'] = 'fail' === $fixture_action;
	update_option( 'llamahire_e2e_flows', $registry, false );
} elseif ( 'state' === $fixture_action ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT application_id FROM %i ORDER BY application_id', $table ) );
	$query = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_QUERY )->search( array( 'job_ids' => array_values( $registry['jobs'] ), 'per_page' => 100 ) );
	echo wp_json_encode( array( 'applications' => count( $query['items'] ), 'answers' => count( $ids ), 'id' => $ids ? (int) $ids[0] : 0, 'mail_count' => (int) get_option( 'llamahire_e2e_mail_count', 0 ) ) );
} else { throw new RuntimeException( 'Unknown extension fixture action.' ); }
