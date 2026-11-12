<?php
/**
 * Cache Enabler. Its settings are the cache_enabler array option, and its
 * exclusions are each one delimited regular expression.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Cache_Enabler extends Perdita_Switch_Caching_Source {

	public function id() {
		return 'cache-enabler';
	}

	public function label() {
		return 'Cache Enabler';
	}

	public function plugin_files() {
		return array( 'cache-enabler/cache-enabler.php' );
	}

	public function dropin_signatures() {
		return array( 'Cache Enabler', 'cache-enabler' );
	}

	public function has_settings() {
		return is_array( get_option( 'cache_enabler', false ) );
	}

	public function plan() {
		$this->begin();
		$c = self::array_option( 'cache_enabler' );
		if ( ! $c ) {
			return $this->finish();
		}
		// Cache Enabler has no off switch: while it's active, it caches.
		$this->offer_enabled( __( 'Cache Enabler', 'perdita-core' ), true );
		if ( ! empty( $c['cache_expires'] ) && isset( $c['cache_expiry_time'] ) ) {
			$this->offer_ttl( __( 'Cache expiry', 'perdita-core' ), HOUR_IN_SECONDS * (int) $c['cache_expiry_time'] );
		} elseif ( isset( $c['cache_expires'] ) ) {
			$this->offer_ttl( __( 'Cache expiry', 'perdita-core' ), 0 );
		}
		if ( isset( $c['mobile_cache'] ) ) {
			$mobile = ! empty( $c['mobile_cache'] );
			$this->offer( __( 'Separate mobile cache', 'perdita-core' ), $mobile, 'mobile_cache', $mobile, __( 'Separate copy for phones', 'perdita-core' ) );
		}
		if ( isset( $c['compress_cache'] ) ) {
			$gzip = ! empty( $c['compress_cache'] );
			$this->offer( __( 'Pre-compress cached pages', 'perdita-core' ), $gzip, 'gzip', $gzip, __( 'Keep gzip copies', 'perdita-core' ) );
		}
		$this->plan_regex( 'excluded_page_paths', __( 'Excluded page paths', 'perdita-core' ), 'exclude_paths' );
		$this->plan_regex( 'excluded_cookies', __( 'Excluded cookies', 'perdita-core' ), 'reject_cookies' );
		if ( ! empty( $c['excluded_query_strings'] ) ) {
			$this->unsupported( __( 'Excluded query strings', 'perdita-core' ), $c['excluded_query_strings'], __( 'In Perdita, you never cache an address with an unknown query string.', 'perdita-core' ) );
		}
		if ( ! empty( $c['minify_html'] ) ) {
			$this->unsupported( __( 'Minify HTML', 'perdita-core' ), true, __( 'You can\'t minify HTML with Perdita\'s page cache.', 'perdita-core' ) );
		}
		return $this->finish();
	}

	/**
	 * One regular expression made of alternatives, as a list of plain paths
	 * or cookie names. The whole setting is skipped unless every part is
	 * plain text.
	 *
	 * @param string $key     Cache Enabler key.
	 * @param string $setting Setting name.
	 * @param string $target  exclude_paths or reject_cookies.
	 */
	private function plan_regex( $key, $setting, $target ) {
		$c   = self::array_option( 'cache_enabler' );
		$raw = isset( $c[ $key ] ) && is_string( $c[ $key ] ) ? trim( $c[ $key ] ) : '';
		if ( '' === $raw ) {
			return;
		}
		$label = 'exclude_paths' === $target ? __( 'Never cache these paths', 'perdita-core' ) : __( 'Skip the cache for these cookies', 'perdita-core' );
		$whole = self::literal_path( $raw, true );
		$inner = null;
		if ( null === $whole && preg_match( '#^([/~\#!|])\^?\((.*)\)\$?\1[a-z]*$#is', $raw, $m ) ) {
			$inner = explode( '|', $m[2] );
		} elseif ( null !== $whole ) {
			$inner = array( $whole );
		}
		$items = array();
		foreach ( (array) $inner as $part ) {
			$plain = self::literal_path( $part );
			if ( null === $plain || '' === $plain ) {
				$items = array();
				break;
			}
			$items[] = $plain;
		}
		if ( ! $items ) {
			$this->skip( $setting, $raw, __( 'This pattern has no plain entries to import. Add the paths the pattern covers on Perdita, Caching.', 'perdita-core' ), $label );
			return;
		}
		if ( 'exclude_paths' === $target ) {
			$this->offer_path_patterns( $setting, $items );
		} else {
			$this->offer( $setting, $items, 'reject_cookies', $items, $label );
		}
	}
}
