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
		foreach ( array( 'interface-service-container.php', 'interface-application-repository.php', 'interface-atomic-application-repository.php', 'interface-application-extension.php', 'interface-application-query.php', 'interface-notification-service.php', 'interface-resume-storage.php', 'interface-candidate-data-lifecycle.php', 'interface-schema-builder.php', 'interface-extension-access.php', 'interface-listing-policy.php', 'interface-job-lifecycle.php', 'interface-application-privacy.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/contracts/' . $file;
		}
		foreach ( array( 'class-service-ids.php', 'class-service-container.php', 'class-settings.php', 'class-telemetry.php', 'class-anti-spam.php', 'class-rate-limiter.php', 'class-setup.php', 'class-migrations.php', 'class-capabilities.php', 'class-jobs.php', 'class-listing-rules.php', 'class-listing-store.php', 'class-listing-lock.php', 'class-job-publication.php', 'class-geocoding.php', 'class-ownership.php', 'class-audit-log.php', 'class-application-notes.php', 'class-employer-notifications.php', 'class-employer-registration.php', 'class-employer-portal.php', 'class-employer-job-extensions.php', 'class-employer-account.php', 'class-employer-applications.php', 'class-applications.php', 'class-application-extensions.php', 'class-privacy.php', 'class-blocks.php', 'class-job-feed.php', 'class-theme-support.php', 'class-admin-workspaces.php', 'class-admin.php', 'class-rest-api.php', 'class-seo.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/' . $file;
		}
		foreach ( array( 'class-application-repository.php', 'class-application-query.php', 'class-notification-service.php', 'class-resume-storage.php', 'class-vip-acl-resume-storage.php', 'class-candidate-data-lifecycle.php', 'class-schema-builder.php', 'class-extension-access.php', 'class-job-lifecycle.php', 'class-application-privacy.php' ) as $file ) {
			require_once LLAMAHIRE_PATH . 'includes/services/' . $file;
		}
	}

	public function init() {
		Jobs::register();
		Geocoding::register();
		Migrations::register();
		Migrations::maybe_run();
		Capabilities::maybe_install();
		$this->register_assets();
		$this->register_services();
		Job_Publication::register();
		Audit_Log::register();
		Employer_Notifications::register();
		Employer_Registration::register();
		Employer_Portal::register();
		Employer_Account::register();
		Employer_Applications::register();
		Settings::register();
		Telemetry::register();
		Setup::register();
		Blocks::register();
		Job_Feed::register();
		Theme_Support::register();
		Applications::register();
		Privacy::register();
		Admin::register();
		REST_API::register();
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
		/**
		 * Filters the built-in private resume storage driver.
		 *
		 * Supported values are `local_private` and `vip_acl`. Unknown values
		 * fall back to the outside-webroot local driver.
		 *
		 * @param string $driver Resume storage driver identifier.
		 */
		$storage_driver = sanitize_key( apply_filters( 'llamahire_resume_storage_driver', 'local_private' ) );
		$resume_storage = 'vip_acl' === $storage_driver ? new Services\VIP_ACL_Resume_Storage() : new Services\Resume_Storage();
		$this->services->set( Service_IDs::RESUME_STORAGE, $resume_storage );
		$lifecycle = new Services\Candidate_Data_Lifecycle( $this->services->get( Service_IDs::APPLICATION_REPOSITORY ), $this->services->get( Service_IDs::RESUME_STORAGE ) );
		$this->services->set( Service_IDs::CANDIDATE_DATA, $lifecycle );
		$this->services->set( Service_IDs::SCHEMA_BUILDER, new Services\Schema_Builder() );
		$this->services->set( Service_IDs::JOB_LIFECYCLE, new Services\Job_Lifecycle() );
		$this->services->set( Service_IDs::APPLICATION_PRIVACY, new Services\Application_Privacy( $this->services ) );
		$extension_access = new Services\Extension_Access( $this->services->get( Service_IDs::APPLICATION_REPOSITORY ) );
		$this->services->set( Service_IDs::EXTENSION_ACCESS, $extension_access );

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
			Service_IDs::CANDIDATE_DATA         => Contracts\Candidate_Data_Lifecycle::class,
			Service_IDs::SCHEMA_BUILDER         => Contracts\Schema_Builder::class,
			Service_IDs::EXTENSION_ACCESS       => Contracts\Extension_Access::class,
			Service_IDs::JOB_LIFECYCLE          => Contracts\Job_Lifecycle::class,
			Service_IDs::APPLICATION_PRIVACY    => Contracts\Application_Privacy::class,
		);
		foreach ( $required as $id => $contract ) {
			if ( ! $this->services->has( $id ) || ! is_a( $this->services->get( $id ), $contract ) ) {
				throw new \UnexpectedValueException( sprintf( 'The %1$s service must implement %2$s.', $id, $contract ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered.
			}
		}
		// Also update the original instance when an extension decorates it.
		$lifecycle->set_dependencies( $this->services->get( Service_IDs::APPLICATION_REPOSITORY ), $this->services->get( Service_IDs::RESUME_STORAGE ) );
		$extension_access->set_repository( $this->services->get( Service_IDs::APPLICATION_REPOSITORY ) );
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
		wp_register_script( 'llamahire-application-form', LLAMAHIRE_URL . 'assets/js/application-form.js', array(), LLAMAHIRE_VERSION, true );
		wp_register_script_module(
			'llamahire-job-discovery',
			LLAMAHIRE_URL . 'assets/js/job-discovery.js',
			array(
				'@wordpress/interactivity',
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			LLAMAHIRE_VERSION
		);
	}
}
