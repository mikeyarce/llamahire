<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private application notes require current, ownership-scoped custom-table reads and writes.

/**
 * Append-only private note history for candidate applications.
 */
final class Application_Notes {
	const MAX_LENGTH = 5000;

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'llamahire_application_notes';
	}

	/**
	 * Add one private note.
	 *
	 * @param int         $application_id Application ID.
	 * @param string      $body           Note body.
	 * @param int|null    $author_user_id Author user ID, or current user.
	 * @param string|null $created_at     UTC creation timestamp.
	 * @param bool        $legacy         Whether this preserves a legacy note.
	 * @return object|\WP_Error
	 */
	public static function add( $application_id, $body, $author_user_id = null, $created_at = null, $legacy = false ) {
		$application_id = absint( $application_id );
		$body           = sanitize_textarea_field( $body );
		if ( ! $application_id || '' === trim( $body ) ) {
			return new \WP_Error( 'llamahire_note_required', __( 'Write a private note before adding it.', 'llamahire' ) );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $body ) : strlen( $body );
		if ( self::MAX_LENGTH < $length ) {
			return new \WP_Error( 'llamahire_note_too_long', __( 'The private note is too long.', 'llamahire' ) );
		}

		global $wpdb;
		$job_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT job_id FROM ' . Applications::table() . ' WHERE id = %d', $application_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Applications::table() returns only the trusted WordPress prefix plus a fixed suffix; the ID is prepared.
		if ( ! $job_id ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ) );
		}
		$created_at = is_string( $created_at ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created_at ) ? $created_at : current_time( 'mysql', true );
		$inserted   = $wpdb->insert(
			self::table(),
			array(
				'application_id' => $application_id,
				'author_user_id' => null === $author_user_id ? get_current_user_id() : absint( $author_user_id ),
				'body'           => $body,
				'is_legacy'      => $legacy ? 1 : 0,
				'created_at'     => $created_at,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'llamahire_note_storage_failed', __( 'The private note could not be added.', 'llamahire' ) );
		}
		$note = self::find( (int) $wpdb->insert_id );
		if ( ! $legacy ) {
			Audit_Log::record( 'application_note_added', $job_id, $application_id );
		}

		return $note;
	}

	public static function find( $note_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, application_id, author_user_id, body, is_legacy, created_at FROM ' . self::table() . ' WHERE id = %d', absint( $note_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() returns only the trusted WordPress prefix plus a fixed suffix; the ID is prepared.
	}

	public static function for_application( $application_id, $limit = 50 ) {
		global $wpdb;
		$limit = min( 500, max( 1, absint( $limit ) ) );

		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, application_id, author_user_id, body, is_legacy, created_at FROM ' . self::table() . ' WHERE application_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', absint( $application_id ), $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- self::table() returns only the trusted WordPress prefix plus a fixed suffix; values are prepared.
	}

	public static function delete_for_application( $application_id ) {
		global $wpdb;

		return false !== $wpdb->delete( self::table(), array( 'application_id' => absint( $application_id ) ), array( '%d' ) );
	}

	public static function export_text( $application_id ) {
		$entries = array();
		foreach ( self::for_application( $application_id, 500 ) as $note ) {
			$author = self::author_label( $note );
			$entries[] = sprintf( '[%1$s — %2$s]' . "\n" . '%3$s', get_date_from_gmt( $note->created_at, 'c' ), $author, $note->body );
		}

		return implode( "\n\n", $entries );
	}

	public static function author_label( $note ) {
		$user = $note->author_user_id ? get_userdata( $note->author_user_id ) : null;
		if ( $note->is_legacy ) {
			return __( 'Saved before note history', 'llamahire' );
		}
		if ( $user ) {
			return $user->display_name;
		}
		return $note->author_user_id ? __( 'Former user', 'llamahire' ) : __( 'System', 'llamahire' );
	}

	private function __construct() {}
}
