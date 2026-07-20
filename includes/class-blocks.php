<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Blocks {
	const QUERY_KEYS = array( 'job_search', 'department', 'employment_type', 'workplace', 'location', 'featured', 'job_page' );

	public static function register() {
		wp_register_script( 'llamahire-blocks-editor', LLAMAHIRE_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n', 'wp-server-side-render' ), LLAMAHIRE_VERSION, true );
		register_block_type( LLAMAHIRE_PATH . 'blocks/jobs-directory', array( 'render_callback' => array( __CLASS__, 'render_directory' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/job-search', array( 'render_callback' => array( __CLASS__, 'render_search' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/job-filters', array( 'render_callback' => array( __CLASS__, 'render_filters' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/application-form', array( 'render_callback' => array( __CLASS__, 'render_form' ) ) );
		add_filter( 'the_content', array( __CLASS__, 'single_job_content' ) );
	}

	public static function single_job_content( $content ) {
		if ( is_admin() || ! is_singular( Jobs::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$meta    = Jobs::get_meta( get_the_ID() );
		$organization = Jobs::organization( $meta );
		$rows = array(
			__( 'Organization', 'llamahire' ) => $organization['name'],
			__( 'Location', 'llamahire' )     => Jobs::full_location_label( $meta ),
			__( 'Workplace', 'llamahire' )    => ucfirst( $meta['workplace'] ),
			__( 'Employment', 'llamahire' )   => Jobs::employment_label( $meta['employment_type'] ),
			__( 'Salary', 'llamahire' )       => Jobs::salary_label( $meta ),
			__( 'Posted', 'llamahire' )       => get_the_date( '', get_the_ID() ),
			__( 'Apply by', 'llamahire' )     => $meta['deadline'] ? wp_date( get_option( 'date_format' ), strtotime( $meta['deadline'] ) ) : '',
			__( 'Job reference', 'llamahire' ) => $meta['job_identifier'],
		);
		$details = '<dl class="llamahire-job-facts">';
		foreach ( array_filter( $rows ) as $label => $value ) {
			$details .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		$details .= '</dl>';
		if ( ! has_block( 'llamahire/application-form', get_the_ID() ) ) {
			$content .= self::render_form(
				array(
					'jobId'   => get_the_ID(),
					'heading' => __( 'Apply for this role', 'llamahire' ),
				)
			);
		}
		return $details . $content;
	}

	public static function render_directory( $attributes ) {
		$state      = self::query_state();
		$meta_query = Jobs::open_meta_query();
		if ( $state['workplace'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_WORKPLACE,
				'value'   => $state['workplace'],
				'compare' => '=',
			);
		}
		if ( $state['employment_type'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_EMPLOYMENT,
				'value'   => $state['employment_type'],
				'compare' => '=',
			);
		}
		if ( $state['location'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_LOCATION,
				'value'   => $state['location'],
				'compare' => 'LIKE',
			);
		}
		if ( ! empty( $attributes['featuredOnly'] ) || $state['featured'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_FEATURED,
				'value'   => '1',
				'compare' => '=',
			);
		}
		$args = array(
			'post_type'      => Jobs::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => min( 50, max( 1, absint( $attributes['perPage'] ?? 12 ) ) ),
			'paged'          => $state['job_page'],
			's'              => $state['job_search'],
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( $state['department'] ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'llamahire_department',
					'field'    => 'slug',
					'terms'    => $state['department'],
				),
			); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$query = new \WP_Query( $args );
		if ( $state['job_page'] > 1 && 0 === (int) $query->post_count ) {
			$args['paged'] = 1;
			$first_page    = new \WP_Query( $args );
			if ( $first_page->found_posts > 0 ) {
				$state['job_page'] = max( 1, (int) $first_page->max_num_pages );
				if ( 1 === $state['job_page'] ) {
					$query = $first_page;
				} else {
					$args['paged'] = $state['job_page'];
					$query         = new \WP_Query( $args );
				}
			}
		}

		ob_start();
		wp_enqueue_style( 'llamahire' );
		?>
		<div <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-directory' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php if ( ! empty( $attributes['showFilters'] ) ) : ?>
			<?php echo self::query_form( $state, true, true, array( 'class' => 'llamahire-filters llamahire-filters--combined' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form markup is escaped by query_form(). ?>
			<?php endif; ?>
			<div class="llamahire-results-summary" aria-live="polite">
				<p>
					<?php
					/* translators: %s: number of matching open jobs. */
					echo esc_html( sprintf( _n( '%s open role', '%s open roles', $query->found_posts, 'llamahire' ), number_format_i18n( $query->found_posts ) ) );
					?>
				</p>
				<?php if ( self::has_active_filters( $state ) ) : ?><a class="llamahire-clear-filters" href="<?php echo esc_url( self::clear_url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?>
			</div>
			<div class="llamahire-job-grid" id="llamahire-job-results">
			<?php
			if ( $query->have_posts() ) :
				while ( $query->have_posts() ) :
					$query->the_post();
					$meta = Jobs::get_meta( get_the_ID() );
					if ( ! Jobs::is_open( get_the_ID() ) ) {
						continue; }
					?>
				<article class="llamahire-job-card">
					<?php
					if ( '1' === $meta['featured'] ) :
						?>
						<span class="llamahire-badge"><?php esc_html_e( 'Featured', 'llamahire' ); ?></span><?php endif; ?>
					<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
					<div class="llamahire-job-meta"><span><?php echo esc_html( Jobs::location_label( $meta ) ); ?></span><span><?php echo esc_html( ucfirst( $meta['workplace'] ) ); ?></span><span><?php echo esc_html( Jobs::employment_label( $meta['employment_type'] ) ); ?></span></div>
					<?php
					if ( has_excerpt() ) :
						?>
						<p><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
					<a class="llamahire-card-link" href="<?php the_permalink(); ?>"><?php esc_html_e( 'View role', 'llamahire' ); ?> <span aria-hidden="true">→</span></a>
				</article>
					<?php
			endwhile; else :
				?>
				<div class="llamahire-empty"><h3><?php esc_html_e( 'No matching open roles', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Try a broader search or clear the filters to see every open role.', 'llamahire' ); ?></p><?php if ( self::has_active_filters( $state ) ) : ?><p><a href="<?php echo esc_url( self::clear_url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a></p><?php endif; ?></div>
				<?php
endif;
			wp_reset_postdata();
			?>
			</div>
			<?php if ( $query->max_num_pages > 1 ) : ?>
			<nav class="llamahire-pagination" aria-label="<?php esc_attr_e( 'Job results pages', 'llamahire' ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'job_page', '%#%', remove_query_arg( 'job_page' ) ),
							'format'    => '',
							'current'   => $state['job_page'],
							'total'     => $query->max_num_pages,
							'type'      => 'list',
							'prev_text' => __( 'Previous', 'llamahire' ),
							'next_text' => __( 'Next', 'llamahire' ),
						)
					)
				);
				?>
			</nav>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_search( $attributes ) {
		wp_enqueue_style( 'llamahire' );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'llamahire-query-block llamahire-search' ) ) . '>' . self::query_form( self::query_state(), true, false, $attributes ) . '</div>';
	}

	public static function render_filters( $attributes ) {
		wp_enqueue_style( 'llamahire' );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'llamahire-query-block llamahire-job-filters' ) ) . '>' . self::query_form( self::query_state(), false, true, $attributes ) . '</div>';
	}

	public static function query_state() {
		$employment_types = Jobs::employment_types();
		$workplace       = sanitize_key( wp_unslash( $_GET['workplace'] ?? '' ) );
		$employment_type = sanitize_key( wp_unslash( $_GET['employment_type'] ?? '' ) );
		return array(
			'job_search'      => sanitize_text_field( wp_unslash( $_GET['job_search'] ?? '' ) ),
			'department'      => sanitize_key( wp_unslash( $_GET['department'] ?? '' ) ),
			'employment_type' => array_key_exists( strtoupper( $employment_type ), $employment_types ) ? strtoupper( $employment_type ) : '',
			'workplace'       => in_array( $workplace, array( 'remote', 'hybrid', 'onsite' ), true ) ? $workplace : '',
			'location'        => sanitize_text_field( wp_unslash( $_GET['location'] ?? '' ) ),
			'featured'        => '1' === sanitize_text_field( wp_unslash( $_GET['featured'] ?? '' ) ) ? '1' : '',
			'job_page'        => max( 1, absint( $_GET['job_page'] ?? 1 ) ),
		);
	}

	private static function query_form( array $state, $include_search, $include_filters, array $attributes = array() ) {
		$controls = array();
		if ( $include_search ) {
			$controls[] = 'job_search';
		}
		$filter_visibility = array(
			'department'      => ! isset( $attributes['showDepartment'] ) || $attributes['showDepartment'],
			'employment_type' => ! isset( $attributes['showEmploymentType'] ) || $attributes['showEmploymentType'],
			'workplace'       => ! isset( $attributes['showWorkplace'] ) || $attributes['showWorkplace'],
			'location'        => ! isset( $attributes['showLocation'] ) || $attributes['showLocation'],
			'featured'        => ! isset( $attributes['showFeatured'] ) || $attributes['showFeatured'],
		);
		if ( $include_filters ) {
			foreach ( $filter_visibility as $key => $visible ) {
				if ( $visible ) {
					$controls[] = $key;
				}
			}
		}
		$class        = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', $attributes['class'] ?? ( $include_search ? 'llamahire-search-form' : 'llamahire-filters' ) ) ) );
		$search_label = self::attribute_text( $attributes, 'label', __( 'Search jobs', 'llamahire' ) );
		$placeholder  = self::attribute_text( $attributes, 'placeholder', __( 'Job title or keyword', 'llamahire' ) );
		$button_label = self::attribute_text( $attributes, 'buttonLabel', $include_search && ! $include_filters ? __( 'Search', 'llamahire' ) : __( 'Apply filters', 'llamahire' ) );
		ob_start();
		?>
		<form class="<?php echo esc_attr( $class ); ?>" method="get" action="<?php echo esc_url( self::form_action_url() ); ?>" role="search" aria-label="<?php echo esc_attr( $include_search && $include_filters ? __( 'Search and filter jobs', 'llamahire' ) : ( $include_search ? __( 'Search jobs', 'llamahire' ) : __( 'Filter jobs', 'llamahire' ) ) ); ?>">
			<?php foreach ( $state as $key => $value ) : if ( 'job_page' !== $key && $value && ! in_array( $key, $controls, true ) ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endif; endforeach; ?>
			<?php if ( $include_search ) : ?>
			<label><span><?php echo esc_html( $search_label ); ?></span><input type="search" name="job_search" value="<?php echo esc_attr( $state['job_search'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"></label>
			<?php endif; ?>
			<?php if ( $include_filters && $filter_visibility['department'] ) : self::department_control( $state['department'] ); endif; ?>
			<?php if ( $include_filters && $filter_visibility['employment_type'] ) : ?>
			<label><span><?php esc_html_e( 'Employment type', 'llamahire' ); ?></span><select name="employment_type"><option value=""><?php esc_html_e( 'Any employment type', 'llamahire' ); ?></option><?php foreach ( Jobs::employment_types() as $value => $label ) : ?><option value="<?php echo esc_attr( strtolower( $value ) ); ?>" <?php selected( $state['employment_type'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<?php endif; ?>
			<?php if ( $include_filters && $filter_visibility['workplace'] ) : ?>
			<label><span><?php esc_html_e( 'Workplace', 'llamahire' ); ?></span><select name="workplace"><option value=""><?php esc_html_e( 'Any workplace', 'llamahire' ); ?></option><option value="remote" <?php selected( $state['workplace'], 'remote' ); ?>><?php esc_html_e( 'Remote', 'llamahire' ); ?></option><option value="hybrid" <?php selected( $state['workplace'], 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'llamahire' ); ?></option><option value="onsite" <?php selected( $state['workplace'], 'onsite' ); ?>><?php esc_html_e( 'On-site', 'llamahire' ); ?></option></select></label>
			<?php endif; ?>
			<?php if ( $include_filters && $filter_visibility['location'] ) : ?>
			<label><span><?php esc_html_e( 'Location', 'llamahire' ); ?></span><input type="search" name="location" value="<?php echo esc_attr( $state['location'] ); ?>" placeholder="<?php esc_attr_e( 'City, region, or country', 'llamahire' ); ?>"></label>
			<?php endif; ?>
			<?php if ( $include_filters && $filter_visibility['featured'] ) : ?>
			<label class="llamahire-checkbox"><input type="checkbox" name="featured" value="1" <?php checked( $state['featured'], '1' ); ?>><span><?php esc_html_e( 'Featured roles only', 'llamahire' ); ?></span></label>
			<?php endif; ?>
			<button type="submit"><?php echo esc_html( $button_label ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function attribute_text( array $attributes, $key, $fallback ) {
		$value = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ? trim( (string) $attributes[ $key ] ) : '';
		return '' === $value ? $fallback : $value;
	}

	private static function department_control( $selected ) {
		$terms = get_terms( array( 'taxonomy' => 'llamahire_department', 'hide_empty' => true ) );
		?>
		<label><span><?php esc_html_e( 'Department', 'llamahire' ); ?></span><select name="department"><option value=""><?php esc_html_e( 'All departments', 'llamahire' ); ?></option><?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; endif; ?></select></label>
		<?php
	}

	private static function has_active_filters( array $state ) {
		foreach ( array_diff( self::QUERY_KEYS, array( 'job_page' ) ) as $key ) {
			if ( ! empty( $state[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function form_action_url() {
		return remove_query_arg( self::QUERY_KEYS );
	}

	private static function clear_url() {
		return remove_query_arg( self::QUERY_KEYS );
	}

	public static function render_form( $attributes ) {
		$job_id = absint( $attributes['jobId'] ?? 0 );
		if ( ! $job_id && is_singular( Jobs::POST_TYPE ) ) {
			$job_id = get_the_ID();
		}
		if ( ! $job_id || ! Jobs::is_open( $job_id ) ) {
			return '<p class="llamahire-notice">' . esc_html__( 'Applications are closed for this role.', 'llamahire' ) . '</p>';
		}
		$result   = sanitize_key( wp_unslash( $_GET['application'] ?? '' ) );
		$messages = array(
			'success'        => __( 'Thanks! Your application has been received.', 'llamahire' ),
			'required'       => __( 'Please provide your name and a valid email address.', 'llamahire' ),
			'resume_size'    => __( 'Your resume must be smaller than 5 MB.', 'llamahire' ),
			'resume_type'    => __( 'Please upload a PDF, DOC, or DOCX resume.', 'llamahire' ),
			'resume_storage' => __( 'Resume uploads are temporarily unavailable. Please contact the employer.', 'llamahire' ),
			'rate_limited'   => __( 'Too many applications were submitted recently. Please wait and try again.', 'llamahire' ),
			'error'          => __( 'We could not save your application. Please try again.', 'llamahire' ),
			'invalid'        => __( 'This application link is no longer valid.', 'llamahire' ),
		);
		wp_enqueue_style( 'llamahire' );
		ob_start();
		?>
		<div <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-application' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> id="llamahire-application">
			<h2><?php echo esc_html( $attributes['heading'] ?? __( 'Apply for this role', 'llamahire' ) ); ?></h2>
			<?php
			if ( isset( $messages[ $result ] ) ) :
				?>
				<div class="llamahire-notice <?php echo 'success' === $result ? 'is-success' : 'is-error'; ?>" role="<?php echo 'success' === $result ? 'status' : 'alert'; ?>" tabindex="-1"><?php echo esc_html( $messages[ $result ] ); ?></div><?php endif; ?>
			<?php if ( 'success' !== $result ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="llamahire_apply"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>"><input type="hidden" name="submission_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'llamahire_apply_' . $job_id, 'llamahire_nonce' ); ?>
				<div class="llamahire-honeypot" aria-hidden="true"><label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
				<label><?php esc_html_e( 'Name', 'llamahire' ); ?> <span aria-hidden="true">*</span><input type="text" name="name" required autocomplete="name"></label>
				<label><?php esc_html_e( 'Email', 'llamahire' ); ?> <span aria-hidden="true">*</span><input type="email" name="email" required autocomplete="email"></label>
				<label><?php esc_html_e( 'Phone', 'llamahire' ); ?><input type="tel" name="phone" autocomplete="tel"></label>
				<label><?php esc_html_e( 'Resume', 'llamahire' ); ?><input type="file" name="resume" accept=".pdf,.doc,.docx" aria-describedby="llamahire-resume-help"><small id="llamahire-resume-help"><?php esc_html_e( 'PDF, DOC, or DOCX. Maximum 5 MB.', 'llamahire' ); ?></small></label>
				<label><?php esc_html_e( 'Cover letter', 'llamahire' ); ?><textarea name="cover_letter" rows="7"></textarea></label>
				<p class="llamahire-application-privacy" id="llamahire-application-privacy">
					<?php
					$settings    = Settings::get();
					$privacy_url = Settings::privacy_url();
					$privacy_text = $settings['privacy_text'] ?: __( 'Your information will be used by the employer to evaluate your application.', 'llamahire' );
					if ( $privacy_url ) {
						echo esc_html( $privacy_text ) . ' ';
						/* translators: %s: privacy policy URL. */
						echo wp_kses_post( sprintf( __( '<a href="%s">Read our privacy policy.</a>', 'llamahire' ), esc_url( $privacy_url ) ) );
					} else {
						echo esc_html( $privacy_text );
					}
					?>
				</p>
				<button type="submit" aria-describedby="llamahire-application-privacy"><?php esc_html_e( 'Submit application', 'llamahire' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
