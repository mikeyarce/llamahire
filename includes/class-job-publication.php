<?php
namespace LlamaHire;

defined( 'ABSPATH' ) || exit;

/** Central publication coordination; public reads recheck policy while writes finalize. */
final class Job_Publication {
	private static $providers = array();
	private static $intents = array();
	private static $changing = array();
	private static $leases = array();
	private static $notification_blocks = array();
	private static $confirmed_publications = array();
	private static $metadata_write = false;
	const RETRY = 'llamahire_reconcile_listing';

	private static function key( $job_id ) { return get_current_blog_id() . ':' . $job_id; }

	public static function register() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_status' ), PHP_INT_MAX, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'after_save' ), PHP_INT_MAX, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 0, 3 );
		add_filter( 'the_posts', array( __CLASS__, 'public_posts' ), PHP_INT_MAX, 2 );
		add_filter( 'posts_pre_query', array( __CLASS__, 'public_ids' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_job' ), PHP_INT_MAX, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'protect_expiry' ), PHP_INT_MAX, 5 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'protect_expiry' ), PHP_INT_MAX, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'protect_expiry_deletion' ), PHP_INT_MAX, 5 );
		add_action( self::RETRY, array( __CLASS__, 'reconcile' ) );
	}

	/** Providers are registered after Free is ready, independently of payment health. */
	private static function providers() {
		$site = get_current_blog_id();
		if ( ! array_key_exists( $site, self::$providers ) ) {
			try {
				$providers = apply_filters( 'llamahire_listing_policies', array(), array( 'site_id' => $site, 'mode' => Settings::site_mode() ) );
				if ( ! is_array( $providers ) || count( $providers ) > 10 ) { throw new \UnexpectedValueException(); }
				foreach ( $providers as $name => $provider ) {
					if ( ! Listing_Rules::name( $name ) || ! $provider instanceof Contracts\Listing_Policy ) { throw new \UnexpectedValueException(); }
				}
				self::$providers[ $site ] = $providers;
			} catch ( \Throwable $error ) { self::$providers[ $site ] = self::held(); }
		}
		return self::$providers[ $site ];
	}

	/** Approval binds saved job content, ownership and terms, not mutable clock fields. */
	private static function fingerprint( $post ) {
		$meta = Jobs::get_meta( $post->ID );
		unset( $meta['closed'], $meta['listing_expires'] );
		$terms = wp_get_object_terms( $post->ID, get_object_taxonomies( Jobs::POST_TYPE ), array( 'fields' => 'tt_ids' ) );
		if ( is_wp_error( $terms ) ) { return ''; }
		sort( $terms, SORT_NUMERIC );
		return hash( 'sha256', wp_json_encode( array( (int) $post->post_author, $post->post_title, $post->post_content, $post->post_excerpt, $meta, $terms ) ) );
	}

	public static function context( $job_id ) {
		$id = Listing_Rules::id( $job_id );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type ) { return null; }
		$state = Listing_Store::state( $id );
		$period = Listing_Store::period( $id );
		if ( is_wp_error( $state ) || is_wp_error( $period ) ) { return Listing_Store::failure(); }
		$meta = Jobs::get_meta( $id );
		$hash = self::fingerprint( $post );
		$approved = $state && (int) $state['owner_id'] === (int) $post->post_author && '' !== $hash && '' !== $state['approval_hash'] && hash_equals( $state['approval_hash'], $hash ) && in_array( $post->post_status, array( 'pending', 'future', 'publish' ), true );
		return array( 'site_id' => get_current_blog_id(), 'job_id' => $id, 'owner_id' => (int) $post->post_author, 'mode' => Settings::site_mode(), 'status' => $post->post_status, 'approved' => (bool) $approved, 'actor_id' => get_current_user_id(), 'closed' => '1' === $meta['closed'], 'deadline' => $meta['deadline'], 'listing_expires' => $meta['listing_expires'], 'publish_at' => $post->post_date_gmt, 'period' => $period );
	}

