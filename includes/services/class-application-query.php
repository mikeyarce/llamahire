<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Application_Query as Application_Query_Contract;

defined( 'ABSPATH' ) || exit;

final class Application_Query implements Application_Query_Contract {
	public function search( array $arguments = array() ) {
		global $wpdb;
		$args = wp_parse_args( $arguments, array( 'status' => '', 'search' => '', 'job_id' => 0, 'page' => 1, 'per_page' => 20 ) );
		$page = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		list( $where, $params ) = $this->where( $args );
		$table = Applications::table();
		$count_sql = "SELECT COUNT(*) FROM {$table} applications WHERE {$where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.phone, applications.cover_letter, applications.resume_name, (applications.resume_path <> '') AS has_resume, applications.status, applications.notes, applications.created_at, applications.updated_at, applications.notification_status, applications.notification_attempts, applications.employer_notified_at, applications.candidate_notified_at, applications.notification_error_code FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} ORDER BY applications.created_at DESC, applications.id DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) );
	}

	public function counts() {
		global $wpdb;
		$table = Applications::table();
		$row = $wpdb->get_row( "SELECT SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count, SUM(CASE WHEN status = 'reviewing' THEN 1 ELSE 0 END) AS reviewing_count, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count, SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) AS hired_count, SUM(CASE WHEN notification_status IN ('pending','partial','failed') THEN 1 ELSE 0 END) AS notification_attention FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array(
			'new' => (int) ( $row->new_count ?? 0 ), 'reviewing' => (int) ( $row->reviewing_count ?? 0 ),
			'rejected' => (int) ( $row->rejected_count ?? 0 ), 'hired' => (int) ( $row->hired_count ?? 0 ),
			'notification_attention' => (int) ( $row->notification_attention ?? 0 ),
		);
	}

	public function recent( $limit = 5 ) {
		global $wpdb;
		$table = Applications::table();
		$limit = min( 20, max( 1, absint( $limit ) ) );
		$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.phone, applications.cover_letter, applications.resume_name, (applications.resume_path <> '') AS has_resume, applications.status, applications.notes, applications.created_at, applications.updated_at, applications.notification_status, applications.notification_attempts, applications.employer_notified_at, applications.candidate_notified_at, applications.notification_error_code FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id ORDER BY applications.created_at DESC, applications.id DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function export_rows( array $arguments = array() ) {
		global $wpdb;
		$args = wp_parse_args( $arguments, array( 'status' => '', 'job_id' => 0 ) );
		list( $where, $params ) = $this->where( $args );
		$table = Applications::table();
		$last_id = PHP_INT_MAX;
		do {
			$sql = "SELECT applications.id, applications.job_id, jobs.post_title AS job_title, applications.name, applications.email, applications.phone, applications.cover_letter, applications.status, applications.created_at FROM {$table} applications LEFT JOIN {$wpdb->posts} jobs ON jobs.ID = applications.job_id WHERE {$where} AND applications.id < %d ORDER BY applications.id DESC LIMIT 500";
			$batch = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $last_id ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
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
		if ( in_array( $args['status'] ?? '', array( 'new', 'reviewing', 'rejected', 'hired' ), true ) ) {
			$where[] = 'applications.status = %s'; $params[] = $args['status'];
		}
		if ( ! empty( $args['job_id'] ) ) {
			$where[] = 'applications.job_id = %d'; $params[] = absint( $args['job_id'] );
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
		return array( implode( ' AND ', $where ), $params );
	}
}
