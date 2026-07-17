<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Stable identifiers for services owned by LlamaHire Free.
 */
final class Service_IDs {
	const APPLICATION_REPOSITORY = 'llamahire.application_repository';
	const APPLICATION_QUERY      = 'llamahire.application_query';
	const NOTIFICATIONS          = 'llamahire.notifications';
	const RESUME_STORAGE         = 'llamahire.resume_storage';
	const SCHEMA_BUILDER         = 'llamahire.schema_builder';

	private function __construct() {}
}
