<?php
namespace LlamaHire\Services;

use LlamaHire\Applications;
use LlamaHire\Contracts\Resume_Storage as Resume_Storage_Contract;

defined( 'ABSPATH' ) || exit;

final class Resume_Storage implements Resume_Storage_Contract {
	const MAX_BYTES = 5242880;

	public function store_upload( array $file, $job_id ) {
		if ( empty( $file['name'] ) ) {
			return array( 'token' => '', 'name' => '' );
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'resume_size' );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$name     = sanitize_file_name( wp_basename( $file['name'] ) );
		$size     = $tmp_name && is_file( $tmp_name ) ? filesize( $tmp_name ) : 0;
		if ( ! $size || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'resume_size' );
		}
		$checked  = wp_check_filetype_and_ext( $tmp_name, $name, $this->allowed_mimes() );
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

		$directory = $this->directory( true );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$path = trailingslashit( $directory ) . wp_generate_uuid4() . '.' . $checked['ext'];
		if ( ! @move_uploaded_file( $tmp_name, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,Generic.PHP.ForbiddenFunctions.Found -- Preserves PHP's HTTP-upload provenance check.
			return new \WP_Error( 'resume_error' );
		}
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts a private candidate file after the atomic upload move.
		return array( 'token' => $path, 'name' => $name );
	}

	public function delete( $token ) {
		$token = (string) $token;
		if ( '' === $token || ! $this->is_managed_path( $token ) ) {
			return false;
		}
		if ( ! file_exists( $token ) ) {
			return true;
		}
		wp_delete_file( $token );
		return ! file_exists( $token );
	}

	public function has_resume( $application_id ) {
		$row = $this->record( $application_id );
		return $row && $row->resume_path && $this->is_managed_path( $row->resume_path ) && is_readable( $row->resume_path );
	}

	public function stream( $application_id ) {
		$row = $this->record( $application_id );
		if ( ! $row || ! $row->resume_path || ! $this->is_managed_path( $row->resume_path ) || ! is_readable( $row->resume_path ) ) {
			return new \WP_Error( 'llamahire_resume_not_found', __( 'Resume not found.', 'llamahire' ) );
		}
		$type = wp_check_filetype( $row->resume_name, $this->allowed_mimes() );
		header( 'Content-Type: ' . ( $type['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="resume.' . ( $type['ext'] ?: 'bin' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $row->resume_name ) );
		header( 'Content-Length: ' . filesize( $row->resume_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		readfile( $row->resume_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function health() {
		$directory = $this->directory( true );
		if ( is_wp_error( $directory ) ) {
			return array( 'available' => false, 'outside_webroot' => false );
		}
		return array( 'available' => is_dir( $directory ) && is_writable( $directory ), 'outside_webroot' => 0 !== strpos( wp_normalize_path( $directory ), trailingslashit( $this->wordpress_root() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Site Health must test actual private-directory writability.
	}

	private function record( $application_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT resume_path, resume_name FROM ' . Applications::table() . ' WHERE id = %d', absint( $application_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private function directory( $create ) {
		$outside = trailingslashit( dirname( untrailingslashit( $this->wordpress_root() ) ) ) . '.llamahire-private';
		if ( @is_dir( $outside ) || ( $create && @wp_mkdir_p( $outside ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->protect( $outside );
			return $outside;
		}
		$uploads = wp_upload_dir();
		$fallback = trailingslashit( $uploads['basedir'] ) . 'llamahire-private';
		$development    = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$allow_fallback = (bool) apply_filters( 'llamahire_allow_webroot_resume_storage', $development, $fallback );
		if ( $allow_fallback && ( is_dir( $fallback ) || ( $create && wp_mkdir_p( $fallback ) ) ) && $this->protect( $fallback ) ) {
			return $fallback;
		}
		return new \WP_Error( 'resume_storage' );
	}

	private function is_managed_path( $path ) {
		$real = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $real ) { return false; }
		$directories = array( trailingslashit( dirname( untrailingslashit( $this->wordpress_root() ) ) ) . '.llamahire-private' );
		$uploads = wp_upload_dir();
		$directories[] = trailingslashit( $uploads['basedir'] ) . 'llamahire-private';
		foreach ( $directories as $directory ) {
			$root = @realpath( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $root && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) { return true; }
		}
		return false;
	}

	private function protect( $directory ) {
		$rules = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
			'index.php'  => "<?php\nhttp_response_code( 403 );\nexit;\n",
		);
		foreach ( $rules as $file => $contents ) {
			$path = trailingslashit( $directory ) . $file;
			if ( ! file_exists( $path ) ) { file_put_contents( $path, $contents, LOCK_EX ); } // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_file( $path ) ) {
				return false;
			}
		}
		return true;
	}

	private function validate_signature( $path, $extension ) {
		$header = file_get_contents( $path, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $header ) {
			return new \WP_Error( 'resume_type' );
		}
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
			if ( class_exists( 'ZipArchive' ) ) {
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
					if ( false === $entry || false !== strpos( $entry, '../' ) || 0 === strpos( $entry, '/' ) || preg_match( '/^[A-Za-z]:/', $entry ) ) {
						$archive->close();
						return new \WP_Error( 'resume_type' );
					}
				}
				$archive->close();
			}
		}
		return true;
	}

	private function allowed_mimes() {
		return array( 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	}

	private function wordpress_root() {
		$root = realpath( ABSPATH );
		return wp_normalize_path( $root ?: ABSPATH );
	}
}
