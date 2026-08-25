<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * First-run setup state and organization-details step.
 */
final class Setup {
	const OPTION  = 'llamahire_setup';
	const VERSION = '4';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_llamahire_save_setup', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_llamahire_skip_setup', array( __CLASS__, 'skip' ) );
		add_action( 'admin_post_llamahire_restart_setup', array( __CLASS__, 'restart' ) );
	}

	public static function defaults() {
		return array(
			'version' => self::VERSION,
			'status'  => 'pending',
		);
	}

	public static function state() {
		$stored = get_option( self::OPTION, false );
		if ( false === $stored ) {
			return array(
				'version' => self::VERSION,
				'status'  => 'skipped',
			);
		}
		$state = wp_parse_args( (array) $stored, self::defaults() );
		if ( ! in_array( $state['status'], array( 'pending', 'completed', 'skipped' ), true ) ) {
			$state['status'] = 'pending';
		}
		$state['version'] = sanitize_text_field( $state['version'] );
		return $state;
	}

	public static function is_complete() {
		return 'completed' === self::state()['status'];
	}

	/**
	 * Queue setup only for a site's first activation.
	 */
	public static function mark_pending() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function menu() {
		$parent = 'edit.php?post_type=' . Jobs::POST_TYPE;
		add_submenu_page(
			$parent,
			__( 'Set up LlamaHire', 'llamahire' ),
			__( 'Setup', 'llamahire' ),
			'manage_options',
			'llamahire-setup',
			array( __CLASS__, 'page' )
		);
		if ( self::is_complete() ) {
			remove_submenu_page( $parent, 'llamahire-setup' );
		}
	}

	public static function maybe_redirect() {
		global $pagenow;
		if ( 'pending' !== self::state()['status'] || ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI || defined( 'DOING_CRON' ) && DOING_CRON || defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( 'admin-post.php' === $pagenow || 'llamahire-setup' === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing checks prevent a setup redirect loop.
			return;
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-setup' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure LlamaHire.', 'llamahire' ), 403 );
		}
		wp_enqueue_style(
			'llamahire-admin-setup',
			LLAMAHIRE_URL . 'assets/css/admin-setup.css',
			array(),
			(string) filemtime( LLAMAHIRE_PATH . 'assets/css/admin-setup.css' )
		);
		$error = get_transient( 'llamahire_setup_error_' . get_current_user_id() );
		$state = self::state();
		$draft = ! empty( $state['draft'] ) && is_array( $state['draft'] ) ? $state['draft'] : array();
		if ( ! empty( $error['values'] ) ) {
			$settings = wp_parse_args( $error['values'], Settings::get() );
		} elseif ( ! empty( $draft['settings'] ) ) {
			$settings = wp_parse_args( $draft['settings'], Settings::get() );
		} else {
			$settings = Settings::get();
		}
		$is_job_board = Settings::SITE_MODE_JOB_BOARD === $settings['site_mode'];
		$careers_action = sanitize_key( $error['careers_action'] ?? $draft['careers_action'] ?? ( self::public_jobs_page( $settings['careers_page_id'] ) ? 'select' : 'create' ) );
		$careers_title  = sanitize_text_field( $error['careers_title'] ?? $draft['careers_title'] ?? ( $is_job_board ? __( 'Jobs', 'llamahire' ) : __( 'Careers', 'llamahire' ) ) );
		$initial_step   = max( 1, min( 4, absint( $error['step'] ?? $draft['step'] ?? 1 ) ) );
		$wp_privacy_page = Settings::public_page( get_option( 'wp_page_for_privacy_policy' ) );
		$privacy_empty_label = $wp_privacy_page ? sprintf(
			/* translators: %s: Current WordPress privacy policy page title. */
			__( 'Use WordPress privacy policy — %s', 'llamahire' ),
			get_the_title( $wp_privacy_page )
		) : __( 'No WordPress privacy policy is configured', 'llamahire' );
		$retention_options = array(
			30 => __( '30 days', 'llamahire' ), 90 => __( '90 days', 'llamahire' ), 180 => __( '180 days', 'llamahire' ),
			365 => __( '1 year', 'llamahire' ), 730 => __( '2 years', 'llamahire' ), 1095 => __( '3 years', 'llamahire' ),
			1825 => __( '5 years', 'llamahire' ), 0 => __( 'Keep until manually erased', 'llamahire' ),
		);
		$step_names = array(
			1 => __( 'Site purpose', 'llamahire' ),
			2 => __( 'Identity & defaults', 'llamahire' ),
			3 => __( 'Applications & privacy', 'llamahire' ),
			4 => __( 'Public jobs page', 'llamahire' ),
		);
		$progress_value_text = __( 'Not complete', 'llamahire' );
		if ( 1 !== $initial_step ) {
			$progress_value_text = sprintf(
				/* translators: %d: Current setup step. */
				__( 'Step %d of 4', 'llamahire' ),
				$initial_step
			);
		}
		delete_transient( 'llamahire_setup_error_' . get_current_user_id() );
		?>
		<div class="wrap llamahire-setup-screen" data-llamahire-setup data-initial-step="<?php echo esc_attr( $initial_step ); ?>">
			<h1><?php esc_html_e( 'Set up LlamaHire', 'llamahire' ); ?></h1>
			<div class="llamahire-setup-progress">
				<p class="llamahire-setup-progress__status" aria-live="polite">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Current setup step. 2: Total setup steps. 3: Current step name. */
							__( 'Step %1$d of %2$d — %3$s', 'llamahire' ),
							$initial_step,
							4,
							$step_names[ $initial_step ]
						)
					);
					?>
				</p>
				<progress class="screen-reader-text" value="<?php echo esc_attr( $initial_step - 1 ); ?>" max="4" aria-label="<?php esc_attr_e( 'Setup progress: purpose, identity and defaults, applications and privacy, and public jobs page', 'llamahire' ); ?>" aria-valuetext="<?php echo esc_attr( $progress_value_text ); ?>"><?php echo esc_html( ( $initial_step - 1 ) * 25 ); ?>%</progress>
				<ol class="llamahire-setup-steps" aria-label="<?php esc_attr_e( 'Setup steps', 'llamahire' ); ?>">
					<?php foreach ( $step_names as $step_number => $step_name ) : ?><li data-llamahire-step-indicator="<?php echo esc_attr( $step_number ); ?>"><span class="llamahire-setup-steps__marker" aria-hidden="true"><?php echo esc_html( $step_number ); ?></span><span><?php echo esc_html( $step_name ); ?></span></li><?php endforeach; ?>
				</ol>
			</div>
			<?php if ( $error ) : ?><div class="notice notice-error inline" role="alert" tabindex="-1"><p><?php echo esc_html( $error['message'] ); ?></p></div><?php endif; ?>
			<?php if ( $draft && ! $error ) : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'Your saved Setup progress has been restored.', 'llamahire' ); ?></p></div><?php endif; ?>
			<form class="llamahire-setup-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llamahire_save_setup">
				<input type="hidden" name="setup_step" value="<?php echo esc_attr( $initial_step ); ?>" data-llamahire-setup-step-input>
				<?php wp_nonce_field( 'llamahire_save_setup', 'llamahire_save_setup_nonce' ); ?>
				<section class="llamahire-setup-step" data-llamahire-setup-step="1" aria-labelledby="llamahire-setup-step-1-title">
					<h2 id="llamahire-setup-step-1-title" tabindex="-1"><?php esc_html_e( 'How will you use LlamaHire?', 'llamahire' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose the setup that matches how this site publishes jobs.', 'llamahire' ); ?></p>
					<fieldset class="llamahire-setup-purpose">
						<legend class="screen-reader-text"><?php esc_html_e( 'Site purpose', 'llamahire' ); ?></legend>
						<label><input type="radio" name="organization[site_mode]" value="company" <?php checked( Settings::SITE_MODE_COMPANY, $settings['site_mode'] ); ?>> <span><strong><?php esc_html_e( 'Company careers site', 'llamahire' ); ?></strong><span class="description"><?php esc_html_e( 'Publish jobs for one organization using shared employer defaults.', 'llamahire' ); ?></span></span></label>
						<label><input type="radio" name="organization[site_mode]" value="job_board" <?php checked( Settings::SITE_MODE_JOB_BOARD, $settings['site_mode'] ); ?>> <span><strong><?php esc_html_e( 'Multi-employer job board', 'llamahire' ); ?></strong><span class="description"><?php esc_html_e( 'Publish listings from multiple employers with approved employer accounts, submission pages, and per-job application routing.', 'llamahire' ); ?></span></span></label>
					</fieldset>
				</section>

				<section class="llamahire-setup-step" data-llamahire-setup-step="2" aria-labelledby="llamahire-setup-step-2-title">
					<h2 id="llamahire-setup-step-2-title" tabindex="-1"><?php esc_html_e( 'Identity & job defaults', 'llamahire' ); ?></h2>
					<p class="description"><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'Set the employer identity and the values new jobs start with.', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Set the board-operator identity and the values new listings start with. Employers can replace them per listing.', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Set the board-operator identity and the values new listings start with. Employers can replace them per listing.', 'llamahire' ) : __( 'Set the employer identity and the values new jobs start with.', 'llamahire' ) ); ?></span></p>
					<div class="llamahire-setup-identity-columns">
						<div class="llamahire-setup-panel">
							<h3><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'Organization identity', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Job board identity', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Job board identity', 'llamahire' ) : __( 'Organization identity', 'llamahire' ) ); ?></span></h3>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-setup-name"><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'Organization name', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Job board name', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Job board name', 'llamahire' ) : __( 'Organization name', 'llamahire' ) ); ?></span> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label></th><td><input class="regular-text" type="text" id="llamahire-setup-name" name="organization[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required aria-describedby="llamahire-setup-name-description"><p class="description" id="llamahire-setup-name-description"><?php esc_html_e( 'This is the name candidates will see on job postings.', 'llamahire' ); ?></p></td></tr>
								<tr data-llamahire-company-website <?php echo $is_job_board ? 'hidden' : ''; ?>><th scope="row"><label for="llamahire-setup-website"><?php esc_html_e( 'Organization website', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-setup-website" name="organization[website]" value="<?php echo esc_attr( $settings['website'] ); ?>" aria-describedby="llamahire-setup-website-description" <?php disabled( $is_job_board ); ?>><p class="description" id="llamahire-setup-website-description"><?php esc_html_e( 'Optional. Use the organization’s main website only when this careers site is on a different domain or subdomain.', 'llamahire' ); ?></p></td></tr>
							</table>
							<h3><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'Organization logo', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Job board logo', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Job board logo', 'llamahire' ) : __( 'Organization logo', 'llamahire' ) ); ?></span></h3>
							<p class="description"><?php esc_html_e( 'This logo will appear on job postings and careers pages.', 'llamahire' ); ?></p>
							<?php Settings::logo_field( 'llamahire-setup-logo', 'organization[logo]', $settings['logo'] ); ?>
						</div>
						<div class="llamahire-setup-panel">
							<h3><?php esc_html_e( 'Job defaults', 'llamahire' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Editors can override these values on every job.', 'llamahire' ); ?></p>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-setup-locality"><?php esc_html_e( 'Default city or locality', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-setup-locality" name="organization[default_locality]" value="<?php echo esc_attr( $settings['default_locality'] ); ?>" placeholder="Vancouver"></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-region"><?php esc_html_e( 'Default state, province, or region', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-setup-region" name="organization[default_region]" value="<?php echo esc_attr( $settings['default_region'] ); ?>" placeholder="British Columbia"></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-country"><?php esc_html_e( 'Default country', 'llamahire' ); ?></label></th><td><?php Settings::country_select( 'llamahire-setup-country', 'organization[default_country]', $settings['default_country'] ); ?></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-currency"><?php esc_html_e( 'Default currency', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label></th><td><?php Settings::currency_select( 'llamahire-setup-currency', 'organization[default_currency]', $settings['default_currency'] ); ?></td></tr>
							</table>
						</div>
					</div>
				</section>

				<section class="llamahire-setup-step" data-llamahire-setup-step="3" aria-labelledby="llamahire-setup-step-3-title">
					<h2 id="llamahire-setup-step-3-title" tabindex="-1"><?php esc_html_e( 'Applications & privacy', 'llamahire' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose where application alerts go and review the privacy notice candidates will see.', 'llamahire' ); ?></p>
					<div class="llamahire-setup-privacy-columns">
						<div class="llamahire-setup-panel">
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-setup-email"><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'Hiring inbox', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Board notification inbox', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Board notification inbox', 'llamahire' ) : __( 'Hiring inbox', 'llamahire' ) ); ?></span> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label></th><td><input class="regular-text" type="email" id="llamahire-setup-email" name="organization[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" required aria-describedby="llamahire-setup-email-description"><p class="description" id="llamahire-setup-email-description"><span data-llamahire-mode-copy data-company-copy="<?php esc_attr_e( 'New application notifications are sent here.', 'llamahire' ); ?>" data-job-board-copy="<?php esc_attr_e( 'Board-level notifications use this address; employer routing is configured per job.', 'llamahire' ); ?>"><?php echo esc_html( $is_job_board ? __( 'Board-level notifications use this address; employer routing is configured per job.', 'llamahire' ) : __( 'New application notifications are sent here.', 'llamahire' ) ); ?></span></p></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-privacy-text"><?php esc_html_e( 'Candidate privacy text', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label></th><td><textarea class="large-text" rows="4" id="llamahire-setup-privacy-text" name="organization[privacy_text]" required aria-describedby="llamahire-setup-privacy-text-description" data-company-default="<?php echo esc_attr( Settings::default_privacy_text( Settings::SITE_MODE_COMPANY ) ); ?>" data-job-board-default="<?php echo esc_attr( Settings::default_privacy_text( Settings::SITE_MODE_JOB_BOARD ) ); ?>"><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea><p class="description" id="llamahire-setup-privacy-text-description"><?php esc_html_e( 'Shown beside every application form. This operational preview is not legal advice.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-privacy-page"><?php esc_html_e( 'Privacy policy page', 'llamahire' ); ?></label></th><td><?php Settings::page_select( 'llamahire-setup-privacy-page', 'organization[privacy_page_id]', $settings['privacy_page_id'], $privacy_empty_label, 'llamahire-setup-privacy-page-description' ); ?><p class="description" id="llamahire-setup-privacy-page-description"><?php esc_html_e( 'Choose a published page, or use the page selected in WordPress Settings → Privacy.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-setup-retention"><?php esc_html_e( 'Application retention', 'llamahire' ); ?></label></th><td><select class="regular-text" id="llamahire-setup-retention" name="organization[retention_days]"><?php foreach ( $retention_options as $days => $label ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $settings['retention_days'], $days ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Older applications and private resumes are permanently deleted by the daily cleanup task.', 'llamahire' ); ?></p></td></tr>
							</table>
						</div>
						<aside class="llamahire-candidate-preview" aria-labelledby="llamahire-candidate-preview-title" data-default-policy-available="<?php echo $wp_privacy_page ? '1' : '0'; ?>">
							<p class="llamahire-candidate-preview__eyebrow"><?php esc_html_e( 'Candidate-facing preview', 'llamahire' ); ?></p>
							<h3 id="llamahire-candidate-preview-title"><?php esc_html_e( 'Application privacy notice', 'llamahire' ); ?></h3>
							<div class="llamahire-candidate-preview__surface"><p><span data-llamahire-preview-privacy><?php echo esc_html( $settings['privacy_text'] ); ?></span> <span class="llamahire-candidate-preview__link" data-llamahire-preview-policy-link><?php esc_html_e( 'Read our privacy policy.', 'llamahire' ); ?></span> <span data-llamahire-preview-retention></span></p></div>
							<p class="description"><span data-llamahire-preview-policy-source></span></p>
						</aside>
					</div>
				</section>

				<section class="llamahire-setup-step" data-llamahire-setup-step="4" aria-labelledby="llamahire-setup-step-4-title">
					<h2 id="llamahire-setup-step-4-title" tabindex="-1"><?php esc_html_e( 'Public jobs page', 'llamahire' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose the compatible page where candidates will discover open jobs, then review everything before completion.', 'llamahire' ); ?></p>
					<fieldset class="llamahire-setup-careers">
						<legend class="screen-reader-text"><?php esc_html_e( 'Public jobs page choice', 'llamahire' ); ?></legend>
						<p><label><input type="radio" name="careers_action" value="create" <?php checked( 'create', $careers_action ); ?>> <?php esc_html_e( 'Create and publish a new public jobs page', 'llamahire' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'The new page includes job search, filters, and the jobs directory. You can edit it after Setup.', 'llamahire' ); ?></p>
						<p><label for="llamahire-careers-title"><?php esc_html_e( 'New page title', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label><br><input class="regular-text" type="text" id="llamahire-careers-title" name="careers_title" value="<?php echo esc_attr( $careers_title ); ?>" data-company-default="<?php esc_attr_e( 'Careers', 'llamahire' ); ?>" data-job-board-default="<?php esc_attr_e( 'Jobs', 'llamahire' ); ?>"></p>
						<p><label><input type="radio" name="careers_action" value="select" <?php checked( 'select', $careers_action ); ?>> <?php esc_html_e( 'Use an existing published page', 'llamahire' ); ?></label></p>
						<p><label for="llamahire-setup-careers-page"><?php esc_html_e( 'Existing public jobs page', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></label><br><?php self::jobs_page_select( 'llamahire-setup-careers-page', 'organization[careers_page_id]', $settings['careers_page_id'] ); ?></p>
					</fieldset>
					<div class="llamahire-setup-callout" data-llamahire-job-board-only <?php echo $is_job_board ? '' : 'hidden'; ?>>
						<h3><?php esc_html_e( 'Employer access pages', 'llamahire' ); ?></h3>
						<p><?php esc_html_e( 'Completing Setup also publishes Submit a Job and My Jobs pages when compatible pages do not already exist. Employer accounts remain administrator-approved.', 'llamahire' ); ?></p>
					</div>
					<div class="llamahire-setup-review" aria-labelledby="llamahire-setup-review-title">
						<h3 id="llamahire-setup-review-title"><?php esc_html_e( 'Setup summary', 'llamahire' ); ?></h3>
						<div class="llamahire-setup-review__groups">
							<section><header><h4><?php esc_html_e( 'Purpose', 'llamahire' ); ?></h4><button type="button" class="button-link" data-llamahire-setup-edit="1" aria-label="<?php esc_attr_e( 'Edit site purpose', 'llamahire' ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></button></header><p data-llamahire-review="purpose"></p></section>
							<section><header><h4><?php esc_html_e( 'Identity & defaults', 'llamahire' ); ?></h4><button type="button" class="button-link" data-llamahire-setup-edit="2" aria-label="<?php esc_attr_e( 'Edit identity and defaults', 'llamahire' ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></button></header><p data-llamahire-review="identity"></p><p data-llamahire-review="defaults"></p></section>
							<section><header><h4><?php esc_html_e( 'Applications & privacy', 'llamahire' ); ?></h4><button type="button" class="button-link" data-llamahire-setup-edit="3" aria-label="<?php esc_attr_e( 'Edit applications and privacy', 'llamahire' ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></button></header><p data-llamahire-review="email"></p><p data-llamahire-review="privacy"></p><p data-llamahire-review="retention"></p></section>
							<section><header><h4><?php esc_html_e( 'Public jobs page', 'llamahire' ); ?></h4><button type="button" class="button-link" data-llamahire-setup-edit="4" aria-label="<?php esc_attr_e( 'Edit public jobs page', 'llamahire' ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></button></header><p data-llamahire-review="careers"></p><p data-llamahire-review="publication"></p><p data-llamahire-review="employer-pages" data-llamahire-job-board-only <?php echo $is_job_board ? '' : 'hidden'; ?>></p></section>
						</div>
					</div>
				</section>

				<div class="llamahire-setup-actions">
					<button class="button" type="button" data-llamahire-setup-back hidden><?php esc_html_e( 'Back', 'llamahire' ); ?></button>
					<button class="button llamahire-setup-save-later" type="submit" name="setup_intent" value="skip" formnovalidate data-llamahire-setup-skip><?php esc_html_e( 'Save and finish later', 'llamahire' ); ?></button>
					<span class="llamahire-setup-actions__spacer"></span>
					<button class="button button-primary" type="button" data-llamahire-setup-next hidden><?php esc_html_e( 'Continue', 'llamahire' ); ?></button>
					<button class="button button-primary" type="submit" data-llamahire-setup-submit><?php esc_html_e( 'Complete setup', 'llamahire' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	public static function notice() {
		$result = sanitize_key( wp_unslash( $_GET['llamahire_setup'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $result, array( 'completed', 'skipped' ), true ) ) {
			return;
		}
		if ( 'completed' === $result ) {
			$careers_page = Settings::public_page( Settings::get()['careers_page_id'] );
			if ( $careers_page ) {
				$message = sprintf(
					/* translators: %s: Careers page URL. */
					__( 'LlamaHire setup is complete. <a href="%s">View your public jobs page</a> or add your first job.', 'llamahire' ),
					esc_url( get_permalink( $careers_page ) )
				);
			} else {
				$message = __( 'LlamaHire setup is complete. Add your first job when you are ready.', 'llamahire' );
			}
		} else {
			$message = sprintf(
				/* translators: %s: setup page URL. */
				__( 'Setup progress saved. You can <a href="%s">resume setup</a> at any time.', 'llamahire' ),
				esc_url( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-setup' ) )
			);
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $message ); ?></p></div>
		<?php
	}

	public static function save() {
		check_admin_referer( 'llamahire_save_setup', 'llamahire_save_setup_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure LlamaHire.', 'llamahire' ), 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize() normalizes every supported field below.
		$input    = isset( $_POST['organization'] ) ? (array) wp_unslash( $_POST['organization'] ) : array();
		$current  = Settings::get();
		$input    = self::preserve_unmanaged_settings( $input, $current );
		$settings = Settings::sanitize( $input );
		$careers_action = sanitize_key( wp_unslash( $_POST['careers_action'] ?? '' ) );
		$careers_title  = sanitize_text_field( wp_unslash( $_POST['careers_title'] ?? '' ) );
		if ( 'skip' === sanitize_key( wp_unslash( $_POST['setup_intent'] ?? '' ) ) ) {
			update_option(
				self::OPTION,
				array(
					'version' => self::VERSION,
					'status'  => 'skipped',
					'draft'   => array(
						'settings'       => $settings,
						'careers_action' => in_array( $careers_action, array( 'create', 'select' ), true ) ? $careers_action : 'create',
						'careers_title'  => $careers_title,
						'step'           => max( 1, min( 4, absint( $_POST['setup_step'] ?? 1 ) ) ),
					),
				),
				false
			);
			self::redirect( 'skipped' );
		}
		if ( ! $settings['name'] || ! $settings['default_currency'] ) {
			self::setup_error( __( 'Enter an organization name and choose a default currency.', 'llamahire' ), $settings, $careers_action, $careers_title, 2 );
		}
		if ( ! is_email( $settings['notification_email'] ) || ! $settings['privacy_text'] ) {
			self::setup_error( __( 'Enter a valid hiring inbox and candidate privacy text.', 'llamahire' ), $settings, $careers_action, $careers_title, 3 );
		}
		if ( $settings['privacy_page_id'] && ! Settings::public_page( $settings['privacy_page_id'] ) ) {
			self::setup_error( __( 'Choose a published privacy policy page.', 'llamahire' ), $settings, $careers_action, $careers_title, 3 );
		}
		if ( 'create' === $careers_action ) {
			$careers_page_id = self::create_careers_page( $careers_title );
			if ( is_wp_error( $careers_page_id ) ) {
				self::setup_error( $careers_page_id->get_error_message(), $settings, $careers_action, $careers_title, 4 );
			}
			$settings['careers_page_id'] = $careers_page_id;
		} elseif ( 'select' === $careers_action && self::public_jobs_page( $settings['careers_page_id'] ) ) {
			$settings['careers_page_id'] = absint( $settings['careers_page_id'] );
		} else {
			self::setup_error( __( 'Create a public jobs page or select a published page containing the LlamaHire Jobs Directory block.', 'llamahire' ), $settings, $careers_action, $careers_title, 4 );
		}
		update_option( Settings::OPTION, $settings, false );
		update_option( self::OPTION, array( 'version' => self::VERSION, 'status' => 'completed' ), false );
		self::redirect( 'completed' );
	}

	private static function preserve_unmanaged_settings( array $input, array $current ) {
		foreach ( array( 'application_phone', 'application_resume', 'application_letter', 'google_geocoding_api_key', 'anti_spam_provider', 'anti_spam_site_key', 'anti_spam_secret_key', 'anti_spam_registration', 'anti_spam_applications', 'email_sender_name', 'email_sender_email', 'employer_email_subject', 'employer_email_body', 'candidate_email_subject', 'candidate_email_body', 'submit_job_page_id', 'my_jobs_page_id', 'employer_registration_page_id', 'employer_approval', 'employer_policy_text', 'employer_policy_page_id', 'active_listing_limit', 'listing_duration_days' ) as $key ) {
			$input[ $key ] = $current[ $key ];
		}
		return $input;
	}

	public static function careers_page_content() {
		ob_start();
		include LLAMAHIRE_PATH . 'patterns/careers-page.php';
		return trim( ob_get_clean() );
	}

	public static function create_careers_page( $title ) {
		$title = sanitize_text_field( $title );
		if ( ! $title ) {
			return new \WP_Error( 'llamahire_careers_title', __( 'Enter a title for the new Careers page.', 'llamahire' ) );
		}
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'llamahire_careers_permission', __( 'You cannot create and publish a Careers page.', 'llamahire' ) );
		}
		$existing = get_page_by_path( sanitize_title( $title ), OBJECT, 'page' );
		if ( $existing && 'publish' === $existing->post_status && has_block( 'llamahire/jobs-directory', $existing->post_content ) ) {
			return $existing->ID;
		}
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => self::careers_page_content(),
			),
			true
		);
	}

	public static function public_jobs_page( $page_id ) {
		$page = Settings::public_page( $page_id );
		return $page && has_block( 'llamahire/jobs-directory', $page->post_content ) ? $page : null;
	}

	private static function jobs_page_select( $id, $name, $selected ) {
		$pages = array_filter(
			get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) ),
			static function ( $page ) {
				return has_block( 'llamahire/jobs-directory', $page->post_content );
			}
		);
		?>
		<select class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<option value="0"><?php esc_html_e( 'Select a page with a LlamaHire jobs directory', 'llamahire' ); ?></option>
			<?php foreach ( $pages as $page ) : ?><option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( absint( $selected ), $page->ID ); ?>><?php echo esc_html( get_the_title( $page ) ); ?></option><?php endforeach; ?>
		</select>
		<?php
	}

	public static function skip() {
		self::require_access( 'llamahire_skip_setup', 'llamahire_skip_setup_nonce' );
		update_option( self::OPTION, array( 'version' => self::VERSION, 'status' => 'skipped' ), false );
		self::redirect( 'skipped' );
	}

	public static function restart() {
		self::require_access( 'llamahire_restart_setup', 'llamahire_restart_setup_nonce' );
		update_option( self::OPTION, self::defaults(), false );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-setup' ) );
		exit;
	}

	private static function require_access( $nonce_action, $nonce_name ) {
		check_admin_referer( $nonce_action, $nonce_name );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure LlamaHire.', 'llamahire' ), 403 );
		}
	}

	private static function setup_error( $message, array $settings, $careers_action, $careers_title, $step = 1 ) {
		set_transient(
			'llamahire_setup_error_' . get_current_user_id(),
			array(
				'message'        => sanitize_text_field( $message ),
				'values'         => $settings,
				'careers_action' => sanitize_key( $careers_action ),
				'careers_title'  => sanitize_text_field( $careers_title ),
				'step'           => max( 1, min( 4, absint( $step ) ) ),
			),
			MINUTE_IN_SECONDS
		);
		self::redirect( 'error' );
	}

	private static function redirect( $result ) {
		wp_safe_redirect( add_query_arg( 'llamahire_setup', sanitize_key( $result ), admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE ) ) );
		exit;
	}

	private function __construct() {}
}
