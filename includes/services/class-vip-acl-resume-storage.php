<?php
namespace LlamaHire\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Store resumes as attachments protected by WordPress VIP Access-Controlled Files.
 */
final class VIP_ACL_Resume_Storage extends Resume_Storage {
	const TOKEN_PREFIX = 'attachment:';
	const MARKER_META  = '_llamahire_private_resume';
	const DIRECTORY    = 'llamahire-private';

	/**
	 * Register the VIP request visibility rule.
	 */
	public function __construct() {
		add_filter( 'vip_files_acl_file_visibility', array( $this, 'filter_file_visibility' ), 999, 2 );
	}

	/**
	 * Store a resume as a WordPress attachment in the protected prefix.
	 *
	 * @param array $file   One normalized $_FILES item.
	 * @param int   $job_id Job post ID.
	 * @return array|\WP_Error
	 */
	public function store_upload( array $file, $job_id ) {
		if ( ! $this->acl_available() ) {
			return new \WP_Error( 'resume_storage' );
		}
		$upload = $this->validate_upload( $file, $job_id );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		if ( '' === $upload['name'] ) {
			return array( 'token' => '', 'name' => '' );
		}
		$directory = $this->directory( true );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$contents = $filesystem->get_contents( $upload['tmp_name'] );
		if ( false === $contents ) {
			return new \WP_Error( 'resume_error' );
		}
		$path = trailingslashit( $directory ) . wp_generate_uuid4() . '.' . $upload['ext'];
		if ( ! $filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'resume_error' );
		}

		$attachment_id = $this->create_attachment( $path, $upload['type'] );
		if ( is_wp_error( $attachment_id ) ) {
			$filesystem->delete( $path, false, 'f' );
			return $attachment_id;
		}

