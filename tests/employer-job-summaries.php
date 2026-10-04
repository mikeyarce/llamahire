<?php
/** Real WordPress contract checks, invoked by the disposable smoke suite. */

( static function ( $assert ) {
	$settings = get_option( \LlamaHire\Settings::OPTION, false );
	$actor = get_current_user_id();
	$users = array();
	$job_id = 0;
	$calls = array();
	$filter = static function ( $items, $context ) use ( &$calls ) {
		$calls[] = $context;
		return array(
			array( 'label' => '<script>status</script>', 'detail' => '<img src=x onerror=alert(1)>', 'action' => array( 'label' => '<b>Review payment</b>', 'url' => home_url( '/payment-review/?job=' . $context['job_id'] ) ), 'private_reference' => 'must-not-render' ),
			array( 'label' => 'Unsafe action omitted', 'action' => array( 'label' => 'Unsafe link', 'url' => 'javascript:alert(1)' ) ),
		);
	};
	try {
		update_option( \LlamaHire\Settings::OPTION, array_merge( \LlamaHire\Settings::defaults(), array( 'site_mode' => 'job_board' ) ) );
		foreach ( array( 'owner', 'other', 'manager' ) as $kind ) {
			$id = wp_insert_user( array( 'user_login' => 'summary-' . $kind . '-' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'manager' === $kind ? \LlamaHire\Capabilities::HIRING_MANAGER_ROLE : \LlamaHire\Capabilities::EMPLOYER_ROLE ) );
			$assert( ! is_wp_error( $id ), 'Summary fixture account created' );
			$users[ $kind ] = $id;
		}
		$job_id = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Summary contract fixture', 'post_author' => $users['owner'] ), true );
		$assert( ! is_wp_error( $job_id ), 'Summary fixture job created' );
		$renderer = new \LlamaHire\Employer_Job_Extensions( \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::EXTENSION_ACCESS ) );
		add_filter( 'llamahire_employer_job_summaries', $filter, 10, 2 );
		foreach ( array( 0, $users['other'] ) as $denied_user ) {
			wp_set_current_user( $denied_user );
			$assert( array() === $renderer->items( $job_id ), 'Anonymous and foreign users receive no extension summaries' );
		}
		$assert( array() === $calls, 'Denied jobs never invoke extension data callbacks' );
		wp_set_current_user( $users['owner'] );
		$items = $renderer->items( $job_id );
		$assert( 2 === count( $items ) && ! isset( $items[1]['action'] ), 'WordPress rejects executable action URLs while retaining explanatory text' );
		$assert( array( 'site_id' => get_current_blog_id(), 'mode' => 'job_board', 'job_id' => $job_id, 'owner_id' => $users['owner'] ) === $calls[0], 'Summary hook receives only the authorized current-site job binding' );
		ob_start();
		$renderer->render( $job_id );
		$html = ob_get_clean();
		$assert( false !== strpos( $html, '&lt;script&gt;status&lt;/script&gt;' ) && false === strpos( $html, '<script>' ) && false === strpos( $html, '<img' ), 'Real WordPress escaping renders extension HTML as inert text' );
		$assert( false === strpos( $html, 'javascript:' ) && false === strpos( $html, 'must-not-render' ) && false !== strpos( $html, '/payment-review/' ), 'Only safe links and allowlisted presentation fields are rendered' );
		$assert( 'draft' === get_post_status( $job_id ), 'Rendering payment status grants no publication rights' );
		wp_set_current_user( $users['manager'] );
		$assert( 2 === count( $renderer->items( $job_id ) ), 'Granular board manager access can read authorized job summaries' );
		$before = count( $calls );
		update_option( \LlamaHire\Settings::OPTION, array_merge( \LlamaHire\Settings::defaults(), array( 'site_mode' => 'company' ) ) );
		$assert( array() === $renderer->items( $job_id ) && $before === count( $calls ), 'Company mode invokes no board summary callbacks' );
		remove_filter( 'llamahire_employer_job_summaries', $filter, 10 );
		update_option( \LlamaHire\Settings::OPTION, array_merge( \LlamaHire\Settings::defaults(), array( 'site_mode' => 'job_board' ) ) );
		ob_start();
		$renderer->render( $job_id );
		$html = ob_get_clean();
		$assert( '' === $html, 'Removing the extension restores empty baseline markup' );
	} finally {
		remove_filter( 'llamahire_employer_job_summaries', $filter, 10 );
		if ( $job_id && ! is_wp_error( $job_id ) ) { wp_delete_post( $job_id, true ); }
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $id ) { wp_delete_user( $id ); }
		wp_set_current_user( $actor );
		if ( false === $settings ) { delete_option( \LlamaHire\Settings::OPTION ); } else { update_option( \LlamaHire\Settings::OPTION, $settings ); }
	}
} )( $assert );
