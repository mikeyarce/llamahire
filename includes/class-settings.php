<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Organization defaults shared by jobs on an employer careers site.
 */
final class Settings {
	const OPTION = 'llamahire_organization';

	public static function register() {
		register_setting(
			'llamahire_settings',
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function defaults() {
		return array(
			'name'             => get_bloginfo( 'name' ),
			'website'          => home_url( '/' ),
			'logo'             => get_site_icon_url( 512 ),
			'default_locality' => '',
			'default_region'   => '',
			'default_country'  => '',
			'default_currency' => 'USD',
			'notification_email' => get_option( 'admin_email' ),
			'privacy_text'       => __( 'Your information will be used by the employer to evaluate your application.', 'llamahire' ),
			'privacy_page_id'    => 0,
			'careers_page_id'    => 0,
		);
	}

	public static function get() {
		$settings = (array) get_option( self::OPTION, array() );
		$legacy   = (array) get_option( 'llamahire_settings', array() );
		if ( empty( $settings['notification_email'] ) && ! empty( $legacy['notification_email'] ) ) {
			$settings['notification_email'] = $legacy['notification_email'];
		}
		return wp_parse_args( $settings, self::defaults() );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'name'             => sanitize_text_field( $input['name'] ?? '' ),
			'website'          => esc_url_raw( $input['website'] ?? '' ),
			'logo'             => esc_url_raw( $input['logo'] ?? '' ),
			'default_locality' => sanitize_text_field( $input['default_locality'] ?? '' ),
			'default_region'   => sanitize_text_field( $input['default_region'] ?? '' ),
			'default_country'  => self::country_code( $input['default_country'] ?? '' ),
			'default_currency' => self::currency_code( $input['default_currency'] ?? 'USD', '' ),
			'notification_email' => sanitize_email( $input['notification_email'] ?? '' ),
			'privacy_text'       => sanitize_textarea_field( $input['privacy_text'] ?? '' ),
			'privacy_page_id'    => absint( $input['privacy_page_id'] ?? 0 ),
			'careers_page_id'    => absint( $input['careers_page_id'] ?? 0 ),
		);
	}

	public static function public_page( $page_id ) {
		$page = get_post( absint( $page_id ) );
		return $page && 'page' === $page->post_type && 'publish' === $page->post_status ? $page : null;
	}

	public static function privacy_url() {
		$page = self::public_page( self::get()['privacy_page_id'] );
		return $page ? get_permalink( $page ) : get_privacy_policy_url();
	}

	public static function country_code( $value ) {
		$value = strtoupper( sanitize_text_field( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
	}

	public static function currency_code( $value, $fallback = 'USD' ) {
		$value = strtoupper( sanitize_text_field( $value ) );
		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : $fallback;
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Jobs::POST_TYPE,
			__( 'LlamaHire settings', 'llamahire' ),
			__( 'Settings', 'llamahire' ),
			'manage_options',
			'llamahire-settings',
			array( __CLASS__, 'page' )
		);
	}

	public static function enqueue_assets() {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( ! in_array( $page, array( 'llamahire-settings', 'llamahire-setup' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'llamahire-admin-settings',
			LLAMAHIRE_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			(string) filemtime( LLAMAHIRE_PATH . 'assets/js/admin-settings.js' ),
			true
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LlamaHire settings', 'llamahire' ); ?></h1>
			<p><?php esc_html_e( 'These organization defaults are used for Google Jobs and can be overridden on an individual job.', 'llamahire' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'llamahire_settings' ); ?>
				<h2><?php esc_html_e( 'Organization identity', 'llamahire' ); ?></h2>
				<p><?php esc_html_e( 'These details identify the employer on job pages and in Google Jobs.', 'llamahire' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-org-name"><?php esc_html_e( 'Organization name', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-org-name" name="<?php echo esc_attr( self::OPTION ); ?>[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required></td></tr>
					<tr><th scope="row"><label for="llamahire-org-website"><?php esc_html_e( 'Organization website', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-org-website" name="<?php echo esc_attr( self::OPTION ); ?>[website]" value="<?php echo esc_attr( $settings['website'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Organization logo', 'llamahire' ); ?></th><td><?php self::logo_field( 'llamahire-org-logo', self::OPTION . '[logo]', $settings['logo'] ); ?></td></tr>
				</table>
				<h2><?php esc_html_e( 'Job defaults', 'llamahire' ); ?></h2>
				<p><?php esc_html_e( 'New jobs start with these location and compensation values.', 'llamahire' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-org-locality"><?php esc_html_e( 'Default city or locality', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-org-locality" name="<?php echo esc_attr( self::OPTION ); ?>[default_locality]" value="<?php echo esc_attr( $settings['default_locality'] ); ?>" placeholder="Vancouver"><p class="description"><?php esc_html_e( 'Used as the starting location for new jobs.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-org-region"><?php esc_html_e( 'Default state, province, or region', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-org-region" name="<?php echo esc_attr( self::OPTION ); ?>[default_region]" value="<?php echo esc_attr( $settings['default_region'] ); ?>" placeholder="British Columbia"></td></tr>
					<tr><th scope="row"><label for="llamahire-org-country"><?php esc_html_e( 'Default country', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-org-country" name="<?php echo esc_attr( self::OPTION ); ?>[default_country]" value="<?php echo esc_attr( $settings['default_country'] ); ?>" maxlength="2" pattern="[A-Za-z]{2}" autocapitalize="characters" spellcheck="false" placeholder="CA"><p class="description"><?php esc_html_e( 'Two-letter ISO country code, such as US, CA, or GB.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-org-currency"><?php esc_html_e( 'Default currency', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-org-currency" name="<?php echo esc_attr( self::OPTION ); ?>[default_currency]" value="<?php echo esc_attr( $settings['default_currency'] ); ?>" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" spellcheck="false" required placeholder="USD"><p class="description"><?php esc_html_e( 'Three-letter ISO currency code.', 'llamahire' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Notifications', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-notification-email"><?php esc_html_e( 'Hiring inbox', 'llamahire' ); ?></label></th><td><input class="regular-text" type="email" id="llamahire-notification-email" name="<?php echo esc_attr( self::OPTION ); ?>[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" required><p class="description"><?php esc_html_e( 'New application notifications are sent here.', 'llamahire' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Candidate privacy', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-privacy-text"><?php esc_html_e( 'Candidate privacy text', 'llamahire' ); ?></label></th><td><textarea class="large-text" rows="3" id="llamahire-privacy-text" name="<?php echo esc_attr( self::OPTION ); ?>[privacy_text]" required><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Shown beside the application form. Describe how candidate information will be used.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-privacy-page"><?php esc_html_e( 'Candidate privacy policy', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-privacy-page', self::OPTION . '[privacy_page_id]', $settings['privacy_page_id'], __( 'Use the WordPress privacy policy', 'llamahire' ) ); ?></td></tr>
				</table>
				<h2><?php esc_html_e( 'Careers page', 'llamahire' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-careers-page"><?php esc_html_e( 'Careers page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-careers-page', self::OPTION . '[careers_page_id]', $settings['careers_page_id'], __( 'No Careers page selected', 'llamahire' ) ); ?></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function logo_field( $id, $name, $value ) {
		?>
		<div class="llamahire-media-field" data-media-title="<?php esc_attr_e( 'Choose organization logo', 'llamahire' ); ?>" data-media-button="<?php esc_attr_e( 'Use this logo', 'llamahire' ); ?>" data-empty-label="<?php esc_attr_e( 'Choose logo', 'llamahire' ); ?>" data-selected-label="<?php esc_attr_e( 'Replace logo', 'llamahire' ); ?>">
			<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<div class="llamahire-media-preview" style="margin-bottom:8px">
				<?php if ( $value ) : ?><img src="<?php echo esc_url( $value ); ?>" alt="" style="display:block;max-width:240px;max-height:120px;width:auto;height:auto"><?php endif; ?>
			</div>
			<button type="button" class="button llamahire-select-media"><?php echo $value ? esc_html__( 'Replace logo', 'llamahire' ) : esc_html__( 'Choose logo', 'llamahire' ); ?></button>
			<button type="button" class="button-link-delete llamahire-remove-media" <?php echo $value ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove logo', 'llamahire' ); ?></button>
			<p class="description"><?php esc_html_e( 'Choose an image from the Media Library. Use a square or landscape image with a width-to-height ratio between 0.75 and 2.5.', 'llamahire' ); ?></p>
		</div>
		<?php
	}

	public static function page_select( $id, $name, $selected, $empty_label, $describedby = '' ) {
		$html = wp_dropdown_pages(
			array(
				'id'               => $id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes attributes before rendering.
				'name'             => $name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes attributes before rendering.
				'selected'         => absint( $selected ),
				'show_option_none' => $empty_label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes option labels before rendering.
				'option_none_value' => '0',
				'post_status'      => 'publish',
				'echo'             => false,
			)
		);
		if ( $describedby ) {
			$html = str_replace( '<select ', '<select aria-describedby="' . esc_attr( $describedby ) . '" ', $html );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes the select; the injected ID is escaped above.
	}

	private function __construct() {}
}