	private static function decision( array $context ) {
		$providers = self::providers();
		if ( is_wp_error( $providers ) ) { return $providers; }
		$result = array( 'managed' => false, 'eligible' => true, 'period_required' => false, 'period' => null );
		try {
			foreach ( $providers as $name => $provider ) {
				$value = Listing_Rules::decision( $provider->evaluate( $context ), $name );
				if ( null === $value ) { continue; }
				$result['managed'] = true;
				$result['eligible'] = $result['eligible'] && $value['eligible'];
				if ( $value['period_required'] ) {
					if ( $result['period_required'] ) { return self::held(); }
					$result['period_required'] = true;
					$result['period'] = $value['period'];
				}
			}
		} catch ( \Throwable $error ) { return self::held(); }
		return $result;
	}

	/** No cached paid flag can reopen an expired, revoked or unapproved listing. */
	public static function available( $job_id ) {
		if ( ! self::providers() ) { return true; }
		$context = self::context( $job_id );
		if ( ! $context || is_wp_error( $context ) ) { return false; }
		$decision = self::decision( $context );
		if ( is_wp_error( $decision ) ) { return false; }
		if ( ! $decision['managed'] ) { return true; }
		$today = current_time( 'Y-m-d' );
		if ( ! $context['approved'] || ! $decision['eligible'] || 'publish' !== $context['status'] || $context['closed'] || ( $context['deadline'] && $context['deadline'] < $today ) || ( $context['listing_expires'] && $context['listing_expires'] < $today ) ) { return false; }
		if ( ! $decision['period_required'] ) { return true; }
		$period = $context['period'];
		return $period && $decision['period'] && $period['period_key'] === $decision['period']['key'] && (int) $period['owner_id'] === $context['owner_id'] && (int) $period['days'] === $decision['period']['days'] && $period['expires'] >= $today && $period['expires'] === $context['listing_expires'];
	}

