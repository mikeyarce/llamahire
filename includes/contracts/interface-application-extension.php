<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/** Required candidate fields and authorized historical answers for one extension. */
interface Application_Extension {
	/** Return escaped form markup using only this extension's namespaced inputs. */
	public function render( array $context, array $input, array $errors );

	/** Return bounded data, null when this job needs no write, or safe WP_Error. */
	public function prepare( array $context, array $input );

	/** Declare current-site tables written by persist(), for transaction checks. */
	public function tables();

	/** Write prepared data using the current connection. Return exactly true on success. */
	public function persist( $application_id, array $context, array $prepared );

	/** Return bounded historical plaintext rows; Free checks candidate access first. */
	public function review( $application_id );
}
