<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Application_Repository as Application_Repository_Contract;

defined( 'ABSPATH' ) || exit;

final class Application_Repository implements Application_Repository_Contract {
	public function create( array $application ) {
		$result = $this->create_once( $application );
		return is_wp_error( $result ) ? $result : $result['id'];
	}

	public function create_once( array $application ) {
		$application = wp_parse_args(
			$application,
			array(
				'job_id'       => 0,
				'name'         => '',
				'email'        => '',
				'phone'        => '',
				'cover_letter' => '',
				'resume_token' => '',
				'resume_name'  => '',
				'status'       => 'new',
				'submission_key' => '',
			)
		);
		if ( ! absint( $application['job_id'] ) || ! $application['name'] || ! is_email( $application['email'] ) ) {
			return new \WP_Error( 'llamahire_invalid_application', __( 'The application is missing required data.', 'llamahire' ) );
		}

		$statuses       = array( 'new', 'reviewing', 'rejected', 'hired' );
		$status         = in_array( $application['status'], $statuses, true ) ? $application['status'] : 'new';
		$submission_key = preg_match( '/^[a-f0-9-]{36}$/', (string) $application['submission_key'] ) ? $application['submission_key'] : null;
		$now            = current_time( 'mysql', true );
		global $wpdb;
		if ( $submission_key ) {
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Applications::table() . ' WHERE submission_key = %s', $submission_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $existing ) {
				return array( 'id' => (int) $existing, 'created' => false );
			}
		}
		$previous_errors = $wpdb->suppress_errors( true );
		$created = $wpdb->insert(
			Applications::table(),
			array(
				'job_id'       => absint( $application['job_id'] ),
				'name'         => sanitize_text_field( $application['name'] ),
				'email'        => sanitize_email( $application['email'] ),
				'phone'        => sanitize_text_field( $application['phone'] ),
				'cover_letter' => sanitize_textarea_field( $application['cover_letter'] ),
				'resume_path'  => (string) $application['resume_token'],
				'resume_name'  => sanitize_file_name( $application['resume_name'] ),
				'status'       => $status,
				'created_at'   => $now,
				'updated_at'   => $now,
				'submission_key' => $submission_key,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous_errors );

		if ( ! $created ) {
			if ( $submission_key ) {
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Applications::table() . ' WHERE submission_key = %s', $submission_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $existing ) {
					return array( 'id' => (int) $existing, 'created' => false );
				}
			}
			return new \WP_Error( 'llamahire_application_storage_failed', __( 'The application could not be stored.', 'llamahire' ) );
		}
		return array( 'id' => (int) $wpdb->insert_id, 'created' => true );
	}

	public function find( $application_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, job_id, name, email, phone, cover_letter, resume_name, (resume_path <> '') AS has_resume, status, notes, created_at, updated_at, notification_status, notification_attempts, employer_notified_at, candidate_notified_at, notification_error_code FROM " . Applications::table() . ' WHERE id = %d', absint( $application_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public function update( $application_id, array $changes ) {
		$allowed = array_intersect_key( $changes, array_flip( array( 'status', 'notes' ) ) );
		if ( isset( $allowed['status'] ) && ! in_array( $allowed['status'], array( 'new', 'reviewing', 'rejected', 'hired' ), true ) ) {
			return new \WP_Error( 'llamahire_invalid_status', __( 'The application status is invalid.', 'llamahire' ) );
		}
		if ( isset( $allowed['notes'] ) ) {
			$allowed['notes'] = sanitize_textarea_field( $allowed['notes'] );
		}
		if ( ! $allowed ) {
			return true;
		}
		$allowed['updated_at'] = current_time( 'mysql', true );
		$formats = array_fill( 0, count( $allowed ), '%s' );
		global $wpdb;
		$result = $wpdb->update( Applications::table(), $allowed, array( 'id' => absint( $application_id ) ), $formats, array( '%d' ) );
		return false !== $result;
	}

	public function delete( $application_id ) {
		global $wpdb;
		return false !== $wpdb->delete( Applications::table(), array( 'id' => absint( $application_id ) ), array( '%d' ) );
	}

	public function record_notification_result( $application_id, array $result ) {
		global $wpdb;
		$table = Applications::table();
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT employer_notified_at, candidate_notified_at, notification_attempts FROM {$table} WHERE id = %d", absint( $application_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $current ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ) );
		}
		$now = current_time( 'mysql', true );
		$employer_at = $current->employer_notified_at ?: ( ! empty( $result['employer'] ) ? $now : null );
		$candidate_at = $current->candidate_notified_at ?: ( ! empty( $result['candidate'] ) ? $now : null );
		$status = $employer_at && $candidate_at ? 'sent' : ( $employer_at || $candidate_at ? 'partial' : 'failed' );
		$codes = array_map( 'sanitize_key', (array) ( $result['error_codes'] ?? array() ) );
		if ( 'sent' !== $status && ! $codes ) {
			$codes[] = 'wp_mail_false';
		}
		$updated = $wpdb->update(
			$table,
			array(
				'notification_status'     => $status,
				'notification_attempts'   => (int) $current->notification_attempts + 1,
				'employer_notified_at'    => $employer_at,
				'candidate_notified_at'   => $candidate_at,
				'notification_error_code' => substr( implode( ',', array_unique( array_filter( $codes ) ) ), 0, 100 ),
				'updated_at'               => $now,
			),
			array( 'id' => absint( $application_id ) ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}
}
