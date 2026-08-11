<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Email-verified employer registration and operator approval.
 */
final class Employer_Registration {
	const STATUS_META       = '_llamahire_employer_status';
	const TOKEN_META        = '_llamahire_employer_verification_hash';
	const TOKEN_EXPIRY_META = '_llamahire_employer_verification_expires';
	const COMPANY_META      = '_llamahire_employer_company';
	const POLICY_META       = '_llamahire_employer_policy_version';
	const POLICY_DATE_META  = '_llamahire_employer_policy_accepted_at';
	const STATUS_EMAIL      = 'pending_email';
	const STATUS_APPROVAL   = 'pending_approval';
	const STATUS_APPROVED   = 'approved';

	public static function register() {
		add_shortcode( 'llamahire_employer_registration', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_page' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
		add_action( 'admin_post_llamahire_approve_employer', array( __CLASS__, 'approve_request' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'user_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'user_column' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'user_actions' ), 10, 2 );
		add_action( 'pre_get_users', array( __CLASS__, 'filter_user_list' ) );
		add_filter( 'views_users', array( __CLASS__, 'user_views' ) );
		add_action( 'admin_notices', array( __CLASS__, 'approval_notice' ) );
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
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) || Settings::public_page( $settings['employer_registration_page_id'] ?? 0 ) ) {
			return;
		}
		$existing = get_page_by_path( 'employer-registration', OBJECT, 'page' );
		$page_id  = $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, 'llamahire_employer_registration' ) ? $existing->ID : wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Employer Registration', 'llamahire' ),
				'post_content' => '[llamahire_employer_registration]',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$settings['employer_registration_page_id'] = absint( $page_id );
			update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
		}
	}

	public static function registration_url() {
		$page = Settings::public_page( Settings::get()['employer_registration_page_id'] );
		return $page ? get_permalink( $page ) : home_url( '/' );
	}

	public static function handle_request() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['llamahire_employer_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The registration handler verifies its nonce.
		if ( 'register_employer' === $action ) {
			self::register_employer();
		}
		if ( ! empty( $_GET['llamahire_employer_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The single-use email token authorizes this request.
			self::verify_email();
		}
	}

	private static function register_employer() {
		check_admin_referer( 'llamahire_employer_register', 'llamahire_employer_nonce' );
		if ( is_user_logged_in() ) {
			self::redirect( 'signed_in' );
		}
		$name     = sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) );
		$company  = sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be transformed before WordPress hashes them.
		$accepted = '1' === sanitize_text_field( wp_unslash( $_POST['accept_policy'] ?? '' ) );
		if ( ! $name || ! $company || ! is_email( $email ) ) {
			self::redirect( 'details' );
		}
		if ( strlen( $password ) < 12 ) {
			self::redirect( 'password' );
		}
		if ( ! $accepted ) {
			self::redirect( 'policy' );
		}
		if ( is_wp_error( Anti_Spam::verify( Anti_Spam::CONTEXT_REGISTRATION ) ) ) {
			self::redirect( 'anti_spam' );
		}
		if ( ! self::consume_limits( $email ) ) {
			self::redirect( 'received' );
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => self::available_login( $email ),
					'user_email'   => $email,
					'user_pass'    => $password,
					'display_name' => $name,
					'first_name'   => $name,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				self::redirect( 'received' );
			}
			$user = get_userdata( $user_id );
			$user->set_role( '' );
			update_user_meta( $user_id, self::STATUS_META, self::STATUS_EMAIL );
			update_user_meta( $user_id, self::COMPANY_META, $company );
			update_user_meta( $user_id, self::POLICY_META, self::policy_version() );
			update_user_meta( $user_id, self::POLICY_DATE_META, current_time( 'mysql', true ) );
		}
		if ( self::STATUS_EMAIL === get_user_meta( $user->ID, self::STATUS_META, true ) ) {
			self::send_verification( $user );
		}
		self::redirect( 'received' );
	}

	private static function send_verification( \WP_User $user ) {
		$token = wp_generate_password( 32, false, false );
		update_user_meta( $user->ID, self::TOKEN_META, self::token_hash( $token ) );
		update_user_meta( $user->ID, self::TOKEN_EXPIRY_META, time() + DAY_IN_SECONDS );
		$url = add_query_arg(
			array(
				'llamahire_employer_verify' => 1,
				'user'                       => $user->ID,
				'token'                      => $token,
			),
			self::registration_url()
		);
		$message = sprintf(
			/* translators: 1: site name, 2: company name, 3: verification URL. */
			__( "Confirm your request to register %2\$s as an employer on %1\$s and accept the board’s listing policy.\n\nVerify your email: %3\$s\n\nThis link expires in 24 hours. If you did not make this request, ignore this message.", 'llamahire' ),
			get_bloginfo( 'name' ),
			get_user_meta( $user->ID, self::COMPANY_META, true ),
			$url
		);
		$result = wp_mail( $user->user_email, __( 'Verify your employer account', 'llamahire' ), $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional verification message for the registering employer.
		do_action( 'llamahire_employer_verification_sent', $result, $user->ID );
	}

	private static function verify_email() {
		$user_id = absint( $_GET['user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The single-use token is checked below.
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The single-use token is checked below.
		self::redirect( self::verify_token( $user_id, $token ) );
	}

	public static function verify_token( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = sanitize_text_field( $token );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		$hash    = $user ? (string) get_user_meta( $user_id, self::TOKEN_META, true ) : '';
		$expires = $user ? absint( get_user_meta( $user_id, self::TOKEN_EXPIRY_META, true ) ) : 0;
		if ( ! $user || self::STATUS_EMAIL !== get_user_meta( $user_id, self::STATUS_META, true ) || ! $token || ! $hash || time() > $expires || ! hash_equals( $hash, self::token_hash( $token ) ) ) {
			return 'invalid_link';
		}
		delete_user_meta( $user_id, self::TOKEN_META );
		delete_user_meta( $user_id, self::TOKEN_EXPIRY_META );
		$settings = Settings::get();
		if ( 'automatic' === Settings::employer_approval( $settings['employer_approval'] ) ) {
			self::approve( $user_id );
			$status = 'approved';
		} else {
			update_user_meta( $user_id, self::STATUS_META, self::STATUS_APPROVAL );
			self::notify_operator( $user, false );
			$status = 'awaiting_approval';
		}
		return $status;
	}

	public static function approve_request() {
		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You cannot approve employer accounts.', 'llamahire' ), 403 );
		}
		$user_id = absint( $_POST['user'] ?? $_GET['user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Used to select the nonce action; verified immediately below.
		check_admin_referer( 'llamahire_approve_employer_' . $user_id );
		if ( self::STATUS_APPROVAL === get_user_meta( $user_id, self::STATUS_META, true ) ) {
			self::approve( $user_id );
		}
		wp_safe_redirect( add_query_arg( 'llamahire_employer_approved', 1, self::pending_url() ) );
		exit;
	}

	public static function approve( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return false;
		}
		$user->set_role( Capabilities::EMPLOYER_ROLE );
		update_user_meta( $user->ID, self::STATUS_META, self::STATUS_APPROVED );
		$submit_page = Settings::public_page( Settings::get()['submit_job_page_id'] );
		$submit_url  = $submit_page ? get_permalink( $submit_page ) : home_url( '/' );
		$message     = sprintf(
			/* translators: 1: site name, 2: submit-job URL. */
			__( "Your employer account on %1\$s is approved.\n\nCreate your first job listing: %2\$s", 'llamahire' ),
			get_bloginfo( 'name' ),
			$submit_url
		);
		$result = wp_mail( $user->user_email, __( 'Your employer account is approved', 'llamahire' ), $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional approval notice for the employer.
		self::notify_operator( $user, true );
		do_action( 'llamahire_employer_account_approved', $user->ID, $result );
		return true;
	}

	private static function notify_operator( \WP_User $user, $approved ) {
		$recipient = sanitize_email( Settings::get()['notification_email'] );
		if ( ! is_email( $recipient ) ) {
			return false;
		}
		$company = get_user_meta( $user->ID, self::COMPANY_META, true );
		$subject = $approved ? __( 'Employer account approved', 'llamahire' ) : __( 'Employer account awaiting approval', 'llamahire' );
		$message = sprintf(
			/* translators: 1: contact name, 2: company, 3: user email, 4: user-management URL. */
			__( "Contact: %1\$s\nCompany: %2\$s\nEmail: %3\$s\n\nManage employer accounts: %4\$s", 'llamahire' ),
			$user->display_name,
			$company,
			$user->user_email,
			admin_url( 'users.php' )
		);
		$result = wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One transactional registration notice for the board operator.
		do_action( 'llamahire_employer_operator_notification_sent', $result, $user->ID, $approved );
		return $result;
	}

	public static function shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		wp_enqueue_style( 'llamahire' );
		if ( is_user_logged_in() ) {
			$status = get_user_meta( get_current_user_id(), self::STATUS_META, true );
			if ( current_user_can( 'edit_llamahire_jobs' ) ) {
				$page = Settings::public_page( Settings::get()['my_jobs_page_id'] );
				$url  = $page ? get_permalink( $page ) : home_url( '/' );
				return '<div class="llamahire-employer-registration"><p class="llamahire-notice is-success">' . esc_html__( 'Your employer account is ready.', 'llamahire' ) . '</p><p><a class="llamahire-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Manage your jobs', 'llamahire' ) . '</a></p></div>';
			}
			$message = self::STATUS_APPROVAL === $status ? __( 'Your email is verified and your account is awaiting approval from the job-board operator.', 'llamahire' ) : __( 'Your account cannot submit jobs yet. Check your email or contact the job-board operator.', 'llamahire' );
			return '<div class="llamahire-employer-registration"><p class="llamahire-notice">' . esc_html( $message ) . '</p></div>';
		}
		$result   = sanitize_key( wp_unslash( $_GET['registration'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice.
		$messages = array(
			'received'          => array( 'success', __( 'Check your email for the next step. If an account can be registered with those details, a verification message has been sent.', 'llamahire' ) ),
			'approved'          => array( 'success', __( 'Your email is verified and your employer account is ready. You can now sign in and submit jobs.', 'llamahire' ) ),
			'awaiting_approval' => array( 'success', __( 'Your email is verified. The job-board operator will review your employer account.', 'llamahire' ) ),
			'invalid_link'      => array( 'error', __( 'This verification link is invalid or has expired. Submit the registration form again to request a new link.', 'llamahire' ) ),
			'details'           => array( 'error', __( 'Enter your name, company, and a valid email address.', 'llamahire' ) ),
			'password'          => array( 'error', __( 'Use a password with at least 12 characters.', 'llamahire' ) ),
			'policy'            => array( 'error', __( 'Accept the listing policy to register as an employer.', 'llamahire' ) ),
			'anti_spam'         => array( 'error', __( 'Complete the spam protection check and try again.', 'llamahire' ) ),
			'signed_in'         => array( 'error', __( 'Sign out before registering a different employer account.', 'llamahire' ) ),
		);
		$settings = Settings::get();
		$policy   = $settings['employer_policy_text'];
		$page     = Settings::public_page( $settings['employer_policy_page_id'] );
		ob_start();
		?>
		<div class="llamahire-employer-registration">
			<h2><?php esc_html_e( 'Register as an employer', 'llamahire' ); ?></h2>
			<p><?php esc_html_e( 'Create an employer account to submit and manage job listings.', 'llamahire' ); ?></p>
			<?php if ( isset( $messages[ $result ] ) ) : ?><div class="llamahire-notice is-<?php echo esc_attr( $messages[ $result ][0] ); ?>" role="<?php echo 'error' === $messages[ $result ][0] ? 'alert' : 'status'; ?>"><?php echo esc_html( $messages[ $result ][1] ); ?></div><?php endif; ?>
			<form method="post">
				<input type="hidden" name="llamahire_employer_action" value="register_employer">
				<?php wp_nonce_field( 'llamahire_employer_register', 'llamahire_employer_nonce' ); ?>
				<label><?php esc_html_e( 'Your name', 'llamahire' ); ?><input type="text" name="contact_name" autocomplete="name" required></label>
				<label><?php esc_html_e( 'Company name', 'llamahire' ); ?><input type="text" name="company_name" autocomplete="organization" required></label>
				<label><?php esc_html_e( 'Work email', 'llamahire' ); ?><input type="email" name="email" autocomplete="email" inputmode="email" required></label>
				<label><?php esc_html_e( 'Password', 'llamahire' ); ?><input type="password" name="password" autocomplete="new-password" minlength="12" required><small><?php esc_html_e( 'Use at least 12 characters.', 'llamahire' ); ?></small></label>
				<label class="llamahire-employer-registration__policy"><input type="checkbox" name="accept_policy" value="1" required><span><?php echo wp_kses_post( self::policy_agreement_html( $policy, $page ) ); ?></span></label>
				<?php Anti_Spam::render( Anti_Spam::CONTEXT_REGISTRATION ); ?>
				<button type="submit"><?php esc_html_e( 'Create employer account', 'llamahire' ); ?></button>
			</form>
			<p><a href="<?php echo esc_url( wp_login_url( self::registration_url() ) ); ?>"><?php esc_html_e( 'Already registered? Sign in', 'llamahire' ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function user_columns( $columns ) {
		$columns['llamahire_employer_status'] = __( 'Employer status', 'llamahire' );
		return $columns;
	}

	public static function user_column( $value, $column, $user_id ) {
		if ( 'llamahire_employer_status' !== $column ) {
			return $value;
		}
		$labels = array(
			self::STATUS_EMAIL    => __( 'Email not verified', 'llamahire' ),
			self::STATUS_APPROVAL => __( 'Awaiting approval', 'llamahire' ),
			self::STATUS_APPROVED => __( 'Approved', 'llamahire' ),
		);
		$status = get_user_meta( $user_id, self::STATUS_META, true );
		$label  = isset( $labels[ $status ] ) ? esc_html( $labels[ $status ] ) : '&mdash;';
		if ( self::STATUS_APPROVAL !== $status || ! current_user_can( 'promote_users' ) ) {
			return $label;
		}
		return '<div class="llamahire-employer-approval"><strong>' . $label . '</strong>' . self::approval_link( $user_id ) . '</div>';
	}

	public static function user_actions( $actions, $user ) {
		if ( current_user_can( 'promote_users' ) && self::STATUS_APPROVAL === get_user_meta( $user->ID, self::STATUS_META, true ) ) {
			$actions['llamahire_approve_employer'] = self::approval_link( $user->ID, false );
		}
		return $actions;
	}

	public static function pending_count() {
		$query = new \WP_User_Query(
			array(
				'fields'     => 'ids',
				'number'     => 1,
				'count_total' => true,
				'meta_key'   => self::STATUS_META,
				'meta_value' => self::STATUS_APPROVAL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The status is intentionally stored as user metadata and the query is limited to the approval queue.
			)
		);
		return (int) $query->get_total();
	}

	public static function pending_url() {
		return add_query_arg( 'llamahire_employer_status', self::STATUS_APPROVAL, admin_url( 'users.php' ) );
	}

	public static function filter_user_list( $query ) {
		if ( ! is_admin() || ! current_user_can( 'promote_users' ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['llamahire_employer_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		if ( self::STATUS_APPROVAL === $status ) {
			$query->set( 'meta_key', self::STATUS_META );
			$query->set( 'meta_value', self::STATUS_APPROVAL );
		}
	}

	public static function user_views( $views ) {
		if ( ! current_user_can( 'promote_users' ) ) {
			return $views;
		}
		$count   = self::pending_count();
		$current = self::STATUS_APPROVAL === sanitize_key( wp_unslash( $_GET['llamahire_employer_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$views['llamahire_employer_pending'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( self::pending_url() ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Awaiting employer approval', 'llamahire' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	public static function approval_notice() {
		if ( empty( $_GET['llamahire_employer_approved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation of a completed nonce-protected action.
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The employer account was approved and can now submit job listings.', 'llamahire' ) . '</p></div>';
	}

	private static function approval_link( $user_id, $button = true ) {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'llamahire_approve_employer', 'user' => absint( $user_id ) ), admin_url( 'admin-post.php' ) ), 'llamahire_approve_employer_' . absint( $user_id ) );
		return '<a' . ( $button ? ' class="button button-primary"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html__( 'Approve employer', 'llamahire' ) . '</a>';
	}

	private static function consume_limits( $email ) {
		$ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		$ip = $ip ? $ip : 'unknown';
		return self::consume_limit( 'ip', $ip, absint( apply_filters( 'llamahire_employer_registration_ip_limit', 5 ) ) ) && self::consume_limit( 'email', strtolower( $email ), absint( apply_filters( 'llamahire_employer_registration_email_limit', 3 ) ) );
	}

	private static function consume_limit( $scope, $identity, $limit ) {
		if ( ! $limit ) {
			return true;
		}
		$key   = 'llamahire_reg_' . substr( hash_hmac( 'sha256', $scope . '|' . $identity, wp_salt( 'nonce' ) ), 0, 32 );
		$count = absint( get_transient( $key ) );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function available_login( $email ) {
		$parts = explode( '@', $email );
		$base  = sanitize_user( $parts[0], true );
		$base  = $base ?: 'employer';
		$login = substr( $base, 0, 50 );
		while ( username_exists( $login ) ) {
			$login = substr( $base, 0, 42 ) . '-' . wp_rand( 100000, 999999 );
		}
		return $login;
	}

	private static function policy_version() {
		$settings = Settings::get();
		return hash( 'sha256', $settings['employer_policy_text'] . '|' . absint( $settings['employer_policy_page_id'] ) );
	}

	private static function policy_agreement_html( $policy, $page ) {
		$token = '{listing_policy}';
		$label = esc_html__( 'listing rules', 'llamahire' );
		$link  = $page ? '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>' : $label;
		$text  = esc_html( $policy );
		if ( false !== strpos( $text, $token ) ) {
			return str_replace( $token, $link, $text );
		}
		if ( $page ) {
			return $text . ' <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the full listing policy.', 'llamahire' ) . '</a>';
		}
		return $text;
	}

	private static function token_hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private static function redirect( $status ) {
		wp_safe_redirect( add_query_arg( 'registration', sanitize_key( $status ), self::registration_url() ) );
		exit;
	}

	private function __construct() {}
}
