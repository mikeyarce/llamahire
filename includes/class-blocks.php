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
		register_block_type( LLAMAHIRE_PATH . 'blocks/job-card', array( 'render_callback' => array( __CLASS__, 'render_job_card' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/featured-jobs', array( 'render_callback' => array( __CLASS__, 'render_featured_jobs' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/single-job-details', array( 'render_callback' => array( __CLASS__, 'render_job_details' ) ) );
		register_block_type( LLAMAHIRE_PATH . 'blocks/application-form', array( 'render_callback' => array( __CLASS__, 'render_form' ) ) );
		self::register_patterns();
		add_filter( 'get_block_type_variations', array( __CLASS__, 'block_variations' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'single_job_content' ) );
	}

	private static function register_patterns() {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}
		$category_registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		if ( ! $category_registry->is_registered( 'llamahire' ) ) {
			register_block_pattern_category(
				'llamahire',
				array(
					'label'       => __( 'LlamaHire', 'llamahire' ),
					'description' => __( 'Careers and hiring layouts from LlamaHire.', 'llamahire' ),
				)
			);
		}
		$patterns = array(
			'careers-page' => array(
				'title'         => __( 'Careers page', 'llamahire' ),
				'description'   => __( 'A complete careers page with a welcoming introduction and searchable job directory.', 'llamahire' ),
				'categories'    => array( 'llamahire', 'featured' ),
				'keywords'      => array( __( 'careers', 'llamahire' ), __( 'jobs', 'llamahire' ) ),
				'viewportWidth' => 1440,
				'postTypes'     => array( 'page' ),
			),
			'careers-hero' => array(
				'title'         => __( 'Careers hero', 'llamahire' ),
				'description'   => __( 'A welcoming careers introduction with a link to open roles.', 'llamahire' ),
				'categories'    => array( 'llamahire', 'banner' ),
				'keywords'      => array( __( 'careers', 'llamahire' ), __( 'hero', 'llamahire' ) ),
				'viewportWidth' => 1440,
				'postTypes'     => array( 'page' ),
			),
			'featured-jobs' => array(
				'title'         => __( 'Featured jobs section', 'llamahire' ),
				'description'   => __( 'A heading, introduction, and grid of featured open roles.', 'llamahire' ),
				'categories'    => array( 'llamahire', 'posts' ),
				'keywords'      => array( __( 'featured jobs', 'llamahire' ), __( 'open roles', 'llamahire' ) ),
				'viewportWidth' => 1200,
				'postTypes'     => array( 'page' ),
			),
			'department-landing-page' => array(
				'title'         => __( 'Department landing page', 'llamahire' ),
				'description'   => __( 'A team introduction and job directory ready to be limited to a selected department.', 'llamahire' ),
				'categories'    => array( 'llamahire', 'featured' ),
				'keywords'      => array( __( 'department', 'llamahire' ), __( 'team jobs', 'llamahire' ) ),
				'viewportWidth' => 1440,
				'postTypes'     => array( 'page' ),
			),
		);
		$registry = \WP_Block_Patterns_Registry::get_instance();
		foreach ( $patterns as $slug => $properties ) {
			$name = 'llamahire/' . $slug;
			if ( $registry->is_registered( $name ) ) {
				continue;
			}
			$properties['content'] = self::pattern_content( $slug . '.php' );
			register_block_pattern( $name, $properties );
		}
	}

	private static function pattern_content( $filename ) {
		ob_start();
		include LLAMAHIRE_PATH . 'patterns/' . $filename;
		return trim( ob_get_clean() );
	}

	public static function block_variations( $variations, $block_type ) {
		$defined = array(
			'llamahire/jobs-directory' => array(
				'name'        => 'llamahire-results-only',
				'title'       => __( 'Jobs List', 'llamahire' ),
				'description' => __( 'Display job results without built-in search or filters.', 'llamahire' ),
				'icon'        => 'list-view',
				'attributes'  => array( 'showFilters' => false ),
				'isActive'    => array( 'showFilters' ),
			),
			'llamahire/job-filters' => array(
				'name'        => 'llamahire-location-work-style',
				'title'       => __( 'Location & Work Style Filters', 'llamahire' ),
				'description' => __( 'Show only location and workplace controls.', 'llamahire' ),
				'icon'        => 'location-alt',
				'attributes'  => array( 'showDepartment' => false, 'showEmploymentType' => false, 'showWorkplace' => true, 'showLocation' => true, 'showFeatured' => false ),
				'isActive'    => array( 'showDepartment', 'showEmploymentType', 'showWorkplace', 'showLocation', 'showFeatured' ),
			),
			'llamahire/featured-jobs' => array(
				'name'        => 'llamahire-compact-featured-jobs',
				'title'       => __( 'Compact Featured Jobs', 'llamahire' ),
				'description' => __( 'Display concise featured cards without excerpts or badges.', 'llamahire' ),
				'icon'        => 'star-filled',
				'attributes'  => array( 'showExcerpt' => false, 'showFeaturedBadge' => false, 'showSalary' => false ),
				'isActive'    => array( 'showExcerpt', 'showFeaturedBadge', 'showSalary' ),
			),
			'llamahire/single-job-details' => array(
				'name'        => 'llamahire-essential-job-details',
				'title'       => __( 'Essential Job Details', 'llamahire' ),
				'description' => __( 'Show the primary role, location, salary, and deadline facts.', 'llamahire' ),
				'icon'        => 'editor-ul',
				'attributes'  => array( 'showOrganization' => false, 'showPostedDate' => false, 'showReference' => false ),
				'isActive'    => array( 'showOrganization', 'showPostedDate', 'showReference' ),
			),
		);
		if ( isset( $defined[ $block_type->name ] ) ) {
			$variation          = $defined[ $block_type->name ];
			$variation['scope'] = array( 'inserter', 'transform' );
			$variations[]       = $variation;
		}
		return $variations;
	}

	public static function single_job_content( $content ) {
		if ( is_admin() || ! is_singular( Jobs::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$job_id  = get_the_ID();
		$details = has_block( 'llamahire/single-job-details', $job_id ) ? '' : self::render_contextual_job_details(
			$job_id,
			array(
				'compact'         => true,
				'showFullAddress' => false,
				'showReference'   => false,
			)
		);
		if ( has_block( 'llamahire/application-form', $job_id ) ) {
			return '<div class="llamahire-single-main">' . $details . $content . '</div>';
		}
		$application = self::render_dynamic_block(
			'llamahire/application-form',
			array(
				'jobId'   => $job_id,
				'heading' => __( 'Apply for this role', 'llamahire' ),
			)
		);
		return '<div class="llamahire-single-layout"><div class="llamahire-single-main">' . $details . $content . '</div><aside class="llamahire-single-apply" aria-label="' . esc_attr__( 'Apply for this role', 'llamahire' ) . '">' . $application . '</aside></div>';
	}

	public static function render_directory( $attributes ) {
		$url_state  = self::query_state();
		$state      = $url_state;
		$department = sanitize_title( $attributes['department'] ?? '' );
		if ( ! $department && is_tax( 'llamahire_department' ) ) {
			$term = get_queried_object();
			$department = $term instanceof \WP_Term ? $term->slug : '';
		}
		if ( $department ) {
			$state['department'] = $department;
		}
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
			<?php echo self::query_form( $state, true, true, array( 'class' => 'llamahire-filters llamahire-filters--combined', 'showDepartment' => ! $department ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form markup is escaped by query_form(). ?>
			<?php endif; ?>
			<div class="llamahire-results-summary" aria-live="polite">
				<p>
					<?php
					/* translators: %s: number of matching open jobs. */
					echo esc_html( sprintf( _n( '%s open role', '%s open roles', $query->found_posts, 'llamahire' ), number_format_i18n( $query->found_posts ) ) );
					?>
				</p>
				<?php if ( self::has_active_filters( $url_state ) ) : ?><a class="llamahire-clear-filters" href="<?php echo esc_url( self::clear_url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?>
			</div>
			<div class="llamahire-job-grid" id="llamahire-job-results">
			<?php
			if ( $query->have_posts() ) :
				while ( $query->have_posts() ) :
					$query->the_post();
					echo self::render_contextual_job_card( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The block render callback escapes its markup.
			endwhile; else :
				?>
				<div class="llamahire-empty"><h3><?php esc_html_e( 'No matching open roles', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Try a broader search or clear the filters to see every open role.', 'llamahire' ); ?></p><?php if ( self::has_active_filters( $url_state ) ) : ?><p><a href="<?php echo esc_url( self::clear_url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a></p><?php endif; ?></div>
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

	public static function render_job_card( $attributes, $content = '', $block = null ) {
		$job_id = self::context_job_id( $block );
		if ( ! $job_id || Jobs::POST_TYPE !== get_post_type( $job_id ) || 'publish' !== get_post_status( $job_id ) || ! Jobs::is_open( $job_id ) ) {
			return '';
		}

		wp_enqueue_style( 'llamahire' );
		$meta          = Jobs::get_meta( $job_id );
		$permalink     = get_permalink( $job_id );
		$heading_level = min( 6, max( 2, absint( $attributes['headingLevel'] ?? 3 ) ) );
		$meta_items    = array();
		if ( ! isset( $attributes['showLocation'] ) || $attributes['showLocation'] ) {
			$meta_items[] = Jobs::location_label( $meta );
		}
		if ( ! isset( $attributes['showWorkplace'] ) || $attributes['showWorkplace'] ) {
			$meta_items[] = self::workplace_label( $meta['workplace'] );
		}
		if ( ! isset( $attributes['showEmploymentType'] ) || $attributes['showEmploymentType'] ) {
			$meta_items[] = Jobs::employment_label( $meta['employment_type'] );
		}
		$meta_items = array_filter( $meta_items );
		$link_label = self::attribute_text( $attributes, 'linkLabel', __( 'View role', 'llamahire' ) );

		ob_start();
		?>
		<article <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-job-card' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php if ( ( ! isset( $attributes['showFeaturedBadge'] ) || $attributes['showFeaturedBadge'] ) && '1' === $meta['featured'] ) : ?>
				<span class="llamahire-badge"><?php esc_html_e( 'Featured', 'llamahire' ); ?></span>
			<?php endif; ?>
			<?php echo '<h' . (int) $heading_level . '><a href="' . esc_url( $permalink ) . '">' . esc_html( get_the_title( $job_id ) ) . '</a></h' . (int) $heading_level . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped. ?>
			<?php if ( $meta_items ) : ?>
				<div class="llamahire-job-meta"><?php foreach ( $meta_items as $item ) : ?><span><?php echo esc_html( $item ); ?></span><?php endforeach; ?></div>
			<?php endif; ?>
			<?php if ( ( ! isset( $attributes['showExcerpt'] ) || $attributes['showExcerpt'] ) && has_excerpt( $job_id ) ) : ?>
				<p><?php echo esc_html( get_the_excerpt( $job_id ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $attributes['showSalary'] ) && Jobs::salary_label( $meta ) ) : ?>
				<p class="llamahire-card-salary"><?php echo esc_html( Jobs::salary_label( $meta ) ); ?></p>
			<?php endif; ?>
			<a class="llamahire-card-link" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $link_label ); ?> <span aria-hidden="true">→</span></a>
		</article>
		<?php
		return ob_get_clean();
	}

	public static function render_job_details( $attributes, $content = '', $block = null ) {
		$job_id = self::context_job_id( $block );
		if ( ! $job_id || Jobs::POST_TYPE !== get_post_type( $job_id ) ) {
			return '';
		}

		$meta         = Jobs::get_meta( $job_id );
		$organization = Jobs::organization( $meta );
		$rows         = array();
		if ( ! isset( $attributes['showOrganization'] ) || $attributes['showOrganization'] ) {
			$rows[] = array( __( 'Company', 'llamahire' ), $organization['name'], 'building', 'organization' );
		}
		if ( ! isset( $attributes['showLocation'] ) || $attributes['showLocation'] ) {
			$location = isset( $attributes['showFullAddress'] ) && ! $attributes['showFullAddress'] ? Jobs::location_label( $meta ) : Jobs::full_location_label( $meta );
			$rows[] = array( __( 'Location', 'llamahire' ), $location, 'location', 'location' );
		}
		if ( ! isset( $attributes['showWorkplace'] ) || $attributes['showWorkplace'] ) {
			$rows[] = array( __( 'Workplace', 'llamahire' ), self::workplace_label( $meta['workplace'] ), 'admin-home', 'workplace' );
		}
		if ( ! isset( $attributes['showEmploymentType'] ) || $attributes['showEmploymentType'] ) {
			$rows[] = array( __( 'Employment type', 'llamahire' ), Jobs::employment_label( $meta['employment_type'] ), 'portfolio', 'employment' );
		}
		if ( ! isset( $attributes['showSalary'] ) || $attributes['showSalary'] ) {
			$rows[] = array( __( 'Salary', 'llamahire' ), Jobs::salary_label( $meta ), 'money-alt', 'salary' );
		}
		if ( ! isset( $attributes['showPostedDate'] ) || $attributes['showPostedDate'] ) {
			$rows[] = array( __( 'Posted', 'llamahire' ), get_the_date( '', $job_id ), 'calendar-alt', 'posted' );
		}
		if ( ! isset( $attributes['showDeadline'] ) || $attributes['showDeadline'] ) {
			$rows[] = array( __( 'Apply by', 'llamahire' ), $meta['deadline'] ? wp_date( get_option( 'date_format' ), strtotime( $meta['deadline'] ) ) : '', 'flag', 'deadline' );
		}
		if ( ! isset( $attributes['showReference'] ) || $attributes['showReference'] ) {
			$rows[] = array( __( 'Job reference', 'llamahire' ), $meta['job_identifier'], 'tag', 'reference' );
		}
		$rows = array_filter( $rows, static function ( $row ) { return '' !== (string) $row[1]; } );
		if ( ! $rows ) {
			return '';
		}

		wp_enqueue_style( 'llamahire' );
		wp_enqueue_style( 'dashicons' );
		$last_row_count = count( $rows ) % 4;
		$classes        = 'llamahire-job-facts' . ( ! empty( $attributes['compact'] ) ? ' is-compact' : '' );
		if ( $last_row_count ) {
			$classes .= ' has-last-row-' . $last_row_count;
		}
		ob_start();
		?>
		<dl <?php echo get_block_wrapper_attributes( array( 'class' => $classes ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php foreach ( $rows as $row ) : ?>
				<div class="is-<?php echo esc_attr( $row[3] ); ?>"><span class="llamahire-job-fact-icon dashicons dashicons-<?php echo esc_attr( $row[2] ); ?>" aria-hidden="true"></span><dt><?php echo esc_html( $row[0] ); ?></dt><dd><?php echo esc_html( $row[1] ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php
		return ob_get_clean();
	}

	public static function render_featured_jobs( $attributes ) {
		$meta_query   = Jobs::open_meta_query();
		$meta_query[] = array(
			'key'     => Jobs::META_FEATURED,
			'value'   => '1',
			'compare' => '=',
		);
		$query = new \WP_Query(
			array(
				'post_type'           => Jobs::POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => min( 12, max( 1, absint( $attributes['perPage'] ?? 3 ) ) ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$card_attributes = array_intersect_key(
			$attributes,
			array_fill_keys( array( 'showExcerpt', 'showFeaturedBadge', 'showLocation', 'showWorkplace', 'showEmploymentType', 'showSalary', 'linkLabel', 'headingLevel' ), true )
		);
		wp_enqueue_style( 'llamahire' );
		ob_start();
		?>
		<section <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-featured-jobs' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php if ( ! isset( $attributes['showHeading'] ) || $attributes['showHeading'] ) : ?>
				<h2 class="llamahire-featured-jobs__heading"><?php echo esc_html( self::attribute_text( $attributes, 'heading', __( 'Featured jobs', 'llamahire' ) ) ); ?></h2>
			<?php endif; ?>
			<div class="llamahire-job-grid">
				<?php if ( $query->have_posts() ) : ?>
					<?php while ( $query->have_posts() ) : $query->the_post(); ?>
						<?php echo self::render_contextual_job_card( get_the_ID(), $card_attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The block render callback escapes its markup. ?>
					<?php endwhile; ?>
				<?php else : ?>
					<div class="llamahire-empty"><h3><?php esc_html_e( 'No featured roles yet', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Mark an open job as featured and it will appear here.', 'llamahire' ); ?></p></div>
				<?php endif; ?>
			</div>
		</section>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	private static function render_contextual_job_card( $job_id, array $attributes = array() ) {
		return self::render_dynamic_block( 'llamahire/job-card', $attributes, array( 'llamahire/jobId' => absint( $job_id ) ) );
	}

	private static function render_contextual_job_details( $job_id, array $attributes = array() ) {
		return self::render_dynamic_block( 'llamahire/single-job-details', $attributes, array( 'llamahire/jobId' => absint( $job_id ) ) );
	}

	private static function render_dynamic_block( $block_name, array $attributes = array(), array $context = array() ) {
		$block = new \WP_Block(
			array(
				'blockName'    => $block_name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$context
		);
		return $block->render();
	}

	private static function context_job_id( $block ) {
		if ( $block instanceof \WP_Block && ! empty( $block->context['llamahire/jobId'] ) ) {
			return absint( $block->context['llamahire/jobId'] );
		}
		return is_singular( Jobs::POST_TYPE ) ? get_queried_object_id() : 0;
	}

	private static function workplace_label( $workplace ) {
		$labels = array(
			'remote' => __( 'Remote', 'llamahire' ),
			'hybrid' => __( 'Hybrid', 'llamahire' ),
			'onsite' => __( 'On-site', 'llamahire' ),
		);
		return $labels[ $workplace ] ?? '';
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
		$job_meta = Jobs::get_meta( $job_id );
		if ( 'external_url' === $job_meta['application_method'] && $job_meta['application_target'] ) {
			wp_enqueue_style( 'llamahire' );
			return '<div class="llamahire-application"><h2>' . esc_html( $attributes['heading'] ?? __( 'Apply for this role', 'llamahire' ) ) . '</h2><p><a class="llamahire-button" href="' . esc_url( $job_meta['application_target'] ) . '" rel="nofollow external">' . esc_html__( 'Apply on the employer website', 'llamahire' ) . '</a></p></div>';
		}
		if ( 'external_email' === $job_meta['application_method'] && is_email( $job_meta['application_target'] ) ) {
			wp_enqueue_style( 'llamahire' );
			return '<div class="llamahire-application"><h2>' . esc_html( $attributes['heading'] ?? __( 'Apply for this role', 'llamahire' ) ) . '</h2><p><a class="llamahire-button" href="mailto:' . esc_attr( $job_meta['application_target'] ) . '?subject=' . rawurlencode( get_the_title( $job_id ) ) . '">' . esc_html__( 'Apply by email', 'llamahire' ) . '</a></p></div>';
		}
		$organization = Jobs::organization( $job_meta );
		$result   = sanitize_key( wp_unslash( $_GET['application'] ?? '' ) );
		$fields   = Settings::application_fields();
		$messages = array(
			'success'        => __( 'Thanks! Your application has been received.', 'llamahire' ),
			'duplicate'      => __( 'We already have your application for this role. No further action is needed.', 'llamahire' ),
			'required'       => __( 'Please complete all required fields and provide a valid email address.', 'llamahire' ),
			'invalid_phone'  => __( 'Not a valid phone number.', 'llamahire' ),
			'invalid_fields' => __( 'One or more fields are too long. Shorten your response and try again.', 'llamahire' ),
			'resume_size'    => __( 'Your resume must be smaller than 5 MB.', 'llamahire' ),
			'resume_type'    => __( 'Please upload a PDF, DOC, or DOCX resume.', 'llamahire' ),
			'resume_storage' => __( 'Resume uploads are temporarily unavailable. Please contact the employer.', 'llamahire' ),
			'rate_limited'   => __( 'Too many applications were submitted recently. Please wait and try again.', 'llamahire' ),
			'error'          => __( 'We could not save your application. Please try again.', 'llamahire' ),
			'invalid'        => __( 'This application link is no longer valid.', 'llamahire' ),
		);
		wp_enqueue_style( 'llamahire' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'llamahire-application-form' );
		ob_start();
		?>
		<div <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-application' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> id="llamahire-application" data-llamahire-application="<?php echo esc_attr( $job_id ); ?>" data-application-result="<?php echo esc_attr( $result ); ?>">
			<h2><?php echo esc_html( $attributes['heading'] ?? __( 'Apply for this role', 'llamahire' ) ); ?></h2>
			<?php if ( Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() && $organization['name'] ) : ?><p class="llamahire-application-recipient"><?php echo esc_html( sprintf( __( 'Your application will be shared with %s for hiring review.', 'llamahire' ), $organization['name'] ) ); ?></p><?php endif; ?>
			<?php
			if ( isset( $messages[ $result ] ) ) :
				?>
				<?php $accepted = in_array( $result, array( 'success', 'duplicate' ), true ); ?>
				<div class="llamahire-notice <?php echo $accepted ? 'is-success' : 'is-error'; ?>" role="<?php echo $accepted ? 'status' : 'alert'; ?>" tabindex="-1"><?php echo esc_html( $messages[ $result ] ); ?></div><?php endif; ?>
			<?php if ( ! in_array( $result, array( 'success', 'duplicate' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-llamahire-application-form>
				<input type="hidden" name="action" value="llamahire_apply"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>"><input type="hidden" name="submission_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'llamahire_apply_' . $job_id, 'llamahire_nonce' ); ?>
				<div class="llamahire-honeypot" aria-hidden="true"><label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
				<label><span><?php esc_html_e( 'Name', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></span><input type="text" name="name" required maxlength="<?php echo esc_attr( Applications::MAX_NAME_LENGTH ); ?>" autocomplete="name" placeholder="<?php esc_attr_e( 'Enter your full name', 'llamahire' ); ?>"></label>
				<label><span><?php esc_html_e( 'Email', 'llamahire' ); ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span></span><input type="email" name="email" required maxlength="<?php echo esc_attr( Applications::MAX_EMAIL_LENGTH ); ?>" autocomplete="email" placeholder="<?php esc_attr_e( 'Enter your email address', 'llamahire' ); ?>"></label>
				<?php if ( 'hidden' !== $fields['phone'] ) : ?><label><span><?php esc_html_e( 'Phone', 'llamahire' ); ?><?php if ( 'required' === $fields['phone'] ) : ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span><?php endif; ?></span><input type="tel" name="phone" inputmode="tel" maxlength="<?php echo esc_attr( Applications::MAX_PHONE_LENGTH ); ?>" autocomplete="tel" placeholder="<?php esc_attr_e( 'Enter your phone number', 'llamahire' ); ?>"<?php echo 'required' === $fields['phone'] ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The output is a fixed attribute selected from an allowlisted mode. ?>></label><?php endif; ?>
				<?php if ( 'hidden' !== $fields['resume'] ) : ?><label class="llamahire-file-field"><span><?php esc_html_e( 'Resume', 'llamahire' ); ?><?php if ( 'required' === $fields['resume'] ) : ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span><?php endif; ?></span><span class="llamahire-file-control"><span class="llamahire-file-icon dashicons dashicons-upload" aria-hidden="true"></span><span class="llamahire-file-prompt" data-resume-prompt><?php esc_html_e( 'Drag and drop a file here or', 'llamahire' ); ?> <span><?php esc_html_e( 'choose a file', 'llamahire' ); ?></span></span><input type="file" name="resume" accept=".pdf,.doc,.docx" aria-describedby="llamahire-resume-help"<?php echo 'required' === $fields['resume'] ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The output is a fixed attribute selected from an allowlisted mode. ?>></span><small id="llamahire-resume-help"><?php esc_html_e( 'PDF, DOC, or DOCX. Maximum 5 MB.', 'llamahire' ); ?></small></label><?php endif; ?>
				<?php if ( 'hidden' !== $fields['cover_letter'] ) : ?><label><span><?php esc_html_e( 'Cover letter', 'llamahire' ); ?><?php if ( 'required' === $fields['cover_letter'] ) : ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span><?php endif; ?></span><textarea name="cover_letter" rows="6" maxlength="<?php echo esc_attr( Applications::MAX_COVER_LETTER_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Tell us why you are a great fit', 'llamahire' ); ?>"<?php echo 'required' === $fields['cover_letter'] ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The output is a fixed attribute selected from an allowlisted mode. ?>></textarea></label><?php endif; ?>
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
					$retention_days = Settings::retention_days();
					if ( $retention_days ) {
						/* translators: %s: configured number of retention days. */
						echo ' ' . esc_html( sprintf( __( 'Application records are scheduled for deletion from this site after %s days.', 'llamahire' ), number_format_i18n( $retention_days ) ) );
					}
					?>
				</p>
				<div class="llamahire-upload-feedback" data-upload-feedback hidden>
					<p data-upload-status role="status" tabindex="-1"
						data-uploading="<?php esc_attr_e( 'Uploading resume…', 'llamahire' ); ?>"
						data-submitting="<?php esc_attr_e( 'Submitting application…', 'llamahire' ); ?>"
						data-processing="<?php esc_attr_e( 'Upload complete. Processing application…', 'llamahire' ); ?>"
						data-error="<?php esc_attr_e( 'We could not submit your application. Check your connection and try again.', 'llamahire' ); ?>"></p>
					<div class="llamahire-upload-progress" data-upload-progress-wrapper hidden>
						<progress data-upload-progress value="0" max="100" aria-label="<?php esc_attr_e( 'Resume upload progress', 'llamahire' ); ?>">0%</progress>
						<span data-upload-percent aria-hidden="true">0%</span>
					</div>
				</div>
				<button type="submit" aria-describedby="llamahire-application-privacy"><?php esc_html_e( 'Submit application', 'llamahire' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
