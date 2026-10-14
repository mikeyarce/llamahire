<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/**
 * Copy WP Job Manager listings on the current site into reviewable drafts.
 *
 * @internal CLI implementation, not an extension contract.
 */
final class WPJM_Import_Command {
	const SOURCE_META = '_llamahire_wpjm_source_id';
	const STATE_META = '_llamahire_wpjm_import_state';
	const LOCK = 'llamahire_wpjm_import_lock';

	/**
	 * Preview or import one bounded batch of WP Job Manager jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Create draft copies. Without this flag, no data is written.
	 *
	 * [--after=<id>]
	 * : Only scan source IDs greater than this cursor. Default: 0.
	 *
	 * [--limit=<number>]
	 * : Batch size, from 1 to 500. Default: 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp llamahire import wpjm --user=admin
	 *     wp llamahire import wpjm --user=admin --commit --limit=100
	 *
	 * @param string[] $args Positional arguments.
	 * @param array    $assoc_args Command options.
	 */
	public function __invoke( $args, $assoc_args ) {
		foreach ( array( 'edit_others_llamahire_jobs', 'edit_llamahire_jobs', 'manage_llamahire_departments', 'manage_llamahire_job_types' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				\WP_CLI::error( 'Select a board manager with --user who can edit all jobs and manage job categories and types.' );
			}
		}
		$after = $assoc_args['after'] ?? '0';
		$limit = $assoc_args['limit'] ?? '100';
		if ( ! ctype_digit( (string) $after ) || ! ctype_digit( (string) $limit ) || (int) $limit < 1 || (int) $limit > 500 ) {
			\WP_CLI::error( 'Use a non-negative --after ID and a --limit between 1 and 500.' );
		}
		$commit = isset( $assoc_args['commit'] );
		if ( $commit && ! add_option( self::LOCK, time(), '', false ) ) {
			\WP_CLI::error( 'Another WPJM import holds the lock. If it was interrupted, confirm it has stopped before deleting the llamahire_wpjm_import_lock option.' );
		}
		$rows   = array();
		$failed = false;
		try {
			foreach ( self::source_ids( (int) $after, (int) $limit ) as $source_id ) {
				$after    = $source_id;
				$existing = self::destination_id( $source_id );
				$plan     = $existing ? array( 'warnings' => (array) get_post_meta( $existing, '_llamahire_wpjm_review', true ) ) : self::plan( $source_id );
				if ( $existing && 'complete' !== get_post_meta( $existing, self::STATE_META, true ) ) {
					$plan = new \WP_Error( 'incomplete_copy_requires_review', 'Review the incomplete destination before retrying.' );
				}
				$job_id   = $existing;
				$result   = $existing ? 'already_imported' : 'preview';
				if ( is_wp_error( $plan ) ) {
					$result = $plan->get_error_code();
					$failed = true;
				} elseif ( $commit && ! $existing ) {
					$job_id = self::copy( $source_id, $plan );
					$result = is_wp_error( $job_id ) ? $job_id->get_error_code() : 'imported_draft';
					$failed = $failed || is_wp_error( $job_id );
				}
				$rows[] = array(
					'source_id' => $source_id,
					'job_id'    => is_wp_error( $job_id ) ? 0 : $job_id,
					'result'    => $result,
					'review'    => is_wp_error( $plan ) ? '' : implode( ', ', $plan['warnings'] ),
				);
				// Stop on failure; the caller retries using the original batch cursor.
				if ( $failed ) {
					break;
				}
			}
		} catch ( \RuntimeException $exception ) {
			$failed = true;
			\WP_CLI::warning( 'A source database read failed; no query details were logged.' );
		} finally {
			if ( $commit ) {
				delete_option( self::LOCK );
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'source_id', 'job_id', 'result', 'review' ) );
		if ( $failed ) {
			\WP_CLI::error( 'Import stopped at a failed record. Retry with the same --after value; completed copies will be skipped.' );
		}
		\WP_CLI::success( sprintf( '%s %d source jobs. Next batch: --after=%d. Originals and user permissions are unchanged.', $commit ? 'Processed' : 'Previewed', count( $rows ), $after ) );
	}

