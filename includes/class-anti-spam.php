<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, provider-based bot verification for public forms.
 */
final class Anti_Spam {
	const PROVIDER_NONE      = 'none';
	const PROVIDER_TURNSTILE = 'turnstile';
	const PROVIDER_RECAPTCHA = 'recaptcha';

	const CONTEXT_REGISTRATION = 'employer_registration';
	const CONTEXT_APPLICATION  = 'job_application';

	public static function providers() {
		return array(
			self::PROVIDER_NONE      => __( 'None', 'llamahire' ),
			self::PROVIDER_TURNSTILE => __( 'Cloudflare Turnstile (recommended)', 'llamahire' ),
			self::PROVIDER_RECAPTCHA => __( 'Google reCAPTCHA', 'llamahire' ),
		);
	}

	public static function sanitize_provider( $provider ) {
		$provider = sanitize_key( $provider );
		return in_array( $provider, array_keys( self::providers() ), true ) ? $provider : self::PROVIDER_NONE;
	}

	public static function is_enabled( $context ) {
		$settings = Settings::get();
		$provider = self::sanitize_provider( $settings['anti_spam_provider'] );
		$setting  = self::CONTEXT_REGISTRATION === $context ? 'anti_spam_registration' : 'anti_spam_applications';
		return self::PROVIDER_NONE !== $provider && ! empty( $settings[ $setting ] ) && ! empty( $settings['anti_spam_site_key'] ) && ! empty( $settings['anti_spam_secret_key'] );
	}

	public static function render( $context ) {
		if ( ! self::is_enabled( $context ) ) {
			return;
		}
		$settings = Settings::get();
		$provider = self::sanitize_provider( $settings['anti_spam_provider'] );
		$site_key = $settings['anti_spam_site_key'];
		if ( self::PROVIDER_TURNSTILE === $provider ) {
			wp_enqueue_script( 'llamahire-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
			wp_script_add_data( 'llamahire-turnstile', 'strategy', 'async' );
			echo '<div class="llamahire-anti-spam cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" data-action="' . esc_attr( $context ) . '"></div>';
		} else {
			wp_enqueue_script( 'llamahire-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
			wp_script_add_data( 'llamahire-recaptcha', 'strategy', 'async' );
			echo '<div class="llamahire-anti-spam g-recaptcha" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
		}
		echo '<noscript><p class="llamahire-notice is-error">' . esc_html__( 'JavaScript is required for the board’s spam protection.', 'llamahire' ) . '</p></noscript>';
	}

	public static function verify( $context ) {
		if ( ! self::is_enabled( $context ) ) {
			return true;
		}
		$settings = Settings::get();
		$provider = self::sanitize_provider( $settings['anti_spam_provider'] );
		$filtered = apply_filters( 'llamahire_anti_spam_pre_verify', null, $context, $provider );
		if ( null !== $filtered ) {
			return true === $filtered ? true : new \WP_Error( 'anti_spam_failed', __( 'Complete the spam protection check and try again.', 'llamahire' ) );
		}
		$field = self::PROVIDER_TURNSTILE === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response';
		$token = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each form handler verifies its action-specific nonce before calling this provider check.
		$maximum_token_length = self::PROVIDER_TURNSTILE === $provider ? 2048 : 4096;
		if ( ! $token || strlen( $token ) > $maximum_token_length ) {
			return new \WP_Error( 'anti_spam_missing', __( 'Complete the spam protection check and try again.', 'llamahire' ) );
		}
		$endpoint = self::PROVIDER_TURNSTILE === $provider ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://www.google.com/recaptcha/api/siteverify';
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 3,
				'body'    => array(
					'secret'   => $settings['anti_spam_secret_key'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'anti_spam_unavailable', __( 'Spam protection is temporarily unavailable. Please try again.', 'llamahire' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new \WP_Error( 'anti_spam_failed', __( 'Complete the spam protection check and try again.', 'llamahire' ) );
		}
		if ( self::PROVIDER_TURNSTILE === $provider && ( empty( $body['action'] ) || $context !== $body['action'] ) ) {
			return new \WP_Error( 'anti_spam_action', __( 'Complete the spam protection check and try again.', 'llamahire' ) );
		}
		if ( ! self::hostname_is_allowed( $body['hostname'] ?? '', $context, $provider ) ) {
			return new \WP_Error( 'anti_spam_hostname', __( 'Complete the spam protection check and try again.', 'llamahire' ) );
		}
		return true;
	}

	/**
	 * Confirm that a provider response belongs to this WordPress site.
	 *
	 * @param string $hostname Provider-returned hostname.
	 * @param string $context  Verification context.
	 * @param string $provider Configured provider.
	 * @return bool
	 */
	private static function hostname_is_allowed( $hostname, $context, $provider ) {
		$site_hostname = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$hostnames     = apply_filters( 'llamahire_anti_spam_allowed_hostnames', array( $site_hostname ), $context, $provider );
		$hostnames     = array_filter( array_map( array( __CLASS__, 'normalize_hostname' ), (array) $hostnames ) );

		return in_array( self::normalize_hostname( $hostname ), array_unique( $hostnames ), true );
	}

	/**
	 * Normalize a hostname for an exact comparison.
	 *
	 * @param string $hostname Hostname.
	 * @return string
	 */
	private static function normalize_hostname( $hostname ) {
		return strtolower( rtrim( trim( sanitize_text_field( (string) $hostname ) ), '.' ) );
	}
}
