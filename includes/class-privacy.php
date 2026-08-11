<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal-data export and erasure integration.
 */
final class Privacy {
	const EXPORTER_ID = 'llamahire-applications';
	const GROUP_ID    = 'llamahire-applications';
	const PAGE_SIZE   = 100;

	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( array $exporters ) {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'LlamaHire applications', 'llamahire' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ) {
		$erasers[ self::EXPORTER_ID ] = array(
			'eraser_friendly_name' => __( 'LlamaHire applications', 'llamahire' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public static function export_personal_data( $email_address, $page = 1 ) {
		$email = strtolower( sanitize_email( $email_address ) );
		if ( ! is_email( $email ) ) {
			return array( 'data' => array(), 'done' => true );
		}

		$page   = max( 1, absint( $page ) );
		$result = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY )->search(
			array(
				'search'   => $email,
				'page'     => $page,
				'per_page' => self::PAGE_SIZE,
			)
		);
		$data = array();
		foreach ( $result['items'] as $application ) {
			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Job applications', 'llamahire' ),
				'item_id'     => 'llamahire-application-' . absint( $application->id ),
				'data'        => self::export_fields( $application ),
			);
		}

		return array(
			'data' => $data,
			'done' => $page >= (int) $result['pages'],
		);
	}

	public static function erase_personal_data( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required WordPress callback signature.
		$email = strtolower( sanitize_email( $email_address ) );
		if ( ! is_email( $email ) ) {
			return self::erasure_result( false, false, array(), true );
		}

		$query  = Plugin::instance()->services()->get( Service_IDs::APPLICATION_QUERY );
		$batch  = $query->search( array( 'search' => $email, 'page' => 1, 'per_page' => self::PAGE_SIZE ) );
		$erased = 0;
		$failed = 0;
		$lifecycle = Plugin::instance()->services()->get( Service_IDs::CANDIDATE_DATA );
		foreach ( $batch['items'] as $application ) {
			$result = $lifecycle->erase( $application->id );
			if ( is_wp_error( $result ) || ! $result ) {
				$failed++;
			} else {
				$erased++;
			}
		}

		$remaining = $query->search( array( 'search' => $email, 'page' => 1, 'per_page' => 1 ) );
		$messages  = array();
		if ( $failed ) {
			$messages[] = __( 'One or more LlamaHire applications could not be safely erased because their private data could not be removed.', 'llamahire' );
		}

		// Stop after a no-progress batch so WordPress cannot retry one failed record forever.
		$done = 0 === (int) $remaining['total'] || 0 === $erased;
		return self::erasure_result( $erased > 0, $failed > 0, $messages, $done );
	}

	private static function export_fields( $application ) {
		$fields = array(
			array( 'name' => __( 'Application ID', 'llamahire' ), 'value' => (string) absint( $application->id ) ),
			// translators: %d is the numeric WordPress job post ID.
			array( 'name' => __( 'Job', 'llamahire' ), 'value' => $application->job_title ?: sprintf( __( 'Deleted job #%d', 'llamahire' ), absint( $application->job_id ) ) ),
			array( 'name' => __( 'Candidate name', 'llamahire' ), 'value' => $application->name ),
			array( 'name' => __( 'Candidate email', 'llamahire' ), 'value' => $application->email ),
			array( 'name' => __( 'Application status', 'llamahire' ), 'value' => ucfirst( $application->status ) ),
			array( 'name' => __( 'Submitted', 'llamahire' ), 'value' => self::format_date( $application->created_at ) ),
			array( 'name' => __( 'Last updated', 'llamahire' ), 'value' => self::format_date( $application->updated_at ) ),
			array( 'name' => __( 'Email notification status', 'llamahire' ), 'value' => ucfirst( $application->notification_status ) ),
			array( 'name' => __( 'Email notification attempts', 'llamahire' ), 'value' => (string) absint( $application->notification_attempts ) ),
		);
		$optional = array(
			__( 'Phone', 'llamahire' )                       => $application->phone,
			__( 'Cover letter', 'llamahire' )                => $application->cover_letter,
			__( 'Resume filename', 'llamahire' )             => $application->resume_name,
			__( 'Private hiring notes', 'llamahire' )         => Application_Notes::export_text( $application->id ) ?: $application->notes,
			__( 'Email notification error', 'llamahire' )    => $application->notification_error_code,
			__( 'Employer notified', 'llamahire' )            => self::format_date( $application->employer_notified_at ),
			__( 'Candidate confirmation sent', 'llamahire' )  => self::format_date( $application->candidate_notified_at ),
		);
		foreach ( $optional as $name => $value ) {
			if ( '' !== (string) $value ) {
				$fields[] = array( 'name' => $name, 'value' => (string) $value );
			}
		}
		return $fields;
	}

	private static function format_date( $date ) {
		return $date ? get_date_from_gmt( $date, 'c' ) : '';
	}

	private static function erasure_result( $removed, $retained, array $messages, $done ) {
		return array(
			'items_removed'  => (bool) $removed,
			'items_retained' => (bool) $retained,
			'messages'       => $messages,
			'done'           => (bool) $done,
		);
	}
}
