<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Jobs {
	const POST_TYPE = 'llamahire_job';
	const META_KEY  = '_llamahire_job';
	const META_WORKPLACE = '_llamahire_workplace';
	const META_FEATURED  = '_llamahire_featured';
	const META_CLOSED    = '_llamahire_closed';
	const META_DEADLINE  = '_llamahire_deadline';

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Jobs', 'llamahire' ),
					'singular_name' => __( 'Job', 'llamahire' ),
					'add_new_item'  => __( 'Add new job', 'llamahire' ),
					'edit_item'     => __( 'Edit job', 'llamahire' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'capability_type' => array( 'llamahire_job', 'llamahire_jobs' ),
				'map_meta_cap' => true,
				'has_archive'  => 'jobs',
				'rewrite'      => array( 'slug' => 'jobs' ),
				'menu_icon'    => 'dashicons-businessperson',
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'template'     => array(
					array( 'core/heading', array( 'level' => 2, 'content' => 'About the role' ) ),
					array( 'core/paragraph' ),
					array( 'core/heading', array( 'level' => 2, 'content' => 'What you’ll do' ) ),
					array( 'core/list' ),
					array( 'core/heading', array( 'level' => 2, 'content' => 'What you’ll bring' ) ),
					array( 'core/list' ),
				),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			array(
				'type'              => 'object',
				'single'            => true,
				'default'           => self::defaults(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => self::rest_properties(),
					),
				),
			)
		);

		register_taxonomy(
			'llamahire_department',
			self::POST_TYPE,
			array(
				'labels'            => array( 'name' => __( 'Departments', 'llamahire' ), 'singular_name' => __( 'Department', 'llamahire' ) ),
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'capabilities'      => array(
					'manage_terms' => 'manage_llamahire_departments',
					'edit_terms'   => 'edit_llamahire_departments',
					'delete_terms' => 'delete_llamahire_departments',
					'assign_terms' => 'assign_llamahire_departments',
				),
				'rewrite'           => array( 'slug' => 'job-department' ),
			)
		);

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'ensure_identifier' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'sync_query_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'sync_query_meta' ), 10, 4 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_llamahire_duplicate_job', array( __CLASS__, 'duplicate' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
	}

	private static function rest_properties() {
		$strings = array( 'location', 'employment_type', 'workplace', 'salary_currency', 'salary_unit', 'deadline', 'featured', 'closed', 'address_street', 'address_locality', 'address_region', 'postal_code', 'address_country', 'applicant_countries', 'job_identifier', 'organization_name', 'organization_url', 'organization_logo' );
		$schema  = array();
		foreach ( $strings as $key ) {
			$schema[ $key ] = array( 'type' => 'string' );
		}
		$schema['salary_min']     = array( 'type' => array( 'number', 'string' ) );
		$schema['salary_max']     = array( 'type' => array( 'number', 'string' ) );
		$schema['organization_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	public static function enqueue_editor() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script(
			'llamahire-job-editor',
			LLAMAHIRE_URL . 'assets/js/job-editor.js',
			array( 'wp-block-editor', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			(string) filemtime( LLAMAHIRE_PATH . 'assets/js/job-editor.js' ),
			true
		);
		wp_enqueue_style( 'llamahire-job-editor', LLAMAHIRE_URL . 'assets/css/job-editor.css', array( 'wp-components' ), (string) filemtime( LLAMAHIRE_PATH . 'assets/css/job-editor.css' ) );
		wp_localize_script(
			'llamahire-job-editor',
			'llamahireJobEditor',
			array(
				'defaults'     => self::defaults(),
				'organization' => Settings::get(),
				'duplicateNotice' => absint( $_GET['llamahire_duplicated'] ?? 0 ) ? __( 'Job duplicated as a new draft. Review its details before publishing.', 'llamahire' ) : '',
			)
		);
	}

	public static function ensure_identifier( $post_id, $post, $update, $post_before ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$meta = self::get_meta( $post_id );
		if ( empty( $meta['job_identifier'] ) ) {
			self::set_meta( $post_id, array( 'job_identifier' => 'llamahire-' . get_current_blog_id() . '-job-' . $post_id ) );
		}
	}

	public static function sync_query_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( self::META_KEY !== $meta_key || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$data = self::sanitize_meta( $meta_value );
		update_post_meta( $post_id, self::META_WORKPLACE, $data['workplace'] );
		update_post_meta( $post_id, self::META_FEATURED, $data['featured'] );
		update_post_meta( $post_id, self::META_CLOSED, $data['closed'] );
		update_post_meta( $post_id, self::META_DEADLINE, $data['deadline'] );
	}

	public static function set_meta( $post_id, array $data ) {
		$current = get_post_meta( $post_id, self::META_KEY, true );
		$current = is_array( $current ) ? $current : array();
		$data    = self::sanitize_meta( array_merge( $current, $data ) );
		update_post_meta( $post_id, self::META_KEY, $data );
		update_post_meta( $post_id, self::META_WORKPLACE, $data['workplace'] );
		update_post_meta( $post_id, self::META_FEATURED, $data['featured'] );
		update_post_meta( $post_id, self::META_CLOSED, $data['closed'] );
		update_post_meta( $post_id, self::META_DEADLINE, $data['deadline'] );
	}

	public static function defaults() {
		return array(
			'location'            => '',
			'employment_type'     => 'FULL_TIME',
			'workplace'           => 'onsite',
			'salary_min'          => '',
			'salary_max'          => '',
			'salary_currency'     => Settings::get()['default_currency'],
			'salary_unit'         => 'YEAR',
			'deadline'            => '',
			'featured'            => '0',
			'closed'              => '0',
			'address_street'      => '',
			'address_locality'    => Settings::get()['default_locality'],
			'address_region'      => Settings::get()['default_region'],
			'postal_code'         => '',
			'address_country'     => Settings::get()['default_country'],
			'applicant_countries' => '',
			'job_identifier'      => '',
			'organization_id'     => 0,
			'organization_name'   => '',
			'organization_url'    => '',
			'organization_logo'   => '',
		);
	}

	public static function sanitize_meta( $input ) {
		$input       = is_array( $input ) ? $input : array();
		$defaults    = self::defaults();
		$data        = wp_parse_args( $input, $defaults );
		$employment = array( 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' );
		$units       = array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' );
		$countries   = array_filter( array_map( 'trim', explode( ',', strtoupper( sanitize_text_field( $data['applicant_countries'] ) ) ) ) );
		$countries   = array_values( array_unique( array_filter( $countries, static function ( $code ) { return (bool) preg_match( '/^[A-Z]{2}$/', $code ); } ) ) );
		$number      = static function ( $value ) {
			if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
				return '';
			}
			$value = (float) $value;
			return is_finite( $value ) && $value > 0 ? $value : '';
		};
		$salary_min      = $number( $data['salary_min'] );
		$salary_max      = $number( $data['salary_max'] );
		$salary_currency = Settings::currency_code( $data['salary_currency'], '' );
		if ( ! $salary_currency || ( '' !== $salary_min && '' !== $salary_max && $salary_max < $salary_min ) ) {
			$salary_min = '';
			$salary_max = '';
		}

		return array(
			'location'            => sanitize_text_field( $data['location'] ),
			'employment_type'     => in_array( $data['employment_type'], $employment, true ) ? $data['employment_type'] : 'FULL_TIME',
			'workplace'           => in_array( $data['workplace'], array( 'onsite', 'hybrid', 'remote' ), true ) ? $data['workplace'] : 'onsite',
			'salary_min'          => $salary_min,
			'salary_max'          => $salary_max,
			'salary_currency'     => $salary_currency,
			'salary_unit'         => in_array( $data['salary_unit'], $units, true ) ? $data['salary_unit'] : 'YEAR',
			'deadline'            => self::valid_date( $data['deadline'] ) ? $data['deadline'] : '',
			'featured'            => empty( $data['featured'] ) || '0' === (string) $data['featured'] ? '0' : '1',
			'closed'              => empty( $data['closed'] ) || '0' === (string) $data['closed'] ? '0' : '1',
			'address_street'      => sanitize_text_field( $data['address_street'] ),
			'address_locality'    => sanitize_text_field( $data['address_locality'] ),
			'address_region'      => sanitize_text_field( $data['address_region'] ),
			'postal_code'         => sanitize_text_field( $data['postal_code'] ),
			'address_country'     => Settings::country_code( $data['address_country'] ),
			'applicant_countries' => implode( ', ', $countries ),
			'job_identifier'      => sanitize_text_field( $data['job_identifier'] ),
			'organization_id'     => absint( $data['organization_id'] ),
			'organization_name'   => sanitize_text_field( $data['organization_name'] ),
			'organization_url'    => esc_url_raw( $data['organization_url'] ),
			'organization_logo'   => esc_url_raw( $data['organization_logo'] ),
		);
	}

	public static function valid_date( $value ) {
		$value = (string) $value;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $value === $date->format( 'Y-m-d' );
	}

	public static function open_meta_query() {
		return array(
			'relation' => 'AND',
			array( 'key' => self::META_CLOSED, 'value' => '1', 'compare' => '!=' ),
			array(
				'relation' => 'OR',
				array( 'key' => self::META_DEADLINE, 'value' => '', 'compare' => '=' ),
				array( 'key' => self::META_DEADLINE, 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		);
	}

	public static function open_count() {
		$query = new \WP_Query(
			array(
				'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
				'meta_query' => self::open_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $query->found_posts;
	}

	public static function get_meta( $post_id ) {
		$data = get_post_meta( $post_id, self::META_KEY, true );
		return self::sanitize_meta( is_array( $data ) ? $data : array() );
	}

	public static function organization( array $meta ) {
		$defaults = Settings::get();
		return array(
			'id'      => absint( $meta['organization_id'] ?? 0 ),
			'name'    => $meta['organization_name'] ?: $defaults['name'],
			'website' => $meta['organization_url'] ?: $defaults['website'],
			'logo'    => $meta['organization_logo'] ?: $defaults['logo'],
		);
	}

	public static function location_label( array $meta ) {
		if ( 'remote' === $meta['workplace'] ) {
			/* translators: %s: comma-separated eligible country codes. */
			return $meta['applicant_countries'] ? sprintf( __( 'Remote — %s', 'llamahire' ), $meta['applicant_countries'] ) : __( 'Remote', 'llamahire' );
		}
		$parts = array_filter( array( $meta['address_locality'], $meta['address_region'], $meta['address_country'] ) );
		return $parts ? implode( ', ', $parts ) : $meta['location'];
	}

	public static function full_location_label( array $meta ) {
		if ( 'remote' === $meta['workplace'] ) {
			return self::location_label( $meta );
		}
		$parts = array_filter( array( $meta['address_street'], $meta['address_locality'], $meta['address_region'], $meta['postal_code'], $meta['address_country'] ) );
		return $parts ? implode( ', ', $parts ) : $meta['location'];
	}

	public static function salary_label( array $meta ) {
		if ( '' === $meta['salary_min'] && '' === $meta['salary_max'] ) {
			return '';
		}
		$amount = trim( ( '' !== $meta['salary_min'] ? number_format_i18n( $meta['salary_min'] ) : '' ) . ( '' !== $meta['salary_min'] && '' !== $meta['salary_max'] ? '–' : '' ) . ( '' !== $meta['salary_max'] ? number_format_i18n( $meta['salary_max'] ) : '' ) );
		$units  = array( 'HOUR' => __( 'hour', 'llamahire' ), 'DAY' => __( 'day', 'llamahire' ), 'WEEK' => __( 'week', 'llamahire' ), 'MONTH' => __( 'month', 'llamahire' ), 'YEAR' => __( 'year', 'llamahire' ) );
		return sprintf( '%1$s %2$s / %3$s', $meta['salary_currency'], $amount, $units[ $meta['salary_unit'] ] );
	}

	public static function employment_label( $value ) {
		return ucwords( strtolower( str_replace( '_', ' ', $value ) ) );
	}

	public static function is_open( $post_id ) {
		$meta = self::get_meta( $post_id );
		return '1' !== $meta['closed'] && ( empty( $meta['deadline'] ) || $meta['deadline'] >= current_time( 'Y-m-d' ) );
	}

	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?action=llamahire_duplicate_job&post=' . $post->ID ), 'llamahire_duplicate_' . $post->ID );
			$actions['llamahire_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'llamahire' ) . '</a>';
		}
		return $actions;
	}

	public static function duplicate() {
		$post_id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'llamahire_duplicate_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot duplicate this job.', 'llamahire' ) );
		}
		$post = get_post( $post_id );
		/* translators: %s: original job title. */
		$new  = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'draft', 'post_title' => sprintf( __( '%s (Copy)', 'llamahire' ), $post->post_title ), 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt ), true );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html__( 'WordPress could not duplicate this job. Please try again.', 'llamahire' ), 500 );
		}
		self::set_meta( $new, self::get_meta( $post_id ) );
		wp_set_object_terms( $new, wp_get_object_terms( $post_id, 'llamahire_department', array( 'fields' => 'ids' ) ), 'llamahire_department' );
		wp_safe_redirect( add_query_arg( 'llamahire_duplicated', $post_id, get_edit_post_link( $new, 'url' ) ) );
		exit;
	}

	public static function post_states( $states, $post ) {
		if ( self::POST_TYPE === $post->post_type && ! self::is_open( $post->ID ) ) {
			$states['llamahire_closed'] = __( 'Closed', 'llamahire' );
		}
		return $states;
	}
}
