<?php
/**
 * IndexNow: tells the IndexNow search engines about a URL the moment it
 * changes, instead of waiting for the next crawl.
 *
 * How it works. A 32-character hex key is generated once and stored in the
 * perdita_indexnow option. The key is served at /<key>.txt so an engine can
 * verify the site owns it. When a public post is published, updated, trashed,
 * or deleted, the post URL, its term archive URLs, and the home URL are
 * collected during the request and, at shutdown, scheduled as one cron event
 * 60 seconds out. That debounce folds a burst of saves into one submission
 * and keeps the HTTP call off the editor's save request. The cron handler
 * POSTs the URL list as JSON to each configured engine and records the HTTP
 * status in a 50-row log. A newly published post also pings the classic
 * Google and Bing sitemap endpoints, throttled to once per 10 minutes.
 *
 * What it never submits: drafts, pending, private, or scheduled posts (only
 * a publish transition or a change away from publish counts), any post type
 * that is not publicly viewable, a post marked noindex by Perdita Pro, and
 * nothing at all while the site discourages search engines. The
 * perdita_indexnow_should_submit filter can veto beyond that but cannot
 * re-enable a post the hard rules excluded.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_IndexNow {

	/**
	 * Option holding the key, the enable flag, the engine list, and the log.
	 */
	const OPTION = 'perdita_indexnow';

	/**
	 * One-off cron hook that performs the submission.
	 */
	const CRON_HOOK = 'perdita_indexnow_submit';

	/**
	 * Seconds between a change and the submission.
	 */
	const DEBOUNCE = 60;

	/**
	 * Log rows kept, newest first.
	 */
	const LOG_MAX = 50;

	/**
	 * HTTP timeout for the IndexNow POST.
	 */
	const TIMEOUT = 10;

	/**
	 * IndexNow's ceiling per POST.
	 */
	const MAX_URLS = 10000;

	/**
	 * Transient that throttles the sitemap pings.
	 */
	const PING_TRANSIENT = 'perdita_indexnow_pinged';

	/**
	 * Minimum gap between sitemap pings.
	 */
	const PING_THROTTLE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Post meta Perdita Pro writes when a post is set to noindex.
	 */
	const NOINDEX_META = '_perdita_seo_pro_noindex';

	/**
	 * The engines pinged with the sitemap URL when a post publishes.
	 *
	 * @var string[]
	 */
	const SITEMAP_PINGS = array(
		'google' => 'https://www.google.com/ping?sitemap=',
		'bing'   => 'https://www.bing.com/ping?sitemap=',
	);

	/**
	 * The Perdita core.
	 *
	 * @var Perdita_Core
	 */
	private $core;

	/**
	 * URLs collected this request, keyed by URL so they dedupe for free.
	 *
	 * @var array<string,bool>
	 */
	private $queue = array();

	/**
	 * Whether a post publish happened this request (triggers the sitemap ping).
	 *
	 * @var bool
	 */
	private $ping = false;

	/**
	 * URLs captured for a post before its status or slug changed, keyed by
	 * post id, so the OLD URL can be submitted after a trash, delete, or
	 * unpublish when the post can no longer produce it.
	 *
	 * @var array<int,string[]>
	 */
	private $stash = array();

	/**
	 * Constructor.
	 *
	 * @param Perdita_Core $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_action( 'template_redirect', array( $this, 'maybe_serve_key_file' ), 0 );

		add_action( 'pre_post_update', array( $this, 'stash_before_update' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'stash_before_delete' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_trashed' ) );

		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush_queue' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/class-perdita-indexnow-cli.php';
			WP_CLI::add_command( 'perdita indexnow', 'Perdita_IndexNow_CLI' );
		}
	}

	/* ==========================================================
	 * Settings
	 * ========================================================== */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'key'     => '',
			'enabled' => true,
			'engines' => array( 'api.indexnow.org' ),
			'log'     => array(),
		);
	}

	/**
	 * Stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( ! is_array( $s['engines'] ) || empty( $s['engines'] ) ) {
			$s['engines'] = self::defaults()['engines'];
		}
		if ( ! is_array( $s['log'] ) ) {
			$s['log'] = array();
		}
		return $s;
	}

	/**
	 * Sanitize a partial settings patch merged over the current settings.
	 * Every write to the option goes through here, so a caller cannot land an
	 * unsanitized value.
	 *
	 * @param array $input Partial settings.
	 * @return array Full, clean settings.
	 */
	public static function sanitize( array $input ) {
		$s = self::settings();

		if ( array_key_exists( 'enabled', $input ) ) {
			$s['enabled'] = (bool) $input['enabled'];
		}
		if ( array_key_exists( 'key', $input ) && self::is_valid_key( $input['key'] ) ) {
			$s['key'] = (string) $input['key'];
		}
		if ( array_key_exists( 'engines', $input ) ) {
			$engines = array();
			foreach ( (array) $input['engines'] as $engine ) {
				$engine = strtolower( trim( (string) $engine ) );
				$engine = (string) preg_replace( '#^https?://#', '', $engine );
				$engine = (string) strtok( $engine, '/' );
				if ( '' !== $engine && preg_match( '/^[a-z0-9.-]+$/', $engine ) ) {
					$engines[] = $engine;
				}
			}
			$engines      = array_values( array_unique( $engines ) );
			$s['engines'] = $engines ? $engines : self::defaults()['engines'];
		}
		if ( array_key_exists( 'log', $input ) ) {
			$log = array();
			foreach ( (array) $input['log'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$log[] = array(
					'time'   => isset( $row['time'] ) ? (int) $row['time'] : 0,
					'engine' => isset( $row['engine'] ) ? sanitize_text_field( (string) $row['engine'] ) : '',
					'count'  => isset( $row['count'] ) ? (int) $row['count'] : 0,
					'status' => isset( $row['status'] ) ? (int) $row['status'] : 0,
					'error'  => isset( $row['error'] ) ? sanitize_text_field( (string) $row['error'] ) : '',
					'urls'   => isset( $row['urls'] ) ? array_map( 'esc_url_raw', array_slice( (array) $row['urls'], 0, 3 ) ) : array(),
				);
			}
			$s['log'] = array_slice( $log, 0, self::LOG_MAX );
		}

		return $s;
	}

	/**
	 * Write a partial patch to the option.
	 *
	 * @param array $patch Partial settings.
	 * @return bool
	 */
	public static function save( array $patch ) {
		return update_option( self::OPTION, self::sanitize( $patch ) );
	}

	/* ==========================================================
	 * Key and key file
	 * ========================================================== */

	/**
	 * The site's IndexNow key, generated and stored on first use.
	 *
	 * @return string 32 hex characters.
	 */
	public static function key() {
		$s = self::settings();
		if ( self::is_valid_key( $s['key'] ) ) {
			return $s['key'];
		}
		$key = self::generate_key();
		self::save( array( 'key' => $key ) );
		return $key;
	}

	/**
	 * A fresh key. Does not store it.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Replace the stored key with a new one.
	 *
	 * @return string The new key.
	 */
	public static function regenerate_key() {
		$key = self::generate_key();
		self::save( array( 'key' => $key ) );
		return $key;
	}

	/**
	 * Whether a value has the shape of a key this module generates.
	 *
	 * @param mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $key );
	}

	/**
	 * The public URL of the key file.
	 *
	 * @return string
	 */
	public static function key_file_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Whether a request URI asks for the key file. Pure, so it can be tested
	 * with a made-up URI. Subdirectory installs are handled by comparing
	 * against the home URL's own path.
	 *
	 * @param string      $uri Request URI (path, optionally with a query string).
	 * @param string|null $key Key to match, defaults to the stored one.
	 * @return bool
	 */
	public static function key_file_requested( $uri, $key = null ) {
		$key = null === $key ? self::key() : (string) $key;
		if ( '' === $key ) {
			return false;
		}
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return ( $base . '/' . $key . '.txt' ) === $path;
	}

	/**
	 * Serve the key file when the request asks for it. Hooked to
	 * template_redirect at priority 0 so it runs before the page cache and
	 * needs no rewrite rule or flush.
	 */
	public function maybe_serve_key_file() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || ! self::key_file_requested( $uri ) ) {
			return;
		}
		$this->print_key_file();
		exit;
	}

	/**
	 * Print the key file body (the bare key as text/plain). Split from the
	 * hook so the output can be captured without exiting.
	 */
	public function print_key_file() {
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		echo esc_html( self::key() );
	}

	/* ==========================================================
	 * What to submit
	 * ========================================================== */

	/**
	 * Whether a post's URLs may be submitted at all. Status is not checked
	 * here, the hooks decide that: a publish transition submits the new URL,
	 * and a change away from publish submits the old one so the engine
	 * re-crawls and drops it.
	 *
	 * @param int|WP_Post $post Post.
	 * @return bool
	 */
	public static function should_submit( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$s = self::settings();
		if ( empty( $s['enabled'] ) || 1 !== (int) get_option( 'blog_public' ) ) {
			return false;
		}
		if ( in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		if ( ! empty( get_post_meta( $post->ID, self::NOINDEX_META, true ) ) ) {
			return false;
		}

		/**
		 * Last word on whether a post's URLs go to IndexNow. Only runs for a
		 * post the hard rules above already allow, so it can veto but not
		 * re-enable a noindex or non-public post.
		 *
		 * @param bool    $submit Default true.
		 * @param WP_Post $post   The post.
		 */
		return (bool) apply_filters( 'perdita_indexnow_should_submit', true, $post );
	}

	/**
	 * The URLs a change to one post affects: its permalink, every public term
	 * archive it sits in, and the home page.
	 *
	 * @param int|WP_Post $post      Post.
	 * @param string|null $permalink Override for the post URL (used when the
	 *                               post can no longer produce its old one).
	 * @return string[]
	 */
	public static function urls_for_post( $post, $permalink = null ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$urls = array();
		$link = null === $permalink ? get_permalink( $post ) : $permalink;
		if ( $link ) {
			$urls[] = (string) $link;
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( empty( $tax->public ) || empty( $tax->publicly_queryable ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$urls[] = (string) $term_link;
				}
			}
		}
		$urls[] = home_url( '/' );
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Keep only absolute http(s) URLs on this site's host, deduplicated.
	 * IndexNow rejects a batch that mixes hosts, and a foreign URL is never
	 * ours to submit.
	 *
	 * @param array $urls Candidate URLs.
	 * @return string[]
	 */
	public static function normalize_urls( array $urls ) {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$out  = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' === $url ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( strtolower( $parts['host'] ) !== $host ) {
				continue;
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	/* ==========================================================
	 * Post hooks
	 * ========================================================== */

	/**
	 * Remember a published post's URLs before wp_insert_post() changes its
	 * status or slug. Fires on pre_post_update.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    The data about to be written.
	 */
	public function stash_before_update( $post_id, $data ) {
		unset( $data );
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && self::should_submit( $post ) ) {
			$this->stash[ (int) $post_id ] = self::urls_for_post( $post );
		}
	}

	/**
	 * A status change. Publish (fresh or re-saved) queues the current URLs.
	 * Leaving publish queues the URLs the post had before the change.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$id = (int) $post->ID;

		if ( 'publish' === $new_status ) {
			if ( ! self::should_submit( $post ) ) {
				unset( $this->stash[ $id ] );
				return;
			}
			$this->queue( self::urls_for_post( $post ), 'publish' !== $old_status );
			// A slug change on a published post: the old URL changed too.
			if ( isset( $this->stash[ $id ] ) ) {
				$this->queue( $this->stash[ $id ] );
				unset( $this->stash[ $id ] );
			}
			return;
		}

		if ( 'publish' === $old_status && self::should_submit( $post ) ) {
			$urls = isset( $this->stash[ $id ] ) ? $this->stash[ $id ] : self::urls_for_post( $post, self::public_permalink( $post ) );
			$this->queue( $urls );
		}
		unset( $this->stash[ $id ] );
	}

	/**
	 * A save of a post that was published before and after. The transition
	 * hook already queued it, this is a belt-and-braces path for a save that
	 * reaches post_updated without a status transition. Duplicates collapse
	 * in the queue.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the save.
	 * @param WP_Post $before  Post before the save.
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( $after instanceof WP_Post && $before instanceof WP_Post && 'publish' === $after->post_status && 'publish' === $before->post_status && self::should_submit( $after ) ) {
			$this->queue( self::urls_for_post( $after ) );
		}
		unset( $this->stash[ (int) $post_id ] );
	}

	/**
	 * Remember a published post's URLs while it still exists. Fires on
	 * before_delete_post.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post (WP 5.5+).
	 */
	public function stash_before_delete( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && self::should_submit( $post ) ) {
			$this->stash[ (int) $post_id ] = self::urls_for_post( $post );
		}
	}

	/**
	 * A permanent delete. Submits the URLs stashed just before it.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post (WP 5.5+).
	 */
	public function on_deleted( $post_id, $post = null ) {
		unset( $post );
		$this->flush_stash( $post_id );
	}

	/**
	 * A trash. transition_post_status normally covers publish to trash, this
	 * catches a path that reached trashed_post without it.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trashed( $post_id ) {
		$this->flush_stash( $post_id );
	}

	/**
	 * Queue and forget a post's stashed URLs.
	 *
	 * @param int $post_id Post id.
	 */
	private function flush_stash( $post_id ) {
		$id = (int) $post_id;
		if ( isset( $this->stash[ $id ] ) ) {
			$this->queue( $this->stash[ $id ] );
			unset( $this->stash[ $id ] );
		}
	}

	/**
	 * The permalink a post would have if it were published, for a post that
	 * just left that status (a draft's permalink is a ?p= form and useless
	 * to an engine).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function public_permalink( WP_Post $post ) {
		$copy              = clone $post;
		$copy->post_status = 'publish';
		return (string) get_permalink( $copy );
	}

	/* ==========================================================
	 * Queue and cron
	 * ========================================================== */

	/**
	 * Add URLs to this request's queue.
	 *
	 * @param array $urls URLs.
	 * @param bool  $ping Whether a publish happened (pings the sitemap too).
	 */
	public function queue( array $urls, $ping = false ) {
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( '' !== $url ) {
				$this->queue[ $url ] = true;
			}
		}
		if ( $ping ) {
			$this->ping = true;
		}
	}

	/**
	 * Schedule everything queued this request as one cron event. Hooked to
	 * shutdown, safe to call earlier (the queue empties either way).
	 *
	 * @return bool Whether an event is now scheduled for this URL set.
	 */
	public function flush_queue() {
		if ( empty( $this->queue ) ) {
			return false;
		}
		$urls        = array_keys( $this->queue );
		$ping        = $this->ping;
		$this->queue = array();
		$this->ping  = false;
		return self::schedule( $urls, $ping );
	}

	/**
	 * The exact cron args a URL set produces, so a caller can look the event
	 * up with wp_next_scheduled().
	 *
	 * @param array $urls URLs.
	 * @param bool  $ping Sitemap ping flag.
	 * @return array|null Null when nothing valid is left.
	 */
	public static function event_args( array $urls, $ping = false ) {
		$urls = self::normalize_urls( $urls );
		if ( empty( $urls ) ) {
			return null;
		}
		sort( $urls );
		return array( $urls, (bool) $ping );
	}

	/**
	 * Schedule one debounced submission for a URL set. An identical set
	 * already waiting is left alone rather than scheduled twice.
	 *
	 * @param array $urls URLs.
	 * @param bool  $ping Sitemap ping flag.
	 * @return bool
	 */
	public static function schedule( array $urls, $ping = false ) {
		$args = self::event_args( $urls, $ping );
		if ( null === $args ) {
			return false;
		}
		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + self::DEBOUNCE, self::CRON_HOOK, $args );
	}

	/**
	 * The cron handler.
	 *
	 * @param array $urls URLs.
	 * @param bool  $ping Whether to ping the sitemap endpoints too.
	 */
	public function run_scheduled( $urls, $ping = false ) {
		if ( is_array( $urls ) && $urls ) {
			self::submit( $urls );
		}
		if ( $ping ) {
			self::ping_sitemaps();
		}
	}

	/* ==========================================================
	 * Submission
	 * ========================================================== */

	/**
	 * Submit URLs to every configured engine right now and log the result.
	 * Foreign-host and malformed URLs are dropped first. Safe to call from
	 * anywhere (the admin page, WP-CLI, another plugin).
	 *
	 * @param array $urls URLs on this site.
	 * @return array One row per engine and chunk: engine, count, status
	 *               (HTTP code, 0 on a transport error), error, urls (first 3).
	 */
	public static function submit( array $urls ) {
		$urls    = self::normalize_urls( $urls );
		$results = array();
		if ( empty( $urls ) ) {
			return $results;
		}

		$s       = self::settings();
		$key     = self::key();
		$host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$key_url = self::key_file_url();

		foreach ( $s['engines'] as $engine ) {
			foreach ( array_chunk( $urls, self::MAX_URLS ) as $chunk ) {
				$payload  = array(
					'host'        => $host,
					'key'         => $key,
					'keyLocation' => $key_url,
					'urlList'     => array_values( $chunk ),
				);
				// Engine hosts come from settings. wp_safe_remote_post()
				// refuses localhost, private ranges and the cloud metadata
				// address, which the host sanitizer lets through.
				$response = wp_safe_remote_post(
					'https://' . $engine . '/indexnow',
					array(
						'timeout'    => self::TIMEOUT,
						'headers'    => array( 'Content-Type' => 'application/json; charset=utf-8' ),
						'body'       => wp_json_encode( $payload ),
						'user-agent' => 'Perdita/' . PERDITA_CORE_VERSION . ' (' . home_url( '/' ) . ')',
					)
				);
				$row      = array(
					'time'   => time(),
					'engine' => $engine,
					'count'  => count( $chunk ),
					'status' => 0,
					'error'  => '',
					'urls'   => array_slice( $chunk, 0, 3 ),
				);
				if ( is_wp_error( $response ) ) {
					$row['error'] = $response->get_error_message();
				} else {
					$row['status'] = (int) wp_remote_retrieve_response_code( $response );
				}
				$results[] = $row;
			}
		}

		self::log( $results );
		return $results;
	}

	/**
	 * Prepend rows to the log and trim it to LOG_MAX.
	 *
	 * @param array $rows Log rows, oldest first.
	 */
	public static function log( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		$s = self::settings();
		self::save( array( 'log' => array_merge( array_reverse( array_values( $rows ) ), $s['log'] ) ) );
	}

	/**
	 * Ping the classic Google and Bing sitemap endpoints with the sitemap
	 * URL, at most once per PING_THROTTLE.
	 *
	 * @param bool $force Ignore the throttle.
	 * @return array Engine => HTTP status (0 on error), empty when throttled.
	 */
	public static function ping_sitemaps( $force = false ) {
		if ( ! $force && get_transient( self::PING_TRANSIENT ) ) {
			return array();
		}
		set_transient( self::PING_TRANSIENT, time(), self::PING_THROTTLE );

		$sitemap = self::sitemap_url();
		if ( '' === $sitemap ) {
			return array();
		}
		$out = array();
		foreach ( self::SITEMAP_PINGS as $name => $endpoint ) {
			$response     = wp_remote_get( $endpoint . rawurlencode( $sitemap ), array( 'timeout' => 5 ) );
			$out[ $name ] = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}
		return $out;
	}

	/**
	 * The sitemap URL to ping: core's index when core sitemaps are on, else
	 * /sitemap.xml for whatever serves it.
	 *
	 * @return string
	 */
	public static function sitemap_url() {
		$url = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : false;
		if ( ! $url ) {
			$url = home_url( '/sitemap.xml' );
		}

		/**
		 * The sitemap URL pinged on publish.
		 *
		 * @param string $url Sitemap URL.
		 */
		return (string) apply_filters( 'perdita_indexnow_sitemap_url', $url );
	}
}
