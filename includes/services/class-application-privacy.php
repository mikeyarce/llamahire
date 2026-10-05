<?php
namespace LlamaHire\Services;

use LlamaHire\Capabilities;
use LlamaHire\Service_IDs;

defined( 'ABSPATH' ) || exit;

/** Privacy-only boundary; normal candidate access never inherits orphan permission. */
final class Application_Privacy implements \LlamaHire\Contracts\Application_Privacy {
	private $services;

	public function __construct( \LlamaHire\Contracts\Service_Container $services ) {
		$this->services = $services;
	}

	public function references( $email_address, $operation, $page = 1 ) {
		$capabilities = array( 'export' => Capabilities::EXPORT_APPLICATIONS, 'erase' => Capabilities::ERASE_APPLICATIONS );
		if ( ! is_string( $operation ) || ! isset( $capabilities[ $operation ] ) || ! current_user_can( $operation . '_others_personal_data' ) ) { return $this->failure( 'llamahire_privacy_denied' ); }
		if ( ! is_string( $email_address ) || strlen( $email_address ) > 254 || ! is_email( $email_address ) || ! is_int( $page ) || $page < 1 || $page > intdiv( PHP_INT_MAX, 100 ) ) { return $this->failure( 'llamahire_privacy_invalid' ); }
		try {
			$access = $this->services->get( Service_IDs::EXTENSION_ACCESS );
			$scope = $access->application_scope( $capabilities[ $operation ] );
		} catch ( \Throwable $error ) { return $this->failure(); }
		if ( ! is_array( $scope ) || ! isset( $scope['author_id'] ) || ! is_int( $scope['author_id'] ) || $scope['author_id'] < 0 ) { return $this->failure( 'llamahire_privacy_denied' ); }
		$email = strtolower( $email_address );
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			$wpdb->last_error = '';
			$result = $this->services->get( Service_IDs::APPLICATION_QUERY )->search( array( 'search' => $email, 'author_id' => $scope['author_id'], 'page' => $page, 'per_page' => 100, 'orderby' => 'received', 'order' => 'asc' ) );
			if ( '' !== $wpdb->last_error || ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) || count( $result['items'] ) > 100 ) { return $this->failure(); }
			$items = array();
			foreach ( $result['items'] as $application ) {
				if ( ! is_object( $application ) || ! isset( $application->id, $application->job_id, $application->email ) || ! is_string( $application->email ) || strtolower( $application->email ) !== $email ) { return $this->failure(); }
				if ( ( ! is_int( $application->id ) && ! is_string( $application->id ) ) || ( ! is_int( $application->job_id ) && ! is_string( $application->job_id ) ) ) { return $this->failure(); }
				$id = filter_var( $application->id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				$job_id = filter_var( $application->job_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id || false === $job_id ) { return $this->failure(); }
				$job = get_post( $job_id );
				if ( '' !== $wpdb->last_error ) { return $this->failure(); }
				if ( $job ) {
					if ( ( $scope['author_id'] && $scope['author_id'] !== (int) $job->post_author ) || ! $access->can_access_application( $id, $capabilities[ $operation ] ) ) { return $this->failure( 'llamahire_privacy_denied' ); }
				} elseif ( 0 !== $scope['author_id'] ) { return $this->failure( 'llamahire_privacy_denied' ); }
				$items[] = array( 'application_id' => $id, 'job_id' => $job_id );
			}
			if ( '' !== $wpdb->last_error ) { return $this->failure(); }
			return array( 'site_id' => get_current_blog_id(), 'items' => $items, 'done' => count( $items ) < 100 );
		} catch ( \Throwable $error ) { return $this->failure(); }
		finally { $wpdb->suppress_errors( $previous ); }
	}

	private function failure( $code = 'llamahire_privacy_unavailable' ) {
		return new \WP_Error( $code, __( 'Application privacy references are unavailable for this request.', 'llamahire' ) );
	}
}
