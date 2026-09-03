<?php
/**
 * Page caching and browser-cache headers.
 *
 * WHAT THIS IS (and what it is not). This is a lightweight, template-level page
 * cache. On a cacheable request it serves a previously saved HTML file and
 * exits, skipping the rest of WordPress. On a cacheable miss it buffers the
 * page and writes the final HTML to disk on shutdown. That is it.
 *
 * It is deliberately LIGHTER than a real drop-in cache. It does NOT install
 * wp-content/advanced-cache.php and does NOT touch wp-config.php, so it cannot
 * run before WordPress loads. A host-level cache (Varnish, LiteSpeed) or a CDN
 * still beats it on raw speed, and those can layer on top. The tradeoff buys
 * safety and self-containment, which is the point of the free tier: dropping
 * this module in can never corrupt a boot file or leave a stale drop-in behind.
 *
 * SAFETY MODEL. Correctness here means never serving the wrong page to the
 * wrong person. The exclusion list (see is_cacheable) is treated as the most
 * important part of the module: logged-in users, carts, POSTs, query strings,
 * admin/REST/feeds, previews, searches, and 404s are never cached. Cache file
 * paths are built only from a hash of the request, never from raw user input,
 * so there is no path traversal, and the host in that hash is the site's own,
 * not the client's Host header. Clearing the cache only unlinks files that
 * resolve (via realpath) to inside the cache directory.
 *
 * The one documented exception to "query strings are never cached" is the
 * tracking-parameter list in ignored_query_args(): utm_*, fbclid, gclid and
 * friends are stripped before any decision is made, because they say where a
 * visitor came from and never change what WordPress renders. See that method
 * for the tradeoff and the filter that undoes it.
 *
 * EXTENSION POINT. Other modules can mark a page non-cacheable with the
 * `perdita_cache_exclude` filter (documented on that method below). The Forms
 * and any commerce module use it to keep their dynamic pages fresh.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'perdita_caching_settings';

	/**
	 * Marker used in the .htaccess block. insert_with_markers() wraps the rules
	 * in "# BEGIN <marker>" / "# END <marker>" so we can rewrite or remove them
	 * without disturbing the rest of the file.
	 */
	const HTACCESS_MARKER = 'Perdita Cache';

	/**
	 * How long a browser may trust its own copy without asking the server
	 * again, in seconds. Deliberately short and NOT tied to ttl_hours (the
	 * server-side regeneration interval): those are different concerns. If a
	 * browser's max-age matched a long server TTL, an edited page could look
	 * stale to a returning visitor for hours even though the server-side
	 * cache was already purged and regenerated correctly. A short client
	 * max-age plus ETag/Last-Modified below still lets a browser skip a full
	 * re-download via a 304 once the 5 minutes are up, without risking a
	 * long-lived stale copy.
	 */
	const CLIENT_CACHE_MAX_AGE = 300;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Absolute path to this request's cache file, computed once. Empty when the
	 * request is not cacheable.
	 *
	 * @var string
	 */
	private $cache_file = '';

	/**
	 * Whether we opened an output buffer for capture on this request.
	 *
	 * @var bool
	 */
	private $buffering = false;

	/**
	 * Constructor. Wire serve/capture on the front end and purge hooks
	 * everywhere (edits happen in the admin).
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		// Serve + capture: front end only. template_redirect runs after the main
		// query resolves, so is_404()/is_search()/is_preview() are reliable, but
		// still before any template output. A low priority (1) means we decide
		// early. On a hit we send the file and exit; on a miss we start buffering.
		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'serve_or_capture' ), 1 );
		}

		// Smart purge. A full clear on any of these is simple and safe: the next
		// request for each page just regenerates. Correctness beats cleverness.
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		// A comment only changes two pages: the post it is on, and the front
		// page (where a recent-comments widget or a comment count can show).
		// These used to clear the entire cache, so a busy comment thread threw
		// away every cached page on the site over and over.
		add_action( 'comment_post', array( $this, 'on_comment' ) );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		// The design token document is stored in option 'perdita_settings'. When
		// it changes, generated CSS changes, so every cached page is stale.
		add_action( 'update_option_perdita_settings', array( $this, 'purge_all' ) );
		// When our own settings change (TTL, exclusions, on/off), drop everything.
		add_action( 'update_option_' . self::OPTION, array( $this, 'purge_all' ) );
	}

	/* ---------- settings ---------- */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'         => false,          // Page cache on/off.
			'ttl_hours'       => 10,             // Cache lifetime in hours.
			'exclude_paths'   => array(),        // Extra URL paths to never cache.
			'browser_cache'   => false,          // Write the .htaccess rules block.
		);
	}

	/**
	 * Read stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$s = array_merge( self::defaults(), $stored );
		// Normalize shapes so callers never have to guard.
		$s['enabled']       = ! empty( $s['enabled'] );
		$s['browser_cache'] = ! empty( $s['browser_cache'] );
		$s['ttl_hours']     = max( 1, (int) $s['ttl_hours'] );
		$s['exclude_paths'] = is_array( $s['exclude_paths'] ) ? array_values( $s['exclude_paths'] ) : array();
		return $s;
	}

	/**
	 * Sanitize a settings array on write. Static so the admin class can reuse it.
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		// TTL is clamped to a sane 1..8760 hours (a year), defaulting to 10.
		$ttl = isset( $input['ttl_hours'] ) ? (int) $input['ttl_hours'] : 10;
		$ttl = min( 8760, max( 1, $ttl ) );

		// Exclusions come in as a textarea (one path per line). Keep only the
		// path portion, leading-slashed, no host, no scheme.
		$paths = array();
		if ( isset( $input['exclude_paths'] ) ) {
			$raw = is_array( $input['exclude_paths'] ) ? implode( "\n", $input['exclude_paths'] ) : (string) $input['exclude_paths'];
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}
				// Drop a full URL down to its path so a matcher only ever compares paths.
				$parsed = wp_parse_url( $line );
				$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : $line;
				$path   = '/' . ltrim( sanitize_text_field( $path ), '/' );
				if ( '/' !== $path ) {
					$paths[] = $path;
				}
			}
			$paths = array_values( array_unique( $paths ) );
		}

		return array(
			'enabled'       => ! empty( $input['enabled'] ),
			'ttl_hours'     => $ttl,
			'exclude_paths' => $paths,
			'browser_cache' => ! empty( $input['browser_cache'] ),
		);
	}

	/* ---------- storage ---------- */

	/**
	 * Absolute path to the cache directory, with a trailing slash.
	 *
	 * Lives under the uploads dir so it is writable and outside the theme. Using
	 * wp_upload_dir() means it moves correctly on multisite and custom setups.
	 *
	 * @return string
	 */
	public static function cache_dir() {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $base ) . 'perdita-cache/';
	}

	/**
	 * The cache file path for a given request key. The key is a URL; the file
	 * name is a sha1 of it, so the path is fixed-length hex and can never escape
	 * the cache dir regardless of what the URL contained.
	 *
	 * @param string $key Canonical request URL (scheme+host+path+whitelisted query).
	 * @return string Absolute file path.
	 */
	public static function file_for_key( $key ) {
		return self::cache_dir() . sha1( $key ) . '.html';
	}

	/**
	 * Build the canonical cache key for the current request: scheme + host +
	 * path, plus any whitelisted query args in a stable order. Non-whitelisted
	 * query strings never reach here (is_cacheable rejects them first), so the
	 * key stays a small, predictable set.
	 *
	 * @return string
	 */
	private function request_key() {
		$scheme = is_ssl() ? 'https' : 'http';
		// The site's OWN host, never the client's Host header. A spoofed Host
		// used to land in the key, so anyone could mint an unbounded number of
		// distinct cache files for the same page just by varying a header.
		// is_cacheable() separately refuses a request whose Host does not match
		// this one, so the two together keep one page to one file.
		$host = self::site_host();
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- path taken and normalized below.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;

		$query = $this->whitelisted_query();
		return $scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * The site's own hostname, lowercased, from home_url(). The single source
	 * of truth for both the cache key and the Host check in is_cacheable().
	 *
	 * @return string
	 */
	private static function site_host() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The request's Host header, lowercased and with any :port stripped, or ''
	 * when the header is absent (a CLI or cron context, which never reaches
	 * the serve/capture path anyway).
	 *
	 * @return string
	 */
	private static function request_host() {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		return (string) preg_replace( '/:\d+$/', '', $host );
	}

	/**
	 * Query args that are stripped from the request before any cache decision
	 * is made: campaign and click-ID parameters that every ad network, mail
	 * blast, and social app appends. They identify where a visitor came from,
	 * they never change what WordPress renders, and treating them as
	 * "unknown arg, do not cache" meant a site's most valuable traffic (every
	 * ad click, every newsletter click, every Facebook share) missed the cache
	 * completely while plain organic traffic hit it.
	 *
	 * BEHAVIOR CHANGE, and the tradeoff is worth naming: a plugin that varies
	 * its output on one of these params (a landing page that greets utm_campaign
	 * by name, an A/B splitter keyed to utm_content) will now be served the same
	 * cached HTML for every value of it. Filter the list to drop the param
	 * that matters to you, and it goes back to being an ordinary
	 * non-whitelisted arg that keeps the page out of the cache entirely.
	 *
	 * @return string[]
	 */
	private static function ignored_query_args() {
		/**
		 * Query args ignored entirely for caching purposes: stripped before
		 * the whitelist check and never part of the cache key.
		 *
		 * @param string[] $args Arg names.
		 */
		return array_map(
			'strval',
			(array) apply_filters(
				'perdita_cache_query_ignore',
				array(
					'utm_source',
					'utm_medium',
					'utm_campaign',
					'utm_term',
					'utm_content',
					'utm_id',
					'fbclid',
					'gclid',
					'msclkid',
					'mc_cid',
					'mc_eid',
					'ttclid',
					'twclid',
				)
			)
		);
	}

	/**
	 * The request's query args with the ignored (tracking) params removed. Every
	 * cache decision reads this rather than $_GET directly, so a tracking param
	 * can neither block caching nor split the cache key.
	 *
	 * @return array
	 */
	private function significant_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection of the query for a cache decision.
		$get = wp_unslash( $_GET );
		if ( empty( $get ) || ! is_array( $get ) ) {
			return array();
		}
		return array_diff_key( $get, array_flip( self::ignored_query_args() ) );
	}

	/**
	 * The whitelisted, normalized query string for the current request, or an
	 * empty string. Only a tiny set of pagination-style args are allowed, and
	 * they are sorted so ?a=1&b=2 and ?b=2&a=1 map to one cache entry.
	 *
	 * @return string
	 */
	private function whitelisted_query() {
		// A conservative whitelist: pagination and common language switching. Any
		// other query arg means "do not cache" (handled in is_cacheable), so this
		// only ever runs against known-safe args. Reads the tracking-stripped
		// query, so an ignored param can never reach the key even if someone
		// filters it onto the whitelist as well.
		$allowed = apply_filters( 'perdita_cache_query_whitelist', array( 'page', 'paged' ) );
		$query   = $this->significant_query();
		$pairs   = array();
		foreach ( (array) $allowed as $arg ) {
			if ( isset( $query[ $arg ] ) && ! is_array( $query[ $arg ] ) ) {
				$pairs[ sanitize_key( $arg ) ] = sanitize_text_field( $query[ $arg ] );
			}
		}
		ksort( $pairs );
		$out = array();
		foreach ( $pairs as $k => $v ) {
			$out[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		return implode( '&', $out );
	}

	/**
	 * Whether any query arg is present that is NOT on the whitelist. Such a
	 * request is never cached, because the URL varies output we do not model.
	 *
	 * @return bool
	 */
	private function has_disallowed_query() {
		// Tracking params are stripped first, so ?utm_source=newsletter is
		// cached as the plain URL instead of being treated as an unknown arg.
		$get = $this->significant_query();
		if ( empty( $get ) ) {
			return false;
		}
		$allowed = (array) apply_filters( 'perdita_cache_query_whitelist', array( 'page', 'paged' ) );
		foreach ( array_keys( $get ) as $arg ) {
			if ( ! in_array( $arg, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- serve / capture ---------- */

	/**
	 * Decide, on template_redirect, whether to serve a cached page or start
	 * capturing one. On a hit we stream the file and exit before the template
	 * loads. On a cacheable miss we open an output buffer that shutdown() flushes
	 * to disk.
	 */
	public function serve_or_capture() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( ! $this->is_cacheable( $settings ) ) {
			return;
		}

		$this->cache_file = self::file_for_key( $this->request_key() );

		// HIT: a fresh file exists. Serve it and stop.
		$fresh = $this->fresh_file( $this->cache_file, (int) $settings['ttl_hours'] );
		if ( '' !== $fresh ) {
			$html = @file_get_contents( $fresh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache read; false handled below.
			if ( false !== $html && '' !== $html ) {
				if ( $this->send_hit_headers( $fresh ) ) {
					// Client already has this exact content (matched by ETag or
					// Last-Modified): a bodyless 304 is a real bandwidth saving,
					// not just a status code for its own sake.
					exit;
				}
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving our own previously captured, complete HTML document.
				exit;
			}
		}

		// MISS: capture. Buffer the whole response; write it on shutdown. Still
		// send Cache-Control here (not just on a HIT): WordPress's Site Health
		// "page cache" check samples the homepage 3 times and only needs ONE of
		// the standard caching headers on ANY of those responses, so a
		// still-empty cache on the very first sampled request should not read
		// as "no caching here" when caching is in fact enabled and about to
		// populate.
		if ( ! headers_sent() ) {
			header( 'X-Cache: MISS' );
			header( 'Cache-Control: max-age=' . self::CLIENT_CACHE_MAX_AGE . ', public' );
		}
		$this->buffering = true;
		ob_start();
		add_action( 'shutdown', array( $this, 'write_buffer' ), 0 );
	}

	/**
	 * Send real HTTP caching headers for a cache hit, computed from the
	 * actual cache file (its mtime, a hash of its own content), not
	 * hardcoded values invented just to satisfy a checker. These are also
	 * exactly the headers WordPress core's Site Health "page cache" check
	 * looks for (cache-control, etag, last-modified, x-cache), so a site
	 * using this cache now gets correctly detected there too, as a side
	 * effect of the headers being genuinely meaningful rather than a special
	 * case for that check.
	 *
	 * @param string $file Absolute path to the cache file being served.
	 * @return bool True if a 304 Not Modified was sent (caller should exit
	 *              with no body); false if the caller should still echo the HTML.
	 */
	private function send_hit_headers( $file ) {
		if ( headers_sent() ) {
			return false;
		}

		$mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; falsy mtime just skips the conditional-GET checks below.
		$etag  = self::etag_for_file( $file );
		$age   = $mtime > 0 ? max( 0, time() - $mtime ) : 0;

		header( 'X-Cache: HIT' );
		header( 'Cache-Control: max-age=' . self::CLIENT_CACHE_MAX_AGE . ', public' );
		header( 'Age: ' . $age );
		header( 'ETag: ' . $etag );
		if ( $mtime > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		}

		// Conditional GET: if the client already has this exact content (by
		// ETag, or hasn't seen a newer version since its own timestamp), a
		// bodyless 304 is a genuine saving over resending the full page.
		$client_etag  = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		$client_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		$not_modified = ( '' !== $client_etag && $client_etag === $etag )
			|| ( $mtime > 0 && false !== $client_since && $client_since >= $mtime );

		if ( $not_modified ) {
			status_header( 304 );
			return true;
		}
		return false;
	}

	/**
	 * The ETag for a cache file, derived from its modification time and size
	 * rather than a hash of its contents.
	 *
	 * md5() over the whole cached document ran on every single cache HIT,
	 * which is the one path in this module that exists to do as little work as
	 * possible: on a 200 KB page that is 200 KB of hashing per request, for a
	 * value that only ever needs to change when the file does. Both numbers
	 * come from the same stat() the caller already performs for
	 * Last-Modified, so this costs nothing.
	 *
	 * Conditional GETs behave identically: store() replaces the file via
	 * rename(), so any regeneration moves the mtime forward, and a same-second
	 * regeneration that somehow kept the mtime would have to produce a
	 * byte-identical length as well to collide.
	 *
	 * @param string $file Absolute path to the cache file.
	 * @return string Quoted ETag value.
	 */
	private static function etag_for_file( $file ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort stat; a failure just yields a stable "0-0" tag.
		$mtime = (int) @filemtime( $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same stat cache as above, so this is free.
		$size = (int) @filesize( $file );
		return '"' . $mtime . '-' . $size . '"';
	}

	/**
	 * Return the file path if it exists and is younger than the TTL, else ''.
	 *
	 * @param string $file      Absolute file path.
	 * @param int    $ttl_hours TTL in hours.
	 * @return string
	 */
	private function fresh_file( $file, $ttl_hours ) {
		if ( '' === $file || ! is_file( $file ) ) {
			return '';
		}
		$age = time() - (int) filemtime( $file );
		if ( $age > $ttl_hours * HOUR_IN_SECONDS ) {
			return '';
		}
		return $file;
	}

	/**
	 * On shutdown, write the buffered HTML to the cache file, but only for a
	 * clean 200 HTML response. Anything unusual (a redirect, a non-200 status, a
	 * non-HTML body, an empty body) is flushed to the browser without caching.
	 */
	public function write_buffer() {
		if ( ! $this->buffering ) {
			return;
		}
		$this->buffering = false;

		$html = ob_get_contents();
		if ( false === $html ) {
			return;
		}
		// Let the buffer flush to the browser regardless of whether we store it.
		ob_end_flush();

		if ( '' === trim( (string) $html ) ) {
			return;
		}

		// Only cache a normal 200 response.
		$status = function_exists( 'http_response_code' ) ? http_response_code() : 200;
		if ( 200 !== (int) $status ) {
			return;
		}

		// Only cache HTML. A body that does not look like an HTML document is
		// probably a feed, JSON, or a redirect stub; skip it.
		$looks_html = ( false !== stripos( $html, '<html' ) ) || ( false !== stripos( $html, '<!doctype html' ) );
		if ( ! $looks_html ) {
			return;
		}

		// A late exclusion filter can still veto (e.g. a shortcode that ran during
		// render decided the page is personalized).
		if ( apply_filters( 'perdita_cache_exclude', false ) ) {
			return;
		}

		$this->store( $this->cache_file, self::filter_output_html( $html ) );
	}

	/**
	 * Filter the final HTML before it is written to the cache file. Runs
	 * AFTER the buffer has already been flushed to this (the first, MISS)
	 * visitor, on purpose: the very first hit gets the page as WordPress
	 * actually generated it, and every request from here on (a cache HIT,
	 * which reads this file back verbatim with no further processing) gets
	 * whatever this filter returns. This is the extension point Perdita
	 * Pro's minification module uses, so free-tier caching behavior is
	 * completely unchanged when Pro is not active (nothing hooks this by
	 * default).
	 *
	 * @param string $html The captured, unmodified page HTML.
	 * @return string The HTML to store, guaranteed to be a real, non-empty string.
	 */
	private static function filter_output_html( $html ) {
		$filtered = apply_filters( 'perdita_cache_output_html', $html );

		// A misbehaving filter (wrong return type, or an accidentally emptied
		// string) must not get written to disk and served to every visitor
		// after this one. Fall back to the original, known-good HTML instead.
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			return $filtered;
		}
		return $html;
	}

	/**
	 * Write HTML to a cache file atomically-ish: write to a temp file in the same
	 * dir, then rename over the target so a reader never sees a half-written file.
	 *
	 * @param string $file Absolute target path (already a hash inside cache_dir).
	 * @param string $html HTML to store.
	 */
	private function store( $file, $html ) {
		if ( '' === $file ) {
			return;
		}
		$dir = self::cache_dir();
		if ( ! $this->ensure_dir( $dir ) ) {
			return;
		}
		// Defense in depth: the target must resolve inside the cache dir. Since we
		// only ever build $file from file_for_key() this is belt-and-suspenders,
		// but it guarantees no write can land outside even if a caller misuses it.
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return;
		}
		if ( 0 !== strpos( $file, trailingslashit( $real_dir ) ) && 0 !== strpos( $file, $dir ) ) {
			return;
		}

		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing a local cache artifact, not user content.
		$ok = @file_put_contents( $tmp, $html, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ok ) {
			return;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap of a cache file inside this plugin's own cache dir; WP_Filesystem is not available on front-end shutdown.
			wp_delete_file( $tmp );
		}
	}

	/* ---------- exclusions (the safety feature) ---------- */

	/**
	 * Whether the current request may be cached. This is the heart of the
	 * module. When in doubt it returns false: it is always safe to skip caching,
	 * never safe to serve a stale or personalized page to the wrong visitor.
	 *
	 * @param array $settings Current settings (passed to avoid a second read).
	 * @return bool
	 */
	private function is_cacheable( $settings ) {
		// Only ever cache plain GET requests.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}
		// A POST body means a form/comment/checkout is happening.
		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, not processing input.
			return false;
		}
		// Logged-in users see personalized chrome (admin bar, edit links).
		if ( is_user_logged_in() ) {
			return false;
		}
		// Never in these request types.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		// Never cache these page types.
		if ( is_404() || is_search() || is_feed() || is_preview() || is_customize_preview() || is_trackback() || is_robots() ) {
			return false;
		}
		// Password-protected posts vary by cookie; skip them.
		if ( is_singular() && post_password_required() ) {
			return false;
		}
		// A query string outside the whitelist means the URL varies output.
		if ( $this->has_disallowed_query() ) {
			return false;
		}
		// The Host header must be this site's own host. request_key() now keys
		// on home_url()'s host rather than the header, so a spoofed Host can no
		// longer mint extra cache files, and refusing the request outright also
		// stops one host's page being written into another's entry. An absent
		// header (CLI, cron) has nothing to mismatch, so it is left alone.
		$req_host = self::request_host();
		if ( '' !== $req_host && $req_host !== self::site_host() ) {
			return false;
		}
		// Session / cart cookies mean a personalized visitor. Note: perdita_consent
		// is fine to cache (the banner is shown/hidden client-side), so it is NOT
		// in this no-cache list. Cart and login cookies ARE.
		if ( $this->has_no_cache_cookie() ) {
			return false;
		}
		// Sitemaps and robots served through query vars or well-known paths.
		if ( $this->is_sitemap_request() ) {
			return false;
		}
		// Admin-configured extra paths.
		if ( $this->path_is_excluded( $settings ) ) {
			return false;
		}

		/**
		 * Final say for other modules. Return true to keep THIS request out of
		 * the page cache.
		 *
		 * This is the module's public extension point. Any other module (the
		 * Forms module, a future commerce/sales module, a membership add-on) that
		 * renders per-visitor content should hook this and return true on its own
		 * pages so they are never served from cache. Example:
		 *
		 *   add_filter( 'perdita_cache_exclude', function ( $exclude ) {
		 *       return is_singular( 'perdita_form_response' ) ? true : $exclude;
		 *   } );
		 *
		 * The filter is applied here (serve/capture decision) and again in
		 * write_buffer() so a page that only becomes personalized mid-render can
		 * still opt out before it is stored.
		 *
		 * @param bool $exclude Whether to exclude this request. Default false.
		 */
		if ( apply_filters( 'perdita_cache_exclude', false ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the request carries a cookie that marks a personalized visitor
	 * (logged in, commented before, or has a cart). Matched loosely by substring
	 * so WooCommerce's hashed cookie names and multisite login cookies all hit.
	 *
	 * @return bool
	 */
	private function has_no_cache_cookie() {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}
		$needles = array(
			'wordpress_logged_in',
			'comment_author',
			'woocommerce_',
			'wp_woocommerce_session',
			'edd_items_in_cart',
			'perdita_cart',
			// The Pro cart cookie, under both its old and new names. Matching
			// is by substring, so 'perdita_cart' already covers
			// 'perdita_cart_pro'; naming it here anyway means a later edit to
			// the free cookie name can never silently un-cover the Pro one.
			'perdita_cart_pro',
			'perdita_sales_pro_cart',
		);
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $name, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether this is a WordPress sitemap request (core sitemaps use the
	 * sitemap query var; some setups expose /sitemap.xml).
	 *
	 * @return bool
	 */
	private function is_sitemap_request() {
		if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-subtype' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return true;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- normalized to a path immediately.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return (bool) preg_match( '#(?:^|/)(?:wp-sitemap[^/]*\.xml|sitemap[^/]*\.xml|sitemap[^/]*\.xsl)$#i', $path );
	}

	/**
	 * Whether the current request path matches one of the admin-configured
	 * exclusion paths. A configured "/cart" excludes "/cart" and "/cart/...".
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function path_is_excluded( $settings ) {
		$paths = isset( $settings['exclude_paths'] ) ? (array) $settings['exclude_paths'] : array();
		if ( empty( $paths ) ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- normalized to a path immediately.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : rtrim( $path, '/' );
		$path = '' === $path ? '/' : $path;
		foreach ( $paths as $ex ) {
			$ex = '/' . trim( (string) $ex, '/' );
			if ( '/' === $ex ) {
				continue;
			}
			if ( $path === $ex || 0 === strpos( $path . '/', $ex . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- purge ---------- */

	/**
	 * Purge on save_post, but only for a real, public content change. Autosaves,
	 * revisions, and auto-draft transitions do not affect the front end.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! is_a( $post, 'WP_Post' ) ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return;
		}
		// A published (or just-unpublished) post can change many pages: the post
		// itself, archives, the home page, feeds. A full clear is the safe call.
		$this->purge_all();
	}

	/**
	 * Purge the pages a comment can change: the post it was left on, and the
	 * front page.
	 *
	 * DELIBERATE BEHAVIOR CHANGE. This used to call purge_all(), which is
	 * correct but wildly over-broad: on a site with any comment activity the
	 * whole page cache was thrown away every time anyone commented or a
	 * moderator approved something, so the cache never had a chance to warm up.
	 * A comment changes the comment list and count on its own post, plus
	 * whatever the front page shows about it. Nothing else moves.
	 *
	 * What that gives up: a template that lists recent comments in a sidebar on
	 * EVERY page will keep showing the old list on the other pages until their
	 * own TTL expires. If that is your theme, hook `perdita_caching_purged` is
	 * no longer the place to catch it (it never fires for a targeted purge);
	 * call Perdita_Caching::purge_all() from your own comment hook instead.
	 *
	 * @param int|WP_Comment $comment_id Comment id (or object).
	 * @return int Number of cache files removed.
	 */
	public function on_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		$removed = $this->purge_url( home_url( '/' ) );

		if ( $comment && ! empty( $comment->comment_post_ID ) ) {
			$permalink = get_permalink( (int) $comment->comment_post_ID );
			if ( $permalink ) {
				$removed += $this->purge_url( $permalink );
			}
		}
		return $removed;
	}

	/**
	 * transition_comment_status hands the status first and the comment last,
	 * so it cannot share on_comment()'s signature. Approving, unapproving, or
	 * spamming a comment changes the same two pages a new comment does.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    The comment.
	 * @return int Number of cache files removed.
	 */
	public function on_comment_status( $new_status, $old_status, $comment ) {
		return $this->on_comment( $comment );
	}

	/**
	 * Delete the cache file for a single URL, under both schemes.
	 *
	 * The key request_key() builds carries the scheme of the request that was
	 * cached, and a purge runs from wherever the admin happens to be (often
	 * https behind a proxy that serves http upstream, or the reverse), so both
	 * are removed rather than guessing which one is on disk. Everything else in
	 * the key (host, path) is rebuilt exactly as request_key() would.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return int Number of files removed (0, 1, or 2).
	 */
	private function purge_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		$host = self::site_host();
		if ( '' === $host ) {
			return 0;
		}

		$removed = 0;
		foreach ( array( 'https', 'http' ) as $scheme ) {
			$file = self::file_for_key( $scheme . '://' . $host . $path );
			// realpath() confirms the target sits inside the cache dir before
			// anything is unlinked, exactly as purge_all() does.
			$real = is_file( $file ) ? realpath( $file ) : false;
			if ( false === $real ) {
				continue;
			}
			$real_dir = realpath( self::cache_dir() );
			if ( false === $real_dir || 0 !== strpos( $real, trailingslashit( $real_dir ) ) ) {
				continue;
			}
			if ( @unlink( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value drives the removed-count; wp_delete_file() returns nothing.
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Delete every cache file. Safe: it only unlinks .html/.tmp files that
	 * resolve, via realpath, to inside the cache directory. It never removes the
	 * directory itself or the index.php guard.
	 *
	 * @return int Number of files removed.
	 */
	public function purge_all() {
		$dir = self::cache_dir();
		$real_dir = realpath( $dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) ) {
			return 0;
		}
		$real_dir = trailingslashit( $real_dir );
		$removed  = 0;

		foreach ( (array) glob( $real_dir . '*' ) as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			// Leave the silence guard in place.
			if ( 'index.php' === basename( $file ) ) {
				continue;
			}
			// Only ever touch our own artifacts.
			if ( ! preg_match( '/\.(html|tmp)$/', $file ) ) {
				continue;
			}
			$real = realpath( $file );
			if ( false === $real || 0 !== strpos( $real, $real_dir ) ) {
				continue;
			}
			if ( @unlink( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value drives the removed-count; wp_delete_file() returns nothing.
				++$removed;
			}
		}

		/**
		 * Fires after the page cache is cleared. Lets other layers (an object
		 * cache, a CDN client) piggyback on Perdita's purge.
		 *
		 * @param int $removed Number of files removed.
		 */
		do_action( 'perdita_caching_purged', $removed );

		return $removed;
	}

	/* ---------- stats ---------- */

	/**
	 * Total size in bytes and the number of cached pages on disk.
	 *
	 * @return array{bytes:int,files:int}
	 */
	public static function stats() {
		$dir   = self::cache_dir();
		$bytes = 0;
		$files = 0;
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '*.html' ) as $file ) {
				if ( is_file( $file ) ) {
					$bytes += (int) filesize( $file );
					++$files;
				}
			}
		}
		return array(
			'bytes' => $bytes,
			'files' => $files,
		);
	}

	/* ---------- directory + install ---------- */

	/**
	 * Ensure the cache directory exists with an index.php guard so it cannot be
	 * browsed directly.
	 *
	 * @param string $dir Directory path (trailing slash).
	 * @return bool Whether the dir exists and is writable.
	 */
	private function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing a static guard file.
			@file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return wp_is_writable( $dir );
	}

	/**
	 * Create the cache directory and its index.php guard. Runs on module
	 * activation. Static so the descriptor can call it without an instance.
	 */
	public static function install() {
		$dir = self::cache_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = $dir . 'index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing a static guard file.
			@file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/* ---------- browser cache / gzip via .htaccess ---------- */

	/**
	 * Whether the server is Apache (or LiteSpeed, which reads .htaccess). Only
	 * then do the .htaccess rules apply; nginx and others get a copyable snippet
	 * in the admin instead.
	 *
	 * @return bool
	 */
	public static function server_is_apache() {
		$sig = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		if ( '' !== $sig ) {
			return ( false !== strpos( $sig, 'apache' ) || false !== strpos( $sig, 'litespeed' ) );
		}
		// Fall back to WordPress's own detection when the signature is hidden.
		return ( function_exists( 'got_mod_rewrite' ) && got_mod_rewrite() );
	}

	/**
	 * Absolute path to the site's .htaccess file.
	 *
	 * @return string
	 */
	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * The mod_expires + mod_deflate rule lines (no BEGIN/END markers; those are
	 * added by insert_with_markers). Shared by the writer and the admin preview.
	 *
	 * @return array Lines.
	 */
	public static function htaccess_rules() {
		return array(
			'<IfModule mod_deflate.c>',
			'  AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml',
			'  AddOutputFilterByType DEFLATE application/javascript application/x-javascript text/javascript',
			'  AddOutputFilterByType DEFLATE application/json application/xml application/rss+xml',
			'  AddOutputFilterByType DEFLATE image/svg+xml application/vnd.ms-fontobject',
			'  AddOutputFilterByType DEFLATE font/ttf font/otf font/woff font/woff2',
			'</IfModule>',
			'<IfModule mod_expires.c>',
			'  ExpiresActive On',
			'  ExpiresByType text/css "access plus 1 year"',
			'  ExpiresByType application/javascript "access plus 1 year"',
			'  ExpiresByType text/javascript "access plus 1 year"',
			'  ExpiresByType image/jpeg "access plus 1 year"',
			'  ExpiresByType image/png "access plus 1 year"',
			'  ExpiresByType image/gif "access plus 1 year"',
			'  ExpiresByType image/webp "access plus 1 year"',
			'  ExpiresByType image/avif "access plus 1 year"',
			'  ExpiresByType image/svg+xml "access plus 1 year"',
			'  ExpiresByType image/x-icon "access plus 1 year"',
			'  ExpiresByType font/woff "access plus 1 year"',
			'  ExpiresByType font/woff2 "access plus 1 year"',
			'  ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
			'  ExpiresByType text/html "access plus 0 seconds"',
			'</IfModule>',
		);
	}

	/**
	 * The equivalent nginx snippet, for the admin to show when the server is not
	 * Apache. Returned as a single string.
	 *
	 * @return string
	 */
	public static function nginx_snippet() {
		return implode(
			"\n",
			array(
				'# Perdita Cache: browser caching + gzip. Add inside your server {} block.',
				'gzip on;',
				'gzip_comp_level 5;',
				'gzip_min_length 256;',
				'gzip_types text/plain text/css text/xml application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf font/woff font/woff2;',
				'',
				'location ~* \.(?:css|js|jpg|jpeg|png|gif|webp|avif|svg|ico|woff|woff2|ttf|otf|eot)$ {',
				'    expires 1y;',
				'    add_header Cache-Control "public, immutable";',
				'    access_log off;',
				'}',
			)
		);
	}

	/**
	 * Write the browser-cache rules into .htaccess under the Perdita Cache
	 * marker. Uses insert_with_markers(), which only replaces the marked block
	 * and leaves the rest of the file (including WordPress's own rewrite rules)
	 * untouched. No-op on non-Apache servers or when .htaccess is not writable.
	 *
	 * @return bool Whether the rules were written.
	 */
	public function write_htaccess_rules() {
		if ( ! self::server_is_apache() ) {
			return false;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$path = self::htaccess_path();
		// insert_with_markers creates the file if missing, but only in a writable
		// dir. Guard so we never fatal on a locked-down host.
		if ( ( file_exists( $path ) && ! wp_is_writable( $path ) ) || ( ! file_exists( $path ) && ! wp_is_writable( dirname( $path ) ) ) ) {
			return false;
		}
		return (bool) insert_with_markers( $path, self::HTACCESS_MARKER, self::htaccess_rules() );
	}

	/**
	 * Remove the Perdita Cache block from .htaccess. Passing an empty array to
	 * insert_with_markers strips the marked block and nothing else.
	 *
	 * @return bool Whether the removal ran (or there was nothing to remove).
	 */
	public function remove_htaccess_rules() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$path = self::htaccess_path();
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! wp_is_writable( $path ) ) {
			return false;
		}
		return (bool) insert_with_markers( $path, self::HTACCESS_MARKER, array() );
	}
}
