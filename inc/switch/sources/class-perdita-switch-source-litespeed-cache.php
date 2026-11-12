<?php
/**
 * LiteSpeed Cache. Each setting is its own litespeed.conf.<id> option, and
 * list settings are stored as JSON strings.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_LiteSpeed_Cache extends Perdita_Switch_Caching_Source {

	public function id() {
		return 'litespeed-cache';
	}

	public function label() {
		return 'LiteSpeed Cache';
	}

	public function plugin_files() {
		return array( 'litespeed-cache/litespeed-cache.php' );
	}

	public function htaccess_markers() {
		return array( 'LSCACHE', 'NON_LSCACHE' );
	}

	public function has_settings() {
		return false !== get_option( 'litespeed.conf.cache', false );
	}

	/**
	 * One setting.
	 *
	 * @param string $id      Setting id.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function conf( $id, $default = null ) {
		$value = get_option( 'litespeed.conf.' . $id, $default );
		return is_scalar( $value ) || null === $value ? $value : $default;
	}

	/**
	 * A list setting.
	 *
	 * @param string $id Setting id.
	 * @return array
	 */
	private static function conf_list( $id ) {
		$value = self::json_option( 'litespeed.conf.' . $id );
		if ( $value ) {
			return $value;
		}
		$raw = get_option( 'litespeed.conf.' . $id, '' );
		return is_string( $raw ) ? self::split_list( $raw ) : array();
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() ) {
			return $this->finish();
		}
		$this->offer_enabled( __( 'Enable Cache', 'perdita-core' ), self::on( self::conf( 'cache' ) ) );
		if ( null !== self::conf( 'cache-ttl_pub' ) ) {
			$this->offer_ttl( __( 'Default Public Cache TTL', 'perdita-core' ), self::conf( 'cache-ttl_pub' ) );
		}
		if ( null !== self::conf( 'cache-mobile' ) ) {
			$mobile = self::on( self::conf( 'cache-mobile' ) );
			$this->offer( __( 'Cache Mobile', 'perdita-core' ), $mobile, 'mobile_cache', $mobile, __( 'Separate copy for phones', 'perdita-core' ) );
		}
		$uris = self::conf_list( 'cache-exc' );
		if ( $uris ) {
			$this->offer_path_patterns( __( 'Do Not Cache URIs', 'perdita-core' ), $uris );
		}
		$uas = array_values( array_filter( self::conf_list( 'cache-exc_useragents' ), 'is_scalar' ) );
		if ( $uas ) {
			$this->offer( __( 'Do Not Cache User Agents', 'perdita-core' ), $uas, 'reject_user_agents', $uas, __( 'Skip the cache for these user agents', 'perdita-core' ) );
		}
		$cookies = self::conf_list( 'cache-exc_cookies' );
		if ( $cookies ) {
			$this->offer_cookie_patterns( __( 'Do Not Cache Cookies', 'perdita-core' ), $cookies );
		}
		if ( self::on( self::conf( 'cache-browser' ) ) ) {
			$this->unsupported( __( 'Browser Cache', 'perdita-core' ), true, __( 'Turn on browser caching on Perdita, Caching and save, and you get the rules in .htaccess.', 'perdita-core' ) );
		}
		if ( self::on( self::conf( 'crawler' ) ) ) {
			$this->offer( __( 'Crawler', 'perdita-core' ), true, 'preload', true, __( 'Preload', 'perdita-core' ) );
			if ( null !== self::conf( 'crawler-crawl_interval' ) ) {
				$this->offer_interval( __( 'Crawl Interval', 'perdita-core' ), (int) self::conf( 'crawler-crawl_interval' ) );
			}
		}
		if ( self::on( self::conf( 'cdn' ) ) ) {
			$mapping = self::conf_list( 'cdn-mapping' );
			$first   = '';
			foreach ( $mapping as $row ) {
				if ( is_array( $row ) && ! empty( $row['url'] ) && is_scalar( $row['url'] ) ) {
					$first = (string) $row['url'];
					break;
				}
			}
			$host = self::cdn_host_from( $first );
			if ( '' !== $host ) {
				$this->offer( __( 'Use CDN Mapping', 'perdita-core' ), true, 'cdn_enabled', true, __( 'Rewrite asset URLs to a CDN', 'perdita-core' ) );
				$this->offer( __( 'CDN URL', 'perdita-core' ), $first, 'cdn_host', $host, __( 'CDN host', 'perdita-core' ) );
				$exclude = array_values( array_filter( self::conf_list( 'cdn-exc' ), 'is_scalar' ) );
				if ( $exclude ) {
					$this->offer( __( 'Exclude Path', 'perdita-core' ), $exclude, 'cdn_exclude', $exclude, __( 'CDN exclusions', 'perdita-core' ) );
				}
			}
		}
		$this->note( __( 'On a LiteSpeed server, you get cached pages from LiteSpeed\'s server-level cache before PHP starts. When you switch to Perdita\'s page cache, you serve cached pages from PHP and give up that speed.', 'perdita-core' ) );
		return $this->finish();
	}
}
