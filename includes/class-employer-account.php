<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend account details and access links for restricted Employer users.
 */
final class Employer_Account {
	public static function register() {
		add_shortcode( 'llamahire_employer_account', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_page' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
	}

	public static function account_url( array $arguments = array() ) {
		$page = Settings::public_page( Settings::get()['employer_account_page_id'] );
		$url  = $page ? get_permalink( $page ) : Employer_Portal::my_jobs_url();
		return $arguments ? add_query_arg( $arguments, $url ) : $url;
	}

	public static function settings_updated( $old_value, $new_value ) {
		if ( Settings::SITE_MODE_JOB_BOARD === Settings::sanitize_site_mode( $new_value['site_mode'] ?? '' ) ) {
			self::ensure_page( (array) $new_value );
		}
	}

	public static function maybe_ensure_page() {
		if ( current_user_can( 'manage_options' ) && Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() ) {
			self::ensure_page( Settings::get() );
		}
	}

	private static function ensure_page( array $settings ) {
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) || Settings::public_page( $settings['employer_account_page_id'] ?? 0 ) ) {
			return;
		}
		$existing = get_page_by_path( 'employer-account', OBJECT, 'page' );
		$page_id  = $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, 'llamahire_employer_account' ) ? $existing->ID : wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Account', 'llamahire' ),
				'post_name'    => 'employer-account',
				'post_content' => '[llamahire_employer_account]',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$settings['employer_account_page_id'] = absint( $page_id );
			update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
		}
	}

	public static function handle_request() {
		$action = sanitize_key( wp_unslash( $_POST['llamahire_employer_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The selected handler verifies its nonce below.
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() || 'update_account' !== $action ) {
			return;
		}
		if ( ! Employer_Portal::is_frontend_employer() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			wp_die( esc_html__( 'You cannot update this employer account.', 'llamahire' ), 403 );
		}
		check_admin_referer( 'llamahire_employer_update_account', 'llamahire_employer_nonce' );
		$name    = sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) );
		$company = sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) );
		if ( ! $name || ! $company ) {
			self::redirect( 'details' );
		}
		$result = wp_update_user(
			array(
				'ID'           => get_current_user_id(),
				'display_name' => $name,
				'first_name'   => $name,
			)
		);
		if ( is_wp_error( $result ) ) {
			self::redirect( 'save' );
		}
		update_user_meta( get_current_user_id(), Employer_Registration::COMPANY_META, $company );
		self::redirect( 'updated' );
	}

	private static function redirect( $result ) {
		wp_safe_redirect( self::account_url( array( 'account' => sanitize_key( $result ) ) ) );
		exit;
	}

	public static function shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		wp_enqueue_style( 'llamahire' );
		if ( ! is_user_logged_in() ) {
			/* translators: %s: Employer sign-in URL. */
			return '<p class="llamahire-notice">' . wp_kses_post( sprintf( __( 'Please <a href="%s">sign in</a> to manage your employer account.', 'llamahire' ), esc_url( wp_login_url( self::account_url() ) ) ) ) . '</p>';
		}
		if ( ! Employer_Portal::is_frontend_employer() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			return '<p class="llamahire-notice">' . esc_html__( 'Your account cannot manage employer details. Contact the job-board operator.', 'llamahire' ) . '</p>';
		}
		$user     = wp_get_current_user();
		$company  = (string) get_user_meta( $user->ID, Employer_Registration::COMPANY_META, true );
		$result   = sanitize_key( wp_unslash( $_GET['account'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a nonce-protected action.
		$messages = array(
			'updated' => array( 'success', __( 'Your employer details were updated.', 'llamahire' ) ),
			'details' => array( 'error', __( 'Enter your contact name and company name.', 'llamahire' ) ),
			'save'    => array( 'error', __( 'Your employer details could not be updated. Please try again.', 'llamahire' ) ),
		);
		ob_start();
		?>
		<div class="llamahire-employer-account">
			<nav class="llamahire-employer-account__nav" aria-label="<?php esc_attr_e( 'Employer account', 'llamahire' ); ?>"><a href="<?php echo esc_url( Employer_Portal::my_jobs_url() ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to My Jobs', 'llamahire' ); ?></a></nav>
			<h2><?php esc_html_e( 'Employer account', 'llamahire' ); ?></h2>
			<p><?php esc_html_e( 'Keep the contact details used for your job listings up to date.', 'llamahire' ); ?></p>
			<?php if ( isset( $messages[ $result ] ) ) : ?><div class="llamahire-notice is-<?php echo esc_attr( $messages[ $result ][0] ); ?>" role="<?php echo 'error' === $messages[ $result ][0] ? 'alert' : 'status'; ?>"><?php echo esc_html( $messages[ $result ][1] ); ?></div><?php endif; ?>
			<form method="post">
				<input type="hidden" name="llamahire_employer_action" value="update_account">
				<?php wp_nonce_field( 'llamahire_employer_update_account', 'llamahire_employer_nonce' ); ?>
				<label for="llamahire-account-contact-name"><?php esc_html_e( 'Contact name', 'llamahire' ); ?><input id="llamahire-account-contact-name" type="text" name="contact_name" autocomplete="name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
				<label for="llamahire-account-company-name"><?php esc_html_e( 'Company name', 'llamahire' ); ?><input id="llamahire-account-company-name" type="text" name="company_name" autocomplete="organization" value="<?php echo esc_attr( $company ); ?>" required></label>
				<label for="llamahire-account-email"><?php esc_html_e( 'Work email', 'llamahire' ); ?><input id="llamahire-account-email" type="email" value="<?php echo esc_attr( $user->user_email ); ?>" readonly aria-describedby="llamahire-account-email-help"></label>
				<small id="llamahire-account-email-help"><?php esc_html_e( 'Contact the job-board operator if this sign-in email needs to change.', 'llamahire' ); ?></small>
				<button type="submit"><?php esc_html_e( 'Save employer details', 'llamahire' ); ?></button>
			</form>
			<section class="llamahire-employer-account__access" aria-labelledby="llamahire-account-access-title">
				<h3 id="llamahire-account-access-title"><?php esc_html_e( 'Password and access', 'llamahire' ); ?></h3>
				<p><?php esc_html_e( 'WordPress sends password changes through a secure email link.', 'llamahire' ); ?></p>
				<div class="llamahire-employer-account__actions"><a href="<?php echo esc_url( wp_lostpassword_url( self::account_url() ) ); ?>"><?php esc_html_e( 'Change password', 'llamahire' ); ?></a><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'llamahire' ); ?></a></div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}
}
