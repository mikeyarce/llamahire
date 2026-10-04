<?php
/** Real fixture command expiry and renewal; numeric references only. */
if ( '/var/www/html/' !== ABSPATH || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Disposable site required.' ); }
use LlamaHire\Tools\Fixtures_Command;
use LlamaHire\Tools\Publication_Fixture;
use LlamaHire\Service_IDs;
require_once LLAMAHIRE_PATH . 'tools/class-publication-fixture.php';
$actor = get_current_user_id(); $count = 0; $foreign = 0; $owned = false;
$assert = static function ( $ok, $message ) use ( &$count ) { ++$count; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$provider = new class implements \LlamaHire\Contracts\Listing_Policy {
	public $job = 0; public $offer;
	public function evaluate( array $context ) { return $context['job_id'] === $this->job ? array( 'eligible' => true, 'period_required' => true, 'period' => $this->offer ) : null; }
};
$register = static function ( $policies ) use ( $provider ) { $policies['expiry_fixture'] = $provider; return $policies; };
$mail = static function () { return true; }; add_filter( 'pre_wp_mail', $mail ); add_filter( 'llamahire_listing_policies', $register );
try {
	$assert( false === get_option( Fixtures_Command::OPTION, false ), 'Clean existing fixture data first.' );
	WP_CLI::runcommand( 'llamahire fixtures generate --scenario=state-matrix --applications=0', array( 'launch' => false, 'return' => 'all', 'exit_error' => false ) ); $owned = true;
	$registry = get_option( Fixtures_Command::OPTION ); $job = (int) $registry['jobs'][8];
	$provider->job = $job; $provider->offer = array( 'id' => wp_generate_uuid4(), 'predecessor' => '', 'days' => 30 );
	wp_set_current_user( 1 ); $lifecycle = \LlamaHire\Plugin::instance()->services()->get( Service_IDs::JOB_LIFECYCLE );
	$assert( true === $lifecycle->approve( $job ), 'Fixture period publication failed.' ); $period = $lifecycle->period( $job );
	$assert( is_wp_error( Publication_Fixture::expire( $job, false ) ) && $period === $lifecycle->period( $job ), 'Expiry did not require explicit confirmation.' );
	$foreign = wp_insert_post( array( 'post_type' => 'llamahire_job', 'post_title' => 'Unregistered fictional job', 'post_status' => 'draft' ) );
	update_post_meta( $foreign, Fixtures_Command::META, Fixtures_Command::OWNER );
	$assert( is_wp_error( Publication_Fixture::expire( $foreign, true ) ), 'An ownership marker alone authorized unrelated job mutation.' );
	$lease = \LlamaHire\Listing_Lock::acquire( $job );
	$assert( is_wp_error( Publication_Fixture::expire( $job, true ) ), 'Expiry ignored concurrent publication.' ); \LlamaHire\Listing_Lock::release( $job, $lease );
	$fail = static function ( $sql ) { return 'COMMIT' === trim( $sql ) ? 'SELECT * FROM missing_expiry_fixture_commit' : $sql; };
	add_filter( 'query', $fail );
	try { $result = Publication_Fixture::expire( $job, true ); }
	finally { remove_filter( 'query', $fail ); }
	$assert( is_wp_error( $result ) && $period === $lifecycle->period( $job ) && \LlamaHire\Jobs::get_meta( $job )['listing_expires'] === $period['expires'], 'Failed expiry commit leaked simulated time.' );
	$assert( true === Publication_Fixture::expire( $job, true ), 'Direct expiry simulation failed.' );
	$output = WP_CLI::runcommand( 'llamahire fixtures expire-listing ' . $job . ' --yes', array( 'launch' => false, 'return' => 'all', 'exit_error' => false ) );
	$expired = $lifecycle->period( $job );
	$assert( 0 === $output->return_code && false !== strpos( $output->stdout, 'Fictional listing period expired' ) && $expired['period_key'] === $period['period_key'] && $expired['expires'] < current_time( 'Y-m-d' ), 'CLI did not expire the same fictional period.' );
	$assert( ! \LlamaHire\Jobs::is_open( $job ) && $expired['expires'] === \LlamaHire\Jobs::get_meta( $job )['listing_expires'] && is_wp_error( $lifecycle->reconcile( $job ) ), 'Expired fixture remained available or changed its canonical expiry.' );
	$provider->offer = array( 'id' => wp_generate_uuid4(), 'predecessor' => $provider->offer['id'], 'days' => 7 );
	$assert( true === $lifecycle->reconcile( $job ) && 7 === $lifecycle->period( $job )['days'] && $expired === $lifecycle->period( $job, $expired['period_key'] ), 'Renewal failed to preserve the expired historical fixture.' );
	echo 'Publication fixture assertions passed: ' . $count . ".\n";
} finally {
	remove_filter( 'llamahire_listing_policies', $register );
	if ( $foreign ) { wp_delete_post( $foreign, true ); }
	if ( $owned ) { WP_CLI::runcommand( 'llamahire fixtures cleanup --yes', array( 'launch' => false, 'return' => true ) ); }
	wp_set_current_user( $actor ); remove_filter( 'pre_wp_mail', $mail );
}
