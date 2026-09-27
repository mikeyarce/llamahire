<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Application_Query as Application_Query_Contract;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded query service owns reads from LlamaHire's private custom table; persistent caching would retain sensitive or stale candidate data.

final class Application_Query implements Application_Query_Contract {
	public function search( array $arguments = array() ) {
		global $wpdb;
		$args = wp_parse_args( $arguments, array( 'status' => '', 'statuses' => array(), 'search' => '', 'candidate' => '', 'email' => '', 'job_id' => 0, 'job_ids' => array(), 'notification_statuses' => array(), 'orderby' => 'received', 'order' => 'desc', 'page' => 1, 'per_page' => 20 ) );
		$page = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		list( $where, $params ) = $this->where( $args );
		$table = Applications::table();
		$count_sql = "SELECT COUNT(*) FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed clauses, and prepared values.
		$order_columns = array(
			'candidate'           => 'applications.name',
			'job'                 => 'jobs.post_title',
			'status'              => 'applications.status',
			'notification_status' => 'applications.notification_status',
			'received'            => 'applications.created_at',
		);
		$orderby = $order_columns[ $args['orderby'] ] ?? $order_columns['received'];
		$order   = 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.status, (COALESCE(applications.notes, '') <> '' OR EXISTS (SELECT 1 FROM " . \LlamaHire\Application_Notes::table() . " private_notes WHERE private_notes.application_id = applications.id)) AS has_notes, applications.created_at, applications.updated_at, applications.stage_changed_at, applications.notification_status FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} ORDER BY {$orderby} {$order}, applications.id {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, allowlisted ordering, and prepared values.
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) );
	}

	public function counts( array $arguments = array() ) {
		global $wpdb;
		$table = Applications::table();
		list( $where, $params ) = $this->where( $arguments );
		$sql = "SELECT SUM(CASE WHEN applications.status = 'new' THEN 1 ELSE 0 END) AS new_count, SUM(CASE WHEN applications.status = 'reviewing' THEN 1 ELSE 0 END) AS reviewing_count, SUM(CASE WHEN applications.status = 'interviewing' THEN 1 ELSE 0 END) AS interviewing_count, SUM(CASE WHEN applications.status = 'offer' THEN 1 ELSE 0 END) AS offer_count, SUM(CASE WHEN applications.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count, SUM(CASE WHEN applications.status = 'hired' THEN 1 ELSE 0 END) AS hired_count, SUM(CASE WHEN applications.notification_status IN ('pending','partial','failed') THEN 1 ELSE 0 END) AS notification_attention FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where}";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed aggregate clauses, and prepared values.
		return array(
			'new' => (int) ( $row->new_count ?? 0 ), 'reviewing' => (int) ( $row->reviewing_count ?? 0 ),
			'interviewing' => (int) ( $row->interviewing_count ?? 0 ), 'offer' => (int) ( $row->offer_count ?? 0 ),
			'rejected' => (int) ( $row->rejected_count ?? 0 ), 'hired' => (int) ( $row->hired_count ?? 0 ),
			'notification_attention' => (int) ( $row->notification_attention ?? 0 ),
		);
	}

	public function recent( $limit = 5, array $arguments = array() ) {
		global $wpdb;
		$table = Applications::table();
		$limit = min( 20, max( 1, absint( $limit ) ) );
		list( $where, $params ) = $this->where( $arguments );
		$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.status, (COALESCE(applications.notes, '') <> '' OR EXISTS (SELECT 1 FROM " . \LlamaHire\Application_Notes::table() . " private_notes WHERE private_notes.application_id = applications.id)) AS has_notes, applications.created_at, applications.updated_at, applications.stage_changed_at, applications.notification_status FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} ORDER BY applications.created_at DESC, applications.id DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed ordering, and prepared values.
	}

	public function counts_by_job( array $job_ids, array $arguments = array() ) {
		global $wpdb;
		$job_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $job_ids ) ) ) ), 0, 100 );
		if ( ! $job_ids ) {
			return array();
		}
		list( $where, $params ) = $this->where( $arguments );
		$table = Applications::table();
		$placeholders = implode( ', ', array_fill( 0, count( $job_ids ), '%d' ) );
		$sql = "SELECT applications.job_id, COUNT(*) AS application_count FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} AND applications.job_id IN ({$placeholders}) GROUP BY applications.job_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, $job_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL contains only trusted table names, fixed clauses, and placeholders generated from a bounded integer list; all values are prepared.
		$counts = array_fill_keys( $job_ids, 0 );
		foreach ( $rows as $row ) {
			$counts[ (int) $row->job_id ] = (int) $row->application_count;
		}
		return $counts;
	}

	public function counts_by_job_and_status( array $job_ids, array $arguments = array() ) {
		global $wpdb;
		$job_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $job_ids ) ) ) ), 0, 100 );
		if ( ! $job_ids ) {
			return array();
		}
		list( $where, $params ) = $this->where( $arguments );
		$table = Applications::table();
		$placeholders = implode( ', ', array_fill( 0, count( $job_ids ), '%d' ) );
		$sql = "SELECT applications.job_id, applications.status, COUNT(*) AS application_count FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} AND applications.job_id IN ({$placeholders}) GROUP BY applications.job_id, applications.status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, $job_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL contains only trusted table names, fixed clauses, and placeholders generated from a bounded integer list; all values are prepared.
		$counts = array();
		foreach ( $job_ids as $job_id ) {
			$counts[ $job_id ] = array_fill_keys( array_keys( Applications::workflow_statuses() ), 0 );
		}
		foreach ( $rows as $row ) {
			if ( isset( $counts[ (int) $row->job_id ][ $row->status ] ) ) {
				$counts[ (int) $row->job_id ][ $row->status ] = (int) $row->application_count;
			}
		}
		return $counts;
	}

	public function export_rows( array $arguments = array() ) {
		global $wpdb;
		$args = wp_parse_args( $arguments, array( 'status' => '', 'search' => '', 'job_id' => 0 ) );
		list( $where, $params ) = $this->where( $args );
		$table = Applications::table();
		$last_id = PHP_INT_MAX;
		do {
			$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.phone, applications.cover_letter, applications.status, applications.created_at FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} AND applications.id < %d ORDER BY applications.id DESC LIMIT 500";
			$batch = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $last_id ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL uses trusted table names, fixed ordering, and prepared values.
			foreach ( $batch as $row ) {
				$last_id = (int) $row['id'];
				yield $row;
			}
		} while ( 500 === count( $batch ) );
	}

	private function where( array $args ) {
		global $wpdb;
		$where = array( '1=1' );
		$params = array();
		$valid_statuses = array_keys( Applications::workflow_statuses() );
		$statuses = array_values( array_unique( array_intersect( $valid_statuses, array_map( 'sanitize_key', (array) ( $args['statuses'] ?? array() ) ) ) ) );
		if ( ! $statuses && in_array( $args['status'] ?? '', $valid_statuses, true ) ) {
			$statuses[] = $args['status'];
		}
		if ( $statuses ) {
			$where[] = 'applications.status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $statuses );
		}
		$job_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['job_ids'] ?? array() ) ) ) ) ), 0, 100 );
		if ( ! $job_ids && ! empty( $args['job_id'] ) ) {
			$job_ids[] = absint( $args['job_id'] );
		}
		if ( $job_ids ) {
			$where[] = 'applications.job_id IN (' . implode( ', ', array_fill( 0, count( $job_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $job_ids );
		}
		if ( ! empty( $args['author_id'] ) ) {
			$where[] = 'jobs.post_author = %d'; $params[] = absint( $args['author_id'] );
		}
		$search = sanitize_text_field( $args['search'] ?? '' );
		if ( $search ) {
			if ( is_email( $search ) ) {
				$where[] = 'applications.email = %s';
				$params[] = $search;
			} else {
				$where[] = '(applications.name LIKE %s OR applications.email LIKE %s)';
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like; $params[] = $like;
			}
		}
		$candidate = sanitize_text_field( $args['candidate'] ?? '' );
		if ( $candidate ) {
			$where[] = '(applications.name LIKE %s OR applications.email LIKE %s)';
			$like = '%' . $wpdb->esc_like( $candidate ) . '%';
			$params[] = $like; $params[] = $like;
		}
		$email = sanitize_text_field( $args['email'] ?? '' );
		if ( $email ) {
			$where[] = 'applications.email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $email ) . '%';
		}
		$notification_statuses = array_values( array_unique( array_intersect( array( 'pending', 'sent', 'partial', 'failed' ), array_map( 'sanitize_key', (array) ( $args['notification_statuses'] ?? array() ) ) ) ) );
		if ( $notification_statuses ) {
			$where[] = 'applications.notification_status IN (' . implode( ', ', array_fill( 0, count( $notification_statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $notification_statuses );
		}
		if ( ! empty( $args['received_after'] ) ) {
			$where[] = 'applications.created_at >= %s'; $params[] = $args['received_after'];
		}
		if ( ! empty( $args['received_after_exclusive'] ) ) {
			$where[] = 'applications.created_at > %s'; $params[] = $args['received_after_exclusive'];
		}
		if ( ! empty( $args['received_before'] ) ) {
			$where[] = 'applications.created_at <= %s'; $params[] = $args['received_before'];
		}
		if ( ! empty( $args['received_before_exclusive'] ) ) {
			$where[] = 'applications.created_at < %s'; $params[] = $args['received_before_exclusive'];
		}
		if ( ! empty( $args['stage_changed_before'] ) ) {
			$where[] = 'COALESCE(applications.stage_changed_at, applications.created_at) <= %s';
			$params[] = $args['stage_changed_before'];
		}
		return array( implode( ' AND ', $where ), $params );
	}
}
