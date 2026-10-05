<?php
/** Site isolation and repeatable publication migration on a disposable network. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() || ! is_multisite() ) { throw new RuntimeException( 'Disposable network required.' ); }

use LlamaHire\Job_Publication;
use LlamaHire\Jobs;
use LlamaHire\Listing_Lock;
use LlamaHire\Listing_Store;
use LlamaHire\Migrations;

$count = 0;
$assert = static function ( $value, $label ) use ( &$count ) { ++$count; if ( ! $value ) { throw new RuntimeException( $label ); } };
$sites = array(); $original_actor = get_current_user_id();
$provider = new class implements \LlamaHire\Contracts\Listing_Policy {
	public $offers = array();
	public function evaluate( array $context ) { return $this->offers[ $context['site_id'] ] ?? null; }
};
add_filter( 'llamahire_listing_policies', static function ( $providers ) use ( $provider ) { $providers['network_fixture'] = $provider; return $providers; } );
add_filter( 'pre_wp_mail', '__return_true' );
$network = get_network();
try {
	foreach ( array( true, false ) as $paid ) {
		$site = wp_insert_site( array( 'domain' => $network->domain, 'path' => '/publication-' . wp_generate_uuid4() . '/', 'network_id' => $network->id, 'user_id' => 1, 'title' => 'Fictional publication network' ) );
		$assert( ! is_wp_error( $site ), 'Could not create disposable publication site.' );
		$sites[] = $site;
		switch_to_blog( $site );
		try {
			\LlamaHire\Activator::activate( false );
			add_user_to_blog( $site, 1, 'administrator' ); wp_set_current_user( 1 );
			global $wpdb;
			// Only this newly created site's publication schema is interrupted.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', Listing_Store::table( 'periods' ) ) );
			update_option( Migrations::OPTION, '12', false );
			$fail_column = static function ( $sql ) {
				if ( 0 === strpos( $sql, 'CREATE TABLE ' . Listing_Store::table( 'periods' ) ) ) { return str_replace( "expires date NOT NULL,\n", '', $sql ); }
				return $sql;
			};
			add_filter( 'query', $fail_column );
			try { $result = Migrations::run(); }
			finally { remove_filter( 'query', $fail_column ); }
			$assert( ! $result && 12 === (int) get_option( Migrations::OPTION ), 'Partial publication schema advanced the marker.' );
			$assert( Migrations::run() && 13 === (int) get_option( Migrations::OPTION ), 'Interrupted migration did not recover.' );
			$job = wp_insert_post( array( 'import_id' => 400001, 'post_type' => Jobs::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Fictional network listing', 'post_author' => 1 ) );
			$assert( 400001 === $job, 'Fixtures did not share the same site-local job ID.' );
			$provider->offers[ $site ] = array( 'eligible' => $paid, 'period_required' => true, 'period' => array( 'id' => '11111111-2222-4333-8444-555555555555', 'predecessor' => '', 'days' => 30 ) );
			Job_Publication::approve( $job );
			$assert( $paid === Job_Publication::available( $job ) && $paid === is_array( Listing_Store::period( $job ) ), 'Publication or usage leaked between sites.' );
			$assert( Migrations::run() && $paid === is_array( Listing_Store::period( $job ) ), 'Repeat migration changed existing periods.' );
			$leases[ $site ] = Listing_Lock::acquire( $job );
			$assert( (bool) $leases[ $site ], 'Lease on another site blocked this job.' );
		} finally { restore_current_blog(); }
	}
	foreach ( $sites as $index => $site ) {
		switch_to_blog( $site );
		try {
			$assert( Listing_Lock::owns( 400001, $leases[ $site ] ), 'Site-local lease was replaced by another site.' );
			Listing_Lock::release( 400001, $leases[ $site ] );
			$assert( ( 0 === $index ) === Job_Publication::available( 400001 ), 'Provider cache crossed blog boundaries.' );
		} finally { restore_current_blog(); }
	}
	echo 'Listing multisite assertions passed: ' . $count . ".\n";
} finally {
	wp_set_current_user( $original_actor );
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	foreach ( $sites as $site ) {
		switch_to_blog( $site );
		try {
			foreach ( array( 'states', 'periods' ) as $kind ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Listing_Store::table( $kind ) ) ); }
		} finally { restore_current_blog(); }
		wpmu_delete_blog( $site, true );
	}
}
