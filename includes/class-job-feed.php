<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Filter-preserving RSS feed for currently open jobs.
 */
final class Job_Feed {
	const FEED            = 'llamahire-jobs';
	const FILTER_KEYS     = array( 'job_search', 'department', 'employment_type', 'workplace', 'location', 'featured' );

	public static function register() {
		add_feed( self::FEED, array( __CLASS__, 'render' ) );
		add_action( 'wp_head', array( __CLASS__, 'discovery_link' ) );
	}

	public static function state() {
		return array_intersect_key( Blocks::query_state(), array_fill_keys( self::FILTER_KEYS, true ) );
	}

	public static function url( ?array $state = null ) {
		$state = null === $state ? self::state() : array_intersect_key( $state, array_fill_keys( self::FILTER_KEYS, true ) );
		$args  = array();
		foreach ( self::FILTER_KEYS as $key ) {
			$value = $state[ $key ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( 'location' === $key ? '|' : ',', array_filter( $value ) );
			}
			if ( '' !== (string) $value ) {
				$args[ $key ] = $value;
			}
		}
		return add_query_arg( $args, get_feed_link( self::FEED ) );
	}

	public static function query_args( array $state ) {
		$meta_query = Jobs::open_meta_query();
		if ( ! empty( $state['workplace'] ) ) {
			$meta_query[] = array( 'key' => Jobs::META_WORKPLACE, 'value' => (array) $state['workplace'], 'compare' => 'IN' );
		}
		if ( ! empty( $state['employment_type'] ) ) {
			$meta_query[] = array( 'key' => Jobs::META_EMPLOYMENT, 'value' => (array) $state['employment_type'], 'compare' => 'IN' );
		}
		if ( ! empty( $state['location'] ) ) {
			$location_query = array( 'relation' => 'OR' );
			foreach ( (array) $state['location'] as $location ) {
				$location  = sanitize_text_field( $location );
				$condensed = trim( preg_replace( '/\s+/', ' ', str_replace( ',', ' ', $location ) ) );
				foreach ( array_unique( array_filter( array( $location, $condensed ) ) ) as $variant ) {
					$location_query[] = array( 'key' => Jobs::META_LOCATION, 'value' => $variant, 'compare' => 'LIKE' );
				}
			}
			$meta_query[] = $location_query;
		}
		if ( ! empty( $state['featured'] ) ) {
			$meta_query[] = array( 'key' => Jobs::META_FEATURED, 'value' => '1', 'compare' => '=' );
		}
		$args = array(
			'post_type'           => Jobs::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => 50,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
			's'                   => sanitize_text_field( $state['job_search'] ?? '' ),
			'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( ! empty( $state['department'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => Jobs::DEPARTMENT_TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_key', (array) $state['department'] ),
					'operator' => 'IN',
				),
			);
		}
		return $args;
	}

	public static function discovery_link() {
		if ( is_feed() ) {
			return;
		}
		/* translators: %s: WordPress site name. */
		$title = sprintf( __( '%s open jobs', 'llamahire' ), get_bloginfo( 'name' ) );
		echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr( $title ) . '" href="' . esc_url( self::url() ) . '">' . "\n";
	}

	public static function render() {
		$state      = self::state();
		$query      = new \WP_Query( self::query_args( $state ) );
		$modified   = array_map(
			static function ( $job ) {
				return (int) get_post_modified_time( 'U', true, $job );
			},
			$query->posts
		);
		$last_build = $modified ? gmdate( 'r', max( $modified ) ) : gmdate( 'r', strtotime( get_lastpostmodified( 'GMT' ) ) );
		$charset = get_option( 'blog_charset' );
		header( 'Content-Type: ' . feed_content_type( 'rss-http' ) . '; charset=' . $charset, true );
		echo '<?xml version="1.0" encoding="' . esc_attr( $charset ) . '"?>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML declaration uses the saved WordPress charset.
		?>
		<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
		<channel>
			<title><?php echo esc_html( sprintf( __( '%s open jobs', 'llamahire' ), get_bloginfo( 'name' ) ) ); ?></title>
			<atom:link href="<?php echo esc_url( self::url( $state ) ); ?>" rel="self" type="application/rss+xml" />
			<link><?php echo esc_url( self::directory_url( $state ) ); ?></link>
			<description><?php esc_html_e( 'Currently open job listings.', 'llamahire' ); ?></description>
			<language><?php echo esc_html( get_bloginfo( 'language' ) ); ?></language>
			<lastBuildDate><?php echo esc_html( $last_build ); ?></lastBuildDate>
			<?php do_action( 'rss2_head' ); ?>
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
			<item>
				<title><?php the_title_rss(); ?></title>
				<link><?php the_permalink_rss(); ?></link>
				<guid isPermaLink="false"><?php echo esc_html( 'llamahire-job-' . get_the_ID() ); ?></guid>
				<pubDate><?php echo esc_html( get_post_time( 'r', true ) ); ?></pubDate>
				<description><![CDATA[<?php echo self::item_description( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML is wrapped safely for RSS CDATA. ?>]]></description>
				<?php self::item_categories( get_the_ID() ); ?>
				<?php do_action( 'rss2_item' ); ?>
			</item>
			<?php endwhile; ?>
		</channel>
		</rss>
		<?php
		wp_reset_postdata();
	}

	private static function directory_url( array $state ) {
		$settings = Settings::get();
		$base     = Settings::public_page( $settings['careers_page_id'] ) ? get_permalink( $settings['careers_page_id'] ) : get_post_type_archive_link( Jobs::POST_TYPE );
		$args     = array();
		foreach ( self::FILTER_KEYS as $key ) {
			$value = $state[ $key ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( 'location' === $key ? '|' : ',', array_filter( $value ) );
			}
			if ( '' !== (string) $value ) {
				$args[ $key ] = $value;
			}
		}
		return add_query_arg( $args, $base ?: home_url( '/' ) );
	}

	private static function item_description( $job_id ) {
		$job      = get_post( $job_id );
		$meta     = Jobs::get_meta( $job_id );
		$details  = array_filter( array( $meta['organization_name'], $meta['location'], Jobs::employment_label( $meta['employment_type'] ) ) );
		$summary  = $job->post_excerpt ? $job->post_excerpt : wp_trim_words( wp_strip_all_tags( $job->post_content ), 55 );
		$html     = ( $details ? '<p>' . esc_html( implode( ' · ', $details ) ) . '</p>' : '' ) . ( $summary ? '<p>' . esc_html( $summary ) . '</p>' : '' );
		return str_replace( ']]>', ']]&gt;', $html );
	}

	private static function item_categories( $job_id ) {
		foreach ( array( Jobs::DEPARTMENT_TAXONOMY, Jobs::TYPE_TAXONOMY ) as $taxonomy ) {
			$terms = get_the_terms( $job_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				echo '<category>' . esc_html( $term->name ) . '</category>';
			}
		}
	}

	private function __construct() {}
}
