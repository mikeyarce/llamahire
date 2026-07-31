<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

final class Theme_Support {
	const TEMPLATE_PREFIX = 'llamahire//';

	public static function register() {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		$templates = array(
			'single-llamahire_job' => array(
				'title'       => __( 'Single Job', 'llamahire' ),
				'description' => __( 'Displays a LlamaHire job with its role details and application form.', 'llamahire' ),
			),
			'archive-llamahire_job' => array(
				'title'       => __( 'Jobs Archive', 'llamahire' ),
				'description' => __( 'Displays searchable and filterable open LlamaHire jobs.', 'llamahire' ),
			),
			'taxonomy-llamahire_department' => array(
				'title'       => __( 'Job Department', 'llamahire' ),
				'description' => __( 'Displays open LlamaHire jobs in the current department.', 'llamahire' ),
			),
		);

		foreach ( $templates as $slug => $args ) {
			$args['content'] = self::template_content( $slug . '.php' );
			$args['plugin']  = 'llamahire';
			register_block_template( self::TEMPLATE_PREFIX . $slug, $args );
		}
	}

	private static function template_content( $filename ) {
		$path = LLAMAHIRE_PATH . 'templates/' . sanitize_file_name( $filename );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		include $path;
		return trim( ob_get_clean() );
	}
}
