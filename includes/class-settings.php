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
	}

	public static function defaults() {
		return array(
			'name'             => get_bloginfo( 'name' ),
			'website'          => home_url( '/' ),
			'logo'             => get_site_icon_url( 512 ),
			'default_country'  => '',
			'default_currency' => 'USD',
		);
	}

	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'name'             => sanitize_text_field( $input['name'] ?? '' ),
			'website'          => esc_url_raw( $input['website'] ?? '' ),
			'logo'             => esc_url_raw( $input['logo'] ?? '' ),
			'default_country'  => self::country_code( $input['default_country'] ?? '' ),
			'default_currency' => self::currency_code( $input['default_currency'] ?? 'USD' ),
		);
	}

	public static function country_code( $value ) {
		$value = strtoupper( sanitize_text_field( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
	}

	public static function currency_code( $value ) {
		$value = strtoupper( sanitize_text_field( $value ) );
		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : 'USD';
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
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="llamahire-org-name"><?php esc_html_e( 'Organization name', 'llamahire' ); ?></label></th><td><input class="regular-text" id="llamahire-org-name" name="<?php echo esc_attr( self::OPTION ); ?>[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required></td></tr>
					<tr><th scope="row"><label for="llamahire-org-website"><?php esc_html_e( 'Organization website', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-org-website" name="<?php echo esc_attr( self::OPTION ); ?>[website]" value="<?php echo esc_attr( $settings['website'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="llamahire-org-logo"><?php esc_html_e( 'Organization logo URL', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-org-logo" name="<?php echo esc_attr( self::OPTION ); ?>[logo]" value="<?php echo esc_attr( $settings['logo'] ); ?>"><p class="description"><?php esc_html_e( 'Use a square or landscape image with a width-to-height ratio between 0.75 and 2.5.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-org-country"><?php esc_html_e( 'Default country', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-org-country" name="<?php echo esc_attr( self::OPTION ); ?>[default_country]" value="<?php echo esc_attr( $settings['default_country'] ); ?>" maxlength="2" placeholder="US"><p class="description"><?php esc_html_e( 'Two-letter ISO country code, such as US, CA, or GB.', 'llamahire' ); ?></p></td></tr>
					<tr><th scope="row"><label for="llamahire-org-currency"><?php esc_html_e( 'Default currency', 'llamahire' ); ?></label></th><td><input class="small-text" id="llamahire-org-currency" name="<?php echo esc_attr( self::OPTION ); ?>[default_currency]" value="<?php echo esc_attr( $settings['default_currency'] ); ?>" maxlength="3" required placeholder="USD"><p class="description"><?php esc_html_e( 'Three-letter ISO currency code.', 'llamahire' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function __construct() {}
}