	public static function approve( $job_id ) {
		$id = Listing_Rules::id( $job_id );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type || ! current_user_can( 'publish_llamahire_jobs' ) || ! current_user_can( 'edit_post', $id ) ) { return self::held(); }
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future', 'publish' ), true ) ) { return self::held(); }
		$context = self::context( $id );
		$decision = $context && ! is_wp_error( $context ) ? self::decision( $context ) : self::held();
		if ( is_wp_error( $decision ) || ! $decision['managed'] ) { return self::held(); }
		if ( 'draft' === $post->post_status ) {
			self::$changing[ self::key( $id ) ] = true;
			try { $saved = wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ), true ); }
			finally { unset( self::$changing[ self::key( $id ) ] ); }
			if ( is_wp_error( $saved ) ) { return self::held(); }
			$post = get_post( $id );
		}
		$hash = self::fingerprint( $post );
		if ( '' === $hash ) { return self::held(); }
		$result = Listing_Store::approve( $id, (int) $post->post_author, $hash, get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::reconcile( $id );
	}

	public static function filter_status( $data, $postarr ) {
		if ( Jobs::POST_TYPE !== $data['post_type'] || ! self::providers() ) { return $data; }
		$id = absint( $postarr['ID'] ?? 0 );
		if ( isset( self::$changing[ self::key( $id ) ] ) ) { return $data; }
		$context = $id ? self::context( $id ) : array( 'site_id' => get_current_blog_id(), 'job_id' => 0, 'owner_id' => (int) $data['post_author'], 'mode' => Settings::site_mode(), 'status' => 'draft', 'approved' => false, 'actor_id' => get_current_user_id(), 'closed' => false, 'deadline' => '', 'listing_expires' => '', 'publish_at' => '', 'period' => null );
		$decision = $context && ! is_wp_error( $context ) ? self::decision( $context ) : self::held();
		if ( ! is_wp_error( $decision ) && ! $decision['managed'] ) { return $data; }
		unset( self::$intents[ self::key( $id ) ] );
		if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) { return $data; }
		$authorized = $id && current_user_can( 'publish_llamahire_jobs' ) && current_user_can( 'edit_post', $id );
		if ( $authorized ) { self::$intents[ self::key( $id ) ] = get_current_user_id(); }
		if ( 'future' === $data['post_status'] && $authorized ) { return $data; }
		if ( ! $authorized || ! $context || is_wp_error( $context ) || 'publish' !== $context['status'] || ! self::available( $id ) ) { $data['post_status'] = 'pending'; }
		return $data;
	}

	public static function after_save( $job_id, $post, $update, $before ) {
		if ( Jobs::POST_TYPE !== $post->post_type || isset( self::$changing[ self::key( $job_id ) ] ) ) { return; }
		if ( isset( self::$intents[ self::key( $job_id ) ] ) ) {
			$actor = self::$intents[ self::key( $job_id ) ]; unset( self::$intents[ self::key( $job_id ) ] );
			if ( get_current_user_id() === $actor ) { self::approve( $job_id ); }
		} elseif ( in_array( $post->post_status, array( 'draft', 'pending', 'trash' ), true ) ) {
			Listing_Store::withdraw( $job_id );
		}
	}

	private static function can_publish( array $context, array $decision ) {
		if ( ! $context['approved'] || ! $decision['eligible'] || $context['closed'] || ! in_array( $context['status'], array( 'pending', 'future', 'publish' ), true ) ) { return false; }
		$today = current_time( 'Y-m-d' );
		if ( $context['deadline'] && $context['deadline'] < $today ) { return false; }
		if ( $context['publish_at'] && '0000-00-00 00:00:00' !== $context['publish_at'] && strtotime( $context['publish_at'] . ' UTC' ) > time() ) { return false; }
		if ( ! $decision['period_required'] ) { return ! $context['listing_expires'] || $context['listing_expires'] >= $today; }
		$offer = $decision['period']; $period = $context['period'];
		if ( ! $offer ) { return false; }
		if ( ! $period ) { return '' === $offer['previous_key']; }
		if ( $period['period_key'] === $offer['key'] ) { return (int) $period['owner_id'] === $context['owner_id'] && $period['expires'] >= $today && (int) $period['days'] === $offer['days']; }
		return $period['period_key'] === $offer['previous_key'] && ( $period['expires'] < $today || (int) $period['owner_id'] !== $context['owner_id'] );
	}

	public static function reconcile( $job_id ) {
		$id = Listing_Rules::id( $job_id );
		if ( ! $id ) { return self::held(); }
		$token = Listing_Lock::acquire( $id );
		if ( ! $token ) { return self::held(); }
		self::$leases[ self::key( $id ) ] = $token;
		unset( self::$notification_blocks[ self::key( $id ) ] );
		try {
			clean_post_cache( $id );
			$context = self::context( $id );
			if ( ! $context || is_wp_error( $context ) ) { return self::held(); }
			$decision = self::decision( $context );
			if ( is_wp_error( $decision ) ) { self::suspend( $id ); return $decision; }
			if ( ! $decision['managed'] ) { return true; }
			if ( ! self::can_publish( $context, $decision ) ) {
				if ( 'publish' === $context['status'] ) { self::suspend( $id ); }
				return self::held();
			}
			if ( self::available( $id ) ) { return true; }
			if ( 'publish' === $context['status'] ) { return self::finalize( $id, $context, $decision ); }
			self::$changing[ self::key( $id ) ] = true;
			$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
			return ! is_wp_error( $result ) && self::available( $id ) ? true : self::held();
		} finally {
			unset( self::$changing[ self::key( $id ) ], self::$leases[ self::key( $id ) ] );
			Listing_Lock::release( $id, $token );
		}
	}

	public static function suspend( $job_id ) {
		$id = Listing_Rules::id( $job_id ); $post = $id ? get_post( $id ) : null;
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type ) { return self::held(); }
		if ( 'publish' !== $post->post_status ) { return true; }
		$changing = isset( self::$changing[ self::key( $id ) ] ); self::$changing[ self::key( $id ) ] = true;
		try { $result = wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ), true ); }
		finally { if ( ! $changing ) { unset( self::$changing[ self::key( $id ) ] ); } }
		return is_wp_error( $result ) ? self::held() : true;
	}

	private static function finalize( $id, array $context, array $decision ) {
		if ( ! isset( self::$leases[ self::key( $id ) ] ) || ! Listing_Lock::owns( $id, self::$leases[ self::key( $id ) ] ) || ! self::can_publish( $context, $decision ) ) { return self::held(); }
		if ( $decision['period_required'] ) {
			$period = Listing_Store::start( $id, $context['owner_id'], $decision['period'] );
			if ( is_wp_error( $period ) ) {
				self::suspend( $id );
				if ( ! wp_next_scheduled( self::RETRY, array( $id ) ) ) { wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RETRY, array( $id ) ); }
				return $period;
			}
			if ( ! $context['period'] || $context['period']['period_key'] !== $period['period_key'] ) {
				do_action( 'llamahire_listing_period_started', array_merge( $period, array( 'site_id' => get_current_blog_id() ) ) );
			}
		}
		return self::available( $id ) ? true : self::held();
	}

	/** Core's scheduled/direct publisher also reaches this hook after writing status. */
	public static function transition( $new, $old, $post ) {
		if ( Jobs::POST_TYPE !== $post->post_type || 'publish' !== $new || ! self::providers() ) { return; }
		$id = (int) $post->ID;
		if ( isset( self::$intents[ self::key( $id ) ] ) && $old === $new && current_user_can( 'publish_llamahire_jobs' ) && current_user_can( 'edit_post', $id ) ) {
			$hash = self::fingerprint( $post );
			if ( $hash ) { Listing_Store::approve( $id, (int) $post->post_author, $hash, get_current_user_id() ); }
		}
		$context = self::context( $id );
		$decision = $context && ! is_wp_error( $context ) ? self::decision( $context ) : self::held();
		if ( ! is_wp_error( $decision ) && ! $decision['managed'] ) { return; }
		$owned = isset( self::$leases[ self::key( $id ) ] );
		$token = $owned ? self::$leases[ self::key( $id ) ] : Listing_Lock::acquire( $id );
		if ( ! $token ) { self::$notification_blocks[ self::key( $id ) ] = true; return; }
		self::$leases[ self::key( $id ) ] = $token;
		try {
			if ( is_wp_error( $decision ) || ! self::can_publish( $context, $decision ) || is_wp_error( self::finalize( $id, $context, $decision ) ) ) {
				self::$notification_blocks[ self::key( $id ) ] = true;
				self::suspend( $id );
				$post->post_status = get_post_status( $id );
			} elseif ( $new !== $old ) {
				self::$confirmed_publications[ self::key( $id ) ] = true;
			}
		} finally {
			if ( ! $owned ) { unset( self::$leases[ self::key( $id ) ] ); Listing_Lock::release( $id, $token ); }
		}
	}

	public static function may_notify( $job_id ) { return empty( self::$notification_blocks[ self::key( $job_id ) ] ) && 'publish' === get_post_status( $job_id ) && self::available( $job_id ); }
	public static function confirmed_publication( $job_id ) { return ! empty( self::$confirmed_publications[ self::key( $job_id ) ] ) && self::may_notify( $job_id ); }

	public static function public_posts( $posts, $query ) {
		if ( ! self::providers() ) { return $posts; }
		return array_values( array_filter( $posts, static function ( $post ) use ( $query ) {
			if ( ! $post instanceof \WP_Post || Jobs::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) { return true; }
			if ( ( is_admin() || $query->is_preview() ) && Ownership::user_can_manage_job( $post->ID ) ) { return true; }
			return self::available( $post->ID );
		} ) );
	}

	/** ID-only queries (including core sitemaps) otherwise skip the_posts entirely. */
	public static function public_ids( $posts, $query ) {
		if ( ! in_array( $query->get( 'fields' ), array( 'ids', 'id=>parent' ), true ) || ! self::providers() ) { return $posts; }
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( Jobs::POST_TYPE, $types, true ) && ! in_array( 'any', $types, true ) ) { return $posts; }
		if ( null !== $posts ) {
			return array_values( array_filter( $posts, static function ( $item ) use ( $query ) {
				$id = is_object( $item ) ? $item->ID : $item;
				$post = get_post( $id );
				return ! $post || Jobs::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ( ( is_admin() || $query->is_preview() ) && Ownership::user_can_manage_job( $id ) ) || self::available( $id );
			} ) );
		}
		$args = $query->query_vars;
		$args['fields'] = 'all';
		$args['suppress_filters'] = false;
		$visible = new \WP_Query( $args );
		$query->found_posts = $visible->found_posts;
		$query->max_num_pages = $visible->max_num_pages;
		$query->set( 'no_found_rows', true );
		return 'ids' === $query->get( 'fields' ) ? wp_list_pluck( $visible->posts, 'ID' ) : $visible->posts;
	}

	public static function rest_job( $response, $server, $request ) {
		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) || ! preg_match( '#^/wp/v2/llamahire_job/([0-9]+)/?$#D', $request->get_route(), $matches ) ) { return $response; }
		$post = get_post( (int) $matches[1] );
		if ( ! $post || Jobs::POST_TYPE !== $post->post_type || ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $post->ID ) ) ) { return $response; }
		return 'publish' === $post->post_status && ! self::available( $post->ID ) ? new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) ) : $response;
	}

	/** Keep the canonical expiry available if an extension is later disabled. */
	public static function protect_expiry_deletion( $check, $job_id, $key, $value, $all ) {
		if ( null !== $check || ! in_array( $key, array( Jobs::META_KEY, Jobs::META_EXPIRY ), true ) || ! self::providers() ) { return $check; }
		// Bulk deletion cannot prove that all affected jobs are unmanaged.
		if ( $all ) { return false; }
		$context = self::context( $job_id );
		if ( ! $context || is_wp_error( $context ) ) { return $context ? false : $check; }
		$decision = self::decision( $context );
		return is_wp_error( $decision ) || $decision['period_required'] ? false : $check;
	}

	/** Preserve canonical paid expiry across native editor, REST and frontend writes. */
	public static function protect_expiry( $check, $job_id, $key, $value, $previous ) {
		if ( null !== $check || self::$metadata_write || Listing_Store::writing_expiry() || ! in_array( $key, array( Jobs::META_KEY, Jobs::META_EXPIRY ), true ) || ! self::providers() ) { return $check; }
		$context = self::context( $job_id );
		if ( ! $context || is_wp_error( $context ) ) { return $context ? false : $check; }
		$decision = self::decision( $context );
		if ( is_wp_error( $decision ) ) { return false; }
		if ( ! $decision['period_required'] ) { return $check; }
		$expiry = $context['period']['expires'] ?? '';
		if ( Jobs::META_KEY === $key ) {
			if ( ! is_array( $value ) ) { return false; }
			if ( ( $value['listing_expires'] ?? '' ) === $expiry ) { return $check; }
			$value['listing_expires'] = $expiry;
		} else {
			if ( $value === $expiry ) { return $check; }
			$value = $expiry;
		}
		self::$metadata_write = true;
		try { return update_post_meta( $job_id, $key, $value ); }
		finally { self::$metadata_write = false; }
	}

	private static function held() { return new \WP_Error( 'llamahire_publication_held', __( 'This listing is waiting for its publication requirements.', 'llamahire' ) ); }
	private function __construct() {}
}
