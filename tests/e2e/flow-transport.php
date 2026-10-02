<?php
/**
 * Plugin Name: LlamaHire disposable E2E transport
 * Description: Installed only by flow-fixtures.php in the isolated wp-env site.
 */

defined( 'ABSPATH' ) || exit;

// Browser journeys use unique emails but share Docker's client IP. Rate-limit
// boundaries are covered by the PHP suite; repeated journey runs stay isolated.
add_filter( 'llamahire_employer_registration_ip_limit', static function ( $limit ) { return get_option( 'llamahire_e2e_flows' ) ? 0 : $limit; } );

add_filter(
	'pre_http_request',
	static function ( $response, $arguments, $url ) {
		if ( get_option( 'llamahire_e2e_flows' ) && in_array( $url, array( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'https://www.google.com/recaptcha/api/siteverify' ), true ) ) {
			update_option( 'llamahire_e2e_spam_checks', (int) get_option( 'llamahire_e2e_spam_checks', 0 ) + 1, false );
			return array( 'headers' => array(), 'body' => '{"success":false}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		}
		return $response;
	},
	10,
	3
);

add_filter(
	'pre_wp_mail',
	static function ( $result, $mail ) {
		if ( ! get_option( 'llamahire_e2e_flows' ) ) {
			return $result;
		}
		// Store only the verification link, never candidate mail bodies or recipients.
		if ( preg_match( '~https?://[^\s]+llamahire_employer_verify[^\s]+~', $mail['message'], $matches ) ) {
			update_option( 'llamahire_e2e_verification_url', $matches[0], false );
		}
		update_option( 'llamahire_e2e_mail_count', (int) get_option( 'llamahire_e2e_mail_count', 0 ) + 1, false );
		return 'fail' !== get_option( 'llamahire_e2e_mail_mode' );
	},
	10,
	2
);
