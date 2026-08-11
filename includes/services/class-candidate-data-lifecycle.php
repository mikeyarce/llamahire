<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Application_Repository;
use LlamaHire\Contracts\Candidate_Data_Lifecycle as Candidate_Data_Lifecycle_Contract;
use LlamaHire\Contracts\Resume_Storage;
use LlamaHire\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle mutations target LlamaHire's private custom table and must not use a stale candidate-data cache.

final class Candidate_Data_Lifecycle implements Candidate_Data_Lifecycle_Contract {
	private $applications;
	private $resumes;

	public function __construct( Application_Repository $applications, Resume_Storage $resumes ) {
		$this->applications = $applications;
		$this->resumes      = $resumes;
	}

	public function cleanup_expired( $limit = 250, $now = null ) {
		$days   = Settings::retention_days();
		$result = array( 'examined' => 0, 'erased' => 0, 'failed' => 0 );
		if ( ! $days ) {
			return $result;
		}
		$limit  = min( 1000, max( 1, absint( $limit ) ) );
		$now    = null === $now ? current_time( 'timestamp', true ) : absint( $now );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Applications::table() . ' WHERE created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d', $cutoff, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Applications::table() returns only the trusted WordPress prefix plus a fixed suffix; values are prepared.
		foreach ( $ids as $application_id ) {
			$result['examined']++;
			$erased = $this->erase( $application_id );
			if ( is_wp_error( $erased ) || ! $erased ) {
				$result['failed']++;
			} else {
				$result['erased']++;
			}
		}
		do_action( 'llamahire_retention_cleanup_completed', $result, $cutoff );
		return $result;
	}

	public function erase( $application_id ) {
		$record = $this->private_record( $application_id );
		if ( ! $record ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ) );
		}
		if ( $record->resume_path && ! $this->resumes->delete( $record->resume_path ) ) {
			return new \WP_Error( 'llamahire_resume_delete_failed', __( 'The private resume could not be deleted, so the application was retained.', 'llamahire' ) );
		}
		if ( ! $this->applications->delete( $application_id ) ) {
			return new \WP_Error( 'llamahire_application_delete_failed', __( 'The application could not be erased.', 'llamahire' ) );
		}
		do_action( 'llamahire_application_erased', absint( $application_id ), absint( $record->job_id ) );
		return true;
	}

	public function delete_resume( $application_id ) {
		$record = $this->private_record( $application_id );
		if ( ! $record ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ) );
		}
		if ( $record->resume_path && ! $this->resumes->delete( $record->resume_path ) ) {
			return new \WP_Error( 'llamahire_resume_delete_failed', __( 'The private resume could not be deleted.', 'llamahire' ) );
		}
		global $wpdb;
		$updated = $wpdb->update(
			Applications::table(),
			array( 'resume_path' => '', 'resume_name' => '', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $application_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new \WP_Error( 'llamahire_resume_record_failed', __( 'The application could not be updated after deleting its resume.', 'llamahire' ) );
		}
		do_action( 'llamahire_application_resume_deleted', absint( $application_id ), absint( $record->job_id ) );
		return true;
	}

	public function replace_resume( $application_id, array $file ) {
		$record = $this->private_record( $application_id );
		if ( ! $record ) {
			return new \WP_Error( 'llamahire_application_not_found', __( 'Application not found.', 'llamahire' ) );
		}
		$resume = $this->resumes->store_upload( $file, $record->job_id );
		if ( is_wp_error( $resume ) ) {
			return $resume;
		}
		if ( empty( $resume['token'] ) ) {
			return new \WP_Error( 'llamahire_resume_required', __( 'Choose a resume to upload.', 'llamahire' ) );
		}
		global $wpdb;
		$updated = $wpdb->update(
			Applications::table(),
			array( 'resume_path' => $resume['token'], 'resume_name' => sanitize_file_name( $resume['name'] ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $application_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$this->resumes->delete( $resume['token'] );
			return new \WP_Error( 'llamahire_resume_record_failed', __( 'The replacement resume could not be attached.', 'llamahire' ) );
		}
		if ( $record->resume_path && ! $this->resumes->delete( $record->resume_path ) ) {
			$wpdb->update(
				Applications::table(),
				array( 'resume_path' => $record->resume_path, 'resume_name' => $record->resume_name, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => absint( $application_id ) ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$this->resumes->delete( $resume['token'] );
			return new \WP_Error( 'llamahire_resume_delete_failed', __( 'The previous resume could not be removed, so it was not replaced.', 'llamahire' ) );
		}
		do_action( 'llamahire_application_resume_replaced', absint( $application_id ), absint( $record->job_id ) );
		return true;
	}

	private function private_record( $application_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, job_id, resume_path, resume_name FROM ' . Applications::table() . ' WHERE id = %d', absint( $application_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Applications::table() returns only the trusted WordPress prefix plus a fixed suffix; the ID is prepared.
	}
}
