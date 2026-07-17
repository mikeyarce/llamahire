<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Public service registry exposed by LlamaHire Free.
 *
 * Extensions may register services during `llamahire_register_services` and
 * consume them after `llamahire_ready` fires.
 */
interface Service_Container {
	/**
	 * Register a service object.
	 *
	 * @param string $id      Stable service identifier.
	 * @param object $service Service implementation.
	 * @return void
	 */
	public function set( $id, $service );

	/**
	 * Retrieve a registered service.
	 *
	 * @param string $id Stable service identifier.
	 * @return object
	 * @throws \InvalidArgumentException When the service does not exist.
	 */
	public function get( $id );

	/**
	 * Determine whether a service exists.
	 *
	 * @param string $id Stable service identifier.
	 * @return bool
	 */
	public function has( $id );
}
