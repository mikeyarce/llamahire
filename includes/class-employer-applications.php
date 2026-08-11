<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership-scoped candidate review inside the frontend Employer portal.
 */
final class Employer_Applications {
	const PER_PAGE = 20;

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
	}

	public static function url( array $arguments = array() ) {
		return Employer_Portal::my_jobs_url( array_merge( array( 'employer_view' => 'applications' ), $arguments ) );
	}

	public static function handle_request() {
		$action = sanitize_key( wp_unslash( $_POST['llamahire_employer_application_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The selected action verifies its own nonce below.
		if ( ! in_array( $action, array( 'save_status', 'add_note' ), true ) ) {
			return;
		}
		if ( ! Employer_Portal::is_frontend_employer() || ! current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) {
			wp_die( esc_html__( 'You cannot manage candidate applications.', 'llamahire' ), 403 );
		}
		$application_id = absint( $_POST['application_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		check_admin_referer( 'llamahire_employer_application_' . $action . '_' . $application_id, 'llamahire_employer_application_nonce' );
		if ( ! $application_id || ! Ownership::user_can_access_application( $application_id, Capabilities::MANAGE_APPLICATIONS ) ) {
			wp_die( esc_html__( 'Application not found.', 'llamahire' ), 404 );
		}
		$application = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $application_id );
		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'llamahire' ), 404 );
		}
		$notice = '';
		if ( 'save_status' === $action ) {
			$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
			if ( ! array_key_exists( $status, Applications::workflow_statuses() ) ) {
				wp_die( esc_html__( 'Choose a valid application status.', 'llamahire' ), 400 );
			}
			$result = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update( $application_id, array( 'status' => $status ) );
			if ( is_wp_error( $result ) || ! $result ) {
				wp_die( esc_html__( 'The application status could not be saved.', 'llamahire' ), 500 );
			}
			$notice = 'status_saved';
		} else {
			$result = Application_Notes::add( $application_id, sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), 400 );
			}
			$notice = 'note_added';
		}
		wp_safe_redirect( self::url( array( 'job_id' => (int) $application->job_id, 'application_id' => $application_id, $notice => 1 ) ) );
		exit;
	}

	public static function render() {
		if ( Settings::SITE_MODE_JOB_BOARD !== Settings::site_mode() || ! Employer_Portal::is_frontend_employer() || ! current_user_can( Capabilities::VIEW_APPLICATIONS ) ) {
			return '<p class="llamahire-notice is-error">' . esc_html__( 'You cannot access candidate applications.', 'llamahire' ) . '</p>';
		}
		$state       = self::state();
		$application = self::selected_application( $state['application_id'] );
		if ( $application ) {
			$state['job_id'] = (int) $application->job_id;
		}
		$query = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->search(
			array_merge(
				Ownership::query_arguments(),
				array(
					'job_id'   => $state['job_id'],
					'status'   => $state['status'],
					'search'   => $state['search'],
					'page'     => $state['page'],
					'per_page' => self::PER_PAGE,
				)
			)
		);
		$jobs = get_posts(
			array(
				'post_type'      => Jobs::POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'author'         => get_current_user_id(),
				'posts_per_page' => 250, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded ownership-scoped selector loads IDs and titles only.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		wp_enqueue_style( 'llamahire' );
		ob_start();
		?>
		<div class="llamahire-employer-applications">
			<nav class="llamahire-employer-applications__breadcrumb" aria-label="<?php esc_attr_e( 'Employer portal', 'llamahire' ); ?>"><a href="<?php echo esc_url( Employer_Portal::my_jobs_url() ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to My Jobs', 'llamahire' ); ?></a></nav>
			<div class="llamahire-employer-applications__heading"><div><h2><?php esc_html_e( 'Applications', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Review candidates for your job listings.', 'llamahire' ); ?></p></div></div>
			<?php if ( ! empty( $_GET['status_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice. ?><div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Application status saved.', 'llamahire' ); ?></div><?php endif; ?>
			<?php if ( ! empty( $_GET['note_added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice. ?><div class="llamahire-notice is-success" role="status"><?php esc_html_e( 'Private note added.', 'llamahire' ); ?></div><?php endif; ?>
			<form class="llamahire-employer-applications__filters" method="get" action="<?php echo esc_url( Employer_Portal::my_jobs_url() ); ?>" role="search" aria-label="<?php esc_attr_e( 'Filter applications', 'llamahire' ); ?>">
				<input type="hidden" name="employer_view" value="applications">
				<label><span><?php esc_html_e( 'Search candidates', 'llamahire' ); ?></span><input type="search" name="application_search" value="<?php echo esc_attr( $state['search'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Job', 'llamahire' ); ?></span><select name="job_id"><option value="0"><?php esc_html_e( 'All jobs', 'llamahire' ); ?></option><?php foreach ( $jobs as $job ) : ?><option value="<?php echo esc_attr( $job->ID ); ?>" <?php selected( $state['job_id'], $job->ID ); ?>><?php echo esc_html( $job->post_title ); ?></option><?php endforeach; ?></select></label>
				<label><span><?php esc_html_e( 'Status', 'llamahire' ); ?></span><select name="application_status"><option value=""><?php esc_html_e( 'All statuses', 'llamahire' ); ?></option><?php foreach ( Applications::workflow_statuses() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state['status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<button type="submit"><?php esc_html_e( 'Filter applications', 'llamahire' ); ?></button>
				<?php if ( $state['search'] || $state['status'] || $state['job_id'] ) : ?><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a><?php endif; ?>
			</form>
			<p class="llamahire-employer-applications__results" role="status"><?php echo esc_html( self::results_label( $query ) ); ?></p>
			<?php if ( $application ) : self::render_detail( $application ); endif; ?>
			<?php self::render_list( $query, $state ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function state() {
		$status = sanitize_key( wp_unslash( $_GET['application_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = array_key_exists( $status, Applications::workflow_statuses() ) ? $status : '';
		$job_id = absint( $_GET['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		if ( $job_id && ! Ownership::user_can_manage_job( $job_id ) ) {
			$job_id = 0;
		}
		return array(
			'application_id' => absint( $_GET['application_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail selection.
			'job_id'         => $job_id,
			'status'         => $status,
			'search'         => sanitize_text_field( wp_unslash( $_GET['application_search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
			'page'           => max( 1, absint( $_GET['applications_page'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		);
	}

	private static function selected_application( $application_id ) {
		if ( ! $application_id || ! Ownership::user_can_access_application( $application_id, Capabilities::VIEW_APPLICATIONS ) ) {
			return null;
		}
		return Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $application_id );
	}

	private static function results_label( array $query ) {
		if ( ! $query['total'] ) {
			return __( 'No matching applications', 'llamahire' );
		}
		$first = ( ( $query['page'] - 1 ) * $query['per_page'] ) + 1;
		$last  = min( $query['total'], $first + count( $query['items'] ) - 1 );
		/* translators: 1: first visible application number, 2: last visible application number, 3: total applications. */
		return sprintf( __( 'Showing %1$s–%2$s of %3$s applications', 'llamahire' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $query['total'] ) );
	}

	private static function render_list( array $query, array $state ) {
		if ( ! $query['items'] ) {
			echo '<p>' . esc_html__( 'No candidates match these filters.', 'llamahire' ) . '</p>';
			return;
		}
		?>
		<div class="llamahire-employer-applications__table"><table><thead><tr><th><?php esc_html_e( 'Candidate', 'llamahire' ); ?></th><th><?php esc_html_e( 'Job', 'llamahire' ); ?></th><th><?php esc_html_e( 'Status', 'llamahire' ); ?></th><th><?php esc_html_e( 'Received', 'llamahire' ); ?></th></tr></thead><tbody>
		<?php foreach ( $query['items'] as $item ) : ?><tr><td><a href="<?php echo esc_url( self::url( array( 'application_id' => $item->id ) ) ); ?>"><strong><?php echo esc_html( $item->name ); ?></strong></a><small><a href="mailto:<?php echo esc_attr( $item->email ); ?>"><?php echo esc_html( $item->email ); ?></a></small></td><td><?php echo esc_html( $item->job_title ); ?></td><td><?php echo esc_html( Applications::workflow_statuses()[ $item->status ] ?? ucfirst( $item->status ) ); ?></td><td><?php echo esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php self::render_pagination( $query, $state );
	}

	private static function render_detail( $application ) {
		$notes    = Application_Notes::for_application( $application->id, 20 );
		$activity = Audit_Log::search( array( 'application_id' => $application->id, 'per_page' => 20 ) );
		?>
		<section class="llamahire-employer-application-review" aria-labelledby="llamahire-employer-application-title">
			<div class="llamahire-employer-application-review__top"><div><p class="llamahire-employer-application-review__eyebrow"><?php echo esc_html( get_the_title( $application->job_id ) ); ?></p><h3 id="llamahire-employer-application-title"><?php echo esc_html( $application->name ); ?></h3><p><a href="mailto:<?php echo esc_attr( $application->email ); ?>"><?php echo esc_html( $application->email ); ?></a><?php if ( $application->phone ) : ?> · <?php echo esc_html( $application->phone ); ?><?php endif; ?></p></div><a href="<?php echo esc_url( self::url( array( 'job_id' => $application->job_id ) ) ); ?>"><?php esc_html_e( 'Close review', 'llamahire' ); ?></a></div>
			<div class="llamahire-employer-application-review__summary">
				<form method="post"><input type="hidden" name="llamahire_employer_application_action" value="save_status"><input type="hidden" name="application_id" value="<?php echo esc_attr( $application->id ); ?>"><?php wp_nonce_field( 'llamahire_employer_application_save_status_' . $application->id, 'llamahire_employer_application_nonce' ); ?><label><span><?php esc_html_e( 'Status', 'llamahire' ); ?></span><select name="status"><?php foreach ( Applications::workflow_statuses() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $application->status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button type="submit"><?php esc_html_e( 'Save status', 'llamahire' ); ?></button></form>
				<div><h4><?php esc_html_e( 'Latest activity', 'llamahire' ); ?></h4><?php if ( $activity['items'] ) : $latest = $activity['items'][0]; ?><p><?php echo esc_html( Audit_Log::describe( $latest ) ); ?><small><?php echo esc_html( get_date_from_gmt( $latest->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small></p><?php else : ?><p><?php esc_html_e( 'No activity recorded yet.', 'llamahire' ); ?></p><?php endif; ?></div>
			</div>
			<div class="llamahire-employer-application-review__columns">
				<section><h4><?php esc_html_e( 'Files and materials', 'llamahire' ); ?></h4><?php if ( $application->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) : ?><p><strong><?php echo esc_html( $application->resume_name ); ?></strong><br><?php if ( Applications::resume_is_previewable( $application->resume_name ) ) : ?><a href="<?php echo esc_url( Applications::resume_url( $application->id, true ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'llamahire' ); ?></a> · <?php endif; ?><a href="<?php echo esc_url( Applications::resume_url( $application->id ) ); ?>"><?php esc_html_e( 'Download', 'llamahire' ); ?></a></p><?php endif; ?><?php if ( $application->cover_letter ) : ?><details><summary><?php esc_html_e( 'View cover letter', 'llamahire' ); ?></summary><p class="llamahire-employer-application-review__letter"><?php echo esc_html( $application->cover_letter ); ?></p></details><?php endif; ?><?php if ( ! $application->has_resume && ! $application->cover_letter ) : ?><p><?php esc_html_e( 'No files or cover letter were provided.', 'llamahire' ); ?></p><?php endif; ?></section>
				<section><h4><?php esc_html_e( 'Private notes', 'llamahire' ); ?></h4><form method="post"><input type="hidden" name="llamahire_employer_application_action" value="add_note"><input type="hidden" name="application_id" value="<?php echo esc_attr( $application->id ); ?>"><?php wp_nonce_field( 'llamahire_employer_application_add_note_' . $application->id, 'llamahire_employer_application_nonce' ); ?><label><span><?php esc_html_e( 'Add note', 'llamahire' ); ?></span><textarea name="note" maxlength="<?php echo esc_attr( Application_Notes::MAX_LENGTH ); ?>" required></textarea></label><button type="submit"><?php esc_html_e( 'Add note', 'llamahire' ); ?></button></form><?php if ( $notes ) : ?><ol class="llamahire-employer-application-review__notes"><?php foreach ( $notes as $note ) : ?><li><p><?php echo esc_html( $note->body ); ?></p><small><?php echo esc_html( Application_Notes::author_label( $note ) . ' · ' . get_date_from_gmt( $note->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small></li><?php endforeach; ?></ol><?php else : ?><p><?php esc_html_e( 'No private notes yet.', 'llamahire' ); ?></p><?php endif; ?></section>
			</div>
			<?php if ( count( $activity['items'] ) > 1 ) : ?><details class="llamahire-employer-application-review__activity"><summary><?php esc_html_e( 'View all activity', 'llamahire' ); ?></summary><ol><?php foreach ( $activity['items'] as $event ) : ?><li><?php echo esc_html( Audit_Log::describe( $event ) ); ?> <small><?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></small></li><?php endforeach; ?></ol></details><?php endif; ?>
		</section>
		<?php
	}

	private static function render_pagination( array $query, array $state ) {
		if ( 2 > $query['pages'] ) {
			return;
		}
		$arguments = array_filter( array( 'job_id' => $state['job_id'], 'application_status' => $state['status'], 'application_search' => $state['search'] ) );
		$base = str_replace( '999999999', '%#%', add_query_arg( array_merge( $arguments, array( 'employer_view' => 'applications', 'applications_page' => 999999999 ) ), Employer_Portal::my_jobs_url() ) );
		$links = paginate_links( array( 'base' => $base, 'current' => $query['page'], 'total' => $query['pages'], 'type' => 'list', 'prev_text' => __( 'Previous', 'llamahire' ), 'next_text' => __( 'Next', 'llamahire' ) ) );
		if ( $links ) {
			echo '<nav class="llamahire-pagination" aria-label="' . esc_attr__( 'Application pages', 'llamahire' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	private function __construct() {}
}
