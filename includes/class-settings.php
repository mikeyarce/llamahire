<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Organization defaults shared by jobs on an employer careers site.
 */
final class Settings {
	const OPTION            = 'llamahire_organization';
	const EMAIL_DIAGNOSTICS = 'llamahire_email_diagnostics';
	const SITE_MODE_COMPANY = 'company';
	const SITE_MODE_JOB_BOARD = 'job_board';

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
		add_action( 'admin_post_llamahire_test_email', array( __CLASS__, 'send_test_email' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
	}

	public static function defaults() {
		return array(
			'site_mode'        => self::SITE_MODE_COMPANY,
			'name'             => get_bloginfo( 'name' ),
			'website'          => home_url( '/' ),
			'logo'             => get_site_icon_url( 512 ),
			'default_locality' => '',
			'default_region'   => '',
			'default_country'  => '',
			'default_currency' => 'USD',
			'google_geocoding_api_key' => '',
			'notification_email' => get_option( 'admin_email' ),
			'email_sender_name'  => get_bloginfo( 'name' ),
			'email_sender_email' => get_option( 'admin_email' ),
			'employer_email_subject' => __( 'New application for {job_title}', 'llamahire' ),
			'employer_email_body'    => __( "{candidate_name} applied for {job_title}.\n\nReview applications: {applications_url}", 'llamahire' ),
			'candidate_email_subject' => __( 'We received your application for {job_title}', 'llamahire' ),
			'candidate_email_body'    => __( "Hi {candidate_name},\n\nThanks for applying for {job_title}. We received your application and will be in touch if your experience matches what we are looking for.\n\n{site_name}\n{site_url}", 'llamahire' ),
			'privacy_text'       => self::default_privacy_text( self::SITE_MODE_COMPANY ),
			'privacy_page_id'    => 0,
			'careers_page_id'    => 0,
			'submit_job_page_id'  => 0,
			'my_jobs_page_id'     => 0,
			'employer_registration_page_id' => 0,
			'employer_approval'   => 'manual',
			'employer_policy_text' => __( 'I agree to follow this job board’s {listing_policy} and provide accurate employer and job information.', 'llamahire' ),
			'employer_policy_page_id' => 0,
			'active_listing_limit' => 0,
			'listing_duration_days' => 30,
			'anti_spam_provider' => 'none',
			'anti_spam_site_key' => '',
			'anti_spam_secret_key' => '',
			'anti_spam_registration' => 1,
			'anti_spam_applications' => 1,
			'application_phone'   => 'optional',
			'application_resume'  => 'optional',
			'application_letter'  => 'optional',
			'retention_days'       => 365,
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
		$defaults = self::defaults();
		$site_mode = self::sanitize_site_mode( $input['site_mode'] ?? self::SITE_MODE_COMPANY );
		return array(
			'site_mode'        => $site_mode,
			'name'             => sanitize_text_field( $input['name'] ?? '' ),
			'website'          => self::SITE_MODE_JOB_BOARD === $site_mode ? home_url( '/' ) : esc_url_raw( $input['website'] ?? '' ),
			'logo'             => esc_url_raw( $input['logo'] ?? '' ),
			'default_locality' => sanitize_text_field( $input['default_locality'] ?? '' ),
			'default_region'   => sanitize_text_field( $input['default_region'] ?? '' ),
			'default_country'  => self::country_code( $input['default_country'] ?? '' ),
			'default_currency' => self::currency_code( $input['default_currency'] ?? 'USD', '' ),
			'google_geocoding_api_key' => substr( sanitize_text_field( $input['google_geocoding_api_key'] ?? '' ), 0, 255 ),
			'notification_email' => sanitize_email( $input['notification_email'] ?? '' ),
			'email_sender_name'  => substr( sanitize_text_field( $input['email_sender_name'] ?? $defaults['email_sender_name'] ), 0, 120 ),
			'email_sender_email' => sanitize_email( $input['email_sender_email'] ?? $defaults['email_sender_email'] ),
			'employer_email_subject' => self::email_subject( $input['employer_email_subject'] ?? '', $defaults['employer_email_subject'] ),
			'employer_email_body'    => self::email_body( $input['employer_email_body'] ?? '', $defaults['employer_email_body'] ),
			'candidate_email_subject' => self::email_subject( $input['candidate_email_subject'] ?? '', $defaults['candidate_email_subject'] ),
			'candidate_email_body'    => self::email_body( $input['candidate_email_body'] ?? '', $defaults['candidate_email_body'] ),
			'privacy_text'       => sanitize_textarea_field( $input['privacy_text'] ?? '' ),
			'privacy_page_id'    => absint( $input['privacy_page_id'] ?? 0 ),
			'careers_page_id'    => absint( $input['careers_page_id'] ?? 0 ),
			'submit_job_page_id'  => absint( $input['submit_job_page_id'] ?? 0 ),
			'my_jobs_page_id'     => absint( $input['my_jobs_page_id'] ?? 0 ),
			'employer_registration_page_id' => absint( $input['employer_registration_page_id'] ?? 0 ),
			'employer_approval'   => self::employer_approval( $input['employer_approval'] ?? $defaults['employer_approval'] ),
			'employer_policy_text' => self::employer_policy_text( $input['employer_policy_text'] ?? '', $defaults['employer_policy_text'] ),
			'employer_policy_page_id' => absint( $input['employer_policy_page_id'] ?? 0 ),
			'active_listing_limit' => min( 1000, absint( $input['active_listing_limit'] ?? $defaults['active_listing_limit'] ) ),
			'listing_duration_days' => self::listing_duration_days( $input['listing_duration_days'] ?? $defaults['listing_duration_days'] ),
			'anti_spam_provider' => Anti_Spam::sanitize_provider( $input['anti_spam_provider'] ?? $defaults['anti_spam_provider'] ),
			'anti_spam_site_key' => substr( sanitize_text_field( $input['anti_spam_site_key'] ?? '' ), 0, 255 ),
			'anti_spam_secret_key' => substr( sanitize_text_field( $input['anti_spam_secret_key'] ?? '' ), 0, 255 ),
			'anti_spam_registration' => empty( $input['anti_spam_registration'] ) ? 0 : 1,
			'anti_spam_applications' => empty( $input['anti_spam_applications'] ) ? 0 : 1,
			'application_phone'   => self::field_mode( $input['application_phone'] ?? 'optional', 'optional' ),
			'application_resume'  => self::field_mode( $input['application_resume'] ?? 'optional', 'optional' ),
			'application_letter'  => self::field_mode( $input['application_letter'] ?? 'optional', 'optional' ),
			'retention_days'       => self::sanitize_retention_days( $input['retention_days'] ?? $defaults['retention_days'] ),
		);
	}

	public static function site_mode() {
		return self::sanitize_site_mode( self::get()['site_mode'] );
	}

	public static function employer_approval( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'automatic', 'manual' ), true ) ? $value : 'manual';
	}

