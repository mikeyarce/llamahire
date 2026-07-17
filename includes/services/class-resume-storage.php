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
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new \WP_Error( 'resume_size' );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$name     = sanitize_file_name( wp_basename( $file['name'] ) );
		$checked  = wp_check_filetype_and_ext( $tmp_name, $name, $this->allowed_mimes() );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new \WP_Error( 'resume_type' );
		}

		$directory = $this->directory( true );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$path = trailingslashit( $directory ) . wp_generate_uuid4() . '.' . $checked['ext'];
		if ( ! @move_uploaded_file( $tmp_name, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'resume_error' );
		}
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
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
		return array( 'available' => is_dir( $directory ) && is_writable( $directory ), 'outside_webroot' => 0 !== strpos( wp_normalize_path( $directory ), trailingslashit( $this->wordpress_root() ) ) );
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
		if ( is_dir( $fallback ) || ( $create && wp_mkdir_p( $fallback ) ) ) {
			$this->protect( $fallback );
			return $fallback;
		}
		return new \WP_Error( 'resume_error' );
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
		}
	}

	private function allowed_mimes() {
		return array( 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	}

	private function wordpress_root() {
		$root = realpath( ABSPATH );
		return wp_normalize_path( $root ?: ABSPATH );
	}
}
