<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/** Optional local-database coordination for required application extensions. */
interface Atomic_Application_Repository extends Application_Repository {
	/**
	 * Commit the canonical application and required extension writes together.
	 *
	 * The callback runs only for a newly created application, with its numeric ID.
	 * It must use the current WordPress connection and declared current-site tables,
	 * return true on success, and perform no DDL, transaction control, external I/O,
	 * cache writes or notification delivery. Call only outside another transaction.
	 * Duplicate submissions preserve the original record without calling the writer.
	 * Store uploads before calling; delete new uploads on failure or deduplication.
	 * Notify only after a successful result with created=true.
	 *
	 * @param array    $application Validated core fields, including submission key.
	 * @param callable $persist     Required extension writer accepting the application ID.
	 * @param string[] $tables      Fully qualified current-site extension table names.
	 * @return array|\WP_Error Same result as create_once(), or a content-free error.
	 */
	public function create_with_extension( array $application, callable $persist, array $tables );
}
