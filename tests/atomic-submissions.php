<?php
/** Disposable real-database contract tests; output contains no candidate values. */
if ( 'local' !== wp_get_environment_type() || '/var/www/html/' !== ABSPATH ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
global $wpdb;
$repository = \LlamaHire\Plugin::instance()->services()->get( \LlamaHire\Service_IDs::APPLICATION_REPOSITORY );
if ( ! $repository instanceof \LlamaHire\Contracts\Atomic_Application_Repository ) {
	throw new RuntimeException( 'Atomic repository contract missing.' );
}
$table = $wpdb->prefix . 'llamahire_atomic_fixture';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
	throw new RuntimeException( 'Refusing to replace an existing fixture table.' );
}
$assert = static function ( $condition, $code ) {
	if ( ! $condition ) { throw new RuntimeException( $code ); }
};
$job = wp_insert_post( array( 'post_type' => 'llamahire_job', 'post_status' => 'draft', 'post_title' => 'Atomic contract fixture' ) );
$ids = array();
try {
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (application_id bigint NOT NULL, value varchar(20) NOT NULL, PRIMARY KEY (application_id))', $table ) );
	$application = array( 'job_id' => $job, 'name' => 'Fictional contract fixture', 'email' => 'atomic-fixture@example.test', 'submission_key' => wp_generate_uuid4() );
	$write = static function ( $id ) use ( $wpdb, $table, &$ids ) {
		$ids[] = $id;
		return 1 === $wpdb->insert( $table, array( 'application_id' => $id, 'value' => 'first' ) );
	};
	$empty = static function () use ( $repository, $application, $wpdb, $table ) {
		return 0 === $repository->find_duplicate( $application['job_id'], $application['email'] ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	};
	$result = $repository->create_with_extension( $application, static function ( $id ) use ( $write ) { $write( $id ); return false; }, array( $table ) );
	$assert( is_wp_error( $result ) && $empty(), 'Required-write failure must roll back both records.' );
	$result = $repository->create_with_extension( $application, static function ( $id ) use ( $write ) { $write( $id ); throw new RuntimeException( 'Do not surface this provider detail.' ); }, array( $table ) );
	$assert( is_wp_error( $result ) && $empty() && false === strpos( $result->get_error_message(), 'provider detail' ), 'Thrown failures must roll back without exposing exception text.' );
	$break_commit = static function ( $query ) { return 'COMMIT' === $query ? 'SELECT * FROM llamahire_nonexistent_commit_fixture' : $query; };
	add_filter( 'query', $break_commit );
	try { $result = $repository->create_with_extension( $application, $write, array( $table ) ); }
	finally { remove_filter( 'query', $break_commit ); }
	$assert( is_wp_error( $result ) && $empty(), 'Commit failure must roll back both records.' );
	$result = $repository->create_with_extension( $application, $write, array( $table . '_missing' ) );
	$assert( is_wp_error( $result ) && $empty(), 'Missing extension storage must fail before core creation.' );
	$result = $repository->create_with_extension( $application, static function ( $id ) use ( $repository, $application, $write, $table, $assert ) {
		$assert( is_wp_error( $repository->create_with_extension( $application, $write, array( $table ) ) ), 'Reentry must fail.' );
		return false;
	}, array( $table ) );
	$assert( is_wp_error( $result ) && $empty(), 'Reentry must not commit the outer submission.' );
	$result = $repository->create_with_extension( $application, $write, array( $table ) );
	$assert( ! is_wp_error( $result ) && $result['created'], 'Successful retry must create both records.' );
	$canonical = $result['id'];
	$assert( $repository->find( $canonical ) && 'first' === $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE application_id = %d', $table, $canonical ) ), 'Both committed records must be readable.' );
	$unexpected = static function () { throw new RuntimeException( 'A duplicate must never invoke its extension writer.' ); };
	$result = $repository->create_with_extension( $application, $unexpected, array( $table ) );
	$assert( ! is_wp_error( $result ) && ! $result['created'] && $canonical === $result['id'], 'Same-key retry must preserve the canonical snapshot.' );
	$application['submission_key'] = wp_generate_uuid4();
	$result = $repository->create_with_extension( $application, $unexpected, array( $table ) );
	$assert( ! is_wp_error( $result ) && ! $result['created'] && 'job_email' === $result['reason'] && $canonical === $result['id'], 'Same-job/email duplicate must preserve the canonical snapshot.' );
	echo "Atomic submission rollback, retry and duplicate contracts passed.\n";
} finally {
	foreach ( array_unique( $ids ) as $id ) { $repository->delete( $id ); }
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	wp_delete_post( $job, true );
}
