<?php
/**
 * Caching: LiteSpeed and OpenLiteSpeed.
 *
 * LiteSpeed's cache sits in the web server, in front of PHP. With this on,
 * every page Perdita stores also tells LiteSpeed it may keep it, and every
 * Perdita purge tells LiteSpeed to drop it. The headers and the .htaccess
 * lines come from LiteSpeed's own docs:
 *
 * - X-LiteSpeed-Cache-Control: public,max-age=N, or no-cache
 *   (docs.litespeedtech.com/lscache/devguide/controls/).
 * - X-LiteSpeed-Tag and X-LiteSpeed-Purge: public, tag=... (same page).
 * - CacheLookup in .htaccess and the rewrite env Cache-Control:no-cache
 *   (docs.litespeedtech.com/lscache/noplugin/settings/).
 *
 * The headers do nothing unless the server's cache module is on. The
 * .htaccess block keeps every visitor with a personal cookie out of
 * LiteSpeed's copy, and Perdita turns the feature on only after a live
 * check proves it: a second anonymous request is a LiteSpeed hit, and a
 * request with a logged-in cookie is not. A purge rides on the response
 * of the request that made it. From WP-CLI, which has no response, it
 * waits in a flag file for the next request through the server.
 *
 * Perdita stands down entirely while the LiteSpeed Cache plugin is active
 * (see Perdita_Caching::other_page_cache()), so the two never fight.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_LiteSpeed {

	/** The .htaccess block's marker. */
	const MARKER = 'Perdita LiteSpeed';

	/** Runtime state: the live check's result. */
	const STATE_OPTION = 'perdita_cache_litespeed';

	/** A purge waiting for a request with a response. */
	const PENDING_FILE = 'litespeed-purge.pending';

	/** @var string[] Tags to purge with this request's response. */
	private static $purge = array();

	/** @var bool The response hook is in. */
	private static $hooked = false;

	/**
	 * Headers for a page Perdita stored, or for any other response.
	 *
	 * @param array $settings Settings.
	 * @param bool  $store    Fresh stored page.
	 */
	public static function send_store_headers( $settings, $store ) {
		if ( ! $store ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			return;
		}
		header( 'X-LiteSpeed-Cache-Control: public,max-age=' . ( (int) $settings['ttl_hours'] * HOUR_IN_SECONDS ) );
		header( 'X-LiteSpeed-Tag: ' . self::page_tags( self::request_path() ) );
	}

	/**
	 * The request's path, the way the drop-in reads it.
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed into a tag, never printed.
		$path = substr( $uri, 0, strcspn( $uri, '?#' ) );
		return '' === $path ? '/' : $path;
	}

	/**
	 * The tags for one page: the site's, and the page's own.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function page_tags( $path ) {
		$site = Perdita_Caching::litespeed_site_tag();
		return $site . ',' . self::page_tag( $path );
	}

	/**
	 * One page's tag. The drop-in builds the same string.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function page_tag( $path ) {
		return Perdita_Caching::litespeed_site_tag() . '_' . substr( md5( Perdita_Caching::site_host() . $path ), 0, 12 );
	}

	/**
	 * `perdita_cache_purged_all`.
	 */
	public static function purge_all() {
		self::queue( Perdita_Caching::litespeed_site_tag() );
	}

	/**
	 * `perdita_cache_purged_url`.
	 *
	 * @param mixed $url URL.
	 */
	public static function purge_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		self::queue( self::page_tag( '' === $path ? '/' : $path ) );
	}

	/**
	 * Queue a tag for this request's response, or for the next one.
	 *
	 * @param string $tag Tag.
	 */
	private static function queue( $tag ) {
		self::$purge[ $tag ] = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
			// A REST or admin response may be sent before shutdown.
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'flush_rest' ), 99 );
		}
	}

	/**
	 * The header value for a set of tags.
	 *
	 * @param string[] $tags Tags.
	 * @return string
	 */
	public static function purge_header( array $tags ) {
		$site = Perdita_Caching::litespeed_site_tag();
		if ( in_array( $site, $tags, true ) ) {
			$tags = array( $site );
		}
		return 'public, ' . implode( ', ', array_map( static function ( $t ) {
			return 'tag=' . $t;
		}, $tags ) );
	}

	/**
	 * `rest_post_dispatch`: send queued purges on the REST response.
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function flush_rest( $response ) {
		self::flush();
		return $response;
	}

	/**
	 * Send the queued purge, or keep it for the next request when this one
	 * has no response to carry it.
	 */
	public static function flush() {
		if ( ! self::$purge ) {
			return;
		}
		$tags        = array_keys( self::$purge );
		self::$purge = array();
		// WP-CLI has no response, and wp-cron.php may have closed its
		// response before events run.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || headers_sent() ) {
			self::save_pending( $tags );
			return;
		}
		header( 'X-LiteSpeed-Purge: ' . self::purge_header( $tags ), false );
	}

	/**
	 * Keep tags for the next request, and ask for one.
	 *
	 * @param string[] $tags Tags.
	 */
	private static function save_pending( array $tags ) {
		$file = Perdita_Caching::cache_dir() . self::PENDING_FILE;
		$old  = is_file( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own small file.
		$all  = array_values( array_unique( array_merge( array_map( 'strval', $old ), $tags ) ) );
		if ( count( $all ) > 200 ) {
			$all = array( Perdita_Caching::litespeed_site_tag() );
		}
		file_put_contents( $file, (string) wp_json_encode( $all ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own small file.
		wp_remote_get(
			add_query_arg( 'perdita_ls_purge', '1', home_url( '/' ) ),
			array(
				'blocking'  => false,
				'timeout'   => 0.5,
				'sslverify' => false,
			)
		);
	}

	/**
	 * On a request through the server: send a purge that waited.
	 */
	public static function send_pending() {
		$file = Perdita_Caching::cache_dir() . self::PENDING_FILE;
		if ( headers_sent() || ! is_file( $file ) ) {
			return;
		}
		$tags = array_filter( array_map( 'strval', (array) json_decode( (string) file_get_contents( $file ), true ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own small file.
		wp_delete_file( $file );
		if ( $tags ) {
			header( 'X-LiteSpeed-Purge: ' . self::purge_header( $tags ), false );
		}
	}

	/* ---------- .htaccess ---------- */

	/**
	 * The .htaccess block: look pages up in LiteSpeed's cache, and never
	 * for a visitor with a personal cookie.
	 *
	 * @return string[]
	 */
	public static function htaccess_rules() {
		$cookies = array();
		foreach ( array_merge( array( 'wordpress_logged_in', 'wordpress_sec_', 'wp-postpass_', 'comment_author' ), Perdita_Caching::no_cache_cookies() ) as $fragment ) {
			$fragment = trim( (string) $fragment );
			if ( '' !== $fragment && preg_match( '/^[A-Za-z0-9_.-]+$/', $fragment ) ) {
				$cookies[ preg_quote( $fragment, '/' ) ] = true;
			}
		}
		return array(
			'<IfModule LiteSpeed>',
			'CacheLookup on',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_COOKIE} (' . implode( '|', array_keys( $cookies ) ) . ') [NC]',
			'RewriteRule .* - [E=Cache-Control:no-cache]',
			'RewriteCond %{REQUEST_URI} ^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php) [NC,OR]',
			'RewriteCond %{QUERY_STRING} .',
			'RewriteRule .* - [E=Cache-Control:no-cache]',
			'</IfModule>',
		);
	}

	/**
	 * Write or remove the block. The block goes above WordPress's own
	 * rules, since their [L] flag ends rewrite processing for every
	 * permalink and the cookie checks below it would never run. LiteSpeed
	 * Cache places its block the same way. Every other block stays as it was.
	 *
	 * @param bool $on Write it, or remove it.
	 * @return bool
	 */
	public static function sync_htaccess( $on ) {
		$file = trailingslashit( ABSPATH ) . '.htaccess';
		if ( ! $on && ( ! is_file( $file ) || false === strpos( (string) file_get_contents( $file ), '# BEGIN ' . self::MARKER ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			return true;
		}
		if ( ( is_file( $file ) && ! wp_is_writable( $file ) ) || ( ! is_file( $file ) && ! wp_is_writable( ABSPATH ) ) ) {
			return false;
		}
		$old  = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$new  = self::without_block( $old );
		if ( $on ) {
			$eol   = false !== strpos( $old, "\r\n" ) ? "\r\n" : "\n";
			$block = '# BEGIN ' . self::MARKER . $eol . implode( $eol, self::htaccess_rules() ) . $eol . '# END ' . self::MARKER . $eol;
			$at    = strpos( $new, '# BEGIN WordPress' );
			$new   = false === $at ? $block . ( '' === $new ? '' : $eol . $new ) : substr( $new, 0, $at ) . $block . $eol . substr( $new, $at );
		}
		if ( $new === $old ) {
			return true;
		}
		return false !== file_put_contents( $file, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- .htaccess, the same write insert_with_markers() makes.
	}

	/**
	 * The file's text with Perdita's block, and the blank line after it, cut.
	 *
	 * @param string $text .htaccess text.
	 * @return string
	 */
	public static function without_block( $text ) {
		$start = strpos( $text, '# BEGIN ' . self::MARKER );
		if ( false === $start ) {
			return $text;
		}
		$end = strpos( $text, '# END ' . self::MARKER, $start );
		if ( false === $end ) {
			return $text;
		}
		$end += strlen( '# END ' . self::MARKER );
		// The line break after the END marker, then one blank line.
		foreach ( array( 0, 1 ) as $unused ) {
			if ( "\r\n" === substr( $text, $end, 2 ) ) {
				$end += 2;
			} elseif ( "\n" === substr( $text, $end, 1 ) ) {
				$end += 1;
			}
		}
		return substr( $text, 0, $start ) . substr( $text, $end );
	}

	/**
	 * Whether the block sits in .htaccess.
	 *
	 * @return bool
	 */
	public static function htaccess_has_block() {
		$file = trailingslashit( ABSPATH ) . '.htaccess';
		return is_file( $file ) && false !== strpos( (string) file_get_contents( $file ), '# BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/* ---------- live check ---------- */

	/**
	 * Prove the setup on the live server and save the result. Pages go to
	 * LiteSpeed only after this passes (see Perdita_Caching::litespeed_active()).
	 *
	 * @return array array{ok: bool, message: string, time: int}
	 */
	public static function verify() {
		$fail = static function ( $message ) {
			// Anything LiteSpeed kept during the check goes.
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Purge: ' . self::purge_header( array( Perdita_Caching::litespeed_site_tag() ) ), false );
			}
			$state = array(
				'ok'      => false,
				'message' => $message,
				'time'    => time(),
			);
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		};
		if ( ! Perdita_Caching::server_is_litespeed() ) {
			return $fail( __( 'This server isn\'t LiteSpeed.', 'perdita-core' ) );
		}
		if ( ! self::htaccess_has_block() ) {
			return $fail( __( 'Perdita couldn\'t write its block to .htaccess.', 'perdita-core' ) );
		}
		// Mark the probe page public for this check only.
		update_option( self::STATE_OPTION, array( 'ok' => true, 'probing' => true, 'time' => time() ), false );
		$url    = home_url( '/' );
		$first = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false, 'redirection' => 0 ) );
		$hit   = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false, 'redirection' => 0 ) );
		$cookie = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'sslverify'   => false,
				'redirection' => 0,
				'headers'     => array( 'Cookie' => 'wordpress_logged_in_perditacheck=1' ),
			)
		);
		if ( is_wp_error( $first ) || is_wp_error( $hit ) || is_wp_error( $cookie ) ) {
			return $fail( __( 'Perdita couldn\'t reach your home page to check LiteSpeed.', 'perdita-core' ) );
		}
		$status = static function ( $res ) {
			return strtolower( (string) wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) );
		};
		if ( 'hit' !== $status( $hit ) ) {
			return $fail( __( 'LiteSpeed didn\'t serve the second request from its cache. Ask your host to turn on the LiteSpeed cache module. On OpenLiteSpeed, a server restart may be needed to read .htaccess.', 'perdita-core' ) );
		}
		if ( 'hit' === $status( $cookie ) ) {
			return $fail( __( 'LiteSpeed served a cached page to a signed-in visitor, so Perdita turned this back off. Check that your server reads rewrite rules from .htaccess.', 'perdita-core' ) );
		}
		// The home page alone can pass while WordPress's rules end rewrite
		// processing for every other page, so a post gets the same check.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'has_password'   => false,
				'fields'         => 'ids',
			)
		);
		$inner = $posts ? (string) get_permalink( (int) $posts[0] ) : '';
		if ( '' !== $inner && untrailingslashit( $inner ) !== untrailingslashit( $url ) ) {
			$args = array( 'timeout' => 10, 'sslverify' => false, 'redirection' => 0 );
			wp_remote_get( $inner, $args );
			$args['headers'] = array( 'Cookie' => 'wordpress_logged_in_perditacheck=1' );
			$inner_cookie    = wp_remote_get( $inner, $args );
			if ( is_wp_error( $inner_cookie ) ) {
				return $fail( __( 'Perdita couldn\'t reach a post to check LiteSpeed.', 'perdita-core' ) );
			}
			if ( 'hit' === $status( $inner_cookie ) ) {
				return $fail( __( 'LiteSpeed served a cached post to a signed-in visitor, so Perdita turned this back off. Check that Perdita\'s block sits above WordPress\'s rules in .htaccess.', 'perdita-core' ) );
			}
		}
		$state = array(
			'ok'      => true,
			'message' => __( 'LiteSpeed caches your pages and skips signed-in visitors.', 'perdita-core' ),
			'time'    => time(),
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}
}
