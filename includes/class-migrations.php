<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Forward-only schema migrations must inspect and update the plugin's custom tables directly and cannot use cached results.

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
				$current = 5;
			}
			if ( $current < 6 ) {
				self::migration_6_backfill_filter_meta();
				update_option( self::OPTION, '6', false );
				$current = 6;
			}
			if ( $current < 7 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				self::migration_7_backfill_candidate_keys();
				update_option( self::OPTION, '7', false );
				$current = 7;
			}
			if ( $current < 8 ) {
				if ( ! self::migration_8_create_audit_table() ) {
					return false;
				}
				update_option( self::OPTION, '8', false );
				$current = 8;
			}
			if ( $current < 9 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				self::migration_9_backfill_stage_changed_at();
				update_option( self::OPTION, '9', false );
				$current = 9;
			}
			if ( $current < 10 ) {
				self::migration_10_convert_employment_types_to_terms();
				update_option( self::OPTION, '10', false );
				$current = 10;
			}
			if ( $current < 11 ) {
				if ( ! self::migration_1_create_applications_table() ) {
					return false;
				}
				update_option( self::OPTION, '11', false );
				$current = 11;
			}
			if ( $current < 12 ) {
				if ( ! self::migration_12_create_application_notes_table() ) {
					return false;
				}
				update_option( self::OPTION, '12', false );
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
			stage_changed_at datetime NULL,
			submission_key varchar(64) NULL DEFAULT NULL,
			notification_status varchar(20) NOT NULL DEFAULT 'pending',
			notification_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			employer_notified_at datetime NULL,
			candidate_notified_at datetime NULL,
			notification_error_code varchar(100) NOT NULL DEFAULT '',
			candidate_key varchar(64) NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY submission_key (submission_key),
			UNIQUE KEY candidate_key (candidate_key),
			KEY job_id (job_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY job_created (job_id, created_at, id),
			KEY job_status_created (job_id, status, created_at, id),
			KEY status_created (status, created_at, id),
			KEY notification_created (notification_status, created_at, id),
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
		$wpdb->query( "UPDATE {$table} SET notification_status = 'unknown' WHERE submission_key IS NULL AND notification_attempts = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built only from the trusted WordPress prefix and a fixed plugin-owned suffix.
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

	private static function migration_6_backfill_filter_meta() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Jobs::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array_chunk( $ids, 250 ) as $batch ) {
			foreach ( $batch as $job_id ) {
				Jobs::set_meta( $job_id, Jobs::get_meta( $job_id ) );
			}
		}
	}

	/**
	 * Assign one canonical key per existing job/email pair without deleting legacy duplicates.
	 */
	private static function migration_7_backfill_candidate_keys() {
		global $wpdb;
		$table = $wpdb->prefix . 'llamahire_applications';
		$seen  = array_fill_keys( array_filter( $wpdb->get_col( "SELECT candidate_key FROM {$table} WHERE candidate_key IS NOT NULL" ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built only from the trusted WordPress prefix and a fixed plugin-owned suffix.
		$rows  = $wpdb->get_results( "SELECT id, job_id, email FROM {$table} WHERE candidate_key IS NULL ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built only from the trusted WordPress prefix and a fixed plugin-owned suffix.
		foreach ( $rows as $row ) {
			$key = Applications::candidate_key( $row->job_id, $row->email );
			if ( ! $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$wpdb->update( $table, array( 'candidate_key' => $key ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			$seen[ $key ] = true;
		}
	}

	private static function migration_8_create_audit_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'llamahire_audit_log';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			subject_type varchar(20) NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			application_id bigint(20) unsigned NULL DEFAULT NULL,
			job_id bigint(20) unsigned NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			from_state varchar(50) NOT NULL DEFAULT '',
			to_state varchar(50) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_created (job_id, created_at, id),
			KEY application_created (application_id, created_at, id),
			KEY actor_created (actor_user_id, created_at, id),
			KEY event_created (event_type, created_at, id)
		) {$charset};";
		dbDelta( $sql );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function migration_9_backfill_stage_changed_at() {
		global $wpdb;
		$table = $wpdb->prefix . 'llamahire_applications';
		$wpdb->query( "UPDATE {$table} SET stage_changed_at = updated_at WHERE stage_changed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built only from the trusted WordPress prefix and a fixed plugin-owned suffix.
	}

	/**
	 * Preserve existing employment values as operator-managed job type terms.
	 */
	private static function migration_10_convert_employment_types_to_terms() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Jobs::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array_chunk( $ids, 250 ) as $batch ) {
			foreach ( $batch as $job_id ) {
				$stored = get_post_meta( $job_id, Jobs::META_KEY, true );
				$value  = is_array( $stored ) ? sanitize_text_field( $stored['employment_type'] ?? '' ) : '';
				if ( ! $value ) {
					continue;
				}
				$slug = sanitize_title( $value );
				$term = get_term_by( 'slug', $slug, Jobs::TYPE_TAXONOMY );
				if ( ! $term ) {
					$name = ucwords( strtolower( str_replace( array( '_', '-' ), ' ', $value ) ) );
					$created = wp_insert_term( $name, Jobs::TYPE_TAXONOMY, array( 'slug' => $slug ) );
					$term = is_wp_error( $created ) ? null : get_term( $created['term_id'], Jobs::TYPE_TAXONOMY );
				}
				if ( $term instanceof \WP_Term ) {
					wp_set_object_terms( $job_id, array( $term->term_id ), Jobs::TYPE_TAXONOMY );
					Jobs::set_meta( $job_id, array( 'employment_type' => $term->slug ) );
				}
			}
		}
	}

	private static function migration_12_create_application_notes_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = Application_Notes::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			body longtext NOT NULL,
			is_legacy tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY application_created (application_id, created_at, id),
			KEY author_created (author_user_id, created_at, id)
		) {$charset};";
		dbDelta( $sql );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return false;
		}

		$rows = $wpdb->get_results( 'SELECT id, notes, updated_at FROM ' . Applications::table() . " WHERE notes IS NOT NULL AND notes <> '' ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Applications::table() returns only the trusted WordPress prefix plus a fixed suffix.
		foreach ( $rows as $row ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE application_id = %d AND is_legacy = 1 LIMIT 1', $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is returned by Application_Notes::table(); the record ID is prepared.
			if ( ! $exists && is_wp_error( Application_Notes::add( $row->id, $row->notes, 0, $row->updated_at, true ) ) ) {
				return false;
			}
		}

		return true;
	}

	public static function failure_notice() {
		?>
		<div class="notice notice-error"><p><?php esc_html_e( 'LlamaHire could not update its application database. Candidate submissions may be unavailable until the database permissions or migration lock are resolved.', 'llamahire' ); ?></p></div>
		<?php
	}

	private function __construct() {}
}
