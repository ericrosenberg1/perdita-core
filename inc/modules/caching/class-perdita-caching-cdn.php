<?php
/**
 * CDN URL rewriting for the page cache module.
 *
 * Points static asset URLs (stylesheets, scripts, images, fonts, media) under
 * the chosen directories at a CDN hostname. The CDN is an origin-pull one: it
 * fetches `/wp-content/...` from this site the first time someone asks for
 * it, so only the hostname changes and the path stays the same.
 *
 * The rewrite runs in an output buffer opened right after the page cache's
 * capture buffer, so the copy saved to the cache and the copy an uncached
 * visitor gets carry the same URLs.
 *
 * Loaded on first use from Perdita_Caching::load( 'cdn' ).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_CDN {

	/**
	 * File extensions that count as a static asset.
	 *
	 * @return string[]
	 */
	public static function extensions() {
		/**
		 * File extensions the CDN rewrite covers.
		 *
		 * @param string[] $extensions Lowercase, no dot.
		 */
		$list = (array) apply_filters(
			'perdita_cache_cdn_extensions',
			array( 'css', 'js', 'mjs', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'ogv', 'mp3', 'ogg', 'wav', 'm4a' )
		);
		return array_values( array_filter( array_map( 'sanitize_key', $list ), 'strlen' ) );
	}

	/**
	 * Whether this request's HTML gets the rewrite. Never the admin, a feed,
	 * a REST or AJAX answer, a preview, or the Customizer's preview frame:
	 * someone editing the site should see the files the server holds now.
	 *
	 * @return bool
	 */
	public static function should_rewrite() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback() ) {
			return false;
		}
		/**
		 * Whether to rewrite static asset URLs to the CDN on this request.
		 *
		 * @param bool $rewrite Default true.
		 */
		return (bool) apply_filters( 'perdita_cache_cdn_rewrite', true );
	}

	/**
	 * Open the rewrite buffer. Runs on template_redirect at priority 2,
	 * after the page cache has served a hit or opened its capture buffer.
	 *
	 * @param array $settings Caching settings.
	 */
	public static function start( $settings ) {
		if ( empty( $settings['cdn_enabled'] ) || '' === (string) $settings['cdn_host'] || ! self::should_rewrite() ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $settings ) {
				return Perdita_Caching_CDN::rewrite( $html, $settings );
			}
		);
	}

	/**
	 * Rewrite static asset URLs in a piece of HTML.
	 *
	 * A URL is rewritten when it points at this site (absolute or
	 * protocol-relative), sits under one of the included directories, ends
	 * in a static file extension, and contains none of the excluded
	 * strings. URLs inside JSON (escaped slashes) are left alone.
	 *
	 * @param string $html     HTML.
	 * @param array  $settings Caching settings.
	 * @return string
	 */
	public static function rewrite( $html, $settings ) {
		$cdn  = isset( $settings['cdn_host'] ) ? (string) $settings['cdn_host'] : '';
		$dirs = isset( $settings['cdn_dirs'] ) ? array_filter( array_map( 'strval', (array) $settings['cdn_dirs'] ), 'strlen' ) : array();
		if ( ! is_string( $html ) || '' === $html || '' === $cdn || empty( $dirs ) ) {
			return $html;
		}
		// Only an HTML response. A plugin that answers a front-end URL with
		// JSON or XML keeps its body untouched.
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'Content-Type:' ) && false === stripos( $line, 'text/html' ) ) {
				return $html;
			}
		}

		// Every host this site answers assets on: the home URL, the WordPress
		// URL and the content URL can each differ.
		$hosts = array();
		foreach ( array( home_url(), site_url(), content_url(), includes_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$hosts[] = strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
			}
		}
		$hosts = array_values( array_unique( $hosts ) );
		if ( empty( $hosts ) || in_array( $cdn, $hosts, true ) ) {
			return $html;
		}
		// WordPress in a subdirectory keeps that prefix on the CDN too.
		$base = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );

		$quote   = static function ( $value ) {
			return preg_quote( $value, '~' );
		};
		$pattern = '~(?<![\w.\-/:])((?:https?:)?//)(?:' . implode( '|', array_map( $quote, $hosts ) ) . ')(' . $quote( $base ) . '/(?:' . implode( '|', array_map( $quote, $dirs ) ) . ')/[^\s"\'<>()\\\\]+)~i';

		$exts    = implode( '|', array_map( $quote, self::extensions() ) );
		$exclude = isset( $settings['cdn_exclude'] ) ? array_filter( array_map( 'strval', (array) $settings['cdn_exclude'] ), 'strlen' ) : array();

		$out = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $cdn, $exts, $exclude ) {
				// The extension is read from the path, before any query string.
				$path = (string) strtok( $m[2], '?#' );
				if ( '' === $exts || ! preg_match( '~\.(?:' . $exts . ')$~i', $path ) ) {
					return $m[0];
				}
				foreach ( $exclude as $needle ) {
					if ( false !== stripos( $m[0], $needle ) ) {
						return $m[0];
					}
				}
				return $m[1] . $cdn . $m[2];
			},
			$html
		);
		// A regex failure (a huge page hitting the backtrack limit) leaves the
		// page as it was.
		return is_string( $out ) ? $out : $html;
	}
}
