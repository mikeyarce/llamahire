<?php
/** Focused review regressions. Run only in the disposable WordPress test site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

( static function () {
	global $wpdb;
	$assert = static function ( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};
	$original_user = get_current_user_id();
	$original_get = $_GET;
	$jobs = array();
	$applications = array();
	$user_id = 0;
	$attachment_id = 0;
	$path = '';
	$repo = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
	$settings = \LlamaHire\Settings::get();
	$settings['site_mode'] = 'company';
	$settings['google_geocoding_api_key'] = '';
	$settings_filter = static function () use ( $settings ) { return $settings; };
	$mail_filter = static function () { return false; };
	add_filter( 'pre_option_' . \LlamaHire\Settings::OPTION, $settings_filter );
	add_filter( 'pre_wp_mail', $mail_filter );
	try {
		$user_id = wp_insert_user( array( 'user_login' => 'review-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => \LlamaHire\Capabilities::EMPLOYER_ROLE ) );
		$assert( ! is_wp_error( $user_id ), 'Create regression employer' );
		wp_set_current_user( $user_id );
		for ( $i = 0; $i < 2; $i++ ) {
			$jobs[] = wp_insert_post( array( 'post_type' => \LlamaHire\Jobs::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Review regression job', 'post_author' => $user_id ) );
		}

		$key = wp_generate_uuid4();
		$allow_duplicates = static function () { return 'allow'; };
		add_filter( 'llamahire_duplicate_application_policy', $allow_duplicates );
		try {
			$data = array( 'job_id' => $jobs[0], 'name' => 'Review Fixture', 'email' => 'review-a@example.test', 'submission_key' => $key );
			$first = $repo->create_once( $data );
			$applications[] = $first['id'];
			$retry = $repo->create_once( array_merge( $data, array( 'email' => strtoupper( $data['email'] ) ) ) );
			$assert( $first['created'] && ! $retry['created'] && $first['id'] === $retry['id'], 'Same applicant retries remain idempotent when duplicate applications are allowed' );
			foreach ( array( array( 'email' => 'review-b@example.test' ), array( 'job_id' => $jobs[1] ) ) as $change ) {
				$other = $repo->create_once( array_merge( $data, $change ) );
				$applications[] = $other['id'];
				$assert( $other['created'] && $other['id'] !== $first['id'], 'Cached form keys do not discard a different applicant or job' );
			}
			// Simulate the unscoped key format saved before this fix.
			$wpdb->update( \LlamaHire\Applications::table(), array( 'submission_key' => $key ), array( 'id' => $first['id'] ) );
			$legacy = $repo->create_once( $data );
			$assert( ! $legacy['created'] && $legacy['id'] === $first['id'], 'Legacy keys still recognize retries for the original applicant' );
			$other = $repo->create_once( array_merge( $data, array( 'email' => 'review-c@example.test' ) ) );
			$applications[] = $other['id'];
			$assert( $other['created'], 'A legacy cached key does not suppress a different applicant' );
		} finally {
			remove_filter( 'llamahire_duplicate_application_policy', $allow_duplicates );
		}

		// REST must reject protected changes before modifying any part of the post.
		foreach ( array( array( 'featured' => '1' ), array( 'listing_expires' => '2099-12-31' ), null ) as $input ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $jobs[0] );
			$request->set_param( 'title', 'This title must not be saved' );
			$request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => $input ) );
			$response = rest_do_request( $request );
			$assert( 403 === $response->get_status() && 'Review regression job' === get_the_title( $jobs[0] ), 'Employer REST cannot change or clear operator metadata, including partial post writes' );
		}
		$request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job' );
		$request->set_param( 'title', 'Forbidden featured creation' );
		$request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => array( 'featured' => '1' ) ) );
		$assert( 403 === rest_do_request( $request )->get_status(), 'Employer REST creation cannot set operator fields' );
		\LlamaHire\Jobs::set_meta( $jobs[0], array( 'featured' => '1', 'listing_expires' => '2030-01-01' ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $jobs[0] );
		$request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => array( 'organization_name' => 'Updated company' ) ) );
		$response = rest_do_request( $request );
		$meta = \LlamaHire\Jobs::get_meta( $jobs[0] );
		$assert( 200 === $response->get_status() && 'Updated company' === $meta['organization_name'] && '1' === $meta['featured'] && '2030-01-01' === $meta['listing_expires'], 'Allowed partial employer saves preserve protected fields' );
		$managers = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
		$assert( ! empty( $managers ), 'Test site provides a board manager' );
		wp_set_current_user( $managers[0] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/llamahire_job/' . $jobs[0] );
		$request->set_param( 'meta', array( \LlamaHire\Jobs::META_KEY => array( 'featured' => '0', 'listing_expires' => '2031-01-01' ) ) );
		$assert( 200 === rest_do_request( $request )->get_status() && '2031-01-01' === \LlamaHire\Jobs::get_meta( $jobs[0] )['listing_expires'], 'Board managers can still edit operator fields' );

		$vip = new \LlamaHire\Services\VIP_ACL_Resume_Storage();
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . 'llamahire-private';
		wp_mkdir_p( $directory );
		$path = $directory . '/' . wp_generate_uuid4() . '.pdf';
		file_put_contents( $path, '%PDF-1.4 synthetic attachment regression' );
		$attach = new ReflectionMethod( $vip, 'create_attachment' );
		$attach->setAccessible( true );
		$attachment_id = $attach->invoke( $vip, $path, 'application/pdf' );
		$assert( ! is_wp_error( $attachment_id ) && $attachment_id && file_exists( $path ) && '1' === get_post_meta( $attachment_id, $vip::MARKER_META, true ), 'VIP keeps a successfully created attachment and its private file' );
		$assert( false === update_attached_file( $attachment_id, $path ), 'An identical attachment-path update is a no-op, not a failed upload' );
		$assert( $vip->delete( $vip::TOKEN_PREFIX . $attachment_id ) && ! file_exists( $path ), 'VIP attachment tokens remain deletable' );
		$attachment_id = 0;

		$storage = new class implements \LlamaHire\Contracts\Resume_Storage {
			public $deletions = 0;
			public function store_upload( array $file, $job_id ) { return array( 'token' => 'review:replacement', 'name' => 'fixture.pdf' ); }
			public function delete( $token ) { ++$this->deletions; return true; }
			public function has_resume( $id ) { return true; }
			public function stream( $id ) { return new WP_Error( 'fixture' ); }
			public function health() { return array(); }
		};
		$replacement_repo = new \LlamaHire\Services\Application_Repository();
		$captured = null;
		$register_replacements = static function ( $container ) use ( $storage, $replacement_repo, &$captured ) {
			$captured = $container->get( \LlamaHire\Service_IDs::CANDIDATE_DATA );
			$container->set( \LlamaHire\Service_IDs::RESUME_STORAGE, $storage );
			$container->set( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY, $replacement_repo );
		};
		$registration = new ReflectionMethod( \LlamaHire\Plugin::class, 'register_services' );
		$registration->setAccessible( true );
		$plugin = new \LlamaHire\Plugin();
		add_action( 'llamahire_register_services', $register_replacements );
		try {
			$registration->invoke( $plugin );
		} finally {
			remove_action( 'llamahire_register_services', $register_replacements );
		}
		$property = new ReflectionProperty( $captured, 'applications' );
		$property->setAccessible( true );
		$assert( $property->getValue( $captured ) === $replacement_repo, 'Lifecycle resolves the final replacement repository' );
		$id = $repo->create( array( 'job_id' => $jobs[0], 'name' => 'Review Fixture', 'email' => 'review-storage@example.test', 'resume_token' => 'review:original' ) );
		$applications[] = $id;
		$assert( true === $captured->replace_resume( $id, array() ) && 1 === $storage->deletions, 'A captured default lifecycle uses replacement storage to replace a resume' );
		$assert( true === $captured->erase( $id ) && 2 === $storage->deletions && ! $repo->find( $id ), 'Erasure uses replacement storage and removes the application' );
		$custom_lifecycle = static function ( $container ) use ( $captured ) { $container->set( \LlamaHire\Service_IDs::CANDIDATE_DATA, $captured ); };
		add_action( 'llamahire_register_services', $custom_lifecycle );
		try {
			$registration->invoke( $plugin );
		} finally {
			remove_action( 'llamahire_register_services', $custom_lifecycle );
		}
		$assert( $captured === $plugin->services()->get( \LlamaHire\Service_IDs::CANDIDATE_DATA ), 'An explicit lifecycle replacement is preserved' );

		for ( $i = 0; $i < 101; $i++ ) {
			$id = $repo->create( array( 'job_id' => $jobs[0], 'name' => 'Pagination Fixture ' . $i, 'email' => 'pagination-' . $i . '@example.test', 'status' => 0 === $i ? 'offer' : 'new' ) );
			$applications[] = $id;
		}
		$_GET = array( 'job_id' => $jobs[0], 'candidate' => 'Pagination Fixture' );
		ob_start();
		\LlamaHire\Admin_Workspaces::render_hiring();
		$first_page = ob_get_clean();
		$assert( 100 === substr_count( $first_page, 'data-candidate-id=' ) && false !== strpos( $first_page, 'of 101 candidates' ) && false !== strpos( $first_page, 'hiring_page=2' ), 'The first pipeline page is bounded and exposes the complete total and next page' );
		$assert( 1 === preg_match( '/data-stage="offer".*?data-stage-count>1</s', $first_page ) && false !== strpos( $first_page, 'No candidates on this page' ), 'Stage totals include candidates on other pages without claiming the stage is empty' );
		$_GET['hiring_page'] = 2;
		ob_start();
		\LlamaHire\Admin_Workspaces::render_hiring();
		$second_page = ob_get_clean();
		$assert( 1 === substr_count( $second_page, 'data-candidate-id=' ) && false !== strpos( $second_page, 'Pagination Fixture 0' ) && false !== strpos( $second_page, 'hiring_page=2' ), 'The second pipeline page reaches older candidates and keeps the page in review links' );
		$_GET['hiring_page'] = 999;
		ob_start();
		\LlamaHire\Admin_Workspaces::render_hiring();
		$assert( false !== strpos( ob_get_clean(), 'Pagination Fixture 0' ), 'Out-of-range pipeline pages clamp to the last available page' );
		WP_CLI::success( 'Review regressions passed: cached submissions, VIP attachments, service replacement, REST policy, and pipeline pagination.' );
	} finally {
		foreach ( $applications as $id ) {
			if ( is_numeric( $id ) ) { $repo->delete( $id ); }
		}
		foreach ( $jobs as $id ) {
			$wpdb->delete( \LlamaHire\Audit_Log::table(), array( 'job_id' => $id ) );
			wp_delete_post( $id, true );
		}
		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) { wp_delete_attachment( $attachment_id, true ); }
		if ( $path && file_exists( $path ) ) { wp_delete_file( $path ); }
		if ( $user_id && ! is_wp_error( $user_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		wp_set_current_user( $original_user );
		$_GET = $original_get;
		remove_filter( 'pre_option_' . \LlamaHire\Settings::OPTION, $settings_filter );
		remove_filter( 'pre_wp_mail', $mail_filter );
	}
} )();
