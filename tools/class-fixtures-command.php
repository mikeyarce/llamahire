<?php
namespace LlamaHire\Tools;

use LlamaHire\Applications;
use LlamaHire\Application_Notes;
use LlamaHire\Audit_Log;
use LlamaHire\Capabilities;
use LlamaHire\Employer_Registration;
use LlamaHire\Jobs;
use LlamaHire\Plugin;
use LlamaHire\Service_IDs;
use LlamaHire\Settings;
use LlamaHire\Setup;

defined( 'ABSPATH' ) || exit;
defined( 'WP_CLI' ) && WP_CLI || exit;

/**
 * Create and remove deterministic LlamaHire demo data on development sites.
 */
final class Fixtures_Command {
	const OPTION = 'llamahire_fixture_registry';
	const EMPLOYER_OPTION = 'llamahire_employer_fixture_registry';
	const OWNER  = 'llamahire-fixtures-v1';
	const META   = '_llamahire_fixture_owner';

	/**
	 * Generate a complete demo hiring dataset.
	 *
	 * ## OPTIONS
	 *
	 * [--scenario=<scenario>]
	 * : demo, small, large, remote, expired, closed, notification-failures, edge-cases, or state-matrix. Default: small.
	 *
	 * [--seed=<seed>]
	 * : Stable content seed. Default: demo.
	 *
	 * [--jobs=<count>]
	 * : Override the scenario job count (1-500).
	 *
	 * [--applications=<count>]
	 * : Override the scenario application count (0-10000).
	 *
	 * [--force]
	 * : Safely remove the currently registered fixture dataset first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp llamahire fixtures generate --scenario=small
	 *     wp llamahire fixtures generate --scenario=demo --force
	 *     wp llamahire fixtures generate --scenario=edge-cases --seed=bug-142 --force
	 *     wp llamahire fixtures generate --scenario=state-matrix --force
	 *
	 * @subcommand generate
	 */
	public function generate( $args, $assoc_args ) {
		$this->require_safe_environment();
		$scenario = sanitize_key( $assoc_args['scenario'] ?? 'small' );
		$scenarios = $this->scenarios();
		if ( ! isset( $scenarios[ $scenario ] ) ) {
			\WP_CLI::error( 'Unknown scenario. Use demo, small, large, remote, expired, closed, notification-failures, edge-cases, or state-matrix.' );
		}
		if ( get_option( self::OPTION, false ) ) {
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ) ) {
				\WP_CLI::error( 'Fixture data is already registered. Run cleanup or pass --force.' );
			}
			$this->remove_registered_data();
		}

		$seed = sanitize_key( $assoc_args['seed'] ?? 'demo' );
		$seed = $seed ?: 'demo';
		$job_count = isset( $assoc_args['jobs'] ) ? min( 500, max( 1, absint( $assoc_args['jobs'] ) ) ) : $scenarios[ $scenario ]['jobs'];
		$application_count = isset( $assoc_args['applications'] ) ? min( 10000, absint( $assoc_args['applications'] ) ) : $scenarios[ $scenario ]['applications'];
		$registry = array(
			'version'      => 2,
			'owner'        => self::OWNER,
			'scenario'     => $scenario,
			'seed'         => $seed,
			'created_at'   => current_time( 'mysql', true ),
			'jobs'         => array(),
			'terms'        => array(),
			'job_types'    => array(),
			'pages'        => array(),
			'attachments'  => array(),
			'applications' => array(),
			'users'        => array(),
			'options'      => array(
				'settings_exists' => false !== get_option( Settings::OPTION, false ),
				'settings'        => get_option( Settings::OPTION, false ),
				'setup_exists'    => false !== get_option( Setup::OPTION, false ),
				'setup'           => get_option( Setup::OPTION, false ),
				'show_on_front'    => get_option( 'show_on_front' ),
				'page_on_front'    => get_option( 'page_on_front' ),
			),
		);
		update_option( self::OPTION, $registry, false );

		try {
			$attachment = $this->create_logo( $seed, $scenario );
			$registry['attachments'][] = $attachment;
			$this->save_registry( $registry );

			foreach ( array( 'full_time' => 'Full time', 'part_time' => 'Part time', 'contractor' => 'Contractor', 'temporary' => 'Temporary', 'intern' => 'Intern', 'volunteer' => 'Volunteer', 'per_diem' => 'Per diem', 'other' => 'Other' ) as $slug => $name ) {
				$existing = get_term_by( 'slug', $slug, Jobs::TYPE_TAXONOMY );
				if ( $existing instanceof \WP_Term ) {
					continue;
				}
				$term = wp_insert_term( $name, Jobs::TYPE_TAXONOMY, array( 'slug' => $slug ) );
				if ( is_wp_error( $term ) ) {
					throw new \RuntimeException( $term->get_error_message() );
				}
				$term_id = (int) $term['term_id'];
				update_term_meta( $term_id, self::META, self::OWNER );
				$registry['job_types'][] = $term_id;
			}
			$this->save_registry( $registry );

			$department_names = array( 'Engineering', 'Design', 'Marketing', 'Customer Success', 'Operations' );
			foreach ( $department_names as $department_name ) {
				$term_name = 'demo' === $scenario ? $department_name . ' — Northstar Labs' : $department_name . ' ' . strtoupper( substr( md5( $seed ), 0, 4 ) );
				$term = wp_insert_term( $term_name, 'llamahire_department' );
				if ( is_wp_error( $term ) ) {
					throw new \RuntimeException( $term->get_error_message() );
				}
				$term_id = (int) $term['term_id'];
				update_term_meta( $term_id, self::META, self::OWNER );
				$registry['terms'][] = $term_id;
			}
			$this->save_registry( $registry );

			$privacy_title = 'demo' === $scenario ? 'Candidate privacy at Northstar Labs' : 'Fixture candidate privacy';
			$privacy_copy  = 'demo' === $scenario ? '<!-- wp:heading --><h2 class="wp-block-heading">How we use candidate information</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Northstar Labs uses application information only to evaluate candidates, coordinate interviews, and meet hiring obligations. This local demo contains fictional candidate data.</p><!-- /wp:paragraph -->' : '<!-- wp:paragraph --><p>This test-site policy explains how fixture candidate data is used.</p><!-- /wp:paragraph -->';
			$careers_title = 'demo' === $scenario ? 'Careers at Northstar Labs' : 'Fixture careers';
			$privacy_page = $this->create_page( $privacy_title, $privacy_copy, $seed . '-privacy' );
			$careers_content = 'demo' === $scenario ? $this->demo_careers_content() : Setup::careers_page_content();
			$careers_page = $this->create_page( $careers_title, $careers_content, $seed . '-careers' );
			$registry['pages'] = array( $privacy_page, $careers_page );
			$state_pages = array();
			if ( 'state-matrix' === $scenario ) {
				foreach ( array(
					'submit_job_page_id' => array( 'State Matrix — Submit a Job', $seed . '-submit-job', '[llamahire_submit_job]' ),
					'my_jobs_page_id' => array( 'State Matrix — My Jobs', $seed . '-my-jobs', '[llamahire_my_jobs]' ),
					'employer_account_page_id' => array( 'State Matrix — Employer Account', $seed . '-employer-account', '[llamahire_employer_account]' ),
					'employer_registration_page_id' => array( 'State Matrix — Employer Registration', $seed . '-employer-registration', '[llamahire_employer_registration]' ),
					'employer_policy_page_id' => array( 'State Matrix — Listing Rules', $seed . '-listing-rules', '<!-- wp:paragraph --><p>Fixture employers must publish accurate, lawful listings.</p><!-- /wp:paragraph -->' ),
				) as $setting_key => $page_spec ) {
					$page_id = $this->create_page( $page_spec[0], $page_spec[2], $page_spec[1] );
					$registry['pages'][] = $page_id;
					$state_pages[ $setting_key ] = $page_id;
				}
			}
			$this->save_registry( $registry );

			$logo_url = wp_get_attachment_url( $attachment );
			update_option(
				Settings::OPTION,
				Settings::sanitize(
					array_merge(
						array(
							'site_mode'             => 'state-matrix' === $scenario ? Settings::SITE_MODE_JOB_BOARD : Settings::SITE_MODE_COMPANY,
							'name'                  => 'demo' === $scenario ? 'Northstar Labs' : 'LlamaHire Fixture Company',
							'website'               => home_url( '/' ),
							'logo'                  => $logo_url,
							'default_locality'      => 'Vancouver',
							'default_region'        => 'British Columbia',
							'default_country'       => 'CA',
							'default_currency'      => 'CAD',
							'notification_email'    => 'demo' === $scenario ? 'careers@northstar.example.test' : 'hiring-fixtures@example.test',
							'privacy_text'          => 'demo' === $scenario ? 'We use your information only to review your application and coordinate the hiring process.' : 'Fixture candidate information is used only for product testing.',
							'privacy_page_id'       => $privacy_page,
							'careers_page_id'       => $careers_page,
							'employer_approval'     => 'manual',
							'employer_policy_text'  => 'I agree to follow the {listing_policy} and provide accurate information.',
							'active_listing_limit'  => 25,
							'listing_duration_days' => 30,
						),
						$state_pages
					)
				)
			);
			update_option( Setup::OPTION, array( 'version' => Setup::VERSION, 'status' => 'completed' ), false );
			if ( 'demo' === $scenario ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $careers_page );
			}

			$state_users = array();
			if ( 'state-matrix' === $scenario ) {
				$state_users = $this->create_state_matrix_users( $seed );
				$registry['users'] = array_values( $state_users );
				$this->save_registry( $registry );
			}

			for ( $index = 0; $index < $job_count; $index++ ) {
				$job_id = $this->create_job( $scenario, $seed, $index, $attachment, $registry['terms'], $state_users );
				$registry['jobs'][] = $job_id;
				$this->save_registry( $registry );
			}

			for ( $index = 0; $index < $application_count; $index++ ) {
				$record = $this->create_application( $scenario, $seed, $index, $registry['jobs'] );
				$registry['applications'][] = $record;
				$this->save_registry( $registry );
			}
		} catch ( \Throwable $error ) {
			\WP_CLI::warning( 'Fixture generation stopped with a recoverable registry in place.' );
			\WP_CLI::error( $error->getMessage() );
		}

		\WP_CLI::success( sprintf( 'Created %1$d jobs, %2$d applications, %3$d departments, %4$d pages, %5$d users, and one Media Library image for the %6$s scenario.', $job_count, $application_count, count( $registry['terms'] ), count( $registry['pages'] ), count( $registry['users'] ), $scenario ) );
		\WP_CLI::log( 'Careers page: ' . get_permalink( $careers_page ) );
		if ( 'state-matrix' === $scenario ) {
			\WP_CLI::log( 'State-matrix user password: llamahire-matrix' );
			foreach ( $state_users as $state => $user_id ) {
				$user = get_userdata( $user_id );
				\WP_CLI::log( sprintf( '%1$s: %2$s', str_replace( '_', ' ', ucfirst( $state ) ), $user ? $user->user_login : '' ) );
			}
		}
	}

	/**
	 * Remove only records owned by the registered fixture dataset.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( $args, $assoc_args ) {
		$this->require_safe_environment();
		if ( ! get_option( self::OPTION, false ) ) {
			\WP_CLI::success( 'No registered LlamaHire fixture data was found.' );
			return;
		}
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			\WP_CLI::confirm( 'Remove the registered LlamaHire fixture dataset?' );
		}
		$counts = $this->remove_registered_data();
		\WP_CLI::success( sprintf( 'Removed %1$d jobs, %2$d applications, %3$d departments, %4$d pages, %5$d users, and %6$d attachments owned by LlamaHire fixtures.', $counts['jobs'], $counts['applications'], $counts['terms'], $counts['pages'], $counts['users'], $counts['attachments'] ) );
	}

	/**
	 * Show the currently registered fixture dataset.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$registry = get_option( self::OPTION, false );
		if ( ! is_array( $registry ) || self::OWNER !== ( $registry['owner'] ?? '' ) ) {
			\WP_CLI::log( 'No registered LlamaHire fixture data.' );
			return;
		}
		$rows = array(
			array( 'property' => 'Scenario', 'value' => $registry['scenario'] ),
			array( 'property' => 'Seed', 'value' => $registry['seed'] ),
			array( 'property' => 'Jobs', 'value' => count( $registry['jobs'] ) ),
			array( 'property' => 'Applications', 'value' => count( $registry['applications'] ) ),
			array( 'property' => 'Users', 'value' => count( $registry['users'] ?? array() ) ),
			array( 'property' => 'Departments', 'value' => count( $registry['terms'] ) ),
			array( 'property' => 'Pages', 'value' => count( $registry['pages'] ) ),
			array( 'property' => 'Attachments', 'value' => count( $registry['attachments'] ) ),
			array( 'property' => 'Created (UTC)', 'value' => $registry['created_at'] ),
		);
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'property', 'value' ) );
	}

	/**
	 * Create a reusable local employer account and lifecycle jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--username=<username>]
	 * : Account login. Default: llamahire-employer.
	 *
	 * [--password=<password>]
	 * : Account password. Default: llamahire-demo.
	 *
	 * [--force]
	 * : Replace the currently registered employer fixture.
	 *
	 * [--cleanup]
	 * : Remove the registered employer fixture and restore its settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp llamahire fixtures employer
	 *     wp llamahire fixtures employer --force
	 *     wp llamahire fixtures employer --cleanup
	 *
	 * @subcommand employer
	 */
	public function employer( $args, $assoc_args ) {
		$this->require_safe_environment();
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'cleanup', false ) ) {
			$counts = $this->remove_employer_fixture();
			\WP_CLI::success( sprintf( 'Removed the employer test account, %1$d jobs, and %2$d pages.', $counts['jobs'], $counts['pages'] ) );
			return;
		}

		if ( get_option( self::EMPLOYER_OPTION, false ) ) {
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ) ) {
				\WP_CLI::error( 'An employer fixture is already registered. Pass --force to replace it or --cleanup to remove it.' );
			}
			$this->remove_employer_fixture();
		}

		$username = sanitize_user( $assoc_args['username'] ?? 'llamahire-employer', true );
		$password = (string) ( $assoc_args['password'] ?? 'llamahire-demo' );
		if ( ! $username || strlen( $password ) < 8 ) {
			\WP_CLI::error( 'Choose a valid username and a password containing at least eight characters.' );
		}
		if ( username_exists( $username ) ) {
			\WP_CLI::error( 'That username already belongs to an account not owned by this fixture.' );
		}

		$registry = array(
			'version'    => 1,
			'owner'      => self::OWNER,
			'created_at' => current_time( 'mysql', true ),
			'user_id'    => 0,
			'jobs'       => array(),
			'pages'      => array(),
			'options'    => array(
				'settings_exists' => false !== get_option( Settings::OPTION, false ),
				'settings'        => get_option( Settings::OPTION, false ),
			),
		);
		update_option( self::EMPLOYER_OPTION, $registry, false );

		try {
			Capabilities::install();
			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => $password,
					'user_email'   => $username . '@example.test',
					'display_name' => 'Demo Employer',
					'role'         => Capabilities::EMPLOYER_ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new \RuntimeException( $user_id->get_error_message() );
			}
			$registry['user_id'] = (int) $user_id;
			update_user_meta( $user_id, self::META, self::OWNER );
			update_user_meta( $user_id, Employer_Registration::STATUS_META, Employer_Registration::STATUS_APPROVED );
			update_user_meta( $user_id, Employer_Registration::COMPANY_META, 'Demo Employer Co.' );
			$this->save_employer_registry( $registry );

			$settings = Settings::get();
			$page_specs = array(
				'submit_job_page_id' => array( 'Submit a Job', 'submit-a-job', '[llamahire_submit_job]' ),
				'my_jobs_page_id' => array( 'My Jobs', 'my-jobs', '[llamahire_my_jobs]' ),
				'employer_account_page_id' => array( 'Account', 'employer-account', '[llamahire_employer_account]' ),
				'employer_registration_page_id' => array( 'Employer registration', 'employer-registration', '[llamahire_employer_registration]' ),
			);
			foreach ( $page_specs as $setting_key => $page_spec ) {
				if ( Settings::public_page( $settings[ $setting_key ] ?? 0 ) ) {
					continue;
				}
				$page_id = $this->create_employer_page( $page_spec[0], $page_spec[1], $page_spec[2] );
				$registry['pages'][] = $page_id;
				$settings[ $setting_key ] = $page_id;
				$this->save_employer_registry( $registry );
			}
			$policy_page = Settings::public_page( $settings['employer_policy_page_id'] ?? 0 );
			if ( ! $policy_page ) {
				$policy_id = $this->create_employer_page( 'Listing rules', 'listing-rules', '<!-- wp:heading --><h2 class="wp-block-heading">Accurate, useful listings</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Employers must provide accurate company and role details, use a real application destination, and keep listings current. Misleading, discriminatory, duplicate, or unlawful listings may be removed.</p><!-- /wp:paragraph -->' );
				$registry['pages'][] = $policy_id;
				$settings['employer_policy_page_id'] = $policy_id;
				$this->save_employer_registry( $registry );
			}
			$settings['site_mode'] = Settings::SITE_MODE_JOB_BOARD;
			$settings['employer_policy_text'] = 'I agree to follow this job board’s {listing_policy} and provide accurate employer and job information.';
			update_option( Settings::OPTION, Settings::sanitize( $settings ) );

			foreach ( array(
				array( 'Demo draft listing', 'draft', '' ),
				array( 'Demo listing expiring soon', 'publish', wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) ) ),
				array( 'Demo expired listing', 'publish', wp_date( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS ) ),
			) as $job_spec ) {
				$registry['jobs'][] = $this->create_employer_job( $user_id, $job_spec[0], $job_spec[1], $job_spec[2] );
				$this->save_employer_registry( $registry );
			}
		} catch ( \Throwable $error ) {
			\WP_CLI::warning( 'Employer fixture creation stopped with a recoverable registry in place.' );
			\WP_CLI::error( $error->getMessage() );
		}

		\WP_CLI::success( 'Created an approved employer test account and three lifecycle jobs.' );
		\WP_CLI::log( 'Username: ' . $username );
		\WP_CLI::log( 'Password: ' . $password );
		\WP_CLI::log( 'My Jobs: ' . get_permalink( Settings::get()['my_jobs_page_id'] ) );
		\WP_CLI::log( 'Account: ' . get_permalink( Settings::get()['employer_account_page_id'] ) );
		\WP_CLI::log( 'Registration: ' . get_permalink( Settings::get()['employer_registration_page_id'] ) );
	}

	private function create_employer_page( $title, $slug, $content ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => wp_unique_post_slug( $slug, 0, 'publish', 'page', 0 ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			throw new \RuntimeException( $page_id->get_error_message() );
		}
		update_post_meta( $page_id, self::META, self::OWNER );
		return (int) $page_id;
	}

	private function create_employer_job( $user_id, $title, $status, $listing_expires ) {
		$job_id = wp_insert_post(
			array(
				'post_type'    => Jobs::POST_TYPE,
				'post_status'  => $status,
				'post_author'  => absint( $user_id ),
				'post_title'   => $title,
				'post_excerpt' => 'A reusable local listing for testing the employer workflow.',
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">About the role</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Use this listing to test editing, previewing, renewing, relisting, and duplicating jobs.</p><!-- /wp:paragraph -->',
			),
			true
		);
		if ( is_wp_error( $job_id ) ) {
			throw new \RuntimeException( $job_id->get_error_message() );
		}
		update_post_meta( $job_id, self::META, self::OWNER );
		Jobs::set_meta(
			$job_id,
			array(
				'location'          => 'Vancouver, British Columbia',
				'employment_type'   => 'full_time',
				'workplace'         => 'hybrid',
				'deadline'          => wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( 30 * DAY_IN_SECONDS ) ),
				'listing_expires'   => $listing_expires,
				'address_locality'  => 'Vancouver',
				'address_region'    => 'British Columbia',
				'address_country'   => 'CA',
				'organization_name' => 'Demo Employer Co.',
				'organization_url'  => home_url( '/' ),
				'application_method'=> 'internal',
				'application_target'=> 'demo-employer@example.test',
			)
		);
		return (int) $job_id;
	}

	private function remove_employer_fixture() {
		$registry = get_option( self::EMPLOYER_OPTION, false );
		if ( ! is_array( $registry ) || self::OWNER !== ( $registry['owner'] ?? '' ) ) {
			\WP_CLI::error( 'No registered employer fixture was found.' );
		}
		$counts = array( 'jobs' => 0, 'pages' => 0 );
		global $wpdb;
		foreach ( (array) ( $registry['jobs'] ?? array() ) as $job_id ) {
			if ( self::OWNER !== get_post_meta( $job_id, self::META, true ) ) {
				continue;
			}
			$wpdb->delete( Audit_Log::table(), array( 'job_id' => absint( $job_id ) ), array( '%d' ) );
			if ( wp_delete_post( $job_id, true ) ) {
				$counts['jobs']++;
			}
		}
		foreach ( (array) ( $registry['pages'] ?? array() ) as $page_id ) {
			if ( self::OWNER === get_post_meta( $page_id, self::META, true ) && wp_delete_post( $page_id, true ) ) {
				$counts['pages']++;
			}
		}
		$user_id = absint( $registry['user_id'] ?? 0 );
		if ( $user_id && self::OWNER === get_user_meta( $user_id, self::META, true ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$administrators = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
			$reassign_id = absint( reset( $administrators ) );
			wp_delete_user( $user_id, $reassign_id ?: null );
		}
		$options = (array) ( $registry['options'] ?? array() );
		if ( ! empty( $options['settings_exists'] ) ) {
			update_option( Settings::OPTION, $options['settings'], false );
		} else {
			delete_option( Settings::OPTION );
		}
		delete_option( self::EMPLOYER_OPTION );
		return $counts;
	}

	private function save_employer_registry( array $registry ) {
		update_option( self::EMPLOYER_OPTION, $registry, false );
	}

	private function scenarios() {
		return array(
			'demo'                  => array( 'jobs' => 16, 'applications' => 64 ),
			'small'                 => array( 'jobs' => 8, 'applications' => 30 ),
			'large'                 => array( 'jobs' => 60, 'applications' => 1000 ),
			'remote'                => array( 'jobs' => 10, 'applications' => 40 ),
			'expired'               => array( 'jobs' => 8, 'applications' => 24 ),
			'closed'                => array( 'jobs' => 8, 'applications' => 24 ),
			'notification-failures' => array( 'jobs' => 6, 'applications' => 30 ),
			'edge-cases'            => array( 'jobs' => 12, 'applications' => 48 ),
			'state-matrix'          => array( 'jobs' => 15, 'applications' => 48 ),
		);
	}

	private function create_state_matrix_users( $seed ) {
		Capabilities::install();
		$suffix = substr( md5( $seed ), 0, 8 );
		$specs = array(
			'hiring_manager' => array( 'State Matrix Hiring Manager', 'manager', Capabilities::HIRING_MANAGER_ROLE, '' ),
			'pending_email'   => array( 'State Matrix Unverified Employer', 'unverified', '', Employer_Registration::STATUS_EMAIL ),
			'pending_approval' => array( 'State Matrix Awaiting Employer', 'awaiting', '', Employer_Registration::STATUS_APPROVAL ),
			'employer_alpha'  => array( 'State Matrix Employer Alpha', 'alpha', Capabilities::EMPLOYER_ROLE, Employer_Registration::STATUS_APPROVED ),
			'employer_beta'   => array( 'State Matrix Employer Beta', 'beta', Capabilities::EMPLOYER_ROLE, Employer_Registration::STATUS_APPROVED ),
		);
		$users = array();
		foreach ( $specs as $key => $spec ) {
			$login = sanitize_user( 'llamahire-' . $spec[1] . '-' . $suffix, true );
			if ( username_exists( $login ) ) {
				throw new \RuntimeException( 'A state-matrix username already exists outside the registered fixture.' );
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => 'llamahire-matrix',
					'user_email'   => $spec[1] . '-' . $suffix . '@example.test',
					'display_name' => $spec[0],
					'first_name'   => $spec[0],
					'role'         => $spec[2],
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new \RuntimeException( $user_id->get_error_message() );
			}
			$user = get_userdata( $user_id );
			if ( '' === $spec[2] && $user ) {
				$user->set_role( '' );
			}
			update_user_meta( $user_id, self::META, self::OWNER );
			if ( $spec[3] ) {
				update_user_meta( $user_id, Employer_Registration::STATUS_META, $spec[3] );
				update_user_meta( $user_id, Employer_Registration::COMPANY_META, 'State Matrix ' . ucfirst( $spec[1] ) . ' Company' );
				update_user_meta( $user_id, Employer_Registration::POLICY_META, hash( 'sha256', Settings::get()['employer_policy_text'] . '|' . absint( Settings::get()['employer_policy_page_id'] ) ) );
				update_user_meta( $user_id, Employer_Registration::POLICY_DATE_META, current_time( 'mysql', true ) );
			}
			if ( Employer_Registration::STATUS_EMAIL === $spec[3] ) {
				$token = 'state-matrix-verification-token';
				update_user_meta( $user_id, Employer_Registration::TOKEN_META, hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
				update_user_meta( $user_id, Employer_Registration::TOKEN_EXPIRY_META, time() + DAY_IN_SECONDS );
			}
			$users[ $key ] = (int) $user_id;
		}
		return $users;
	}

	private function state_matrix_job_specs() {
		return array(
			array( 'Open — Internal On-site', 'publish', 'onsite', 'internal', 'open' ),
			array( 'Open — Internal Hybrid', 'publish', 'hybrid', 'internal', 'open' ),
			array( 'Open — Internal Remote', 'publish', 'remote', 'internal', 'open' ),
			array( 'Closing Soon — Application Deadline', 'publish', 'onsite', 'internal', 'closing_soon' ),
			array( 'Expiring Soon — Listing Duration', 'publish', 'hybrid', 'internal', 'expiring_soon' ),
			array( 'Expired — Application Deadline', 'publish', 'onsite', 'internal', 'deadline_expired' ),
			array( 'Expired — Listing Duration', 'publish', 'remote', 'internal', 'listing_expired' ),
			array( 'Closed — Employer Action', 'publish', 'hybrid', 'internal', 'closed' ),
			array( 'Draft — Incomplete Listing', 'draft', 'onsite', 'internal', 'draft' ),
			array( 'Awaiting Review — Pending Moderation', 'pending', 'remote', 'internal', 'pending' ),
			array( 'Open — External Application Website', 'publish', 'onsite', 'external_url', 'open' ),
			array( 'Open — External Application Email', 'publish', 'hybrid', 'external_email', 'open' ),
			array( 'Open — Featured Exact Salary', 'publish', 'remote', 'internal', 'exact_salary' ),
			array( 'Open — No Salary Disclosed', 'publish', 'onsite', 'internal', 'no_salary' ),
			array( 'Open — Minimal Optional Facts', 'publish', 'hybrid', 'internal', 'minimal' ),
		);
	}

	private function create_job( $scenario, $seed, $index, $attachment, array $terms, array $state_users = array() ) {
		$titles = 'demo' === $scenario
			? array( 'Senior Product Designer', 'Backend Platform Engineer', 'Customer Success Lead', 'Content Strategist', 'People Operations Partner', 'Data Analyst', 'Frontend Engineer', 'Growth Marketing Manager', 'Product Manager', 'Security Engineer', 'Technical Writer', 'Finance Operations Analyst', 'Developer Experience Engineer', 'Design Systems Lead', 'Community Programs Manager', 'Support Engineer' )
			: array( 'Senior Product Designer', 'Backend Engineer', 'Customer Success Lead', 'Content Strategist', 'People Operations Partner', 'Data Analyst', 'Frontend Engineer', 'Growth Marketer' );
		$workplaces = array( 'onsite', 'hybrid', 'remote' );
		$employment = array( 'full_time', 'part_time', 'contractor', 'temporary', 'intern', 'volunteer', 'per_diem', 'other' );
		$workplace = 'remote' === $scenario ? 'remote' : $workplaces[ $index % count( $workplaces ) ];
		$matrix_spec = 'state-matrix' === $scenario ? $this->state_matrix_job_specs()[ $index % count( $this->state_matrix_job_specs() ) ] : array();
		$status = $matrix_spec ? $matrix_spec[1] : ( ( 'edge-cases' === $scenario && 0 === $index % 5 ) || ( 'demo' === $scenario && 11 === $index ) ? 'draft' : 'publish' );
		$title  = $titles[ $index % count( $titles ) ];
		if ( $matrix_spec ) {
			$title = 'State Matrix: ' . $matrix_spec[0];
			$workplace = $matrix_spec[2];
		} elseif ( 'demo' !== $scenario ) {
			$title .= ' — Fixture ' . ( $index + 1 );
		}
		$author_id = $matrix_spec ? absint( $state_users[ 0 === $index % 2 ? 'employer_alpha' : 'employer_beta' ] ?? 0 ) : 0;
		$job_id = wp_insert_post(
			array(
				'post_type'    => Jobs::POST_TYPE,
				'post_status'  => $status,
				'post_author'  => $author_id,
				'post_title'   => $title,
				'post_name'    => 'llamahire-fixture-' . $seed . '-' . ( $index + 1 ),
				'post_excerpt' => 'demo' === $scenario ? 'Join Northstar Labs and help thoughtful teams do ambitious work.' : 'A deterministic ' . $scenario . ' scenario role for LlamaHire testing.',
				'post_content' => 'demo' === $scenario ? '<!-- wp:heading --><h2 class="wp-block-heading">About the role</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Northstar Labs builds practical tools for modern teams. You will join a collaborative group that values clear thinking, kind communication, and measurable customer impact.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">What you will do</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Own meaningful work from discovery through delivery</li><li>Collaborate across product, design, engineering, and go-to-market teams</li><li>Improve the experience for customers and teammates</li></ul><!-- /wp:list --><!-- wp:heading --><h2 class="wp-block-heading">What we offer</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Flexible hybrid and remote work</li><li>Learning and wellness budgets</li><li>Transparent compensation and growth paths</li></ul><!-- /wp:list -->' : '<!-- wp:heading --><h2 class="wp-block-heading">About the role</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Help the fixture company test a complete, realistic hiring workflow.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">What you will do</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Own meaningful work</li><li>Collaborate across teams</li><li>Improve the candidate experience</li></ul><!-- /wp:list -->',
			),
			true
		);
		if ( is_wp_error( $job_id ) ) {
			throw new \RuntimeException( $job_id->get_error_message() );
		}
		update_post_meta( $job_id, self::META, self::OWNER );
		$deadline_days = 20 + ( $index % 50 );
		$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS * $deadline_days );
		$listing_expires = '';
		if ( 'expired' === $scenario || ( 'edge-cases' === $scenario && 1 === $index % 5 ) || ( 'demo' === $scenario && 12 === $index ) ) {
			$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS * ( 1 + $index ) );
		}
		$closed = 'closed' === $scenario || ( 'edge-cases' === $scenario && 2 === $index % 5 ) || ( 'demo' === $scenario && 13 === $index ) ? '1' : '0';
		$salary_min = 50000 + ( $index % 8 ) * 7500;
		$salary_max = $salary_min + 15000;
		if ( ( 'edge-cases' === $scenario && 3 === $index % 5 ) || ( 'demo' === $scenario && 14 === $index ) ) {
			$salary_max = $salary_min;
		}
		if ( ( 'edge-cases' === $scenario && 4 === $index % 5 ) || ( 'demo' === $scenario && 15 === $index ) ) {
			$salary_min = '';
			$salary_max = '';
		}
		$application_method = 'internal';
		$application_target = '';
		if ( $matrix_spec ) {
			$lifecycle = $matrix_spec[4];
			$application_method = $matrix_spec[3];
			$application_target = 'external_url' === $application_method ? 'https://apply.example.test/state-matrix' : ( 'external_email' === $application_method ? 'apply-matrix@example.test' : 'hiring-fixtures@example.test' );
			if ( 'closing_soon' === $lifecycle ) {
				$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) );
			} elseif ( 'expiring_soon' === $lifecycle ) {
				$listing_expires = wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) );
			} elseif ( 'deadline_expired' === $lifecycle ) {
				$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( 3 * DAY_IN_SECONDS ) );
			} elseif ( 'listing_expired' === $lifecycle ) {
				$listing_expires = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( 3 * DAY_IN_SECONDS ) );
			} elseif ( 'closed' === $lifecycle ) {
				$closed = '1';
			} elseif ( 'exact_salary' === $lifecycle ) {
				$salary_max = $salary_min;
			} elseif ( 'no_salary' === $lifecycle || 'minimal' === $lifecycle ) {
				$salary_min = '';
				$salary_max = '';
			}
			if ( 'minimal' === $lifecycle ) {
				$deadline = '';
			}
		}
		Jobs::set_meta(
			$job_id,
			array(
				'location'            => 'Vancouver, British Columbia',
				'employment_type'     => $employment[ $index % count( $employment ) ],
				'workplace'           => $workplace,
				'salary_min'          => $salary_min,
				'salary_max'          => $salary_max,
				'salary_currency'     => 0 === $index % 2 ? 'CAD' : 'USD',
				'salary_unit'         => 'state-matrix' === $scenario ? array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' )[ $index % 5 ] : ( 0 === $index % 4 ? 'HOUR' : 'YEAR' ),
				'deadline'            => $deadline,
				'listing_expires'     => $listing_expires,
				'featured'            => 0 === $index % 4 ? '1' : '0',
				'closed'              => $closed,
				'address_street'      => ( 100 + $index ) . ' Fixture Street',
				'address_locality'    => 'Vancouver',
				'address_region'      => 'British Columbia',
				'postal_code'         => 'V6B 1A1',
				'address_country'     => 'CA',
				'applicant_countries' => 'CA, US, GB',
				'job_identifier'      => 'fixture-' . $seed . '-job-' . ( $index + 1 ),
				'organization_name'   => 0 === $index % 3 ? ( 'demo' === $scenario ? 'Northstar Product Studio' : 'LlamaHire Fixture Studio' ) : '',
				'organization_url'    => 0 === $index % 3 ? ( 'demo' === $scenario ? home_url( '/product-studio/' ) : home_url( '/fixture-studio/' ) ) : '',
				'organization_logo'   => 0 === $index % 3 ? wp_get_attachment_url( $attachment ) : '',
				'application_method'  => $application_method,
				'application_target'  => $application_target,
			)
		);
		set_post_thumbnail( $job_id, $attachment );
		wp_set_object_terms( $job_id, array( $terms[ $index % count( $terms ) ] ), 'llamahire_department' );
		wp_set_object_terms( $job_id, array( $employment[ $index % count( $employment ) ] ), Jobs::TYPE_TAXONOMY );
		return (int) $job_id;
	}

	private function create_application( $scenario, $seed, $index, array $jobs ) {
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$application_jobs = $jobs;
		if ( 'state-matrix' === $scenario ) {
			$application_jobs = array_values(
				array_filter(
					$jobs,
					static function ( $job_id ) {
						$meta = Jobs::get_meta( $job_id );
						return 'publish' === get_post_status( $job_id ) && 'internal' === $meta['application_method'];
					}
				)
			);
		}
		$job_id = $application_jobs[ $index % count( $application_jobs ) ];
		$key = $this->uuid( $seed . '|application|' . $index );
		$resume_interval = 'state-matrix' === $scenario ? 3 : 4;
		$resume = 0 === $index % $resume_interval ? $this->create_resume( $seed, $index, $scenario ) : array( 'token' => '', 'name' => '' );
		$statuses = array( 'new', 'reviewing', 'interviewing', 'offer', 'hired', 'rejected' );
		$status = $statuses[ ( $index + ( 'demo' === $scenario ? 1 : 0 ) ) % count( $statuses ) ];
		$candidate_name = 'demo' === $scenario ? array( 'Avery Chen', 'Maya Patel', 'Jordan Williams', 'Sofia Garcia', 'Noah Kim', 'Amara Okafor', 'Theo Martin', 'Priya Shah', 'Lucas Silva', 'Emma Wilson', 'Kai Anderson', 'Nina Rossi', 'Owen Brown', 'Leila Haddad', 'Mateo Rivera', 'Zoe Thompson' )[ $index % 16 ] . ( $index >= 16 ? ' ' . ( 1 + intdiv( $index, 16 ) ) : '' ) : 'Fixture Candidate ' . ( $index + 1 );
		$phone = 'state-matrix' === $scenario && 1 === $index % 4 ? '' : '+1 604 555 ' . str_pad( (string) ( 1000 + $index ), 4, '0', STR_PAD_LEFT );
		$cover_letter = 'state-matrix' === $scenario && 2 === $index % 4 ? '' : ( 'demo' === $scenario ? 'I am excited about Northstar Labs because the role combines meaningful ownership, cross-functional collaboration, and a thoughtful approach to customer impact.' : 'I am applying through the deterministic ' . $scenario . ' fixture scenario. Candidate index: ' . ( $index + 1 ) . '.' );
		$created = $repository->create_once(
			array(
				'job_id'        => $job_id,
				'name'          => $candidate_name,
				'email'         => ( 'demo' === $scenario ? 'candidate' : 'fixture+' . $seed . '-' ) . ( $index + 1 ) . '@example.test',
				'phone'         => $phone,
				'cover_letter'  => $cover_letter,
				'resume_token'  => $resume['token'],
				'resume_name'   => $resume['name'],
				'status'        => 'new',
				'submission_key'=> $key,
			)
		);
		if ( is_wp_error( $created ) ) {
			if ( $resume['token'] ) {
				Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE )->delete( $resume['token'] );
			}
			throw new \RuntimeException( $created->get_error_message() );
		}
		$application_id = (int) $created['id'];
		$changes = array( 'status' => $status );
		if ( 'state-matrix' !== $scenario || 0 !== $index % 3 ) {
			$changes['notes'] = 'Private fixture review note for candidate ' . ( $index + 1 ) . '.';
		}
		$repository->update( $application_id, $changes );
		if ( 'state-matrix' === $scenario && 2 === $index % 3 ) {
			Application_Notes::add( $application_id, 'Second append-only fixture note for candidate ' . ( $index + 1 ) . '.' );
		}
		$this->set_application_state( $application_id, $scenario, $index );
		return array( 'id' => $application_id, 'key' => $key );
	}

	private function set_application_state( $application_id, $scenario, $index ) {
		global $wpdb;
		$states = array( 'pending', 'sent', 'partial', 'failed' );
		$state = 'notification-failures' === $scenario ? $states[ array( 2, 3, 3, 0 )[ $index % 4 ] ] : $states[ $index % 4 ];
		$created = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - HOUR_IN_SECONDS * ( $index + 1 ) );
		$employer = in_array( $state, array( 'sent', 'partial' ), true ) ? $created : null;
		$candidate = 'sent' === $state ? $created : null;
		$attempts = 'pending' === $state ? 0 : ( 'failed' === $state ? 2 : 1 );
		$wpdb->update(
			Applications::table(),
			array(
				'created_at'               => $created,
				'updated_at'               => $created,
				'stage_changed_at'         => $created,
				'notification_status'      => $state,
				'notification_attempts'    => $attempts,
				'employer_notified_at'     => $employer,
				'candidate_notified_at'    => $candidate,
				'notification_error_code'  => 'failed' === $state ? 'fixture_mail_failure' : ( 'partial' === $state ? 'candidate_mail_failure' : '' ),
			),
			array( 'id' => $application_id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private function create_logo( $seed, $scenario ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		if ( 'demo' === $scenario && function_exists( 'imagecreatetruecolor' ) ) {
			$image = imagecreatetruecolor( 512, 512 );
			$navy  = imagecolorallocate( $image, 25, 38, 63 );
			$coral = imagecolorallocate( $image, 244, 111, 96 );
			$white = imagecolorallocate( $image, 255, 255, 255 );
			imagefill( $image, 0, 0, $navy );
			imagefilledellipse( $image, 256, 230, 260, 260, $coral );
			imagestring( $image, 5, 215, 220, 'NL', $white );
			imagestring( $image, 4, 166, 400, 'NORTHSTAR', $white );
			ob_start();
			imagepng( $image );
			$png = ob_get_clean();
			imagedestroy( $image );
		}
		$upload = wp_upload_bits( 'llamahire-fixture-' . $seed . '.png', null, $png );
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( $upload['error'] );
		}
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'LlamaHire fixture logo', 'post_status' => 'inherit' ), $upload['file'], 0, true );
		if ( is_wp_error( $attachment ) ) {
			throw new \RuntimeException( $attachment->get_error_message() );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment, $metadata );
		}
		update_post_meta( $attachment, self::META, self::OWNER );
		return (int) $attachment;
	}

	private function demo_careers_content() {
		$featured = '<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">'
			. '<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Featured opportunities</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Start with a few of the roles where Northstar Labs is growing fastest.</p><!-- /wp:paragraph -->'
			. '<!-- wp:llamahire/featured-jobs {"align":"wide","showHeading":false,"perPage":3} /-->'
			. '</div><!-- /wp:group -->';
		$marker = '<!-- wp:group {"anchor":"open-roles"';

		return str_replace( $marker, $featured . $marker, Setup::careers_page_content() );
	}

	private function create_page( $title, $content, $slug ) {
		$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => 'llamahire-fixture-' . sanitize_title( $slug ), 'post_content' => $content ), true );
		if ( is_wp_error( $page_id ) ) {
			throw new \RuntimeException( $page_id->get_error_message() );
		}
		update_post_meta( $page_id, self::META, self::OWNER );
		return (int) $page_id;
	}

	private function create_resume( $seed, $index, $scenario = '' ) {
		$storage = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE );
		$health = $storage->health();
		if ( empty( $health['available'] ) ) {
			throw new \RuntimeException( 'Private resume storage is unavailable.' );
		}
		$directory_method = new \ReflectionMethod( get_class( $storage ), 'directory' );
		$directory_method->setAccessible( true );
		$directory = $directory_method->invoke( $storage, true );
		if ( is_wp_error( $directory ) ) {
			throw new \RuntimeException( 'Private resume storage is unavailable.' );
		}
		$is_docx = 'state-matrix' === $scenario && 3 === $index % 6 && class_exists( '\\ZipArchive' );
		$name = 'fixture-resume-' . $seed . '-' . ( $index + 1 ) . ( $is_docx ? '.docx' : '.pdf' );
		$path = trailingslashit( $directory ) . wp_unique_filename( $directory, $name );
		if ( $is_docx ) {
			$archive = new \ZipArchive();
			if ( true !== $archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new \RuntimeException( 'Could not create the fixture DOCX resume.' );
			}
			$archive->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
			$archive->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
			$archive->addFromString( 'word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Fictional state-matrix resume for local testing only.</w:t></w:r></w:p></w:body></w:document>' );
			$archive->close();
			return array( 'token' => $path, 'name' => $name );
		}
		$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
		if ( false === file_put_contents( $path, $pdf, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'Could not create the fixture resume.' );
		}
		return array( 'token' => $path, 'name' => $name );
	}

	private function remove_registered_data() {
		$registry = get_option( self::OPTION, false );
		if ( ! is_array( $registry ) || self::OWNER !== ( $registry['owner'] ?? '' ) ) {
			throw new \RuntimeException( 'The fixture registry is invalid; no records were removed.' );
		}
		$counts = array( 'jobs' => 0, 'applications' => 0, 'terms' => 0, 'pages' => 0, 'users' => 0, 'attachments' => 0 );
		global $wpdb;
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$storage = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE );
		foreach ( (array) $registry['applications'] as $application ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, submission_key, resume_path FROM ' . Applications::table() . ' WHERE id = %d', absint( $application['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $row && hash_equals( (string) ( $application['key'] ?? '' ), (string) $row->submission_key ) ) {
				$wpdb->delete( Audit_Log::table(), array( 'application_id' => absint( $row->id ) ), array( '%d' ) );
				if ( $row->resume_path ) {
					$storage->delete( $row->resume_path );
				}
				if ( $repository->delete( $row->id ) ) {
					$counts['applications']++;
				}
			}
		}
		foreach ( (array) $registry['jobs'] as $post_id ) {
			if ( self::OWNER === get_post_meta( $post_id, self::META, true ) ) {
				$wpdb->delete( Audit_Log::table(), array( 'job_id' => absint( $post_id ) ), array( '%d' ) );
			}
			if ( self::OWNER === get_post_meta( $post_id, self::META, true ) && wp_delete_post( $post_id, true ) ) {
				$counts['jobs']++;
			}
		}
		foreach ( (array) ( $registry['users'] ?? array() ) as $user_id ) {
			if ( self::OWNER !== get_user_meta( $user_id, self::META, true ) ) {
				continue;
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( wp_delete_user( $user_id ) ) {
				$counts['users']++;
			}
		}
		foreach ( (array) $registry['pages'] as $post_id ) {
			if ( self::OWNER === get_post_meta( $post_id, self::META, true ) && wp_delete_post( $post_id, true ) ) {
				$counts['pages']++;
			}
		}
		foreach ( (array) $registry['attachments'] as $post_id ) {
			if ( self::OWNER === get_post_meta( $post_id, self::META, true ) && wp_delete_attachment( $post_id, true ) ) {
				$counts['attachments']++;
			}
		}
		foreach ( (array) $registry['terms'] as $term_id ) {
			if ( self::OWNER === get_term_meta( $term_id, self::META, true ) ) {
				$result = wp_delete_term( $term_id, 'llamahire_department' );
				if ( ! is_wp_error( $result ) && $result ) {
					$counts['terms']++;
				}
			}
		}
		foreach ( (array) ( $registry['job_types'] ?? array() ) as $term_id ) {
			if ( self::OWNER === get_term_meta( $term_id, self::META, true ) ) {
				$result = wp_delete_term( $term_id, Jobs::TYPE_TAXONOMY );
				if ( ! is_wp_error( $result ) && $result ) {
					$counts['terms']++;
				}
			}
		}
		$options = (array) ( $registry['options'] ?? array() );
		if ( ! empty( $options['settings_exists'] ) ) {
			update_option( Settings::OPTION, $options['settings'], false );
		} else {
			delete_option( Settings::OPTION );
		}
		if ( ! empty( $options['setup_exists'] ) ) {
			update_option( Setup::OPTION, $options['setup'], false );
		} else {
			delete_option( Setup::OPTION );
		}
		if ( array_key_exists( 'show_on_front', $options ) ) {
			update_option( 'show_on_front', $options['show_on_front'] );
		}
		if ( array_key_exists( 'page_on_front', $options ) ) {
			update_option( 'page_on_front', absint( $options['page_on_front'] ) );
		}
		delete_option( self::OPTION );
		return $counts;
	}

	private function uuid( $value ) {
		$hex = md5( $value );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}

	private function save_registry( array $registry ) {
		update_option( self::OPTION, $registry, false );
	}

	private function require_safe_environment() {
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) ) {
			\WP_CLI::error( 'Fixture commands are disabled when WP_ENVIRONMENT_TYPE is production.' );
		}
	}
}
