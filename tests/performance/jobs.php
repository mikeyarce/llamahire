<?php
/**
 * Disposable job-query benchmark using 500 published job fixtures.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with WP-CLI.' );
}

global $wpdb;
$total   = 500;
$job_ids = array();
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
	for ( $i = 0; $i < $total; $i++ ) {
		$job_id = wp_insert_post(
			array(
				'post_type'    => 'llamahire_job',
				'post_status'  => 'publish',
				'post_title'   => 'Performance Role ' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ),
				'post_content' => 'A synthetic performance role with complete responsibilities and qualifications.',
				'post_excerpt' => 'Synthetic performance fixture.',
			)
		);
		$job_ids[] = $job_id;
		\LlamaHire\Jobs::set_meta(
			$job_id,
			array(
				'location' => 'Vancouver, BC', 'employment_type' => 'FULL_TIME', 'workplace' => 0 === $i % 3 ? 'remote' : 'hybrid',
				'salary_min' => 80000, 'salary_max' => 100000, 'salary_currency' => 'CAD', 'deadline' => '',
				'featured' => 0 === $i % 10 ? '1' : '0', 'closed' => 0 === $i % 20 ? '1' : '0',
			)
		);
	}

	$measure(
		'directory_default',
		static function () {
			$html = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":12} /-->' );
			return substr_count( $html, 'llamahire-job-card' );
		}
	);
	$_GET['workplace'] = 'remote';
	$measure(
		'directory_remote_filter',
		static function () {
			$html = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":12} /-->' );
			return substr_count( $html, 'llamahire-job-card' );
		}
	);
	unset( $_GET['workplace'] );
	$measure(
		'directory_featured_filter',
		static function () {
			$html = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"featuredOnly":true,"perPage":12} /-->' );
			return substr_count( $html, 'llamahire-job-card' );
		}
	);
	$_GET['job_search'] = 'Performance Role 0499';
	$measure(
		'directory_keyword_search',
		static function () {
			$html = do_blocks( '<!-- wp:llamahire/jobs-directory {"showFilters":false,"perPage":12} /-->' );
			return substr_count( $html, 'llamahire-job-card' );
		}
	);
	unset( $_GET['job_search'] );
	$measure(
		'dashboard_open_count',
		static function () {
			return \LlamaHire\Jobs::open_count();
		}
	);

	WP_CLI::line( wp_json_encode( array( 'database' => $wpdb->db_server_info(), 'rows' => $total, 'results' => $results ), JSON_PRETTY_PRINT ) );
} finally {
	foreach ( $job_ids as $job_id ) {
		wp_delete_post( $job_id, true );
	}
	unset( $_GET['workplace'], $_GET['job_search'] );
}
