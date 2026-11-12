<?php
/**
 * W3 Total Cache. Its settings live in wp-content/w3tc-config/master.php,
 * a file that starts with an exit line followed by JSON. Perdita reads the
 * JSON as text and never runs the file.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_W3_Total_Cache extends Perdita_Switch_Caching_Source {

	public function id() {
		return 'w3-total-cache';
	}

	public function label() {
		return 'W3 Total Cache';
	}

	public function plugin_files() {
		return array( 'w3-total-cache/w3-total-cache.php' );
	}

	public function dropin_signatures() {
		return array( 'W3 Total Cache', 'PgCache_ContentGrabber' );
	}

	public function htaccess_markers() {
		return array( 'W3TC Page Cache core', 'W3TC Page Cache cache', 'W3TC Browser Cache', 'W3TC CDN', 'W3TC Minify core', 'W3TC Minify cache' );
	}

	/**
	 * Path of master.php.
	 *
	 * @return string
	 */
	public static function config_path() {
		return WP_CONTENT_DIR . '/w3tc-config/master.php';
	}

	/**
	 * The settings, or an empty array.
	 *
	 * @return array
	 */
	public function config() {
		$path = self::config_path();
		if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 2000000 ) {
			return array();
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local settings file, read as text and never run.
		if ( ! is_string( $raw ) ) {
			return array();
		}
		$start = strpos( $raw, '{' );
		$data  = false === $start ? null : json_decode( substr( $raw, $start ), true );
		return is_array( $data ) ? Perdita_Switch_Source::scalars_only( $data ) : array();
	}

	public function has_settings() {
		return is_file( self::config_path() );
	}

	public function plan() {
		$this->begin();
		$c = $this->config();
		if ( ! $c ) {
			return $this->finish();
		}
		$on = ! empty( $c['pgcache.enabled'] );
		$this->offer_enabled( __( 'Page cache', 'perdita-core' ), $on );
		if ( isset( $c['pgcache.lifetime'] ) ) {
			$this->offer_ttl( __( 'Maximum lifetime of cache objects', 'perdita-core' ), $c['pgcache.lifetime'] );
		}
		if ( isset( $c['pgcache.file.gc'] ) ) {
			$this->offer_gc_seconds( __( 'Garbage collection interval', 'perdita-core' ), (int) $c['pgcache.file.gc'] );
		}
		if ( ! empty( $c['pgcache.reject.uri'] ) && is_array( $c['pgcache.reject.uri'] ) ) {
			$this->offer_path_patterns( __( 'Never cache the following pages', 'perdita-core' ), $c['pgcache.reject.uri'], array( 'wp-.*\\.php', 'index\\.php' ) );
		}
		if ( ! empty( $c['pgcache.reject.ua'] ) && is_array( $c['pgcache.reject.ua'] ) ) {
			$agents = self::split_list( $c['pgcache.reject.ua'] );
			if ( $agents ) {
				$this->offer( __( 'Rejected user agents', 'perdita-core' ), $agents, 'reject_user_agents', $agents, __( 'Skip the cache for these user agents', 'perdita-core' ) );
			}
		}
		if ( ! empty( $c['pgcache.reject.cookie'] ) && is_array( $c['pgcache.reject.cookie'] ) ) {
			$this->offer_cookie_patterns( __( 'Rejected cookies', 'perdita-core' ), $c['pgcache.reject.cookie'] );
		}
		if ( isset( $c['pgcache.cache.feed'] ) ) {
			$this->offer( __( 'Cache feeds', 'perdita-core' ), ! empty( $c['pgcache.cache.feed'] ), 'cache_feeds', ! empty( $c['pgcache.cache.feed'] ), __( 'Cache RSS and Atom feeds', 'perdita-core' ) );
		}
		if ( isset( $c['mobile.enabled'] ) ) {
			$phones = ! empty( $c['mobile.enabled'] ) && ! empty( $c['mobile.rgroups']['phones']['enabled'] );
			$this->offer( __( 'User agent groups', 'perdita-core' ), $phones, 'mobile_cache', $phones, __( 'Separate copy for phones', 'perdita-core' ) );
		}
		$gzip = ! empty( $c['browsercache.enabled'] ) && ! empty( $c['browsercache.html.compression'] );
		$this->offer( __( 'Enable HTTP (gzip) compression', 'perdita-core' ), $gzip, 'gzip', $gzip, __( 'Keep gzip copies', 'perdita-core' ) );
		if ( ! empty( $c['pgcache.prime.enabled'] ) ) {
			$this->offer( __( 'Automatically prime the page cache', 'perdita-core' ), true, 'preload', true, __( 'Preload', 'perdita-core' ) );
			if ( isset( $c['pgcache.prime.interval'] ) ) {
				$this->offer_interval( __( 'Update interval', 'perdita-core' ), (int) $c['pgcache.prime.interval'] );
			}
		}
		if ( ! empty( $c['minify.enabled'] ) ) {
			$this->unsupported( __( 'Minify', 'perdita-core' ), true, __( 'You can\'t minify CSS or JavaScript with Perdita\'s page cache.', 'perdita-core' ) );
		}
		if ( ! empty( $c['dbcache.enabled'] ) || ! empty( $c['objectcache.enabled'] ) ) {
			$this->unsupported( __( 'Database and object cache', 'perdita-core' ), true, __( 'Perdita has a page cache only, with no database or object cache.', 'perdita-core' ) );
		}

		if ( ! empty( $c['cdn.enabled'] ) ) {
			$engine = (string) self::val( $c, 'cdn.engine' );
			$keys   = array(
				'mirror'    => 'cdn.mirror.domain',
				'cf'        => 'cdn.cf.cname',
				'cf2'       => 'cdn.cf2.cname',
				's3'        => 'cdn.s3.cname',
				'azure'     => 'cdn.azure.cname',
				'ftp'       => 'cdn.ftp.domain',
				'rscf'      => 'cdn.rscf.cname',
				'bunnycdn'  => 'cdn.bunnycdn.cdn_hostname',
			);
			$raw    = isset( $keys[ $engine ], $c[ $keys[ $engine ] ] ) ? $c[ $keys[ $engine ] ] : '';
			$first  = is_array( $raw ) ? (string) reset( $raw ) : (string) $raw;
			$host   = self::cdn_host_from( $first );
			if ( '' !== $host ) {
				$this->offer( __( 'CDN', 'perdita-core' ), true, 'cdn_enabled', true, __( 'Rewrite asset URLs to a CDN', 'perdita-core' ) );
				$this->offer( __( 'CDN hostname', 'perdita-core' ), $first, 'cdn_host', $host, __( 'CDN host', 'perdita-core' ) );
				if ( is_array( $raw ) && count( $raw ) > 1 ) {
					$this->unsupported( __( 'More CDN hostnames', 'perdita-core' ), array_slice( $raw, 1 ), __( 'In Perdita, you can rewrite URLs to one CDN host.', 'perdita-core' ) );
				}
				$exclude = isset( $c['cdn.reject.files'] ) ? self::split_list( $c['cdn.reject.files'] ) : array();
				if ( $exclude ) {
					$this->offer( __( 'Rejected files', 'perdita-core' ), $exclude, 'cdn_exclude', $exclude, __( 'CDN exclusions', 'perdita-core' ) );
				}
			} else {
				$this->unsupported( __( 'CDN', 'perdita-core' ), $engine, __( 'With this CDN type, you push files to storage. In Perdita, you rewrite URLs to a pull host. Set the host on Perdita, Caching.', 'perdita-core' ) );
			}
		}
		return $this->finish();
	}
}
