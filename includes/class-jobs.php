<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Jobs {
	const POST_TYPE = 'llamahire_job';
	const DEPARTMENT_TAXONOMY = 'llamahire_department';
	const TYPE_TAXONOMY = 'llamahire_job_type';
	const META_KEY  = '_llamahire_job';
	const META_WORKPLACE = '_llamahire_workplace';
	const META_FEATURED  = '_llamahire_featured';
	const META_CLOSED    = '_llamahire_closed';
	const META_DEADLINE  = '_llamahire_deadline';
	const META_EXPIRY    = '_llamahire_listing_expires';
	const META_EMPLOYMENT = '_llamahire_employment_type';
	const META_LOCATION   = '_llamahire_location';

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
					'prepare_callback' => array( __CLASS__, 'prepare_meta_for_rest' ),
					'schema'           => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => self::rest_properties(),
					),
				),
			)
		);

		self::register_taxonomies();

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'ensure_identifier' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'sync_query_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'sync_query_meta' ), 10, 4 );
		add_action( 'set_object_terms', array( __CLASS__, 'sync_type_meta' ), 10, 6 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_llamahire_duplicate_job', array( __CLASS__, 'duplicate' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
	}

	/**
	 * Register taxonomies needed by both runtime and activation migrations.
	 *
	 * @internal
	 * @return void
	 */
	public static function register_taxonomies() {
		$department_labels = self::department_labels();
		register_taxonomy(
			self::DEPARTMENT_TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array( 'name' => $department_labels['plural'], 'singular_name' => $department_labels['singular'] ),
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

		register_taxonomy(
			self::TYPE_TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array( 'name' => __( 'Job Types', 'llamahire' ), 'singular_name' => __( 'Job Type', 'llamahire' ) ),
				'public'            => true,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'capabilities'      => array(
					'manage_terms' => 'manage_llamahire_job_types',
					'edit_terms'   => 'edit_llamahire_job_types',
					'delete_terms' => 'delete_llamahire_job_types',
					'assign_terms' => 'assign_llamahire_job_types',
				),
				'rewrite'           => array( 'slug' => 'job-type' ),
			)
		);
	}

	/**
	 * Hide private application routing from public REST responses.
	 *
	 * The meta authorization callback controls writes. REST considers registered
	 * meta readable, so response preparation must independently remove the target
	 * unless the requester can edit this specific job.
	 *
	 * @param mixed            $value   Stored job metadata.
	 * @param \WP_REST_Request $request Current REST request.
	 * @return mixed Prepared metadata.
	 */
	public static function prepare_meta_for_rest( $value, $request ) {
		$post_id = $request instanceof \WP_REST_Request ? absint( $request['id'] ?? 0 ) : 0;
		if ( is_array( $value ) && 'internal' === ( $value['application_method'] ?? 'internal' ) && ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) ) {
			$value['application_target'] = '';
		}

		return $value;
	}

	public static function title_placeholder( $placeholder, $post ) {
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? __( 'Add job title', 'llamahire' ) : $placeholder;
	}

	private static function rest_properties() {
		$strings = array( 'location', 'employment_type', 'workplace', 'salary_currency', 'salary_unit', 'deadline', 'listing_expires', 'featured', 'closed', 'address_street', 'address_locality', 'address_region', 'postal_code', 'address_country', 'applicant_countries', 'job_identifier', 'organization_name', 'organization_tagline', 'organization_url', 'organization_logo', 'application_method', 'application_target' );
		$schema  = array();
		foreach ( $strings as $key ) {
			$schema[ $key ] = array( 'type' => 'string' );
		}
		$schema['salary_min']     = array( 'type' => array( 'number', 'string' ) );
		$schema['salary_max']     = array( 'type' => array( 'number', 'string' ) );
		$schema['organization_id'] = array( 'type' => 'integer' );
		$schema['organization_logo_id'] = array( 'type' => 'integer' );
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
				'organization' => self::editor_organization(),
				'employmentTypes' => self::employment_types(),
				'duplicateNotice' => absint( $_GET['llamahire_duplicated'] ?? 0 ) ? __( 'Job duplicated as a new draft. Review its details before publishing.', 'llamahire' ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
			)
		);
	}

	/**
	 * Return the non-sensitive organization fields consumed by the editor.
	 *
	 * @return array{name:string,website:string,site_mode:string}
	 */
	public static function editor_organization() {
		$settings = Settings::get();

		return array(
			'name'      => (string) $settings['name'],
			'website'   => (string) $settings['website'],
			'site_mode' => (string) $settings['site_mode'],
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
		update_post_meta( $post_id, self::META_EXPIRY, $data['listing_expires'] );
		update_post_meta( $post_id, self::META_EMPLOYMENT, $data['employment_type'] );
		update_post_meta( $post_id, self::META_LOCATION, self::query_location( $data ) );
		wp_set_object_terms( $post_id, $data['employment_type'] ? array( $data['employment_type'] ) : array(), self::TYPE_TAXONOMY );
	}

	public static function sync_type_meta( $object_id, $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids ) {
		if ( self::TYPE_TAXONOMY !== $taxonomy || self::POST_TYPE !== get_post_type( $object_id ) ) {
			return;
		}
		$slugs = wp_get_object_terms( $object_id, self::TYPE_TAXONOMY, array( 'fields' => 'slugs' ) );
		$value = ! is_wp_error( $slugs ) && $slugs ? reset( $slugs ) : '';
		$meta  = self::get_meta( $object_id );
		if ( $meta['employment_type'] !== $value ) {
			self::set_meta( $object_id, array( 'employment_type' => $value ) );
		}
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
		update_post_meta( $post_id, self::META_EXPIRY, $data['listing_expires'] );
		update_post_meta( $post_id, self::META_EMPLOYMENT, $data['employment_type'] );
		update_post_meta( $post_id, self::META_LOCATION, self::query_location( $data ) );
		wp_set_object_terms( $post_id, $data['employment_type'] ? array( $data['employment_type'] ) : array(), self::TYPE_TAXONOMY );
	}

	public static function query_location( array $data ) {
		if ( 'remote' === ( $data['workplace'] ?? '' ) ) {
			return sanitize_text_field( $data['applicant_countries'] ?? '' );
		}
		$parts = array_filter(
			array(
				$data['address_locality'] ?? '',
				$data['address_region'] ?? '',
				$data['address_country'] ?? '',
			)
		);
		if ( empty( $data['address_locality'] ) && empty( $data['address_country'] ) && ! empty( $data['location'] ) ) {
			array_unshift( $parts, $data['location'] );
		}
		return sanitize_text_field( implode( ' ', array_unique( $parts ) ) );
	}

	public static function defaults() {
		return array(
			'location'            => '',
			'employment_type'     => '',
			'workplace'           => 'onsite',
			'salary_min'          => '',
			'salary_max'          => '',
			'salary_currency'     => Settings::get()['default_currency'],
			'salary_unit'         => 'YEAR',
			'deadline'            => '',
			'listing_expires'     => '',
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
			'organization_tagline' => '',
			'organization_url'    => '',
			'organization_logo'   => '',
			'organization_logo_id' => 0,
			'application_method'  => 'internal',
			'application_target'  => '',
		);
	}

	public static function sanitize_meta( $input ) {
		$input       = is_array( $input ) ? $input : array();
		$defaults    = self::defaults();
		$data        = wp_parse_args( $input, $defaults );
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

		$application_method = in_array( $data['application_method'], array( 'internal', 'external_url', 'external_email' ), true ) ? $data['application_method'] : 'internal';
		$application_target = self::sanitize_application_target( $application_method, $data['application_target'] );

		return array(
			'location'            => sanitize_text_field( $data['location'] ),
			'employment_type'     => sanitize_title( $data['employment_type'] ),
			'workplace'           => in_array( $data['workplace'], array( 'onsite', 'hybrid', 'remote' ), true ) ? $data['workplace'] : 'onsite',
			'salary_min'          => $salary_min,
			'salary_max'          => $salary_max,
			'salary_currency'     => $salary_currency,
			'salary_unit'         => in_array( $data['salary_unit'], $units, true ) ? $data['salary_unit'] : 'YEAR',
			'deadline'            => self::valid_date( $data['deadline'] ) ? $data['deadline'] : '',
			'listing_expires'     => self::valid_date( $data['listing_expires'] ) ? $data['listing_expires'] : '',
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
			'organization_tagline' => sanitize_text_field( $data['organization_tagline'] ),
			'organization_url'    => esc_url_raw( $data['organization_url'] ),
			'organization_logo'   => esc_url_raw( $data['organization_logo'] ),
			'organization_logo_id' => absint( $data['organization_logo_id'] ),
			'application_method'  => $application_method,
			'application_target'  => $application_target,
		);
	}

	public static function sanitize_application_target( $method, $value ) {
		if ( 'external_url' === $method ) {
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			return $url && wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
		}
		$email = sanitize_email( $value );
		return is_email( $email ) ? $email : '';
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
			array(
				'relation' => 'OR',
				array( 'key' => self::META_EXPIRY, 'compare' => 'NOT EXISTS' ),
				array( 'key' => self::META_EXPIRY, 'value' => '', 'compare' => '=' ),
				array( 'key' => self::META_EXPIRY, 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		);
	}

	public static function closing_soon_meta_query() {
		$today = current_datetime();
		return array(
			'relation' => 'AND',
			self::open_meta_query(),
			array(
				'relation' => 'OR',
				array( 'key' => self::META_DEADLINE, 'value' => array( $today->format( 'Y-m-d' ), $today->modify( '+7 days' )->format( 'Y-m-d' ) ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
				array( 'key' => self::META_EXPIRY, 'value' => array( $today->format( 'Y-m-d' ), $today->modify( '+7 days' )->format( 'Y-m-d' ) ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
			),
		);
	}

	public static function open_count( $author_id = 0 ) {
		$args = array(
			'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
			'meta_query' => self::open_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( absint( $author_id ) ) {
			$args['author'] = absint( $author_id );
		}
		$query = new \WP_Query(
			$args
		);
		return (int) $query->found_posts;
	}

	public static function get_meta( $post_id ) {
		$data = get_post_meta( $post_id, self::META_KEY, true );
		return self::sanitize_meta( is_array( $data ) ? $data : array() );
	}

	public static function organization( array $meta ) {
		$defaults = Settings::get();
		$is_job_board = Settings::SITE_MODE_JOB_BOARD === Settings::site_mode();
		return array(
			'id'      => absint( $meta['organization_id'] ?? 0 ),
			'name'    => $meta['organization_name'] ?: $defaults['name'],
			'website' => $meta['organization_url'] ?: ( $is_job_board ? '' : $defaults['website'] ),
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
		$term = get_term_by( 'slug', sanitize_title( $value ), self::TYPE_TAXONOMY );
		return $term instanceof \WP_Term ? $term->name : ucwords( strtolower( str_replace( array( '_', '-' ), ' ', $value ) ) );
	}

	public static function employment_types() {
		$terms = get_terms( array( 'taxonomy' => self::TYPE_TAXONOMY, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$options = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->slug ] = $term->name;
			}
		}
		return $options;
	}

	public static function department_labels() {
		if ( Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() ) {
			return array( 'singular' => __( 'Job Category', 'llamahire' ), 'plural' => __( 'Job Categories', 'llamahire' ) );
		}
		return array( 'singular' => __( 'Department', 'llamahire' ), 'plural' => __( 'Departments', 'llamahire' ) );
	}

	public static function is_open( $post_id ) {
		$meta = self::get_meta( $post_id );
		$today = current_time( 'Y-m-d' );
		return '1' !== $meta['closed'] && ( empty( $meta['deadline'] ) || $meta['deadline'] >= $today ) && ( empty( $meta['listing_expires'] ) || $meta['listing_expires'] >= $today );
	}

	public static function listing_expires_soon( $post_id, $days = 7 ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ! self::is_open( $post->ID ) ) {
			return false;
		}
		$meta   = self::get_meta( $post->ID );
		$expiry = $meta['listing_expires'];
		if ( ! $expiry || ( $meta['deadline'] && $meta['deadline'] <= $expiry ) ) {
			return false;
		}
		$today = current_datetime();
		return $expiry >= $today->format( 'Y-m-d' ) && $expiry <= $today->modify( '+' . max( 0, absint( $days ) ) . ' days' )->format( 'Y-m-d' );
	}

	public static function duplicate_as_draft( $post_id, $author_id = 0 ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'llamahire_job_not_found', __( 'The job listing could not be found.', 'llamahire' ) );
		}
		$new = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_author'  => absint( $author_id ) ?: absint( $post->post_author ),
				/* translators: %s: original job title. */
				'post_title'   => sprintf( __( '%s (Copy)', 'llamahire' ), $post->post_title ),
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$duplicate_meta = self::get_meta( $post->ID );
		$duplicate_meta = array_merge(
			$duplicate_meta,
			array(
				'closed'           => '0',
				'deadline'         => '',
				'featured'         => '0',
				'job_identifier'   => '',
				'listing_expires'  => '',
			)
		);
		self::set_meta( $new, $duplicate_meta );
		foreach ( array( self::DEPARTMENT_TAXONOMY, self::TYPE_TAXONOMY ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new, $terms, $taxonomy );
			}
		}
		return $new;
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
		$new = self::duplicate_as_draft( $post_id );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html__( 'WordPress could not duplicate this job. Please try again.', 'llamahire' ), 500 );
		}
		wp_safe_redirect( add_query_arg( 'llamahire_duplicated', $post_id, get_edit_post_link( $new, 'url' ) ) );
		exit;
	}

	public static function post_states( $states, $post ) {
		if ( self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $states;
		}
		$meta = self::get_meta( $post->ID );
		if ( '1' === $meta['closed'] ) {
			$states['llamahire_closed'] = __( 'Closed', 'llamahire' );
		} elseif ( ( ! empty( $meta['deadline'] ) && $meta['deadline'] < current_time( 'Y-m-d' ) ) || ( ! empty( $meta['listing_expires'] ) && $meta['listing_expires'] < current_time( 'Y-m-d' ) ) ) {
			$states['llamahire_expired'] = __( 'Expired', 'llamahire' );
		}
		return $states;
	}
}
