<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Admin {
	const EXPORT_COLUMNS = array( 'ID', 'Job', 'Name', 'Email', 'Phone', 'Cover letter', 'Status', 'Received' );

	private static $job_application_counts;

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'order_job_menu' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_menu_assets' ) );
		add_action( 'admin_enqueue_scripts', array( 'LlamaHire\\Admin_Workspaces', 'enqueue_assets' ) );
		add_action( 'admin_post_llamahire_update_application', array( __CLASS__, 'update_application' ) );
		add_action( 'admin_post_llamahire_add_application_note', array( __CLASS__, 'add_application_note' ) );
		add_action( 'wp_ajax_llamahire_move_application', array( 'LlamaHire\\Admin_Workspaces', 'move_application_ajax' ) );
		add_action( 'admin_post_llamahire_retry_notifications', array( __CLASS__, 'retry_notifications' ) );
		add_action( 'admin_post_llamahire_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_llamahire_delete_resume', array( __CLASS__, 'delete_resume' ) );
		add_action( 'admin_post_llamahire_replace_resume', array( __CLASS__, 'replace_resume' ) );
		add_action( 'admin_post_llamahire_erase_application', array( __CLASS__, 'erase_application' ) );
		add_action( 'admin_post_llamahire_moderate_job', array( __CLASS__, 'moderate_job' ) );
		add_action( 'admin_notices', array( __CLASS__, 'moderation_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'pending_job_prompt' ) );
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
		$meta_query = self::job_state_meta_query( $state );
		if ( ! $meta_query ) {
			return;
		}
		$query->set( 'post_status', 'publish' );
		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function job_views( array $views ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a read-only list view.
		$current_state  = sanitize_key( wp_unslash( $_GET['llamahire_job_state'] ?? '' ) );
		$current_status = sanitize_key( wp_unslash( $_GET['post_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a read-only list view.
		$jobs_url       = admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE );
		$author_id      = Ownership::current_author_scope();
		$pending_args   = array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'pending', 'posts_per_page' => 1, 'fields' => 'ids' );
		if ( $author_id ) {
			$pending_args['author'] = $author_id;
		}
		$pending_query = new \WP_Query( $pending_args );
		$pending_url   = add_query_arg( 'post_status', 'pending', $jobs_url );
		$views['llamahire_pending'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $pending_url ),
			'pending' === $current_status ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Awaiting review', 'llamahire' ),
			esc_html( number_format_i18n( $pending_query->found_posts ) )
		);
		$open_url = add_query_arg(
			array(
				'post_type'           => Jobs::POST_TYPE,
				'llamahire_job_state' => 'open',
			),
			admin_url( 'edit.php' )
		);
		$count = self::job_state_count( 'open', $author_id );
		$views['llamahire_open'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $open_url ),
			'open' === $current_state ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Live', 'llamahire' ),
			esc_html( number_format_i18n( $count ) )
		);
		foreach (
			array(
				'closing-soon' => __( 'Closing soon', 'llamahire' ),
				'closed'       => __( 'Closed', 'llamahire' ),
				'expired'      => __( 'Expired', 'llamahire' ),
			) as $state => $label
		) {
			$state_url = add_query_arg( 'llamahire_job_state', $state, $jobs_url );
			$views[ 'llamahire_' . $state ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $state_url ),
				$state === $current_state ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( self::job_state_count( $state, $author_id ) ) )
			);
		}
		return $views;
	}

	private static function job_state_count( $state, $author_id ) {
		$args = array(
			'post_type'      => Jobs::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => self::job_state_meta_query( $state ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( $author_id ) {
			$args['author'] = $author_id;
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	private static function job_state_meta_query( $state ) {
		if ( 'open' === $state ) {
			return Jobs::open_meta_query();
		}
		if ( 'closing-soon' === $state ) {
			return Jobs::closing_soon_meta_query();
		}
		if ( 'closed' === $state ) {
			return array( array( 'key' => Jobs::META_CLOSED, 'value' => '1' ) );
		}
		if ( 'expired' === $state ) {
			return array(
				'relation' => 'AND',
				array( 'key' => Jobs::META_CLOSED, 'value' => '1', 'compare' => '!=' ),
				array(
					'relation' => 'OR',
					array( 'key' => Jobs::META_DEADLINE, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ),
					array( 'key' => Jobs::META_EXPIRY, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ),
				),
			);
		}
		return array();
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
			'llamahire-applications',
			'llamahire-hiring',
			'edit-tags.php?taxonomy=' . Jobs::TYPE_TAXONOMY . '&post_type=' . Jobs::POST_TYPE,
			'edit-tags.php?taxonomy=llamahire_department&post_type=' . Jobs::POST_TYPE,
			'llamahire-dashboard',
			'llamahire-activity',
			'llamahire-settings',
			'llamahire-setup',
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
		foreach ( $items as $item_index => $item ) {
			if ( 'llamahire-dashboard' === html_entity_decode( (string) $item[2], ENT_QUOTES, 'UTF-8' ) ) {
				$items[ $item_index ][4] = trim( (string) ( $item[4] ?? '' ) . ' llamahire-submenu-section-start' );
				break;
			}
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reorders only LlamaHire's registered submenu items.
		$submenu[ $parent ] = $items;
	}

	public static function enqueue_menu_assets() {
		wp_enqueue_style(
			'llamahire-admin-menu',
			LLAMAHIRE_URL . 'assets/css/admin-menu.css',
			array(),
			(string) filemtime( LLAMAHIRE_PATH . 'assets/css/admin-menu.css' )
		);
	}

	public static function activity_page() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		$asset_path = LLAMAHIRE_PATH . 'build/admin-activity.asset.php';
		$script_path = LLAMAHIRE_PATH . 'build/admin-activity.js';
		$style_path = LLAMAHIRE_PATH . 'build/admin-activity.css';
		if ( ! is_readable( $asset_path ) || ! is_readable( $script_path ) || ! is_readable( $style_path ) ) {
			wp_die( esc_html__( 'The Activity interface assets are missing. Rebuild the LlamaHire plugin assets and try again.', 'llamahire' ) );
		}
		$asset = require $asset_path;
		wp_enqueue_script(
			'llamahire-admin-activity',
			LLAMAHIRE_URL . 'build/admin-activity.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			'llamahire-admin-activity',
			LLAMAHIRE_URL . 'build/admin-activity.css',
			array( 'wp-components' ),
			$asset['version']
		);
		wp_style_add_data( 'llamahire-admin-activity', 'rtl', 'replace' );
		$author_id = Ownership::current_author_scope();
		wp_localize_script(
			'llamahire-admin-activity',
			'llamahireActivity',
			array(
				'apiPath' => '/llamahire/v1/activity',
				'baseUrl' => self::activity_url(),
				'events'  => array_map(
					static function ( $type, $label ) {
						return array( 'value' => $type, 'label' => $label );
					},
					array_keys( Audit_Log::event_labels() ),
					array_values( Audit_Log::event_labels() )
				),
				'jobs'    => array_map(
					static function ( $job ) {
						return array( 'value' => (int) $job->ID, 'label' => $job->post_title );
					},
					self::application_filter_jobs( $author_id )
				),
				'actors'  => array_map(
					static function ( $actor ) {
						return array( 'value' => (int) $actor->ID, 'label' => $actor->display_name );
					},
					Audit_Log::actor_options( $author_id )
				),
			)
		);
		?>
		<div class="wrap llamahire-activity-screen"><h1><?php esc_html_e( 'Hiring activity', 'llamahire' ); ?></h1><p><?php esc_html_e( 'Privacy-safe operational history. Candidate names, contact details, notes, resume filenames, IP addresses, and browser data are never copied here.', 'llamahire' ); ?></p>
		<div id="llamahire-activity-root"><p><?php esc_html_e( 'Loading activity…', 'llamahire' ); ?></p></div>
		<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'The Activity interface requires JavaScript.', 'llamahire' ); ?></p></div></noscript>
		</div>
		<?php
	}

	public static function activity_url( array $arguments = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Jobs::POST_TYPE,
					'page'      => 'llamahire-activity',
				),
				$arguments
			),
			admin_url( 'edit.php' )
		);
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
		if ( isset( $_GET['application'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail routing; authorization is checked in application_detail().
			wp_enqueue_style( 'llamahire-admin-application-detail', LLAMAHIRE_URL . 'assets/css/admin-application-detail.css', array(), LLAMAHIRE_VERSION );
			self::application_detail( absint( $_GET['application'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only application identifier; authorization is checked in application_detail().
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
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inbox filter.
		$status = array_key_exists( $status, Applications::workflow_statuses() ) ? $status : '';
		wp_localize_script(
			'llamahire-admin-applications',
			'llamahireApplications',
			array(
				'apiPath'       => '/llamahire/v1/applications',
				'bulkStatusPath' => '/llamahire/v1/applications/bulk-status',
				'noteMaxLength' => Application_Notes::MAX_LENGTH,
				'baseUrl'       => self::applications_url(),
				'exportUrl'     => wp_nonce_url( add_query_arg( 'action', 'llamahire_export', admin_url( 'admin-post.php' ) ), 'llamahire_export' ),
				'canExport'     => current_user_can( Capabilities::EXPORT_APPLICATIONS ),
				'canManage'     => current_user_can( Capabilities::MANAGE_APPLICATIONS ),
				'canDownload'   => current_user_can( Capabilities::DOWNLOAD_RESUMES ),
				'initialSearch' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inbox filter.
				'initialStatus' => $status,
				'initialJobId'  => absint( $_GET['job_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inbox filter.
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
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		if ( ! empty( $_GET['application_erased'] ) ) : ?>
		<div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The application and its private resume were permanently erased.', 'llamahire' ); ?></p></div><?php endif; ?>
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
			'posts_per_page' => 250, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Deliberately bounded admin-only selector; no post content or metadata is primed.
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
		$private_notes = Application_Notes::for_application( $id, 50 );
		$history       = Audit_Log::search( array( 'application_id' => $id, 'per_page' => 20 ) );
		$job_title     = get_the_title( $row->job_id );
		$applied_at    = get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$updated = ! empty( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		$retried = ! empty( $_GET['notifications_retried'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		$data_action = sanitize_key( wp_unslash( $_GET['data_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		?>
		<div class="wrap llamahire-application-detail">
		<p class="llamahire-application-detail__back"><a href="<?php echo esc_url( self::applications_url() ); ?>">&larr; <?php esc_html_e( 'All applications', 'llamahire' ); ?></a></p>
		<header class="llamahire-application-detail__header">
			<h1><?php echo esc_html( $row->name ); ?></h1>
			<p><strong><?php echo esc_html( $job_title ); ?></strong><span aria-hidden="true"> · </span><?php printf( esc_html__( 'Applied %s', 'llamahire' ), esc_html( $applied_at ) ); ?></p>
		</header>
		<?php if ( $updated ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Application review saved.', 'llamahire' ); ?></p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['note_added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action. ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Private note added.', 'llamahire' ); ?></p></div><?php elseif ( ! empty( $_GET['note_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action. ?><div class="notice notice-error inline" role="alert"><p><?php esc_html_e( 'The private note could not be added.', 'llamahire' ); ?></p></div><?php endif; ?>
		<?php if ( $retried ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Missing email notifications were retried.', 'llamahire' ); ?></p></div><?php endif; ?>
		<?php if ( 'resume_deleted' === $data_action ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The private resume was permanently deleted.', 'llamahire' ); ?></p></div><?php elseif ( 'resume_replaced' === $data_action ) : ?><div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'The private resume was replaced.', 'llamahire' ); ?></p></div><?php elseif ( 'error' === $data_action ) : ?><div class="notice notice-error inline" role="alert"><p><?php esc_html_e( 'The candidate-data change could not be completed. No application record was removed.', 'llamahire' ); ?></p></div><?php endif; ?>
		<div class="llamahire-application-detail__layout">
			<main class="card llamahire-application-detail__main">
				<section class="llamahire-application-detail__section" aria-labelledby="llamahire-application-materials-title">
					<h2 id="llamahire-application-materials-title"><?php esc_html_e( 'Application materials', 'llamahire' ); ?></h2>
					<?php if ( $row->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) : ?>
					<div class="llamahire-application-detail__resume">
						<div><strong><?php echo esc_html( $row->resume_name ); ?></strong><span><?php esc_html_e( 'Resume', 'llamahire' ); ?></span></div>
						<div class="llamahire-application-detail__resume-actions"><?php if ( Applications::resume_is_previewable( $row->resume_name ) ) : ?><a class="button" href="<?php echo esc_url( Applications::resume_url( $id, true ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View resume', 'llamahire' ); ?></a><?php endif; ?><a class="button" href="<?php echo esc_url( Applications::resume_url( $id ) ); ?>"><?php esc_html_e( 'Download resume', 'llamahire' ); ?></a></div>
					</div>
					<?php endif; ?>
					<h3><?php esc_html_e( 'Cover letter', 'llamahire' ); ?></h3>
					<p class="llamahire-application-detail__cover-letter"><?php echo esc_html( $row->cover_letter ?: __( 'No cover letter provided.', 'llamahire' ) ); ?></p>
				</section>
				<section class="llamahire-application-detail__section llamahire-application-detail__notes" aria-labelledby="llamahire-private-notes-title">
					<h2 id="llamahire-private-notes-title"><?php esc_html_e( 'Private notes', 'llamahire' ); ?></h2>
					<?php if ( current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_add_application_note"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_add_note_' . $id ); ?><p><label for="notes"><strong><?php esc_html_e( 'Add private note', 'llamahire' ); ?></strong></label><textarea id="notes" name="note" rows="4" maxlength="<?php echo esc_attr( Application_Notes::MAX_LENGTH ); ?>" required></textarea></p><p class="llamahire-application-detail__form-actions"><button class="button button-primary"><?php esc_html_e( 'Add note', 'llamahire' ); ?></button></p></form><?php endif; ?>
					<?php if ( $private_notes ) : $latest_note = $private_notes[0]; ?>
					<div class="llamahire-application-detail__latest-note"><p><?php echo esc_html( $latest_note->body ); ?></p><small><?php echo esc_html( Application_Notes::author_label( $latest_note ) ); ?> · <?php echo esc_html( get_date_from_gmt( $latest_note->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small></div>
					<?php if ( count( $private_notes ) > 1 ) : ?><details class="llamahire-application-detail__history"><summary><?php esc_html_e( 'View all notes', 'llamahire' ); ?></summary><ol><?php foreach ( $private_notes as $note ) : ?><li><p><?php echo esc_html( $note->body ); ?></p><small><?php echo esc_html( Application_Notes::author_label( $note ) ); ?> · <?php echo esc_html( get_date_from_gmt( $note->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small></li><?php endforeach; ?></ol></details><?php endif; ?>
					<?php else : ?><p><?php esc_html_e( 'No private notes yet.', 'llamahire' ); ?></p><?php endif; ?>
				</section>
			</main>
			<aside class="card llamahire-application-detail__sidebar" aria-label="<?php esc_attr_e( 'Application details', 'llamahire' ); ?>">
				<section class="llamahire-application-detail__sidebar-section"><h2><?php esc_html_e( 'Contact info', 'llamahire' ); ?></h2><dl><dt><?php esc_html_e( 'Email', 'llamahire' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></dd><?php if ( $row->phone ) : ?><dt><?php esc_html_e( 'Phone', 'llamahire' ); ?></dt><dd><?php echo esc_html( $row->phone ); ?></dd><?php endif; ?></dl></section>
				<section class="llamahire-application-detail__sidebar-section"><h2><?php esc_html_e( 'Application status', 'llamahire' ); ?></h2><?php if ( current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $id ); ?><p><label for="status"><strong><?php esc_html_e( 'Status', 'llamahire' ); ?></strong></label><select id="status" name="status"><?php foreach ( Applications::workflow_statuses() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p><button class="button button-primary"><?php esc_html_e( 'Save status', 'llamahire' ); ?></button></form><?php else : ?><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( Applications::status_label( $row->status ) ); ?></p><?php endif; ?>
					<div class="llamahire-application-detail__email-delivery"><h3><?php esc_html_e( 'Email delivery', 'llamahire' ); ?></h3><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( ucfirst( $row->notification_status ) ); ?><br><strong><?php esc_html_e( 'Attempts:', 'llamahire' ); ?></strong> <?php echo esc_html( $row->notification_attempts ); ?></p><?php if ( in_array( $row->notification_status, array( 'pending', 'partial', 'failed' ), true ) && current_user_can( Capabilities::RETRY_NOTIFICATIONS ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_retry_notifications"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_retry_notifications_' . $id ); ?><button class="button"><?php esc_html_e( 'Retry missing emails', 'llamahire' ); ?></button></form><?php endif; ?></div>
				</section>
				<section id="llamahire-application-activity" class="llamahire-application-detail__sidebar-section"><h2><?php esc_html_e( 'Activity', 'llamahire' ); ?></h2><?php if ( $history['items'] ) : ?><ol><?php foreach ( array_slice( $history['items'], 0, 2 ) as $event ) : $actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null; ?><li><strong><?php echo esc_html( Audit_Log::describe( $event ) ); ?></strong><span><?php echo esc_html( $actor ? $actor->display_name : __( 'System', 'llamahire' ) ); ?> · <?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span></li><?php endforeach; ?></ol><?php if ( count( $history['items'] ) > 2 ) : ?><details class="llamahire-application-detail__history"><summary><?php esc_html_e( 'View all activity', 'llamahire' ); ?></summary><ol><?php foreach ( array_slice( $history['items'], 2 ) as $event ) : $actor = $event->actor_user_id ? get_userdata( $event->actor_user_id ) : null; ?><li><strong><?php echo esc_html( Audit_Log::describe( $event ) ); ?></strong><span><?php echo esc_html( $actor ? $actor->display_name : __( 'System', 'llamahire' ) ); ?> · <?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span></li><?php endforeach; ?></ol></details><?php endif; ?><?php else : ?><p><?php esc_html_e( 'No activity recorded yet.', 'llamahire' ); ?></p><?php endif; ?></section>
			</aside>
		</div>
		<?php if ( Ownership::user_can_access_application( $id, Capabilities::ERASE_APPLICATIONS ) ) : ?>
		<details id="llamahire-candidate-data" class="card llamahire-application-detail__candidate-data">
			<summary><?php esc_html_e( 'Manage candidate data', 'llamahire' ); ?></summary>
			<div class="llamahire-application-detail__candidate-data-content">
				<form class="llamahire-application-detail__data-action" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="llamahire_replace_resume">
					<input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'llamahire_replace_resume_' . $id ); ?>
					<div class="llamahire-application-detail__data-action-copy">
						<h3><?php echo $row->has_resume ? esc_html__( 'Replace resume', 'llamahire' ) : esc_html__( 'Add resume', 'llamahire' ); ?></h3>
						<p><?php echo $row->has_resume ? esc_html__( 'Upload a new resume to replace the current one. This change cannot be undone.', 'llamahire' ) : esc_html__( 'Upload a resume for this application.', 'llamahire' ); ?></p>
						<label class="screen-reader-text" for="llamahire-replacement-resume"><?php echo $row->has_resume ? esc_html__( 'Select replacement resume', 'llamahire' ) : esc_html__( 'Select resume', 'llamahire' ); ?></label>
						<input id="llamahire-replacement-resume" type="file" name="resume" accept=".pdf,.docx" required>
						<small><?php esc_html_e( 'PDF or DOCX', 'llamahire' ); ?></small>
					</div>
					<button class="button"><?php echo $row->has_resume ? esc_html__( 'Replace resume', 'llamahire' ) : esc_html__( 'Upload resume', 'llamahire' ); ?></button>
				</form>
				<?php if ( $row->has_resume ) : ?>
				<form class="llamahire-application-detail__data-action llamahire-application-detail__data-action--destructive" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="llamahire_delete_resume">
					<input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'llamahire_delete_resume_' . $id ); ?>
					<div class="llamahire-application-detail__data-action-copy">
						<h3><?php esc_html_e( 'Delete resume', 'llamahire' ); ?></h3>
						<p><?php esc_html_e( 'Permanently delete the current resume. This action cannot be undone.', 'llamahire' ); ?></p>
						<label><input type="checkbox" name="confirm_delete_resume" value="1" required> <?php esc_html_e( 'I understand the current resume will be permanently deleted.', 'llamahire' ); ?></label>
					</div>
					<button class="button button-link-delete"><?php esc_html_e( 'Delete resume', 'llamahire' ); ?></button>
				</form>
				<?php endif; ?>
				<form class="llamahire-application-detail__data-action llamahire-application-detail__data-action--destructive" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="llamahire_erase_application">
					<input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'llamahire_erase_application_' . $id ); ?>
					<div class="llamahire-application-detail__data-action-copy">
						<h3><?php esc_html_e( 'Erase application', 'llamahire' ); ?></h3>
						<p><?php esc_html_e( 'Permanently delete the application, notes, notification history, and private resume. This action cannot be undone.', 'llamahire' ); ?></p>
						<label><input type="checkbox" name="confirm_erase" value="1" required> <?php esc_html_e( 'I understand this permanently deletes the application, notes, notification history, and private resume.', 'llamahire' ); ?></label>
					</div>
					<button class="button button-link-delete"><?php esc_html_e( 'Erase application', 'llamahire' ); ?></button>
				</form>
			</div>
		</details>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function update_application() {
		$id = absint( $_POST['application'] ?? 0 ); check_admin_referer( 'llamahire_update_' . $id );
		if ( ! Ownership::user_can_access_application( $id, Capabilities::MANAGE_APPLICATIONS ) ) { wp_die( esc_html__( 'You cannot update applications.', 'llamahire' ), 403 ); }
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! array_key_exists( $status, Applications::workflow_statuses() ) ) { $status = 'new'; }
		Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update(
			$id,
			array( 'status' => $status )
		);
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );
		if ( ! $redirect || 0 !== strpos( $redirect, admin_url() ) ) {
			$redirect = self::applications_url( array( 'application' => $id, 'updated' => 1 ) );
		}
		wp_safe_redirect( $redirect ); exit;
	}

	public static function add_application_note() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_add_note_' . $id );
		if ( ! Ownership::user_can_access_application( $id, Capabilities::MANAGE_APPLICATIONS ) ) {
			wp_die( esc_html__( 'You cannot update applications.', 'llamahire' ), 403 );
		}
		$result   = Application_Notes::add( $id, sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );
		if ( ! $redirect || 0 !== strpos( $redirect, admin_url() ) ) {
			$redirect = self::applications_url( array( 'application' => $id ) );
		}
		$redirect = add_query_arg( is_wp_error( $result ) ? 'note_error' : 'note_added', 1, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function delete_resume() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_delete_resume_' . $id );
		self::require_erasure_capability( $id );
		if ( '1' !== sanitize_text_field( wp_unslash( $_POST['confirm_delete_resume'] ?? '' ) ) ) {
			self::candidate_data_redirect( $id, 'error' );
		}
		$result = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->delete_resume( $id );
		self::candidate_data_redirect( $id, is_wp_error( $result ) ? 'error' : 'resume_deleted' );
	}

	public static function replace_resume() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_replace_resume_' . $id );
		self::require_erasure_capability( $id );
		$file = isset( $_FILES['resume'] ) && is_array( $_FILES['resume'] ) ? $_FILES['resume'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The storage service validates the HTTP upload, extension, MIME type, signature, and size.
		$result = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA )->replace_resume( $id, $file );
		self::candidate_data_redirect( $id, is_wp_error( $result ) ? 'error' : 'resume_replaced' );
	}

	public static function erase_application() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_erase_application_' . $id );
		self::require_erasure_capability( $id );
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

	private static function require_erasure_capability( $application_id ) {
		if ( ! Ownership::user_can_access_application( $application_id, Capabilities::ERASE_APPLICATIONS ) ) {
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
		// The byte-order mark lets spreadsheet applications detect UTF-8 without altering parsed values.
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' ); fputcsv( $out, self::EXPORT_COLUMNS, ',', '"', '\\' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- Streams an authorized CSV response directly; no VIP filesystem path is accessed.
		$rows = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->export_rows(
			array_merge(
				REST_API::application_query_arguments( $_GET ),
				Ownership::query_arguments()
			)
		);
		foreach ( $rows as $row ) {
			$values = array( $row['id'], $row['job_title'], $row['name'], $row['email'], $row['phone'], $row['cover_letter'], $row['status'], $row['created_at'] );
			fputcsv( $out, array_map( array( __CLASS__, 'safe_csv_value' ), $values ), ',', '"', '\\' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- Streams an authorized CSV response directly; no VIP filesystem path is accessed.
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
		if ( 'pending' === get_post_status( $post_id ) && self::can_moderate_job( $post_id ) ) {
			echo self::moderation_form( $post_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes all dynamic output.
		}
	}

	public static function moderate_job() {
		$job_id   = absint( $_POST['job_id'] ?? $_GET['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Used to select the nonce action; verified immediately below.
		$decision = sanitize_key( wp_unslash( $_POST['moderation_decision'] ?? $_GET['moderation_decision'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- The request is verified immediately below.
		check_admin_referer( 'llamahire_moderate_job_' . $job_id );
		$job = get_post( $job_id );
		if ( ! $job || Jobs::POST_TYPE !== $job->post_type || 'pending' !== $job->post_status || ! self::can_moderate_job( $job_id ) ) {
			wp_die( esc_html__( 'This job listing cannot be moderated.', 'llamahire' ), 403 );
		}
		$statuses = array(
			'approve'         => 'publish',
			'request_changes' => 'draft',
			'decline'         => 'trash',
		);
		if ( ! isset( $statuses[ $decision ] ) ) {
			wp_die( esc_html__( 'Choose a valid moderation action.', 'llamahire' ), 400 );
		}
		if ( 'trash' === $statuses[ $decision ] ) {
			$result = wp_trash_post( $job_id );
		} else {
			$result = wp_update_post( array( 'ID' => $job_id, 'post_status' => $statuses[ $decision ] ), true );
		}
		if ( ! $result || is_wp_error( $result ) ) {
			wp_die( esc_html__( 'The moderation decision could not be saved. Please try again.', 'llamahire' ), 500 );
		}
		$url = add_query_arg(
			array(
				'post_type'            => Jobs::POST_TYPE,
				'post_status'          => 'pending',
				'llamahire_moderated' => $decision,
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function moderation_notices() {
		$decision = sanitize_key( wp_unslash( $_GET['llamahire_moderated'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation of a completed nonce-protected action.
		$messages = array(
			'approve'         => __( 'The job was approved and published.', 'llamahire' ),
			'request_changes' => __( 'The job was returned to the employer as a draft for changes.', 'llamahire' ),
			'decline'         => __( 'The job was declined and moved to the trash.', 'llamahire' ),
		);
		if ( isset( $messages[ $decision ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $decision ] ) . '</p></div>';
		}
	}

	public static function pending_job_prompt() {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context.
		$action  = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context.
		if ( 'edit' !== $action || ! $post_id || 'pending' !== get_post_status( $post_id ) || ! self::can_moderate_job( $post_id ) ) {
			return;
		}
		echo '<div class="notice notice-info llamahire-moderation-prompt"><h2>' . esc_html__( 'This job is awaiting your review', 'llamahire' ) . '</h2><p>' . esc_html__( 'Review the listing details, then choose an explicit moderation outcome.', 'llamahire' ) . '</p>';
		echo self::moderation_form( $post_id, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes all dynamic output.
		echo '</div>';
	}

	private static function can_moderate_job( $post_id ) {
		return current_user_can( 'publish_llamahire_jobs' ) && current_user_can( 'edit_post', absint( $post_id ) );
	}

	private static function moderation_form( $post_id, $compact ) {
		$classes = 'llamahire-moderation-actions' . ( $compact ? ' is-compact' : '' );
		$actions = array(
			'approve'         => array( 'button button-primary', __( 'Approve and publish', 'llamahire' ) ),
			'request_changes' => array( 'button', __( 'Request changes', 'llamahire' ) ),
			'decline'         => array( 'button-link-delete', __( 'Decline', 'llamahire' ) ),
		);
		$output = '<div class="' . esc_attr( $classes ) . '">';
		foreach ( $actions as $decision => $action ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'              => 'llamahire_moderate_job',
						'job_id'              => absint( $post_id ),
						'moderation_decision' => $decision,
					),
					admin_url( 'admin-post.php' )
				),
				'llamahire_moderate_job_' . absint( $post_id )
			);
			$output .= '<a class="' . esc_attr( $action[0] ) . '" href="' . esc_url( $url ) . '">' . esc_html( $action[1] ) . '</a>';
		}
		return $output . '</div>';
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
