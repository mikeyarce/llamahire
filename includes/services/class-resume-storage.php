<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Resume_Storage as Resume_Storage_Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Store resumes in a private directory outside the WordPress web root.
 */
class Resume_Storage implements Resume_Storage_Contract {
	const MAX_BYTES = 5242880;

	/**
	 * Requested response disposition for the current private-file stream.
	 *
	 * @var string
	 */
	private $content_disposition = 'attachment';

	/**
	 * Store one validated resume upload.
	 *
	 * @param array $file   One normalized $_FILES item.
	 * @param int   $job_id Job post ID.
	 * @return array|\WP_Error
	 */
	public function store_upload( array $file, $job_id ) {
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

		return array( 'token' => $path, 'name' => $upload['name'] );
	}

	/**
	 * Delete one managed resume.
	 *
	 * @param string $token Storage token.
	 * @return bool
	 */
	public function delete( $token ) {
		$token = (string) $token;
		if ( '' === $token || ! $this->is_managed_path( $token, true ) ) {
			return false;
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}
		if ( ! $filesystem->exists( $token ) ) {
			return true;
		}

		return $filesystem->delete( $token, false, 'f' ) && ! $filesystem->exists( $token );
	}

	/**
	 * Determine whether an application has an available resume.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	public function has_resume( $application_id ) {
		$row = $this->record( $application_id );
		if ( ! $row || ! $row->resume_path || ! $this->is_managed_path( $row->resume_path ) ) {
			return false;
		}
		$filesystem = $this->filesystem();

		return ! is_wp_error( $filesystem ) && $filesystem->is_readable( $row->resume_path );
	}

	/**
	 * Stream one authorized resume download.
	 *
	 * @param int $application_id Application ID.
	 * @return \WP_Error
	 */
	public function stream( $application_id ) {
		$row = $this->record( $application_id );
		if ( ! $row || ! $row->resume_path || ! $this->is_managed_path( $row->resume_path ) ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) || ! $filesystem->is_readable( $row->resume_path ) ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}
		$contents = $filesystem->get_contents( $row->resume_path );
		if ( false === $contents ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}

