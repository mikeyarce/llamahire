<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent database schema migrations for the current site.
 */
final class Migrations {
	const OPTION = 'llamahire_schema_version';
	const LOCK   = 'llamahire_migration_lock';

	public static function maybe_run() {
		if ( (int) get_option( self::OPTION, 0 ) < (int) LLAMAHIRE_SCHEMA_VERSION && ! self::run() && is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'failure_notice' ) );
		}
	}

	/**
	 * Apply every migration needed by the current site.
	 *
	 * Safe to call repeatedly during activation, upgrade, or tests.
	 *
	 * @return bool True when the schema is current.
	 */
	public static function run() {
		if ( (int) get_option( self::OPTION, 0 ) >= (int) LLAMAHIRE_SCHEMA_VERSION ) {
			return true;
		}

		$locked = add_option( self::LOCK, time(), '', false );
		if ( ! $locked && time() - (int) get_option( self::LOCK, 0 ) > 5 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
			$locked = add_option( self::LOCK, time(), '', false );
		}
		if ( ! $locked ) {
			return false;
		}

		try {
			$current = (int) get_option( self::OPTION, 0 );
			if ( $current < 1 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				update_option( self::OPTION, '1', false );
				$current = 1;
			}
			if ( $current < 2 ) {
				self::migration_2_backfill_job_query_meta();
				update_option( self::OPTION, '2', false );
				$current = 2;
			}
			if ( $current < 3 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				update_option( self::OPTION, '3', false );
				$current = 3;
			}
			if ( $current < 4 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				self::migration_4_mark_legacy_notifications_unknown();
				update_option( self::OPTION, '4', false );
				$current = 4;
			}
			if ( $current < 5 ) {
				self::migration_5_upgrade_job_model();
				update_option( self::OPTION, '5', false );
			}
			delete_option( 'llamahire_db_version' );
		} finally {
			delete_option( self::LOCK );
		}

		return (int) get_option( self::OPTION, 0 ) >= (int) LLAMAHIRE_SCHEMA_VERSION;
	}

	private static function migration_1_create_applications_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'llamahire_applications';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			name varchar(190) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			cover_letter longtext NULL,
			resume_path varchar(500) NOT NULL DEFAULT '',
			resume_name varchar(255) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'new',
			notes longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			submission_key varchar(64) NULL DEFAULT NULL,
			notification_status varchar(20) NOT NULL DEFAULT 'pending',
			notification_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			employer_notified_at datetime NULL,
			candidate_notified_at datetime NULL,
			notification_error_code varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY submission_key (submission_key),
			KEY job_id (job_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY job_created (job_id, created_at, id),
			KEY job_status_created (job_id, status, created_at, id),
			KEY status_created (status, created_at, id),
			KEY job_email (job_id, email)
		) {$charset};";
		dbDelta( $sql );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function migration_2_backfill_job_query_meta() {
		$mappings = array(
			Jobs::META_WORKPLACE => array( 'source' => 'workplace', 'default' => 'onsite' ),
			Jobs::META_FEATURED  => array( 'source' => 'featured', 'default' => '0' ),
			Jobs::META_CLOSED    => array( 'source' => 'closed', 'default' => '0' ),
			Jobs::META_DEADLINE  => array( 'source' => 'deadline', 'default' => '' ),
		);
		global $wpdb;
		foreach ( $mappings as $target_key => $mapping ) {
			$sql = "SELECT posts.ID, source.meta_value FROM {$wpdb->posts} posts INNER JOIN {$wpdb->postmeta} source ON source.post_id = posts.ID AND source.meta_key = %s LEFT JOIN {$wpdb->postmeta} target ON target.post_id = posts.ID AND target.meta_key = %s WHERE posts.post_type = %s AND target.meta_id IS NULL";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, Jobs::META_KEY, $target_key, Jobs::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( array_chunk( $rows, 250 ) as $batch ) {
				$values = array();
				foreach ( $batch as $row ) {
					$data = maybe_unserialize( $row->meta_value );
					$value = is_array( $data ) && array_key_exists( $mapping['source'], $data ) ? $data[ $mapping['source'] ] : $mapping['default'];
					$values[] = $wpdb->prepare( '(%d,%s,%s)', $row->ID, $target_key, (string) $value );
				}
				if ( $values ) {
					$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			}
		}
	}

	private static function migration_4_mark_legacy_notifications_unknown() {
		global $wpdb;
		$table = $wpdb->prefix . 'llamahire_applications';
		$wpdb->query( "UPDATE {$table} SET notification_status = 'unknown' WHERE submission_key IS NULL AND notification_attempts = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Preserve legacy values while adding the structured Google Jobs fields.
	 */
	private static function migration_5_upgrade_job_model() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Jobs::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array_chunk( $ids, 250 ) as $batch ) {
			foreach ( $batch as $job_id ) {
				$stored = get_post_meta( $job_id, Jobs::META_KEY, true );
				$stored = is_array( $stored ) ? $stored : array();
				if ( empty( $stored['address_locality'] ) && ! empty( $stored['location'] ) ) {
					$stored['address_locality'] = $stored['location'];
				}
				if ( empty( $stored['salary_unit'] ) ) {
					$stored['salary_unit'] = 'YEAR';
				}
				if ( empty( $stored['job_identifier'] ) ) {
					$stored['job_identifier'] = 'llamahire-' . get_current_blog_id() . '-job-' . $job_id;
				}
				Jobs::set_meta( $job_id, $stored );
			}
		}
	}

	public static function failure_notice() {
		?>
		<div class="notice notice-error"><p><?php esc_html_e( 'LlamaHire could not update its application database. Candidate submissions may be unavailable until the database permissions or migration lock are resolved.', 'llamahire' ); ?></p></div>
		<?php
	}

	private function __construct() {}
}
