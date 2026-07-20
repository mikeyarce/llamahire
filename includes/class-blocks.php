<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Blocks {
	public static function register() {
		wp_register_script( 'llamahire-blocks-editor', LLAMAHIRE_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n', 'wp-server-side-render' ), LLAMAHIRE_VERSION, true );
		register_block_type( LLAMAHIRE_PATH . 'blocks/jobs-directory', array( 'render_callback' => array( __CLASS__, 'render_directory' ) ) );
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
		$search     = sanitize_text_field( wp_unslash( $_GET['job_search'] ?? '' ) );
		$department = sanitize_key( wp_unslash( $_GET['department'] ?? '' ) );
		$workplace  = sanitize_key( wp_unslash( $_GET['workplace'] ?? '' ) );
		$meta_query = Jobs::open_meta_query();
		if ( $workplace ) {
			$meta_query[] = array(
				'key'     => Jobs::META_WORKPLACE,
				'value'   => $workplace,
				'compare' => '=',
			);
		}
		if ( ! empty( $attributes['featuredOnly'] ) ) {
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
			's'              => $search,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( $department ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'llamahire_department',
					'field'    => 'slug',
					'terms'    => $department,
				),
			); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$query = new \WP_Query( $args );
		$terms = get_terms(
			array(
				'taxonomy'   => 'llamahire_department',
				'hide_empty' => true,
			)
		);

		ob_start();
		wp_enqueue_style( 'llamahire' );
		?>
		<div <?php echo get_block_wrapper_attributes( array( 'class' => 'llamahire-directory' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> >
			<?php if ( ! empty( $attributes['showFilters'] ) ) : ?>
			<form class="llamahire-filters" method="get" role="search">
				<label><span><?php esc_html_e( 'Search jobs', 'llamahire' ); ?></span><input type="search" name="job_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Job title or keyword', 'llamahire' ); ?>"></label>
				<label><span><?php esc_html_e( 'Department', 'llamahire' ); ?></span><select name="department"><option value=""><?php esc_html_e( 'All departments', 'llamahire' ); ?></option>
				<?php
				foreach ( $terms as $term ) :
					?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $department, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label>
				<label><span><?php esc_html_e( 'Workplace', 'llamahire' ); ?></span><select name="workplace"><option value=""><?php esc_html_e( 'Any workplace', 'llamahire' ); ?></option><option value="remote" <?php selected( $workplace, 'remote' ); ?>><?php esc_html_e( 'Remote', 'llamahire' ); ?></option><option value="hybrid" <?php selected( $workplace, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'llamahire' ); ?></option><option value="onsite" <?php selected( $workplace, 'onsite' ); ?>><?php esc_html_e( 'On-site', 'llamahire' ); ?></option></select></label>
				<button type="submit"><?php esc_html_e( 'Find jobs', 'llamahire' ); ?></button>
			</form>
			<?php endif; ?>
			<div class="llamahire-job-grid">
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
				<p class="llamahire-empty"><?php esc_html_e( 'No open roles match your search.', 'llamahire' ); ?> <?php if ( $search || $department || $workplace ) : ?><a href="<?php echo esc_url( remove_query_arg( array( 'job_search', 'department', 'workplace' ) ) ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?></p>
				<?php
endif;
			wp_reset_postdata();
			?>
			</div>
		</div>
		<?php
		return ob_get_clean();
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
