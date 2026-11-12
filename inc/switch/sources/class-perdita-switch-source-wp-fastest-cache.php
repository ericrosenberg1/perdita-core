<?php
/**
 * WP Fastest Cache. Its settings are JSON strings in the WpFastestCache,
 * WpFastestCacheExclude and WpFastestCacheCDN options.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_WP_Fastest_Cache extends Perdita_Switch_Caching_Source {

	public function id() {
		return 'wp-fastest-cache';
	}

	public function label() {
		return 'WP Fastest Cache';
	}

	public function plugin_files() {
		return array( 'wp-fastest-cache/wpFastestCache.php' );
	}

	public function htaccess_markers() {
		return array( 'WpFastestCache', 'GzipWpFastestCache', 'LBCWpFastestCache', 'WEBPWpFastestCache' );
	}

	public function has_settings() {
		return false !== get_option( 'WpFastestCache', false );
	}

	public function plan() {
		$this->begin();
		$c = self::json_option( 'WpFastestCache' );
		if ( ! $c ) {
			return $this->finish();
		}
		$on = self::on( self::val( $c, 'wpFastestCacheStatus' ) );
		$this->offer_enabled( __( 'Cache System', 'perdita-core' ), $on );
		$mobile = self::on( self::val( $c, 'wpFastestCacheMobileTheme' ) );
		$this->offer( __( 'Create cache for mobile theme', 'perdita-core' ), $mobile, 'mobile_cache', $mobile, __( 'Separate copy for phones', 'perdita-core' ) );
		$preload = self::on( self::val( $c, 'wpFastestCachePreload' ) );
		$this->offer( __( 'Preload', 'perdita-core' ), $preload, 'preload', $preload, __( 'Preload', 'perdita-core' ) );
		if ( self::on( self::val( $c, 'wpFastestCacheGzip' ) ) ) {
			$this->unsupported( __( 'Gzip', 'perdita-core' ), true, __( 'In WP Fastest Cache, you used this setting to turn on your server\'s own compression. With Perdita\'s gzip setting, you keep compressed copies of cached pages instead, so turn on gzip yourself if you want those copies.', 'perdita-core' ) );
		}
		if ( self::on( self::val( $c, 'wpFastestCacheLBC' ) ) ) {
			$this->unsupported( __( 'Browser Caching', 'perdita-core' ), true, __( 'Turn on browser caching on Perdita, Caching and save, and you get the rules in .htaccess.', 'perdita-core' ) );
		}
		if ( self::on( self::val( $c, 'wpFastestCacheMinifyHtml' ) ) || self::on( self::val( $c, 'wpFastestCacheMinifyCss' ) ) || self::on( self::val( $c, 'wpFastestCacheCombineJs' ) ) ) {
			$this->unsupported( __( 'Minify and combine', 'perdita-core' ), true, __( 'You can\'t minify CSS or JavaScript with Perdita\'s page cache.', 'perdita-core' ) );
		}
		if ( self::on( self::val( $c, 'wpFastestCacheLoggedInUser' ) ) ) {
			$this->unsupported( __( 'Logged-in Users', 'perdita-core' ), true, __( 'In Perdita, logged-in visitors get a freshly built page by default.', 'perdita-core' ) );
		}

		$this->plan_exclusions();
		$this->plan_cdn();
		$this->note( __( 'In WP Fastest Cache, you set the lifetime as a cron schedule, and the schedule stays behind when you deactivate the plugin. You keep Perdita\'s 10-hour lifetime unless you change the setting.', 'perdita-core' ) );
		return $this->finish();
	}

	/**
	 * Exclusion rules: pages, user agents and cookies.
	 */
	private function plan_exclusions() {
		$rules = self::json_option( 'WpFastestCacheExclude' );
		$paths = array();
		$uas   = array();
		$cooks = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type    = (string) self::val( $rule, 'type' );
			$prefix  = (string) self::val( $rule, 'prefix' );
			$content = trim( (string) self::val( $rule, 'content' ) );
			if ( '' === $content ) {
				continue;
			}
			$shown = $prefix . ': ' . $content;
			if ( 'page' === $type ) {
				$setting = __( 'Exclude page', 'perdita-core' );
				if ( in_array( $prefix, array( 'homepage', 'category', 'tag', 'post', 'page', 'archive', 'attachment' ), true ) ) {
					$this->unsupported( $setting, $prefix, __( 'In Perdita, you can exclude paths but not whole kinds of page.', 'perdita-core' ) );
				} elseif ( 'startwith' === $prefix || 'exact' === $prefix ) {
					$path = '/' . ltrim( $content, '/' );
					if ( 'exact' === $prefix ) {
						$this->skip( $setting, $shown, __( 'On Perdita, Caching, you can exclude a path along with everything under the path, but not one exact address. Add the path there if excluding the pages under it works for you.', 'perdita-core' ), __( 'Never cache these paths', 'perdita-core' ) );
					} else {
						$paths[] = $path;
					}
				} else {
					$this->skip( $setting, $shown, __( 'This matched anywhere in an address. In Perdita, you match a path from its start, so add the full path on Perdita, Caching.', 'perdita-core' ), __( 'Never cache these paths', 'perdita-core' ) );
				}
			} elseif ( 'useragent' === $type ) {
				if ( 'contain' === $prefix ) {
					$uas[] = $content;
				} else {
					$this->skip( __( 'Exclude user agent', 'perdita-core' ), $shown, __( 'In Perdita, you can only match part of a user agent, and this rule uses a different kind of match.', 'perdita-core' ), __( 'Skip the cache for these user agents', 'perdita-core' ) );
				}
			} elseif ( 'cookie' === $type ) {
				if ( 'contain' === $prefix ) {
					$cooks[] = $content;
				} else {
					$this->skip( __( 'Exclude cookie', 'perdita-core' ), $shown, __( 'In Perdita, you can only match part of a cookie name, and this rule uses a different kind of match.', 'perdita-core' ), __( 'Skip the cache for these cookies', 'perdita-core' ) );
				}
			}
		}
		if ( $paths ) {
			$this->offer( __( 'Exclude page', 'perdita-core' ), $paths, 'exclude_paths', $paths, __( 'Never cache these paths', 'perdita-core' ) );
		}
		if ( $uas ) {
			$this->offer( __( 'Exclude user agent', 'perdita-core' ), $uas, 'reject_user_agents', $uas, __( 'Skip the cache for these user agents', 'perdita-core' ) );
		}
		if ( $cooks ) {
			$this->offer( __( 'Exclude cookie', 'perdita-core' ), $cooks, 'reject_cookies', $cooks, __( 'Skip the cache for these cookies', 'perdita-core' ) );
		}
	}

	/**
	 * The first CDN entry that points at a pull host.
	 */
	private function plan_cdn() {
		$cdns = self::json_option( 'WpFastestCacheCDN' );
		if ( isset( $cdns['cdnurl'] ) ) {
			$cdns = array( $cdns );
		}
		$used = false;
		foreach ( $cdns as $cdn ) {
			if ( ! is_array( $cdn ) || ! isset( $cdn['cdnurl'] ) ) {
				continue;
			}
			$host = self::cdn_host_from( (string) $cdn['cdnurl'] );
			if ( '' === $host ) {
				continue;
			}
			if ( $used ) {
				$this->unsupported( __( 'CDN', 'perdita-core' ), $cdn['cdnurl'], __( 'In Perdita, you can rewrite URLs to one CDN host.', 'perdita-core' ) );
				continue;
			}
			$used = true;
			if ( isset( $cdn['status'] ) && ! self::on( $cdn['status'] ) ) {
				continue;
			}
			$this->offer( __( 'CDN', 'perdita-core' ), true, 'cdn_enabled', true, __( 'Rewrite asset URLs to a CDN', 'perdita-core' ) );
			$this->offer( __( 'CDN URL', 'perdita-core' ), $cdn['cdnurl'], 'cdn_host', $host, __( 'CDN host', 'perdita-core' ) );
		}
	}
}
