<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_llamahire_update_application', array( __CLASS__, 'update_application' ) );
		add_action( 'admin_post_llamahire_retry_notifications', array( __CLASS__, 'retry_notifications' ) );
		add_action( 'admin_post_llamahire_export', array( __CLASS__, 'export' ) );
		add_filter( 'manage_' . Jobs::POST_TYPE . '_posts_columns', array( __CLASS__, 'job_columns' ) );
		add_action( 'manage_' . Jobs::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'job_column' ), 10, 2 );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Hiring dashboard', 'llamahire' ), __( 'Dashboard', 'llamahire' ), Capabilities::VIEW_APPLICATIONS, 'llamahire-dashboard', array( __CLASS__, 'dashboard' ) );
		add_submenu_page( 'edit.php?post_type=' . Jobs::POST_TYPE, __( 'Applications', 'llamahire' ), __( 'Applications', 'llamahire' ), Capabilities::VIEW_APPLICATIONS, 'llamahire-applications', array( __CLASS__, 'applications_page' ) );
	}

	public static function dashboard() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		$applications = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY );
		$counts = $applications->counts();
		$open_count = Jobs::open_count();
		$recent = $applications->recent( 5 );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Hiring dashboard', 'llamahire' ); ?></h1>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;max-width:900px;margin:20px 0">
		<?php foreach ( array( __( 'Open jobs', 'llamahire' ) => $open_count, __( 'New', 'llamahire' ) => $counts['new'], __( 'Reviewing', 'llamahire' ) => $counts['reviewing'], __( 'Hired', 'llamahire' ) => $counts['hired'], __( 'Email attention', 'llamahire' ) => $counts['notification_attention'] ) as $label => $count ) : ?>
		<div class="card" style="margin:0"><p style="font-size:28px;font-weight:700;margin:0"><?php echo esc_html( $count ); ?></p><p><?php echo esc_html( $label ); ?></p></div><?php endforeach; ?>
		</div>
		<h2><?php esc_html_e( 'Recent applicants', 'llamahire' ); ?></h2>
		<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Candidate', 'llamahire' ); ?></th><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Status', 'llamahire' ); ?></th><th><?php esc_html_e( 'Received', 'llamahire' ); ?></th></tr></thead><tbody>
		<?php if ( $recent ) : foreach ( $recent as $row ) : ?><tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=llamahire-applications&application=' . $row->id ) ); ?>"><?php echo esc_html( $row->name ); ?></a></td><td><?php echo esc_html( $row->job_title ); ?></td><td><?php echo esc_html( ucfirst( $row->status ) ); ?></td><td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="4"><?php esc_html_e( 'Applications will appear here.', 'llamahire' ); ?></td></tr><?php endif; ?>
		</tbody></table></div>
		<?php
	}

	public static function applications_page() {
		self::require_capability( Capabilities::VIEW_APPLICATIONS );
		if ( isset( $_GET['application'] ) ) {
			self::application_detail( absint( $_GET['application'] ) );
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$result = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->search( array( 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => 20 ) );
		$rows   = $result['items'];
		?>
		<div class="wrap"><h1 class="wp-heading-inline"><?php esc_html_e( 'Applications', 'llamahire' ); ?></h1> <?php if ( current_user_can( Capabilities::EXPORT_APPLICATIONS ) ) : ?><a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=llamahire_export' ), 'llamahire_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'llamahire' ); ?></a><?php endif; ?><hr class="wp-header-end">
		<ul class="subsubsub"><?php foreach ( array( '' => __( 'All', 'llamahire' ), 'new' => __( 'New', 'llamahire' ), 'reviewing' => __( 'Reviewing', 'llamahire' ), 'rejected' => __( 'Rejected', 'llamahire' ), 'hired' => __( 'Hired', 'llamahire' ) ) as $key => $label ) : ?><li><a <?php echo $status === $key ? 'class="current"' : ''; ?> href="<?php echo esc_url( add_query_arg( array( 'page' => 'llamahire-applications', 'status' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a> | </li><?php endforeach; ?></ul>
		<form method="get"><input type="hidden" name="page" value="llamahire-applications"><?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?><p class="search-box"><label class="screen-reader-text" for="application-search"><?php esc_html_e( 'Search applications', 'llamahire' ); ?></label><input id="application-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>"><button class="button"><?php esc_html_e( 'Search', 'llamahire' ); ?></button></p></form>
		<table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Candidate', 'llamahire' ); ?></th><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Status', 'llamahire' ); ?></th><th><?php esc_html_e( 'Email', 'llamahire' ); ?></th><th><?php esc_html_e( 'Received', 'llamahire' ); ?></th></tr></thead><tbody>
		<?php if ( $rows ) : foreach ( $rows as $row ) : ?><tr><td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=llamahire-applications&application=' . $row->id ) ); ?>"><?php echo esc_html( $row->name ); ?></a></strong><br><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td><td><a href="<?php echo esc_url( get_edit_post_link( $row->job_id ) ); ?>"><?php echo esc_html( $row->job_title ); ?></a></td><td><?php echo esc_html( ucfirst( $row->status ) ); ?></td><td><?php echo esc_html( ucfirst( $row->notification_status ) ); ?></td><td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'No applications found.', 'llamahire' ); ?></td></tr><?php endif; ?>
		</tbody></table><?php if ( $result['pages'] > 1 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $result['page'], 'total' => $result['pages'], 'prev_text' => __( '&laquo; Previous', 'llamahire' ), 'next_text' => __( 'Next &raquo;', 'llamahire' ) ) ) ); ?></div></div><?php endif; ?></div>
		<?php
	}

	private static function application_detail( $id ) {
		$row = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $id );
		if ( ! $row ) { wp_die( esc_html__( 'Application not found.', 'llamahire' ) ); }
		?>
		<div class="wrap"><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=llamahire-applications' ) ); ?>">&larr; <?php esc_html_e( 'All applications', 'llamahire' ); ?></a></p><h1><?php echo esc_html( $row->name ); ?></h1><div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:24px;max-width:1000px">
		<div class="card" style="max-width:none"><h2><?php esc_html_e( 'Candidate', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Email:', 'llamahire' ); ?></strong> <a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></p><?php if ( $row->phone ) : ?><p><strong><?php esc_html_e( 'Phone:', 'llamahire' ); ?></strong> <?php echo esc_html( $row->phone ); ?></p><?php endif; ?><p><strong><?php esc_html_e( 'Applied for:', 'llamahire' ); ?></strong> <?php echo esc_html( get_the_title( $row->job_id ) ); ?></p><?php if ( $row->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) : ?><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=llamahire_resume&application=' . $id ), 'llamahire_resume_' . $id ) ); ?>"><?php esc_html_e( 'Download resume', 'llamahire' ); ?></a></p><?php endif; ?><h2><?php esc_html_e( 'Cover letter', 'llamahire' ); ?></h2><p style="white-space:pre-wrap"><?php echo esc_html( $row->cover_letter ?: __( 'No cover letter provided.', 'llamahire' ) ); ?></p><h2><?php esc_html_e( 'Notifications', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( ucfirst( $row->notification_status ) ); ?><br><strong><?php esc_html_e( 'Attempts:', 'llamahire' ); ?></strong> <?php echo esc_html( $row->notification_attempts ); ?></p><?php if ( in_array( $row->notification_status, array( 'pending', 'partial', 'failed' ), true ) && current_user_can( Capabilities::RETRY_NOTIFICATIONS ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_retry_notifications"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_retry_notifications_' . $id ); ?><button class="button"><?php esc_html_e( 'Retry missing emails', 'llamahire' ); ?></button></form><?php endif; ?></div>
		<div><?php if ( current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) : ?><form class="card" style="max-width:none" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><h2><?php esc_html_e( 'Review', 'llamahire' ); ?></h2><input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $id ); ?><p><label for="status"><strong><?php esc_html_e( 'Status', 'llamahire' ); ?></strong></label><br><select id="status" name="status" style="width:100%"><?php foreach ( array( 'new' => __( 'New', 'llamahire' ), 'reviewing' => __( 'Reviewing', 'llamahire' ), 'rejected' => __( 'Rejected', 'llamahire' ), 'hired' => __( 'Hired', 'llamahire' ) ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p><p><label for="notes"><strong><?php esc_html_e( 'Private notes', 'llamahire' ); ?></strong></label><textarea id="notes" name="notes" rows="8" style="width:100%"><?php echo esc_textarea( $row->notes ); ?></textarea></p><button class="button button-primary"><?php esc_html_e( 'Save changes', 'llamahire' ); ?></button></form><?php else : ?><div class="card" style="max-width:none"><h2><?php esc_html_e( 'Review', 'llamahire' ); ?></h2><p><strong><?php esc_html_e( 'Status:', 'llamahire' ); ?></strong> <?php echo esc_html( ucfirst( $row->status ) ); ?></p><p><strong><?php esc_html_e( 'Private notes:', 'llamahire' ); ?></strong><br><?php echo nl2br( esc_html( $row->notes ?: __( 'No private notes.', 'llamahire' ) ) ); ?></p></div><?php endif; ?></div>
		</div></div>
		<?php
	}

	public static function update_application() {
		$id = absint( $_POST['application'] ?? 0 ); check_admin_referer( 'llamahire_update_' . $id );
		if ( ! current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) { wp_die( esc_html__( 'You cannot update applications.', 'llamahire' ) ); }
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'new', 'reviewing', 'rejected', 'hired' ), true ) ) { $status = 'new'; }
		Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update(
			$id,
			array(
				'status' => $status,
				'notes'  => wp_unslash( $_POST['notes'] ?? '' ),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=llamahire-applications&application=' . $id . '&updated=1' ) ); exit;
	}

	public static function retry_notifications() {
		$id = absint( $_POST['application'] ?? 0 );
		check_admin_referer( 'llamahire_retry_notifications_' . $id );
		if ( ! current_user_can( Capabilities::RETRY_NOTIFICATIONS ) ) {
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
		}
		wp_safe_redirect( admin_url( 'admin.php?page=llamahire-applications&application=' . $id . '&notifications_retried=1' ) );
		exit;
	}

	public static function export() {
		check_admin_referer( 'llamahire_export' ); if ( ! current_user_can( Capabilities::EXPORT_APPLICATIONS ) ) { wp_die( esc_html__( 'You cannot export applications.', 'llamahire' ) ); }
		header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=llamahire-applications-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); fputcsv( $out, array( 'ID', 'Job', 'Name', 'Email', 'Phone', 'Cover letter', 'Status', 'Received' ) );
		$rows = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->export_rows();
		foreach ( $rows as $row ) {
			$values = array( $row['id'], $row['job_title'], $row['name'], $row['email'], $row['phone'], $row['cover_letter'], $row['status'], $row['created_at'] );
			fputcsv( $out, array_map( array( __CLASS__, 'safe_csv_value' ), $values ) );
		}
		fclose( $out ); exit;
	}

	private static function safe_csv_value( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	private static function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You cannot access candidate applications.', 'llamahire' ), 403 );
		}
	}

	public static function job_columns( $columns ) { $columns['llamahire_status'] = __( 'Hiring status', 'llamahire' ); return $columns; }
	public static function job_column( $column, $post_id ) { if ( 'llamahire_status' === $column ) { echo Jobs::is_open( $post_id ) ? esc_html__( 'Open', 'llamahire' ) : esc_html__( 'Closed', 'llamahire' ); } }
}
