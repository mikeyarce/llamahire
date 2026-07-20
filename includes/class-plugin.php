<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $instance;
	private $services;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		$this->load_files();
		add_action( 'init', array( $this, 'init' ) );
	}

	private function load_files() {
		foreach ( array( 'interface-service-container.php', 'interface-application-repository.php', 'interface-application-query.php', 'interface-notification-service.php', 'interface-resume-storage.php', 'interface-schema-builder.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/contracts/' . $file;
		}
		foreach ( array( 'class-service-ids.php', 'class-service-container.php', 'class-settings.php', 'class-setup.php', 'class-migrations.php', 'class-capabilities.php', 'class-jobs.php', 'class-applications.php', 'class-blocks.php', 'class-admin.php', 'class-seo.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/' . $file;
		}
		foreach ( array( 'class-application-repository.php', 'class-application-query.php', 'class-notification-service.php', 'class-resume-storage.php', 'class-schema-builder.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/services/' . $file;
		}
	}

	public function init() {
		Migrations::maybe_run();
		Capabilities::maybe_install();
		$this->register_assets();
		$this->register_services();
		Jobs::register();
		Settings::register();
		Setup::register();
		Blocks::register();
		Applications::register();
		Admin::register();
		SEO::register();

		/**
		 * Fires after LlamaHire Free and its public services are ready.
		 *
		 * Pro and third-party extensions should begin runtime integration here.
		 *
		 * @param Plugin $plugin Initialized plugin instance.
		 */
		do_action( 'llamahire_ready', $this );
	}

	private function register_services() {
		$this->services = new Service_Container();
		$this->services->set( Service_IDs::APPLICATION_REPOSITORY, new Services\Application_Repository() );
		$this->services->set( Service_IDs::APPLICATION_QUERY, new Services\Application_Query() );
		$this->services->set( Service_IDs::NOTIFICATIONS, new Services\Notification_Service() );
		$this->services->set( Service_IDs::RESUME_STORAGE, new Services\Resume_Storage() );
		$this->services->set( Service_IDs::SCHEMA_BUILDER, new Services\Schema_Builder() );

		/**
		 * Fires while extensions may register or replace service implementations.
		 *
		 * This hook runs before the container is locked and before llamahire_ready.
		 * Replacements for Free service IDs must implement the matching contract.
		 *
		 * @param Service_Container $services Service registry.
		 * @param string            $version  Public API version.
		 */
		do_action( 'llamahire_register_services', $this->services, LLAMAHIRE_API_VERSION );
		$required = array(
			Service_IDs::APPLICATION_REPOSITORY => Contracts\Application_Repository::class,
			Service_IDs::APPLICATION_QUERY      => Contracts\Application_Query::class,
			Service_IDs::NOTIFICATIONS          => Contracts\Notification_Service::class,
			Service_IDs::RESUME_STORAGE         => Contracts\Resume_Storage::class,
			Service_IDs::SCHEMA_BUILDER         => Contracts\Schema_Builder::class,
		);
		foreach ( $required as $id => $contract ) {
			if ( ! $this->services->has( $id ) || ! is_a( $this->services->get( $id ), $contract ) ) {
				throw new \UnexpectedValueException( sprintf( 'The %1$s service must implement %2$s.', $id, $contract ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered.
			}
		}
		$this->services->lock();
	}

	/**
	 * Access the immutable runtime service registry.
	 *
	 * Call after `llamahire_ready` or later in the WordPress lifecycle.
	 *
	 * @return Contracts\Service_Container
	 * @throws \LogicException Before LlamaHire initializes.
	 */
	public function services() {
		if ( ! $this->services ) {
			throw new \LogicException( 'LlamaHire services are available after the llamahire_ready action.' );
		}
		return $this->services;
	}

	/**
	 * Public API version used for Free/Pro compatibility checks.
	 *
	 * @return string
	 */
	public function api_version() {
		return LLAMAHIRE_API_VERSION;
	}

	private function register_assets() {
		wp_register_style( 'llamahire', LLAMAHIRE_URL . 'assets/css/llamahire.css', array(), LLAMAHIRE_VERSION );
	}
}
