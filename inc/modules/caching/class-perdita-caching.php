<?php
/**
 * Page caching and browser-cache headers.
 *
 * WHAT THIS IS. A page cache. On a cacheable request it serves a previously
 * saved HTML file and exits, skipping the rest of WordPress. On a cacheable
 * miss it buffers the page and writes the final HTML to disk on shutdown.
 * With "Serve before WordPress loads" on, wp-content/advanced-cache.php
 * answers hits before WordPress boots (see dropin_source()). It never edits
 * wp-config.php.
 *
 * Around that core, each behind its own setting and off by default:
 *  - preload: build every public page in the background (class-perdita-caching-preload.php)
 *  - gzip copies: a .gz beside each page, served to clients that accept gzip
 *  - serve stale: keep the old copy while one request rebuilds the page
 *  - a separate copy of each page for phones
 *  - rejected user agents and cookies
 *  - feed caching
 *  - a scheduled sweep of expired files, the cached pages list and the debug
 *    log (class-perdita-caching-tools.php)
 *  - CDN URL rewriting (class-perdita-caching-cdn.php)
 *  - wp perdita cache ... (class-perdita-caching-cli.php)
 *
 * FILES ON DISK, all in uploads/perdita-cache/. One page is
 * {sha1 of its URL}{variant suffix}.html, with these beside it:
 *   .html.json   headers to replay, then one entry naming the URL
 *   .html.gz     the gzip copy
 *   .html.stale  a purge marked this page for a rebuild
 *   .html.lock   a request is rebuilding it right now
 * A full purge deletes all of them at once. A single-URL purge deletes them
 * too, unless "serve stale" is on, in which case it writes the .stale mark.
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
 * EXTENSION POINTS. Other modules can mark a page non-cacheable with the
 * `perdita_cache_exclude` filter (documented on that method below). The Forms
 * and any commerce module use it to keep their dynamic pages fresh.
 *
 * Perdita Pro and other plugins also code against these, so the names are a
 * contract:
 *   filter perdita_cache_output_html( string $html )           stored HTML
 *   filter perdita_cache_allow_logged_in( bool false, int $user_id )
 *   filter perdita_cache_key_variant( string '' )               key variant
 *   filter perdita_cache_preload_urls( string[] $urls, string $phase )
 *   action perdita_cache_purged_all( int $removed )
 *   action perdita_cache_purged_url( string $url, int $count )
 *   action perdita_cache_preload_finished( array $stats )
 *   Perdita_Caching::purge_urls( array $urls ): int
 *   Perdita_Caching::purge_everything(): int
 *   Perdita_Caching::settings(): array
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
	 * ob_get_level() right after our capture buffer opened, so write_buffer()
	 * reads our buffer and not one somebody opened after it.
	 *
	 * @var int
	 */
	private $buffer_level = 0;

	/**
	 * The canonical URL this request is cached under, for the sidecar and
	 * the cached pages list.
	 *
	 * @var string
	 */
	private $cache_url = '';

	/**
	 * The variant this request is cached under ('' for the shared copy).
	 *
	 * @var string
	 */
	private $cache_variant = '';

	/**
	 * Whether a filter let this logged-in request into the cache.
	 *
	 * @var bool
	 */
	private $logged_in_allowed = false;

	/**
	 * Whether this request is a feed that the feed cache covers.
	 *
	 * @var bool
	 */
	private $is_feed_request = false;

	/**
	 * When capture began (microtime), so a page whose render overlapped a
	 * purge is never stored. 0 when unknown, which skips that check.
	 *
	 * @var float
	 */
	private $capture_started = 0;

	/**
	 * When this request last cleared the whole cache (microtime). The stamp
	 * file says the same for every other request.
	 *
	 * @var float
	 */
	private static $purged_all_at = 0.0;

	/**
	 * When this request last purged each URL, by the sha1 of its key.
	 *
	 * @var array<string,float>
	 */
	private static $purged_url_at = array();

	/**
	 * Post saves in progress: what changed, recorded by post_updated and
	 * transition_post_status for on_save_post() to act on.
	 *
	 * @var array<int,array>
	 */
	private $post_changes = array();

	/**
	 * File in the cache dir holding the time of the last full purge.
	 */
	const PURGE_STAMP = 'purged-all.stamp';

	/**
	 * File in the cache dir holding roughly how many bytes the cache uses.
	 */
	const USAGE_FILE = 'usage.stamp';

	/**
	 * File in the cache dir holding the drop-in's filter values as the
	 * front end sees them, when they differ from the admin's.
	 */
	const FRONT_CONFIG_FILE = 'frontend-config.json';

	/**
	 * How long a single-URL purge mark is kept, in seconds. A render that
	 * started before the purge and is still running after this is not one
	 * PHP lets finish.
	 */
	const PURGE_MARK_TTL = 3600;

	/**
	 * Why is_cacheable() said no, for the debug log.
	 *
	 * @var string
	 */
	private $skip_reason = '';

	/**
	 * How long a stale copy may be served while a fresh one is built, in
	 * seconds, counted from the moment the page expired or was purged.
	 */
	const STALE_MAX_AGE = 600;

	/**
	 * How long one rebuild may hold its lock, in seconds. A stale copy is
	 * served only while a live lock exists, so a rebuild that died stops
	 * holding visitors on the old page after this long.
	 */
	const LOCK_TTL = 30;

	/**
	 * Cron hook for the scheduled sweep of expired files.
	 */
	const GC_HOOK = 'perdita_cache_gc';

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
		// A post save clears everything by default. With "purge_on_save" set
		// to "smart" it clears the post's own pages and the lists it sits in,
		// and still clears everything when something site-wide moved (see
		// on_save_post()). pre_post_update and transition_post_status only
		// record what changed, so one save makes one purge.
		add_action( 'pre_post_update', array( $this, 'on_post_updated' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ) );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );
		// A term's name and slug print on every post filed under it, in menus
		// and in widgets, so any term change clears everything.
		add_action( 'created_term', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'edited_term', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'delete_term', array( $this, 'purge_all' ), 10, 0 );
		// Menus: the menu itself is a term (covered above), its items are
		// nav_menu_item posts (a type nobody views, so on_save_post clears
		// everything), and these two catch a rename or delete from the
		// Menus screen.
		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'wp_delete_nav_menu', array( $this, 'purge_all' ), 10, 0 );
		// Turning any plugin on or off can change every page.
		add_action( 'activated_plugin', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ), 10, 0 );
		// A comment only changes two pages: the post it is on, and the front
		// page (where a recent-comments widget or a comment count can show).
		// These used to clear the entire cache, so a busy comment thread threw
		// away every cached page on the site over and over.
		add_action( 'comment_post', array( $this, 'on_comment' ) );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		// A theme, plugin or WordPress update can change every page's markup
		// and its ?ver= asset URLs. Without this, a live site kept serving
		// the previous version's pages for an hour after an update.
		add_action( 'upgrader_process_complete', array( $this, 'purge_all' ), 10, 0 );
		// Any Perdita-owned option can change rendered HTML: the design token
		// document, this module's own settings, the SEO store, the module
		// on/off registry, and every other module's settings (analytics,
		// search console, security headers, and so on). Rather than hardcoding
		// one hook per option name here (easy to write once and forget to
		// repeat for the next module), hook the generic, un-suffixed actions
		// WordPress fires for every option write and match by the 'perdita_'
		// prefix every one of those options shares. See
		// purge_on_option_change() for why all three are needed.
		add_action( 'added_option', array( $this, 'purge_on_option_change' ) );
		add_action( 'updated_option', array( $this, 'purge_on_option_change' ), 10, 3 );
		add_action( 'deleted_option', array( $this, 'purge_on_option_change' ) );

		// Keep wp-content/advanced-cache.php in step with the settings: put
		// it back after another plugin removed it, take it out when another
		// page cache comes on. Admin and WP-CLI only, never a visitor request.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'admin_init', array( __CLASS__, 'sync_dropin' ) );
		}
		add_action( 'activated_plugin', array( __CLASS__, 'sync_dropin_later' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'sync_dropin_later' ) );

		// Background work. The classes behind these load only when a hook
		// fires, so an ordinary page view never parses them.
		add_action( self::GC_HOOK, array( __CLASS__, 'run_gc' ) );
		// A store that found the cache full asks for a run within a minute.
		add_action( self::GC_HOOK . '_soon', array( __CLASS__, 'run_gc' ) );
		add_action( 'perdita_cache_preload_batch', array( __CLASS__, 'run_preload_batch' ) );
		add_action( 'perdita_cache_preload_start', array( __CLASS__, 'run_preload_start' ) );
		add_action( 'perdita_cache_purged_url', array( __CLASS__, 'queue_preload_url' ) );
		add_action( 'perdita_cache_purged_all', array( __CLASS__, 'queue_preload_all' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'sync_schedules' ) );
		} elseif ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// A site that updates itself and never opens wp-admin still gets
			// its cleanup booked: WP-Cron and WP-CLI requests book it too.
			// admin_init never fires on either one.
			add_action( 'init', array( __CLASS__, 'sync_schedules' ), 20 );
			// Same for the early-serve drop-in: after an automatic update the
			// file on disk is the previous release's until one of these
			// requests rewrites it.
			add_action( 'init', array( __CLASS__, 'sync_dropin' ), 21 );
		}

		// CDN rewriting runs in its own buffer, opened right after the capture
		// buffer, so a cached page and an uncached one carry the same URLs.
		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( __CLASS__, 'start_cdn_buffer' ), 2 );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			require_once __DIR__ . '/class-perdita-caching-cli.php';
			WP_CLI::add_command( 'perdita cache', 'Perdita_Caching_CLI' );
		}
	}

	/**
	 * Load one of the module's helper classes on first use.
	 *
	 * @param string $which preload, cdn or tools.
	 */
	public static function load( $which ) {
		$files = array(
			'preload' => 'class-perdita-caching-preload.php',
			'cdn'     => 'class-perdita-caching-cdn.php',
			'tools'   => 'class-perdita-caching-tools.php',
		);
		if ( isset( $files[ $which ] ) ) {
			require_once __DIR__ . '/' . $files[ $which ];
		}
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
			'early_serve'     => false,          // Serve hits from wp-content/advanced-cache.php.
			'serve_stale'     => false,          // Serve the old copy while a fresh one is built.
			'gzip'            => false,          // Keep a gzip copy beside each page.
			'mobile_cache'    => false,          // A second copy of each page for phones.
			'cache_feeds'     => false,          // Cache RSS and Atom feeds too.
			'reject_user_agents' => array(),     // User agent fragments that skip the cache.
			'reject_cookies'  => array(),        // Extra cookie name fragments that skip the cache.
			'preload'         => false,          // Build pages in the background.
			'preload_interval_hours' => 0,       // Rebuild everything this often (0 is never).
			'gc_schedule'     => 'daily',        // off, hourly, twicedaily or daily.
			'gc_chosen'       => false,          // A save picked the schedule (an older "off" was only the old default).
			'max_size_mb'     => 1024,           // Cap on the cache directory, in MB (0 is no cap).
			'purge_on_save'   => 'all',          // all (clear everything on a post save) or smart.
			'cdn_enabled'     => false,          // Rewrite static asset URLs to the CDN host.
			'cdn_host'        => '',             // CDN hostname.
			'cdn_dirs'        => array( 'wp-content', 'wp-includes' ),
			'cdn_exclude'     => array( '.php' ),
			'debug_log'       => false,          // Write cache decisions to a log file.
			'debug_log_until' => 0,              // When the log switches itself off.
		);
	}

	/**
	 * Schedules the garbage collector accepts.
	 *
	 * @return string[]
	 */
	public static function gc_schedules() {
		return array( 'off', 'hourly', 'twicedaily', 'daily' );
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
		$s['early_serve']   = ! empty( $s['early_serve'] );
		$s['ttl_hours']     = max( 1, (int) $s['ttl_hours'] );
		$s['exclude_paths'] = is_array( $s['exclude_paths'] ) ? array_values( $s['exclude_paths'] ) : array();
		foreach ( array( 'serve_stale', 'gzip', 'mobile_cache', 'cache_feeds', 'preload', 'cdn_enabled' ) as $flag ) {
			$s[ $flag ] = ! empty( $s[ $flag ] );
		}
		foreach ( array( 'reject_user_agents', 'reject_cookies', 'cdn_dirs', 'cdn_exclude' ) as $list ) {
			$s[ $list ] = is_array( $s[ $list ] ) ? array_values( array_filter( array_map( 'strval', $s[ $list ] ), 'strlen' ) ) : array();
		}
		$s['preload_interval_hours'] = max( 0, (int) $s['preload_interval_hours'] );
		$s['gc_schedule']            = in_array( $s['gc_schedule'], self::gc_schedules(), true ) ? $s['gc_schedule'] : 'daily';
		$s['gc_chosen']              = ! empty( $s['gc_chosen'] );
		// Garbage collection used to default to off, so an "off" saved before
		// the schedule was a choice on the screen is read as the new default.
		// Without it, cache files stayed on disk forever.
		if ( 'off' === $s['gc_schedule'] && ! $s['gc_chosen'] ) {
			$s['gc_schedule'] = 'daily';
		}
		$s['max_size_mb']            = max( 0, (int) $s['max_size_mb'] );
		$s['purge_on_save']          = in_array( $s['purge_on_save'], array( 'all', 'smart' ), true ) ? $s['purge_on_save'] : 'all';
		$s['cdn_host']               = is_string( $s['cdn_host'] ) ? $s['cdn_host'] : '';
		$s['debug_log_until']        = (int) $s['debug_log_until'];
		// The debug log turns itself off a day after it was switched on. The
		// stored flag stays as it is, because writing this option clears the
		// whole cache.
		$s['debug_log'] = ! empty( $s['debug_log'] ) && $s['debug_log_until'] > time();
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

		// The CDN host is a bare hostname (a port is allowed). Anything else,
		// a scheme, a path, a stray character, is dropped or refused, because
		// this value is written into every page.
		$cdn_host = isset( $input['cdn_host'] ) && is_scalar( $input['cdn_host'] ) ? strtolower( trim( (string) $input['cdn_host'] ) ) : '';
		$cdn_host = (string) preg_replace( '#^(?:[a-z][a-z0-9+.-]*:)?//#', '', $cdn_host );
		$cdn_host = (string) strtok( $cdn_host, '/?#' );
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?(?::\d{1,5})?$/', $cdn_host ) ) {
			$cdn_host = '';
		}

		// Directories are path fragments under the site root: letters, digits,
		// dot, dash, underscore and slashes between them, never "..".
		$cdn_dirs = array();
		foreach ( self::sanitize_list( isset( $input['cdn_dirs'] ) ? $input['cdn_dirs'] : array() ) as $dir ) {
			$dir = trim( $dir, '/' );
			if ( preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $dir ) && false === strpos( $dir, '..' ) ) {
				$cdn_dirs[] = $dir;
			}
		}
		$cdn_dirs = array_values( array_unique( $cdn_dirs ) );
		if ( empty( $cdn_dirs ) ) {
			$cdn_dirs = array( 'wp-content', 'wp-includes' );
		}

		$gc = isset( $input['gc_schedule'] ) && in_array( $input['gc_schedule'], self::gc_schedules(), true ) ? $input['gc_schedule'] : 'daily';

		// Turning the debug log on starts its 24 hours. Saving the screen while
		// it is already on keeps the original deadline.
		$debug       = ! empty( $input['debug_log'] );
		$stored      = get_option( self::OPTION, array() );
		$debug_until = 0;
		if ( $debug ) {
			$was         = is_array( $stored ) && ! empty( $stored['debug_log'] ) && isset( $stored['debug_log_until'] ) ? (int) $stored['debug_log_until'] : 0;
			$debug_until = $was > time() ? $was : time() + DAY_IN_SECONDS;
		}

		return array(
			'enabled'                => ! empty( $input['enabled'] ),
			'ttl_hours'              => $ttl,
			'exclude_paths'          => $paths,
			'browser_cache'          => ! empty( $input['browser_cache'] ),
			'early_serve'            => ! empty( $input['early_serve'] ),
			'serve_stale'            => ! empty( $input['serve_stale'] ),
			'gzip'                   => ! empty( $input['gzip'] ),
			'mobile_cache'           => ! empty( $input['mobile_cache'] ),
			'cache_feeds'            => ! empty( $input['cache_feeds'] ),
			'reject_user_agents'     => self::sanitize_list( isset( $input['reject_user_agents'] ) ? $input['reject_user_agents'] : array() ),
			'reject_cookies'         => self::sanitize_list( isset( $input['reject_cookies'] ) ? $input['reject_cookies'] : array() ),
			'preload'                => ! empty( $input['preload'] ),
			'preload_interval_hours' => isset( $input['preload_interval_hours'] ) ? min( 720, max( 0, (int) $input['preload_interval_hours'] ) ) : 0,
			'gc_schedule'            => $gc,
			'gc_chosen'              => isset( $input['gc_schedule'] ),
			'cdn_enabled'            => ! empty( $input['cdn_enabled'] ),
			'cdn_host'               => $cdn_host,
			'cdn_dirs'               => $cdn_dirs,
			'cdn_exclude'            => isset( $input['cdn_exclude'] ) ? self::sanitize_list( $input['cdn_exclude'] ) : array( '.php' ),
			'debug_log'              => $debug,
			'debug_log_until'        => $debug_until,
			'max_size_mb'            => isset( $input['max_size_mb'] ) && is_scalar( $input['max_size_mb'] ) ? min( 1048576, max( 0, (int) $input['max_size_mb'] ) ) : 1024,
			'purge_on_save'          => isset( $input['purge_on_save'] ) && 'smart' === $input['purge_on_save'] ? 'smart' : 'all',
		);
	}

	/**
	 * Clean a list setting: a textarea (one entry per line) or an array.
	 * Each entry is plain text with no control characters, at most 100
	 * characters, and the list stops at 100 entries.
	 *
	 * @param mixed $raw Raw input.
	 * @return string[]
	 */
	public static function sanitize_list( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_filter( $raw, 'is_scalar' ) );
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', is_scalar( $raw ) ? (string) $raw : '' ) as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' !== $line && strlen( $line ) <= 100 ) {
				$out[] = $line;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 100 );
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
	 * A variant (the phone copy, or whatever `perdita_cache_key_variant`
	 * returned) adds a short keyed hash after the URL's hash. Every copy of
	 * one URL shares the same first 40 characters, so a purge finds them
	 * all, and nobody can work out a variant's file name from its URL.
	 *
	 * @param string $key     Canonical request URL (scheme+host+path+whitelisted query).
	 * @param string $variant Variant label, '' for the shared copy.
	 * @return string Absolute file path.
	 */
	public static function file_for_key( $key, $variant = '' ) {
		return self::cache_dir() . sha1( $key ) . self::variant_suffix( $variant ) . '.html';
	}

	/**
	 * The file name suffix for a variant: '' for the shared copy, otherwise
	 * a dash and 12 hex characters keyed on the site's salt.
	 *
	 * @param string $variant Variant label.
	 * @return string
	 */
	public static function variant_suffix( $variant ) {
		$variant = (string) $variant;
		if ( '' === $variant ) {
			return '';
		}
		return '-' . substr( hash_hmac( 'sha1', $variant, wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * User agent fragments that mark a phone, matched case-sensitively, the
	 * way wp_is_mobile() matches them. The default is wp_is_mobile()'s own
	 * list, so a tablet WordPress calls mobile gets the phone copy too and a
	 * theme that branches on wp_is_mobile() never writes its phone markup
	 * into the desktop copy. The drop-in gets this list written into it.
	 *
	 * @return string[]
	 */
	public static function mobile_user_agents() {
		/**
		 * User agent fragments (matched case-sensitively, like
		 * wp_is_mobile()) that get the phone copy when "Separate cache for
		 * phones" is on. Change it together with any `wp_is_mobile` filter,
		 * or requests the two disagree on are never cached.
		 *
		 * @param string[] $needles Fragments.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'perdita_cache_mobile_user_agents', array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ) ) ), 'strlen' ) );
	}

	/**
	 * User agent fragments that are never a phone, whatever else matches.
	 * Empty: wp_is_mobile() has no exceptions, and the phone test follows it.
	 *
	 * @return string[]
	 */
	public static function mobile_user_agent_exceptions() {
		return array();
	}

	/**
	 * Whether a user agent is a phone by the user agent alone.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_mobile_ua( $ua ) {
		return self::is_mobile_request( array( 'HTTP_USER_AGENT' => (string) $ua ) );
	}

	/**
	 * Whether a request is a phone, by the same test wp_is_mobile() runs
	 * (before its filter): the Sec-CH-UA-Mobile client hint when the
	 * browser sends one, otherwise the user agent. The drop-in runs this
	 * exact test on the same list.
	 *
	 * @param array $server A $_SERVER-shaped array.
	 * @return bool
	 */
	public static function is_mobile_request( $server ) {
		if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
			return '?1' === $server['HTTP_SEC_CH_UA_MOBILE'];
		}
		$ua = isset( $server['HTTP_USER_AGENT'] ) && is_string( $server['HTTP_USER_AGENT'] ) ? $server['HTTP_USER_AGENT'] : '';
		if ( '' === $ua ) {
			return false;
		}
		foreach ( self::mobile_user_agent_exceptions() as $not ) {
			if ( '' !== $not && false !== strpos( $ua, $not ) ) {
				return false;
			}
		}
		foreach ( self::mobile_user_agents() as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The request's user agent, as sent.
	 *
	 * @return string
	 */
	private static function request_user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Whether this request is a phone by Perdita's test, read from the raw
	 * request the way wp_is_mobile() reads it.
	 *
	 * @return bool
	 */
	private static function request_is_mobile() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a whole against fixed strings, never stored or printed.
		return self::is_mobile_request( wp_unslash( $_SERVER ) );
	}

	/**
	 * The variant this request is cached under: the phone copy when the
	 * separate phone cache is on, plus whatever another plugin mixes in.
	 *
	 * @param array $settings Settings.
	 * @return string '' for the shared copy.
	 */
	private function request_variant( $settings ) {
		$parts = array();
		if ( ! empty( $settings['mobile_cache'] ) && self::request_is_mobile() ) {
			$parts[] = 'mobile';
		}
		$custom = self::custom_variant();
		if ( '' !== $custom ) {
			$parts[] = 'x:' . $custom;
		}
		return implode( '|', $parts );
	}

	/**
	 * What other plugins mix into the cache key for this request.
	 *
	 * @return string
	 */
	private static function custom_variant() {
		/**
		 * A string mixed into this request's cache key, so one URL can hold
		 * several copies (one per membership level, say). Return '' for the
		 * shared copy. Only the in-WordPress cache reads it: the early-serve
		 * drop-in answers the shared copy and the phone copy, so a variant
		 * for visitors it can't tell apart also needs a rejected cookie.
		 *
		 * @param string $variant Variant. Default ''.
		 */
		$custom = apply_filters( 'perdita_cache_key_variant', '' );
		return is_scalar( $custom ) ? (string) $custom : '';
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
		$path = self::request_path();
		$path = '' === $path ? '/' : $path;

		$query = $this->whitelisted_query();
		return $scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * This request's path, checked by clean_path(), or '' when it isn't one
	 * the cache may key on.
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a strict pattern in clean_path().
		return self::clean_path( is_string( $uri ) ? $uri : '' );
	}

	/**
	 * The path part of a request URI, or '' when the path isn't a plain,
	 * normalized one: it starts with "//" (PHP's URL parser reads that as a
	 * host, so "//hello-world/" keyed as the home page), or it has an empty,
	 * "." or ".." segment (plain or percent-encoded), a backslash, or a
	 * control character. The cache keys only on paths a canonical URL can
	 * have, and the drop-in runs the same pattern.
	 *
	 * @param string $uri Request URI, query string and all.
	 * @return string
	 */
	public static function clean_path( $uri ) {
		$uri  = (string) $uri;
		$path = substr( $uri, 0, strcspn( $uri, '?#' ) );
		if ( '' === $path ) {
			return '/';
		}
		if ( '/' !== $path[0] || preg_match( self::BAD_PATH_PATTERN, $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * What makes a path one the cache refuses. Shared with the drop-in.
	 */
	const BAD_PATH_PATTERN = '#//|%00|\\\\|[\x00-\x20\x7f]|(?:^|/)(?:\.|%2e){1,2}(?:/|$)#i';

	/**
	 * Query args whose value must be a page number above 1.
	 *
	 * @return string[]
	 */
	public static function numeric_query_args() {
		return array( 'page', 'paged' );
	}

	/**
	 * Whether a whitelisted query arg's value is one the cache keys on. A
	 * page number is digits only and above 1 (page 1 is the plain URL), and
	 * any other value is up to 64 letters, digits, dashes and underscores.
	 * An array is never one. The drop-in runs the same test, so the two
	 * agree on every request, and a visitor can't mint a new cache file per
	 * made-up value.
	 *
	 * @param string $name  Arg name.
	 * @param mixed  $value Arg value.
	 * @return bool
	 */
	public static function query_value_ok( $name, $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		if ( in_array( (string) $name, self::numeric_query_args(), true ) ) {
			return (bool) preg_match( '/^[1-9][0-9]{0,8}$/', $value ) && (int) $value > 1;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value );
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
		$allowed = self::query_whitelist();
		$query   = $this->significant_query();
		$pairs   = array();
		foreach ( $allowed as $arg ) {
			if ( isset( $query[ $arg ] ) && self::query_value_ok( $arg, $query[ $arg ] ) ) {
				$pairs[ $arg ] = $query[ $arg ];
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
		$allowed = self::query_whitelist();
		foreach ( $get as $arg => $value ) {
			// An arg on the whitelist with a value the cache won't key on (a
			// made-up page number, an array) refuses the request too, or it
			// would be stored under the URL without that arg.
			if ( ! in_array( (string) $arg, $allowed, true ) || ! self::query_value_ok( (string) $arg, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Query args the cache keys on, as plain names.
	 *
	 * @return string[]
	 */
	public static function query_whitelist() {
		/**
		 * Query args a cached URL may carry. Each becomes part of the cache
		 * key, and its value has to pass query_value_ok().
		 *
		 * @param string[] $args Arg names. Default page and paged.
		 */
		$allowed = (array) apply_filters( 'perdita_cache_query_whitelist', array( 'page', 'paged' ) );
		return array_values( array_unique( array_filter( array_map( 'strval', array_filter( $allowed, 'is_scalar' ) ), 'strlen' ) ) );
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
		// Two page caches on one site each serve the other's stale copies
		// and neither purges the other's, so Perdita waits until the other
		// one is off. The Caching screen says which plugin it's waiting on.
		if ( '' !== self::other_page_cache() ) {
			return;
		}
		// The drop-in serves with filter values it got when it was written.
		// If this front-end request sees different ones, early serving stops
		// until the next admin request writes a drop-in that matches.
		if ( ! empty( $settings['early_serve'] ) ) {
			self::check_dropin_config();
		}
		if ( ! $this->is_cacheable( $settings ) ) {
			self::log( 'SKIP', self::log_uri() . ' (' . $this->skip_reason . ')' );
			return;
		}

		$this->cache_url     = $this->request_key();
		$this->cache_variant = $this->request_variant( $settings );
		$this->cache_file    = self::file_for_key( $this->cache_url, $this->cache_variant );

		$look = self::lookup( $this->cache_file, $settings );
		// A stale copy is for anonymous visitors only.
		if ( 'stale' === $look['state'] && $this->logged_in_allowed ) {
			$look['state'] = 'miss';
		}

		// HIT (or STALE while another request rebuilds): serve it and stop.
		if ( 'miss' !== $look['state'] && $this->serve_file( $this->cache_file, $look, $settings ) ) {
			self::log( 'hit' === $look['state'] ? 'HIT' : 'STALE', self::display_url( $this->cache_url ) );
			exit;
		}

		// An old copy is on disk and this request is about to replace it. The
		// lock tells every other request to keep serving the old copy until
		// the new one lands.
		if ( ! empty( $settings['serve_stale'] ) && $look['exists'] && ! $this->logged_in_allowed ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a rebuild lock beside our own cache file.
			@file_put_contents( $this->cache_file . '.lock', (string) time() );
		}
		$this->capture_started = microtime( true );
		self::log( 'MISS', self::display_url( $this->cache_url ) );

		// MISS: capture. Buffer the whole response and decide at shutdown.
		// Cache-Control is set there too, once it's known whether the page
		// was stored: a redirect, an error or a page that turns out to be
		// personal must never go out marked public. WordPress's Site Health
		// "page cache" check still sees it on every stored page.
		if ( ! headers_sent() ) {
			header( 'X-Cache: MISS' );
			self::send_vary_headers( $settings );
		}
		$this->buffering = true;
		ob_start();
		$this->buffer_level = ob_get_level();
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
	 * @param string $file     Absolute path to the cache file being served.
	 * @param string $state    hit or stale.
	 * @param int    $gz_bytes Size of the gzip copy when that is the body, else 0.
	 * @param array  $settings Settings.
	 * @return bool True if a 304 Not Modified was sent (caller should exit
	 *              with no body); false if the caller should still echo the HTML.
	 */
	private function send_hit_headers( $file, $state = 'hit', $gz_bytes = 0, $settings = array() ) {
		if ( headers_sent() ) {
			return false;
		}

		$mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; falsy mtime just skips the conditional-GET checks below.
		$etag  = self::etag_for_file( $file );
		if ( $gz_bytes > 0 ) {
			// The gzip copy is a different representation, so it gets its own tag.
			$etag = substr( $etag, 0, -1 ) . '-gz"';
		}
		$age = $mtime > 0 ? max( 0, time() - $mtime ) : 0;

		header( 'X-Cache: ' . ( 'stale' === $state ? 'STALE' : 'HIT' ) );
		// A browser may keep a fresh page for a few minutes. It gets no such
		// leave for a stale one, which is about to be replaced.
		// (A logged-in visitor's page keeps WordPress's private, no-store headers.)
		if ( ! $this->logged_in_allowed ) {
			header( 'Cache-Control: max-age=' . ( 'stale' === $state ? 0 : self::CLIENT_CACHE_MAX_AGE ) . ', public' );
		}
		self::send_vary_headers( $settings );
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
		if ( $gz_bytes > 0 ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . (int) $gz_bytes );
		}
		return false;
	}

	/**
	 * Say what a response varies on, so a shared cache between the visitor
	 * and this server keeps the copies apart.
	 *
	 * @param array $settings Settings.
	 */
	private static function send_vary_headers( $settings ) {
		if ( ! empty( $settings['gzip'] ) ) {
			header( 'Vary: Accept-Encoding', false );
		}
		if ( ! empty( $settings['mobile_cache'] ) ) {
			header( 'Vary: User-Agent', false );
			header( 'Vary: Sec-CH-UA-Mobile', false );
		}
	}

	/**
	 * What is on disk for a cache file: a fresh copy (hit), an old copy
	 * another request is rebuilding right now (stale), or nothing to serve
	 * (miss).
	 *
	 * A copy is old when it outlived the cache lifetime or a purge marked it
	 * (a `.stale` file beside it). An old copy is served only when "serve
	 * stale" is on, a live rebuild lock sits beside it, and it has been old
	 * for less than stale_max_age().
	 *
	 * @param string $file     Absolute cache file path.
	 * @param array  $settings Settings.
	 * @return array{state:string,mtime:int,exists:bool}
	 */
	public static function lookup( $file, $settings ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- another request can delete the file between the two calls.
		$mtime = ( '' !== $file && is_file( $file ) ) ? (int) @filemtime( $file ) : 0;
		if ( $mtime <= 0 ) {
			return array(
				'state'  => 'miss',
				'mtime'  => 0,
				'exists' => false,
			);
		}
		$now    = time();
		$ttl    = max( 1, isset( $settings['ttl_hours'] ) ? (int) $settings['ttl_hours'] : 10 ) * HOUR_IN_SECONDS;
		$marked = is_file( $file . '.stale' );
		$state  = 'miss';
		if ( ! $marked && $now - $mtime <= $ttl ) {
			$state = 'hit';
		} elseif ( ! empty( $settings['serve_stale'] ) ) {
			$since = $marked ? self::stale_since( $file ) : $mtime + $ttl;
			$lock  = is_file( $file . '.lock' ) ? (int) @filemtime( $file . '.lock' ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the lock can vanish between the two calls.
			if ( $now - $since <= self::stale_max_age() && $lock > 0 && $now - $lock <= self::LOCK_TTL ) {
				$state = 'stale';
			}
		}
		return array(
			'state'  => $state,
			'mtime'  => $mtime,
			'exists' => true,
		);
	}

	/**
	 * How long an old copy may be served while a fresh one is built.
	 *
	 * @return int Seconds.
	 */
	public static function stale_max_age() {
		/**
		 * The hard cap on serving a stale page, in seconds, counted from the
		 * moment it expired or was purged.
		 *
		 * @param int $seconds Default 600.
		 */
		return max( 0, (int) apply_filters( 'perdita_cache_stale_max_age', self::STALE_MAX_AGE ) );
	}

	/**
	 * When a purge first marked this file stale. The mark's contents hold
	 * that first moment, and its mtime the latest purge.
	 *
	 * @param string $file Cache file.
	 * @return int Unix time, 0 when the file carries no mark.
	 */
	public static function stale_since( $file ) {
		if ( ! is_file( $file . '.stale' ) ) {
			return 0;
		}
		$first = (int) @file_get_contents( $file . '.stale' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a ten-byte local mark.
		return $first > 0 ? $first : (int) @filemtime( $file . '.stale' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the mark can vanish between the two calls.
	}

	/**
	 * Whether this response may go out as stored gzip bytes: the visitor
	 * accepts gzip, and nothing else (PHP's zlib output compression, an
	 * output handler, a header already set) is going to compress or rewrite
	 * the body on its way out.
	 *
	 * @return bool
	 */
	private static function can_send_gzip() {
		$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) : '';
		if ( false === stripos( $accept, 'gzip' ) || preg_match( '/gzip\s*;\s*q=0(?:\.0*)?\s*(?:,|$)/i', $accept ) ) {
			return false;
		}
		if ( headers_sent() || ! in_array( strtolower( (string) ini_get( 'zlib.output_compression' ) ), array( '', '0', 'off' ), true ) ) {
			return false;
		}
		if ( array_diff( ob_list_handlers(), array( 'default output handler' ) ) ) {
			return false;
		}
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'Content-Encoding:' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Send a cached page: headers, then the body (the gzip copy when the
	 * visitor takes one), or a bodyless 304.
	 *
	 * @param string $file     Cache file.
	 * @param array  $look     lookup() result.
	 * @param array  $settings Settings.
	 * @return bool False when the file could not be read, so the caller
	 *              rebuilds the page instead.
	 */
	private function serve_file( $file, $look, $settings ) {
		$body = false;
		$gz   = 0;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the gzip copy can vanish between the two calls.
		if ( ! empty( $settings['gzip'] ) && is_file( $file . '.gz' ) && (int) @filemtime( $file . '.gz' ) >= (int) $look['mtime'] && self::can_send_gzip() ) {
			$body = @file_get_contents( $file . '.gz' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache read; false handled below.
			$gz   = is_string( $body ) ? strlen( $body ) : 0;
		}
		if ( $gz <= 0 ) {
			$body = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache read; false handled below.
		}
		if ( false === $body || '' === $body ) {
			return false;
		}
		// Client already has this exact content (matched by ETag or
		// Last-Modified): a bodyless 304 is a real bandwidth saving.
		if ( $this->send_hit_headers( $file, $look['state'], $gz, $settings ) ) {
			return true;
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving our own previously captured, complete document.
		return true;
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
	 * On shutdown, write the buffered HTML to the cache file, but only for a
	 * clean 200 HTML response. Anything unusual (a redirect, a non-200 status, a
	 * non-HTML body, an empty body) is flushed to the browser without caching.
	 */
	public function write_buffer() {
		if ( ! $this->buffering ) {
			return;
		}
		$this->buffering = false;

		// Close every buffer opened after ours first, so its callback runs and
		// its output lands in ours. WordPress 6.9+ opens one right before the
		// template (wp_start_template_enhancement_output_buffer()) and fills
		// its placeholders there, block styles included, so reading the
		// innermost buffer here stored the page without them: cached pages on
		// nonprofitmanager.app and hsatracker.org lost WordPress's per-block
		// CSS (buttons, groups, spacers) while the first visitor's copy had it.
		while ( ob_get_level() > $this->buffer_level ) {
			$status = ob_get_status();
			if ( empty( $status['flags'] ) || ! ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_flush() ) {
				$this->decline( 'an output buffer could not be closed' );
				return;
			}
		}
		// Our buffer is gone (something closed it), so whatever buffer is
		// current now isn't the page. Don't cache it.
		if ( ob_get_level() !== $this->buffer_level ) {
			$this->decline( 'the capture buffer was closed early' );
			return;
		}

		$html = ob_get_contents();
		if ( false === $html ) {
			$this->decline( 'nothing captured' );
			return;
		}

		// Decide before the flush, so the response headers can still say
		// whether this page may be shared.
		$verdict = $this->verdict( $html );
		$this->send_final_cache_control( 'store' === $verdict['action'] );

		// Let the buffer flush to the browser whether or not it is stored.
		ob_end_flush();

		if ( 'abandon' === $verdict['action'] ) {
			$this->abandon( $verdict['why'] );
			return;
		}
		if ( 'decline' === $verdict['action'] ) {
			$this->decline( $verdict['why'] );
			return;
		}

		$stored = $this->is_feed_request ? $html : self::filter_output_html( $html );
		if ( ! self::has_room( strlen( $stored ) ) ) {
			$this->decline( 'the cache is full' );
			return;
		}
		$settings = self::settings();
		$had      = self::entry_bytes( $this->cache_file );
		// A gzip copy that outlived its page would go out with the wrong
		// body, so the old one goes before the new page is written.
		if ( is_file( $this->cache_file . '.gz' ) ) {
			wp_delete_file( $this->cache_file . '.gz' );
		}
		$this->store( $this->cache_file, $stored );
		// A purge that landed while the file was being written: the copy on
		// disk may be the old page, so it goes.
		if ( self::purged_since( $this->cache_url, $this->capture_started ) ) {
			self::delete_entry( $this->cache_file );
			self::log( 'SKIP', self::display_url( $this->cache_url ) . ' (purged during the write)' );
			return;
		}
		if ( ! is_file( $this->cache_file ) ) {
			$this->release_lock();
			return;
		}
		if ( ! empty( $settings['gzip'] ) && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $stored, 6 );
			if ( false !== $gz ) {
				$this->store( $this->cache_file . '.gz', $gz );
			}
		}
		self::add_usage( self::entry_bytes( $this->cache_file ) - $had );
		$this->store_headers( $this->cache_file );

		// The page is fresh again. A purge that landed after this render
		// began refused the store above, so any mark here is older.
		if ( is_file( $this->cache_file . '.stale' ) ) {
			wp_delete_file( $this->cache_file . '.stale' );
		}
		$this->release_lock();
		self::log( 'STORE', self::display_url( $this->cache_url ) );
	}

	/**
	 * What to do with a captured response: store it, decline it (keep any
	 * older copy, store nothing), or abandon it (the URL isn't a cacheable
	 * page any more, so the older copy goes too).
	 *
	 * @param string $html Captured body.
	 * @return array{action:string,why:string}
	 */
	private function verdict( $html ) {
		$result = static function ( $action, $why = '' ) {
			return array(
				'action' => $action,
				'why'    => $why,
			);
		};
		if ( '' === trim( (string) $html ) ) {
			return $result( 'decline', 'empty body' );
		}
		// Only cache a normal 200 response.
		// http_response_code() answers false only where no response exists
		// (the CLI), never on a web request.
		$status = function_exists( 'http_response_code' ) ? http_response_code() : 200;
		if ( false !== $status && 200 !== (int) $status ) {
			return $result( 'abandon', 'status ' . (int) $status );
		}
		// Only cache HTML (or a feed, when the feed cache is on and this
		// request is one). Any other body is probably JSON or a redirect stub.
		if ( $this->is_feed_request ) {
			$looks_right = 0 === strpos( ltrim( $html ), '<?xml' ) || false !== stripos( $html, '<rss' ) || false !== stripos( $html, '<feed' ) || false !== stripos( $html, '<rdf:RDF' );
		} else {
			$looks_right = ( false !== stripos( $html, '<html' ) ) || ( false !== stripos( $html, '<!doctype html' ) );
		}
		if ( ! $looks_right ) {
			return $result( 'abandon', 'not a page' );
		}
		// WooCommerce, membership and form plugins define DONOTCACHEPAGE on
		// pages that carry one visitor's data.
		if ( self::page_says_do_not_cache() ) {
			return $result( 'abandon', 'DONOTCACHEPAGE' );
		}
		// A late exclusion filter can still veto (e.g. a shortcode that ran during
		// render decided the page is personalized).
		if ( apply_filters( 'perdita_cache_exclude', false ) ) {
			return $result( 'abandon', 'excluded during render' );
		}
		// A response that hands the visitor a cookie may carry that visitor's
		// session in its HTML (a cart, a form token), so it is never stored.
		$cookies = self::response_cookie_names();
		if ( ! empty( $cookies ) ) {
			return $result( 'decline', 'sets a cookie (' . implode( ', ', $cookies ) . ')' );
		}
		// A plugin that said this response must not be stored is believed.
		if ( self::response_forbids_storing() ) {
			return $result( 'decline', 'Cache-Control forbids storing' );
		}
		// A purge that arrived while this page rendered: the render may hold
		// what the purge was meant to remove (a post that just went private).
		if ( self::purged_since( $this->cache_url, $this->capture_started ) ) {
			return $result( 'decline', 'purged during render' );
		}
		return $result( 'store' );
	}

	/**
	 * Set the response's Cache-Control once the outcome is known. A stored
	 * anonymous page goes out public for a few minutes. Every other
	 * outcome goes out private and no-store, unless WordPress or a plugin
	 * already sent something at least that strict.
	 *
	 * @param bool $stored Whether the page is being stored.
	 */
	private function send_final_cache_control( $stored ) {
		if ( headers_sent() ) {
			return;
		}
		if ( $stored && ! $this->logged_in_allowed ) {
			header( 'Cache-Control: max-age=' . self::CLIENT_CACHE_MAX_AGE . ', public' );
			return;
		}
		$current = self::response_header( 'Cache-Control' );
		if ( '' !== $current && preg_match( '/\b(?:no-store|private)\b/i', $current ) ) {
			return;
		}
		header( 'Cache-Control: private, no-store, max-age=0' );
	}

	/**
	 * The value of one response header queued so far, or ''.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private static function response_header( $name ) {
		$value = '';
		foreach ( self::sent_headers() as $line ) {
			if ( 0 === stripos( $line, $name . ':' ) ) {
				$value = trim( substr( $line, strlen( $name ) + 1 ) );
			}
		}
		return $value;
	}

	/**
	 * The response headers queued so far. headers_list() is empty in the
	 * CLI, so tests hand in their own list through the filter.
	 *
	 * @return string[]
	 */
	private static function sent_headers() {
		/**
		 * The response headers the page cache reads its decision from.
		 *
		 * @param string[] $lines headers_list().
		 */
		return array_map( 'strval', (array) apply_filters( 'perdita_cache_response_headers', headers_list() ) );
	}

	/**
	 * Whether the response already says it must not be stored.
	 *
	 * @return bool
	 */
	private static function response_forbids_storing() {
		$cc = self::response_header( 'Cache-Control' );
		return '' !== $cc && (bool) preg_match( '/\b(?:no-store|private)\b/i', $cc );
	}

	/**
	 * Whether the page defined WordPress's do-not-cache constant.
	 *
	 * @return bool
	 */
	public static function page_says_do_not_cache() {
		return defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
	}

	/**
	 * This request turned out not to be worth storing, but the URL is still
	 * a cacheable page: keep whatever copy is on disk and drop the lock.
	 *
	 * @param string $why Reason, for the debug log.
	 */
	private function decline( $why ) {
		$this->release_lock();
		self::log( 'SKIP', self::display_url( $this->cache_url ) . ' (' . $why . ')' );
	}

	/**
	 * Drop this request's rebuild lock.
	 */
	private function release_lock() {
		if ( '' !== $this->cache_file && is_file( $this->cache_file . '.lock' ) ) {
			wp_delete_file( $this->cache_file . '.lock' );
		}
	}

	/**
	 * This request turned out not to be a page worth keeping (a 404, a
	 * redirect, a page a filter excluded). Whatever old copy is on disk is
	 * wrong now, so it goes too, instead of being served stale.
	 *
	 * @param string $why Reason, for the debug log.
	 */
	private function abandon( $why ) {
		if ( '' !== $this->cache_file ) {
			self::delete_entry( $this->cache_file );
		}
		self::log( 'SKIP', self::display_url( $this->cache_url ) . ' (' . $why . ')' );
	}

	/**
	 * Names of the cookies this response sets, less the ones a site said
	 * are safe to ignore.
	 *
	 * @return string[]
	 */
	public static function response_cookie_names() {
		/**
		 * Cookie names that may be set on a response the page cache still
		 * stores. Only list a cookie whose value never shows in the page,
		 * such as a consent flag. Nothing is stripped from the response.
		 *
		 * @param string[] $names Exact cookie names. Default none.
		 */
		$safe  = array_map( 'strval', (array) apply_filters( 'perdita_cache_allow_set_cookie', array() ) );
		$names = array();
		foreach ( self::sent_headers() as $line ) {
			if ( 0 !== stripos( $line, 'Set-Cookie:' ) ) {
				continue;
			}
			$name = trim( (string) strtok( trim( substr( $line, 11 ) ), '=;' ) );
			if ( ! in_array( $name, $safe, true ) ) {
				$names[] = '' === $name ? '(unnamed)' : $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Headers a cached page is replayed with when the drop-in serves it.
	 * Security and content headers only: never a cookie, never a caching
	 * header (the hit sets its own).
	 *
	 * @return string[] Lowercase header names.
	 */
	public static function replay_headers() {
		/**
		 * Response headers saved beside a cached page and sent again when
		 * the early-serve drop-in answers before WordPress loads.
		 *
		 * @param string[] $names Lowercase header names.
		 */
		return array_map(
			'strtolower',
			(array) apply_filters(
				'perdita_cache_replay_headers',
				array( 'content-type', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'strict-transport-security', 'content-security-policy', 'content-security-policy-report-only', 'cross-origin-opener-policy', 'cross-origin-resource-policy', 'cross-origin-embedder-policy', 'x-robots-tag', 'link' )
			)
		);
	}

	/**
	 * Save this response's replayable headers next to its cache file, so a
	 * page the drop-in serves carries the same security headers WordPress
	 * sent the first time. Without it an early hit would drop every header a
	 * plugin adds on send_headers (the Security module's CSP among them).
	 *
	 * @param string $file Cache file just written.
	 */
	private function store_headers( $file ) {
		if ( '' === $file || ! is_file( $file ) || ! function_exists( 'headers_list' ) ) {
			return;
		}
		$keep = self::replay_headers();
		$out  = array();
		foreach ( headers_list() as $line ) {
			$name = strtolower( trim( (string) strtok( $line, ':' ) ) );
			if ( in_array( $name, $keep, true ) ) {
				$out[] = $line;
			}
		}
		// The last entry is not a header line: it says which URL this file is,
		// for the cached pages list. The drop-in replays strings only, so it
		// skips it.
		$out[] = array(
			'url'     => self::display_url( $this->cache_url ),
			'variant' => '' === $this->cache_variant ? '' : ( 0 === strpos( $this->cache_variant, 'mobile' ) ? 'mobile' : 'custom' ),
			'type'    => $this->is_feed_request ? 'feed' : 'page',
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a small sidecar next to our own cache file.
		@file_put_contents( $file . '.json', (string) wp_json_encode( $out ), LOCK_EX );
	}

	/**
	 * A cache key as an address someone can open. The key leaves the port
	 * out (it is built from the site's bare hostname), so the port from the
	 * site's own address goes back in.
	 *
	 * @param string $key Cache key from request_key().
	 * @return string
	 */
	private static function display_url( $key ) {
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		if ( ! $port || '' === (string) $key ) {
			return (string) $key;
		}
		$host = self::site_host();
		return (string) preg_replace( '#^(https?://' . preg_quote( $host, '#' ) . ')(?=/|$)#', '$1:' . (int) $port, (string) $key );
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
		if ( ! self::ensure_dir( $dir ) ) {
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
		$this->skip_reason       = '';
		$this->logged_in_allowed = false;
		$this->is_feed_request   = false;
		if ( 'GET' !== $method ) {
			return $this->skip( 'method' );
		}
		// A POST body means a form/comment/checkout is happening.
		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, not processing input.
			return $this->skip( 'post body' );
		}
		// Logged-in users see personalized chrome (admin bar, edit links), so
		// they stay out unless another plugin vouches for the request AND
		// gives it a key variant of its own. Without a variant the page would
		// land in the copy anonymous visitors get, so that is refused.
		if ( is_user_logged_in() ) {
			/**
			 * Whether this logged-in request may be cached. Pair it with
			 * `perdita_cache_key_variant`: a logged-in request with no variant
			 * is never cached.
			 *
			 * @param bool $allow   Default false.
			 * @param int  $user_id Current user id.
			 */
			if ( ! apply_filters( 'perdita_cache_allow_logged_in', false, get_current_user_id() ) || '' === self::custom_variant() ) {
				return $this->skip( 'logged in' );
			}
			$this->logged_in_allowed = true;
		}
		// Never in these request types.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $this->skip( 'admin, ajax or cron' );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $this->skip( 'rest' );
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return $this->skip( 'xmlrpc' );
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $this->skip( 'autosave' );
		}
		// Never cache these page types.
		if ( is_404() || is_search() || is_preview() || is_customize_preview() || is_trackback() || is_robots() ) {
			return $this->skip( '404, search, preview or robots' );
		}
		// Feeds are cached only when the feed cache is on.
		if ( is_feed() ) {
			if ( empty( $settings['cache_feeds'] ) ) {
				return $this->skip( 'feed' );
			}
			$this->is_feed_request = true;
		}
		// Password-protected posts vary by cookie; skip them. Not only when
		// post_password_required() is true: once a visitor has entered the
		// password it turns false, and the unlocked page would then be
		// cached and served to everyone. The wp-postpass_ cookie check in
		// has_no_cache_cookie() covers the other pages that visitor loads.
		$queried = get_queried_object();
		if ( ( is_singular() && post_password_required() ) || ( $queried instanceof WP_Post && '' !== (string) $queried->post_password ) ) {
			return $this->skip( 'password-protected' );
		}
		// A query string outside the whitelist means the URL varies output.
		if ( $this->has_disallowed_query() ) {
			return $this->skip( 'query string' );
		}
		// A path that isn't plain and normalized ("//x/", "/a/../b/") could
		// key one page under another page's URL.
		if ( '' === self::request_path() ) {
			return $this->skip( 'path' );
		}
		// The page said so itself, before WordPress got this far.
		if ( self::page_says_do_not_cache() ) {
			return $this->skip( 'DONOTCACHEPAGE' );
		}
		// With the phone cache on, the copy is picked by Perdita's phone
		// test. A plugin that filters wp_is_mobile() can answer differently
		// for this visitor, and the theme renders by its answer, so the
		// page would land in the wrong copy.
		if ( ! empty( $settings['mobile_cache'] ) && (bool) wp_is_mobile() !== self::request_is_mobile() ) {
			return $this->skip( 'phone test disagrees with wp_is_mobile()' );
		}
		// The Host header must be this site's own host. request_key() now keys
		// on home_url()'s host rather than the header, so a spoofed Host can no
		// longer mint extra cache files, and refusing the request outright also
		// stops one host's page being written into another's entry. An absent
		// header (CLI, cron) has nothing to mismatch, so it is left alone.
		$req_host = self::request_host();
		if ( '' !== $req_host && $req_host !== self::site_host() ) {
			return $this->skip( 'host' );
		}
		// Session / cart cookies mean a personalized visitor. Note: perdita_consent
		// is fine to cache (the banner is shown/hidden client-side), so it is NOT
		// in this no-cache list. Cart and login cookies ARE.
		if ( $this->has_no_cache_cookie( $this->logged_in_allowed ) ) {
			return $this->skip( 'cookie' );
		}
		// User agents the site owner keeps out of the cache.
		if ( self::user_agent_is_rejected( self::request_user_agent(), $settings ) ) {
			return $this->skip( 'user agent' );
		}
		// Sitemaps and robots served through query vars or well-known paths.
		if ( $this->is_sitemap_request() ) {
			return $this->skip( 'sitemap' );
		}
		// Admin-configured extra paths.
		if ( $this->path_is_excluded( $settings ) ) {
			return $this->skip( 'excluded path' );
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
			return $this->skip( 'perdita_cache_exclude' );
		}

		return true;
	}

	/**
	 * Record why a request stays out of the cache, and answer false.
	 *
	 * @param string $reason Short reason for the debug log.
	 * @return bool Always false.
	 */
	private function skip( $reason ) {
		$this->skip_reason = (string) $reason;
		return false;
	}

	/**
	 * Whether a user agent matches one of the site owner's rejected
	 * fragments. Matching is case-insensitive, anywhere in the string.
	 *
	 * @param string $ua       User agent.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	public static function user_agent_is_rejected( $ua, $settings ) {
		$needles = isset( $settings['reject_user_agents'] ) ? (array) $settings['reject_user_agents'] : array();
		$ua      = (string) $ua;
		if ( '' === $ua || empty( $needles ) ) {
			return false;
		}
		foreach ( $needles as $needle ) {
			$needle = (string) $needle;
			if ( '' !== $needle && false !== stripos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the request carries a cookie that marks a personalized visitor
	 * (logged in, commented before, or has a cart). Matched loosely by substring
	 * so WooCommerce's hashed cookie names and multisite login cookies all hit.
	 *
	 * @param bool $allow_login True when a filter let this logged-in request
	 *                          in, so the login cookie alone doesn't refuse it.
	 * @return bool
	 */
	private function has_no_cache_cookie( $allow_login = false ) {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}
		$needles = self::no_cache_cookies();
		if ( $allow_login ) {
			$needles = array_diff( $needles, array( 'wordpress_logged_in' ) );
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			foreach ( $needles as $needle ) {
				if ( '' !== $needle && false !== stripos( $name, $needle ) ) {
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
		$path = self::request_path();
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
		return self::path_matches_exclusions( self::request_path(), $paths );
	}

	/**
	 * Whether a URL path falls under one of the excluded paths. The page
	 * cache and the preloader share it, so a path that is never cached is
	 * never requested for preloading either.
	 *
	 * @param string   $path  URL path.
	 * @param string[] $paths Excluded paths.
	 * @return bool
	 */
	public static function path_matches_exclusions( $path, $paths ) {
		$path = (string) $path;
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
	 * Note a post's state before an update, so on_save_post() can tell what
	 * moved. Runs on pre_post_update, before the database row changes, so
	 * the term changes wp_insert_post() makes next find the record open.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    New post data (unused).
	 */
	public function on_post_updated( $post_id, $data = null ) {
		$id     = (int) $post_id;
		$before = $id > 0 ? get_post( $id ) : null;
		if ( ! $before instanceof WP_Post || isset( $this->post_changes[ $id ]['before'] ) ) {
			return;
		}
		$this->post_changes[ $id ]['before'] = clone $before;
		if ( 'publish' === $before->post_status ) {
			$old = get_permalink( $before );
			if ( is_string( $old ) && '' !== $old ) {
				$this->post_changes[ $id ]['urls'][] = $old;
			}
		}
	}

	/**
	 * Note the status a post had before this save. The first transition in a
	 * request wins, so a chain of updates still compares against the start.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		if ( ! isset( $this->post_changes[ $id ]['old_status'] ) ) {
			$this->post_changes[ $id ]['old_status'] = (string) $old_status;
		}
	}

	/**
	 * Purge after a post save. Autosaves, revisions and auto-drafts change
	 * nothing a visitor sees.
	 *
	 * With "purge_on_save" at "all" (the default) every save clears the
	 * whole cache, as it always has. At "smart", a save clears:
	 *  - nothing, for a post that was not published before or after;
	 *  - everything, when the post was published or unpublished (trash,
	 *    draft, private, scheduled), when its title, slug, date, parent,
	 *    menu order, password or public terms changed, or when it is a type
	 *    nobody views directly (menu items, templates, patterns, global
	 *    styles);
	 *  - otherwise its own pages and the lists it sits in (see
	 *    urls_for_post()).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		$post_id = (int) $post_id;
		$change  = isset( $this->post_changes[ $post_id ] ) ? $this->post_changes[ $post_id ] : array();
		unset( $this->post_changes[ $post_id ] );
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
		$settings = self::settings();
		if ( 'smart' !== $settings['purge_on_save'] ) {
			$this->purge_all();
			return;
		}
		$scope = self::post_purge_scope( $post, $change );
		if ( 'all' === $scope ) {
			$this->purge_all();
		} elseif ( 'urls' === $scope ) {
			$extra = isset( $change['urls'] ) ? (array) $change['urls'] : array();
			self::purge_urls( self::urls_for_post( $post, $extra ) );
		}
	}

	/**
	 * How much a save of this post has to clear in smart mode: none, urls
	 * (the post's own pages and lists) or all.
	 *
	 * @param WP_Post $post   The post as saved.
	 * @param array   $change What on_post_updated() and
	 *                        on_transition_post_status() recorded.
	 * @return string
	 */
	public static function post_purge_scope( $post, $change ) {
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return 'all';
		}
		$before = isset( $change['before'] ) && $change['before'] instanceof WP_Post ? $change['before'] : null;
		$old    = isset( $change['old_status'] ) ? (string) $change['old_status'] : ( $before ? $before->post_status : '' );
		$new    = (string) $post->post_status;
		if ( 'publish' !== $old && 'publish' !== $new ) {
			// An update with no record of the old state may still have
			// unpublished something, so only a known non-public post is let off.
			return '' === $old ? 'all' : 'none';
		}
		if ( $old !== $new || null === $before || ! empty( $change['terms'] ) ) {
			return 'all';
		}
		foreach ( array( 'post_title', 'post_name', 'post_date', 'post_parent', 'menu_order', 'post_password', 'post_type' ) as $field ) {
			if ( (string) $before->$field !== (string) $post->$field ) {
				return 'all';
			}
		}
		return 'urls';
	}

	/**
	 * The URLs an in-place edit of a published post can change: the post,
	 * the home page and the blog page, its post type archive, its term
	 * archives, its author and date archives, the list page it sits on in
	 * each paged list (with the pages either side), the posts before and
	 * after it, and the feeds.
	 *
	 * @param WP_Post|int $post  Post.
	 * @param string[]    $extra More URLs, such as the post's old address.
	 * @return string[]
	 */
	public static function urls_for_post( $post, $extra = array() ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$urls  = array_merge( array( home_url( '/' ) ), array_filter( (array) $extra, 'is_string' ) );
		$lists = array();
		$link  = get_permalink( $post );
		if ( $link ) {
			$urls[] = $link;
			$urls[] = get_post_comments_feed_link( $post->ID );
		}
		$per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$base     = array(
			'post_type'   => $post->post_type,
			'post_status' => 'publish',
		);
		if ( 'post' === $post->post_type ) {
			$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
			$blog       = $posts_page > 0 ? get_permalink( $posts_page ) : home_url( '/' );
			if ( $blog ) {
				$lists[] = array( $blog, $base );
			}
			$time   = strtotime( $post->post_date );
			$urls[] = get_year_link( (int) gmdate( 'Y', $time ) );
			$urls[] = get_month_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ) );
			$urls[] = get_day_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ), (int) gmdate( 'd', $time ) );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive && 'post' !== $post->post_type ) {
			$lists[] = array( $archive, $base );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( empty( $tax->public ) || empty( $tax->publicly_queryable ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$term_link = get_term_link( $term );
				if ( is_string( $term_link ) ) {
					$lists[] = array(
						$term_link,
						$base + array(
							'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one count query per term on a post save.
								array(
									'taxonomy' => $tax->name,
									'terms'    => array( (int) $term->term_id ),
								),
							),
						),
					);
					$urls[] = get_term_feed_link( $term->term_id, $tax->name );
				}
			}
		}
		if ( post_type_supports( $post->post_type, 'author' ) ) {
			$lists[] = array( get_author_posts_url( (int) $post->post_author ), $base + array( 'author' => (int) $post->post_author ) );
			$urls[]  = get_author_feed_link( (int) $post->post_author );
		}
		// Each paged list: the page this post sits on, and one either side.
		foreach ( $lists as $list ) {
			$urls[] = $list[0];
			$query  = new WP_Query(
				$list[1] + array(
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'date_query'             => array( array( 'after' => $post->post_date ) ),
				)
			);
			$page = (int) floor( (int) $query->found_posts / $per_page ) + 1;
			for ( $n = max( 2, $page - 1 ); $n <= $page + 1; $n++ ) {
				$urls[] = self::paged_url( $list[0], $n );
			}
		}
		// The posts before and after it print its title in their navigation.
		foreach ( array( 'before', 'after' ) as $side ) {
			$ids = get_posts(
				array(
					'post_type'        => $post->post_type,
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'orderby'          => 'date',
					'order'            => 'before' === $side ? 'DESC' : 'ASC',
					'date_query'       => array( array( $side => $post->post_date ) ),
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = get_permalink( (int) $id );
			}
		}
		$urls[] = get_feed_link();
		$urls[] = get_feed_link( 'atom' );
		$urls[] = get_feed_link( 'comments_rss2' );

		/**
		 * The URLs a smart purge clears when a published post is edited in
		 * place. Add a page that lists this post somewhere the default list
		 * doesn't know about.
		 *
		 * @param string[] $urls URLs on this site.
		 * @param WP_Post  $post The post.
		 */
		$urls = (array) apply_filters( 'perdita_cache_purge_urls_for_post', $urls, $post );
		return array_values( array_unique( array_filter( $urls, static function ( $url ) {
			return is_string( $url ) && '' !== $url;
		} ) ) );
	}

	/**
	 * Page $n of a paged list, the way WordPress links to it.
	 *
	 * @param string $base The list's first page.
	 * @param int    $n    Page number.
	 * @return string
	 */
	public static function paged_url( $base, $n ) {
		global $wp_rewrite;
		$n = (int) $n;
		if ( $n <= 1 ) {
			return (string) $base;
		}
		if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() && false === strpos( (string) $base, '?' ) ) {
			return trailingslashit( (string) $base ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $n, 'paged' );
		}
		return add_query_arg( 'paged', $n, (string) $base );
	}

	/**
	 * Note a post's state before it is deleted for good.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_before_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->post_changes[ (int) $post_id ]['deleted'] = array(
				'status' => $post->post_status,
				'type'   => $post->post_type,
			);
		}
	}

	/**
	 * A post deleted for good (wp post delete --force, "Delete Permanently"
	 * with the trash off) never fires save_post. A published one, or one of
	 * a type nobody views directly, clears everything.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_deleted_post( $post_id ) {
		$id   = (int) $post_id;
		$info = isset( $this->post_changes[ $id ]['deleted'] ) ? $this->post_changes[ $id ]['deleted'] : null;
		unset( $this->post_changes[ $id ] );
		if ( null === $info || 'revision' === $info['type'] || 'auto-draft' === $info['status'] ) {
			return;
		}
		if ( 'publish' === $info['status'] || ! is_post_type_viewable( $info['type'] ) ) {
			$this->purge_all();
		}
	}

	/**
	 * A published post's public terms changed. Term archives, term counts
	 * and every list filtered by a term can move, so this counts as a
	 * site-wide change in both modes. During a save the purge waits for
	 * on_save_post(), so one save makes one purge. Outside one (the block
	 * editor sets terms after save_post, and wp_set_post_terms() can run on
	 * its own) everything clears now.
	 *
	 * @param int    $object_id  Object id.
	 * @param array  $terms      Terms passed in (unused).
	 * @param array  $tt_ids     New term taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended (unused).
	 * @param array  $old_tt_ids Old term taxonomy ids.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old ) {
			return;
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->public ) ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$id = (int) $post->ID;
		if ( isset( $this->post_changes[ $id ] ) ) {
			$this->post_changes[ $id ]['terms'] = true;
			return;
		}
		if ( 'publish' === $post->post_status ) {
			$this->purge_all();
		}
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
		// With the feed cache on, a comment also changes the comment feeds.
		$settings = self::settings();
		if ( ! empty( $settings['cache_feeds'] ) ) {
			$feeds = array( get_feed_link( 'comments_rss2' ) );
			if ( $comment && ! empty( $comment->comment_post_ID ) ) {
				$feeds[] = get_post_comments_feed_link( (int) $comment->comment_post_ID );
			}
			foreach ( array_filter( $feeds ) as $feed ) {
				$removed += $this->purge_url( $feed );
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
	 * Purge after an option write that can change what a page prints.
	 *
	 * WordPress only fires `update_option_{$name}` when the option already
	 * existed and its value changed. A module's settings saved for the first
	 * time create the row instead, so the three generic actions (added,
	 * updated, deleted) are hooked and every name goes through
	 * option_affects_render().
	 *
	 * @param string $option Option name being added, updated, or deleted.
	 * @param mixed  $old    Old value (updated_option only).
	 * @param mixed  $new    New value (updated_option only).
	 */
	public function purge_on_option_change( $option, $old = null, $new = null ) {
		if ( self::option_affects_render( (string) $option ) ) {
			$this->purge_all();
		}
	}

	/**
	 * Whether a write to this option can change a rendered page.
	 *
	 * Core options are an allowlist: the site's name and tagline, reading
	 * and permalink settings, date and language formats, the site icon,
	 * sticky posts, widgets, theme mods and comment display settings.
	 * WordPress writes many other options on ordinary requests (cron,
	 * transients, rewrite rules, user counts) and none of them are markup.
	 *
	 * Perdita's own options purge by default, since most of them are a
	 * module's settings, which print in the page. The exceptions are
	 * runtime state no page reads: queues, locks, tokens, run logs,
	 * counters, notices, schema versions and migration flags, plus the
	 * MCP OAuth store, the IndexNow log, this module's own runtime state
	 * (`perdita_cache_*`) and the Search Console connection (its
	 * verification tag lives in the SEO settings, which still purge).
	 * Without them, an AI client connecting, a queued subscription email or
	 * a token refresh emptied the cache for every visitor.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function option_affects_render( $option ) {
		$option = (string) $option;
		$verdict = false;
		if ( 0 === strpos( $option, 'perdita_' ) ) {
			$verdict = true;
			foreach ( array( 'perdita_mcp_oauth_', 'perdita_indexnow', 'perdita_cache_' ) as $prefix ) {
				if ( 0 === strpos( $option, $prefix ) ) {
					$verdict = false;
				}
			}
			foreach ( array( '_db_version', '_queue', '_lock', '_locks', '_state', '_stats', '_notice', '_notices', '_key_error', '_seen', '_run', '_runs', '_last_run', '_migrated', '_baseline_time', '_scan_results', '_integration_failure', '_tokens', '_token', '_log', '_defaulted' ) as $suffix ) {
				if ( substr( $option, -strlen( $suffix ) ) === $suffix ) {
					$verdict = false;
				}
			}
			if ( in_array( $option, array( 'perdita_search_console_settings', 'perdita_subscriptions_queue', 'perdita_core_auto_updates_defaulted', 'perdita_seo_llms_rules', 'perdita_core_pointer', 'perdita_onboarding_pending' ), true ) ) {
				$verdict = false;
			}
		} else {
			/**
			 * Options outside Perdita whose writes clear the page cache.
			 *
			 * @param string[] $names Exact names. A name ending in "*" matches
			 *                        by prefix.
			 */
			$names = (array) apply_filters(
				'perdita_cache_purge_options',
				array( 'blogname', 'blogdescription', 'permalink_structure', 'category_base', 'tag_base', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'date_format', 'time_format', 'start_of_week', 'timezone_string', 'gmt_offset', 'WPLANG', 'site_icon', 'sticky_posts', 'sidebars_widgets', 'widget_*', 'theme_mods_*', 'blog_public', 'home', 'siteurl', 'show_avatars', 'avatar_default', 'avatar_rating', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'default_comment_status', 'comment_registration', 'close_comments_for_old_posts', 'close_comments_days_old', 'require_name_email', 'wp_page_for_privacy_policy', 'site_logo', 'large_size_w', 'large_size_h', 'medium_size_w', 'medium_size_h', 'thumbnail_size_w', 'thumbnail_size_h' )
			);
			foreach ( $names as $name ) {
				$name = (string) $name;
				if ( '' === $name ) {
					continue;
				}
				if ( '*' === substr( $name, -1 ) ? 0 === strpos( $option, substr( $name, 0, -1 ) ) : $option === $name ) {
					$verdict = true;
					break;
				}
			}
		}
		/**
		 * Whether a write to this option clears the page cache. The last word,
		 * after the allowlist above and Perdita's runtime-state exceptions.
		 *
		 * @param bool   $verdict Whether it purges.
		 * @param string $option  Option name.
		 */
		return (bool) apply_filters( 'perdita_cache_option_purges', $verdict, $option );
	}

	/**
	 * Purge the cache for a single URL, under both schemes and every variant.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return int Number of cached copies removed or marked stale.
	 */
	private function purge_url( $url ) {
		return self::invalidate_url( $url );
	}

	/**
	 * Purge a list of URLs. For other plugins and Perdita Pro.
	 *
	 * With "serve stale" on, each copy is marked stale and stays on disk
	 * until its replacement is written. Otherwise it is deleted. Either way
	 * `perdita_cache_purged_url` fires once per URL.
	 *
	 * @param array $urls Absolute URLs (or paths) on this site. A URL on any
	 *                    other host is ignored.
	 * @return int Number of cached copies removed or marked stale.
	 */
	public static function purge_urls( array $urls ) {
		$count = 0;
		foreach ( $urls as $url ) {
			$count += self::invalidate_url( $url );
		}
		return $count;
	}

	/**
	 * The cache keys a URL on this site is stored under, one per scheme,
	 * built the way request_key() builds them: the site's host, the path
	 * (refused when request_key() would refuse it) and the whitelisted query
	 * args in order. A URL whose query has anything else was never cached
	 * under it, so its plain path is purged instead.
	 *
	 * @param mixed $url URL or path.
	 * @return string[]
	 */
	public static function keys_for_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return array();
		}
		$parts = wp_parse_url( $url );
		$host  = self::site_host();
		if ( ! is_array( $parts ) || '' === $host ) {
			return array();
		}
		if ( isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== $host ) {
			return array();
		}
		$path = self::clean_path( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' );
		if ( '' === $path ) {
			return array();
		}
		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$args = array();
			wp_parse_str( $parts['query'], $args );
			$args    = array_diff_key( $args, array_flip( self::ignored_query_args() ) );
			$allowed = self::query_whitelist();
			$pairs   = array();
			$clean   = true;
			foreach ( $args as $name => $value ) {
				if ( ! in_array( (string) $name, $allowed, true ) || ! self::query_value_ok( (string) $name, $value ) ) {
					$clean = false;
					break;
				}
				$pairs[ (string) $name ] = $value;
			}
			if ( $clean && $pairs ) {
				ksort( $pairs );
				$out = array();
				foreach ( $pairs as $k => $v ) {
					$out[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
				}
				$query = '?' . implode( '&', $out );
			}
		}
		return array( 'https://' . $host . $path . $query, 'http://' . $host . $path . $query );
	}

	/**
	 * Purge one URL. The work behind purge_url() and purge_urls().
	 *
	 * Each key also gets a purge mark (`{hash}.purged`, holding the time),
	 * so a render of that URL that began before this purge and finishes
	 * after it is never stored.
	 *
	 * @param mixed $url    URL or path on this site.
	 * @param bool  $notify Fire `perdita_cache_purged_url`. The preloader
	 *                      passes false for a page it is about to rebuild
	 *                      anyway.
	 * @return int Number of cached copies removed or marked stale.
	 */
	public static function invalidate_url( $url, $notify = true ) {
		$keys = self::keys_for_url( $url );
		if ( ! $keys ) {
			return 0;
		}
		$settings = self::settings();
		$dir      = self::cache_dir();
		$real_dir = realpath( $dir );
		$now      = microtime( true );
		$count    = 0;
		foreach ( $keys as $key ) {
			$hash                         = sha1( $key );
			self::$purged_url_at[ $hash ] = $now;
			if ( false === $real_dir ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a purge mark inside our own cache dir.
			@file_put_contents( $dir . $hash . '.purged', sprintf( '%.6F', $now ), LOCK_EX );
			$files = array( $dir . $hash . '.html', $dir . $hash . self::variant_suffix( 'mobile' ) . '.html' );
			// Copies under a variant another plugin chose. Their names can't be
			// rebuilt here, so they are found by the URL's hash, and only when
			// some plugin actually sets variants.
			if ( has_filter( 'perdita_cache_key_variant' ) ) {
				$files = array_merge( $files, (array) glob( $dir . $hash . '-*.html' ) );
			}
			foreach ( array_unique( $files ) as $file ) {
				// realpath() confirms the target sits inside the cache dir before
				// anything is touched, exactly as purge_everything() does.
				$real = is_file( $file ) ? realpath( $file ) : false;
				if ( false === $real || 0 !== strpos( $real, trailingslashit( $real_dir ) ) ) {
					continue;
				}
				if ( ! empty( $settings['serve_stale'] ) ) {
					$count += self::mark_stale( $real );
				} else {
					$count += self::delete_entry( $real );
				}
			}
		}

		if ( $notify ) {
			/**
			 * Fires after one URL was purged from the page cache.
			 *
			 * @param string $url   The URL as it was passed in.
			 * @param int    $count Cached copies removed or marked stale.
			 */
			do_action( 'perdita_cache_purged_url', $url, $count );
		}
		return $count;
	}

	/**
	 * Whether a full purge, or a purge of this key, happened after $since.
	 * A render that began before a purge may hold what the purge was meant
	 * to remove, so it is not stored. Both this request's own purges and
	 * the marks other requests left on disk count.
	 *
	 * @param string $key   Cache key from request_key().
	 * @param float  $since microtime( true ) when the render began. 0 when
	 *                      unknown, which answers false.
	 * @return bool
	 */
	public static function purged_since( $key, $since ) {
		$since = (float) $since;
		if ( $since <= 0 ) {
			return false;
		}
		$dir = self::cache_dir();
		$all = max( self::$purged_all_at, self::read_stamp( $dir . self::PURGE_STAMP ) );
		if ( $all >= $since ) {
			return true;
		}
		$hash = sha1( (string) $key );
		$one  = max( isset( self::$purged_url_at[ $hash ] ) ? self::$purged_url_at[ $hash ] : 0.0, self::read_stamp( $dir . $hash . '.purged' ) );
		return $one >= $since;
	}

	/**
	 * The time in a purge stamp, or 0.
	 *
	 * @param string $file Stamp file.
	 * @return float
	 */
	private static function read_stamp( $file ) {
		if ( ! is_file( $file ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a short stamp in our own cache dir.
		$raw = @file_get_contents( $file, false, null, 0, 32 );
		return is_string( $raw ) && is_numeric( trim( $raw ) ) ? (float) trim( $raw ) : 0.0;
	}

	/**
	 * Mark a cache file stale, so it is rebuilt on the next request and
	 * served in the meantime only while that rebuild runs. The mark holds
	 * the time of the first purge (the hard cap counts from there), and its
	 * mtime the time of the latest.
	 *
	 * @param string $file Cache file, already checked to sit in the cache dir.
	 * @return int 1 when marked, else 0.
	 */
	private static function mark_stale( $file ) {
		$mark  = $file . '.stale';
		$first = self::stale_since( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a ten-byte mark beside our own cache file.
		return false !== @file_put_contents( $mark, (string) ( $first > 0 ? $first : time() ), LOCK_EX ) ? 1 : 0;
	}

	/**
	 * Delete one cached page and everything stored beside it (headers, gzip
	 * copy, stale mark, rebuild lock).
	 *
	 * @param string $file The page's .html file. The caller has already
	 *                     built it from a hash or checked it with realpath().
	 * @return int 1 when the page file was removed, else 0.
	 */
	public static function delete_entry( $file ) {
		$file     = (string) $file;
		$real_dir = realpath( self::cache_dir() );
		$parent   = realpath( dirname( $file ) );
		// Only a page file that sits directly in the cache directory.
		if ( '.html' !== substr( $file, -5 ) || false === $real_dir || $parent !== $real_dir ) {
			return 0;
		}
		$removed = 0;
		if ( is_file( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value drives the removed-count; wp_delete_file() returns nothing.
			$removed = 1;
		}
		foreach ( array( '.json', '.gz', '.stale', '.lock' ) as $ext ) {
			if ( is_file( $file . $ext ) ) {
				wp_delete_file( $file . $ext );
			}
		}
		return $removed;
	}

	/**
	 * Delete every cache file. Kept as an instance method because it is the
	 * callback on the purge hooks. purge_everything() does the work.
	 *
	 * @return int Number of files removed.
	 */
	public function purge_all() {
		return self::purge_everything();
	}

	/**
	 * Delete every cache file, at once, whatever "serve stale" says: a
	 * theme, plugin or settings change can alter every page, so no old copy
	 * may outlive it. Safe: it only unlinks this module's own artifacts that
	 * resolve, via realpath, to inside the cache directory. It never removes
	 * the directory itself, its guard files or the debug log.
	 *
	 * The purge stamp goes down first, so a page that began rendering before
	 * this purge and finishes after it is never stored (see purged_since()).
	 *
	 * For other plugins and Perdita Pro.
	 *
	 * @return int Number of pages removed.
	 */
	public static function purge_everything() {
		$dir      = self::cache_dir();
		$now      = microtime( true );
		$removed  = 0;
		self::$purged_all_at = $now;
		self::$purged_url_at = array();
		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a purge stamp inside our own cache dir.
			@file_put_contents( $dir . self::PURGE_STAMP, sprintf( '%.6F', $now ), LOCK_EX );
		}
		$real_dir = realpath( $dir );

		if ( false !== $real_dir && is_dir( $real_dir ) ) {
			$real_dir = trailingslashit( $real_dir );
			foreach ( (array) glob( $real_dir . '*' ) as $file ) {
				// Only ever touch our own artifacts: pages, what is stored
				// beside them (headers, gzip copy, stale mark, rebuild lock),
				// purge marks the stamp now covers, and half-written temp
				// files. That leaves index.php, .htaccess, the early-serve
				// switch, the purge stamp and the debug log alone.
				if ( ! preg_match( '/\.(html|tmp|purged|html\.(?:json|gz|stale|lock))$/', $file ) || ! is_file( $file ) ) {
					continue;
				}
				$real = realpath( $file );
				if ( false === $real || 0 !== strpos( $real, $real_dir ) ) {
					continue;
				}
				if ( @unlink( $real ) && preg_match( '/\.html$/', $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the return value drives the removed-count; wp_delete_file() returns nothing.
					++$removed;
				}
			}
			self::write_usage( 0 );
		}

		/**
		 * Fires after the page cache is cleared. Lets other layers (an object
		 * cache, a CDN client) piggyback on Perdita's purge. The older name
		 * for `perdita_cache_purged_all`, kept for code already hooked to it.
		 *
		 * @param int $removed Number of files removed.
		 */
		do_action( 'perdita_caching_purged', $removed );

		/**
		 * Fires after a full purge of the page cache.
		 *
		 * @param int $removed Number of files removed.
		 */
		do_action( 'perdita_cache_purged_all', $removed );

		self::log( 'PURGE', 'everything (' . $removed . ')' );
		return $removed;
	}

	/* ---------- size cap ---------- */

	/**
	 * The most bytes of pages the cache directory may hold, or 0 for no cap.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$settings = self::settings();
		/**
		 * The cache directory's size cap in bytes. 0 turns the cap off.
		 *
		 * @param int $bytes Default: the "Size cap" setting (1 GB).
		 */
		return max( 0, (int) apply_filters( 'perdita_cache_max_bytes', (int) $settings['max_size_mb'] * MB_IN_BYTES ) );
	}

	/**
	 * The running total of stored bytes, as the last store or garbage
	 * collection left it.
	 *
	 * @return int
	 */
	public static function usage() {
		return (int) self::read_stamp( self::cache_dir() . self::USAGE_FILE );
	}

	/**
	 * Save the running total.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function write_usage( $bytes ) {
		$dir = self::cache_dir();
		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a byte count inside our own cache dir.
			@file_put_contents( $dir . self::USAGE_FILE, (string) max( 0, (int) $bytes ), LOCK_EX );
		}
	}

	/**
	 * Add to the running total.
	 *
	 * @param int $bytes Bytes (negative to take away).
	 */
	private static function add_usage( $bytes ) {
		if ( 0 !== (int) $bytes ) {
			self::write_usage( self::usage() + (int) $bytes );
		}
	}

	/**
	 * Bytes one cached page takes: the page and its gzip copy.
	 *
	 * @param string $file The page's .html file.
	 * @return int
	 */
	public static function entry_bytes( $file ) {
		clearstatcache( true, $file );
		clearstatcache( true, $file . '.gz' );
		return ( is_file( $file ) ? (int) filesize( $file ) : 0 ) + ( is_file( $file . '.gz' ) ? (int) filesize( $file . '.gz' ) : 0 );
	}

	/**
	 * Whether a page of this size fits under the cap. One small file read,
	 * so every store can ask. When the cache is full, garbage collection
	 * runs within a minute and trims the oldest pages.
	 *
	 * @param int $bytes Size of the page about to be stored.
	 * @return bool
	 */
	private static function has_room( $bytes ) {
		$max = self::max_bytes();
		if ( 0 === $max || self::usage() + (int) $bytes <= $max ) {
			return true;
		}
		if ( ! wp_next_scheduled( self::GC_HOOK . '_soon' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::GC_HOOK . '_soon' );
		}
		return false;
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
	 * Ensure the cache directory exists with its guard files, so it cannot
	 * be browsed or fetched from directly.
	 *
	 * @param string $dir Directory path (trailing slash).
	 * @return bool Whether the dir exists and is writable.
	 */
	private static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
		}
		self::write_guards( $dir );
		return wp_is_writable( $dir );
	}

	/**
	 * Write the directory's guard files when they are missing: an index.php
	 * against directory listings, and an .htaccess that refuses every direct
	 * request on Apache and LiteSpeed. Nothing in this directory is meant to
	 * be fetched by URL. PHP reads the files and sends them.
	 *
	 * @param string $dir Directory path (trailing slash).
	 */
	private static function write_guards( $dir ) {
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing a static guard file.
			@file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = array(
				'# Perdita Cache: nothing in this directory is served directly.',
				'<IfModule mod_authz_core.c>',
				'	Require all denied',
				'</IfModule>',
				'<IfModule !mod_authz_core.c>',
				'	Order deny,allow',
				'	Deny from all',
				'</IfModule>',
				'',
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing a static guard file.
			@file_put_contents( $htaccess, implode( "\n", $rules ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Create the cache directory and its guard files. Runs on module
	 * activation. Static so the descriptor can call it without an instance.
	 */
	public static function install() {
		$dir = self::cache_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			self::write_guards( $dir );
		}
	}

	/* ---------- background work: garbage collection, preload, CDN, log ---------- */

	/**
	 * Delete expired cache files. The cron callback, and what the "Delete
	 * expired" button and `wp perdita cache gc` run.
	 *
	 * @return int Number of pages removed.
	 */
	public static function run_gc() {
		self::load( 'tools' );
		return Perdita_Caching_Tools::gc();
	}

	/**
	 * Cron callback: build the next few pages of a preload.
	 */
	public static function run_preload_batch() {
		self::load( 'preload' );
		Perdita_Caching_Preload::run_batch();
	}

	/**
	 * Cron callback: start a preload. Scheduled after a full purge and by
	 * the refresh interval.
	 *
	 * @param string $mode fill (build what is missing) or refresh (rebuild everything).
	 */
	public static function run_preload_start( $mode = 'fill' ) {
		self::load( 'preload' );
		Perdita_Caching_Preload::start( 'refresh' === $mode ? 'refresh' : 'fill' );
	}

	/**
	 * After a single URL was purged: put it at the front of the preload
	 * queue, when preloading is on.
	 *
	 * @param string $url Purged URL.
	 */
	public static function queue_preload_url( $url ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['preload'] ) ) {
			return;
		}
		self::load( 'preload' );
		Perdita_Caching_Preload::queue_url( $url );
	}

	/**
	 * After a full purge: build everything again, when preloading is on.
	 * One request can purge many times (every Perdita option write does),
	 * so the restart is booked once, at shutdown.
	 */
	public static function queue_preload_all() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['preload'] ) ) {
			return;
		}
		if ( ! has_action( 'shutdown', array( __CLASS__, 'restart_preload' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'restart_preload' ) );
		}
	}

	/**
	 * Shutdown callback for queue_preload_all().
	 */
	public static function restart_preload() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['preload'] ) ) {
			return;
		}
		self::load( 'preload' );
		Perdita_Caching_Preload::restart_soon();
	}

	/**
	 * Bring the cron schedule in line with the settings: the garbage
	 * collector's recurring event and the preload refresh. Runs on
	 * admin_init and on save.
	 */
	public static function sync_schedules() {
		$settings = self::settings();
		$on       = ! empty( $settings['enabled'] ) && self::module_is_on();
		$want     = $on ? $settings['gc_schedule'] : 'off';
		$have     = wp_get_scheduled_event( self::GC_HOOK );
		if ( 'off' === $want ) {
			if ( $have ) {
				wp_unschedule_hook( self::GC_HOOK );
			}
		} elseif ( ! $have || $have->schedule !== $want ) {
			wp_clear_scheduled_hook( self::GC_HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $want, self::GC_HOOK );
		}

		$preload = $on && ! empty( $settings['preload'] );
		if ( ! $preload ) {
			if ( wp_next_scheduled( 'perdita_cache_preload_batch' ) || wp_next_scheduled( 'perdita_cache_preload_start' ) || wp_next_scheduled( 'perdita_cache_preload_start', array( 'refresh' ) ) ) {
				self::clear_preload_schedules();
			}
			return;
		}
		$hours = (int) $settings['preload_interval_hours'];
		$next  = wp_next_scheduled( 'perdita_cache_preload_start', array( 'refresh' ) );
		if ( $hours <= 0 && $next ) {
			wp_clear_scheduled_hook( 'perdita_cache_preload_start', array( 'refresh' ) );
		} elseif ( $hours > 0 && ( ! $next || $next > time() + $hours * HOUR_IN_SECONDS ) ) {
			wp_clear_scheduled_hook( 'perdita_cache_preload_start', array( 'refresh' ) );
			wp_schedule_single_event( time() + $hours * HOUR_IN_SECONDS, 'perdita_cache_preload_start', array( 'refresh' ) );
		}
	}

	/**
	 * Unschedule every preload event.
	 */
	public static function clear_preload_schedules() {
		// Every event of each hook, whatever its arguments.
		wp_unschedule_hook( 'perdita_cache_preload_batch' );
		wp_unschedule_hook( 'perdita_cache_preload_start' );
	}

	/**
	 * Open the CDN rewrite buffer on a front-end page, when a CDN host is set.
	 */
	public static function start_cdn_buffer() {
		$settings = self::settings();
		if ( empty( $settings['cdn_enabled'] ) || '' === $settings['cdn_host'] ) {
			return;
		}
		self::load( 'cdn' );
		Perdita_Caching_CDN::start( $settings );
	}

	/**
	 * This request's path, for a log line. Never the query string, which can
	 * carry a visitor's own values.
	 *
	 * @return string
	 */
	private static function log_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to a path and sanitized on the next line.
		return sanitize_text_field( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Absolute path of the debug log. The name carries a keyed hash so it
	 * can't be guessed, and ends in .php with a first line that exits, so a
	 * server that ignores .htaccess still never shows its contents.
	 *
	 * @return string
	 */
	public static function log_path() {
		return self::cache_dir() . 'debug-' . substr( hash_hmac( 'sha1', 'perdita-cache-debug-log', wp_salt( 'auth' ) ), 0, 16 ) . '.log.php';
	}

	/**
	 * Write one line to the debug log, when it is on.
	 *
	 * @param string $event  HIT, STALE, MISS, STORE, SKIP, PURGE, PRELOAD or GC.
	 * @param string $detail What it was about.
	 */
	public static function log( $event, $detail = '' ) {
		$settings = self::settings();
		if ( empty( $settings['debug_log'] ) ) {
			return;
		}
		self::load( 'tools' );
		Perdita_Caching_Tools::log( $event, $detail );
	}

	/* ---------- other page caches ---------- */

	/**
	 * The name of another page-cache plugin that is active, or ''.
	 *
	 * @return string
	 */
	public static function other_page_cache() {
		$known = array(
			'wp-super-cache/wp-cache.php'          => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'    => 'W3 Total Cache',
			'wp-rocket/wp-rocket.php'              => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php'  => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php'  => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'      => 'Cache Enabler',
			'wp-optimize/wp-optimize.php'          => 'WP-Optimize',
			'breeze/breeze.php'                    => 'Breeze',
			'sg-cachepress/sg-cachepress.php'      => 'Speed Optimizer',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $known as $basename => $label ) {
			if ( in_array( $basename, $active, true ) ) {
				return $label;
			}
		}
		return '';
	}

	/* ---------- early serve (wp-content/advanced-cache.php) ---------- */

	/**
	 * First line of every drop-in this module writes, so it never touches
	 * an advanced-cache.php that belongs to another plugin.
	 */
	const DROPIN_MARKER = 'Perdita Cache early-serve drop-in';

	/**
	 * Drop-in format version. Bump when dropin_source() changes, and the
	 * next admin request rewrites the file.
	 */
	const DROPIN_VERSION = '3';

	/**
	 * @return string Absolute path to wp-content/advanced-cache.php.
	 */
	public static function dropin_path() {
		return trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
	}

	/**
	 * What sits at advanced-cache.php: none, ours (current), stale (ours,
	 * older settings or format), or foreign (another plugin's).
	 *
	 * @return string
	 */
	public static function dropin_state() {
		$path = self::dropin_path();
		if ( ! is_file( $path ) ) {
			return 'none';
		}
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === strpos( $head, self::DROPIN_MARKER ) ) {
			return 'foreign';
		}
		return (string) file_get_contents( $path ) === self::dropin_source() ? 'ours' : 'stale'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/**
	 * Whether WordPress will load advanced-cache.php at all.
	 *
	 * @return bool
	 */
	public static function wp_cache_on() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * The drop-in's PHP. Everything it needs is written into it as plain
	 * values, because it runs before WordPress has a database connection.
	 *
	 * It answers only a request the in-WordPress cache would have answered
	 * for the same visitor: a GET with no form body, this site's own host,
	 * no login, post-password, comment or cart cookie, and no query argument
	 * outside the tracking list and the pagination whitelist. Everything
	 * page-related (404s, searches, feeds, previews, excluded paths, the
	 * perdita_cache_exclude filter) already decided whether a file exists,
	 * so a page that may not be cached never has one to find.
	 *
	 * @return string
	 */
	public static function dropin_source() {
		$settings = self::settings();
		$front    = self::dropin_filter_config();
		$config   = array(
			'dir'             => self::cache_dir(),
			'host'            => self::site_host(),
			'ttl'             => (int) $settings['ttl_hours'] * HOUR_IN_SECONDS,
			'ignore'          => $front['ignore'],
			'allow'           => $front['allow'],
			'numeric'         => self::numeric_query_args(),
			'bad_path'        => self::BAD_PATH_PATTERN,
			'cookies'         => array_values( array_unique( array_merge( $front['cookies'], $settings['reject_cookies'] ) ) ),
			'max_age'         => self::CLIENT_CACHE_MAX_AGE,
			'charset'         => (string) get_option( 'blog_charset', 'UTF-8' ),
			'reject_ua'       => array_values( $settings['reject_user_agents'] ),
			'mobile'          => ! empty( $settings['mobile_cache'] ),
			'mobile_ua'       => $front['mobile_ua'],
			'mobile_filtered' => $front['mobile_filtered'],
			'mobile_suffix'   => self::variant_suffix( 'mobile' ),
			'gzip'            => ! empty( $settings['gzip'] ),
			'stale'           => ! empty( $settings['serve_stale'] ),
			'stale_max'       => $front['stale_max'],
			'lock_ttl'        => self::LOCK_TTL,
			'log'             => ! empty( $settings['debug_log'] ) ? self::log_path() : '',
			'log_old'         => ! empty( $settings['debug_log'] ) ? substr( self::log_path(), 0, -8 ) . '.old.log.php' : '',
			'log_max'         => 1048576,
			'log_until'       => ! empty( $settings['debug_log'] ) ? (int) $settings['debug_log_until'] : 0,
		);
		// The settings go into the PHP source as a JSON string literal:
		// json_decode() needs nothing WordPress provides, and a
		// single-quoted literal only has to escape \\ and '.
		$json   = (string) wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$export = "json_decode( '" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $json ) . "', true )";
		$marker = self::DROPIN_MARKER;
		$ver    = self::DROPIN_VERSION;
		$template = array(
			'<?php',
			'/**',
			' * %%PERDITA_MARKER%% v%%PERDITA_VER%%.',
			' *',
			' * Perdita Core wrote this file and rewrites or removes it from the Caching',
			' * screen. Edits here are lost. It serves a page Perdita already cached before',
			' * WordPress loads, and hands every other request to WordPress unchanged.',
			' */',
			'',
			'defined( \'ABSPATH\' ) || exit;',
			'',
			'( function () {',
			'	$c = %%PERDITA_CONFIG%%;',
			'	if ( ( defined( \'WP_CLI\' ) && WP_CLI ) || ! is_file( $c[\'dir\'] . \'early-serve.on\' ) ) {',
			'		return;',
			'	}',
			'	if ( \'GET\' !== strtoupper( (string) ( $_SERVER[\'REQUEST_METHOD\'] ?? \'GET\' ) ) || ! empty( $_POST ) ) {',
			'		return;',
			'	}',
			'	$host = strtolower( (string) preg_replace( \'/:\\d+$/\', \'\', (string) ( $_SERVER[\'HTTP_HOST\'] ?? \'\' ) ) );',
			'	if ( $host !== $c[\'host\'] ) {',
			'		return;',
			'	}',
			'	foreach ( array_keys( $_COOKIE ) as $name ) {',
			'		foreach ( $c[\'cookies\'] as $needle ) {',
			'			if ( false !== stripos( (string) $name, $needle ) ) {',
			'				return;',
			'			}',
			'		}',
			'	}',
			'	$pairs = array();',
			'	foreach ( $_GET as $k => $v ) {',
			'		if ( in_array( (string) $k, $c[\'ignore\'], true ) ) {',
			'			continue;',
			'		}',
			'		if ( ! in_array( (string) $k, $c[\'allow\'], true ) || ! is_string( $v ) ) {',
			'			return;',
			'		}',
			'		if ( in_array( (string) $k, $c[\'numeric\'], true ) ? ( ! preg_match( \'/^[1-9][0-9]{0,8}$/\', $v ) || (int) $v < 2 ) : ! preg_match( \'/^[A-Za-z0-9_-]{1,64}$/\', $v ) ) {',
			'			return;',
			'		}',
			'		$pairs[ (string) $k ] = $v;',
			'	}',
			'	ksort( $pairs );',
			'	$query = array();',
			'	foreach ( $pairs as $k => $v ) {',
			'		$query[] = rawurlencode( $k ) . \'=\' . rawurlencode( $v );',
			'	}',
			'	$uri  = (string) ( $_SERVER[\'REQUEST_URI\'] ?? \'/\' );',
			'	$path = substr( $uri, 0, strcspn( $uri, \'?#\' ) );',
			'	$path = \'\' === $path ? \'/\' : $path;',
			'	if ( \'/\' !== $path[0] || preg_match( $c[\'bad_path\'], $path ) ) {',
			'		return;',
			'	}',
			'	$https = ( isset( $_SERVER[\'HTTPS\'] ) && ( \'on\' === strtolower( (string) $_SERVER[\'HTTPS\'] ) || \'1\' === (string) $_SERVER[\'HTTPS\'] ) ) || ( isset( $_SERVER[\'SERVER_PORT\'] ) && \'443\' === (string) $_SERVER[\'SERVER_PORT\'] );',
			'	$key  = ( $https ? \'https\' : \'http\' ) . \'://\' . $c[\'host\'] . $path . ( $query ? \'?\' . implode( \'&\', $query ) : \'\' );',
			'	$ua   = (string) ( $_SERVER[\'HTTP_USER_AGENT\'] ?? \'\' );',
			'	foreach ( $c[\'reject_ua\'] as $needle ) {',
			'		if ( \'\' !== $needle && false !== stripos( $ua, $needle ) ) {',
			'			return;',
			'		}',
			'	}',
			'	$suffix = \'\';',
			'	if ( $c[\'mobile\'] ) {',
			'		if ( $c[\'mobile_filtered\'] ) {',
			'			return;',
			'		}',
			'		if ( isset( $_SERVER[\'HTTP_SEC_CH_UA_MOBILE\'] ) ) {',
			'			$phone = \'?1\' === $_SERVER[\'HTTP_SEC_CH_UA_MOBILE\'];',
			'		} else {',
			'			$phone = false;',
			'			foreach ( $c[\'mobile_ua\'] as $needle ) {',
			'				if ( \'\' !== $needle && false !== strpos( $ua, $needle ) ) {',
			'					$phone = true;',
			'					break;',
			'				}',
			'			}',
			'		}',
			'		$suffix = $phone ? $c[\'mobile_suffix\'] : \'\';',
			'	}',
			'	$file = $c[\'dir\'] . sha1( $key ) . $suffix . \'.html\';',
			'	$mtime = (int) @filemtime( $file );',
			'	if ( $mtime <= 0 || headers_sent() ) {',
			'		return;',
			'	}',
			'	$now   = time();',
			'	$state = \'HIT\';',
			'	$mark  = is_file( $file . \'.stale\' );',
			'	if ( $mark || $now - $mtime > $c[\'ttl\'] ) {',
			'		if ( ! $c[\'stale\'] ) {',
			'			return;',
			'		}',
			'		$since = $mtime + $c[\'ttl\'];',
			'		if ( $mark ) {',
			'			$since = (int) @file_get_contents( $file . \'.stale\' );',
			'			$since = $since > 0 ? $since : (int) @filemtime( $file . \'.stale\' );',
			'		}',
			'		$lock = (int) @filemtime( $file . \'.lock\' );',
			'		if ( $now - $since > $c[\'stale_max\'] || $lock <= 0 || $now - $lock > $c[\'lock_ttl\'] ) {',
			'			return;',
			'		}',
			'		$state = \'STALE\';',
			'	}',
			'	$size = (int) @filesize( $file );',
			'	$send = $file;',
			'	$gzip = false;',
			'	if ( $c[\'gzip\'] ) {',
			'		$accept = (string) ( $_SERVER[\'HTTP_ACCEPT_ENCODING\'] ?? \'\' );',
			'		if ( false !== stripos( $accept, \'gzip\' ) && ! preg_match( \'/gzip\\s*;\\s*q=0(?:\\.0*)?\\s*(?:,|$)/i\', $accept )',
			'			&& in_array( strtolower( (string) ini_get( \'zlib.output_compression\' ) ), array( \'\', \'0\', \'off\' ), true )',
			'			&& ! array_diff( ob_list_handlers(), array( \'default output handler\' ) )',
			'			&& (int) @filemtime( $file . \'.gz\' ) >= $mtime ) {',
			'			$send = $file . \'.gz\';',
			'			$gzip = true;',
			'		}',
			'	}',
			'	$etag = \'"\' . $mtime . \'-\' . $size . ( $gzip ? \'-gz\' : \'\' ) . \'"\';',
			'	$sent = array();',
			'	$side = @file_get_contents( $file . \'.json\' );',
			'	foreach ( (array) json_decode( (string) $side, true ) as $line ) {',
			'		if ( is_string( $line ) && false === strpbrk( $line, "\\r\\n" ) ) {',
			'			$name = strtolower( trim( (string) strtok( $line, \':\' ) ) );',
			'			header( $line, \'link\' !== $name );',
			'			$sent[ $name ] = true;',
			'		}',
			'	}',
			'	if ( empty( $sent[\'content-type\'] ) ) {',
			'		header( \'Content-Type: text/html; charset=\' . $c[\'charset\'] );',
			'	}',
			'	header( \'X-Cache: \' . $state );',
			'	header( \'X-Perdita-Cache: early\' );',
			'	header( \'Cache-Control: max-age=\' . ( \'STALE\' === $state ? 0 : (int) $c[\'max_age\'] ) . \', public\' );',
			'	if ( $c[\'gzip\'] ) {',
			'		header( \'Vary: Accept-Encoding\', false );',
			'	}',
			'	if ( $c[\'mobile\'] ) {',
			'		header( \'Vary: User-Agent\', false );',
			'		header( \'Vary: Sec-CH-UA-Mobile\', false );',
			'	}',
			'	header( \'Age: \' . max( 0, $now - $mtime ) );',
			'	header( \'ETag: \' . $etag );',
			'	header( \'Last-Modified: \' . gmdate( \'D, d M Y H:i:s\', $mtime ) . \' GMT\' );',
			'	if ( \'\' !== $c[\'log\'] && $now < $c[\'log_until\'] && is_file( $c[\'log\'] ) ) {',
			'		if ( (int) @filesize( $c[\'log\'] ) > $c[\'log_max\'] ) {',
			'			@rename( $c[\'log\'], $c[\'log_old\'] );',
			'		}',
			'	}',
			'	if ( \'\' !== $c[\'log\'] && $now < $c[\'log_until\'] && is_file( $c[\'log\'] ) ) {',
			'		@file_put_contents( $c[\'log\'], \'[\' . gmdate( \'Y-m-d H:i:s\' ) . \'] \' . $state . \' early \' . str_replace( array( \'<?\', \'?>\' ), \'\', (string) preg_replace( \'/[^\\x20-\\x7E]/\', \'\', $path ) ) . "\\n", FILE_APPEND | LOCK_EX );',
			'	}',
			'	$inm = (string) ( $_SERVER[\'HTTP_IF_NONE_MATCH\'] ?? \'\' );',
			'	$ims = isset( $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) ? strtotime( (string) $_SERVER[\'HTTP_IF_MODIFIED_SINCE\'] ) : false;',
			'	if ( ( \'\' !== $inm && $inm === $etag ) || ( false !== $ims && $ims >= $mtime ) ) {',
			'		http_response_code( 304 );',
			'		exit;',
			'	}',
			'	if ( $gzip ) {',
			'		header( \'Content-Encoding: gzip\' );',
			'		header( \'Content-Length: \' . (int) @filesize( $send ) );',
			'	}',
			'	readfile( $send );',
			'	exit;',
			'} )();',
			'',
		);
		// A plain list of lines, not a heredoc: the wordpress.org Plugin Check
		// refuses heredoc syntax.
		return strtr(
			implode( "\n", $template ),
			array(
				'%%PERDITA_MARKER%%' => $marker,
				'%%PERDITA_VER%%'    => $ver,
				'%%PERDITA_CONFIG%%' => $export,
			)
		);
	}

	/**
	 * Cookie name fragments that keep a visitor out of the cache. One list
	 * for has_no_cache_cookie() and the drop-in, so the two can't disagree.
	 *
	 * @return string[]
	 */
	public static function no_cache_cookies() {
		/**
		 * Cookie name fragments (matched case-insensitively anywhere in the
		 * name) that mark a personalized visitor the page cache must skip.
		 *
		 * @param string[] $needles Fragments.
		 */
		$settings = self::settings();
		// The site owner's own list, from the Caching screen, on top.
		return array_values( array_unique( array_merge( self::no_cache_cookie_base(), $settings['reject_cookies'] ) ) );
	}

	/**
	 * The filtered half of no_cache_cookies(), without the site owner's list.
	 *
	 * @return string[]
	 */
	public static function no_cache_cookie_base() {
		return array_values(
			array_map(
			'strval',
			(array) apply_filters(
				'perdita_cache_no_cache_cookies',
				array(
					'wordpress_logged_in',
					// A visitor who entered a post password sees that post (and any
					// archive listing it) unlocked, so nothing they load is safe to
					// store or to serve them from the shared cache.
					'wp-postpass_',
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
				)
			)
			)
		);
	}

	/**
	 * The parts of the drop-in's settings that come from filters, worked
	 * out here: the tracking args it ignores, the query whitelist, the
	 * rejected cookies, the phone list, whether anything filters
	 * wp_is_mobile(), and the stale cap.
	 *
	 * @return array
	 */
	public static function dropin_filter_values() {
		return array(
			'ignore'          => array_values( self::ignored_query_args() ),
			'allow'           => self::query_whitelist(),
			'cookies'         => self::no_cache_cookie_base(),
			'mobile_ua'       => self::mobile_user_agents(),
			'mobile_filtered' => (bool) has_filter( 'wp_is_mobile' ),
			'stale_max'       => self::stale_max_age(),
		);
	}

	/**
	 * The filter values the drop-in is written with.
	 *
	 * Plugins and themes often add their filters only on the front end, and
	 * the drop-in is written from an admin request. So the front end checks
	 * the values it sees against the ones the drop-in has (see
	 * check_dropin_config()), saves its own when they differ, and those are
	 * the ones the next drop-in gets. Until then the admin request's own
	 * values are used.
	 *
	 * @return array
	 */
	public static function dropin_filter_config() {
		$values = self::dropin_filter_values();
		$file   = self::cache_dir() . self::FRONT_CONFIG_FILE;
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a small JSON file in our own cache dir.
			$saved = is_file( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
			if ( is_array( $saved ) && ! array_diff_key( $values, $saved ) ) {
				$values = array_intersect_key( $saved, $values );
			}
		}
		$values['ignore']          = array_values( array_map( 'strval', (array) $values['ignore'] ) );
		$values['allow']           = array_values( array_map( 'strval', (array) $values['allow'] ) );
		$values['cookies']         = array_values( array_map( 'strval', (array) $values['cookies'] ) );
		$values['mobile_ua']       = array_values( array_map( 'strval', (array) $values['mobile_ua'] ) );
		$values['mobile_filtered'] = (bool) $values['mobile_filtered'];
		$values['stale_max']       = max( 0, (int) $values['stale_max'] );
		return $values;
	}

	/**
	 * A short fingerprint of a set of drop-in filter values.
	 *
	 * @param array $values From dropin_filter_values().
	 * @return string
	 */
	public static function dropin_fingerprint( $values ) {
		$values['ignore']          = array_values( array_map( 'strval', (array) $values['ignore'] ) );
		$values['allow']           = array_values( array_map( 'strval', (array) $values['allow'] ) );
		$values['cookies']         = array_values( array_map( 'strval', (array) $values['cookies'] ) );
		$values['mobile_ua']       = array_values( array_map( 'strval', (array) $values['mobile_ua'] ) );
		$values['mobile_filtered'] = (bool) $values['mobile_filtered'];
		$values['stale_max']       = max( 0, (int) $values['stale_max'] );
		ksort( $values );
		return md5( (string) wp_json_encode( $values ) );
	}

	/**
	 * On a front-end request with early serving on: when this request's
	 * filter values differ from the ones the drop-in was written with (the
	 * switch file holds their fingerprint), save this request's values and
	 * turn early serving off. The next admin request writes a drop-in with
	 * them and turns it back on. In the meantime WordPress serves every
	 * page, with the values it sees itself.
	 *
	 * @return bool Whether the drop-in matches.
	 */
	public static function check_dropin_config() {
		$flag = self::cache_dir() . 'early-serve.on';
		if ( ! is_file( $flag ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the switch file in our own cache dir.
		$have   = trim( (string) @file_get_contents( $flag, false, null, 0, 64 ) );
		$values = self::dropin_filter_values();
		if ( hash_equals( self::dropin_fingerprint( $values ), $have ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a small JSON file in our own cache dir.
		@file_put_contents( self::cache_dir() . self::FRONT_CONFIG_FILE, (string) wp_json_encode( $values ), LOCK_EX );
		wp_delete_file( $flag );
		self::log( 'EARLY', 'off until the next admin request: the front end sees different filter values' );
		return false;
	}

	/**
	 * Whether this is a multisite network. Every site on a network shares
	 * one wp-content/advanced-cache.php, and the drop-in knows one site's
	 * settings, so early serving stays off there.
	 *
	 * @return bool
	 */
	public static function is_network() {
		/**
		 * Whether to treat this install as a multisite network.
		 *
		 * @param bool $network is_multisite().
		 */
		return (bool) apply_filters( 'perdita_cache_is_multisite', is_multisite() );
	}

	/**
	 * Bring advanced-cache.php in line with the settings, and say how it went.
	 *
	 * Writes the drop-in when the page cache and early serving are both on,
	 * no other page-cache plugin is active, WP_CACHE is true and the file is
	 * free or already Perdita's. Removes Perdita's drop-in in every other
	 * case. Never touches another plugin's file, and never wp-config.php.
	 *
	 * @return string installed, current, removed, off, multisite, other_cache, foreign, no_wp_cache or unwritable.
	 */
	public static function sync_dropin() {
		$settings = self::settings();
		$want     = ! empty( $settings['enabled'] ) && ! empty( $settings['early_serve'] ) && self::module_is_on();
		$state    = self::dropin_state();
		$flag     = self::cache_dir() . 'early-serve.on';

		$network  = self::is_network();
		if ( ! $want || $network || '' !== self::other_page_cache() || ! self::wp_cache_on() ) {
			$why = ! $want ? 'off' : ( $network ? 'multisite' : ( '' !== self::other_page_cache() ? 'other_cache' : 'no_wp_cache' ) );
			if ( is_file( $flag ) ) {
				wp_delete_file( $flag );
			}
			if ( in_array( $state, array( 'ours', 'stale' ), true ) ) {
				wp_delete_file( self::dropin_path() );
				return is_file( self::dropin_path() ) ? 'unwritable' : ( 'off' === $why ? 'removed' : $why );
			}
			return $why;
		}
		if ( 'foreign' === $state ) {
			if ( is_file( $flag ) ) {
				wp_delete_file( $flag );
			}
			return 'foreign';
		}
		if ( 'ours' !== $state ) {
			$path = self::dropin_path();
			$tmp  = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the drop-in has to be a real file WordPress includes before it loads.
			if ( false === @file_put_contents( $tmp, self::dropin_source(), LOCK_EX ) || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap so a request never includes half a file.
				if ( is_file( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return 'unwritable';
			}
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache may be restricted.
			}
		}
		self::install();
		// The switch holds the fingerprint of the filter values the drop-in
		// was written with, so the front end can tell when they differ.
		$print = self::dropin_fingerprint( self::dropin_filter_config() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the switch file in our own cache dir.
		if ( ! is_file( $flag ) || trim( (string) @file_get_contents( $flag ) ) !== $print ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the switch the drop-in checks before serving anything.
			@file_put_contents( $flag, $print, LOCK_EX );
		}
		return 'ours' === $state ? 'current' : 'installed';
	}

	/**
	 * Re-sync once the plugin list has changed, at the end of the request
	 * that changed it.
	 */
	public static function sync_dropin_later() {
		if ( ! has_action( 'shutdown', array( __CLASS__, 'sync_dropin' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'sync_dropin' ) );
		}
	}

	/**
	 * Remove Perdita's drop-in and its switch, whatever the settings say.
	 * Runs when the module or the plugin goes off, so nothing keeps serving
	 * pages no one is purging any more.
	 */
	public static function remove_dropin() {
		$flag = self::cache_dir() . 'early-serve.on';
		if ( is_file( $flag ) ) {
			wp_delete_file( $flag );
		}
		if ( in_array( self::dropin_state(), array( 'ours', 'stale' ), true ) ) {
			wp_delete_file( self::dropin_path() );
		}
		// The module's cron events go with it: nothing should preload or
		// sweep a cache nobody serves.
		wp_unschedule_hook( self::GC_HOOK );
		wp_unschedule_hook( self::GC_HOOK . '_soon' );
		self::clear_preload_schedules();
	}

	/**
	 * Whether the caching module is switched on in Perdita's module list.
	 *
	 * @return bool
	 */
	private static function module_is_on() {
		if ( function_exists( 'perdita_core' ) && isset( perdita_core()->modules ) && method_exists( perdita_core()->modules, 'is_enabled' ) ) {
			return (bool) perdita_core()->modules->is_enabled( 'caching' );
		}
		return true;
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
