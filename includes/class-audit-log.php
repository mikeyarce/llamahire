<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The append-only audit service owns its custom table and must return current authorization-sensitive history.

/**
 * Privacy-safe operational history for jobs and applications.
 *
 * Records IDs, allow-listed workflow states, and timestamps only. Candidate
 * content, contact data, request metadata, and storage identifiers are forbidden.
 */
final class Audit_Log {
	const EVENTS = array(
		'application_status_changed',
		'application_note_added',
		'application_erased',
		'application_resume_deleted',
		'application_resume_replaced',
		'application_notifications_retried',
		'application_resume_accessed',
		'job_submitted',
		'job_resubmitted',
		'job_approved',
		'job_changes_requested',
		'job_declined',
		'job_closed',
		'job_renewed',
		'job_relist_started',
		'job_duplicated',
		'job_expiry_notice_sent',
		'job_deleted',
		'job_owner_changed',
	);

	public static function register() {
		add_action( 'llamahire_application_erased', array( __CLASS__, 'application_erased' ), 10, 2 );
		add_action( 'llamahire_application_resume_deleted', array( __CLASS__, 'resume_deleted' ), 10, 2 );
		add_action( 'llamahire_application_resume_replaced', array( __CLASS__, 'resume_replaced' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'job_status_changed' ), 20, 3 );
		add_action( 'post_updated', array( __CLASS__, 'job_updated' ), 10, 3 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'llamahire_audit_log';
	}

	public static function record( $event_type, $job_id, $application_id = 0, $from_state = '', $to_state = '', $actor_user_id = null ) {
		$event_type = sanitize_key( $event_type );
		$job_id = absint( $job_id );
		$application_id = absint( $application_id );
		if ( ! in_array( $event_type, self::EVENTS, true ) || ! $job_id ) {
			return false;
		}
		$subject_type = 0 < $application_id ? 'application' : 'job';
		$subject_id = $application_id ?: $job_id;
		$actor_user_id = null === $actor_user_id ? get_current_user_id() : absint( $actor_user_id );
		global $wpdb;
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'event_type'     => $event_type,
				'subject_type'   => $subject_type,
				'subject_id'     => $subject_id,
				'application_id' => $application_id ?: null,
				'job_id'         => $job_id,
				'actor_user_id'  => $actor_user_id,
				'from_state'     => substr( sanitize_key( $from_state ), 0, 50 ),
				'to_state'       => substr( sanitize_key( $to_state ), 0, 50 ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public static function search( array $arguments = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$arguments,
			array(
				'author_id'                => 0,
				'application_id'           => 0,
				'job_id'                   => 0,
				'job_ids'                  => array(),
				'actor_ids'                => array(),
				'event_types'              => array(),
				'search'                   => '',
				'occurred_after'           => '',
				'occurred_after_exclusive' => '',
				'occurred_before'          => '',
				'occurred_before_exclusive'=> '',
				'orderby'                  => 'occurred',
				'order'                    => 'desc',
				'page'                     => 1,
				'per_page'                 => 50,
			)
		);
		$page = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$where = array( '1=1' );
		$params = array();
		if ( absint( $args['author_id'] ) ) { $where[] = 'jobs.post_author = %d'; $params[] = absint( $args['author_id'] ); }
		if ( absint( $args['application_id'] ) ) { $where[] = 'audit.application_id = %d'; $params[] = absint( $args['application_id'] ); }
		if ( absint( $args['job_id'] ) ) { $where[] = 'audit.job_id = %d'; $params[] = absint( $args['job_id'] ); }
		$job_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $args['job_ids'] ) ) ) ), 0, 100 );
		if ( $job_ids ) {
			$where[] = 'audit.job_id IN (' . implode( ',', array_fill( 0, count( $job_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $job_ids );
		}
		$actor_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $args['actor_ids'] ) ) ) ), 0, 100 );
		if ( $actor_ids ) {
			$where[] = 'audit.actor_user_id IN (' . implode( ',', array_fill( 0, count( $actor_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $actor_ids );
		}
		$event_types = array_values( array_unique( array_intersect( array_map( 'sanitize_key', (array) $args['event_types'] ), self::EVENTS ) ) );
		if ( $event_types ) {
			$where[] = 'audit.event_type IN (' . implode( ',', array_fill( 0, count( $event_types ), '%s' ) ) . ')';
			$params = array_merge( $params, $event_types );
		}
		$search = sanitize_text_field( $args['search'] );
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$event_like = '%' . $wpdb->esc_like( str_replace( ' ', '_', strtolower( $search ) ) ) . '%';
			$search_where = array( 'audit.event_type LIKE %s', 'audit.from_state LIKE %s', 'audit.to_state LIKE %s', 'jobs.post_title LIKE %s' );
			$search_params = array( $event_like, $like, $like, $like );
			$matching_actor_ids = get_users(
				array(
					'fields'         => 'ids',
					'number'         => 250,
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'display_name', 'user_login' ),
				)
			);
			if ( $matching_actor_ids ) {
				$search_where[] = 'audit.actor_user_id IN (' . implode( ',', array_fill( 0, count( $matching_actor_ids ), '%d' ) ) . ')';
				$search_params = array_merge( $search_params, array_map( 'absint', $matching_actor_ids ) );
			}
			$where[] = '(' . implode( ' OR ', $search_where ) . ')';
			$params = array_merge( $params, $search_params );
		}
		$date_filters = array(
			'occurred_after'            => array( 'audit.created_at >= %s', $args['occurred_after'] ),
			'occurred_after_exclusive'  => array( 'audit.created_at > %s', $args['occurred_after_exclusive'] ),
			'occurred_before'           => array( 'audit.created_at <= %s', $args['occurred_before'] ),
			'occurred_before_exclusive' => array( 'audit.created_at < %s', $args['occurred_before_exclusive'] ),
		);
		foreach ( $date_filters as $filter ) {
			$value = sanitize_text_field( $filter[1] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
				$where[] = $filter[0];
				$params[] = $value;
			}
		}
		$where_sql = implode( ' AND ', $where );
		$table = self::table();
		$join = " LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = audit.job_id";
		$count_sql = "SELECT COUNT(*) FROM {$table} audit{$join} WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed clauses, allowlisted columns, and prepared values.
		$orderby = sanitize_key( $args['orderby'] );
		$orderby_sql = array(
			'event'    => 'audit.event_type',
			'job'      => 'jobs.post_title',
			'occurred' => 'audit.created_at',
		);
		$order_sql = 'asc' === strtolower( sanitize_key( $args['order'] ) ) ? 'ASC' : 'DESC';
		$order_column = $orderby_sql[ $orderby ] ?? $orderby_sql['occurred'];
		$sql = "SELECT audit.id, audit.event_type, audit.subject_type, audit.subject_id, audit.application_id, audit.job_id, audit.actor_user_id, audit.from_state, audit.to_state, audit.created_at, jobs.post_title AS job_title FROM {$table} audit{$join} WHERE {$where_sql} ORDER BY {$order_column} {$order_sql}, audit.id {$order_sql} LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed clauses, allowlisted columns, and prepared values.
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) );
	}

	public static function actor_options( $author_id = 0 ) {
		global $wpdb;
		$table = self::table();
		$sql = "SELECT DISTINCT audit.actor_user_id FROM {$table} audit LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = audit.job_id WHERE audit.actor_user_id > 0";
		if ( absint( $author_id ) ) {
			$sql .= $wpdb->prepare( ' AND jobs.post_author = %d', absint( $author_id ) );
		}
		$sql .= ' LIMIT 250';
		$actor_ids = array_map( 'absint', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, a fixed limit, and an optional prepared author ID.
		if ( ! $actor_ids ) {
			return array();
		}
		return get_users(
			array(
				'include' => $actor_ids,
				'fields'  => array( 'ID', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
	}

	public static function event_labels() {
		return array(
			'application_status_changed'        => __( 'Application status changed', 'llamahire' ),
			'application_note_added'             => __( 'Private note added', 'llamahire' ),
			'application_erased'                => __( 'Application permanently erased', 'llamahire' ),
			'application_resume_deleted'        => __( 'Resume permanently deleted', 'llamahire' ),
			'application_resume_replaced'       => __( 'Resume replaced', 'llamahire' ),
			'application_notifications_retried' => __( 'Application notifications retried', 'llamahire' ),
			'application_resume_accessed'       => __( 'Resume accessed', 'llamahire' ),
			'job_submitted'                     => __( 'Job submitted for review', 'llamahire' ),
			'job_resubmitted'                   => __( 'Job resubmitted for review', 'llamahire' ),
			'job_approved'                      => __( 'Job approved and published', 'llamahire' ),
			'job_changes_requested'             => __( 'Job returned for changes', 'llamahire' ),
			'job_declined'                      => __( 'Job declined', 'llamahire' ),
			'job_closed'                        => __( 'Job closed by employer', 'llamahire' ),
			'job_renewed'                       => __( 'Job listing renewed by employer', 'llamahire' ),
			'job_relist_started'                 => __( 'Expired job prepared for relisting', 'llamahire' ),
			'job_duplicated'                     => __( 'Job duplicated as a fresh draft', 'llamahire' ),
			'job_expiry_notice_sent'             => __( 'Job expiration reminder sent', 'llamahire' ),
			'job_deleted'                       => __( 'Job deleted by employer', 'llamahire' ),
			'job_owner_changed'                 => __( 'Job owner changed', 'llamahire' ),
		);
	}

	public static function describe( $event ) {
		$labels = self::event_labels();
		$description = $labels[ $event->event_type ] ?? ucwords( str_replace( '_', ' ', $event->event_type ) );
		if ( 'application_status_changed' === $event->event_type ) {
			$description .= ': ' . ucfirst( $event->from_state ) . ' → ' . ucfirst( $event->to_state );
		}
		return $description;
	}

	public static function application_erased( $application_id, $job_id ) { self::record( 'application_erased', $job_id, $application_id ); }
	public static function resume_deleted( $application_id, $job_id ) { self::record( 'application_resume_deleted', $job_id, $application_id ); }
	public static function resume_replaced( $application_id, $job_id ) { self::record( 'application_resume_replaced', $job_id, $application_id ); }

	public static function job_status_changed( $new_status, $old_status, $post ) {
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type || $new_status === $old_status ) { return; }
		$events = array( 'publish' => 'job_approved', 'draft' => 'job_changes_requested', 'trash' => 'job_declined' );
		if ( isset( $events[ $new_status ] ) && in_array( $old_status, array( 'pending', 'publish', 'draft' ), true ) && current_user_can( 'edit_others_llamahire_jobs' ) ) {
			self::record( $events[ $new_status ], $post->ID, 0, $old_status, $new_status );
		}
	}

	public static function job_updated( $post_id, $post_after, $post_before ) {
		if ( Jobs::POST_TYPE === $post_after->post_type && (int) $post_after->post_author !== (int) $post_before->post_author ) {
			self::record( 'job_owner_changed', $post_id, 0, (string) $post_before->post_author, (string) $post_after->post_author );
		}
	}

	private function __construct() {}
}