	private static function employer_policy_text( $value, $fallback ) {
		$value = sanitize_textarea_field( $value );
		return $value ?: $fallback;
	}

	public static function listing_duration_days( $value ) {
		$value = absint( $value );
		return in_array( $value, array( 0, 7, 14, 30, 45, 60, 90, 180, 365 ), true ) ? $value : 30;
	}

	public static function sanitize_site_mode( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( self::SITE_MODE_COMPANY, self::SITE_MODE_JOB_BOARD ), true ) ? $value : self::SITE_MODE_COMPANY;
	}

	public static function default_privacy_text( $site_mode ) {
		if ( self::SITE_MODE_JOB_BOARD === self::sanitize_site_mode( $site_mode ) ) {
			return __( 'Your information will be used by the job-board operator and the employer responsible for this listing to evaluate your application.', 'llamahire' );
		}
		return __( 'Your information will be used by the employer to evaluate your application.', 'llamahire' );
	}

	public static function retention_days() {
		return self::sanitize_retention_days( self::get()['retention_days'] );
	}

	public static function sanitize_retention_days( $value ) {
		$value = absint( $value );
		return in_array( $value, array( 0, 30, 90, 180, 365, 730, 1095, 1825 ), true ) ? $value : 365;
	}

	public static function application_fields() {
		$settings = self::get();
		return array(
			'phone'        => self::field_mode( $settings['application_phone'], 'optional' ),
			'resume'       => self::field_mode( $settings['application_resume'], 'optional' ),
			'cover_letter' => self::field_mode( $settings['application_letter'], 'optional' ),
		);
	}

	public static function field_mode( $value, $fallback = 'optional' ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'required', 'optional', 'hidden' ), true ) ? $value : $fallback;
	}

	public static function public_page( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return null;
		}
		$page = get_post( $page_id );
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

	public static function country_select( $id, $name, $selected, $describedby = '' ) {
		self::standard_select(
			$id,
			$name,
			self::country_options(),
			self::country_code( $selected ),
			__( 'Select a country', 'llamahire' ),
			$describedby
		);
	}

	public static function currency_select( $id, $name, $selected, $describedby = '' ) {
		self::standard_select(
			$id,
			$name,
			self::currency_options(),
			self::currency_code( $selected, '' ),
			__( 'Select a currency', 'llamahire' ),
			$describedby,
			true
		);
	}

	public static function country_options() {
		$codes = explode( ' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW' );
		$fallbacks = array(
			'AU' => __( 'Australia', 'llamahire' ),
			'BR' => __( 'Brazil', 'llamahire' ),
			'CA' => __( 'Canada', 'llamahire' ),
			'CN' => __( 'China', 'llamahire' ),
			'DE' => __( 'Germany', 'llamahire' ),
			'ES' => __( 'Spain', 'llamahire' ),
			'FR' => __( 'France', 'llamahire' ),
			'GB' => __( 'United Kingdom', 'llamahire' ),
			'IN' => __( 'India', 'llamahire' ),
			'IT' => __( 'Italy', 'llamahire' ),
			'JP' => __( 'Japan', 'llamahire' ),
			'MX' => __( 'Mexico', 'llamahire' ),
			'NL' => __( 'Netherlands', 'llamahire' ),
			'NZ' => __( 'New Zealand', 'llamahire' ),
			'US' => __( 'United States', 'llamahire' ),
			'ZA' => __( 'South Africa', 'llamahire' ),
		);
		return self::localized_standard_options( $codes, 'country', $fallbacks );
	}

	public static function currency_options() {
		$codes = explode( ' ', 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST YER ZAR ZMW ZWL' );
		$fallbacks = array(
			'AUD' => __( 'Australian Dollar', 'llamahire' ),
			'BRL' => __( 'Brazilian Real', 'llamahire' ),
			'CAD' => __( 'Canadian Dollar', 'llamahire' ),
			'CHF' => __( 'Swiss Franc', 'llamahire' ),
			'CNY' => __( 'Chinese Yuan', 'llamahire' ),
			'EUR' => __( 'Euro', 'llamahire' ),
			'GBP' => __( 'British Pound', 'llamahire' ),
			'INR' => __( 'Indian Rupee', 'llamahire' ),
			'JPY' => __( 'Japanese Yen', 'llamahire' ),
			'MXN' => __( 'Mexican Peso', 'llamahire' ),
			'NZD' => __( 'New Zealand Dollar', 'llamahire' ),
			'USD' => __( 'US Dollar', 'llamahire' ),
			'ZAR' => __( 'South African Rand', 'llamahire' ),
		);
		return self::localized_standard_options( $codes, 'currency', $fallbacks );
	}

	public static function email_subject( $value, $fallback ) {
		$value = substr( sanitize_text_field( $value ), 0, 200 );
		return '' === $value ? $fallback : $value;
	}

	public static function email_body( $value, $fallback ) {
		$value = substr( sanitize_textarea_field( $value ), 0, 5000 );
		return '' === $value ? $fallback : $value;
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
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen selection.
		if ( ! in_array( $page, array( 'llamahire-settings', 'llamahire-setup' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		if ( 'llamahire-settings' === $page ) {
			wp_enqueue_style(
				'llamahire-admin-settings',
				LLAMAHIRE_URL . 'assets/css/admin-settings.css',
				array(),
				(string) filemtime( LLAMAHIRE_PATH . 'assets/css/admin-settings.css' )
			);
			wp_enqueue_style( 'wp-components' );
		}
		wp_enqueue_script(
			'llamahire-admin-settings',
			LLAMAHIRE_URL . 'assets/js/admin-settings.js',
			'llamahire-settings' === $page ? array( 'jquery', 'wp-components', 'wp-element' ) : array( 'jquery' ),
			(string) filemtime( LLAMAHIRE_PATH . 'assets/js/admin-settings.js' ),
			true
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings     = self::get();
		$is_job_board = self::SITE_MODE_JOB_BOARD === $settings['site_mode'];
		$email_test   = sanitize_key( wp_unslash( $_GET['email_test'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		$sections     = array(
			'site-purpose' => array( __( 'Site purpose', 'llamahire' ), 'dashicons-admin-settings' ),
			'organization' => array( $is_job_board ? __( 'Board identity', 'llamahire' ) : __( 'Organization', 'llamahire' ), 'dashicons-building' ),
			'job-defaults' => array( __( 'Job defaults', 'llamahire' ), 'dashicons-portfolio' ),
			'listing-policy' => array( __( 'Listing policy', 'llamahire' ), 'dashicons-clipboard' ),
			'applications' => array( __( 'Applications & privacy', 'llamahire' ), 'dashicons-shield' ),
			'notifications' => array( __( 'Notifications', 'llamahire' ), 'dashicons-bell' ),
			'pages'        => array( __( 'Pages', 'llamahire' ), 'dashicons-media-document' ),
		);
		if ( ! $is_job_board ) {
			unset( $sections['listing-policy'] );
		}
		?>
		<div class="wrap llamahire-settings-screen" data-llamahire-settings>
			<h1><?php esc_html_e( 'LlamaHire settings', 'llamahire' ); ?></h1>
			<?php settings_errors(); ?>
			<?php if ( 'sent' === $email_test ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'WordPress accepted the test email. Confirm that it arrived before relying on application notifications.', 'llamahire' ); ?></p></div><?php elseif ( in_array( $email_test, array( 'failed', 'invalid' ), true ) ) : ?><div class="notice notice-error inline" role="alert" tabindex="-1"><p><?php esc_html_e( 'The test email was not accepted. Review the sender details and your WordPress mail transport.', 'llamahire' ); ?></p></div><?php endif; ?>
			<p class="llamahire-settings-intro"><?php echo esc_html( $is_job_board ? __( 'Manage the defaults and operating rules for this multi-employer job board.', 'llamahire' ) : __( 'Manage the organization defaults used across your careers site and job listings.', 'llamahire' ) ); ?></p>
			<div class="llamahire-settings-layout">
				<nav class="llamahire-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'llamahire' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $section ) : ?>
							<li><a href="#llamahire-settings-<?php echo esc_attr( $slug ); ?>" data-llamahire-settings-link="<?php echo esc_attr( $slug ); ?>"><span class="dashicons <?php echo esc_attr( $section[1] ); ?>" aria-hidden="true"></span><span><?php echo esc_html( $section[0] ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( Setup::is_complete() ) : ?>
						<div class="llamahire-settings-setup-status">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'Setup complete', 'llamahire' ); ?></strong>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="llamahire_restart_setup">
									<?php wp_nonce_field( 'llamahire_restart_setup', 'llamahire_restart_setup_nonce' ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Restart setup', 'llamahire' ); ?></button>
								</form>
							</div>
						</div>
					<?php endif; ?>
				</nav>
				<div class="llamahire-settings-content">
					<form id="llamahire-settings-form" method="post" action="options.php" data-llamahire-settings-form>
						<?php settings_fields( 'llamahire_settings' ); ?>

						<section id="llamahire-settings-site-purpose" class="llamahire-settings-section" data-llamahire-settings-section="site-purpose" aria-labelledby="llamahire-settings-site-purpose-title">
							<header><h2 id="llamahire-settings-site-purpose-title" tabindex="-1"><?php esc_html_e( 'Site purpose', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Choose the operating model that matches this site. This changes labels and which multi-employer tools are available.', 'llamahire' ); ?></p></header>
							<fieldset class="llamahire-settings-purpose">
								<legend class="screen-reader-text"><?php esc_html_e( 'How do you use LlamaHire?', 'llamahire' ); ?></legend>
								<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[site_mode]" value="company" <?php checked( self::SITE_MODE_COMPANY, $settings['site_mode'] ); ?>><span><strong><?php esc_html_e( 'Company careers site', 'llamahire' ); ?></strong><span class="description"><?php esc_html_e( 'Publish jobs for one organization using shared employer defaults.', 'llamahire' ); ?></span></span></label>
								<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[site_mode]" value="job_board" <?php checked( self::SITE_MODE_JOB_BOARD, $settings['site_mode'] ); ?>><span><strong><?php esc_html_e( 'Community job board', 'llamahire' ); ?></strong><span class="description"><?php esc_html_e( 'Publish listings from multiple employers while this site acts as the board operator.', 'llamahire' ); ?></span></span></label>
							</fieldset>
							<?php if ( $is_job_board ) : ?>
								<div class="llamahire-settings-callout">
									<h3><?php esc_html_e( 'Employer registration', 'llamahire' ); ?></h3>
									<p><?php esc_html_e( 'New employers verify their email address before they can submit jobs.', 'llamahire' ); ?></p>
									<label for="llamahire-employer-approval"><strong><?php esc_html_e( 'After email verification', 'llamahire' ); ?></strong></label>
									<select id="llamahire-employer-approval" name="<?php echo esc_attr( self::OPTION ); ?>[employer_approval]">
										<option value="manual" <?php selected( 'manual', $settings['employer_approval'] ); ?>><?php esc_html_e( 'Require operator approval', 'llamahire' ); ?></option>
										<option value="automatic" <?php selected( 'automatic', $settings['employer_approval'] ); ?>><?php esc_html_e( 'Approve automatically', 'llamahire' ); ?></option>
									</select>
									<label for="llamahire-employer-policy"><strong><?php esc_html_e( 'Registration agreement', 'llamahire' ); ?></strong></label>
									<textarea class="large-text" rows="3" id="llamahire-employer-policy" name="<?php echo esc_attr( self::OPTION ); ?>[employer_policy_text]" required><?php echo esc_textarea( $settings['employer_policy_text'] ); ?></textarea>
									<p class="description"><?php echo wp_kses_post( __( 'Use <code>{listing_policy}</code> where the linked “listing rules” text should appear. It remains plain text until a policy page is selected.', 'llamahire' ) ); ?></p>
									<label for="llamahire-employer-policy-page"><strong><?php esc_html_e( 'Full listing policy page', 'llamahire' ); ?></strong></label>
									<?php self::page_select( 'llamahire-employer-policy-page', self::OPTION . '[employer_policy_page_id]', $settings['employer_policy_page_id'], __( 'No separate policy page', 'llamahire' ), '', __( 'Listing policy page', 'llamahire' ) ); ?>
								</div>
							<?php else : ?>
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[employer_approval]" value="<?php echo esc_attr( $settings['employer_approval'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[employer_policy_text]" value="<?php echo esc_attr( $settings['employer_policy_text'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[employer_policy_page_id]" value="<?php echo esc_attr( $settings['employer_policy_page_id'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[employer_registration_page_id]" value="<?php echo esc_attr( $settings['employer_registration_page_id'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[active_listing_limit]" value="<?php echo esc_attr( $settings['active_listing_limit'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[listing_duration_days]" value="<?php echo esc_attr( $settings['listing_duration_days'] ); ?>">
							<?php endif; ?>
						</section>

						<section id="llamahire-settings-organization" class="llamahire-settings-section" data-llamahire-settings-section="organization" aria-labelledby="llamahire-settings-organization-title">
							<header><h2 id="llamahire-settings-organization-title" tabindex="-1"><?php echo esc_html( $is_job_board ? __( 'Board identity', 'llamahire' ) : __( 'Organization', 'llamahire' ) ); ?></h2><p><?php echo esc_html( $is_job_board ? __( 'These details identify the board operator. Each listing can identify its own employer.', 'llamahire' ) : __( 'These details identify the employer on job pages and in Google Jobs.', 'llamahire' ) ); ?></p></header>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-org-name"><?php echo esc_html( $is_job_board ? __( 'Job board name', 'llamahire' ) : __( 'Organization name', 'llamahire' ) ); ?></label><span class="description"><?php echo esc_html( $is_job_board ? __( 'The name shown as the board operator.', 'llamahire' ) : __( 'The employer name shown on job pages.', 'llamahire' ) ); ?></span></th><td><input class="regular-text" type="text" id="llamahire-org-name" name="<?php echo esc_attr( self::OPTION ); ?>[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required></td></tr>
								<tr data-llamahire-company-website <?php echo $is_job_board ? 'hidden' : ''; ?>><th scope="row"><label for="llamahire-org-website"><?php esc_html_e( 'Organization website', 'llamahire' ); ?></label></th><td><input class="regular-text" type="url" id="llamahire-org-website" name="<?php echo esc_attr( self::OPTION ); ?>[website]" value="<?php echo esc_attr( $settings['website'] ); ?>" <?php disabled( $is_job_board ); ?>><p class="description"><?php esc_html_e( 'Optional. Use the organization’s canonical website when this careers site is on a different domain or subdomain.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><?php echo esc_html( $is_job_board ? __( 'Job board logo', 'llamahire' ) : __( 'Organization logo', 'llamahire' ) ); ?></th><td><?php self::logo_field( 'llamahire-org-logo', self::OPTION . '[logo]', $settings['logo'] ); ?></td></tr>
							</table>
						</section>

						<section id="llamahire-settings-job-defaults" class="llamahire-settings-section" data-llamahire-settings-section="job-defaults" aria-labelledby="llamahire-settings-job-defaults-title">
							<header><h2 id="llamahire-settings-job-defaults-title" tabindex="-1"><?php esc_html_e( 'Job defaults', 'llamahire' ); ?></h2><p><?php esc_html_e( 'New jobs start with these location and compensation values. Editors can override them on each job.', 'llamahire' ); ?></p></header>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-org-locality"><?php esc_html_e( 'Default city or locality', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-org-locality" name="<?php echo esc_attr( self::OPTION ); ?>[default_locality]" value="<?php echo esc_attr( $settings['default_locality'] ); ?>" placeholder="Vancouver"></td></tr>
								<tr><th scope="row"><label for="llamahire-org-region"><?php esc_html_e( 'Default state, province, or region', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-org-region" name="<?php echo esc_attr( self::OPTION ); ?>[default_region]" value="<?php echo esc_attr( $settings['default_region'] ); ?>" placeholder="British Columbia"></td></tr>
							<tr><th scope="row"><label for="llamahire-org-country"><?php esc_html_e( 'Default country', 'llamahire' ); ?></label></th><td><?php self::country_select( 'llamahire-org-country', self::OPTION . '[default_country]', $settings['default_country'] ); ?></td></tr>
							<tr><th scope="row"><label for="llamahire-org-currency"><?php esc_html_e( 'Default currency', 'llamahire' ); ?></label></th><td><?php self::currency_select( 'llamahire-org-currency', self::OPTION . '[default_currency]', $settings['default_currency'] ); ?></td></tr>
							</table>
							<h3><?php esc_html_e( 'Geocoding', 'llamahire' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Optional. Add a Google Maps Platform key to turn physical and hybrid job addresses into coordinates when they are saved. Remote jobs are not sent to Google, and a lookup failure never prevents a job from saving.', 'llamahire' ); ?></p>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-google-geocoding-key"><?php esc_html_e( 'Google Geocoding API key', 'llamahire' ); ?></label></th><td><input class="regular-text" type="password" id="llamahire-google-geocoding-key" name="<?php echo esc_attr( self::OPTION ); ?>[google_geocoding_api_key]" value="<?php echo esc_attr( $settings['google_geocoding_api_key'] ); ?>" autocomplete="new-password"><p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: Google API key security documentation URL. */ __( 'Enable the Geocoding API for this key and restrict it to your server and that API. <a href="%s" target="_blank" rel="noopener noreferrer">Review Google’s API key security guidance</a>. Existing jobs are geocoded the next time their address is saved.', 'llamahire' ), esc_url( 'https://developers.google.com/maps/api-security-best-practices' ) ) ); ?></p></td></tr>
							</table>
						</section>

						<?php if ( $is_job_board ) : ?>
							<section id="llamahire-settings-listing-policy" class="llamahire-settings-section" data-llamahire-settings-section="listing-policy" aria-labelledby="llamahire-settings-listing-policy-title">
								<header><h2 id="llamahire-settings-listing-policy-title" tabindex="-1"><?php esc_html_e( 'Listing policy', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Set the free operating limits that apply equally to every employer.', 'llamahire' ); ?></p></header>
								<table class="form-table" role="presentation">
									<tr><th scope="row"><label for="llamahire-active-listing-limit"><?php esc_html_e( 'Active listings per employer', 'llamahire' ); ?></label></th><td><input class="small-text" type="number" min="0" max="1000" step="1" id="llamahire-active-listing-limit" name="<?php echo esc_attr( self::OPTION ); ?>[active_listing_limit]" value="<?php echo esc_attr( $settings['active_listing_limit'] ); ?>"><p class="description"><?php esc_html_e( 'Counts published listings and listings awaiting review. Enter 0 for no limit; private drafts do not count.', 'llamahire' ); ?></p></td></tr>
									<tr><th scope="row"><label for="llamahire-listing-duration"><?php esc_html_e( 'Default listing duration', 'llamahire' ); ?></label></th><td><select id="llamahire-listing-duration" name="<?php echo esc_attr( self::OPTION ); ?>[listing_duration_days]"><?php foreach ( array( 7, 14, 30, 45, 60, 90, 180, 365 ) as $days ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $settings['listing_duration_days'], $days ); ?>><?php /* translators: %d: listing duration in days. */ echo esc_html( sprintf( _n( '%d day', '%d days', $days, 'llamahire' ), $days ) ); ?></option><?php endforeach; ?><option value="0" <?php selected( 0, $settings['listing_duration_days'] ); ?>><?php esc_html_e( 'No automatic expiration', 'llamahire' ); ?></option></select><p class="description"><?php esc_html_e( 'The clock starts when an employer first submits a listing for review. Existing listings keep their saved expiration.', 'llamahire' ); ?></p></td></tr>
								</table>
							</section>
						<?php endif; ?>

						<section id="llamahire-settings-applications" class="llamahire-settings-section" data-llamahire-settings-section="applications" aria-labelledby="llamahire-settings-applications-title">
							<header><h2 id="llamahire-settings-applications-title" tabindex="-1"><?php esc_html_e( 'Applications & privacy', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Control the information candidates submit and how long their private data is retained.', 'llamahire' ); ?></p></header>
							<h3><?php esc_html_e( 'Application fields', 'llamahire' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Name and email are always required.', 'llamahire' ); ?></p>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-application-phone"><?php esc_html_e( 'Phone', 'llamahire' ); ?></label></th><td><?php self::field_mode_select( 'llamahire-application-phone', self::OPTION . '[application_phone]', $settings['application_phone'] ); ?></td></tr>
								<tr><th scope="row"><label for="llamahire-application-resume"><?php esc_html_e( 'Resume', 'llamahire' ); ?></label></th><td><?php self::field_mode_select( 'llamahire-application-resume', self::OPTION . '[application_resume]', $settings['application_resume'] ); ?><p class="description"><?php esc_html_e( 'PDF or DOCX files smaller than 5 MB.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-application-letter"><?php esc_html_e( 'Cover letter', 'llamahire' ); ?></label></th><td><?php self::field_mode_select( 'llamahire-application-letter', self::OPTION . '[application_letter]', $settings['application_letter'] ); ?></td></tr>
							</table>
							<h3><?php esc_html_e( 'Candidate privacy', 'llamahire' ); ?></h3>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-privacy-text"><?php esc_html_e( 'Privacy notice', 'llamahire' ); ?></label></th><td><textarea class="large-text" rows="3" id="llamahire-privacy-text" name="<?php echo esc_attr( self::OPTION ); ?>[privacy_text]" required data-company-default="<?php echo esc_attr( self::default_privacy_text( self::SITE_MODE_COMPANY ) ); ?>" data-job-board-default="<?php echo esc_attr( self::default_privacy_text( self::SITE_MODE_JOB_BOARD ) ); ?>"><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Shown beside the application form. Describe how candidate information will be used.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-privacy-page"><?php esc_html_e( 'Privacy policy page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-privacy-page', self::OPTION . '[privacy_page_id]', $settings['privacy_page_id'], __( 'Use the WordPress privacy policy', 'llamahire' ), '', __( 'Privacy policy page', 'llamahire' ) ); ?></td></tr>
								<tr><th scope="row"><label for="llamahire-retention-days"><?php esc_html_e( 'Application retention', 'llamahire' ); ?></label></th><td><select class="regular-text" id="llamahire-retention-days" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]"><?php foreach ( array( 30 => __( '30 days', 'llamahire' ), 90 => __( '90 days', 'llamahire' ), 180 => __( '180 days', 'llamahire' ), 365 => __( '1 year', 'llamahire' ), 730 => __( '2 years', 'llamahire' ), 1095 => __( '3 years', 'llamahire' ), 1825 => __( '5 years', 'llamahire' ), 0 => __( 'Keep until manually erased', 'llamahire' ) ) as $days => $label ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $settings['retention_days'], $days ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Applications and private resumes older than this are permanently deleted by the daily cleanup task.', 'llamahire' ); ?></p></td></tr>
							</table>
							<h3><?php esc_html_e( 'Spam protection', 'llamahire' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Add an optional bot check to public forms. Existing rate limits and the application honeypot remain active.', 'llamahire' ); ?></p>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-anti-spam-provider"><?php esc_html_e( 'Provider', 'llamahire' ); ?></label></th><td><select class="regular-text" id="llamahire-anti-spam-provider" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_provider]"><?php foreach ( Anti_Spam::providers() as $provider => $label ) : ?><option value="<?php echo esc_attr( $provider ); ?>" <?php selected( $settings['anti_spam_provider'], $provider ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php echo wp_kses_post( __( 'Turnstile is free for most sites and can run without using Cloudflare hosting. <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">Create Turnstile keys</a> or <a href="https://console.cloud.google.com/security/recaptcha" target="_blank" rel="noopener noreferrer">create reCAPTCHA keys</a>.', 'llamahire' ) ); ?></p></td></tr>
								<tr data-llamahire-anti-spam-keys><th scope="row"><label for="llamahire-anti-spam-site-key"><?php esc_html_e( 'Site key', 'llamahire' ); ?></label></th><td><input class="regular-text code" type="text" id="llamahire-anti-spam-site-key" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_site_key]" value="<?php echo esc_attr( $settings['anti_spam_site_key'] ); ?>" autocomplete="off"></td></tr>
								<tr data-llamahire-anti-spam-keys><th scope="row"><label for="llamahire-anti-spam-secret-key"><?php esc_html_e( 'Secret key', 'llamahire' ); ?></label></th><td><input class="regular-text code" type="password" id="llamahire-anti-spam-secret-key" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_secret_key]" value="<?php echo esc_attr( $settings['anti_spam_secret_key'] ); ?>" autocomplete="new-password"><p class="description"><?php esc_html_e( 'Protection becomes active only when a provider and both keys are saved.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Protected forms', 'llamahire' ); ?></th><td><fieldset><?php if ( $is_job_board ) : ?><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_registration]" value="1" <?php checked( $settings['anti_spam_registration'], 1 ); ?>> <?php esc_html_e( 'Employer registration', 'llamahire' ); ?></label><br><?php else : ?><input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_registration]" value="<?php echo esc_attr( $settings['anti_spam_registration'] ); ?>"><?php endif; ?><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[anti_spam_applications]" value="1" <?php checked( $settings['anti_spam_applications'], 1 ); ?>> <?php esc_html_e( 'Candidate applications', 'llamahire' ); ?></label></fieldset><p class="description"><?php esc_html_e( 'Approved employers are already authenticated, so routine job edits and draft saves are not interrupted by a challenge.', 'llamahire' ); ?></p></td></tr>
							</table>
						</section>

						<section id="llamahire-settings-notifications" class="llamahire-settings-section" data-llamahire-settings-section="notifications" aria-labelledby="llamahire-settings-notifications-title">
							<header><h2 id="llamahire-settings-notifications-title" tabindex="-1"><?php esc_html_e( 'Notifications', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Choose where application alerts are sent and customize the messages candidates and hiring teams receive.', 'llamahire' ); ?></p></header>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-notification-email"><?php esc_html_e( 'Hiring inbox', 'llamahire' ); ?></label></th><td><input class="regular-text" type="email" id="llamahire-notification-email" name="<?php echo esc_attr( self::OPTION ); ?>[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" required><p class="description"><?php esc_html_e( 'New application notifications are sent here.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-email-sender-name"><?php esc_html_e( 'Sender name', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-email-sender-name" name="<?php echo esc_attr( self::OPTION ); ?>[email_sender_name]" value="<?php echo esc_attr( $settings['email_sender_name'] ); ?>" maxlength="120" required></td></tr>
								<tr><th scope="row"><label for="llamahire-email-sender-email"><?php esc_html_e( 'Sender email', 'llamahire' ); ?></label></th><td><input class="regular-text" type="email" id="llamahire-email-sender-email" name="<?php echo esc_attr( self::OPTION ); ?>[email_sender_email]" value="<?php echo esc_attr( $settings['email_sender_email'] ); ?>" required><p class="description"><?php esc_html_e( 'Use an address authorized by your domain and mail provider.', 'llamahire' ); ?></p></td></tr>
								<tr><th scope="row"><label for="llamahire-employer-email-subject"><?php esc_html_e( 'Employer subject', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-employer-email-subject" name="<?php echo esc_attr( self::OPTION ); ?>[employer_email_subject]" value="<?php echo esc_attr( $settings['employer_email_subject'] ); ?>" maxlength="200" required></td></tr>
								<tr><th scope="row"><label for="llamahire-employer-email-body"><?php esc_html_e( 'Employer message', 'llamahire' ); ?></label></th><td><textarea class="large-text code" rows="6" id="llamahire-employer-email-body" name="<?php echo esc_attr( self::OPTION ); ?>[employer_email_body]" maxlength="5000" required><?php echo esc_textarea( $settings['employer_email_body'] ); ?></textarea></td></tr>
								<tr><th scope="row"><label for="llamahire-candidate-email-subject"><?php esc_html_e( 'Candidate subject', 'llamahire' ); ?></label></th><td><input class="regular-text" type="text" id="llamahire-candidate-email-subject" name="<?php echo esc_attr( self::OPTION ); ?>[candidate_email_subject]" value="<?php echo esc_attr( $settings['candidate_email_subject'] ); ?>" maxlength="200" required></td></tr>
								<tr><th scope="row"><label for="llamahire-candidate-email-body"><?php esc_html_e( 'Candidate message', 'llamahire' ); ?></label></th><td><textarea class="large-text code" rows="8" id="llamahire-candidate-email-body" name="<?php echo esc_attr( self::OPTION ); ?>[candidate_email_body]" maxlength="5000" required><?php echo esc_textarea( $settings['candidate_email_body'] ); ?></textarea><p class="description"><?php esc_html_e( 'Plain text. Available placeholders: {candidate_name}, {job_title}, {site_name}, {site_url}, and {applications_url}.', 'llamahire' ); ?></p></td></tr>
							</table>
						</section>

						<section id="llamahire-settings-pages" class="llamahire-settings-section" data-llamahire-settings-section="pages" aria-labelledby="llamahire-settings-pages-title">
							<header><h2 id="llamahire-settings-pages-title" tabindex="-1"><?php esc_html_e( 'Pages', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Connect the public pages that visitors and employers use.', 'llamahire' ); ?></p></header>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="llamahire-careers-page"><?php esc_html_e( 'Careers page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-careers-page', self::OPTION . '[careers_page_id]', $settings['careers_page_id'], __( 'No Careers page selected', 'llamahire' ), '', __( 'Careers page', 'llamahire' ) ); ?></td></tr>
								<?php if ( $is_job_board ) : ?>
									<tr><th scope="row"><label for="llamahire-submit-job-page"><?php esc_html_e( 'Submit a Job page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-submit-job-page', self::OPTION . '[submit_job_page_id]', $settings['submit_job_page_id'], __( 'Create automatically', 'llamahire' ), '', __( 'Submit a Job page', 'llamahire' ) ); ?><p class="description"><?php esc_html_e( 'Use a page containing [llamahire_submit_job].', 'llamahire' ); ?></p></td></tr>
									<tr><th scope="row"><label for="llamahire-my-jobs-page"><?php esc_html_e( 'My Jobs page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-my-jobs-page', self::OPTION . '[my_jobs_page_id]', $settings['my_jobs_page_id'], __( 'Create automatically', 'llamahire' ), '', __( 'My Jobs page', 'llamahire' ) ); ?><p class="description"><?php esc_html_e( 'Use a page containing [llamahire_my_jobs].', 'llamahire' ); ?></p></td></tr>
									<tr><th scope="row"><label for="llamahire-employer-registration-page"><?php esc_html_e( 'Employer registration page', 'llamahire' ); ?></label></th><td><?php self::page_select( 'llamahire-employer-registration-page', self::OPTION . '[employer_registration_page_id]', $settings['employer_registration_page_id'], __( 'Create automatically', 'llamahire' ), '', __( 'Employer registration page', 'llamahire' ) ); ?><p class="description"><?php esc_html_e( 'Use a page containing [llamahire_employer_registration].', 'llamahire' ); ?></p></td></tr>
								<?php endif; ?>
							</table>
						</section>

					</form>
					<div class="llamahire-settings-section llamahire-settings-email-tools" data-llamahire-settings-related="notifications">
						<?php self::email_delivery_panel(); ?>
					</div>
					<div class="llamahire-settings-actions">
						<span class="llamahire-settings-save-note"><?php esc_html_e( 'Changes apply when you save.', 'llamahire' ); ?></span>
						<button type="submit" form="llamahire-settings-form" name="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'llamahire' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function email_delivery_panel() {
		$service     = Plugin::instance()->services()->get( Service_IDs::NOTIFICATIONS );
		$preview     = $service->preview( array( 'name' => __( 'Alex Candidate', 'llamahire' ), 'email' => 'alex@example.test' ), 0 );
		$diagnostics = wp_parse_args( (array) get_option( self::EMAIL_DIAGNOSTICS, array() ), array( 'tested_at' => '', 'success' => null, 'error_codes' => array() ) );
		?>
		<hr>
		<h2 id="llamahire-email-delivery"><?php esc_html_e( 'Email previews and delivery diagnostics', 'llamahire' ); ?></h2>
		<p><?php esc_html_e( 'Previews use saved settings and sample candidate data. Save changes before testing an edit.', 'llamahire' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:20px;max-width:1100px">
			<?php foreach ( array( 'employer' => __( 'Employer email preview', 'llamahire' ), 'candidate' => __( 'Candidate email preview', 'llamahire' ) ) as $channel => $title ) : ?>
			<details><summary><strong><?php echo esc_html( $title ); ?></strong></summary><div class="card" style="max-width:none;margin-top:8px"><p><strong><?php esc_html_e( 'To:', 'llamahire' ); ?></strong> <?php echo esc_html( $preview[ $channel ]['to'] ); ?><br><strong><?php esc_html_e( 'Subject:', 'llamahire' ); ?></strong> <?php echo esc_html( $preview[ $channel ]['subject'] ); ?></p><pre style="white-space:pre-wrap"><?php echo esc_html( $preview[ $channel ]['message'] ); ?></pre></div></details>
			<?php endforeach; ?>
		</div>
		<h3><?php esc_html_e( 'Send a test email', 'llamahire' ); ?></h3>
		<?php if ( $diagnostics['tested_at'] ) : ?><p><strong><?php esc_html_e( 'Last test:', 'llamahire' ); ?></strong> <?php echo esc_html( get_date_from_gmt( $diagnostics['tested_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?> — <?php echo $diagnostics['success'] ? esc_html__( 'Accepted by WordPress', 'llamahire' ) : esc_html__( 'Failed', 'llamahire' ); ?><?php if ( ! $diagnostics['success'] && $diagnostics['error_codes'] ) : ?> (<?php echo esc_html( implode( ', ', array_map( 'sanitize_key', (array) $diagnostics['error_codes'] ) ) ); ?>)<?php endif; ?></p><?php else : ?><p><?php esc_html_e( 'No delivery test has been run yet.', 'llamahire' ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="llamahire_test_email">
			<?php wp_nonce_field( 'llamahire_test_email' ); ?>
			<div class="llamahire-settings-test-row">
				<label for="llamahire-test-email"><strong><?php esc_html_e( 'Test recipient', 'llamahire' ); ?></strong></label>
				<div>
					<input class="regular-text" type="email" id="llamahire-test-email" name="test_email" value="<?php echo esc_attr( Settings::get()['notification_email'] ); ?>" required>
					<?php submit_button( __( 'Send test email', 'llamahire' ), 'secondary', 'submit', false ); ?>
				</div>
			</div>
		</form>
		<p class="description"><?php esc_html_e( 'A successful test means WordPress accepted the message. Confirm inbox delivery and configure SMTP or another mail transport if necessary.', 'llamahire' ); ?></p>
		<?php
	}

	public static function send_test_email() {
		check_admin_referer( 'llamahire_test_email' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot test LlamaHire email delivery.', 'llamahire' ), 403 );
		}
		$to     = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$result = Plugin::instance()->services()->get( Service_IDs::NOTIFICATIONS )->test_delivery( $to );
		update_option(
			self::EMAIL_DIAGNOSTICS,
			array(
				'tested_at'  => current_time( 'mysql', true ),
				'success'    => ! empty( $result['success'] ),
				'error_codes' => array_map( 'sanitize_key', (array) ( $result['error_codes'] ?? array() ) ),
			),
			false
		);
		$status = ! is_email( $to ) ? 'invalid' : ( ! empty( $result['success'] ) ? 'sent' : 'failed' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-settings&email_test=' . $status . '#llamahire-email-delivery' ) );
		exit;
	}

	public static function site_health_tests( $tests ) {
		$tests['direct']['llamahire_email_configuration'] = array( 'label' => __( 'LlamaHire email configuration', 'llamahire' ), 'test' => array( __CLASS__, 'email_configuration_health' ) );
		return $tests;
	}

	public static function email_configuration_health() {
		$settings = self::get();
		$good     = is_email( $settings['notification_email'] ) && is_email( $settings['email_sender_email'] ) && ! empty( $settings['email_sender_name'] );
		return array(
			'label'       => $good ? __( 'LlamaHire email sender is configured', 'llamahire' ) : __( 'LlamaHire email sender needs attention', 'llamahire' ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => array( 'label' => __( 'LlamaHire', 'llamahire' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $good ? __( 'The hiring inbox and sender identity are valid. Send a test email to verify the site mail transport.', 'llamahire' ) : __( 'Add a valid hiring inbox, sender name, and sender email before relying on application notifications.', 'llamahire' ) ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE . '&page=llamahire-settings#llamahire-email-delivery' ) ) . '">' . esc_html__( 'Review email settings', 'llamahire' ) . '</a></p>',
			'test'        => 'llamahire_email_configuration',
		);
	}

	public static function logo_field( $id, $name, $value ) {
		?>
		<div class="llamahire-media-field" data-media-title="<?php esc_attr_e( 'Choose organization logo', 'llamahire' ); ?>" data-media-button="<?php esc_attr_e( 'Use this logo', 'llamahire' ); ?>" data-empty-label="<?php esc_attr_e( 'Choose logo', 'llamahire' ); ?>" data-selected-label="<?php esc_attr_e( 'Replace logo', 'llamahire' ); ?>">
			<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<div class="llamahire-media-preview" style="margin-bottom:8px">
				<?php if ( $value ) : ?><img src="<?php echo esc_url( $value ); ?>" alt="" style="display:block;max-width:240px;max-height:120px;width:auto;height:auto"><?php endif; ?>
			</div>
			<button type="button" class="button llamahire-select-media"><?php echo $value ? esc_html__( 'Replace logo', 'llamahire' ) : esc_html__( 'Choose logo', 'llamahire' ); ?></button>
			<button type="button" class="button button-secondary llamahire-button-danger llamahire-remove-media" <?php echo $value ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove logo', 'llamahire' ); ?></button>
			<p class="description"><?php esc_html_e( 'Choose an image from the Media Library. Use a square or landscape image with a width-to-height ratio between 0.75 and 2.5.', 'llamahire' ); ?></p>
		</div>
		<?php
	}

	public static function page_select( $id, $name, $selected, $empty_label, $describedby = '', $search_label = '' ) {
		$html = wp_dropdown_pages(
			array(
				'id'               => $id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes attributes before rendering.
				'name'             => $name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes attributes before rendering.
				'selected'         => absint( $selected ),
				'show_option_none' => $empty_label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes option labels before rendering.
				'option_none_value' => '0',
				'post_status'      => 'publish',
				'class'            => 'regular-text',
				'echo'             => false,
			)
		);
		if ( $describedby ) {
			$html = str_replace( '<select ', '<select aria-describedby="' . esc_attr( $describedby ) . '" ', $html );
		}
		if ( $search_label ) {
			?>
			<div class="llamahire-page-picker" data-llamahire-page-picker data-select-id="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $search_label ); ?>">
				<div class="llamahire-page-picker__combobox" data-llamahire-page-combobox></div>
				<div class="llamahire-page-picker__native"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes the select; the injected ID is escaped above. ?></div>
			</div>
			<?php
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes the select; the injected ID is escaped above.
	}

	public static function field_mode_select( $id, $name, $selected ) {
		$options = array(
			'required' => __( 'Required', 'llamahire' ),
			'optional' => __( 'Optional', 'llamahire' ),
			'hidden'   => __( 'Do not ask', 'llamahire' ),
		);
		?>
		<select class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
		</select>
		<?php
	}

	private static function localized_standard_options( array $codes, $type, array $fallbacks ) {
		$options = array();
		$locale  = str_replace( '-', '_', get_user_locale() );
		$names   = null;
		if ( class_exists( '\ResourceBundle' ) ) {
			$bundle = \ResourceBundle::create( $locale, 'country' === $type ? 'ICUDATA-region' : 'ICUDATA-curr' );
			if ( $bundle ) {
				$names = $bundle->get( 'country' === $type ? 'Countries' : 'Currencies' );
			}
		}
		foreach ( $codes as $code ) {
			$label = $fallbacks[ $code ] ?? $code;
			if ( $names instanceof \ResourceBundle ) {
				$localized = $names->get( $code );
				if ( $localized instanceof \ResourceBundle ) {
					$localized = $localized->get( 1 );
				}
				if ( is_string( $localized ) && $localized ) {
					$label = $localized;
				}
			}
			$options[ $code ] = $label;
		}
		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
		return $options;
	}

	private static function standard_select( $id, $name, array $options, $selected, $empty_label, $describedby = '', $required = false ) {
		?>
		<select class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped immediately above. ?><?php echo $required ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
			<option value=""><?php echo esc_html( $empty_label ); ?></option>
			<?php foreach ( $options as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected, $code ); ?>><?php echo esc_html( sprintf( '%1$s (%2$s)', $label, $code ) ); ?></option><?php endforeach; ?>
		</select>
		<?php
	}

	private function __construct() {}
}
