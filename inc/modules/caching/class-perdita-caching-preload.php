<?php
/**
 * Cache preload: build every public page into the page cache in the
 * background, a few at a time.
 *
 * A preload walks the site in three phases: the start list (home page, blog
 * page, post type archives), then every published post of a public type by
 * ascending id, then every term archive that has posts. The walk keeps only
 * a cursor, never the full URL list, so a site with 50,000 posts costs the
 * same memory as one with 50. Each WP-Cron run builds a small batch and
 * books the next run, so a preload survives a restart and picks up where it
 * stopped.
 *
 * Each page is built by asking this site for it over HTTP, with no cookies
 * and a user agent that names the preloader, exactly the anonymous request
 * the page cache stores.
 *
 * Progress lives in the option `perdita_cache_preload`. The page cache
 * ignores every `perdita_cache_*` option when it decides whether a settings
 * change should purge, so saving progress never clears the cache.
 *
 * Loaded on first use from Perdita_Caching::load( 'preload' ).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_Preload {

	/**
	 * Option holding the preload's progress.
	 */
	const OPTION = 'perdita_cache_preload';

	/**
	 * Cron hook that builds one batch.
	 */
	const HOOK_BATCH = 'perdita_cache_preload_batch';

	/**
	 * Cron hook that starts a preload.
	 */
	const HOOK_START = 'perdita_cache_preload_start';

	/**
	 * The name every preload request carries in its user agent.
	 */
	const USER_AGENT = 'Perdita Cache Preload';

	/**
	 * Transient that keeps two batches from running at once.
	 */
	const LOCK = 'perdita_cache_preload_lock';

	/**
	 * The most purged URLs held for a rebuild at one time.
	 */
	const QUEUE_CAP = 500;

	/* ---------- state ---------- */

	/**
	 * A fresh, idle state.
	 *
	 * @return array
	 */
	public static function blank_state() {
		return array(
			'status'   => 'idle',   // idle, waiting, running, done or stopped.
			'run'      => '',       // Id of the current run.
			'mode'     => 'fill',   // fill builds what is missing, refresh rebuilds everything.
			'phase'    => 'start',  // start, posts, terms or done.
			'cursor'   => 0,        // Last post id, or the term offset.
			'total'    => 0,        // Pages this run expects to visit.
			'done'     => 0,        // Pages visited so far.
			'built'    => 0,        // Pages requested and answered 200.
			'cached'   => 0,        // Pages already fresh, so not requested.
			'failed'   => 0,        // Pages that did not answer 200.
			'started'  => 0,
			'finished' => 0,
			'queue'    => array(),  // Purged URLs waiting for a rebuild.
			'extra'    => array(),  // Hashes of URLs a filter added, so each is built once per run.
			'again'    => false,    // A full purge landed during this run: one more fill pass when it ends.
		);
	}

	/**
	 * The stored state, merged over a blank one.
	 *
	 * @param bool $fresh Read past this request's option cache, to see what
	 *                    another request wrote (a Stop click, a restart).
	 * @return array
	 */
	public static function state( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$stored = get_option( self::OPTION, array() );
		$state  = array_merge( self::blank_state(), is_array( $stored ) ? $stored : array() );
		foreach ( array( 'cursor', 'total', 'done', 'built', 'cached', 'failed', 'started', 'finished' ) as $key ) {
			$state[ $key ] = max( 0, (int) $state[ $key ] );
		}
		$state['queue'] = is_array( $state['queue'] ) ? array_values( array_filter( $state['queue'], 'is_string' ) ) : array();
		$state['extra'] = is_array( $state['extra'] ) ? array_values( $state['extra'] ) : array();
		return $state;
	}

	/**
	 * Save the state. Not autoloaded: only the preloader and the Caching
	 * screen read it.
	 *
	 * @param array $state State.
	 */
	private static function save( $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Whether preloading can run at all: the page cache is on and no other
	 * page cache plugin has it standing down.
	 *
	 * @return bool
	 */
	public static function available() {
		$settings = Perdita_Caching::settings();
		return ! empty( $settings['enabled'] ) && '' === Perdita_Caching::other_page_cache();
	}

	/* ---------- start / stop ---------- */

	/**
	 * Start a preload from the beginning.
	 *
	 * @param string $mode fill builds only pages with no fresh copy. refresh
	 *                     rebuilds every page.
	 * @return bool False when the page cache is off.
	 */
	public static function start( $mode = 'fill' ) {
		if ( ! self::available() ) {
			return false;
		}
		$old   = self::state( true );
		$state = array_merge(
			self::blank_state(),
			array(
				'status'  => 'running',
				'run'     => wp_generate_password( 12, false ),
				'mode'    => 'refresh' === $mode ? 'refresh' : 'fill',
				'total'   => self::count_urls(),
				'started' => time(),
				// A full run visits every page, so the purged URLs waiting
				// in the old queue are covered.
				'queue'   => 'refresh' === $mode ? array() : $old['queue'],
			)
		);
		self::save( $state );
		wp_clear_scheduled_hook( self::HOOK_BATCH );
		wp_clear_scheduled_hook( self::HOOK_START );
		wp_schedule_single_event( time(), self::HOOK_BATCH );
		Perdita_Caching::log( 'PRELOAD', 'started (' . $state['mode'] . '), ' . $state['total'] . ' pages' );
		return true;
	}

	/**
	 * Stop the current preload. Progress is kept for the screen, and nothing
	 * more is built until the next start.
	 */
	public static function stop() {
		$state = self::state( true );
		// A finished or idle preload keeps its line on the screen.
		if ( in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			$state['status'] = 'stopped';
		}
		$state['queue'] = array();
		self::save( $state );
		wp_clear_scheduled_hook( self::HOOK_BATCH );
		wp_clear_scheduled_hook( self::HOOK_START );
		Perdita_Caching::log( 'PRELOAD', 'stopped at ' . $state['done'] );
	}

	/**
	 * Book a fresh preload for a minute from now. Runs at shutdown after a
	 * full purge, so a burst of purges (a bulk edit, a plugin update) ends
	 * in one preload, not one per purge.
	 *
	 * A run already under way keeps going where it is and gets one more
	 * fill pass when it ends, which rebuilds only the pages the purge
	 * removed behind it. Starting over from the home page on every purge
	 * meant a site that purged more often than a run took never got past
	 * its first pages.
	 */
	public static function restart_soon() {
		if ( ! self::available() ) {
			return;
		}
		$state = self::state( true );
		if ( 'running' === $state['status'] ) {
			if ( empty( $state['again'] ) ) {
				$state['again'] = true;
				self::save( $state );
			}
			if ( ! wp_next_scheduled( self::HOOK_BATCH ) ) {
				wp_schedule_single_event( time(), self::HOOK_BATCH );
			}
			return;
		}
		if ( 'waiting' !== $state['status'] ) {
			$state['status'] = 'waiting';
			$state['run']    = '';
			$state['queue']  = array();
			self::save( $state );
		}
		wp_clear_scheduled_hook( self::HOOK_BATCH );
		if ( ! wp_next_scheduled( self::HOOK_START ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_START );
		}
	}

	/**
	 * Put one purged URL at the front of the line, so it is rebuilt by the
	 * next batch instead of waiting for a visitor.
	 *
	 * @param string $url URL on this site.
	 */
	public static function queue_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! self::available() || ! self::is_own_url( $url ) ) {
			return;
		}
		$state = self::state( true );
		// A preload that is about to start over covers this URL anyway.
		if ( 'waiting' === $state['status'] || in_array( $url, $state['queue'], true ) || count( $state['queue'] ) >= self::QUEUE_CAP ) {
			return;
		}
		$state['queue'][] = $url;
		self::save( $state );
		if ( ! wp_next_scheduled( self::HOOK_BATCH ) ) {
			wp_schedule_single_event( time(), self::HOOK_BATCH );
		}
	}

	/* ---------- the batch ---------- */

	/**
	 * Build the next few pages, then book the next batch if any are left.
	 *
	 * @return array The state after this batch.
	 */
	public static function run_batch() {
		$state = self::state( true );
		if ( ! self::available() ) {
			return $state;
		}
		if ( get_transient( self::LOCK ) ) {
			return $state;
		}
		set_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );

		/**
		 * How many pages one preload batch builds.
		 *
		 * @param int $size Default 10.
		 */
		$size = max( 1, (int) apply_filters( 'perdita_cache_preload_batch_size', 10 ) );
		/**
		 * How many seconds one preload batch may run.
		 *
		 * @param int $seconds Default 20.
		 */
		$deadline = microtime( true ) + max( 1, (int) apply_filters( 'perdita_cache_preload_batch_seconds', 20 ) );
		$settings = Perdita_Caching::settings();
		$visited  = 0;
		$queued   = $state['queue'];

		// Purged pages first. They were live a moment ago and someone is
		// likely to ask for them again.
		while ( ! empty( $state['queue'] ) && $visited < $size && microtime( true ) < $deadline ) {
			$url = array_shift( $state['queue'] );
			self::build( $url, 'fill', $settings );
			++$visited;
		}

		$finished = false;
		while ( 'running' === $state['status'] && $visited < $size && microtime( true ) < $deadline ) {
			$urls = self::next_urls( $state, $size - $visited );
			if ( 'done' === $state['phase'] && empty( $urls ) ) {
				$finished = true;
				break;
			}
			foreach ( $urls as $url ) {
				$result = self::build( $url, $state['mode'], $settings );
				++$state['done'];
				++$visited;
				if ( isset( $state[ $result ] ) ) {
					++$state[ $result ];
				}
			}
		}

		// Another request may have stopped or restarted the preload while
		// this batch was out fetching pages. Its word stands.
		$now = self::state( true );
		if ( $now['run'] !== $state['run'] || ( 'running' === $state['status'] && 'running' !== $now['status'] ) ) {
			delete_transient( self::LOCK );
			return $now;
		}
		// A full purge that landed during the batch asks for another pass.
		$state['again'] = ! empty( $state['again'] ) || ! empty( $now['again'] );
		// Purged URLs that arrived during the batch join the line.
		foreach ( $now['queue'] as $url ) {
			if ( ! in_array( $url, $queued, true ) && ! in_array( $url, $state['queue'], true ) ) {
				$state['queue'][] = $url;
			}
		}

		$again = $finished && ! empty( $state['again'] );
		if ( $finished ) {
			$state['status']   = $again ? 'waiting' : 'done';
			$state['finished'] = time();
			$state['total']    = max( $state['total'], $state['done'] );
			$state['extra']    = array();
			$state['again']    = false;
		}
		self::save( $state );
		delete_transient( self::LOCK );
		if ( $again ) {
			// One more pass, a minute from now, for what the purge removed.
			if ( ! wp_next_scheduled( self::HOOK_START ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_START );
			}
		}

		if ( $finished ) {
			Perdita_Caching::log( 'PRELOAD', 'finished, ' . $state['done'] . ' pages' );
			$hours = (int) $settings['preload_interval_hours'];
			if ( $hours > 0 && ! empty( $settings['preload'] ) ) {
				wp_clear_scheduled_hook( self::HOOK_START, array( 'refresh' ) );
				wp_schedule_single_event( time() + $hours * HOUR_IN_SECONDS, self::HOOK_START, array( 'refresh' ) );
			}
			/**
			 * Fires when a preload has visited every page.
			 *
			 * @param array $stats {
			 *     @type string $mode     fill or refresh.
			 *     @type int    $done     Pages visited.
			 *     @type int    $built    Pages requested and answered 200.
			 *     @type int    $cached   Pages already fresh.
			 *     @type int    $failed   Pages that did not answer 200.
			 *     @type int    $started  Unix time the run began.
			 *     @type int    $finished Unix time it ended.
			 * }
			 */
			do_action( 'perdita_cache_preload_finished', array_intersect_key( $state, array_flip( array( 'mode', 'done', 'built', 'cached', 'failed', 'started', 'finished' ) ) ) );
		}
		if ( 'running' === $state['status'] || ! empty( $state['queue'] ) ) {
			if ( ! wp_next_scheduled( self::HOOK_BATCH ) ) {
				wp_schedule_single_event( time(), self::HOOK_BATCH );
			}
		} else {
			// Nothing left to build, so no batch should stay booked.
			wp_clear_scheduled_hook( self::HOOK_BATCH );
		}
		return $state;
	}

	/**
	 * The next URLs of the walk. Moves the state's phase and cursor.
	 *
	 * @param array $state State, by reference.
	 * @param int   $limit How many URLs to return at most.
	 * @return string[]
	 */
	private static function next_urls( &$state, $limit ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		$urls  = array();
		$phase = $state['phase'];

		if ( 'start' === $phase ) {
			$urls[] = home_url( '/' );
			if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) > 0 ) {
				$urls[] = (string) get_permalink( (int) get_option( 'page_for_posts' ) );
			}
			foreach ( self::post_types() as $type ) {
				$link = get_post_type_archive_link( $type );
				if ( $link && 'post' !== $type ) {
					$urls[] = $link;
				}
			}
			$state['phase']  = 'posts';
			$state['cursor'] = 0;
		} elseif ( 'posts' === $phase ) {
			$types = self::post_types();
			$ids   = array();
			if ( ! empty( $types ) ) {
				$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				// Password-protected posts are never cached, so never requested.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a keyset walk WP_Query has no argument for. $in is one %s per post type, and the array carries one value per placeholder.
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($in) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( $types, array( (int) $state['cursor'], $limit ) ) ) );
			}
			if ( empty( $ids ) ) {
				$state['phase']  = 'terms';
				$state['cursor'] = 0;
				return self::next_urls( $state, $limit );
			}
			foreach ( $ids as $id ) {
				$link = get_permalink( (int) $id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
			$state['cursor'] = (int) end( $ids );
		} elseif ( 'terms' === $phase ) {
			$taxonomies = self::taxonomies();
			$terms      = array();
			if ( ! empty( $taxonomies ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomies,
						'hide_empty' => true,
						'orderby'    => 'term_id',
						'order'      => 'ASC',
						'number'     => $limit,
						'offset'     => (int) $state['cursor'],
					)
				);
			}
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$state['phase'] = 'done';
				return array();
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
			$state['cursor'] += count( $terms );
		} else {
			return array();
		}

		/**
		 * The URLs the preloader is about to build. Runs once per batch of
		 * the walk, so add your own URLs when `$phase` is `start` (each
		 * added URL is built once per run however often it is returned),
		 * and remove any you want left alone. URLs on another host are
		 * dropped.
		 *
		 * @param string[] $urls  URLs in this batch.
		 * @param string   $phase start, posts or terms.
		 */
		$filtered = (array) apply_filters( 'perdita_cache_preload_urls', $urls, $phase );
		$out      = array();
		foreach ( $filtered as $url ) {
			if ( ! is_string( $url ) || '' === $url || ! self::is_own_url( $url ) || in_array( $url, $out, true ) ) {
				continue;
			}
			if ( ! in_array( $url, $urls, true ) ) {
				$hash = substr( md5( $url ), 0, 10 );
				if ( in_array( $hash, $state['extra'], true ) || count( $state['extra'] ) >= 2000 ) {
					continue;
				}
				$state['extra'][] = $hash;
			}
			$out[] = $url;
		}
		return $out;
	}

	/**
	 * Public post types whose posts get preloaded.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Public taxonomies whose term archives get preloaded.
	 *
	 * @return string[]
	 */
	private static function taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);
		unset( $taxonomies['post_format'] );
		return array_values( $taxonomies );
	}

	/**
	 * Roughly how many pages a full run visits, for the progress line.
	 *
	 * @return int
	 */
	public static function count_urls() {
		$total = 1;
		foreach ( self::post_types() as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		$taxonomies = self::taxonomies();
		if ( ! empty( $taxonomies ) ) {
			$terms = wp_count_terms(
				array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => true,
				)
			);
			$total += is_wp_error( $terms ) ? 0 : (int) $terms;
		}
		return $total;
	}

	/**
	 * Whether a URL is this site's own: the same scheme, host and port as
	 * the site address. A preload request goes out to whatever URL it is
	 * handed, so a filter's URL on another port or scheme of the same host
	 * is refused too.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$want = self::origin( home_url( '/' ) );
		return '' !== $want && self::origin( (string) $url ) === $want;
	}

	/**
	 * A URL's scheme, host and port, with the scheme's default port filled
	 * in, or '' when it has no http(s) scheme or host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}

	/* ---------- one page ---------- */

	/**
	 * Whether every copy the preloader builds for a URL is fresh on disk.
	 *
	 * @param string $url      URL.
	 * @param array  $settings Caching settings.
	 * @return bool
	 */
	public static function is_fresh( $url, $settings ) {
		// The same key the page cache stores the URL under.
		$keys = Perdita_Caching::keys_for_url( $url );
		if ( ! $keys ) {
			return false;
		}
		$key = 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? $keys[1] : $keys[0];
		foreach ( self::variants( $settings ) as $variant ) {
			$look = Perdita_Caching::lookup( Perdita_Caching::file_for_key( $key, $variant ), $settings );
			if ( 'hit' !== $look['state'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The copies of each page the preloader builds: the shared one, and the
	 * phone one when the separate phone cache is on.
	 *
	 * @param array $settings Caching settings.
	 * @return string[]
	 */
	private static function variants( $settings ) {
		return ! empty( $settings['mobile_cache'] ) ? array( '', 'mobile' ) : array( '' );
	}

	/**
	 * The user agent for a preload request.
	 *
	 * @param string $variant '' or mobile.
	 * @return string
	 */
	public static function user_agent( $variant = '' ) {
		$name = self::USER_AGENT . '/' . PERDITA_CORE_VERSION . ' (+' . home_url( '/' ) . ')';
		// The phone copy needs a user agent the page cache reads as a phone.
		return 'mobile' === $variant ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile ' . $name : $name;
	}

	/**
	 * Build one page into the cache.
	 *
	 * @param string $url      URL on this site.
	 * @param string $mode     fill or refresh.
	 * @param array  $settings Caching settings.
	 * @return string built, cached or failed.
	 */
	public static function build( $url, $mode, $settings ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( Perdita_Caching::path_matches_exclusions( $path, (array) $settings['exclude_paths'] ) ) {
			return 'cached';
		}
		if ( 'refresh' === $mode ) {
			// Mark the old copy for a rebuild without telling the preloader
			// to queue it: it is being rebuilt right now.
			Perdita_Caching::invalidate_url( $url, false );
		} elseif ( self::is_fresh( $url, $settings ) ) {
			return 'cached';
		}

		$ok = true;
		foreach ( self::variants( $settings ) as $variant ) {
			/**
			 * The arguments for one preload request. It goes out with no
			 * cookies, follows no redirect, and names the preloader in its
			 * user agent.
			 *
			 * @param array  $args wp_remote_get() arguments.
			 * @param string $url  The URL being built.
			 */
			$args = (array) apply_filters(
				'perdita_cache_preload_request_args',
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'cookies'     => array(),
					'user-agent'  => self::user_agent( $variant ),
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for loopback requests.
				),
				$url
			);
			// Whatever a filter did, a preload request never carries cookies.
			$args['cookies'] = array();
			if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
				unset( $args['headers']['Cookie'], $args['headers']['cookie'] );
			}
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$ok = false;
				Perdita_Caching::log( 'PRELOAD', 'failed ' . $url . ' (' . ( is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response ) ) . ')' );
			}
		}
		return $ok ? 'built' : 'failed';
	}
}
