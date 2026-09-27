<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Mode-aware operations dashboard and focused candidate pipeline.
 */
final class Admin_Workspaces {
	public static function hiring_available() {
		if ( ! current_user_can( Capabilities::MANAGE_APPLICATIONS ) ) {
			return false;
		}
		return Settings::SITE_MODE_COMPANY === Settings::site_mode() || 0 < Ownership::current_author_scope();
	}

	public static function enqueue_assets() {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen selection.
		if ( ! in_array( $page, array( 'llamahire-dashboard', 'llamahire-hiring' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'llamahire-admin-workspaces', LLAMAHIRE_URL . 'assets/css/admin-workspaces.css', array( 'llamahire-admin-tokens' ), LLAMAHIRE_VERSION );
		if ( 'llamahire-hiring' === $page ) {
			wp_enqueue_script( 'llamahire-admin-hiring', LLAMAHIRE_URL . 'assets/js/admin-hiring.js', array(), LLAMAHIRE_VERSION, true );
			wp_localize_script(
				'llamahire-admin-hiring',
				'llamahireHiring',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'llamahire_move_application' ),
					'errorMessage' => __( 'The candidate could not be moved. Please try again.', 'llamahire' ),
					/* translators: %s: Destination hiring stage. */
					'movedMessage' => __( 'Candidate moved to %s.', 'llamahire' ),
				)
			);
		}
	}

