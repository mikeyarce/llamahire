<?php
namespace LlamaHire;

use LlamaHire\Services\Resume_Storage;
use LlamaHire\Services\VIP_ACL_Resume_Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall operates on plugin-owned custom tables and removes its own transient rows.

/**
 * Permanently remove plugin-owned data after private files are deleted.
 *
 * @internal Loaded only by uninstall.php and the disposable smoke suite.
 */
final class Uninstaller {
	const APPLICATIONS_TABLE = 'llamahire_applications';
	const NOTES_TABLE        = 'llamahire_application_notes';
	const AUDIT_TABLE        = 'llamahire_audit_log';

	/**
	 * Remove all plugin-owned data without orphaning private resumes.
	 *
	 * Database records are retained when any resume cannot be deleted so a
	 * later cleanup attempt still has the private storage token it needs.
	 *
	 * @return bool Whether all requested data was removed.
	 */
	public static function remove_data() {
		if ( ! self::delete_private_resumes() ) {
			return false;
		}

		self::delete_jobs();
		self::delete_job_terms();
		self::remove_employer_user_data();
		self::drop_tables();
		self::delete_options();

		return true;
	}

	/**
	 * Delete a storage token through the matching managed storage driver.
	 *
	 * @param string                 $token Storage token.
	 * @param Resume_Storage         $local Local private storage driver.
	 * @param VIP_ACL_Resume_Storage $vip   VIP attachment storage driver.
	 * @return bool
	 */
	public static function delete_resume_token( $token, Resume_Storage $local, VIP_ACL_Resume_Storage $vip ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return true;
		}
		if ( 0 === strpos( $token, VIP_ACL_Resume_Storage::TOKEN_PREFIX ) ) {
			return $vip->delete( $token );
		}
		if ( path_is_absolute( $token ) ) {
			return $local->delete( $token );
		}

		/**
		 * Filters deletion of an opaque token owned by a custom resume driver.
		 *
		 * Custom drivers must register this filter from an active plugin or MU
		 * plugin bootstrap because LlamaHire itself is inactive during uninstall.
		 * Return true only after the private object is deleted, false on failure,
		 * or null when the token does not belong to the integration.
		 *
		 * @param bool|null $deleted Whether the token was handled successfully.
		 * @param string    $token   Private opaque storage token. Never log it.
		 * @param int       $site_id Current WordPress site ID.
		 */
		$deleted = apply_filters( 'llamahire_uninstall_delete_resume_token', null, $token, get_current_blog_id() );

		return true === $deleted;
	}

	/**
	 * Delete referenced resumes and marked VIP attachments in bounded batches.
	 *
	 * @return bool
	 */
	private static function delete_private_resumes() {
		global $wpdb;
		$local       = new Resume_Storage();
		$vip         = new VIP_ACL_Resume_Storage();
		$applications = $wpdb->prefix . self::APPLICATIONS_TABLE;
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $applications ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect the plugin-owned table before deleting private files.
		$last_id      = 0;

		if ( $applications === $table_exists ) {
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, resume_path FROM {$applications} WHERE id > %d ORDER BY id ASC LIMIT 250", $last_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is derived only from the trusted WordPress prefix.
				foreach ( $rows as $row ) {
					$last_id = (int) $row->id;
					if ( ! self::delete_resume_token( $row->resume_path, $local, $vip ) ) {
						return false;
					}
				}
			} while ( 250 === count( $rows ) );
		}

		$last_id = 0;
		do {
			$attachment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT posts.ID FROM {$wpdb->posts} posts INNER JOIN {$wpdb->postmeta} marker ON marker.post_id = posts.ID WHERE posts.post_type = 'attachment' AND posts.ID > %d AND marker.meta_key = %s AND marker.meta_value = '1' ORDER BY posts.ID ASC LIMIT 250",
					$last_id,
					VIP_ACL_Resume_Storage::MARKER_META
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table names are trusted and orphaned private attachments must be found during uninstall.
			foreach ( $attachment_ids as $attachment_id ) {
				$last_id = (int) $attachment_id;
				if ( ! $vip->delete( VIP_ACL_Resume_Storage::TOKEN_PREFIX . $last_id ) ) {
					return false;
				}
			}
		} while ( 250 === count( $attachment_ids ) );

		return true;
	}

	/**
	 * Permanently delete plugin job posts while retaining unrelated media.
	 */
	private static function delete_jobs() {
		do {
			$job_ids = get_posts(
				array(
					'post_type'              => 'llamahire_job',
					'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ),
					'fields'                 => 'ids',
					'posts_per_page'         => 100,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $job_ids as $job_id ) {
				wp_delete_post( $job_id, true );
			}
		} while ( 100 === count( $job_ids ) );
	}

	/**
	 * Remove terms from the plugin's registered taxonomies.
	 */
	private static function delete_job_terms() {
		foreach ( array( 'llamahire_department', 'llamahire_job_type' ) as $taxonomy ) {
			$registered_here = false;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$registered = register_taxonomy(
					$taxonomy,
					'llamahire_job',
					array(
						'public'    => false,
						'query_var' => false,
						'rewrite'   => false,
					)
				);
				if ( is_wp_error( $registered ) ) {
					continue;
				}
				$registered_here = true;
			}
			$term_ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) ) {
				foreach ( $term_ids as $term_id ) {
					wp_delete_term( $term_id, $taxonomy );
				}
			}
			if ( $registered_here ) {
				unregister_taxonomy( $taxonomy );
			}
		}
	}

	/**
	 * Remove plugin roles and metadata without deleting WordPress users.
	 */
	private static function remove_employer_user_data() {
		do {
			$user_ids = get_users( array( 'role' => 'llamahire_employer', 'fields' => 'ids', 'number' => 100 ) );
			foreach ( $user_ids as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$user->remove_role( 'llamahire_employer' );
				}
			}
		} while ( 100 === count( $user_ids ) );

		foreach ( array( '_llamahire_employer_status', '_llamahire_employer_verification_hash', '_llamahire_employer_verification_expires', '_llamahire_employer_company', '_llamahire_employer_policy_version', '_llamahire_employer_policy_accepted_at' ) as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}
	}

	/**
	 * Drop plugin-owned custom tables after private-file cleanup succeeds.
	 */
	private static function drop_tables() {
		global $wpdb;
		foreach ( array( self::NOTES_TABLE, self::APPLICATIONS_TABLE, self::AUDIT_TABLE ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built only from the trusted WordPress prefix and a fixed plugin-owned suffix.
		}
	}

	/**
	 * Remove plugin options and transient rate counters.
	 */
	private static function delete_options() {
		foreach ( array( 'llamahire_db_version', 'llamahire_schema_version', 'llamahire_capabilities_version', 'llamahire_organization', 'llamahire_setup', 'llamahire_settings', 'llamahire_email_diagnostics' ) as $option ) {
			delete_option( $option );
		}

		global $wpdb;
		$like = $wpdb->esc_like( '_transient_llamahire_rate_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for bounded plugin-owned transient keys.
		$timeout_like = $wpdb->esc_like( '_transient_timeout_llamahire_rate_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for bounded plugin-owned transient timeout keys.
	}

	private function __construct() {}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
