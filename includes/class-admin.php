<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private static $job_application_counts;

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'order_job_menu' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( 'LlamaHire\\Admin_Workspaces', 'enqueue_assets' ) );
		add_action( 'admin_post_llamahire_update_application', array( __CLASS__, 'update_application' ) );
		add_action( 'wp_ajax_llamahire_move_application', array( 'LlamaHire\\Admin_Workspaces', 'move_application_ajax' ) );
		add_action( 'admin_post_llamahire_retry_notifications', array( __CLASS__, 'retry_notifications' ) );
		add_action( 'admin_post_llamahire_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_llamahire_delete_resume', array( __CLASS__, 'delete_resume' ) );
		add_action( 'admin_post_llamahire_replace_resume', array( __CLASS__, 'replace_resume' ) );
		add_action( 'admin_post_llamahire_erase_application', array( __CLASS__, 'erase_application' ) );
		add_filter( 'manage_' . Jobs::POST_TYPE . '_posts_columns', array( __CLASS__, 'job_columns' ) );
		add_action( 'manage_' . Jobs::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'job_column' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_job_list' ) );
		add_filter( 'views_edit-' . Jobs::POST_TYPE, array( __CLASS__, 'job_views' ) );
	}

	public static function filter_job_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Jobs::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a read-only list view.
		$state = sanitize_key( wp_unslash( $_GET['llamahire_job_state'] ?? '' ) );
		if ( 'open' !== $state ) {
			return;
		}
		$query->set( 'post_status', 'publish' );
		$query->set( 'meta_query', Jobs::open_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function job_views( array $views ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a read-only list view.
		$current = 'open' === sanitize_key( wp_unslash( $_GET['llamahire_job_state'] ?? '' ) );
		$url = add_query_arg(
			array(
				'post_type'           => Jobs::POST_TYPE,
				'llamahire_job_state' => 'open',
			),
			admin_url( 'edit.php' )
		);
		$count = Jobs::open_count( Ownership::current_author_scope() );
		$views['llamahire_open'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Live', 'llamahire' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Hiring dashboard', 'llamahire' ), __( 'Dashboard', 'llamahire' ), Capabilities::VIEW_APPLICATIONS, 'llamahire-dashboard', array( __CLASS__, 'dashboard' ) );
		add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Applications', 'llamahire' ), __( 'Applications', 'llamahire' ), Capabilities::VIEW_APPLICATIONS, 'llamahire-applications', array( __CLASS__, 'applications_page' ) );
		if ( Admin_Workspaces::hiring_available() ) {
			add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Hiring pipeline', 'llamahire' ), __( 'Hiring', 'llamahire' ), Capabilities::MANAGE_APPLICATIONS, 'llamahire-hiring', array( __CLASS__, 'hiring_page' ) );
		}
		add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Hiring activity', 'llamahire' ), __( 'Activity', 'llamahire' ), Capabilities::VIEW_APPLICATIONS, 'llamahire-activity', array( __CLASS__, 'activity_page' ) );
	}

	public static function order_job_menu() {
		global $submenu;
		$parent = 'edit.php?post_type=' . Jobs::POST_TYPE;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$order = array(
			$parent,
			'post-new.php?post_type=' . Jobs::POST_TYPE,
			'llamahire-dashboard',
			'llamahire-applications',
			'llamahire-hiring',
			'llamahire-activity',
			'edit-tags.php?taxonomy=llamahire_department&post_type=' . Jobs::POST_TYPE,
			'llamahire-setup',
			'llamahire-settings',
		);
		$items = $submenu[ $parent ];
		usort(
			$items,
			static function ( $left, $right ) use ( $order ) {
				$left_slug      = html_entity_decode( (string) $left[2], ENT_QUOTES, 'UTF-8' );
				$right_slug     = html_entity_decode( (string) $right[2], ENT_QUOTES, 'UTF-8' );
				$left_position  = array_search( $left_slug, $order, true );
				$right_position = array_search( $right_slug, $order, true );
				$left_position  = false === $left_position ? count( $order ) : $left_position;
				$right_position = false === $right_position ? count( $order ) : $right_position;
				return $left_position <=> $right_position;
			}
		);
		$submenu[ $parent ] = $items;
	}

	public static function activity_page() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$result = Audit_Log::search( array_merge( array( 'page' => $page, 'per_page' => 50 ), Ownership::query_arguments() ) );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Hiring activity', 'llamahire' ); ?></h1><p><?php esc_html_e( 'Privacy-safe operational history. Candidate names, contact details, notes, resume filenames, IP addresses, and browser data are never copied here.', 'llamahire' ); ?></p>
		<table class="widefat striped"><caption class="screen-reader-text"><?php esc_html_e( 'Hiring activity events', 'llamahire' ); ?></caption><thead><tr><th><?php esc_html_e( 'Event', 'llamahire' ); ?></th><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Subject', 'llamahire' ); ?></th><th><?php esc_html_e( 'Actor', 'llamahire' ); ?></th><th><?php esc_html_e( 'Date', 'llamahire' ); ?></th></tr></thead><tbody>
		<?php if ( $result['items'] ) : foreach ( $result['items'] as $event ) : $actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null; ?><tr><td><?php echo esc_html( Audit_Log::describe( $event ) ); ?></td><td><?php echo esc_html( $event->job_title ?: sprintf( __( 'Deleted job #%d', 'llamahire' ), $event->job_id ) ); ?></td><td><?php echo esc_html( 'application' === $event->subject_type ? sprintf( __( 'Application #%d', 'llamahire' ), $event->subject_id ) : sprintf( __( 'Job #%d', 'llamahire' ), $event->subject_id ) ); ?></td><td><?php echo esc_html( $actor ? $actor->display_name : __( 'System', 'llamahire' ) ); ?></td><td><?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'No hiring activity has been recorded yet.', 'llamahire' ); ?></td></tr><?php endif; ?>
		</tbody></table><?php if ( $result['pages'] > 1 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $result['page'], 'total' => $result['pages'] ) ) ); ?></div></div><?php endif; ?></div>
		<?php
	}

	public static function dashboard() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		Admin_Workspaces::render_dashboard();
	}

	public static function hiring_page() {
		self::require_capability( Capabilities::MANAGE_APPLICATIONS );
		Admin_Workspaces::render_hiring();
	}

	public static function applications_page() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		if ( isset( $_GET['application'] ) ) {
			self::application_detail( absint( $_GET['application'] ) );
			return;
		}
		$asset_path = LLAMAHIRE_PATH . 'build/admin-applications.asset.php';
		$script_path = LLAMAHIRE_PATH . 'build/admin-applications.js';
		$style_path = LLAMAHIRE_PATH . 'build/admin-applications.css';
		if ( ! is_readable( $asset_path ) || ! is_readable( $script_path ) || ! is_readable( $style_path ) ) {
			wp_die( esc_html__( 'The Applications interface assets are missing. Rebuild the LlamaHire plugin assets and try again.', 'llamahire' ) );
		}
		$asset = require $asset_path;
		wp_enqueue_script(
			'llamahire-admin-applications',
			LLAMAHIRE_URL . 'build/admin-applications.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			'llamahire-admin-applications',
			LLAMAHIRE_URL . 'build/admin-applications.css',
			array( 'wp-components' ),
			$asset['version']
		);
		wp_style_add_data( 'llamahire-admin-applications', 'rtl', 'replace' );
		$scope = Ownership::query_arguments();
		$jobs  = self::application_filter_jobs( absint( $scope['author_id'] ?? 0 ) );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$status = array_key_exists( $status, Applications::workflow_statuses() ) ? $status : '';
		wp_localize_script(
			'llamahire-admin-applications',
			'llamahireApplications',
			array(
				'apiPath'       => '/llamahire/v1/applications',
				'baseUrl'       => self::applications_url(),
				'exportUrl'     => wp_nonce_url( add_query_arg( 'action', 'llamahire_export', admin_url( 'admin-post.php' ) ), 'llamahire_export' ),
				'canExport'     => current_user_can( Capabilities::EXPORT_APPLICATIONS ),
				'initialSearch' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'initialStatus' => $status,
				'initialJobId'  => absint( $_GET['job_id'] ?? 0 ),
				'jobs'          => array_map(
					static function ( $job ) {
						return array( 'value' => (int) $job->ID, 'label' => $job->post_title );
					},
					$jobs
				),
			)
		);
		?>
		<div class="wrap llamahire-applications-screen"><h1><?php esc_html_e( 'Applications', 'llamahire' ); ?></h1>
		<?php if ( ! empty( $_GET['application_erased'] ) ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The application and its private resume were permanently erased.', 'llamahire' ); ?></p></div><?php endif; ?>
		<div id="llamahire-applications-root"><p><?php esc_html_e( 'Loading applications…', 'llamahire' ); ?></p></div>
		<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'The Applications inbox requires JavaScript. Candidate detail and privacy actions remain server-rendered.', 'llamahire' ); ?></p></div></noscript>
		</div>
		<?php
	}

	public static function applications_url( array $arguments = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Jobs::POST_TYPE,
					'page'      => 'llamahire-applications',
				),
				$arguments
			),
			admin_url( 'edit.php' )
		);
	}

	public static function application_filter_jobs( $author_id = 0 ) {
		$args = array(
			'post_type'      => Jobs::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 250,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $author_id ) {
			$args['author'] = absint( $author_id );
		}
		return get_posts( $args );
	}

	private static function application_detail( $id ) {
		if ( ! Ownership::user_can_access_application( $id, Capabilities::VIEW_APPLICATIONS ) ) {
			wp_die( esc_html__( 'Application not found.', 'llamahire' ), 404 );
		}
		$row = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $id );
		if ( ! $row ) { wp_die( esc_html__( 'Application not found.', 'llamahire' ) ); }
		$updated = ! empty( $_GET['updated'] );
		$retried = ! empty( $_GET['notifications_retried'] );
		$data_action = sanitize_key( wp_unslash( $_GET['data_action'] ?? '' ) );
		?>
		<div class="wrap"><p><a href="<?php echo esc_url( self::applications_url() ); ?>">&larr; <?php esc_html_e( 'All applications', 'llamahire' ); ?></a></p><h1><?php echo esc_html( $row->name ); ?></h1>
		<?php if ( $updated ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Application review saved.', 'llamahire' ); ?></p></div><?php endif; ?>
		<?php if ( $retried ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Missing email notifications were retried.', 'llamahire' ); ?></p></div><?php endif; ?>
		<?php if ( 'resume_deleted' === $data_action ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The private resume was permanently deleted.', 'llamahire' ); ?></p></div><?php elseif ( 'resume_replaced' === $data_action ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The private resume was replaced.', 'llamahire' ); ?></p></div><?php elseif ( 'error' === $data_action ) : ?><div class="notice notice-error inline" role="alert"><p><?php esc_html_e( 'The candidate-data change could not be completed. No application record was removed.', 'llamahire' ); ?></p></div><?php endif; ?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:24px;max-width:1000px">
		<div class="card" style="max-width:none"><h2><?php esc_html_e( 'Candidate', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Email:', 'llamahire' ); ?></strong> <a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></p><?php if ( $row->phone ) : ?><p><strong><?php esc_html_e( 'Phone:', 'llamahire' ); ?></strong> <?php echo esc_html( $row->phone ); ?></p><?php endif; ?><p><strong><?php esc_html_e( 'Applied for:', 'llamahire' ); ?></strong> <?php echo esc_html( get_the_title( $row->job_id ) ); ?></p><?php if ( $row->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) : ?><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=llamahire_resume&application=' . $id ), 'llamahire_resume_' . $id ) ); ?>"><?php esc_html_e( 'Download resume', 'llamahire' ); ?></a></p><?php endif; ?><h2><?php esc_html_e( 'Cover letter', 'llamahire' ); ?></h2><p style="white-space:pre-wrap"><?php echo esc_html( $row->cover_letter ?: __( 'No cover letter provided.', 'llamahire' ) ); ?></p><h2><?php esc_html_e( 'Notifications', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( ucfirst( $row->notification_status ) ); ?><br><strong><?php esc_html_e( 'Attempts:', 'llamahire' ); ?></strong> <?php echo esc_html( $row->notification_attempts ); ?></p><?php if ( in_array( $row->notification_status, array( 'pending', 'partial', 'failed' ), true ) && current_user_can( Capabilities::RETRY_NOTIFICATIONS ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_retry_notifications"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_retry_notifications_' . $id ); ?><button class="button"><?php esc_html_e( 'Retry missing emails', 'llamahire' ); ?></button></form><?php endif; ?></div>
		<div><?php if ( current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) : ?><form class="card" style="max-width:none" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><h2><?php esc_html_e( 'Review', 'llamahire' ); ?></h2><input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $id ); ?><p><label for="status"><strong><?php esc_html_e( 'Status', 'llamahire' ); ?></strong></label><br><select id="status" name="status" style="width:100%"><?php foreach ( Applications::workflow_statuses() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p><p><label for="notes"><strong><?php esc_html_e( 'Private notes', 'llamahire' ); ?></strong></label><textarea id="notes" name="notes" rows="8" style="width:100%"><?php echo esc_textarea( $row->notes ); ?></textarea></p><button class="button button-primary"><?php esc_html_e( 'Save changes', 'llamahire' ); ?></button></form><?php else : ?><div class="card" style="max-width:none"><h2><?php esc_html_e( 'Review', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( Applications::status_label( $row->status ) ); ?></p><p><strong><?php esc_html_e( 'Private notes:', 'llamahire' ); ?></strong><br><?php echo nl2br( esc_html( $row->notes ?: __( 'No private notes.', 'llamahire' ) ) ); ?></p></div><?php endif; ?></div>
		<div class="card" style="max-width:none"><h2><?php esc_html_e( 'Activity', 'llamahire' ); ?></h2><?php $history = Audit_Log::search( array( 'application_id' => $id, 'per_page' => 20 ) ); ?><?php if ( $history['items'] ) : ?><ol><?php foreach ( $history['items'] as $event ) : $actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null; ?><li><strong><?php echo esc_html( Audit_Log::describe( $event ) ); ?></strong><br><span><?php echo esc_html( $actor ? $actor->display_name : __( 'System', 'llamahire' ) ); ?> — <?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span></li><?php endforeach; ?></ol><?php else : ?><p><?php esc_html_e( 'No activity recorded yet.', 'llamahire' ); ?></p><?php endif; ?></div>
		<?php if ( current_user_can( Capabilities::ERASE_APPLICATIONS ) ) : ?><div class="card" style="max-width:none"><h2><?php esc_html_e( 'Candidate data', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Resume changes and erasure are permanent and are not restored from LlamaHire.', 'llamahire' ); ?></p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_replace_resume"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_replace_resume_' . $id ); ?><p><label for="llamahire-replacement-resume"><strong><?php echo $row->has_resume ? esc_html__( 'Replace resume', 'llamahire' ) : esc_html__( 'Add resume', 'llamahire' ); ?></strong></label><br><input id="llamahire-replacement-resume" type="file" name="resume" accept=".pdf,.doc,.docx" required></p><button class="button"><?php echo $row->has_resume ? esc_html__( 'Replace resume', 'llamahire' ) : esc_html__( 'Upload resume', 'llamahire' ); ?></button></form><?php if ( $row->has_resume ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px"><input type="hidden" name="action" value="llamahire_delete_resume"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_delete_resume_' . $id ); ?><p><label><input type="checkbox" name="confirm_delete_resume" value="1" required> <?php esc_html_e( 'I understand the current resume will be permanently deleted.', 'llamahire' ); ?></label></p><button class="button button-link-delete"><?php esc_html_e( 'Delete resume permanently', 'llamahire' ); ?></button></form><?php endif; ?><hr><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_erase_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_erase_application_' . $id ); ?><p><label><input type="checkbox" name="confirm_erase" value="1" required> <?php esc_html_e( 'I understand this permanently deletes the application, notes, notification history, and private resume.', 'llamahire' ); ?></label></p><button class="button button-link-delete"><?php esc_html_e( 'Erase application permanently', 'llamahire' ); ?></button></form></div><?php endif; ?>
		</div></div>
		<?php
	}

	public static function update_application() {
		$id = absint( $_POST['application'] ?? 0 ); check_admin_referer( 'llamahire_update_' . $id );
		if ( ! Ownership::user_can_access_application( $id, Capabilities::MANAGE_APPLICATIONS ) ) { wp_die( esc_html__( 'You cannot update applications.', 'llamahire' ), 403 ); }
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! array_key_exists( $status, Applications::workflow_statuses() ) ) { $status = 'new'; }
		$changes = array( 'status' => $status );
		if ( array_key_exists( 'notes', $_POST ) ) {
			$changes['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ) );
		}
		Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update(
			$id,
			$changes
		);
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );
		if ( ! $redirect || 0 !== strpos( $redirect, admin_url() ) ) {
			$redirect = self::applications_url( array( 'application' => $id, 'updated' => 1 ) );
		}
		wp_safe_redirect( $redirect ); exit;
	}

	public static function delete_resume() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_delete_resume_' . $id );
		self::require_erasure_capability();
		if ( '1' !== sanitize_text_field( wp_unslash( $_POST['confirm_delete_resume'] ?? '' ) ) ) {
			self::candidate_data_redirect( $id, 'error' );
		}
		$result = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->delete_resume( $id );
		self::candidate_data_redirect( $id, is_wp_error( $result ) ? 'error' : 'resume_deleted' );
	}

	public static function replace_resume() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_replace_resume_' . $id );
		self::require_erasure_capability();
		$file = isset( $_FILES['resume'] ) && is_array( $_FILES['resume'] ) ? $_FILES['resume'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The storage service validates the HTTP upload, extension, MIME type, signature, and size.
		$result = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->replace_resume( $id, $file );
		self::candidate_data_redirect( $id, is_wp_error( $result ) ? 'error' : 'resume_replaced' );
	}

	public static function erase_application() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_erase_application_' . $id );
		self::require_erasure_capability();
		if ( '1' !== sanitize_text_field( wp_unslash( $_POST['confirm_erase'] ?? '' ) ) ) {
			self::candidate_data_redirect( $id, 'error' );
		}
		$result = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->erase( $id );
		if ( is_wp_error( $result ) ) {
			self::candidate_data_redirect( $id, 'error' );
		}
		wp_safe_redirect( self::applications_url( array( 'application_erased' => 1 ) ) );
		exit;
	}

	private static function require_erasure_capability() {
		if ( ! current_user_can( Capabilities::ERASE_APPLICATIONS ) ) {
			wp_die( esc_html__( 'You cannot erase candidate data.', 'llamahire' ), 403 );
		}
	}

	private static function candidate_data_redirect( $id, $result ) {
		wp_safe_redirect( self::applications_url( array( 'application' => absint( $id ), 'data_action' => sanitize_key( $result ) ) ) );
		exit;
	}

	public static function retry_notifications() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_retry_notifications_' . $id );
		if ( ! Ownership::user_can_access_application( $id, Capabilities::RETRY_NOTIFICATIONS ) ) {
			wp_die( esc_html__( 'You cannot retry application notifications.', 'llamahire' ), 403 );
		}
		$repository  = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$application = $repository->find( $id );
		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'llamahire' ), 404 );
		}
		$channels = array();
		if ( ! $application->employer_notified_at ) { $channels[] = 'employer'; }
		if ( ! $application->candidate_notified_at ) { $channels[] = 'candidate'; }
		if ( $channels ) {
			$result = Plugin::instance()->services()->get( Service_IDs::NOTIFICATIONS )->application_received( (array) $application, $application->job_id, $channels );
			$repository->record_notification_result( $id, $result );
			Audit_Log::record( 'application_notifications_retried', $application->job_id, $id );
		}
		wp_safe_redirect( self::applications_url( array( 'application' => $id, 'notifications_retried' => 1 ) ) );
		exit;
	}

	public static function export() {
		check_admin_referer( 'llamahire_export' ); if ( ! current_user_can( Capabilities::EXPORT_APPLICATIONS ) ) { wp_die( esc_html__( 'You cannot export applications.', 'llamahire' ) ); }
		header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=llamahire-applications-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); fputcsv( $out, array( 'ID', 'Job', 'Name', 'Email', 'Phone', 'Cover letter', 'Status', 'Received' ), ',', '"', '\\' );
		$rows = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->export_rows(
			array_merge(
				REST_API::application_query_arguments( $_GET ),
				Ownership::query_arguments()
			)
		);
		foreach ( $rows as $row ) {
			$values = array( $row['id'], $row['job_title'], $row['name'], $row['email'], $row['phone'], $row['cover_letter'], $row['status'], $row['created_at'] );
			fputcsv( $out, array_map( array( __CLASS__, 'safe_csv_value' ), $values ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the streamed CSV output handle.
		exit;
	}

	private static function safe_csv_value( $value ) {
		$value = (string) $value;
		$test  = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
		return preg_match( '/^[\x00-\x20]*[=+\-@]/', $test ) ? "'" . $value : $value;
	}

	private static function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You cannot access candidate applications.', 'llamahire' ), 403 );
		}
	}

	public static function job_columns( $columns ) { $columns['llamahire_status'] = __( 'Publication and hiring', 'llamahire' ); return $columns; }
	public static function job_column( $column, $post_id ) {
		if ( 'llamahire_status' !== $column ) {
			return;
		}
		$status = get_post_status_object( get_post_status( $post_id ) );
		echo '<strong>' . esc_html( $status ? $status->label : __( 'Unknown', 'llamahire' ) ) . '</strong><br>';
		if ( 'publish' !== get_post_status( $post_id ) ) {
			esc_html_e( 'Not accepting applications', 'llamahire' );
		} else {
			echo Jobs::is_open( $post_id ) ? esc_html__( 'Accepting applications', 'llamahire' ) : esc_html__( 'Closed to applications', 'llamahire' );
		}
		$counts = self::visible_job_application_counts( $post_id );
		$count  = (int) ( $counts[ $post_id ] ?? 0 );
		$url    = self::applications_url( array( 'job_id' => absint( $post_id ) ) );
		/* translators: %s: Number of applications for a job. */
		$label = sprintf( _n( '%s application', '%s applications', $count, 'llamahire' ), number_format_i18n( $count ) );
		echo '<br><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function visible_job_application_counts( $fallback_job_id = 0 ) {
		if ( null !== self::$job_application_counts ) {
			return self::$job_application_counts;
		}
		global $wp_query;
		$job_ids = array();
		if ( ! empty( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				if ( $post instanceof \WP_Post && Jobs::POST_TYPE === $post->post_type ) {
					$job_ids[] = (int) $post->ID;
				}
			}
		}
		if ( ! $job_ids && $fallback_job_id ) {
			$job_ids[] = absint( $fallback_job_id );
		}
		self::$job_application_counts = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->counts_by_job( $job_ids, Ownership::query_arguments() );
		return self::$job_application_counts;
	}
}
