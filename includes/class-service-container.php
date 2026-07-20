<?php
namespace LlamaHire;

use LlamaHire\Contracts\Service_Container as Service_Container_Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Small immutable-at-runtime service registry.
 */
final class Service_Container implements Service_Container_Contract {
	private $services = array();
	private $locked   = false;

	public function set( $id, $service ) {
		if ( $this->locked ) {
			throw new \LogicException( 'LlamaHire services cannot be changed after llamahire_ready.' );
		}
		if ( ! is_string( $id ) || '' === trim( $id ) || ! is_object( $service ) ) {
			throw new \InvalidArgumentException( 'A LlamaHire service requires a non-empty ID and an object implementation.' );
		}
		$this->services[ $id ] = $service;
	}

	public function get( $id ) {
		if ( ! $this->has( $id ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown LlamaHire service: %s', (string) $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered.
		}
		return $this->services[ $id ];
	}

	public function has( $id ) {
		return isset( $this->services[ $id ] );
	}

	/**
	 * Prevent runtime service replacement after extensions have registered.
	 *
	 * @internal Called by LlamaHire Free during initialization.
	 */
	public function lock() {
		$this->locked = true;
	}
}