		$this->send_contents( $contents, $row->resume_name );
	}

	/**
	 * Preview one authorized browser-renderable resume.
	 *
	 * The caller must authorize the request before invoking this method. Files
	 * that the browser cannot safely render continue to download as attachments.
	 *
	 * @param int $application_id Application ID.
	 * @return \WP_Error Returns only when the resume cannot be streamed.
	 */
	public function preview( $application_id ) {
		$this->content_disposition = 'inline';
		$result                    = $this->stream( $application_id );
		$this->content_disposition = 'attachment';

		return $result;
	}

	/**
	 * Report whether the private storage driver is ready.
	 *
	 * @return array
	 */
	public function health() {
		$directory  = $this->directory( true );
		$filesystem = $this->filesystem();
		if ( is_wp_error( $directory ) || is_wp_error( $filesystem ) ) {
			return array( 'available' => false, 'outside_webroot' => false, 'protected' => false, 'driver' => 'local_private' );
		}
		$outside_webroot = 0 !== strpos( wp_normalize_path( $directory ), trailingslashit( $this->wordpress_root() ) );
		$available       = $filesystem->is_dir( $directory ) && $filesystem->is_writable( $directory );

		return array( 'available' => $available, 'outside_webroot' => $outside_webroot, 'protected' => $available && $outside_webroot, 'driver' => 'local_private' );
	}

	/**
	 * Validate upload metadata and content.
	 *
	 * @param array $file   One normalized $_FILES item.
	 * @param int   $job_id Job post ID.
	 * @return array|\WP_Error
	 */
	protected function validate_upload( array $file, $job_id ) {
		if ( empty( $file['name'] ) ) {
			return array( 'tmp_name' => '', 'name' => '', 'ext' => '', 'type' => '' );
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'resume_size' );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new \WP_Error( 'resume_error' );
		}
		$name       = sanitize_file_name( wp_basename( $file['name'] ) );
		$filesystem = $this->filesystem();
		$size       = ! is_wp_error( $filesystem ) && $tmp_name ? $filesystem->size( $tmp_name ) : 0;
		if ( ! $size || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'resume_size' );
		}
		$checked = wp_check_filetype_and_ext( $tmp_name, $name, $this->allowed_mimes() );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new \WP_Error( 'resume_type' );
		}
		$signature = $this->validate_signature( $tmp_name, $checked['ext'] );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}
		$validation = apply_filters( 'llamahire_validate_resume_upload', true, $tmp_name, $name, $checked, absint( $job_id ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( true !== $validation ) {
			return new \WP_Error( 'resume_type' );
		}

		return array( 'tmp_name' => $tmp_name, 'name' => $name, 'ext' => $checked['ext'], 'type' => $checked['type'] );
	}

	/**
	 * Initialize the WordPress filesystem abstraction.
	 *
	 * @return \WP_Filesystem_Base|\WP_Error
	 */
	protected function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return new \WP_Error( 'resume_storage' );
		}

		return $wp_filesystem;
	}

	/**
	 * Retrieve only the fields required to serve a resume.
	 *
	 * @param int $application_id Application ID.
	 * @return object|null
	 */
	protected function record( $application_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT resume_path, resume_name FROM ' . Applications::table() . ' WHERE id = %d', absint( $application_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Applications::table() is trusted; private storage tokens must be read directly and remain current for downloads.
	}

	/**
	 * Resolve the outside-webroot storage directory.
	 *
	 * @param bool $create Whether to create the directory.
	 * @return string|\WP_Error
	 */
	protected function directory( $create ) {
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			$uploads  = wp_upload_dir();
			$fallback = trailingslashit( $uploads['basedir'] ) . 'llamahire-private';
			if ( empty( $uploads['error'] ) && ( $filesystem->is_dir( $fallback ) || ( $create && $filesystem->mkdir( $fallback, FS_CHMOD_DIR ) ) ) && $this->protect( $fallback ) ) {
				return $fallback;
			}
		}
		$outside = trailingslashit( dirname( untrailingslashit( $this->wordpress_root() ) ) ) . '.llamahire-private';
		if ( $this->path_allowed_by_open_basedir( $outside ) && ( $filesystem->is_dir( $outside ) || ( $create && $filesystem->mkdir( $outside, FS_CHMOD_DIR ) ) ) ) {
			return $outside;
		}

		return new \WP_Error( 'resume_storage' );
	}

	/**
	 * Add defense-in-depth deny files to the development-only uploads fallback.
	 *
	 * @param string $directory Storage directory.
	 * @return bool
	 */
	private function protect( $directory ) {
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}
		$rules = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
			'index.php'  => "<?php\nhttp_response_code( 403 );\nexit;\n",
		);
		foreach ( $rules as $file => $contents ) {
			$path = trailingslashit( $directory ) . $file;
			if ( ! $filesystem->exists( $path ) && ! $filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) ) {
				return false;
			}
			if ( ! $filesystem->is_file( $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Confirm that a legacy path belongs to this plugin.
	 *
	 * The uploads path remains recognized so sites can delete or serve legacy
	 * files and so local/development environments can use their protected
	 * fallback. Production local-driver files are never stored there.
	 *
	 * @param string $path          Candidate path.
	 * @param bool   $allow_missing Whether a missing final file is acceptable.
	 * @return bool
	 */
	protected function is_managed_path( $path, $allow_missing = false ) {
		if ( ! $this->path_allowed_by_open_basedir( $path ) ) {
			return false;
		}
		$real = realpath( $path );
		if ( ! $real && $allow_missing ) {
			$parent = realpath( dirname( $path ) );
			$real   = $parent ? trailingslashit( $parent ) . wp_basename( $path ) : false;
		}
		if ( ! $real ) {
			return false;
		}
		$directories = array( trailingslashit( dirname( untrailingslashit( $this->wordpress_root() ) ) ) . '.llamahire-private' );
		$uploads     = wp_upload_dir();
		$directories[] = trailingslashit( $uploads['basedir'] ) . 'llamahire-private';
		foreach ( $directories as $directory ) {
			if ( ! $this->path_allowed_by_open_basedir( $directory ) ) {
				continue;
			}
			$root = realpath( $directory );
			if ( $root && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check PHP's optional filesystem boundary before touching a private path.
	 *
	 * @param string $path Candidate file or directory path.
	 * @return bool
	 */
	private function path_allowed_by_open_basedir( $path ) {
		$restriction = (string) ini_get( 'open_basedir' );
		if ( '' === $restriction ) {
			return true;
		}

		$path = wp_normalize_path( $path );
		foreach ( explode( PATH_SEPARATOR, $restriction ) as $allowed ) {
			$allowed = untrailingslashit( wp_normalize_path( trim( $allowed ) ) );
			if ( '' !== $allowed && ( $path === $allowed || 0 === strpos( $path, trailingslashit( $allowed ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate the file signature and DOCX container shape.
	 *
	 * @param string $path      Temporary upload path.
	 * @param string $extension Validated extension.
	 * @return true|\WP_Error
	 */
	protected function validate_signature( $path, $extension ) {
		$filesystem = $this->filesystem();
		$header     = is_wp_error( $filesystem ) ? false : $filesystem->get_contents( $path );
		if ( false === $header ) {
			return new \WP_Error( 'resume_type' );
		}
		$header = substr( $header, 0, 8 );
		if ( 'pdf' === $extension && 0 !== strncmp( $header, '%PDF-', 5 ) ) {
			return new \WP_Error( 'resume_type' );
		}
		if ( 'doc' === $extension && "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" !== $header ) {
			return new \WP_Error( 'resume_type' );
		}
		if ( 'docx' === $extension ) {
			if ( 0 !== strncmp( $header, "PK\x03\x04", 4 ) ) {
				return new \WP_Error( 'resume_type' );
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new \WP_Error( 'resume_type' );
			}
			$archive = new \ZipArchive();
			if ( true !== $archive->open( $path ) ) {
				return new \WP_Error( 'resume_type' );
			}
			if ( false === $archive->locateName( '[Content_Types].xml' ) || false === $archive->locateName( 'word/document.xml' ) || false !== $archive->locateName( 'word/vbaProject.bin', \ZipArchive::FL_NOCASE ) ) {
				$archive->close();
				return new \WP_Error( 'resume_type' );
			}
			for ( $index = 0; $index < $archive->numFiles; $index++ ) {
				$entry = $archive->getNameIndex( $index );
				$entry = false === $entry ? false : str_replace( '\\', '/', $entry );
				if ( false === $entry || false !== strpos( $entry, '../' ) || 0 === strpos( $entry, '/' ) || preg_match( '/^[A-Za-z]:/', $entry ) ) {
					$archive->close();
					return new \WP_Error( 'resume_type' );
				}
			}
			$archive->close();
		}

		return true;
	}

	/**
	 * Send private file contents and terminate.
	 *
	 * @param string $contents File contents.
	 * @param string $name     Original display name.
	 * @return void
	 */
	protected function send_contents( $contents, $name ) {
		$type        = wp_check_filetype( $name, $this->delivery_mimes() );
		$mime        = $type['type'] ?: 'application/octet-stream';
		$disposition = 'inline' === $this->content_disposition && 'application/pdf' === $mime ? 'inline' : 'attachment';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . $disposition . '; filename="resume.' . ( $type['ext'] ?: 'bin' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . strlen( $contents ) );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'inline' === $disposition ) {
			header( "Content-Security-Policy: sandbox; default-src 'none'" );
		}
		header( 'Cache-Control: private, no-store, max-age=0' );
		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated binary download contents.
		exit;
	}

	/**
	 * Allowed resume MIME types.
	 *
	 * @return array
	 */
	protected function allowed_mimes() {
		$mimes = array( 'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
		if ( apply_filters( 'llamahire_allow_legacy_doc_uploads', false ) ) {
			$mimes['doc'] = 'application/msword';
		}

		return $mimes;
	}

	/**
	 * Recognized delivery types, including files accepted by older releases.
	 *
	 * This is intentionally separate from the upload allowlist so tightening new
	 * uploads does not corrupt the response metadata for an existing resume.
	 *
	 * @return array
	 */
	protected function delivery_mimes() {
		return array(
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'doc'  => 'application/msword',
		);
	}

	/**
	 * Resolve the normalized WordPress root.
	 *
	 * @return string
	 */
	protected function wordpress_root() {
		$root = realpath( ABSPATH );

		return wp_normalize_path( $root ?: ABSPATH );
	}
}
