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
					'job_ids'             => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer' ) ),
					'statuses'            => array( 'type' => 'array', 'maxItems' => 6, 'items' => array( 'type' => 'string' ) ),
					'notification_statuses' => array( 'type' => 'array', 'maxItems' => 4, 'items' => array( 'type' => 'string' ) ),
					'received_operator'   => array( 'type' => 'string', 'enum' => array( 'on', 'before', 'beforeInc', 'after', 'afterInc', 'between' ) ),
					'received'            => array( 'type' => 'array', 'maxItems' => 2, 'items' => array( 'type' => 'string', 'format' => 'date' ) ),
					'orderby'             => array( 'type' => 'string', 'enum' => array( 'candidate', 'job', 'status', 'notification_status', 'received' ), 'default' => 'received' ),
					'order'               => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/applications/bulk-status',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'bulk_application_status' ),
				'permission_callback' => static function () {
					return current_user_can( Capabilities::MANAGE_APPLICATIONS );
				},
				'args'                => array(
					'application_ids' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'status'          => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Applications::workflow_statuses() ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/applications/(?P<id>\\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'application' ),
					'permission_callback' => static function ( \WP_REST_Request $request ) {
						return self::application_permission( $request, Capabilities::VIEW_APPLICATIONS );
					},
					'args'                => array(
						'id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_application' ),
					'permission_callback' => static function ( \WP_REST_Request $request ) {
						return self::application_permission( $request, Capabilities::MANAGE_APPLICATIONS );
					},
					'args'                => array(
						'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'status' => array( 'type' => 'string', 'enum' => array_keys( Applications::workflow_statuses() ) ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/applications/(?P<id>\\d+)/notes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_application_note' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return self::application_permission( $request, Capabilities::MANAGE_APPLICATIONS );
				},
				'args'                => array(
					'id'   => array( 'type' => 'integer', 'minimum' => 1 ),
					'note' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => Application_Notes::MAX_LENGTH,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'activity' ),
				'permission_callback' => static function () {
					return current_user_can( Capabilities::VIEW_APPLICATIONS );
				},
				'args'                => array(
					'page'              => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page'          => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					'search'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'event_types'       => array( 'type' => 'array', 'maxItems' => count( Audit_Log::EVENTS ), 'items' => array( 'type' => 'string' ) ),
					'job_ids'           => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer' ) ),
					'actor_ids'         => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer' ) ),
					'occurred_operator' => array( 'type' => 'string', 'enum' => array( 'on', 'before', 'beforeInc', 'after', 'afterInc', 'between' ) ),
					'occurred'          => array( 'type' => 'array', 'maxItems' => 2, 'items' => array( 'type' => 'string', 'format' => 'date' ) ),
					'orderby'           => array( 'type' => 'string', 'enum' => array( 'event', 'job', 'occurred' ), 'default' => 'occurred' ),
					'order'             => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
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

	public static function bulk_application_status( \WP_REST_Request $request ) {
		$application_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request['application_ids'] ) ) ) );
		$status          = sanitize_key( $request['status'] );
		if ( ! $application_ids || count( $application_ids ) > 100 || ! array_key_exists( $status, Applications::workflow_statuses() ) ) {
			return new \WP_Error( 'llamahire_invalid_bulk_status', __( 'Choose valid applications and a valid destination status.', 'llamahire' ), array( 'status' => 400 ) );
		}
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$records    = array();
		foreach ( $application_ids as $application_id ) {
			if ( ! Plugin::instance()->services()->get( Service_IDs::EXTENSION_ACCESS )->can_access_application( $application_id, Capabilities::MANAGE_APPLICATIONS ) ) {
				return new \WP_Error( 'llamahire_bulk_status_forbidden', __( 'One or more selected applications cannot be updated by this account.', 'llamahire' ), array( 'status' => 403 ) );
			}
			$record = $repository->find( $application_id );
			if ( ! $record ) {
				return new \WP_Error( 'llamahire_bulk_status_missing', __( 'One or more selected applications no longer exist.', 'llamahire' ), array( 'status' => 404 ) );
			}
			$records[] = $record;
		}
		$updated = 0;
		foreach ( $records as $record ) {
			if ( $record->status === $status ) {
				continue;
			}
			$result = $repository->update( $record->id, array( 'status' => $status ) );
			if ( is_wp_error( $result ) || ! $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'llamahire_bulk_status_failed', __( 'The selected applications could not be updated.', 'llamahire' ), array( 'status' => 500 ) );
			}
			++$updated;
		}
		return rest_ensure_response(
			array(
				'updated'         => $updated,
				'application_ids' => $application_ids,
				'status'          => $status,
			)
		);
	}

	public static function application( \WP_REST_Request $request ) {
		return self::application_response( absint( $request['id'] ) );
	}

	public static function update_application( \WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$changes = array();
		if ( null !== $request->get_param( 'status' ) ) {
			$changes['status'] = sanitize_key( $request['status'] );
		}
		if ( ! $changes ) {
			return new \WP_Error( 'llamahire_application_update_empty', __( 'Choose a status before saving.', 'llamahire' ), array( 'status' => 400 ) );
		}

		$result = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update( $id, $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'llamahire_application_update_failed', __( 'The application review could not be saved.', 'llamahire' ), array( 'status' => 500 ) );
		}

		return self::application_response( $id );
	}

	public static function add_application_note( \WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$result = Application_Notes::add( $id, $request['note'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return self::application_response( $id );
	}

	public static function activity( \WP_REST_Request $request ) {
		$arguments = array_merge( self::activity_query_arguments( $request->get_params() ), Ownership::query_arguments() );
		$result = Audit_Log::search( $arguments );
		$items = array_map(
			static function ( $event ) {
				$actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null;
				$job_edit_url = get_edit_post_link( $event->job_id, 'raw' );
				$application_url = 'application' === $event->subject_type && Plugin::instance()->services()->get( Service_IDs::EXTENSION_ACCESS )->can_access_application( $event->application_id, Capabilities::VIEW_APPLICATIONS )
					? Admin::applications_url( array( 'application' => (int) $event->application_id ) )
					: '';
				$target_url = $application_url ?: $job_edit_url;
				/* translators: %d is the numeric WordPress job post ID. */
				$job_label = $event->job_title ?: sprintf( __( 'Deleted job #%d', 'llamahire' ), $event->job_id );
				if ( 'application' === $event->subject_type ) {
					/* translators: %d is the numeric application ID. */
					$subject_label = sprintf( __( 'Application #%d', 'llamahire' ), $event->subject_id );
				} else {
					/* translators: %d is the numeric WordPress job post ID. */
					$subject_label = sprintf( __( 'Job #%d', 'llamahire' ), $event->subject_id );
				}
				return array(
					'id'               => (int) $event->id,
					'event_type'       => (string) $event->event_type,
					'event'            => Audit_Log::describe( $event ),
					'job_id'           => (int) $event->job_id,
					'job'              => $job_label,
					'job_edit_url'     => $job_edit_url ?: '',
					'subject'          => $subject_label,
					'subject_url'      => $target_url ?: '',
					'actor_id'         => (int) $event->actor_user_id,
					'actor'            => $actor ? $actor->display_name : __( 'System', 'llamahire' ),
					'occurred'         => mysql_to_rfc3339( $event->created_at ),
					'occurred_display' => get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'target_url'       => $target_url ?: '',
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

	private static function application_permission( \WP_REST_Request $request, $capability ) {
		$id = absint( $request['id'] );
		if ( $id && Plugin::instance()->services()->get( Service_IDs::EXTENSION_ACCESS )->can_access_application( $id, $capability ) ) {
			return true;
		}
		return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ), array( 'status' => 404 ) );
	}

	private static function application_response( $id ) {
		$application = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $id );
		if ( ! $application ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ), array( 'status' => 404 ) );
		}

		$resume = null;
		if ( $application->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) {
			$type         = wp_check_filetype( $application->resume_name );
			$download_url = Applications::resume_url( $id );
			$resume = array(
				'name'         => (string) $application->resume_name,
				'type'         => strtoupper( (string) ( $type['ext'] ?: __( 'File', 'llamahire' ) ) ),
				'download_url' => $download_url,
				'preview_url'  => Applications::resume_is_previewable( $application->resume_name ) ? Applications::resume_url( $id, true ) : '',
			);
		}

		$history = Audit_Log::search( array( 'application_id' => $id, 'per_page' => 20 ) );
		$notes   = array_map(
			static function ( $note ) {
				return array(
					'id'      => (int) $note->id,
					'body'    => (string) $note->body,
					'legacy'  => (bool) $note->is_legacy,
					'author'  => Application_Notes::author_label( $note ),
					'created' => get_date_from_gmt( $note->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				);
			},
			Application_Notes::for_application( $id, 20 )
		);
		$activity = array_map(
			static function ( $event ) {
				$actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null;
				return array(
					'id'       => (int) $event->id,
					'event'    => Audit_Log::describe( $event ),
					'actor'    => $actor ? $actor->display_name : __( 'System', 'llamahire' ),
					'occurred' => get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				);
			},
			$history['items']
		);

		return rest_ensure_response(
			array(
				'id'           => (int) $application->id,
				'status'       => (string) $application->status,
				'private_notes' => $notes,
				'resume'       => $resume,
				'cover_letter' => trim( (string) $application->cover_letter ),
				'extensions'   => Application_Extensions::review( $id ),
				'activity'     => $activity,
				'activity_url' => Admin::applications_url( array( 'application' => $id ) ) . '#llamahire-application-activity',
				'detail_url'   => Admin::applications_url( array( 'application' => $id ) ),
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
				'job_ids'               => array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $source['job_ids'] ?? array() ) ) ) ) ), 0, 100 ),
				'statuses'              => array_values( array_unique( array_intersect( array_map( 'sanitize_key', (array) ( $source['statuses'] ?? array() ) ), array_keys( Applications::workflow_statuses() ) ) ) ),
				'notification_statuses' => array_values( array_unique( array_intersect( array_map( 'sanitize_key', (array) ( $source['notification_statuses'] ?? array() ) ), array( 'pending', 'sent', 'partial', 'failed' ) ) ) ),
				'orderby'               => in_array( $orderby, array( 'candidate', 'job', 'status', 'notification_status', 'received' ), true ) ? $orderby : 'received',
				'order'                 => 'asc' === $order ? 'asc' : 'desc',
			),
			self::received_arguments(
				sanitize_key( $source['received_operator'] ?? '' ),
				(array) ( $source['received'] ?? array() )
			)
		);
	}

	public static function activity_query_arguments( array $source ) {
		$orderby = sanitize_key( $source['orderby'] ?? 'occurred' );
		$order = strtolower( sanitize_key( $source['order'] ?? 'desc' ) );
		return array_merge(
			array(
				'page'        => max( 1, absint( $source['page'] ?? 1 ) ),
				'per_page'    => min( 100, max( 1, absint( $source['per_page'] ?? 20 ) ) ),
				'search'      => sanitize_text_field( wp_unslash( $source['search'] ?? '' ) ),
				'event_types' => array_values( array_unique( array_intersect( array_map( 'sanitize_key', (array) ( $source['event_types'] ?? array() ) ), Audit_Log::EVENTS ) ) ),
				'job_ids'     => array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $source['job_ids'] ?? array() ) ) ) ) ), 0, 100 ),
				'actor_ids'   => array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $source['actor_ids'] ?? array() ) ) ) ) ), 0, 100 ),
				'orderby'     => in_array( $orderby, array( 'event', 'job', 'occurred' ), true ) ? $orderby : 'occurred',
				'order'       => 'asc' === $order ? 'asc' : 'desc',
			),
			self::activity_occurred_arguments(
				sanitize_key( $source['occurred_operator'] ?? '' ),
				(array) ( $source['occurred'] ?? array() )
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

	private static function activity_occurred_arguments( $operator, array $values ) {
		$operator = in_array( $operator, array( 'on', 'before', 'beforeInc', 'after', 'afterInc', 'between' ), true ) ? $operator : '';
		$dates = array_values(
			array_filter(
				array_map(
					static function ( $value ) {
						$value = sanitize_text_field( $value );
						$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
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
				'occurred_after'  => $dates[0] . ' 00:00:00',
				'occurred_before' => $dates[1] . ' 23:59:59',
			);
		}
		$date = $dates[0];
		switch ( $operator ) {
			case 'on':
				return array( 'occurred_after' => $date . ' 00:00:00', 'occurred_before' => $date . ' 23:59:59' );
			case 'before':
				return array( 'occurred_before_exclusive' => $date . ' 00:00:00' );
			case 'beforeInc':
				return array( 'occurred_before' => $date . ' 23:59:59' );
			case 'after':
				return array( 'occurred_after_exclusive' => $date . ' 23:59:59' );
			case 'afterInc':
				return array( 'occurred_after' => $date . ' 00:00:00' );
		}
		return array();
	}

	private function __construct() {}
}
