<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * First-run setup state and organization-details step.
 */
final class Setup {
	const OPTION  = 'llamahire_setup';
	const VERSION = '2';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_llamahire_save_setup', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_llamahire_skip_setup', array( __CLASS__, 'skip' ) );
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

	/**
	 * Queue setup only for a site's first activation.
	 */
	public static function mark_pending() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Jobs::POST_TYPE,
			__( 'Set up LlamaHire', 'llamahire' ),
			__( 'Setup', 'llamahire' ),
			'manage_options',
			'llamahire-setup',
			array( __CLASS__, 'page' )
		);
	}

	public static function maybe_redirect() {
		global $pagenow;
		if ( 'pending' !== self::state()['status'] || ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI || defined( 'DOING_CRON' ) && DOING_CRON || defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( 'admin-post.php' === $pagenow || 'llamahire-setup' === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-setup' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure LlamaHire.', 'llamahire' ), 403 );
		}
		$error    = get_transient( 'llamahire_setup_error_' . get_current_user_id() );
		$settings = ! empty( $error['values'] ) ? wp_parse_args( $error['values'], Settings::get() ) : Settings::get();
		$careers_action = sanitize_key( $error['careers_action'] ?? ( Settings::public_page( $settings['careers_page_id'] ) ? 'select' : 'create' ) );
		$careers_title  = sanitize_text_field( $error['careers_title'] ?? __( 'Careers', 'llamahire' ) );
		delete_transient( 'llamahire_setup_error_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Welcome to LlamaHire', 'llamahire' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure your organization, candidate privacy notice, and public Careers page. You can change these later in Jobs → Settings.', 'llamahire' ); ?></p>
			<p><strong><?php esc_html_e( 'Three setup areas', 'llamahire' ); ?></strong><br><progress value="0" max="3" aria-label="<?php esc_attr_e( 'Setup progress: organization, privacy, and Careers page', 'llamahire' ); ?>" aria-valuetext="<?php esc_attr_e( 'Not complete', 'llamahire' ); ?>" style="width:min(100%, 520px)">0%</progress></p>
			<?php if ( $error ) : ?><div class="notice notice-error inline" role="alert" tabindex="-1"><p><?php echo esc_html( $error['message'] ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llamahire_save_setup">
				<?php wp_nonce_field( 'llamahire_save_setup', 'llamahire_save_setup_nonce' ); ?>
				<h2><?php esc_html_e( 'Organization identity', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-setup-name"><?php esc_html_e( 'Organization name', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-setup-name" name="organization[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required></td></tr>
					<tr><th scope="row"><label for="llamahire-setup-website"><?php esc_html_e( 'Organization website', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-setup-website" name="organization[website]" value="<?php echo esc_attr( $settings['website'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Organization logo', 'llamahire' ); ?></th><td><?php Settings::logo_field( 'llamahire-setup-logo', 'organization[logo]', $settings['logo'] ); ?></td></tr>
				</table>
				<h2><?php esc_html_e( 'Job defaults', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-setup-locality"><?php esc_html_e( 'Default city or locality', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-setup-locality" name="organization[default_locality]" value="<?php echo esc_attr( $settings['default_locality'] ); ?>" placeholder="Vancouver"></td></tr>
					<tr><th scope="row"><label for="llamahire-setup-region"><?php esc_html_e( 'Default state, province, or region', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-setup-region" name="organization[default_region]" value="<?php echo esc_attr( $settings['default_region'] ); ?>" placeholder="British Columbia"></td></tr>
					<tr><th scope="row"><label for="llamahire-setup-country"><?php esc_html_e( 'Default country', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-setup-country" name="organization[default_country]" value="<?php echo esc_attr( $settings['default_country'] ); ?>" maxlength="2" pattern="[A-Za-z]{2}" autocapitalize="characters" spellcheck="false" placeholder="CA" aria-describedby="llamahire-setup-country-description"><p class="description" id="llamahire-setup-country-description"><?php esc_html_e( 'Two-letter ISO country code.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-setup-currency"><?php esc_html_e( 'Default currency', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-setup-currency" name="organization[default_currency]" value="<?php echo esc_attr( $settings['default_currency'] ); ?>" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" spellcheck="false" required placeholder="USD"></td></tr>
				</table>
				<h2><?php esc_html_e( 'Notifications', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-setup-email"><?php esc_html_e( 'Hiring inbox', 'llamahire' ); ?></label></th><td><input class="regular-text" type="email" id="llamahire-setup-email" name="organization[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" required aria-describedby="llamahire-setup-email-description"><p class="description" id="llamahire-setup-email-description"><?php esc_html_e( 'New application notifications are sent here.', 'llamahire' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Candidate privacy', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-setup-privacy-text"><?php esc_html_e( 'Candidate privacy text', 'llamahire' ); ?></label></th><td><textarea class="large-text" rows="3" id="llamahire-setup-privacy-text" name="organization[privacy_text]" required aria-describedby="llamahire-setup-privacy-text-description"><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea><p class="description" id="llamahire-setup-privacy-text-description"><?php esc_html_e( 'Shown beside every application form. Keep it concise and explain how candidate information will be used.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-setup-privacy-page"><?php esc_html_e( 'Privacy policy page', 'llamahire' ); ?></label></th><td><?php Settings::page_select( 'llamahire-setup-privacy-page', 'organization[privacy_page_id]', $settings['privacy_page_id'], __( 'Use the WordPress privacy policy', 'llamahire' ), 'llamahire-setup-privacy-page-description' ); ?><p class="description" id="llamahire-setup-privacy-page-description"><?php esc_html_e( 'Choose a published page, or use the policy selected in WordPress Settings → Privacy.', 'llamahire' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Careers page', 'llamahire' ); ?></h2>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Careers page choice', 'llamahire' ); ?></legend>
					<p><label><input type="radio" name="careers_action" value="create" <?php checked( 'create', $careers_action ); ?>> <?php esc_html_e( 'Create a new Careers page using the LlamaHire pattern', 'llamahire' ); ?></label></p>
					<p><label for="llamahire-careers-title"><?php esc_html_e( 'New page title', 'llamahire' ); ?></label><br><input class="regular-text" id="llamahire-careers-title" name="careers_title" value="<?php echo esc_attr( $careers_title ); ?>"></p>
					<p><label><input type="radio" name="careers_action" value="select" <?php checked( 'select', $careers_action ); ?>> <?php esc_html_e( 'Use an existing published page', 'llamahire' ); ?></label></p>
					<p><label class="screen-reader-text" for="llamahire-setup-careers-page"><?php esc_html_e( 'Existing Careers page', 'llamahire' ); ?></label><?php Settings::page_select( 'llamahire-setup-careers-page', 'organization[careers_page_id]', $settings['careers_page_id'], __( 'Select a published page', 'llamahire' ) ); ?></p>
				</fieldset>
				<?php submit_button( __( 'Complete setup', 'llamahire' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llamahire_skip_setup">
				<?php wp_nonce_field( 'llamahire_skip_setup', 'llamahire_skip_setup_nonce' ); ?>
				<button class="button-link" type="submit"><?php esc_html_e( 'Skip for now', 'llamahire' ); ?></button>
			</form>
		</div>
		<?php
	}

	public static function notice() {
		$result = sanitize_key( wp_unslash( $_GET['llamahire_setup'] ?? '' ) );
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $result, array( 'completed', 'skipped' ), true ) ) {
			return;
		}
		if ( 'completed' === $result ) {
			$careers_page = Settings::public_page( Settings::get()['careers_page_id'] );
			if ( $careers_page ) {
				$message = sprintf(
					/* translators: %s: Careers page URL. */
					__( 'LlamaHire setup is complete. <a href="%s">View your Careers page</a> or add your first job.', 'llamahire' ),
					esc_url( get_permalink( $careers_page ) )
				);
			} else {
				$message = __( 'LlamaHire setup is complete. Add your first job when you are ready.', 'llamahire' );
			}
		} else {
			$message = sprintf(
				/* translators: %s: setup page URL. */
				__( 'Setup skipped for now. You can <a href="%s">resume setup</a> at any time.', 'llamahire' ),
				esc_url( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-setup' ) )
			);
		}
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $message ); ?></p></div>
		<?php
	}

	public static function save() {
		self::require_access( 'llamahire_save_setup', 'llamahire_save_setup_nonce' );
		$input    = isset( $_POST['organization'] ) ? (array) wp_unslash( $_POST['organization'] ) : array();
		$settings = Settings::sanitize( $input );
		$careers_action = sanitize_key( wp_unslash( $_POST['careers_action'] ?? '' ) );
		$careers_title  = sanitize_text_field( wp_unslash( $_POST['careers_title'] ?? '' ) );
		if ( ! $settings['name'] || ! $settings['default_currency'] || ! is_email( $settings['notification_email'] ) || ! $settings['privacy_text'] ) {
			self::setup_error( __( 'Enter an organization name, a valid three-letter currency, a valid hiring inbox, and candidate privacy text.', 'llamahire' ), $settings, $careers_action, $careers_title );
		}
		if ( $settings['privacy_page_id'] && ! Settings::public_page( $settings['privacy_page_id'] ) ) {
			self::setup_error( __( 'Choose a published privacy policy page.', 'llamahire' ), $settings, $careers_action, $careers_title );
		}
		if ( 'create' === $careers_action ) {
			$careers_page_id = self::create_careers_page( $careers_title );
			if ( is_wp_error( $careers_page_id ) ) {
				self::setup_error( $careers_page_id->get_error_message(), $settings, $careers_action, $careers_title );
			}
			$settings['careers_page_id'] = $careers_page_id;
		} elseif ( 'select' === $careers_action && Settings::public_page( $settings['careers_page_id'] ) ) {
			$settings['careers_page_id'] = absint( $settings['careers_page_id'] );
		} else {
			self::setup_error( __( 'Create a Careers page or select an existing published page.', 'llamahire' ), $settings, $careers_action, $careers_title );
		}
		update_option( Settings::OPTION, $settings, false );
		update_option( self::OPTION, array( 'version' => self::VERSION, 'status' => 'completed' ), false );
		self::redirect( 'completed' );
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

	public static function skip() {
		self::require_access( 'llamahire_skip_setup', 'llamahire_skip_setup_nonce' );
		update_option( self::OPTION, array( 'version' => self::VERSION, 'status' => 'skipped' ), false );
		self::redirect( 'skipped' );
	}

	private static function require_access( $nonce_action, $nonce_name ) {
		check_admin_referer( $nonce_action, $nonce_name );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure LlamaHire.', 'llamahire' ), 403 );
		}
	}

	private static function setup_error( $message, array $settings, $careers_action, $careers_title ) {
		set_transient(
			'llamahire_setup_error_' . get_current_user_id(),
			array(
				'message'        => sanitize_text_field( $message ),
				'values'         => $settings,
				'careers_action' => sanitize_key( $careers_action ),
				'careers_title'  => sanitize_text_field( $careers_title ),
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