		return array( 'token' => self::TOKEN_PREFIX . $attachment_id, 'name' => $upload['name'] );
	}

	/** Create and verify the attachment for an already validated private file. */
	private function create_attachment( $path, $mime_type ) {
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type,
				'post_title'     => 'Private candidate resume',
				'post_status'    => 'inherit',
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new \WP_Error( 'resume_error' );
		}
		// wp_insert_attachment() already saves the path; an identical update returns false.
		$attached = wp_normalize_path( get_attached_file( $attachment_id, true ) ) === wp_normalize_path( $path );
		$marked   = update_post_meta( $attachment_id, self::MARKER_META, '1' );
		if ( ! $attached || false === $marked ) {
			wp_delete_attachment( $attachment_id, true );

			return new \WP_Error( 'resume_error' );
		}

		return $attachment_id;
	}

	/**
	 * Delete an ACL attachment or a legacy local token.
	 *
	 * @param string $token Storage token.
	 * @return bool
	 */
	public function delete( $token ) {
		$attachment_id = $this->attachment_id( $token );
		if ( ! $attachment_id ) {
			return parent::delete( $token );
		}
		if ( ! get_post( $attachment_id ) ) {
			return true;
		}
		if ( ! $this->is_resume_attachment( $attachment_id ) ) {
			return false;
		}

		return false !== wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Determine whether an application has an available resume.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	public function has_resume( $application_id ) {
		$row = $this->record( $application_id );
		if ( ! $row || ! $row->resume_path ) {
			return false;
		}
		$attachment_id = $this->attachment_id( $row->resume_path );
		if ( ! $attachment_id ) {
			return parent::has_resume( $application_id );
		}

		return $this->is_resume_attachment( $attachment_id ) && false !== $this->attachment_contents( $attachment_id );
	}

	/**
	 * Stream an ACL attachment or a legacy local resume.
	 *
	 * @param int $application_id Application ID.
	 * @return \WP_Error
	 */
	public function stream( $application_id ) {
		$row = $this->record( $application_id );
		if ( ! $row || ! $row->resume_path ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}
		$attachment_id = $this->attachment_id( $row->resume_path );
		if ( ! $attachment_id ) {
			return parent::stream( $application_id );
		}
		$contents = $this->is_resume_attachment( $attachment_id ) ? $this->attachment_contents( $attachment_id ) : false;
		if ( false === $contents ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}

		$this->send_contents( $contents, $row->resume_name );
	}

	/**
	 * Report VIP ACL readiness.
	 *
	 * @return array
	 */
	public function health() {
		$uploads    = wp_get_upload_dir();
		$filesystem = $this->filesystem();
		$available  = $this->acl_available() && ! is_wp_error( $filesystem ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] );

		return array( 'available' => $available, 'outside_webroot' => false, 'protected' => $available, 'driver' => 'vip_acl' );
	}

	/**
	 * Deny direct requests for every file below LlamaHire's private prefix.
	 *
	 * @param mixed  $visibility Existing VIP visibility result.
	 * @param string $file_path  Requested file path.
	 * @return mixed
	 */
	public function filter_file_visibility( $visibility, $file_path ) {
		if ( $this->is_private_path( $file_path ) && defined( 'Automattic\VIP\Files\Acl\FILE_IS_PRIVATE_AND_DENIED' ) ) {
			return constant( 'Automattic\VIP\Files\Acl\FILE_IS_PRIVATE_AND_DENIED' );
		}

		return $visibility;
	}

	/**
	 * Resolve the dedicated uploads directory.
	 *
	 * @param bool $create Whether to create the directory.
	 * @return string|\WP_Error
	 */
	protected function directory( $create ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new \WP_Error( 'resume_storage' );
		}
		$directory  = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		if ( $filesystem->is_dir( $directory ) || ( $create && $filesystem->mkdir( $directory, FS_CHMOD_DIR ) ) ) {
			return $directory;
		}

		return new \WP_Error( 'resume_storage' );
	}

	/**
	 * Determine whether VIP Access-Controlled Files is active in this environment.
	 *
	 * @return bool
	 */
	private function acl_available() {
		$constant = defined( 'Automattic\VIP\Files\Acl\FILE_IS_PRIVATE_AND_DENIED' );
		$enabled  = (bool) get_option( 'vip_files_acl_restrict_all_enabled' ) || (bool) get_option( 'vip_files_acl_restrict_unpublished_enabled' );
		if ( defined( 'VIP_FILES_ACL_ENABLED' ) && ! VIP_FILES_ACL_ENABLED ) {
			return false;
		}

		return $constant && $enabled;
	}

	/**
	 * Parse an attachment token.
	 *
	 * @param string $token Storage token.
	 * @return int
	 */
	private function attachment_id( $token ) {
		$token = (string) $token;
		if ( 0 !== strpos( $token, self::TOKEN_PREFIX ) ) {
			return 0;
		}
		$id = substr( $token, strlen( self::TOKEN_PREFIX ) );

		return ctype_digit( $id ) ? absint( $id ) : 0;
	}

	/**
	 * Confirm that an attachment is owned by this driver and remains private.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_resume_attachment( $attachment_id ) {
		$path = get_attached_file( $attachment_id );

		return 'attachment' === get_post_type( $attachment_id ) && '1' === get_post_meta( $attachment_id, self::MARKER_META, true ) && $this->is_private_path( $path );
	}

	/**
	 * Read one attachment through the WordPress filesystem abstraction.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false
	 */
	private function attachment_contents( $attachment_id ) {
		$filesystem = $this->filesystem();
		$path       = get_attached_file( $attachment_id );
		if ( is_wp_error( $filesystem ) || ! $path || ! $filesystem->is_readable( $path ) ) {
			return false;
		}

		return $filesystem->get_contents( $path );
	}

	/**
	 * Match only the dedicated LlamaHire private uploads prefix.
	 *
	 * @param string $path File path or URL path.
	 * @return bool
	 */
	private function is_private_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return (bool) preg_match( '#(?:^|/)' . preg_quote( self::DIRECTORY, '#' ) . '/#', $path );
	}
}