	public static function move_application_ajax() {
		check_ajax_referer( 'llamahire_move_application', 'nonce' );
		$application_id = absint( $_POST['application'] ?? 0 );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! Ownership::user_can_access_application( $application_id, Capabilities::MANAGE_APPLICATIONS ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot move this candidate.', 'llamahire' ) ), 403 );
		}
		if ( ! array_key_exists( $status, Applications::workflow_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'That hiring stage is not valid.', 'llamahire' ) ), 400 );
		}
		$updated = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->update( $application_id, array( 'status' => $status ) );
		if ( is_wp_error( $updated ) || ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'The candidate could not be moved.', 'llamahire' ) ), 500 );
		}
		wp_send_json_success( array( 'status' => $status, 'label' => Applications::status_label( $status ) ) );
	}

	public static function hiring_url( array $arguments = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Jobs::POST_TYPE,
					'page'      => 'llamahire-hiring',
				),
				$arguments
			),
			admin_url( 'edit.php' )
		);
	}

	public static function render_dashboard() {
		$scope       = Ownership::query_arguments();
		$author_id   = absint( $scope['author_id'] ?? 0 );
		$board_mode  = Settings::SITE_MODE_JOB_BOARD === Settings::site_mode();
		$is_operator = $board_mode && ! $author_id;
		if ( ! $is_operator ) {
			self::render_company_dashboard( $scope );
			return;
		}
		$query       = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY );
		$counts      = $query->counts( $scope );
		$open_count  = Jobs::open_count( $author_id );
		$jobs        = self::dashboard_jobs( $author_id );
		$job_ids     = wp_list_pluck( $jobs, 'ID' );
		$job_counts  = $query->counts_by_job_and_status( $job_ids, $scope );
		$pending     = self::job_count( 'pending', $author_id );
		$pending_employers = $is_operator ? Employer_Registration::pending_count() : 0;
		$expiring    = self::expiring_job_count( $author_id );
		$activity    = Audit_Log::search( array_merge( array( 'per_page' => 5 ), $scope ) );
		$title       = $is_operator ? __( 'Job board dashboard', 'llamahire' ) : __( 'Hiring dashboard', 'llamahire' );
		$intro       = $is_operator
			? __( 'Keep listings moving, resolve issues, and see what needs attention across your community.', 'llamahire' )
			: __( 'See what needs attention across your jobs and applications, then jump into hiring when you need the full pipeline.', 'llamahire' );
		$jobs_url    = admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE );
		$open_jobs_url = add_query_arg( 'llamahire_job_state', 'open', $jobs_url );
		?>
		<div class="wrap llamahire-workspace llamahire-dashboard">
			<div class="llamahire-page-header">
				<div><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $intro ); ?></p></div>
				<div class="llamahire-header-actions">
					<?php if ( self::hiring_available() ) : ?><a class="button" href="<?php echo esc_url( self::hiring_url() ); ?>"><?php esc_html_e( 'Open Hiring', 'llamahire' ); ?></a><?php endif; ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Jobs::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add job', 'llamahire' ); ?></a>
				</div>
			</div>

			<section class="llamahire-attention" aria-labelledby="llamahire-attention-title">
				<div class="llamahire-section-heading"><div><h2 id="llamahire-attention-title"><?php esc_html_e( 'Needs your attention', 'llamahire' ); ?></h2><p><?php esc_html_e( 'The most useful work to pick up next.', 'llamahire' ); ?></p></div></div>
				<div class="llamahire-attention-list">
					<?php
					if ( $is_operator ) {
						/* translators: %d: Number of employer accounts awaiting approval. */
						self::attention_row( 'businessperson', sprintf( _n( '%d employer account is awaiting approval', '%d employer accounts are awaiting approval', $pending_employers, 'llamahire' ), $pending_employers ), __( 'Review verified employers before they can submit job listings.', 'llamahire' ), $pending_employers, Employer_Registration::pending_url(), __( 'Review employers', 'llamahire' ) );
						/* translators: %d: Number of submitted job listings awaiting review. */
						self::attention_row( 'flag', sprintf( _n( '%d job listing is waiting for review', '%d job listings are waiting for review', $pending, 'llamahire' ), $pending ), __( 'Moderate submitted jobs before they appear publicly.', 'llamahire' ), $pending, add_query_arg( array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'pending' ), admin_url( 'edit.php' ) ), __( 'Review listings', 'llamahire' ) );
					} else {
						/* translators: %d: Number of new applications. */
						self::attention_row( 'groups', sprintf( _n( '%d new application', '%d new applications', $counts['new'], 'llamahire' ), $counts['new'] ), __( 'Candidates are waiting for an initial review.', 'llamahire' ), $counts['new'], self::hiring_available() ? self::hiring_url() : Admin::applications_url( array( 'status' => 'new' ) ), __( 'Review candidates', 'llamahire' ) );
					}
					/* translators: %d: Number of notification emails needing attention. */
					self::attention_row( 'email-alt', sprintf( _n( '%d email needs attention', '%d emails need attention', $counts['notification_attention'], 'llamahire' ), $counts['notification_attention'] ), __( 'A candidate or employer notification may not have arrived.', 'llamahire' ), $counts['notification_attention'], Admin::applications_url( array( 'notification_statuses' => 'pending,partial,failed' ) ), __( 'Check email issues', 'llamahire' ) );
					/* translators: %d: Number of jobs closing soon. */
					self::attention_row( 'calendar-alt', sprintf( _n( '%d job closes soon', '%d jobs close soon', $expiring, 'llamahire' ), $expiring ), __( 'These listings reach their application deadline or listing expiration in the next seven days.', 'llamahire' ), $expiring, add_query_arg( 'llamahire_job_state', 'closing-soon', $jobs_url ), __( 'Review jobs', 'llamahire' ) );
					?>
				</div>
			</section>

			<section aria-labelledby="llamahire-summary-title">
				<div class="llamahire-section-heading"><div><h2 id="llamahire-summary-title"><?php echo esc_html( $is_operator ? __( 'Board at a glance', 'llamahire' ) : __( 'Hiring at a glance', 'llamahire' ) ); ?></h2></div></div>
				<div class="llamahire-metrics">
					<?php
					self::metric( $open_count, $is_operator ? __( 'Live listings', 'llamahire' ) : __( 'Open jobs', 'llamahire' ), 'portfolio', $open_jobs_url );
					self::metric(
						$is_operator ? $pending : $counts['new'],
						$is_operator ? __( 'Awaiting review', 'llamahire' ) : __( 'New candidates', 'llamahire' ),
						'visibility',
						$is_operator
							? add_query_arg( 'post_status', 'pending', $jobs_url )
							: Admin::applications_url( array( 'status' => 'new' ) )
					);
					if ( $is_operator ) {
						self::metric( self::active_employer_count(), __( 'Active employers', 'llamahire' ), 'businessperson', admin_url( 'users.php?role=' . Capabilities::EMPLOYER_ROLE ) );
						self::metric( array_sum( array_intersect_key( $counts, Applications::workflow_statuses() ) ), __( 'Applications', 'llamahire' ), 'groups', Admin::applications_url() );
					} else {
						self::metric( $counts['interviewing'], __( 'Interviewing', 'llamahire' ), 'format-chat', Admin::applications_url( array( 'status' => 'interviewing' ) ) );
						self::metric( $counts['offer'], __( 'Offers', 'llamahire' ), 'awards', Admin::applications_url( array( 'status' => 'offer' ) ) );
					}
					?>
				</div>
			</section>

			<div class="llamahire-dashboard-grid">
				<section class="llamahire-panel" aria-labelledby="llamahire-active-jobs-title">
					<div class="llamahire-section-heading"><div><h2 id="llamahire-active-jobs-title"><?php echo esc_html( $is_operator ? __( 'Active listings', 'llamahire' ) : __( 'Active jobs', 'llamahire' ) ); ?></h2><p><?php esc_html_e( 'A quick view of demand and candidate flow.', 'llamahire' ); ?></p></div><a href="<?php echo esc_url( $jobs_url ); ?>"><?php esc_html_e( 'View all listings', 'llamahire' ); ?></a></div>
					<?php if ( $jobs ) : ?>
						<div class="llamahire-job-list"><?php foreach ( $jobs as $job ) : $meta = Jobs::get_meta( $job->ID ); $per_status = $job_counts[ $job->ID ] ?? array(); ?>
							<article class="llamahire-job-row">
								<div><h3><a href="<?php echo esc_url( get_edit_post_link( $job->ID ) ); ?>"><?php echo esc_html( $job->post_title ); ?></a></h3><p><?php echo esc_html( self::job_context( $job, $meta, $is_operator ) ); ?></p></div>
								<?php $application_count = array_sum( $per_status ); ?>
								<a class="llamahire-job-application-count" href="<?php echo esc_url( Admin::applications_url( array( 'job_id' => $job->ID ) ) ); ?>">
									<strong><?php echo esc_html( $application_count ); ?></strong>
									<span><?php echo esc_html( _n( 'application', 'applications', $application_count, 'llamahire' ) ); ?></span>
								</a>
							</article>
						<?php endforeach; ?></div>
					<?php else : ?><div class="llamahire-empty"><span class="dashicons dashicons-portfolio"></span><h3><?php esc_html_e( 'No active jobs yet', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Publish a job to start tracking activity here.', 'llamahire' ); ?></p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Jobs::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add your first job', 'llamahire' ); ?></a></div><?php endif; ?>
				</section>

				<section class="llamahire-panel" aria-labelledby="llamahire-activity-title">
					<div class="llamahire-section-heading"><div><h2 id="llamahire-activity-title"><?php esc_html_e( 'Recent activity', 'llamahire' ); ?></h2><p><?php esc_html_e( 'Operational changes across your workspace.', 'llamahire' ); ?></p></div><a href="<?php echo esc_url( add_query_arg( array( 'post_type' => Jobs::POST_TYPE, 'page' => 'llamahire-activity' ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'View all activity', 'llamahire' ); ?></a></div>
					<?php if ( $activity['items'] ) : ?><ol class="llamahire-activity-list"><?php foreach ( $activity['items'] as $event ) : ?>
						<?php
						$activity_url = self::activity_url( $event );
						$activity_job_title = $event->job_title ?: sprintf(
							/* translators: %d is the numeric WordPress job post ID. */
							__( 'Job #%d', 'llamahire' ),
							$event->job_id
						);
						?>
						<li><span class="llamahire-activity-dot" aria-hidden="true"></span><?php if ( $activity_url ) : ?><a class="llamahire-activity-link" href="<?php echo esc_url( $activity_url ); ?>"><?php else : ?><div><?php endif; ?><strong><?php echo esc_html( Audit_Log::describe( $event ) ); ?></strong><p><?php echo esc_html( $activity_job_title ); ?> · <?php echo esc_html( human_time_diff( strtotime( $event->created_at . ' UTC' ), current_time( 'timestamp', true ) ) ); ?> <?php esc_html_e( 'ago', 'llamahire' ); ?></p><?php if ( $activity_url ) : ?></a><?php else : ?></div><?php endif; ?></li>
					<?php endforeach; ?></ol><?php else : ?><div class="llamahire-empty llamahire-empty--compact"><p><?php esc_html_e( 'Activity will appear as jobs and candidates move forward.', 'llamahire' ); ?></p></div><?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	private static function render_company_dashboard( array $scope ) {
		$query = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY );
		$counts = $query->counts( $scope );
		$author_id = absint( $scope['author_id'] ?? 0 );
		$can_hire = self::hiring_available();
		$hiring_url = $can_hire ? self::hiring_url() : Admin::applications_url();
		$new_url = $can_hire ? self::hiring_url() . '#llamahire-stage-new' : Admin::applications_url( array( 'status' => 'new' ) );
		$seven_days_ago = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$recent_count = $query->search( array_merge( $scope, array( 'received_after' => $seven_days_ago, 'per_page' => 1 ) ) )['total'];
		$send_issues = $query->search( array_merge( $scope, array( 'notification_statuses' => array( 'partial', 'failed' ), 'per_page' => 1 ) ) )['total'];
		$aged_count = $query->search( array_merge( $scope, array( 'statuses' => array( 'new', 'reviewing', 'interviewing', 'offer' ), 'stage_changed_before' => $seven_days_ago, 'per_page' => 1 ) ) )['total'];
		$oldest_new = $counts['new'] ? $query->search( array_merge( $scope, array( 'status' => 'new', 'orderby' => 'received', 'order' => 'asc', 'per_page' => 1 ) ) )['items'][0] ?? null : null;
		$closing_count = self::expiring_job_count( $author_id );
		$jobs = self::dashboard_jobs_to_watch( $author_id, $query, $scope );
		$jobs_url = admin_url( 'edit.php?post_type=' . Jobs::POST_TYPE );
		$has_attention = $counts['new'] || $closing_count || $send_issues;
		?>
		<div class="wrap llamahire-workspace llamahire-dashboard llamahire-dashboard--company">
			<div class="llamahire-page-header">
				<div><h1><?php esc_html_e( 'Hiring dashboard', 'llamahire' ); ?></h1><p><?php esc_html_e( 'See what needs attention across your jobs and candidates.', 'llamahire' ); ?></p></div>
				<div class="llamahire-header-actions"><a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Jobs::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add job', 'llamahire' ); ?></a><a class="button button-primary" href="<?php echo esc_url( $counts['new'] ? $new_url : $hiring_url ); ?>"><?php echo esc_html( ! $can_hire ? __( 'View applications', 'llamahire' ) : ( $counts['new'] ? __( 'Review new candidates', 'llamahire' ) : __( 'Open Hiring', 'llamahire' ) ) ); ?></a></div>
			</div>
			<section class="llamahire-dashboard-tasks" aria-labelledby="llamahire-dashboard-tasks-title">
				<div class="llamahire-section-heading"><h2 id="llamahire-dashboard-tasks-title"><?php esc_html_e( 'Needs attention', 'llamahire' ); ?></h2></div>
				<?php if ( $has_attention ) : ?>
					<?php if ( $counts['new'] ) : ?>
						<div class="llamahire-dashboard-task"><span class="llamahire-dashboard-task-count"><?php echo esc_html( $counts['new'] ); ?></span><div><h3><?php esc_html_e( 'New candidates awaiting review', 'llamahire' ); ?></h3><?php if ( $oldest_new ) : ?><p><?php
							/* translators: %s: Time since the oldest new application was received. */
							printf( esc_html__( 'Oldest application: %s ago', 'llamahire' ), esc_html( human_time_diff( strtotime( $oldest_new->created_at . ' UTC' ), current_time( 'timestamp', true ) ) ) );
						?></p><?php endif; ?></div><a href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Review candidates', 'llamahire' ); ?> <span aria-hidden="true">→</span></a></div>
					<?php endif; ?>
					<?php if ( $closing_count ) : ?>
						<div class="llamahire-dashboard-task"><span class="llamahire-dashboard-task-count"><?php echo esc_html( $closing_count ); ?></span><div><h3><?php esc_html_e( 'Jobs closing within 7 days', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Review deadlines and candidate activity.', 'llamahire' ); ?></p></div><a href="<?php echo esc_url( add_query_arg( 'llamahire_job_state', 'closing-soon', $jobs_url ) ); ?>"><?php esc_html_e( 'Review jobs', 'llamahire' ); ?> <span aria-hidden="true">→</span></a></div>
					<?php endif; ?>
					<?php if ( $send_issues ) : ?>
						<div class="llamahire-dashboard-task is-error"><span class="llamahire-dashboard-task-count"><?php echo esc_html( $send_issues ); ?></span><div><h3><?php esc_html_e( 'Notification send issues', 'llamahire' ); ?></h3><p><?php esc_html_e( 'Failed or partial send attempts need a check.', 'llamahire' ); ?></p></div><a href="<?php echo esc_url( Admin::applications_url( array( 'notification_statuses' => 'partial,failed' ) ) ); ?>"><?php esc_html_e( 'Check issues', 'llamahire' ); ?> <span aria-hidden="true">→</span></a></div>
					<?php endif; ?>
				<?php else : ?><p class="llamahire-dashboard-clear"><?php esc_html_e( 'You are caught up. No new candidates, closing jobs, or notification send issues need attention.', 'llamahire' ); ?></p><?php endif; ?>
			</section>
			<div class="llamahire-dashboard-company-grid">
				<section class="llamahire-dashboard-pulse" aria-labelledby="llamahire-dashboard-pulse-title"><div class="llamahire-section-heading"><h2 id="llamahire-dashboard-pulse-title"><?php esc_html_e( 'Hiring pulse', 'llamahire' ); ?></h2><a href="<?php echo esc_url( $hiring_url ); ?>"><?php echo esc_html( $can_hire ? __( 'Open Hiring', 'llamahire' ) : __( 'View applications', 'llamahire' ) ); ?> <span aria-hidden="true">→</span></a></div><div class="llamahire-dashboard-pulse-stats"><div><strong><?php echo esc_html( $recent_count ); ?></strong><span><?php esc_html_e( 'Applications in the last 7 days', 'llamahire' ); ?></span></div><div><strong><?php echo esc_html( $aged_count ); ?></strong><span><?php esc_html_e( 'Candidates in a stage for 7+ days', 'llamahire' ); ?></span></div></div></section>
				<section class="llamahire-dashboard-watch" aria-labelledby="llamahire-dashboard-watch-title"><div class="llamahire-section-heading"><h2 id="llamahire-dashboard-watch-title"><?php esc_html_e( 'Jobs to watch', 'llamahire' ); ?></h2><a href="<?php echo esc_url( $jobs_url ); ?>"><?php esc_html_e( 'View jobs', 'llamahire' ); ?> <span aria-hidden="true">→</span></a></div><?php if ( $jobs ) : ?><?php foreach ( $jobs as $item ) : ?><div class="llamahire-dashboard-watch-job"><div><h3><?php echo esc_html( get_the_title( $item['id'] ) ); ?></h3><p><?php echo esc_html( $item['summary'] ); ?></p></div><a href="<?php echo esc_url( $can_hire ? self::hiring_url( array( 'job_id' => $item['id'] ) ) : Admin::applications_url( array( 'job_id' => $item['id'] ) ) ); ?>"><?php esc_html_e( 'Review', 'llamahire' ); ?> <span aria-hidden="true">→</span></a></div><?php endforeach; ?><?php else : ?><p class="llamahire-dashboard-clear"><?php esc_html_e( 'No jobs need attention right now.', 'llamahire' ); ?></p><?php endif; ?></section>
			</div>
		</div>
		<?php
	}

	public static function render_hiring() {
		if ( ! self::hiring_available() ) {
			wp_die( esc_html__( 'The Hiring workspace is available to company hiring teams and employers managing their own jobs.', 'llamahire' ), 403 );
		}
		$scope     = Ownership::query_arguments();
		$search    = sanitize_text_field( wp_unslash( $_GET['candidate'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only hiring workspace filter.
		$job_token = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only hiring workspace filter.
		$show_all  = '' === $job_token || 'all' === $job_token;
		$job_id    = $show_all ? 0 : absint( $job_token );
		$jobs      = Admin::application_filter_jobs( absint( $scope['author_id'] ?? 0 ) );
		$query     = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY );
		$job_argument = $show_all ? 'all' : $job_id;
		$page = max( 1, absint( $_GET['hiring_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination; the query retains the ownership scope.
		$arguments = array_merge( $scope, array( 'job_id' => $job_id, 'candidate' => $search, 'statuses' => array_keys( Applications::pipeline_statuses() ), 'per_page' => 100, 'orderby' => 'received', 'order' => 'desc', 'page' => $page ) );
		$result = $query->search( $arguments );
		if ( $page > $result['pages'] ) {
			$page = $result['pages'];
			$arguments['page'] = $page;
			$result = $query->search( $arguments );
		}
		$counts = $query->counts( $arguments );
		$columns = array_fill_keys( array_keys( Applications::pipeline_statuses() ), array() );
		foreach ( $result['items'] as $candidate ) {
			$columns[ $candidate->status ][] = $candidate;
		}
		$has_filters = ! $show_all || '' !== $search;
		$selected_id = absint( $_GET['application'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection; ownership is checked immediately below.
		$selected = $selected_id && Ownership::user_can_access_application( $selected_id, Capabilities::VIEW_APPLICATIONS )
			? Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY )->find( $selected_id )
			: null;
		?>
		<div class="wrap llamahire-workspace llamahire-hiring<?php echo $selected ? ' has-drawer' : ''; ?>">
			<div class="llamahire-page-header">
				<div><h1><?php esc_html_e( 'Hiring', 'llamahire' ); ?></h1><p><?php esc_html_e( 'Move candidates through your hiring process.', 'llamahire' ); ?></p></div>
				<div class="llamahire-header-actions"><a class="button" href="<?php echo esc_url( Admin::applications_url( array( 'job_id' => $job_id ) ) ); ?>"><?php esc_html_e( 'View applications', 'llamahire' ); ?></a></div>
			</div>
			<form class="llamahire-hiring-filters" method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Jobs::POST_TYPE ); ?>"><input type="hidden" name="page" value="llamahire-hiring">
				<label class="llamahire-candidate-search"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Search candidates', 'llamahire' ); ?></span><input type="search" name="candidate" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search candidates', 'llamahire' ); ?>"></label>
				<label class="llamahire-job-filter"><span class="screen-reader-text"><?php esc_html_e( 'Job title', 'llamahire' ); ?></span><select name="job_id"><option value="all" <?php selected( $show_all ); ?>><?php esc_html_e( 'All job titles', 'llamahire' ); ?></option><?php foreach ( $jobs as $job ) : ?><option value="<?php echo esc_attr( $job->ID ); ?>" <?php selected( $job_id, $job->ID ); ?>><?php echo esc_html( Admin::application_filter_job_label( $job ) ); ?></option><?php endforeach; ?></select></label>
				<button class="button llamahire-filter-button"><?php esc_html_e( 'Apply', 'llamahire' ); ?></button>
			</form>
			<div class="llamahire-drag-help"><span class="dashicons dashicons-move"></span><?php esc_html_e( 'Drag candidates between stages.', 'llamahire' ); ?></div>
			<div class="llamahire-hiring-toast" role="status" aria-live="polite" hidden></div>
			<?php self::hiring_pagination( $result, $job_argument, $search ); ?>
			<div class="llamahire-pipeline-layout">
				<?php if ( $has_filters && ! $result['items'] ) : ?>
					<div class="llamahire-empty llamahire-hiring-empty">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<h2><?php esc_html_e( 'No candidates match these filters.', 'llamahire' ); ?></h2>
						<a class="button" href="<?php echo esc_url( self::hiring_url() ); ?>"><?php esc_html_e( 'Clear filters', 'llamahire' ); ?></a>
					</div>
				<?php else : ?>
					<div class="llamahire-pipeline" data-hiring-pipeline aria-label="<?php esc_attr_e( 'Candidate pipeline', 'llamahire' ); ?>">
						<?php foreach ( Applications::pipeline_statuses() as $status => $label ) : ?>
							<section id="llamahire-stage-<?php echo esc_attr( $status ); ?>" class="llamahire-stage llamahire-stage--<?php echo esc_attr( $status ); ?>" data-stage="<?php echo esc_attr( $status ); ?>">
								<header><h2><?php echo esc_html( $label ); ?></h2><span data-stage-count><?php echo esc_html( $counts[ $status ] ); ?></span></header>
								<div class="llamahire-stage-cards" data-stage-cards>
									<?php if ( $columns[ $status ] ) : foreach ( $columns[ $status ] as $candidate ) : self::candidate_card( $candidate, $job_argument, $search, $selected ? (int) $selected->id : 0, $page ); endforeach; else : ?>
										<div class="llamahire-stage-empty" data-stage-empty><span class="dashicons <?php echo 'hired' === $status ? 'dashicons-yes' : 'dashicons-admin-users'; ?>"></span><strong><?php echo $counts[ $status ] ? esc_html__( 'No candidates on this page', 'llamahire' ) : esc_html__( 'No candidates', 'llamahire' ); ?></strong><p><?php echo esc_html( $counts[ $status ] ? __( 'Use the page controls to see more candidates.', 'llamahire' ) : self::empty_stage_copy( $status ) ); ?></p></div>
									<?php endif; ?>
								</div>
							</section>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( $selected ) : self::candidate_drawer( $selected, $job_argument, $search, $page ); endif; ?>
			</div>
		</div>
		<?php
	}

	private static function hiring_pagination( array $result, $job_argument, $search ) {
		if ( $result['pages'] < 2 ) {
			return;
		}
		$page = (int) $result['page'];
		$args = array_filter( array( 'job_id' => $job_argument, 'candidate' => $search ) );
		$first = ( $page - 1 ) * $result['per_page'] + 1;
		$last = min( $result['total'], $first + count( $result['items'] ) - 1 );
		?>
		<nav class="llamahire-hiring-pagination" aria-label="<?php esc_attr_e( 'Candidate pages', 'llamahire' ); ?>">
			<p><?php
					printf(
						/* translators: 1: First visible candidate, 2: Last visible candidate, 3: Total matching candidates. */
						esc_html__( 'Showing %1$s–%2$s of %3$s candidates. Stage totals include all matching candidates.', 'llamahire' ),
						esc_html( number_format_i18n( $first ) ),
						esc_html( number_format_i18n( $last ) ),
						esc_html( number_format_i18n( $result['total'] ) )
					);
			?></p>
			<div class="llamahire-header-actions">
				<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( self::hiring_url( array_merge( $args, array( 'hiring_page' => $page - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'llamahire' ); ?></a><?php endif; ?>
				<?php if ( $page < $result['pages'] ) : ?><a class="button" href="<?php echo esc_url( self::hiring_url( array_merge( $args, array( 'hiring_page' => $page + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'llamahire' ); ?></a><?php endif; ?>
			</div>
		</nav>
		<?php
	}

	private static function candidate_card( $candidate, $job_argument, $search, $selected_id, $page ) {
		$url = self::hiring_url( array_filter( array( 'job_id' => $job_argument, 'candidate' => $search, 'application' => $candidate->id, 'hiring_page' => $page ) ) );
		$applied_label = sprintf(
			/* translators: %s: Date, and optionally time, the candidate applied. */
			__( 'Applied %s', 'llamahire' ),
			get_date_from_gmt( $candidate->created_at, get_option( 'date_format' ) )
		);
		$stage_time_label = sprintf(
			/* translators: %s: Human-readable time in the current hiring stage. */
			__( '%s in stage', 'llamahire' ),
			self::time_in_stage( $candidate )
		);
		?>
		<article class="llamahire-candidate-card<?php echo (int) $selected_id === (int) $candidate->id ? ' is-selected' : ''; ?>" draggable="true" data-candidate-id="<?php echo esc_attr( $candidate->id ); ?>" data-candidate-status="<?php echo esc_attr( $candidate->status ); ?>">
			<a class="llamahire-candidate-main" href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $candidate->name ); ?></strong><small><?php echo esc_html( $candidate->job_title ); ?></small><small><?php echo esc_html( $applied_label ); ?></small><small><span class="dashicons dashicons-clock"></span><?php echo esc_html( $stage_time_label ); ?></small></a>
			<span class="llamahire-card-menu dashicons dashicons-move" title="<?php esc_attr_e( 'Drag candidate', 'llamahire' ); ?>" aria-hidden="true"></span>
			<footer><span class="llamahire-avatar"><?php echo esc_html( self::initials( $candidate->name ) ); ?></span><?php if ( ! empty( $candidate->has_notes ) ) : ?><span class="screen-reader-text"><?php esc_html_e( 'Has a private note', 'llamahire' ); ?></span><?php endif; ?></footer>
		</article>
		<?php
	}

	private static function candidate_drawer( $candidate, $job_argument, $search, $page ) {
		$return_url = self::hiring_url( array_filter( array( 'job_id' => $job_argument, 'candidate' => $search, 'application' => $candidate->id, 'updated' => 1, 'hiring_page' => $page ) ) );
		$note_return_url = self::hiring_url( array_filter( array( 'job_id' => $job_argument, 'candidate' => $search, 'application' => $candidate->id, 'hiring_page' => $page ) ) );
		$history = Audit_Log::search( array( 'application_id' => $candidate->id, 'per_page' => 5 ) );
		$private_notes = Application_Notes::for_application( $candidate->id, 10 );
		$close_url = self::hiring_url( array_filter( array( 'job_id' => $job_argument, 'candidate' => $search, 'hiring_page' => $page ) ) );
		$next_status = self::next_pipeline_status( $candidate->status );
		$next_status_label = '';
		if ( $next_status ) {
			$next_status_label = sprintf(
				/* translators: %s: Destination hiring stage. */
				__( 'Move to %s', 'llamahire' ),
				Applications::status_label( $next_status )
			);
		}
		$updated = ! empty( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		$note_added = ! empty( $_GET['note_added'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice for a previously nonce-protected action.
		$cover_letter_preview = $candidate->cover_letter ? wp_trim_words( $candidate->cover_letter, 34, '…' ) : '';
		$has_long_cover_letter = $candidate->cover_letter && $cover_letter_preview !== $candidate->cover_letter;
		?>
		<aside class="llamahire-candidate-drawer" aria-labelledby="llamahire-candidate-title">
			<a href="<?php echo esc_url( $close_url ); ?>" class="llamahire-drawer-close" aria-label="<?php esc_attr_e( 'Close candidate details', 'llamahire' ); ?>"><span class="dashicons dashicons-no-alt"></span></a>
			<div class="llamahire-candidate-heading"><div><h2 id="llamahire-candidate-title"><?php echo esc_html( $candidate->name ); ?></h2><p><?php echo esc_html( get_the_title( $candidate->job_id ) ); ?> · <?php
				printf(
					/* translators: %s: Date, and optionally time, the candidate applied. */
					esc_html__( 'Applied %s', 'llamahire' ),
					esc_html( get_date_from_gmt( $candidate->created_at, get_option( 'date_format' ) ) )
				);
			?></p></div></div>
			<p class="llamahire-drawer-stage"><span><?php echo esc_html( Applications::status_label( $candidate->status ) ); ?></span><?php
				printf(
					/* translators: %s: Human-readable time in the current hiring stage. */
					esc_html__( '%s in stage', 'llamahire' ),
					esc_html( self::time_in_stage( $candidate ) )
				);
			?></p>
			<?php if ( $updated ) : ?><div class="llamahire-save-confirmation" role="status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><?php esc_html_e( 'Changes saved.', 'llamahire' ); ?></span></div><?php endif; ?>
			<?php if ( $note_added ) : ?><div class="llamahire-save-confirmation" role="status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><?php esc_html_e( 'Private note added.', 'llamahire' ); ?></span></div><?php endif; ?>
			<section class="llamahire-drawer-section llamahire-drawer-materials" aria-labelledby="llamahire-drawer-materials-title">
				<h3 id="llamahire-drawer-materials-title"><?php esc_html_e( 'Application materials', 'llamahire' ); ?></h3>
				<?php if ( $candidate->has_resume && current_user_can( Capabilities::DOWNLOAD_RESUMES ) ) : ?>
					<div class="llamahire-drawer-resume"><strong><?php echo esc_html( $candidate->resume_name ); ?></strong><div><?php if ( Applications::resume_is_previewable( $candidate->resume_name ) ) : ?><a href="<?php echo esc_url( Applications::resume_url( $candidate->id, true ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View resume', 'llamahire' ); ?></a><?php endif; ?><a href="<?php echo esc_url( Applications::resume_url( $candidate->id ) ); ?>"><?php esc_html_e( 'Download', 'llamahire' ); ?></a></div></div>
				<?php endif; ?>
				<h4><?php esc_html_e( 'Cover letter', 'llamahire' ); ?></h4>
				<?php if ( $candidate->cover_letter ) : ?><p class="llamahire-drawer-cover-letter"><?php echo nl2br( esc_html( $cover_letter_preview ) ); ?></p><?php if ( $has_long_cover_letter ) : ?><details class="llamahire-drawer-cover-letter-more"><summary><?php esc_html_e( 'Read full cover letter', 'llamahire' ); ?></summary><p><?php echo nl2br( esc_html( $candidate->cover_letter ) ); ?></p></details><?php endif; ?><?php else : ?><p><?php esc_html_e( 'No cover letter provided.', 'llamahire' ); ?></p><?php endif; ?>
			</section>
			<section class="llamahire-drawer-section llamahire-drawer-notes" aria-labelledby="llamahire-drawer-notes-title"><h3 id="llamahire-drawer-notes-title"><?php esc_html_e( 'Team notes', 'llamahire' ); ?></h3><?php if ( $private_notes ) : $latest_note = $private_notes[0]; ?><div class="llamahire-drawer-latest-note"><p><?php echo nl2br( esc_html( $latest_note->body ) ); ?></p><small><?php echo esc_html( Application_Notes::author_label( $latest_note ) ); ?> · <?php echo esc_html( get_date_from_gmt( $latest_note->created_at, get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ) ) ); ?></small></div><?php if ( count( $private_notes ) > 1 ) : ?><details><summary><?php esc_html_e( 'View earlier notes', 'llamahire' ); ?></summary><ol><?php foreach ( array_slice( $private_notes, 1 ) as $note ) : ?><li><p><?php echo nl2br( esc_html( $note->body ) ); ?></p><small><?php echo esc_html( Application_Notes::author_label( $note ) ); ?> · <?php echo esc_html( get_date_from_gmt( $note->created_at, get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ) ) ); ?></small></li><?php endforeach; ?></ol></details><?php endif; ?><?php else : ?><p><?php esc_html_e( 'No private notes yet.', 'llamahire' ); ?></p><?php endif; ?></section>
			<form class="llamahire-drawer-form llamahire-drawer-note-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llamahire_add_application_note"><input type="hidden" name="application" value="<?php echo esc_attr( $candidate->id ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $note_return_url ); ?>"><?php wp_nonce_field( 'llamahire_add_note_' . $candidate->id ); ?>
				<section><label for="llamahire-drawer-notes"><strong><?php esc_html_e( 'Add private note', 'llamahire' ); ?></strong></label><textarea id="llamahire-drawer-notes" name="note" rows="4" maxlength="<?php echo esc_attr( Application_Notes::MAX_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Interview feedback, next steps, or context for the team…', 'llamahire' ); ?>" required></textarea></section>
				<button class="button button-primary"><?php esc_html_e( 'Add note', 'llamahire' ); ?></button>
			</form>
			<section class="llamahire-drawer-section llamahire-drawer-contact" aria-labelledby="llamahire-drawer-contact-title"><h3 id="llamahire-drawer-contact-title"><?php esc_html_e( 'Contact info', 'llamahire' ); ?></h3><a href="mailto:<?php echo esc_attr( $candidate->email ); ?>"><?php echo esc_html( $candidate->email ); ?></a><?php if ( $candidate->phone ) : ?><span><?php echo esc_html( $candidate->phone ); ?></span><?php endif; ?></section>
			<div class="llamahire-drawer-activity"><h3><?php esc_html_e( 'Recent activity', 'llamahire' ); ?></h3><?php if ( $history['items'] ) : ?><ol><?php foreach ( $history['items'] as $event ) : ?><li><span></span><div><strong><?php echo esc_html( Audit_Log::describe( $event ) ); ?></strong><small><?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ) ) ); ?></small></div></li><?php endforeach; ?></ol><?php else : ?><p><?php esc_html_e( 'No recorded changes yet.', 'llamahire' ); ?></p><?php endif; ?></div>
			<p class="llamahire-drawer-full-application"><a href="<?php echo esc_url( Admin::applications_url( array( 'application' => $candidate->id ) ) ); ?>"><?php esc_html_e( 'View full application', 'llamahire' ); ?></a></p>
			<div class="llamahire-drawer-actions"><?php if ( $next_status ) : self::quick_stage_form( $candidate->id, $next_status, $return_url, $next_status_label ); endif; ?><details><summary class="button"><?php esc_html_e( 'Move candidate', 'llamahire' ); ?></summary><?php self::stage_form( $candidate->id, $candidate->status, $return_url ); ?></details><button type="button" class="button-link-delete" data-open-reject-dialog="llamahire-reject-dialog-<?php echo esc_attr( $candidate->id ); ?>" aria-haspopup="dialog"><?php esc_html_e( 'Reject candidate', 'llamahire' ); ?></button></div>
			<?php self::reject_dialog( $candidate, $return_url ); ?>
		</aside>
		<?php
	}

	private static function stage_form( $id, $current, $redirect ) {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $id ); ?><label><span class="screen-reader-text"><?php esc_html_e( 'New stage', 'llamahire' ); ?></span><select name="status"><?php foreach ( Applications::workflow_statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button class="button button-small"><?php esc_html_e( 'Update', 'llamahire' ); ?></button></form><?php
	}

	private static function quick_stage_form( $id, $status, $redirect, $label ) {
		?><form class="llamahire-quick-stage-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $id ); ?><button class="button button-primary"><?php echo esc_html( $label ); ?> <span aria-hidden="true">→</span></button></form><?php
	}

	private static function reject_dialog( $candidate, $redirect ) {
		$dialog_id = 'llamahire-reject-dialog-' . absint( $candidate->id );
		$title_id = $dialog_id . '-title';
		$description_id = $dialog_id . '-description';
		$dialog_title = sprintf(
			/* translators: %s: Candidate name. */
			__( 'Reject %s?', 'llamahire' ),
			$candidate->name
		);
		$dialog_description = sprintf(
			/* translators: 1: Candidate name. 2: Job title. */
			__( '%1$s will be moved to Rejected for %2$s. You can change their stage later.', 'llamahire' ),
			$candidate->name,
			get_the_title( $candidate->job_id )
		);
		?>
		<dialog id="<?php echo esc_attr( $dialog_id ); ?>" class="llamahire-reject-dialog" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" aria-describedby="<?php echo esc_attr( $description_id ); ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="llamahire_update_application"><input type="hidden" name="application" value="<?php echo esc_attr( $candidate->id ); ?>"><input type="hidden" name="status" value="rejected"><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>"><?php wp_nonce_field( 'llamahire_update_' . $candidate->id ); ?>
				<header>
					<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $dialog_title ); ?></h2>
					<button type="button" class="llamahire-dialog-close" data-close-reject-dialog aria-label="<?php esc_attr_e( 'Close rejection confirmation', 'llamahire' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</header>
				<p id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $dialog_description ); ?></p>
				<div class="llamahire-dialog-actions">
					<button type="button" class="button" data-close-reject-dialog><?php esc_html_e( 'Cancel', 'llamahire' ); ?></button>
					<button class="button llamahire-button-danger"><?php esc_html_e( 'Reject candidate', 'llamahire' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	private static function next_pipeline_status( $status ) {
		$statuses = array_keys( Applications::pipeline_statuses() );
		$index = array_search( $status, $statuses, true );
		return false !== $index && isset( $statuses[ $index + 1 ] ) ? $statuses[ $index + 1 ] : '';
	}

	private static function empty_stage_copy( $status ) {
		if ( 'offer' === $status ) {
			return __( 'Move candidates here when you are ready to make an offer.', 'llamahire' );
		}
		if ( 'hired' === $status ) {
			return __( 'Successful hires will appear here.', 'llamahire' );
		}
		return __( 'Drag a candidate here when they are ready.', 'llamahire' );
	}

	private static function attention_row( $icon, $title, $description, $count, $url, $action ) {
		$quiet = ! $count;
		$display_title = $quiet ? preg_replace( '/^0\s+/', __( 'No ', 'llamahire' ), $title ) : $title;
		?><article class="llamahire-attention-row<?php echo $quiet ? ' is-clear' : ''; ?>"><span class="dashicons dashicons-<?php echo esc_attr( $quiet ? 'yes-alt' : $icon ); ?>" aria-hidden="true"></span><div><h3><?php echo esc_html( $display_title ); ?></h3><p><?php echo esc_html( $quiet ? __( 'Nothing needs action here right now.', 'llamahire' ) : $description ); ?></p></div><a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action ); ?></a></article><?php
	}

	private static function metric( $value, $label, $icon, $url ) {
		?><a class="llamahire-metric" href="<?php echo esc_url( $url ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><span class="llamahire-metric-copy"><strong><?php echo esc_html( $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></span></a><?php
	}

	private static function activity_url( $event ) {
		$application_id = absint( $event->application_id ?? 0 );
		if ( $application_id && Ownership::user_can_access_application( $application_id, Capabilities::VIEW_APPLICATIONS ) ) {
			return Admin::applications_url( array( 'application' => $application_id ) );
		}
		$job_id = absint( $event->job_id ?? 0 );
		if ( $job_id && Jobs::POST_TYPE === get_post_type( $job_id ) && current_user_can( 'edit_post', $job_id ) ) {
			return get_edit_post_link( $job_id );
		}
		return '';
	}

	private static function dashboard_jobs( $author_id ) {
		$args = array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => Jobs::open_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $author_id ) {
			$args['author'] = $author_id;
		}
		return get_posts( $args );
	}

	private static function dashboard_jobs_to_watch( $author_id, $query, array $scope ) {
		$args = array( 'post_type' => Jobs::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => Jobs::open_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery -- Bounded dashboard candidate set uses the saved open-state query fields.
		if ( $author_id ) {
			$args['author'] = $author_id;
		}
		$jobs = get_posts( $args );
		if ( ! $jobs ) {
			return array();
		}
		$counts = $query->counts_by_job_and_status( wp_list_pluck( $jobs, 'ID' ), $scope );
		$today = current_time( 'Y-m-d' );
		$soon = current_datetime()->modify( '+7 days' )->format( 'Y-m-d' );
		$items = array();
		foreach ( $jobs as $job ) {
			$meta = Jobs::get_meta( $job->ID );
			$dates = array_filter( array( $meta['deadline'], $meta['listing_expires'] ) );
			sort( $dates );
			$closes = $dates[0] ?? '';
			$closing_soon = $closes && $closes >= $today && $closes <= $soon;
			$per_status = $counts[ $job->ID ] ?? array();
			$new = (int) ( $per_status['new'] ?? 0 );
			if ( ! $closing_soon && ! $new ) {
				continue;
			}
			$parts = array();
			if ( $closing_soon ) {
				/* translators: %s: Job closing date. */
				$parts[] = sprintf( __( 'Closes %s', 'llamahire' ), wp_date( get_option( 'date_format' ), strtotime( $closes ) ) );
			}
			if ( $new ) {
				/* translators: %d: New candidates for this job. */
				$parts[] = sprintf( _n( '%d new candidate', '%d new candidates', $new, 'llamahire' ), $new );
			} elseif ( ! array_sum( $per_status ) ) {
				$parts[] = __( 'No applications yet', 'llamahire' );
			}
			$items[] = array( 'id' => $job->ID, 'summary' => implode( ' · ', $parts ), 'score' => ( $closing_soon ? 100 : 0 ) + ( $new * 10 ) + ( $closing_soon && ! array_sum( $per_status ) ? 20 : 0 ), 'closes' => $closes );
		}
		usort( $items, static function ( $first, $second ) {
			if ( $first['score'] !== $second['score'] ) {
				return $second['score'] - $first['score'];
			}
			return strcmp( $first['closes'] ?: '9999-12-31', $second['closes'] ?: '9999-12-31' );
		} );
		return array_slice( $items, 0, 3 );
	}

	private static function job_count( $status, $author_id ) {
		$args = array( 'post_type' => Jobs::POST_TYPE, 'post_status' => $status, 'posts_per_page' => 1, 'fields' => 'ids' );
		if ( $author_id ) {
			$args['author'] = $author_id;
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	private static function expiring_job_count( $author_id ) {
		$args = array(
			'post_type' => Jobs::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
			'meta_query' => Jobs::closing_soon_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( $author_id ) {
			$args['author'] = $author_id;
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	private static function active_employer_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate dashboard count must reflect the current bounded job table state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','pending','draft','future','private')",
				Jobs::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function job_context( $job, array $meta, $show_organization ) {
		$parts = array();
		if ( $show_organization ) {
			$parts[] = Jobs::organization( $meta )['name'];
		}
		$parts[] = Jobs::location_label( $meta );
		if ( $meta['deadline'] && ( empty( $meta['listing_expires'] ) || $meta['deadline'] <= $meta['listing_expires'] ) ) {
			/* translators: %s: Job closing date. */
			$parts[] = sprintf( __( 'Closes %s', 'llamahire' ), date_i18n( get_option( 'date_format' ), strtotime( $meta['deadline'] ) ) );
		} elseif ( $meta['listing_expires'] ) {
			/* translators: %s: formatted listing-expiration date. */
			$parts[] = sprintf( __( 'Listing ends %s', 'llamahire' ), date_i18n( get_option( 'date_format' ), strtotime( $meta['listing_expires'] ) ) );
		}
		return implode( ' · ', array_filter( $parts ) );
	}

	private static function initials( $name ) {
		$words = preg_split( '/\s+/u', trim( $name ) );
		$letters = '';
		foreach ( array_slice( $words, 0, 2 ) as $word ) {
			$letters .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
		}
		return strtoupper( $letters );
	}

	private static function time_in_stage( $candidate ) {
		$changed = $candidate->stage_changed_at ?: $candidate->updated_at ?: $candidate->created_at;
		return human_time_diff( strtotime( $changed . ' UTC' ), current_time( 'timestamp', true ) );
	}

	private function __construct() {}
}
