<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned recruiter-facing REST resources.
 */
final class REST_API {
	const NAMESPACE = 'llamahire/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/applications',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'applications' ),
				'permission_callback' => static function () {
					return current_user_can( Capabilities::VIEW_APPLICATIONS );
				},
				'args'                => array(
					'page'                => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					'search'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'candidate'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'email'               => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					'job_ids'             => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'statuses'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'notification_statuses' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'received_operator'   => array( 'type' => 'string', 'enum' => array( 'on', 'before', 'beforeInc', 'after', 'afterInc', 'between' ) ),
					'received'            => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'format' => 'date' ) ),
					'orderby'             => array( 'type' => 'string', 'enum' => array( 'candidate', 'job', 'status', 'notification_status', 'received' ), 'default' => 'received' ),
					'order'               => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				),
			)
		);
	}

	public static function applications( \WP_REST_Request $request ) {
		$arguments = array_merge( self::application_query_arguments( $request->get_params() ), Ownership::query_arguments() );
		$result = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->search( $arguments );
		$items  = array_map(
			static function ( $row ) {
				return array(
					'id'                  => (int) $row->id,
					'candidate'           => (string) $row->name,
					'email'               => (string) $row->email,
					'job_id'              => (int) $row->job_id,
					'job'                 => (string) $row->job_title,
					'status'              => (string) $row->status,
					'notification_status' => (string) $row->notification_status,
					'received'            => mysql_to_rfc3339( $row->created_at ),
					'received_display'    => get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'detail_url'          => Admin::applications_url( array( 'application' => (int) $row->id ) ),
					'job_edit_url'        => get_edit_post_link( $row->job_id, 'raw' ),
				);
			},
			$result['items']
		);

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $result['total'],
				'page'  => (int) $result['page'],
				'pages' => (int) $result['pages'],
			)
		);
	}

	public static function application_query_arguments( array $source ) {
		$orderby = sanitize_key( $source['orderby'] ?? 'received' );
		$order   = strtolower( sanitize_key( $source['order'] ?? 'desc' ) );
		return array_merge(
			array(
				'page'                  => max( 1, absint( $source['page'] ?? 1 ) ),
				'per_page'              => min( 100, max( 1, absint( $source['per_page'] ?? 20 ) ) ),
				'search'                => sanitize_text_field( wp_unslash( $source['search'] ?? '' ) ),
				'candidate'             => sanitize_text_field( wp_unslash( $source['candidate'] ?? '' ) ),
				'email'                 => sanitize_text_field( wp_unslash( $source['email'] ?? '' ) ),
				'job_ids'               => array_map( 'absint', (array) ( $source['job_ids'] ?? array() ) ),
				'statuses'              => array_map( 'sanitize_key', (array) ( $source['statuses'] ?? array() ) ),
				'notification_statuses' => array_map( 'sanitize_key', (array) ( $source['notification_statuses'] ?? array() ) ),
				'orderby'               => in_array( $orderby, array( 'candidate', 'job', 'status', 'notification_status', 'received' ), true ) ? $orderby : 'received',
				'order'                 => 'asc' === $order ? 'asc' : 'desc',
			),
			self::received_arguments(
				sanitize_key( $source['received_operator'] ?? '' ),
				(array) ( $source['received'] ?? array() )
			)
		);
	}

	private static function received_arguments( $operator, array $values ) {
		$operator = in_array( $operator, array( 'on', 'before', 'beforeInc', 'after', 'afterInc', 'between' ), true ) ? $operator : '';
		$dates    = array_values(
			array_filter(
				array_map(
					static function ( $value ) {
						$value = sanitize_text_field( $value );
						$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
						return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
					},
					array_slice( $values, 0, 2 )
				)
			)
		);
		if ( ! $operator || ! $dates ) {
			return array();
		}
		if ( 'between' === $operator && count( $dates ) > 1 ) {
			return array(
				'received_after'  => $dates[0] . ' 00:00:00',
				'received_before' => $dates[1] . ' 23:59:59',
			);
		}
		$date = $dates[0];
		switch ( $operator ) {
			case 'on':
				return array( 'received_after' => $date . ' 00:00:00', 'received_before' => $date . ' 23:59:59' );
			case 'before':
				return array( 'received_before_exclusive' => $date . ' 00:00:00' );
			case 'beforeInc':
				return array( 'received_before' => $date . ' 23:59:59' );
			case 'after':
				return array( 'received_after_exclusive' => $date . ' 23:59:59' );
			case 'afterInc':
				return array( 'received_after' => $date . ' 00:00:00' );
		}
		return array();
	}

	private function __construct() {}
}
