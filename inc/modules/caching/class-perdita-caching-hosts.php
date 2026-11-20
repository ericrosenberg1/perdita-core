<?php
/**
 * Caching: managed hosts that run their own page cache.
 *
 * On these hosts the host's cache sits in front of WordPress, so Perdita's
 * page cache stands down (Perdita_Caching::other_page_cache() returns the
 * host's name) and Perdita's purges go to the host's own purge call. Each
 * signal and call below comes from the host's own documentation or code:
 *
 * - WP Engine: is_wpe() says the site runs there
 *   (wpengine.com/support/determining-wp-engine-environment/). Purge with
 *   WpeCommon::purge_varnish_cache(), with a post id for one post
 *   (wpengine.com/support/cache/).
 * - Pressable: the IS_PRESSABLE constant, which Pressable's own Pressable
 *   Cache Management plugin checks. Its edge cache purges through
 *   Edge_Cache_Plugin::purge_domain_now() and purge_uris_now(), and Batcache
 *   drops one URL through batcache_clear_url() (github.com/Automattic/batcache).
 * - WordPress.com: IS_ATOMIC or ATOMIC_CLIENT_ID, read in Automattic's
 *   wpcom-site-helper. WordPress.com documents no PHP purge call, so purges
 *   happen from its dashboard.
 * - SiteGround: Perdita already stands down for SiteGround's Speed
 *   Optimizer plugin. While it's active, purges go through
 *   sg_cachepress_purge_everything() and sg_cachepress_purge_cache()
 *   (sg-cachepress, helpers/helpers.php).
 *
 * Kinsta and GoDaddy document no PHP signal Perdita can trust. An owner
 * there declares the host with the `perdita_cache_managed_host` filter.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_Hosts {

	/** @var bool A full purge went out in this request. */
	private static $purged_all = false;

	/**
	 * The host's id, or '' on any other server.
	 *
	 * @return string
	 */
	public static function detect() {
		return Perdita_Caching::managed_host();
	}

	/**
	 * A host's display name.
	 *
	 * @param string $host Host id.
	 * @return string
	 */
	public static function label( $host ) {
		$labels = array(
			'wpengine'  => 'WP Engine',
			'pressable' => 'Pressable',
			'wpcom'     => 'WordPress.com',
			'kinsta'    => 'Kinsta',
			'godaddy'   => 'GoDaddy',
		);
		return isset( $labels[ $host ] ) ? $labels[ $host ] : ucfirst( $host );
	}

	/**
	 * Whether Perdita can purge this host's cache from PHP.
	 *
	 * @param string $host Host id.
	 * @return bool
	 */
	public static function can_purge( $host ) {
		switch ( $host ) {
			case 'wpengine':
				return class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' );
			case 'pressable':
				return ( class_exists( 'Edge_Cache_Plugin' ) && method_exists( 'Edge_Cache_Plugin', 'get_instance' ) ) || function_exists( 'batcache_clear_url' );
		}
		return function_exists( 'sg_cachepress_purge_everything' );
	}

	/**
	 * `perdita_cache_purged_all`: purge the host's whole cache, once per
	 * request.
	 */
	public static function purge_all() {
		if ( self::$purged_all ) {
			return;
		}
		self::$purged_all = true;
		$host             = self::detect();
		if ( 'wpengine' === $host && class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			WpeCommon::purge_varnish_cache();
		} elseif ( 'pressable' === $host ) {
			$edge = self::pressable_edge();
			if ( $edge && method_exists( $edge, 'purge_domain_now' ) ) {
				$edge->purge_domain_now( 'perdita-purge-all' );
			}
		}
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything();
		}
	}

	/**
	 * `perdita_cache_purged_url`: purge one URL from the host's cache.
	 *
	 * @param mixed $url URL.
	 */
	public static function purge_url( $url ) {
		if ( self::$purged_all || ! is_string( $url ) || '' === $url ) {
			return;
		}
		$host = self::detect();
		if ( 'wpengine' === $host && class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			$post_id = (int) url_to_postid( $url );
			if ( $post_id > 0 ) {
				WpeCommon::purge_varnish_cache( $post_id );
			} else {
				// A home page or an archive has no post id to name.
				self::purge_all();
			}
		} elseif ( 'pressable' === $host ) {
			$edge = self::pressable_edge();
			if ( $edge && method_exists( $edge, 'purge_uris_now' ) ) {
				$edge->purge_uris_now( array( $url ) );
			}
			if ( function_exists( 'batcache_clear_url' ) ) {
				batcache_clear_url( $url );
			}
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}
	}

	/**
	 * Pressable's edge cache object, or null.
	 *
	 * @return object|null
	 */
	private static function pressable_edge() {
		if ( ! class_exists( 'Edge_Cache_Plugin' ) || ! method_exists( 'Edge_Cache_Plugin', 'get_instance' ) ) {
			return null;
		}
		$edge = Edge_Cache_Plugin::get_instance();
		return is_object( $edge ) ? $edge : null;
	}

	/**
	 * Reset the once-per-request flag. For tests.
	 */
	public static function reset() {
		self::$purged_all = false;
	}
}