	/** Read by keyset, including WPJM statuses when WPJM is deactivated. */
	public static function source_ids( $after, $limit ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d", 'job_listing', $after, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded CLI import reads source records with unregistered statuses, without persistent caching.
		self::check_read();
		return array_map( 'intval', $ids );
	}

	/** Find even trashed copies so a repeated import cannot restore deleted work. */
	public static function destination_id( $source_id ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 1", Jobs::POST_TYPE, self::SOURCE_META, (string) $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- CLI provenance lookup includes every destination status and bypasses caches.
		self::check_read();
		return (int) $id;
	}

	/** Read source terms without requiring WPJM's taxonomy registration. */
	private static function source_terms( $source_id, $taxonomy ) {
		global $wpdb;
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} r ON r.term_taxonomy_id = tt.term_taxonomy_id WHERE r.object_id = %d AND tt.taxonomy = %s ORDER BY t.term_id ASC", $source_id, $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only CLI source taxonomy lookup must work with WPJM deactivated.
		self::check_read();
		return $terms;
	}

	/** Do not turn failed reads into a successful empty scan. */
	private static function check_read() {
		global $wpdb;
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Source read failed.' );
		}
	}

	/** Build a sanitized plan without writing posts, terms, users, or settings. */
	public static function plan( $source_id ) {
		$post = get_post( $source_id );
		if ( ! $post || 'job_listing' !== $post->post_type ) {
			return new \WP_Error( 'invalid_source', 'The source is not a WPJM job.' );
		}
		if ( ! $post->post_author || ! get_userdata( $post->post_author ) ) {
			return new \WP_Error( 'owner_requires_review', 'Assign an existing owner to the source before importing.' );
		}
		$read = static function ( $key ) use ( $source_id ) {
			$value = get_post_meta( $source_id, $key, true );
			return is_scalar( $value ) ? (string) $value : '';
		};
		$warnings = array();
		if ( $read( '_featured' ) ) {
			$warnings[] = 'featured_requires_review';
		}
		$types    = self::source_terms( $source_id, 'job_listing_type' );
		if ( count( $types ) > 1 ) {
			$warnings[] = 'multiple_types_primary_only';
		}
		$expiry = $read( '_job_expires' );
		if ( $expiry && ! Jobs::valid_date( $expiry ) ) {
			$warnings[] = 'expiry_requires_review';
		}
		$method = 'internal';
		$target = $read( '_application' );
		if ( is_email( $target ) ) {
			$method = 'external_email';
		} elseif ( preg_match( '#^https?://#i', $target ) && Jobs::sanitize_application_target( 'external_url', $target ) ) {
			$method = 'external_url';
		} else {
			$target     = '';
			$warnings[] = 'application_method_requires_review';
		}
		$salary   = $read( '_job_salary' );
		$currency = $read( '_job_salary_currency' ) ?: get_option( 'job_manager_default_salary_currency', '' );
		$unit     = strtoupper( $read( '_job_salary_unit' ) ?: get_option( 'job_manager_default_salary_unit', '' ) );
		$numeric_salary = is_numeric( $salary ) && (float) $salary > 0 && is_finite( (float) $salary ) && Settings::currency_code( $currency, '' ) && in_array( $unit, array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ), true );
		if ( $salary && ! $numeric_salary ) {
			$warnings[] = 'salary_requires_review';
		}
		if ( ! user_can( $post->post_author, 'edit_llamahire_jobs' ) ) {
			$warnings[] = 'employer_access_requires_review';
		}
		$meta = Jobs::sanitize_meta(
			array(
				'location'             => $read( '_job_location' ),
				'workplace'            => '1' === $read( '_remote_position' ) ? 'remote' : 'onsite',
				'closed'               => '1' === $read( '_filled' ) || 'expired' === $post->post_status ? '1' : '0',
				// Legacy metadata cannot approve board-controlled promotion.
				'featured'             => '0',
				'listing_expires'      => $expiry,
				'employment_type'      => $types ? $types[0]->slug : '',
				'organization_name'    => $read( '_company_name' ),
				'organization_tagline' => $read( '_company_tagline' ),
				'organization_url'     => $read( '_company_website' ),
				'organization_logo'    => $read( '_company_logo' ),
				'application_method'   => $method,
				'application_target'   => $target,
				'salary_min'           => $numeric_salary ? $salary : '',
				'salary_currency'      => $numeric_salary ? $currency : '',
				'salary_unit'          => $numeric_salary ? $unit : 'YEAR',
				// Never infer the source address from this board's organization defaults.
				'address_street'       => $read( 'geolocation_street' ),
				'address_locality'     => $read( 'geolocation_city' ),
				'address_region'       => $read( 'geolocation_state_long' ),
				'postal_code'          => $read( 'geolocation_postcode' ),
				'address_country'      => $read( 'geolocation_country_short' ),
			)
		);
		return array( 'post' => $post, 'meta' => $meta, 'types' => array_slice( $types, 0, 1 ), 'categories' => self::source_terms( $source_id, 'job_listing_category' ), 'warnings' => $warnings );
	}

	/** Copy a prepared record; failed term or metadata writes remove the new draft. */
	private static function copy( $source_id, $plan ) {
		$post = $plan['post'];
		// Avoid moderation mail for imported drafts; restore the callback immediately.
		$callback = array( Employer_Notifications::class, 'status_changed' );
		$priority = has_action( 'transition_post_status', $callback );
		if ( false !== $priority ) {
			remove_action( 'transition_post_status', $callback, $priority );
		}
		try {
			$job_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => Jobs::POST_TYPE,
						'post_status'  => 'draft',
						'post_author'  => (int) $post->post_author,
						'post_title'   => sanitize_text_field( $post->post_title ),
						'post_content' => wp_kses_post( $post->post_content ),
						'post_excerpt' => wp_kses_post( $post->post_excerpt ),
						'post_name'    => $post->post_name,
						'post_date'    => $post->post_date,
						'post_date_gmt' => $post->post_date_gmt,
						'meta_input'    => array( self::SOURCE_META => $source_id, self::STATE_META => 'incomplete' ),
					)
				),
				true
			);
			if ( is_wp_error( $job_id ) ) {
				return new \WP_Error( 'post_write_failed', 'The draft could not be created.' );
			}
			foreach ( array( Jobs::TYPE_TAXONOMY => $plan['types'], Jobs::DEPARTMENT_TAXONOMY => $plan['categories'] ) as $taxonomy => $terms ) {
				$ids = array();
				foreach ( $terms as $term ) {
					$existing = get_term_by( 'slug', $term->slug, $taxonomy );
					$created  = $existing ? array( 'term_id' => $existing->term_id ) : wp_insert_term( $term->name, $taxonomy, array( 'slug' => $term->slug, 'description' => wp_kses_post( $term->description ) ) );
					if ( is_wp_error( $created ) ) {
						wp_delete_post( $job_id, true );
						return new \WP_Error( 'term_write_failed', 'A destination term could not be created.' );
					}
					$ids[] = (int) $created['term_id'];
				}
				if ( is_wp_error( wp_set_object_terms( $job_id, $ids, $taxonomy ) ) ) {
					wp_delete_post( $job_id, true );
					return new \WP_Error( 'term_write_failed', 'Destination terms could not be assigned.' );
				}
			}
			$meta = $plan['meta'];
			$meta['job_identifier'] = Jobs::get_meta( $job_id )['job_identifier'];
			// The registered metadata hooks synchronize query fields from the saved model.
			update_post_meta( $job_id, Jobs::META_KEY, wp_slash( $meta ) );
			$thumbnail = get_post_thumbnail_id( $source_id );
			if ( $thumbnail ) {
				set_post_thumbnail( $job_id, $thumbnail );
			}
			update_post_meta( $job_id, '_llamahire_wpjm_source_status', $post->post_status );
			update_post_meta( $job_id, '_llamahire_wpjm_review', $plan['warnings'] );
			if ( (int) get_post_meta( $job_id, self::SOURCE_META, true ) !== $source_id || Jobs::get_meta( $job_id ) !== $meta || ! update_post_meta( $job_id, self::STATE_META, 'complete' ) ) {
				wp_delete_post( $job_id, true );
				return new \WP_Error( 'metadata_write_failed', 'Import metadata could not be saved.' );
			}
			return $job_id;
		} finally {
			if ( false !== $priority ) {
				add_action( 'transition_post_status', $callback, $priority, 3 );
			}
		}
	}
}
