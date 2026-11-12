<?php
/**
 * WP Super Cache. Its settings live in wp-content/wp-cache-config.php as
 * PHP variables, read here with Perdita_Switch_PHP_Config (a tokenizer,
 * never include), and its CDN settings in the ossdl_* options.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_WP_Super_Cache extends Perdita_Switch_Caching_Source {

	public function id() {
		return 'wp-super-cache';
	}

	public function label() {
		return 'WP Super Cache';
	}

	public function plugin_files() {
		return array( 'wp-super-cache/wp-cache.php' );
	}

	public function dropin_signatures() {
		return array( 'WP SUPER CACHE', 'wp-cache-phase1.php' );
	}

	public function htaccess_markers() {
		return array( 'WPSuperCache' );
	}

	/**
	 * Path of wp-cache-config.php.
	 *
	 * @return string
	 */
	public static function config_path() {
		$dir = defined( 'WPCACHECONFIGPATH' ) ? (string) constant( 'WPCACHECONFIGPATH' ) : WP_CONTENT_DIR;
		return trailingslashit( $dir ) . 'wp-cache-config.php';
	}

	/**
	 * The config file's literal values.
	 *
	 * @return array
	 */
	public function config() {
		return Perdita_Switch_Source::scalars_only( Perdita_Switch_PHP_Config::parse_file( self::config_path() ) );
	}

	public function has_settings() {
		return is_file( self::config_path() );
	}

	public function reactivate_note() {
		return __( 'WP Super Cache\'s own cache was switched off when you deactivated the plugin, so switch caching back on under Settings, WP Super Cache.', 'perdita-core' );
	}

	public function plan() {
		$this->begin();
		$c = $this->config();
		if ( ! $c ) {
			return $this->finish();
		}

		// WP Super Cache writes $cache_enabled = false into its own config
		// whenever it's deactivated, so "off" on an inactive copy says
		// nothing about how the owner ran it. Leave Perdita's switch alone.
		$det = $this->detect();
		if ( empty( $c['cache_enabled'] ) && ! $det['active'] ) {
			$this->skip( __( 'Caching', 'perdita-core' ), false, __( 'WP Super Cache\'s caching is switched off whenever you deactivate the plugin, so you can\'t tell from this setting whether you used caching. You keep your earlier choice for Perdita\'s page cache switch.', 'perdita-core' ), __( 'Page cache', 'perdita-core' ) );
		} else {
			$this->offer_enabled( __( 'Caching', 'perdita-core' ), ! empty( $c['cache_enabled'] ) );
		}
		if ( isset( $c['cache_max_time'] ) ) {
			$this->offer_ttl( __( 'Cache timeout', 'perdita-core' ), $c['cache_max_time'] );
		}

		// Garbage collection: a named schedule in "time" mode, seconds in
		// "interval" mode, and nothing at all with a timeout of 0.
		if ( isset( $c['cache_max_time'] ) && 0 === (int) $c['cache_max_time'] ) {
			$this->offer_gc_seconds( __( 'Garbage collection', 'perdita-core' ), 0 );
		} elseif ( 'time' === self::val( $c, 'cache_schedule_type' ) && in_array( self::val( $c, 'cache_schedule_interval' ), array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			$this->offer( __( 'Garbage collection', 'perdita-core' ), self::val( $c, 'cache_schedule_interval' ), 'gc_schedule', self::val( $c, 'cache_schedule_interval' ), __( 'Sweep expired files', 'perdita-core' ) );
		} elseif ( isset( $c['cache_time_interval'] ) ) {
			$this->offer_gc_seconds( __( 'Garbage collection', 'perdita-core' ), (int) $c['cache_time_interval'] );
		}

		if ( isset( $c['cache_rejected_uri'] ) && is_array( $c['cache_rejected_uri'] ) ) {
			$this->offer_path_patterns( __( 'Rejected URL strings', 'perdita-core' ), $c['cache_rejected_uri'], array( 'wp-.*\\.php', 'index\\.php' ) );
		}
		if ( ! empty( $c['cache_rejected_user_agent'] ) && is_array( $c['cache_rejected_user_agent'] ) ) {
			$agents = array_values( array_filter( array_map( 'trim', array_filter( $c['cache_rejected_user_agent'], 'is_scalar' ) ), 'strlen' ) );
			if ( $agents ) {
				$this->offer( __( 'Rejected user agents', 'perdita-core' ), $agents, 'reject_user_agents', $agents, __( 'Skip the cache for these user agents', 'perdita-core' ) );
			}
		}
		if ( ! empty( $c['wpsc_rejected_cookies'] ) && is_array( $c['wpsc_rejected_cookies'] ) ) {
			$this->offer_cookie_patterns( __( 'Rejected cookies', 'perdita-core' ), $c['wpsc_rejected_cookies'] );
		}
		if ( isset( $c['wp_cache_mobile_enabled'] ) ) {
			$this->offer( __( 'Mobile device support', 'perdita-core' ), ! empty( $c['wp_cache_mobile_enabled'] ), 'mobile_cache', ! empty( $c['wp_cache_mobile_enabled'] ), __( 'Separate copy for phones', 'perdita-core' ) );
		}
		if ( isset( $c['cache_compression'] ) ) {
			$this->offer( __( 'Compress pages', 'perdita-core' ), ! empty( $c['cache_compression'] ), 'gzip', ! empty( $c['cache_compression'] ), __( 'Keep gzip copies', 'perdita-core' ) );
		}
		if ( isset( $c['wp_cache_preload_on'] ) ) {
			$on = ! empty( $c['wp_cache_preload_on'] );
			$this->offer( __( 'Preload mode', 'perdita-core' ), $on, 'preload', $on, __( 'Preload', 'perdita-core' ) );
			if ( $on && isset( $c['wp_cache_preload_interval'] ) ) {
				$this->offer_interval( __( 'Refresh preloaded files', 'perdita-core' ), 60 * (int) $c['wp_cache_preload_interval'] );
			}
		}
		if ( isset( $c['wp_cache_pages']['feed'] ) ) {
			$feeds = empty( $c['wp_cache_pages']['feed'] );
			$this->offer( __( 'Cache feeds', 'perdita-core' ), $feeds, 'cache_feeds', $feeds, __( 'Cache RSS and Atom feeds', 'perdita-core' ) );
		}
		if ( isset( $c['wp_cache_not_logged_in'] ) && empty( $c['wp_cache_not_logged_in'] ) ) {
			$this->unsupported( __( 'Cache for known users', 'perdita-core' ), __( 'on', 'perdita-core' ), __( 'In Perdita, logged-in visitors and commenters get a freshly built page by default.', 'perdita-core' ) );
		}
		if ( ! empty( $c['wp_cache_mod_rewrite'] ) ) {
			$this->unsupported( __( 'Expert (mod_rewrite) mode', 'perdita-core' ), __( 'on', 'perdita-core' ), __( 'In Perdita, you serve cached pages from the early-serve drop-in instead of rewrite rules.', 'perdita-core' ) );
		}

		// CDN: on unless switched off, and only when the URL is another host.
		$cdn_on = ! isset( $c['ossdlcdn'] ) || ! empty( $c['ossdlcdn'] );
		$host   = self::cdn_host_from( (string) get_option( 'ossdl_off_cdn_url', '' ) );
		if ( $cdn_on && '' !== $host ) {
			$this->offer( __( 'CDN', 'perdita-core' ), true, 'cdn_enabled', true, __( 'Rewrite asset URLs to a CDN', 'perdita-core' ) );
			$this->offer( __( 'Off-site URL', 'perdita-core' ), get_option( 'ossdl_off_cdn_url', '' ), 'cdn_host', $host, __( 'CDN host', 'perdita-core' ) );
			$dirs = self::split_list( get_option( 'ossdl_off_include_dirs', '' ) );
			if ( $dirs ) {
				$this->offer( __( 'Include directories', 'perdita-core' ), $dirs, 'cdn_dirs', $dirs, __( 'CDN directories', 'perdita-core' ) );
			}
			$exclude = self::split_list( get_option( 'ossdl_off_exclude', '' ) );
			if ( $exclude ) {
				$this->offer( __( 'Exclude if substring', 'perdita-core' ), $exclude, 'cdn_exclude', $exclude, __( 'CDN exclusions', 'perdita-core' ) );
			}
			if ( '' !== trim( (string) get_option( 'ossdl_cname', '' ) ) ) {
				$this->unsupported( __( 'Additional CNAMES', 'perdita-core' ), get_option( 'ossdl_cname', '' ), __( 'In Perdita, you can rewrite URLs to one CDN host.', 'perdita-core' ) );
			}
		}

		$this->note( __( 'When you deactivate WP Super Cache, the WP_CACHE line is removed from wp-config.php. You need that line for Perdita\'s early serving, and you can add the line back from this card afterward.', 'perdita-core' ) );
		return $this->finish();
	}
}
