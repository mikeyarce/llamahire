<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Account-required, front-end job submission and author-scoped job management.
 */
final class Employer_Portal {
	public static function register() {
		add_shortcode( 'llamahire_submit_job', array( __CLASS__, 'submit_job_shortcode' ) );
		add_shortcode( 'llamahire_my_jobs', array( __CLASS__, 'my_jobs_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_pages' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
	}

	public static function settings_updated( $old_value, $new_value ) {
		if ( Settings::SITE_MODE_JOB_BOARD === Settings::sanitize_site_mode( $new_value['site_mode'] ?? '' ) ) {
			self::ensure_pages( (array) $new_value );
		}
	}

	public static function maybe_ensure_pages() {
		if ( current_user_can( 'manage_options' ) && Settings::SITE_MODE_JOB_BOARD === Settings::site_mode() ) {
			self::ensure_pages( Settings::get() );
		}
	}

	private static function ensure_pages( array $settings ) {
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'submit_job_page_id' => array( __( 'Submit a Job', 'llamahire' ), '[llamahire_submit_job]' ), 'my_jobs_page_id' => array( __( 'My Jobs', 'llamahire' ), '[llamahire_my_jobs]' ) ) as $key => $page ) {
			if ( Settings::public_page( $settings[ $key ] ?? 0 ) ) {
				continue;
			}
			$existing = get_page_by_path( sanitize_title( $page[0] ), OBJECT, 'page' );
			$shortcode_tag = trim( $page[1], '[]' );
			$page_id = $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, $shortcode_tag ) ? $existing->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page[0], 'post_content' => $page[1] ) );
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$settings[ $key ] = absint( $page_id );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
		}
	}

	public static function handle_request() {
		$action = sanitize_key( wp_unslash( $_POST['llamahire_employer_action'] ?? '' ) );
		if ( ! $action ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			wp_die( esc_html__( 'You cannot manage job listings.', 'llamahire' ), 403 );
		}
		if ( 'save_job' === $action ) {
			self::save_job();
		}
		if ( 'close_job' === $action ) {
			self::close_job();
		}
		if ( 'delete_job' === $action ) {
			self::delete_job();
		}
	}

	private static function save_job() {
		check_admin_referer( 'llamahire_employer_save_job', 'llamahire_employer_nonce' );
		$job_id = absint( $_POST['job_id'] ?? 0 );
		$was_update = (bool) $job_id;
		if ( $job_id && ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'You cannot edit this job listing.', 'llamahire' ), 403 );
		}
		$title = sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['job_description'] ?? '' ) );
		$company = sanitize_text_field( wp_unslash( $_POST['organization_name'] ?? '' ) );
		$method = sanitize_key( wp_unslash( $_POST['application_method'] ?? 'internal' ) );
		$target_raw = wp_unslash( $_POST['application_target'] ?? '' );
		$target = Jobs::sanitize_application_target( $method, $target_raw );
		if ( ! $title || ! trim( wp_strip_all_tags( $content ) ) || ! $company || ! in_array( $method, array( 'internal', 'external_url', 'external_email' ), true ) || ! $target ) {
			self::redirect( array( 'job_error' => 'required', 'job_id' => $job_id ) );
		}
		$logo_validation = self::validate_logo_upload();
		if ( is_wp_error( $logo_validation ) ) {
			self::redirect( array( 'job_error' => 'logo', 'job_id' => $job_id ) );
		}
		$postarr = array(
			'ID'           => $job_id,
			'post_type'    => Jobs::POST_TYPE,
			'post_status'  => 'pending',
			'post_title'   => $title,
			'post_content' => $content,
		);
		if ( ! $job_id ) {
			$postarr['post_author'] = get_current_user_id();
		}
		$saved_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $saved_id ) ) {
			self::redirect( array( 'job_error' => 'save', 'job_id' => $job_id ) );
		}
		Jobs::set_meta(
			$saved_id,
			array(
				'organization_name'    => $company,
				'organization_tagline' => sanitize_text_field( wp_unslash( $_POST['organization_tagline'] ?? '' ) ),
				'organization_url'     => esc_url_raw( wp_unslash( $_POST['organization_url'] ?? '' ) ),
				'location'             => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
				'workplace'            => sanitize_key( wp_unslash( $_POST['workplace'] ?? 'onsite' ) ),
				'employment_type'      => strtoupper( sanitize_key( wp_unslash( $_POST['employment_type'] ?? 'FULL_TIME' ) ) ),
				'application_method'   => $method,
				'application_target'   => $target,
				'closed'               => '0',
			)
		);
		if ( ! empty( $_POST['remove_organization_logo'] ) ) {
			Jobs::set_meta( $saved_id, array( 'organization_logo' => '', 'organization_logo_id' => 0 ) );
		}
		if ( true === $logo_validation ) {
			$attachment_id = self::store_logo_upload( $saved_id );
			if ( is_wp_error( $attachment_id ) ) {
				self::redirect( array( 'job_error' => 'logo', 'job_id' => $saved_id ) );
			}
			Jobs::set_meta( $saved_id, array( 'organization_logo' => wp_get_attachment_url( $attachment_id ), 'organization_logo_id' => $attachment_id ) );
		}
		Employer_Notifications::job_submitted( $saved_id, $was_update );
		Audit_Log::record( $was_update ? 'job_resubmitted' : 'job_submitted', $saved_id );
		$settings = Settings::get();
		$url = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : ( wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'job_submitted', 1, $url ) );
		exit;
	}

	/**
	 * Return true for an upload, false for no upload, or an error for an unsafe file.
	 */
	private static function validate_logo_upload() {
		if ( empty( $_FILES['organization_logo'] ) || ! is_array( $_FILES['organization_logo'] ) ) {
			return false;
		}
		$file = $_FILES['organization_logo']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below before WordPress handles the upload.
		$error = absint( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return false;
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || empty( $file['name'] ) || absint( $file['size'] ?? 0 ) > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'llamahire_logo_upload', __( 'Upload a JPG, PNG, GIF, or WebP image smaller than 2 MB.', 'llamahire' ) );
		}
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), self::logo_mimes() );
		return ! empty( $checked['ext'] ) && ! empty( $checked['type'] ) ? true : new \WP_Error( 'llamahire_logo_type', __( 'Upload a valid JPG, PNG, GIF, or WebP image.', 'llamahire' ) );
	}

	private static function store_logo_upload( $job_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		return media_handle_upload( 'organization_logo', absint( $job_id ), array(), array( 'test_form' => false, 'mimes' => self::logo_mimes() ) );
	}

	private static function logo_mimes() {
		return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
	}

	private static function close_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_close_job_' . $job_id, 'llamahire_employer_nonce' );
		if ( ! Ownership::user_can_manage_job( $job_id ) ) {
			wp_die( esc_html__( 'You cannot close this job listing.', 'llamahire' ), 403 );
		}
		Jobs::set_meta( $job_id, array( 'closed' => '1' ) );
		Audit_Log::record( 'job_closed', $job_id );
		self::my_jobs_redirect( array( 'job_closed' => 1 ) );
	}

	private static function delete_job() {
		$job_id = absint( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'llamahire_employer_delete_job_' . $job_id, 'llamahire_employer_nonce' );
		if ( ! Ownership::user_can_manage_job( $job_id ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm_delete_job'] ?? '' ) ) ) {
			wp_die( esc_html__( 'You cannot delete this job listing.', 'llamahire' ), 403 );
		}
		Audit_Log::record( 'job_deleted', $job_id );
		if ( ! wp_trash_post( $job_id ) ) {
			wp_die( esc_html__( 'The job listing could not be deleted.', 'llamahire' ), 500 );
		}
		self::my_jobs_redirect( array( 'job_deleted' => 1 ) );
	}

	private static function my_jobs_redirect( array $args ) {
		$settings = Settings::get();
		$url = Settings::public_page( $settings['my_jobs_page_id'] ) ? get_permalink( $settings['my_jobs_page_id'] ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private static function redirect( array $args ) {
		$url = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'job_error', 'job_submitted', 'job_closed' ), $url ) ) );
		exit;
	}

	private static function access_message() {
		if ( ! is_user_logged_in() ) {
			return '<p class="llamahire-notice">' . wp_kses_post( sprintf( __( 'Please <a href="%s">sign in</a> to manage job listings. Employer accounts are approved by the job-board operator.', 'llamahire' ), esc_url( wp_login_url( self::current_url() ) ) ) ) . '</p>';
		}
		return '<p class="llamahire-notice">' . esc_html__( 'Your account cannot manage job listings. Contact the job-board operator.', 'llamahire' ) . '</p>';
	}

	public static function submit_job_shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			return self::access_message();
		}
		$job_id = absint( $_GET['job_id'] ?? 0 );
		if ( $job_id && ! Ownership::user_can_manage_job( $job_id ) ) {
			return '<p class="llamahire-notice">' . esc_html__( 'Job listing not found.', 'llamahire' ) . '</p>';
		}
		$post = $job_id ? get_post( $job_id ) : null;
		$meta = $job_id ? Jobs::get_meta( $job_id ) : self::latest_employer_defaults();
		$error = sanitize_key( wp_unslash( $_GET['job_error'] ?? '' ) );
		wp_enqueue_style( 'llamahire' );
		ob_start();
		?>
		<div class="llamahire-employer-portal"><h2><?php echo $job_id ? esc_html__( 'Edit job listing', 'llamahire' ) : esc_html__( 'Submit a job', 'llamahire' ); ?></h2>
		<?php if ( $error ) : ?><div class="llamahire-notice is-error" role="alert"><?php echo esc_html( 'logo' === $error ? __( 'Upload a valid JPG, PNG, GIF, or WebP logo smaller than 2 MB.', 'llamahire' ) : __( 'Complete all required fields with a valid application email or URL.', 'llamahire' ) ); ?></div><?php endif; ?>
		<form method="post" enctype="multipart/form-data">
			<input type="hidden" name="llamahire_employer_action" value="save_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>"><?php wp_nonce_field( 'llamahire_employer_save_job', 'llamahire_employer_nonce' ); ?>
			<label><?php esc_html_e( 'Job title', 'llamahire' ); ?> *<input name="job_title" required value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>"></label>
			<label><?php esc_html_e( 'Job description', 'llamahire' ); ?> *<textarea name="job_description" rows="10" required><?php echo esc_textarea( $post ? $post->post_content : '' ); ?></textarea></label>
			<label><?php esc_html_e( 'Company name', 'llamahire' ); ?> *<input name="organization_name" required value="<?php echo esc_attr( $meta['organization_name'] ); ?>"></label>
			<label><?php esc_html_e( 'Company tagline', 'llamahire' ); ?><input name="organization_tagline" value="<?php echo esc_attr( $meta['organization_tagline'] ); ?>"></label>
			<label><?php esc_html_e( 'Company website', 'llamahire' ); ?><input type="url" name="organization_url" value="<?php echo esc_attr( $meta['organization_url'] ); ?>"></label>
			<label><?php esc_html_e( 'Company logo', 'llamahire' ); ?><?php if ( $meta['organization_logo'] ) : ?><img src="<?php echo esc_url( $meta['organization_logo'] ); ?>" alt="" style="max-width:160px;max-height:100px;object-fit:contain"><?php endif; ?><input type="file" name="organization_logo" accept="image/jpeg,image/png,image/gif,image/webp"><small><?php esc_html_e( 'JPG, PNG, GIF, or WebP. Maximum 2 MB. Uploads are stored in the WordPress Media Library.', 'llamahire' ); ?></small></label>
			<?php if ( $meta['organization_logo'] ) : ?><label><span><input type="checkbox" name="remove_organization_logo" value="1"> <?php esc_html_e( 'Remove the current company logo from this listing', 'llamahire' ); ?></span></label><?php endif; ?>
			<label><?php esc_html_e( 'Location', 'llamahire' ); ?><input name="location" value="<?php echo esc_attr( $meta['location'] ); ?>"></label>
			<label><?php esc_html_e( 'Work style', 'llamahire' ); ?><select name="workplace"><?php foreach ( array( 'onsite' => __( 'On-site', 'llamahire' ), 'hybrid' => __( 'Hybrid', 'llamahire' ), 'remote' => __( 'Remote', 'llamahire' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['workplace'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><?php esc_html_e( 'Employment type', 'llamahire' ); ?><select name="employment_type"><?php foreach ( Jobs::employment_types() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $meta['employment_type'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><?php esc_html_e( 'How candidates apply', 'llamahire' ); ?><select name="application_method"><option value="internal" <?php selected( $meta['application_method'], 'internal' ); ?>><?php esc_html_e( 'LlamaHire application form', 'llamahire' ); ?></option><option value="external_url" <?php selected( $meta['application_method'], 'external_url' ); ?>><?php esc_html_e( 'Employer website', 'llamahire' ); ?></option><option value="external_email" <?php selected( $meta['application_method'], 'external_email' ); ?>><?php esc_html_e( 'Email', 'llamahire' ); ?></option></select></label>
			<label><?php esc_html_e( 'Application email or URL', 'llamahire' ); ?> *<input name="application_target" required value="<?php echo esc_attr( $meta['application_target'] ); ?>"><small><?php esc_html_e( 'For the LlamaHire form, enter the employer inbox that should receive candidate notifications.', 'llamahire' ); ?></small></label>
			<button type="submit"><?php esc_html_e( 'Submit for review', 'llamahire' ); ?></button>
		</form></div>
		<?php
		return ob_get_clean();
	}

	public static function my_jobs_shortcode() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() ) {
			return '';
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_llamahire_jobs' ) ) {
			return self::access_message();
		}
		$jobs = get_posts( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => array( 'pending', 'publish', 'draft' ), 'author' => get_current_user_id(), 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
		wp_enqueue_style( 'llamahire' );
		ob_start();
		$settings = Settings::get();
		$submit_url = Settings::public_page( $settings['submit_job_page_id'] ) ? get_permalink( $settings['submit_job_page_id'] ) : self::current_url();
		?><div class="llamahire-employer-portal"><h2><?php esc_html_e( 'My jobs', 'llamahire' ); ?></h2><?php if ( ! empty( $_GET['job_submitted'] ) ) : ?><div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your job was submitted for moderation.', 'llamahire' ); ?></div><?php elseif ( ! empty( $_GET['job_deleted'] ) ) : ?><div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Your job listing was deleted.', 'llamahire' ); ?></div><?php endif; ?><p><a class="llamahire-button" href="<?php echo esc_url( $submit_url ); ?>"><?php esc_html_e( 'Submit a job', 'llamahire' ); ?></a></p>
		<?php if ( $jobs ) : ?><table><thead><tr><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Status', 'llamahire' ); ?></th><th><?php esc_html_e( 'Actions', 'llamahire' ); ?></th></tr></thead><tbody><?php foreach ( $jobs as $job ) : $meta = Jobs::get_meta( $job->ID ); $status_object = get_post_status_object( $job->post_status ); ?><tr><td><?php echo esc_html( $job->post_title ); ?></td><td><?php echo esc_html( '1' === $meta['closed'] ? __( 'Closed', 'llamahire' ) : ( $status_object ? $status_object->label : $job->post_status ) ); ?></td><td><a href="<?php echo esc_url( add_query_arg( 'job_id', $job->ID, $submit_url ) ); ?>"><?php esc_html_e( 'Edit', 'llamahire' ); ?></a> <a href="<?php echo esc_url( get_preview_post_link( $job ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'llamahire' ); ?></a><?php if ( '1' !== $meta['closed'] ) : ?> <form method="post" style="display:inline"><input type="hidden" name="llamahire_employer_action" value="close_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_close_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><button type="submit" class="llamahire-link-button"><?php esc_html_e( 'Close', 'llamahire' ); ?></button></form><?php endif; ?> <details style="display:inline"><summary class="llamahire-link-button"><?php esc_html_e( 'Delete', 'llamahire' ); ?></summary><form method="post"><input type="hidden" name="llamahire_employer_action" value="delete_job"><input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>"><?php wp_nonce_field( 'llamahire_employer_delete_job_' . $job->ID, 'llamahire_employer_nonce' ); ?><label><input type="checkbox" name="confirm_delete_job" value="1" required> <?php esc_html_e( 'Move this listing to the trash and remove it from My Jobs.', 'llamahire' ); ?></label><button type="submit"><?php esc_html_e( 'Confirm delete', 'llamahire' ); ?></button></form></details></td></tr><?php endforeach; ?></tbody></table><?php else : ?><p><?php esc_html_e( 'You have not submitted any jobs yet.', 'llamahire' ); ?></p><?php endif; ?></div><?php
		return ob_get_clean();
	}

	private static function latest_employer_defaults() {
		$defaults = Jobs::defaults();
		$latest = get_posts( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => array( 'pending', 'publish', 'draft' ), 'author' => get_current_user_id(), 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( ! $latest ) {
			return $defaults;
		}
		$previous = Jobs::get_meta( $latest[0]->ID );
		foreach ( array( 'organization_name', 'organization_tagline', 'organization_url', 'organization_logo', 'organization_logo_id', 'application_method', 'application_target' ) as $key ) {
			$defaults[ $key ] = $previous[ $key ];
		}
		return $defaults;
	}

	private static function current_url() {
		return home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	}

	private function __construct() {}
}
