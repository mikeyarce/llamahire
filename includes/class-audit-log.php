<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy-safe operational history for jobs and applications.
 *
 * Records IDs, allow-listed workflow states, and timestamps only. Candidate
 * content, contact data, request metadata, and storage identifiers are forbidden.
 */
final class Audit_Log {
	const EVENTS = array(
		'application_status_changed',
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
		$args = wp_parse_args( $arguments, array( 'author_id' => 0, 'application_id' => 0, 'job_id' => 0, 'page' => 1, 'per_page' => 50 ) );
		$page = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$where = array( '1=1' );
		$params = array();
		if ( absint( $args['author_id'] ) ) { $where[] = 'jobs.post_author = %d'; $params[] = absint( $args['author_id'] ); }
		if ( absint( $args['application_id'] ) ) { $where[] = 'audit.application_id = %d'; $params[] = absint( $args['application_id'] ); }
		if ( absint( $args['job_id'] ) ) { $where[] = 'audit.job_id = %d'; $params[] = absint( $args['job_id'] ); }
		$where_sql = implode( ' AND ', $where );
		$table = self::table();
		$join = " LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = audit.job_id";
		$count_sql = "SELECT COUNT(*) FROM {$table} audit{$join} WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql = "SELECT audit.id, audit.event_type, audit.subject_type, audit.subject_id, audit.application_id, audit.job_id, audit.actor_user_id, audit.from_state, audit.to_state, audit.created_at, jobs.post_title AS job_title FROM {$table} audit{$join} WHERE {$where_sql} ORDER BY audit.created_at DESC, audit.id DESC LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) );
	}

	public static function describe( $event ) {
		$labels = array(
			'application_status_changed'       => __( 'Application status changed', 'llamahire' ),
			'application_erased'               => __( 'Application permanently erased', 'llamahire' ),
			'application_resume_deleted'       => __( 'Resume permanently deleted', 'llamahire' ),
			'application_resume_replaced'      => __( 'Resume replaced', 'llamahire' ),
			'application_notifications_retried'=> __( 'Application notifications retried', 'llamahire' ),
			'application_resume_accessed'      => __( 'Resume accessed', 'llamahire' ),
			'job_submitted'                    => __( 'Job submitted for review', 'llamahire' ),
			'job_resubmitted'                  => __( 'Job resubmitted for review', 'llamahire' ),
			'job_approved'                     => __( 'Job approved and published', 'llamahire' ),
			'job_changes_requested'            => __( 'Job returned for changes', 'llamahire' ),
			'job_declined'                     => __( 'Job declined', 'llamahire' ),
			'job_closed'                       => __( 'Job closed by employer', 'llamahire' ),
			'job_deleted'                      => __( 'Job deleted by employer', 'llamahire' ),
			'job_owner_changed'                => __( 'Job owner changed', 'llamahire' ),
		);
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
