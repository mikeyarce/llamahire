<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/** Bounded adapter between public application requests and trusted extension providers. */
final class Application_Extensions {
	const MAX_BYTES = 131072;
	private static $errors = array();
	private static $input = array();
	private static $providers = array();
	private static $input_job = 0;
	private static $input_site = 0;

	/** Public numeric context; this exposes no candidate data or unpublished job content. */
	private static function context( $job_id ) {
		$job = get_post( $job_id );
		return array( 'site_id' => get_current_blog_id(), 'job_id' => (int) $job_id, 'owner_id' => $job ? (int) $job->post_author : 0, 'site_mode' => Settings::site_mode() );
	}

	/** Freeze the provider set for this site's job during the current request. */
	private static function providers( $job_id ) {
		$key = get_current_blog_id() . ':' . (int) $job_id;
		if ( ! array_key_exists( $key, self::$providers ) ) {
			try {
				$providers = apply_filters( 'llamahire_application_extensions', array(), self::context( $job_id ) );
				if ( ! is_array( $providers ) || count( $providers ) > 10 ) { throw new \UnexpectedValueException(); }
				foreach ( $providers as $name => $provider ) {
					if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $name ) || ! $provider instanceof Contracts\Application_Extension ) { throw new \UnexpectedValueException(); }
				}
				self::$providers[ $key ] = $providers;
			} catch ( \Throwable $exception ) {
				self::$providers[ $key ] = self::failure();
			}
		}
		return self::$providers[ $key ];
	}

	/** Render trusted providers in the existing form, using request-local error state. */
	public static function render( $job_id ) {
		$providers = self::providers( $job_id );
		if ( is_wp_error( $providers ) ) { return '<p role="alert">' . esc_html( self::failure()->get_error_message() ) . '</p>'; }
		$html = '';
		try {
			foreach ( $providers as $name => $provider ) {
				$markup = $provider->render( self::context( $job_id ), self::$input_job === (int) $job_id && self::$input_site === get_current_blog_id() ? ( self::$input[ $name ] ?? array() ) : array(), self::$input_job === (int) $job_id && self::$input_site === get_current_blog_id() ? ( self::$errors[ $name ] ?? array() ) : array() );
				if ( ! is_string( $markup ) || strlen( $html ) + strlen( $markup ) > self::MAX_BYTES * 4 ) { return '<p role="alert">' . esc_html( self::failure()->get_error_message() ) . '</p>'; }
				$html .= $markup;
			}
		} catch ( \Throwable $exception ) {
			return '<p role="alert">' . esc_html( self::failure()->get_error_message() ) . '</p>';
		}
		return $html;
	}

	/** Validate only this extension payload; no provider reads raw request globals. */
	public static function prepare( $job_id, $input ) {
		$providers = self::providers( $job_id );
		if ( is_wp_error( $providers ) ) { return $providers; }
		if ( ! $providers ) { return array(); }
		$json = wp_json_encode( $input );
		if ( ! is_array( $input ) || false === $json || strlen( $json ) > self::MAX_BYTES || array_diff( array_keys( $input ), array_keys( $providers ) ) ) { return self::failure(); }
		self::$input = $input;
		self::$input_job = (int) $job_id;
		self::$input_site = get_current_blog_id();
		self::$errors = array();
		$prepared = array();
		try {
			foreach ( $providers as $name => $provider ) {
				$values = $input[ $name ] ?? array();
				if ( ! is_array( $values ) ) { return self::failure(); }
				$result = $provider->prepare( self::context( $job_id ), $values );
				if ( null === $result ) { continue; }
				if ( is_wp_error( $result ) ) {
					// Messages are provider-authored fixed validation text, never submitted values.
					foreach ( array_slice( $result->get_error_codes(), 0, 10 ) as $code ) {
						$message = $result->get_error_message( $code );
						if ( is_string( $message ) && strlen( $message ) <= 1000 ) { self::$errors[ $name ][ sanitize_key( $code ) ] = $message; }
					}
					if ( empty( self::$errors[ $name ] ) ) { self::$errors[ $name ] = array( 'invalid' => self::failure()->get_error_message() ); }
					continue;
				}
				$encoded = wp_json_encode( $result );
				if ( ! is_array( $result ) || false === $encoded || strlen( $encoded ) > self::MAX_BYTES ) { return self::failure(); }
				$prepared[ $name ] = $result;
			}
		} catch ( \Throwable $exception ) { return self::failure(); }
		return self::$errors || strlen( wp_json_encode( $prepared ) ) > self::MAX_BYTES ? self::failure() : $prepared;
	}

	/** Route new applications through atomic persistence only when extensions participate. */
	public static function create( array $application, array $prepared ) {
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		if ( ! $prepared ) { return $repository->create_once( $application ); }
		if ( ! $repository instanceof Contracts\Atomic_Application_Repository ) { return self::failure(); }
		$providers = self::providers( $application['job_id'] );
		if ( is_wp_error( $providers ) || array_diff( array_keys( $prepared ), array_keys( $providers ) ) ) { return self::failure(); }
		$providers = array_intersect_key( $providers, $prepared );
		$tables = array();
		try {
			foreach ( $providers as $provider ) {
				$owned = $provider->tables();
				if ( ! is_array( $owned ) ) { return self::failure(); }
				$tables = array_merge( $tables, $owned );
			}
			$context = self::context( $application['job_id'] );
			return $repository->create_with_extension( $application, static function ( $id ) use ( $providers, $context, $prepared ) {
				foreach ( $providers as $name => $provider ) {
					if ( true !== $provider->persist( $id, $context, $prepared[ $name ] ) ) { return false; }
				}
				return true;
			}, $tables );
		} catch ( \Throwable $exception ) { return self::failure(); }
	}

	/** Return normalized detail sections only after Free's ownership/capability check. */
	public static function review( $application_id ) {
		$services = Plugin::instance()->services();
		if ( ! $services->get( Service_IDs::EXTENSION_ACCESS )->can_access_application( $application_id, Capabilities::VIEW_APPLICATIONS ) ) { return array(); }
		$application = $services->get( Service_IDs::APPLICATION_REPOSITORY )->find( $application_id );
		$providers = $application ? self::providers( $application->job_id ) : array();
		if ( is_wp_error( $providers ) ) { return array(); }
		$sections = array();
		foreach ( $providers as $name => $provider ) {
			try {
				$section = self::normalize_review( $provider->review( (int) $application_id ) );
				if ( $section ) { $sections[ $name ] = $section; }
			} catch ( \Throwable $exception ) {
				$sections[ $name ] = array( 'title' => __( 'Additional answers', 'llamahire' ), 'fields' => array( array( 'label' => __( 'Status', 'llamahire' ), 'value' => __( 'Additional answers are temporarily unavailable.', 'llamahire' ), 'url' => '' ) ) );
			}
		}
		return $sections;
	}

	/** Normalize plain, bounded detail data; never accept provider HTML. */
	public static function normalize_review( $section ) {
		if ( null === $section ) { return null; }
		if ( ! is_array( $section ) || ! self::text( $section['title'] ?? null, 200 ) || ! is_array( $section['fields'] ?? null ) || count( $section['fields'] ) > 10 ) { throw new \UnexpectedValueException(); }
		$fields = array();
		foreach ( $section['fields'] as $field ) {
			if ( ! is_array( $field ) || ! self::text( $field['label'] ?? null, 200 ) || ! self::text( $field['value'] ?? null, 2048 ) ) { throw new \UnexpectedValueException(); }
			$url = '';
			if ( 'url' === ( $field['type'] ?? '' ) && filter_var( $field['value'], FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $field['value'], PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
				$url = esc_url_raw( $field['value'], array( 'http', 'https' ) );
			}
			$fields[] = array( 'label' => $field['label'], 'value' => $field['value'], 'url' => $url );
		}
		return array( 'title' => $section['title'], 'fields' => $fields );
	}

	private static function text( $value, $limit ) {
		return is_string( $value ) && strlen( $value ) <= $limit * 4 && 1 === preg_match( '//u', $value ) && preg_match_all( '/./us', $value ) <= $limit;
	}

	/** Shared server-rendered detail slot, including the no-JavaScript workspaces. */
	public static function render_review( $application_id ) {
		$html = '';
		foreach ( self::review( $application_id ) as $section ) {
			$html .= '<section class="llamahire-application-extra"><h4>' . esc_html( $section['title'] ) . '</h4>';
			if ( ! $section['fields'] ) { $html .= '<p>' . esc_html__( 'No additional answers were submitted.', 'llamahire' ) . '</p>'; }
			$html .= '<dl>';
			foreach ( $section['fields'] as $field ) {
				$value = '' !== $field['value'] ? $field['value'] : __( 'Not provided', 'llamahire' );
				$html .= '<dt>' . esc_html( $field['label'] ) . '</dt><dd>' . ( $field['url'] ? '<a href="' . esc_url( $field['url'] ) . '" rel="nofollow noopener noreferrer">' . esc_html( $value ) . '</a>' : nl2br( esc_html( $value ) ) ) . '</dd>';
			}
			$html .= '</dl></section>';
		}
		return $html;
	}

	/** Candidate-safe failure without exception text, values or provider payloads. */
	private static function failure() {
		return new \WP_Error( 'llamahire_extension_invalid', __( 'Check the additional application questions and try again. If the problem continues, contact the employer.', 'llamahire' ) );
	}
}
