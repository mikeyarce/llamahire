<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/** Authorized numeric application references for extension-owned privacy data. */
interface Application_Privacy {
	/**
	 * Select one exact identity within the current user's privacy and candidate scope.
	 *
	 * @param string $email_address Exact candidate email identity.
	 * @param string $operation Either export or erase.
	 * @param int $page Positive page number; 100 references per page.
	 * @return array|\WP_Error Numeric items, current site ID and done flag, or a safe error.
	 */
	public function references( $email_address, $operation, $page = 1 );
}
