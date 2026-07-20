<?php
namespace LlamaHire\Tools;

use LlamaHire\Applications;
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
	const OWNER  = 'llamahire-fixtures-v1';
	const META   = '_llamahire_fixture_owner';

	/**
	 * Generate a complete demo hiring dataset.
	 *
	 * ## OPTIONS
	 *
	 * [--scenario=<scenario>]
	 * : small, large, remote, expired, closed, notification-failures, or edge-cases. Default: small.
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
	 *     wp llamahire fixtures generate --scenario=edge-cases --seed=bug-142 --force
	 *
	 * @subcommand generate
	 */
	public function generate( $args, $assoc_args ) {
		$this->require_safe_environment();
		$scenario = sanitize_key( $assoc_args['scenario'] ?? 'small' );
		$scenarios = $this->scenarios();
		if ( ! isset( $scenarios[ $scenario ] ) ) {
			\WP_CLI::error( 'Unknown scenario. Use small, large, remote, expired, closed, notification-failures, or edge-cases.' );
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
			'version'      => 1,
			'owner'        => self::OWNER,
			'scenario'     => $scenario,
			'seed'         => $seed,
			'created_at'   => current_time( 'mysql', true ),
			'jobs'         => array(),
			'terms'        => array(),
			'pages'        => array(),
			'attachments'  => array(),
			'applications' => array(),
			'options'      => array(
				'settings_exists' => false !== get_option( Settings::OPTION, false ),
				'settings'        => get_option( Settings::OPTION, false ),
				'setup_exists'    => false !== get_option( Setup::OPTION, false ),
				'setup'           => get_option( Setup::OPTION, false ),
			),
		);
		update_option( self::OPTION, $registry, false );

		try {
			$attachment = $this->create_logo( $seed );
			$registry['attachments'][] = $attachment;
			$this->save_registry( $registry );

			$department_names = array( 'Engineering', 'Design', 'Marketing', 'Customer Success', 'Operations' );
			foreach ( $department_names as $department_name ) {
				$term = wp_insert_term( $department_name . ' ' . strtoupper( substr( md5( $seed ), 0, 4 ) ), 'llamahire_department' );
				if ( is_wp_error( $term ) ) {
					throw new \RuntimeException( $term->get_error_message() );
				}
				$term_id = (int) $term['term_id'];
				update_term_meta( $term_id, self::META, self::OWNER );
				$registry['terms'][] = $term_id;
			}
			$this->save_registry( $registry );

			$privacy_page = $this->create_page( 'Fixture candidate privacy', '<!-- wp:paragraph --><p>This test-site policy explains how fixture candidate data is used.</p><!-- /wp:paragraph -->', $seed . '-privacy' );
			$careers_page = $this->create_page( 'Fixture careers', Setup::careers_page_content(), $seed . '-careers' );
			$registry['pages'] = array( $privacy_page, $careers_page );
			$this->save_registry( $registry );

			$logo_url = wp_get_attachment_url( $attachment );
			update_option(
				Settings::OPTION,
				Settings::sanitize(
					array(
						'name'               => 'LlamaHire Fixture Company',
						'website'            => home_url( '/' ),
						'logo'               => $logo_url,
						'default_locality'   => 'Vancouver',
						'default_region'     => 'British Columbia',
						'default_country'    => 'CA',
						'default_currency'   => 'CAD',
						'notification_email' => 'hiring-fixtures@example.test',
						'privacy_text'       => 'Fixture candidate information is used only for product testing.',
						'privacy_page_id'    => $privacy_page,
						'careers_page_id'    => $careers_page,
					)
				)
			);
			update_option( Setup::OPTION, array( 'version' => Setup::VERSION, 'status' => 'completed' ), false );

			for ( $index = 0; $index < $job_count; $index++ ) {
				$job_id = $this->create_job( $scenario, $seed, $index, $attachment, $registry['terms'] );
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

		\WP_CLI::success( sprintf( 'Created %1$d jobs, %2$d applications, %3$d departments, two pages, and one Media Library image for the %4$s scenario.', $job_count, $application_count, count( $registry['terms'] ), $scenario ) );
		\WP_CLI::log( 'Careers page: ' . get_permalink( $careers_page ) );
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
		\WP_CLI::success( sprintf( 'Removed %1$d jobs, %2$d applications, %3$d departments, %4$d pages, and %5$d attachments owned by LlamaHire fixtures.', $counts['jobs'], $counts['applications'], $counts['terms'], $counts['pages'], $counts['attachments'] ) );
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
			array( 'property' => 'Departments', 'value' => count( $registry['terms'] ) ),
			array( 'property' => 'Pages', 'value' => count( $registry['pages'] ) ),
			array( 'property' => 'Attachments', 'value' => count( $registry['attachments'] ) ),
			array( 'property' => 'Created (UTC)', 'value' => $registry['created_at'] ),
		);
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'property', 'value' ) );
	}

	private function scenarios() {
		return array(
			'small'                 => array( 'jobs' => 8, 'applications' => 30 ),
			'large'                 => array( 'jobs' => 60, 'applications' => 1000 ),
			'remote'                => array( 'jobs' => 10, 'applications' => 40 ),
			'expired'               => array( 'jobs' => 8, 'applications' => 24 ),
			'closed'                => array( 'jobs' => 8, 'applications' => 24 ),
			'notification-failures' => array( 'jobs' => 6, 'applications' => 30 ),
			'edge-cases'            => array( 'jobs' => 12, 'applications' => 48 ),
		);
	}

	private function create_job( $scenario, $seed, $index, $attachment, array $terms ) {
		$titles = array( 'Senior Product Designer', 'Backend Engineer', 'Customer Success Lead', 'Content Strategist', 'People Operations Partner', 'Data Analyst', 'Frontend Engineer', 'Growth Marketer' );
		$workplaces = array( 'onsite', 'hybrid', 'remote' );
		$employment = array( 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' );
		$workplace = 'remote' === $scenario ? 'remote' : $workplaces[ $index % count( $workplaces ) ];
		$status = 'edge-cases' === $scenario && 0 === $index % 5 ? 'draft' : 'publish';
		$job_id = wp_insert_post(
			array(
				'post_type'    => Jobs::POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $titles[ $index % count( $titles ) ] . ' — Fixture ' . ( $index + 1 ),
				'post_name'    => 'llamahire-fixture-' . $seed . '-' . ( $index + 1 ),
				'post_excerpt' => 'A deterministic ' . $scenario . ' scenario role for LlamaHire testing.',
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">About the role</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Help the fixture company test a complete, realistic hiring workflow.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">What you will do</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Own meaningful work</li><li>Collaborate across teams</li><li>Improve the candidate experience</li></ul><!-- /wp:list -->',
			),
			true
		);
		if ( is_wp_error( $job_id ) ) {
			throw new \RuntimeException( $job_id->get_error_message() );
		}
		update_post_meta( $job_id, self::META, self::OWNER );
		$deadline_days = 20 + ( $index % 50 );
		$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS * $deadline_days );
		if ( 'expired' === $scenario || ( 'edge-cases' === $scenario && 1 === $index % 5 ) ) {
			$deadline = wp_date( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS * ( 1 + $index ) );
		}
		$closed = 'closed' === $scenario || ( 'edge-cases' === $scenario && 2 === $index % 5 ) ? '1' : '0';
		$salary_min = 50000 + ( $index % 8 ) * 7500;
		$salary_max = $salary_min + 15000;
		if ( 'edge-cases' === $scenario && 3 === $index % 5 ) {
			$salary_max = $salary_min;
		}
		if ( 'edge-cases' === $scenario && 4 === $index % 5 ) {
			$salary_min = '';
			$salary_max = '';
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
				'salary_unit'         => 0 === $index % 4 ? 'HOUR' : 'YEAR',
				'deadline'            => $deadline,
				'featured'            => 0 === $index % 4 ? '1' : '0',
				'closed'              => $closed,
				'address_street'      => ( 100 + $index ) . ' Fixture Street',
				'address_locality'    => 'Vancouver',
				'address_region'      => 'British Columbia',
				'postal_code'         => 'V6B 1A1',
				'address_country'     => 'CA',
				'applicant_countries' => 'CA, US, GB',
				'job_identifier'      => 'fixture-' . $seed . '-job-' . ( $index + 1 ),
				'organization_name'   => 0 === $index % 3 ? 'LlamaHire Fixture Studio' : '',
				'organization_url'    => 0 === $index % 3 ? home_url( '/fixture-studio/' ) : '',
				'organization_logo'   => 0 === $index % 3 ? wp_get_attachment_url( $attachment ) : '',
			)
		);
		set_post_thumbnail( $job_id, $attachment );
		wp_set_object_terms( $job_id, array( $terms[ $index % count( $terms ) ] ), 'llamahire_department' );
		return (int) $job_id;
	}

	private function create_application( $scenario, $seed, $index, array $jobs ) {
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$job_id = $jobs[ $index % count( $jobs ) ];
		$key = $this->uuid( $seed . '|application|' . $index );
		$resume = 0 === $index % 4 ? $this->create_resume( $seed, $index ) : array( 'token' => '', 'name' => '' );
		$statuses = array( 'new', 'reviewing', 'rejected', 'hired' );
		$status = $statuses[ $index % count( $statuses ) ];
		$created = $repository->create_once(
			array(
				'job_id'        => $job_id,
				'name'          => 'Fixture Candidate ' . ( $index + 1 ),
				'email'         => 'fixture+' . $seed . '-' . ( $index + 1 ) . '@example.test',
				'phone'         => '+1 604 555 ' . str_pad( (string) ( 1000 + $index ), 4, '0', STR_PAD_LEFT ),
				'cover_letter'  => 'I am applying through the deterministic ' . $scenario . ' fixture scenario. Candidate index: ' . ( $index + 1 ) . '.',
				'resume_token'  => $resume['token'],
				'resume_name'   => $resume['name'],
				'status'        => $status,
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
		$repository->update( $application_id, array( 'status' => $status, 'notes' => 'Private fixture review note for candidate ' . ( $index + 1 ) . '.' ) );
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
				'notification_status'      => $state,
				'notification_attempts'    => $attempts,
				'employer_notified_at'     => $employer,
				'candidate_notified_at'    => $candidate,
				'notification_error_code'  => 'failed' === $state ? 'fixture_mail_failure' : ( 'partial' === $state ? 'candidate_mail_failure' : '' ),
			),
			array( 'id' => $application_id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private function create_logo( $seed ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		$upload = wp_upload_bits( 'llamahire-fixture-' . $seed . '.png', null, $png );
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( $upload['error'] );
		}
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'LlamaHire fixture logo', 'post_status' => 'inherit' ), $upload['file'], 0, true );
		if ( is_wp_error( $attachment ) ) {
			throw new \RuntimeException( $attachment->get_error_message() );
		}
		update_post_meta( $attachment, self::META, self::OWNER );
		return (int) $attachment;
	}

	private function create_page( $title, $content, $slug ) {
		$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => 'llamahire-fixture-' . sanitize_title( $slug ), 'post_content' => $content ), true );
		if ( is_wp_error( $page_id ) ) {
			throw new \RuntimeException( $page_id->get_error_message() );
		}
		update_post_meta( $page_id, self::META, self::OWNER );
		return (int) $page_id;
	}

	private function create_resume( $seed, $index ) {
		$storage = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE );
		$health = $storage->health();
		if ( empty( $health['available'] ) ) {
			throw new \RuntimeException( 'Private resume storage is unavailable.' );
		}
		$outside = trailingslashit( dirname( untrailingslashit( wp_normalize_path( ABSPATH ) ) ) ) . '.llamahire-private';
		if ( is_dir( $outside ) && is_writable( $outside ) ) {
			$directory = $outside;
		} else {
			$uploads = wp_upload_dir();
			$directory = trailingslashit( $uploads['basedir'] ) . 'llamahire-private';
		}
		$name = 'fixture-resume-' . $seed . '-' . ( $index + 1 ) . '.pdf';
		$path = trailingslashit( $directory ) . wp_unique_filename( $directory, $name );
		$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
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
		$counts = array( 'jobs' => 0, 'applications' => 0, 'terms' => 0, 'pages' => 0, 'attachments' => 0 );
		global $wpdb;
		$repository = Plugin::instance()->services()->get( Service_IDs::APPLICATION_REPOSITORY );
		$storage = Plugin::instance()->services()->get( Service_IDs::RESUME_STORAGE );
		foreach ( (array) $registry['applications'] as $application ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, submission_key, resume_path FROM ' . Applications::table() . ' WHERE id = %d', absint( $application['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $row && hash_equals( (string) ( $application['key'] ?? '' ), (string) $row->submission_key ) ) {
				if ( $row->resume_path ) {
					$storage->delete( $row->resume_path );
				}
				if ( $repository->delete( $row->id ) ) {
					$counts['applications']++;
				}
			}
		}
		foreach ( (array) $registry['jobs'] as $post_id ) {
			if ( self::OWNER === get_post_meta( $post_id, self::META, true ) && wp_delete_post( $post_id, true ) ) {
				$counts['jobs']++;
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
