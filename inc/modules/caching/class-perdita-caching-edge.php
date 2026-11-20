<?php
/**
 * Caching: the Cloudflare Cache Rule that lets Cloudflare keep this site's
 * anonymous pages at the edge.
 *
 * Cloudflare doesn't cache HTML by default
 * (developers.cloudflare.com/cache/concepts/default-cache-behavior/). A Cache
 * Rule marks a request eligible for cache. This class builds that rule's
 * expression from the same lists the page cache uses, so the edge skips
 * exactly the visitors and paths Perdita skips:
 *
 * - every cookie fragment from Perdita_Caching::no_cache_cookies() (logged
 *   in, post password, comment author, WooCommerce, Easy Digital Downloads,
 *   Perdita's carts, and the site owner's own list),
 * - wp-admin, wp-login.php, the REST API, xmlrpc.php, wp-cron.php, any other
 *   .php path, the WooCommerce cart, checkout and account pages, and the
 *   Caching screen's excluded paths,
 * - any request with a query string (previews, searches, tracking links).
 *
 * The rule respects origin headers ("Use cache-control header if present,
 * bypass cache if not", developers.cloudflare.com/cache/how-to/cache-rules/settings/),
 * so a page that slips past the expression still needs Perdita's public
 * header to be stored. Every personal response goes out private, no-store,
 * which Cloudflare never stores
 * (developers.cloudflare.com/cache/concepts/cache-responses/).
 *
 * Perdita Pro installs the rule through the API. Without Pro, the Caching
 * screen shows the expression to paste into the dashboard.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_Edge {

	/**
	 * Longest expression Cloudflare accepts, in characters
	 * (developers.cloudflare.com/ruleset-engine/rules-language/expressions/).
	 */
	const MAX_EXPRESSION = 4096;

	/**
	 * Path prefixes that never come from the edge cache, relative to the
	 * site's home path.
	 *
	 * @return string[]
	 */
	public static function bypass_paths() {
		$paths = array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php', '/wp-content/', '/wp-includes/' );
		// WooCommerce's cart, checkout and account pages.
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$link = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
					if ( '' !== $link && '/' !== $link ) {
						$paths[] = untrailingslashit( $link );
					}
				}
			}
		}
		$settings = Perdita_Caching::settings();
		foreach ( $settings['exclude_paths'] as $path ) {
			$paths[] = (string) $path;
		}
		/**
		 * Path prefixes the Cloudflare edge rule never caches.
		 *
		 * @param string[] $paths Prefixes, each starting with a slash.
		 */
		$paths = (array) apply_filters( 'perdita_cache_edge_bypass_paths', $paths );
		$out   = array();
		foreach ( $paths as $path ) {
			$path = '/' . ltrim( (string) $path, '/' );
			if ( '/' !== $path && preg_match( '#^/[A-Za-z0-9._~%!$&()*+,;=:@/\-]*$#', $path ) ) {
				$out[ $path ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * A string literal for the Rules language, or '' when the value holds a
	 * character this won't put in one.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function literal( $value ) {
		$value = (string) $value;
		if ( '' === $value || preg_match( '/["\\\\\x00-\x1f\x7f]/', $value ) ) {
			return '';
		}
		return '"' . $value . '"';
	}

	/**
	 * The rule's expression, or a WP_Error that says why there is none.
	 *
	 * @return string|WP_Error
	 */
	public static function expression() {
		$home = wp_parse_url( home_url( '/' ) );
		$host = strtolower( (string) ( $home['host'] ?? '' ) );
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return new WP_Error( 'perdita_edge_host', __( 'Your site address has no host name Cloudflare can match.', 'perdita-core' ) );
		}
		$base = untrailingslashit( (string) ( $home['path'] ?? '' ) );

		$parts   = array();
		$parts[] = 'http.host eq ' . self::literal( $host );
		if ( '' !== $base ) {
			$parts[] = '(http.request.uri.path eq ' . self::literal( $base ) . ' or starts_with(http.request.uri.path, ' . self::literal( $base . '/' ) . '))';
		}
		// Pages only: WordPress permalinks have no extension. Static files
		// keep Cloudflare's own defaults.
		$parts[] = '(http.request.uri.path.extension eq "" or http.request.uri.path.extension in {"html" "htm"})';
		$parts[] = 'http.request.uri.query eq ""';
		foreach ( self::bypass_paths() as $path ) {
			$lit = self::literal( $base . $path );
			if ( '' !== $lit ) {
				$parts[] = 'not starts_with(http.request.uri.path, ' . $lit . ')';
			}
		}
		$cookies = array( 'wordpress_logged_in', 'wordpress_sec_', 'wp-postpass_', 'comment_author' );
		$cookies = array_merge( $cookies, Perdita_Caching::no_cache_cookies() );
		$seen    = array();
		foreach ( $cookies as $fragment ) {
			$fragment = strtolower( trim( (string) $fragment ) );
			$lit      = self::literal( $fragment );
			if ( '' === $lit || isset( $seen[ $fragment ] ) ) {
				continue;
			}
			$seen[ $fragment ] = true;
			// Cookie fragments match anywhere in the Cookie header, the way
			// the page cache matches them in a cookie's name. lower() makes
			// the match case-insensitive like Core's own check.
			$parts[] = 'not lower(http.cookie) contains ' . $lit;
		}
		$expression = '(' . implode( ' and ', $parts ) . ')';
		if ( strlen( $expression ) > self::MAX_EXPRESSION ) {
			return new WP_Error( 'perdita_edge_long', __( 'The rule is longer than Cloudflare allows. Shorten your excluded paths or cookie list.', 'perdita-core' ) );
		}
		return $expression;
	}

	/**
	 * Whether the edge rule fits this site's settings, or a WP_Error that
	 * says why not.
	 *
	 * @return true|WP_Error
	 */
	public static function check_settings() {
		$settings = Perdita_Caching::settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'perdita_edge_off', __( 'Turn on the page cache first. The edge keeps only the pages Perdita marks public.', 'perdita-core' ) );
		}
		if ( ! empty( $settings['mobile_cache'] ) ) {
			return new WP_Error( 'perdita_edge_mobile', __( 'Turn off the separate phone copy first. Cloudflare keeps one copy of each page, so a phone page could reach a desktop visitor.', 'perdita-core' ) );
		}
		if ( '' !== Perdita_Caching::other_page_cache() ) {
			return new WP_Error( 'perdita_edge_other', __( 'Another page cache runs this site, so Perdita doesn\'t mark pages for the edge.', 'perdita-core' ) );
		}
		return true;
	}

	/**
	 * The settings to enter in Cloudflare's dashboard, for the Caching
	 * screen's copy-paste box.
	 *
	 * @return string
	 */
	public static function dashboard_text() {
		$expression = self::expression();
		if ( is_wp_error( $expression ) ) {
			return $expression->get_error_message();
		}
		return implode(
			"\n",
			array(
				__( 'Cloudflare dashboard > your domain > Caching > Cache Rules > Create rule', 'perdita-core' ),
				__( 'Rule name: Perdita: cache anonymous HTML', 'perdita-core' ),
				__( 'When incoming requests match: Edit expression, then paste:', 'perdita-core' ),
				$expression,
				__( 'Cache eligibility: Eligible for cache', 'perdita-core' ),
				__( 'Edge TTL: Use cache-control header if present, bypass cache if not', 'perdita-core' ),
				__( 'Browser TTL: Respect origin TTL', 'perdita-core' ),
				__( 'Then set "Cache at the edge for" on the Caching screen, so stored pages carry s-maxage.', 'perdita-core' ),
			)
		);
	}
}
