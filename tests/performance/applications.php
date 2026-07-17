<?php
/**
 * Disposable application-query benchmark.
 *
 * Run with WP-CLI. It creates and removes 10,000 synthetic rows linked to one
 * temporary job. No emails, uploads, or public requests are performed.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

global $wpdb;
$table  = $wpdb->prefix . 'llamahire_applications';
$job_id = wp_insert_post( array( 'post_type' => 'llamahire_job', 'post_status' => 'draft', 'post_title' => 'LlamaHire performance fixture' ) );
$total  = 10000;
$batch_size = 500;
$statuses = array( 'new', 'reviewing', 'rejected', 'hired' );
$results = array();

$measure = static function ( $name, $callback ) use ( &$results, $wpdb ) {
	$queries = $wpdb->num_queries;
	$memory  = memory_get_usage( true );
	$start   = hrtime( true );
	$value   = $callback();
	$results[ $name ] = array(
		'milliseconds' => round( ( hrtime( true ) - $start ) / 1000000, 3 ),
		'queries'      => $wpdb->num_queries - $queries,
		'memory_kb'    => round( ( memory_get_usage( true ) - $memory ) / 1024, 1 ),
		'result'       => $value,
	);
};

try {
	$measure(
		'seed_10000',
		static function () use ( $wpdb, $table, $job_id, $total, $batch_size, $statuses ) {
			$inserted = 0;
			for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
				$values = array();
				for ( $i = $offset; $i < min( $total, $offset + $batch_size ); $i++ ) {
					$values[] = $wpdb->prepare(
						'(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
						$job_id,
						'Performance Candidate ' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ),
						'candidate-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) . '@example.test',
						'',
						'',
						'',
						'',
						$statuses[ $i % count( $statuses ) ],
						'',
						gmdate( 'Y-m-d H:i:s', time() - $i ),
						gmdate( 'Y-m-d H:i:s', time() - $i )
					);
				}
				$sql = "INSERT INTO {$table} (job_id,name,email,phone,cover_letter,resume_path,resume_name,status,notes,created_at,updated_at) VALUES " . implode( ',', $values );
				$inserted += (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			return $inserted;
		}
	);

	$query = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_QUERY );
	$measure( 'first_page', static function () use ( $query, $job_id ) { $r = $query->search( array( 'job_id' => $job_id ) ); return array( 'total' => $r['total'], 'items' => count( $r['items'] ) ); } );
	$measure( 'status_page', static function () use ( $query, $job_id ) { $r = $query->search( array( 'job_id' => $job_id, 'status' => 'reviewing' ) ); return array( 'total' => $r['total'], 'items' => count( $r['items'] ) ); } );
	$measure( 'deep_page_400', static function () use ( $query, $job_id ) { $r = $query->search( array( 'job_id' => $job_id, 'page' => 400 ) ); return array( 'total' => $r['total'], 'items' => count( $r['items'] ) ); } );
	$measure( 'exact_email_search', static function () use ( $query, $job_id ) { $r = $query->search( array( 'job_id' => $job_id, 'search' => 'candidate-09999@example.test' ) ); return array( 'total' => $r['total'], 'items' => count( $r['items'] ) ); } );
	$measure( 'counts', static function () use ( $query ) { return $query->counts(); } );
	$measure( 'recent_5', static function () use ( $query ) { return count( $query->recent( 5 ) ); } );
	$measure( 'export_10000', static function () use ( $query, $job_id ) { $count = 0; foreach ( $query->export_rows( array( 'job_id' => $job_id ) ) as $row ) { ++$count; } return $count; } );

	WP_CLI::line( wp_json_encode( array( 'database' => $wpdb->db_server_info(), 'rows' => $total, 'results' => $results ), JSON_PRETTY_PRINT ) );
} finally {
	$wpdb->delete( $table, array( 'job_id' => $job_id ), array( '%d' ) );
	wp_delete_post( $job_id, true );
}
