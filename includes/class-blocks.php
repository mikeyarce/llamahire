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
				'title'       => __( 'Location Filters', 'llamahire' ),
				'description' => __( 'Show only location and location-type controls.', 'llamahire' ),
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
			$state['department'] = array( $department );
		}
		$meta_query = Jobs::open_meta_query();
		if ( $state['workplace'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_WORKPLACE,
				'value'   => $state['workplace'],
				'compare' => 'IN',
			);
		}
		if ( $state['employment_type'] ) {
			$meta_query[] = array(
				'key'     => Jobs::META_EMPLOYMENT,
				'value'   => $state['employment_type'],
				'compare' => 'IN',
			);
		}
		if ( $state['location'] ) {
			$location_query = array( 'relation' => 'OR' );
			foreach ( $state['location'] as $location ) {
				foreach ( self::location_query_variants( $location ) as $variant ) {
					$location_query[] = array(
						'key'     => Jobs::META_LOCATION,
						'value'   => $variant,
						'compare' => 'LIKE',
					);
				}
			}
			$meta_query[] = $location_query;
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
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Native indexed taxonomy filtering is required for the bounded public jobs query.
				array(
					'taxonomy' => 'llamahire_department',
					'field'    => 'slug',
					'terms'    => $state['department'],
					'operator' => 'IN',
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
		$active_filters = self::active_filter_items( $url_state );
		$feed_state     = $state;
		if ( ! empty( $attributes['featuredOnly'] ) ) {
			$feed_state['featured'] = '1';
		}
		if ( $active_filters ) {
			wp_enqueue_style( 'dashicons' );
		}
		?>
		<div <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-directory', 'data-wp-interactive' => 'llamahire/job-discovery', 'data-wp-router-region' => 'llamahire-job-results', 'data-wp-bind--aria-busy' => 'state.isLoading', 'data-wp-class--is-loading' => 'state.isLoading' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php if ( ! empty( $attributes['showFilters'] ) ) : ?>
			<?php echo self::query_form( $state, true, true, array( 'class' => 'llamahire-filters llamahire-filters--combined', 'showDepartment' => ! $department ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form markup is escaped by query_form(). ?>
			<?php endif; ?>
			<div class="llamahire-results-summary" aria-live="polite" aria-atomic="true">
				<p>
					<?php
					/* translators: %s: number of matching open jobs. */
					echo esc_html( sprintf( _n( '%s open role', '%s open roles', $query->found_posts, 'llamahire' ), number_format_i18n( $query->found_posts ) ) );
					?>
				</p>
				<div class="llamahire-results-summary__actions">
					<a class="llamahire-job-feed-link" href="<?php echo esc_url( Job_Feed::url( $feed_state ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Subscribe to these jobs (RSS)', 'llamahire' ); ?><span class="llamahire-screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'llamahire' ); ?></span></a>
					<?php if ( $active_filters ) : ?><a class="llamahire-clear-filters" href="<?php echo esc_url( self::clear_url() ); ?>" data-wp-on--click="actions.clear"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?>
				</div>
			</div>
			<?php if ( $active_filters ) : ?>
			<div class="llamahire-active-filters" aria-label="<?php esc_attr_e( 'Active job filters', 'llamahire' ); ?>">
				<?php foreach ( $active_filters as $filter ) : ?>
				<?php
				/* translators: %s: active job filter label. */
				$remove_label = sprintf( __( 'Remove %s filter', 'llamahire' ), $filter['label'] );
				?>
				<a class="llamahire-active-filter" href="<?php echo esc_url( $filter['url'] ); ?>" data-llamahire-filter-key="<?php echo esc_attr( $filter['key'] ); ?>" data-wp-on--click="actions.remove" aria-label="<?php echo esc_attr( $remove_label ); ?>"><span><?php echo esc_html( $filter['label'] ); ?></span><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="llamahire-job-grid" id="llamahire-job-results">
			<?php
			if ( $query->have_posts() ) :
				while ( $query->have_posts() ) :
					$query->the_post();
					echo self::render_contextual_job_card( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The block render callback escapes its markup.
			endwhile; else :
				?>
				<div class="llamahire-empty"><h3><?php esc_html_e( 'No matching open roles', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Try a broader search or clear the filters to see every open role.', 'llamahire' ); ?></p><?php if ( $active_filters ) : ?><p><a href="<?php echo esc_url( self::clear_url() ); ?>" data-wp-on--click="actions.clear"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a></p><?php endif; ?></div>
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
			$rows[] = array( __( 'Location type', 'llamahire' ), self::workplace_label( $meta['workplace'] ), 'admin-home', 'workplace' );
		}
		if ( ! isset( $attributes['showEmploymentType'] ) || $attributes['showEmploymentType'] ) {
			$rows[] = array( __( 'Employment', 'llamahire' ), Jobs::employment_label( $meta['employment_type'] ), 'portfolio', 'employment' );
		}
		if ( ! isset( $attributes['showSalary'] ) || $attributes['showSalary'] ) {
			$rows[] = array( __( 'Salary', 'llamahire' ), Jobs::salary_label( $meta ), 'money-alt', 'salary' );
		}
		if ( ! isset( $attributes['showPostedDate'] ) || $attributes['showPostedDate'] ) {
			$rows[] = array( __( 'Posted', 'llamahire' ), get_the_date( '', $job_id ), 'calendar-alt', 'posted' );
		}
		if ( ! isset( $attributes['showDeadline'] ) || $attributes['showDeadline'] ) {
			$rows[] = array( __( 'Apply by', 'llamahire' ), $meta['deadline'] ? wp_date( get_option( 'date_format' ), strtotime( $meta['deadline'] ) ) : '', 'flag', 'deadline' );
			$rows[] = array( __( 'Listing ends', 'llamahire' ), $meta['listing_expires'] ? wp_date( get_option( 'date_format' ), strtotime( $meta['listing_expires'] ) ) : '', 'calendar-alt', 'expiration' );
		}
		if ( ! isset( $attributes['showReference'] ) || $attributes['showReference'] ) {
			$rows[] = array( __( 'Job reference', 'llamahire' ), $meta['job_identifier'], 'tag', 'reference' );
		}
		$rows = array_filter( $rows, static function ( $row ) { return '' !== (string) $row[1]; } );
		if ( ! $rows ) {
			return '';
		}

		wp_enqueue_style( 'llamahire' );
		$last_row_count = count( $rows ) % 4;
		$classes        = 'llamahire-job-facts' . ( ! empty( $attributes['compact'] ) ? ' is-compact' : '' );
		if ( $last_row_count ) {
			$classes .= ' has-last-row-' . $last_row_count;
		}
		ob_start();
		?>
		<dl <?php echo get_block_wrapper_attributes( array( 'class' => $classes ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php foreach ( $rows as $row ) : ?>
				<div class="is-<?php echo esc_attr( $row[3] ); ?>"><dt><?php echo esc_html( $row[0] ); ?></dt><dd><?php echo esc_html( $row[1] ); ?></dd></div>
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
		$employment_types = array_keys( Jobs::employment_types() );
		return array(
			'job_search'      => sanitize_text_field( wp_unslash( $_GET['job_search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only job filters intentionally use shareable query parameters.
			'department'      => self::multi_query_values( $_GET['department'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Public read-only values are sanitized item-by-item in multi_query_values().
			'employment_type' => self::multi_query_values( $_GET['employment_type'] ?? '', $employment_types ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Public read-only values are sanitized and allowlisted in multi_query_values().
			'workplace'       => self::multi_query_values( $_GET['workplace'] ?? '', array( 'remote', 'hybrid', 'onsite' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Public read-only values are sanitized and allowlisted in multi_query_values().
			'location'        => self::multi_location_values( $_GET['location'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Public read-only values are sanitized item-by-item in multi_location_values().
			'featured'        => '1' === sanitize_text_field( wp_unslash( $_GET['featured'] ?? '' ) ) ? '1' : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only job filter.
			'job_page'        => max( 1, absint( $_GET['job_page'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only pagination parameter.
		);
	}

	private static function multi_query_values( $raw_value, array $allowed = array() ) {
		$raw_values = is_array( $raw_value ) ? $raw_value : explode( ',', (string) $raw_value );
		$values     = array();
		foreach ( array_slice( $raw_values, 0, 20 ) as $raw_item ) {
			$value = sanitize_key( wp_unslash( $raw_item ) );
			if ( $value && ( ! $allowed || in_array( $value, $allowed, true ) ) ) {
				$values[] = $value;
			}
		}
		return array_values( array_unique( $values ) );
	}

	private static function multi_location_values( $raw_value ) {
		$raw_values = is_array( $raw_value ) ? $raw_value : explode( '|', (string) $raw_value );
		$values     = array();
		foreach ( array_slice( $raw_values, 0, 20 ) as $raw_item ) {
			$value = sanitize_text_field( wp_unslash( $raw_item ) );
			if ( $value ) {
				$values[ strtolower( $value ) ] = $value;
			}
		}
		return array_values( $values );
	}

	private static function location_query_variants( $location ) {
		$location  = sanitize_text_field( $location );
		$condensed = trim( preg_replace( '/\s+/', ' ', str_replace( ',', ' ', $location ) ) );
		return array_values( array_unique( array_filter( array( $location, $condensed ) ) ) );
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
		if ( $include_filters ) {
			wp_enqueue_style( 'dashicons' );
		}
		ob_start();
		?>
		<form class="<?php echo esc_attr( $class ); ?>" method="get" action="<?php echo esc_url( self::form_action_url() ); ?>" role="search" aria-label="<?php echo esc_attr( $include_search && $include_filters ? __( 'Search and filter jobs', 'llamahire' ) : ( $include_search ? __( 'Search jobs', 'llamahire' ) : __( 'Filter jobs', 'llamahire' ) ) ); ?>" data-llamahire-query-form data-wp-interactive="llamahire/job-discovery" data-wp-on--submit="actions.submit" data-wp-class--is-loading="state.isLoading">
			<?php foreach ( $state as $key => $value ) : if ( 'job_page' !== $key && $value && ! in_array( $key, $controls, true ) ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( is_array( $value ) ? implode( 'location' === $key ? '|' : ',', $value ) : $value ); ?>"><?php endif; endforeach; ?>
			<?php if ( $include_search ) : ?>
			<div class="llamahire-search-row">
				<label class="llamahire-search-field"><span class="llamahire-screen-reader-text"><?php echo esc_html( $search_label ); ?></span><input type="search" name="job_search" value="<?php echo esc_attr( $state['job_search'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-wp-on--input="actions.debounce"></label>
				<button class="llamahire-search-submit" type="submit" data-wp-bind--disabled="state.isLoading"><?php echo esc_html( $include_filters ? __( 'Search', 'llamahire' ) : $button_label ); ?></button>
			</div>
			<?php endif; ?>
			<?php if ( $include_filters ) : ?>
			<div class="llamahire-filter-row">
				<?php if ( $filter_visibility['department'] ) : self::department_control( $state['department'] ); endif; ?>
				<?php if ( $filter_visibility['employment_type'] ) : ?>
				<?php self::multi_filter_control( 'employment_type', __( 'Job type', 'llamahire' ), Jobs::employment_types(), $state['employment_type'] ); ?>
				<?php endif; ?>
				<?php if ( $filter_visibility['workplace'] ) : ?>
				<?php self::multi_filter_control( 'workplace', __( 'Location type', 'llamahire' ), array( 'remote' => __( 'Remote', 'llamahire' ), 'hybrid' => __( 'Hybrid', 'llamahire' ), 'onsite' => __( 'On-site', 'llamahire' ) ), $state['workplace'] ); ?>
				<?php endif; ?>
				<?php if ( $filter_visibility['location'] ) : ?>
				<?php self::location_filter_control( $state['location'] ); ?>
				<?php endif; ?>
				<?php if ( $filter_visibility['featured'] ) : ?>
				<label class="llamahire-checkbox<?php echo $state['featured'] ? ' is-active' : ''; ?>"><input type="checkbox" name="featured" value="1" <?php checked( $state['featured'], '1' ); ?> data-wp-on--change="actions.update"><span><?php esc_html_e( 'Featured', 'llamahire' ); ?></span></label>
				<?php endif; ?>
			</div>
			<button class="llamahire-query-submit" type="submit" data-wp-bind--disabled="state.isLoading"><?php echo esc_html( $button_label ); ?></button>
			<?php endif; ?>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function attribute_text( array $attributes, $key, $fallback ) {
		$value = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ? trim( (string) $attributes[ $key ] ) : '';
		return '' === $value ? $fallback : $value;
	}

	private static function department_control( array $selected ) {
		$terms = get_terms( array( 'taxonomy' => 'llamahire_department', 'hide_empty' => true ) );
		$options = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->slug ] = $term->name;
			}
		}
		$labels = Jobs::department_labels();
		self::multi_filter_control( 'department', $labels['singular'], $options, $selected );
	}

	private static function multi_filter_control( $name, $label, array $options, array $selected ) {
		$summary = $label;
		if ( 1 === count( $selected ) ) {
			$selected_key = reset( $selected );
			$summary      = $options[ $selected_key ] ?? $selected_key;
		} elseif ( count( $selected ) > 1 ) {
			$summary = $label . ' · ' . count( $selected );
		}
		?>
		<details class="llamahire-filter-menu llamahire-filter-menu--<?php echo esc_attr( sanitize_html_class( $name ) ); ?><?php echo $selected ? ' is-active' : ''; ?>">
			<summary aria-label="<?php echo esc_attr( $label ); ?>"><span data-llamahire-filter-summary data-placeholder="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $summary ); ?></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
			<div class="llamahire-filter-menu__panel" role="group" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php foreach ( $options as $value => $option_label ) : ?>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $selected, true ) ); ?> data-wp-on--change="actions.update"><span><?php echo esc_html( $option_label ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	private static function location_filter_control( array $selected ) {
		$options = self::available_location_options();
		foreach ( $selected as $location ) {
			$options[ strtolower( $location ) ] = $location;
		}
		natcasesort( $options );
		$summary = __( 'Location', 'llamahire' );
		if ( 1 === count( $selected ) ) {
			$summary = reset( $selected );
		} elseif ( count( $selected ) > 1 ) {
			/* translators: %d: number of selected locations. */
			$summary = sprintf( __( 'Location · %d', 'llamahire' ), count( $selected ) );
		}
		$list_id = wp_unique_id( 'llamahire-location-options-' );
		?>
		<details class="llamahire-filter-menu llamahire-filter-menu--location<?php echo $selected ? ' is-active' : ''; ?>" data-llamahire-location-menu>
			<summary aria-label="<?php esc_attr_e( 'Location', 'llamahire' ); ?>"><span data-llamahire-filter-summary data-placeholder="<?php esc_attr_e( 'Location', 'llamahire' ); ?>"><?php echo esc_html( $summary ); ?></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
			<div class="llamahire-filter-menu__panel llamahire-location-menu__panel" role="group" aria-label="<?php esc_attr_e( 'Location', 'llamahire' ); ?>">
				<label class="llamahire-location-search"><span class="llamahire-screen-reader-text"><?php esc_html_e( 'Search available locations', 'llamahire' ); ?></span><input type="search" placeholder="<?php esc_attr_e( 'Search locations', 'llamahire' ); ?>" autocomplete="off" aria-controls="<?php echo esc_attr( $list_id ); ?>" data-llamahire-location-search></label>
				<div id="<?php echo esc_attr( $list_id ); ?>" class="llamahire-location-options" data-llamahire-location-options>
					<?php foreach ( $options as $option_label ) : ?>
					<label data-llamahire-location-option><input type="checkbox" name="location[]" value="<?php echo esc_attr( $option_label ); ?>" <?php checked( in_array( $option_label, $selected, true ) ); ?> data-wp-on--change="actions.update"><span><?php echo esc_html( $option_label ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<p class="llamahire-location-empty" data-llamahire-location-empty<?php echo $options ? ' hidden' : ''; ?>><?php esc_html_e( 'No available locations match your search.', 'llamahire' ); ?></p>
				<p class="llamahire-screen-reader-text" aria-live="polite" data-llamahire-location-status data-singular="<?php esc_attr_e( 'location', 'llamahire' ); ?>" data-plural="<?php esc_attr_e( 'locations', 'llamahire' ); ?>"></p>
			</div>
		</details>
		<?php
	}

	private static function available_location_options() {
		static $options = null;
		if ( null !== $options ) {
			return $options;
		}
		$query = new \WP_Query(
			array(
				'post_type'              => Jobs::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'meta_query'             => Jobs::open_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$options = array();
		foreach ( $query->posts as $job_id ) {
			$meta = Jobs::get_meta( $job_id );
			if ( 'remote' === $meta['workplace'] ) {
				continue;
			}
			$label = Jobs::location_label( $meta );
			if ( $label ) {
				$options[ strtolower( $label ) ] = $label;
			}
		}
		return $options;
	}

	private static function active_filter_items( array $state ) {
		$labels = array();
		if ( $state['job_search'] ) {
			$labels[] = array( 'key' => 'job_search', 'value' => $state['job_search'], 'label' => $state['job_search'] );
		}
		foreach ( $state['department'] as $department_slug ) {
			$department = get_term_by( 'slug', $department_slug, 'llamahire_department' );
			$labels[] = array( 'key' => 'department', 'value' => $department_slug, 'label' => $department instanceof \WP_Term ? $department->name : $department_slug );
		}
		$employment_types = Jobs::employment_types();
		foreach ( $state['employment_type'] as $employment_type ) {
			$labels[] = array( 'key' => 'employment_type', 'value' => $employment_type, 'label' => $employment_types[ $employment_type ] ?? Jobs::employment_label( $employment_type ) );
		}
		foreach ( $state['workplace'] as $workplace ) {
			$labels[] = array( 'key' => 'workplace', 'value' => $workplace, 'label' => self::workplace_label( $workplace ) );
		}
		foreach ( $state['location'] as $location ) {
			$labels[] = array( 'key' => 'location', 'value' => $location, 'label' => $location );
		}
		if ( $state['featured'] ) {
			$labels[] = array( 'key' => 'featured', 'value' => '1', 'label' => __( 'Featured', 'llamahire' ) );
		}

		$items = array();
		foreach ( $labels as $filter ) {
			$items[] = array(
				'key'   => $filter['key'],
				'label' => $filter['label'],
				'url'   => self::remove_filter_url( $filter['key'], $filter['value'], $state ),
			);
		}
		return $items;
	}

	private static function remove_filter_url( $key, $value, array $state ) {
		if ( in_array( $key, array( 'department', 'employment_type', 'workplace', 'location' ), true ) ) {
			$remaining = array_values( array_diff( $state[ $key ], array( $value ) ) );
			$url       = remove_query_arg( array( $key, $key . '[]', 'job_page' ) );
			return $remaining ? add_query_arg( $key, implode( 'location' === $key ? '|' : ',', $remaining ), $url ) : $url;
		}
		return remove_query_arg( array( $key, $key . '[]', 'job_page' ) );
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
		return remove_query_arg( array_merge( self::QUERY_KEYS, array( 'department[]', 'employment_type[]', 'workplace[]', 'location[]' ) ) );
	}

	private static function clear_url() {
		return self::form_action_url();
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
		$result   = sanitize_key( wp_unslash( $_GET['application'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only result notice after a submission redirect.
		$fields   = Settings::application_fields();
		$messages = array(
			'success'        => __( 'Thanks! Your application has been received.', 'llamahire' ),
			'duplicate'      => __( 'Thanks! Your application has been received.', 'llamahire' ),
			'required'       => __( 'Please complete all required fields and provide a valid email address.', 'llamahire' ),
			'invalid_phone'  => __( 'Not a valid phone number.', 'llamahire' ),
			'invalid_fields' => __( 'One or more fields are too long. Shorten your response and try again.', 'llamahire' ),
			'resume_size'    => __( 'Your resume must be smaller than 5 MB.', 'llamahire' ),
			'resume_type'    => __( 'Please upload a PDF or DOCX resume.', 'llamahire' ),
			'resume_storage' => __( 'Resume uploads are temporarily unavailable. Please contact the employer.', 'llamahire' ),
				'rate_limited'   => __( 'Too many applications were submitted recently. Please wait and try again.', 'llamahire' ),
				'anti_spam'      => __( 'Complete the spam protection check and try again.', 'llamahire' ),
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
				<?php if ( 'hidden' !== $fields['resume'] ) : ?><label class="llamahire-file-field"><span><?php esc_html_e( 'Resume', 'llamahire' ); ?><?php if ( 'required' === $fields['resume'] ) : ?> <span class="llamahire-required"><?php esc_html_e( '(required)', 'llamahire' ); ?></span><?php endif; ?></span><span class="llamahire-file-control"><span class="llamahire-file-icon dashicons dashicons-upload" aria-hidden="true"></span><span class="llamahire-file-prompt" data-resume-prompt><?php esc_html_e( 'Drag and drop a file here or', 'llamahire' ); ?> <span><?php esc_html_e( 'choose a file', 'llamahire' ); ?></span></span><input type="file" name="resume" accept=".pdf,.docx" aria-describedby="llamahire-resume-help"<?php echo 'required' === $fields['resume'] ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The output is a fixed attribute selected from an allowlisted mode. ?>></span><small id="llamahire-resume-help"><?php esc_html_e( 'PDF or DOCX. Maximum 5 MB.', 'llamahire' ); ?></small></label><?php endif; ?>
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
					<?php Anti_Spam::render( Anti_Spam::CONTEXT_APPLICATION ); ?>
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
