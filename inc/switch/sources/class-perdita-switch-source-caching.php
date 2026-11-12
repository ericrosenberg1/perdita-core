<?php
/**
 * Shared parts of the page cache sources: leftovers after deactivation and
 * the translations every cache plugin needs (seconds to hours, regular
 * expressions to plain paths).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_Caching_Source extends Perdita_Switch_Source {

	/**
	 * @return string
	 */
	public function module() {
		return 'caching';
	}

	/**
	 * Text that marks this plugin's advanced-cache.php.
	 *
	 * @return string[]
	 */
	public function dropin_signatures() {
		return array();
	}

	/**
	 * This plugin's .htaccess marker names.
	 *
	 * @return string[]
	 */
	public function htaccess_markers() {
		return array();
	}

	/**
	 * @return array[]
	 */
	public function leftovers() {
		return Perdita_Switch_Leftovers::find( $this, $this->dropin_signatures(), $this->htaccess_markers() );
	}

	/**
	 * Remove one leftover.
	 *
	 * @param string $item Item id from leftovers().
	 * @return array ok, message.
	 */
	public function fix_leftover( $item ) {
		return Perdita_Switch_Leftovers::fix( $this, $item, $this->dropin_signatures(), $this->htaccess_markers() );
	}

	/**
	 * Take Perdita's own drop-in out of the way before the plugin comes back.
	 * These plugins refuse to write advanced-cache.php over another one, and
	 * the caching module removes its drop-in as soon as another page cache is
	 * active, so without this the site ends up with no drop-in at all.
	 */
	public function before_reactivate() {
		if ( ! class_exists( 'Perdita_Caching' ) ) {
			return;
		}
		if ( in_array( Perdita_Caching::dropin_state(), array( 'ours', 'stale' ), true ) ) {
			$flag = Perdita_Caching::cache_dir() . 'early-serve.on';
			if ( is_file( $flag ) ) {
				wp_delete_file( $flag );
			}
			wp_delete_file( Perdita_Caching::dropin_path() );
		}
	}

	/* ---------- shared offers ---------- */

	/**
	 * The on switch, and early serving with it: every one of these plugins
	 * answers before WordPress loads, so Perdita does the same.
	 *
	 * @param string $setting Setting name.
	 * @param bool   $on      Whether the cache was on.
	 */
	protected function offer_enabled( $setting, $on ) {
		$this->offer( $setting, $on, 'enabled', (bool) $on, __( 'Page cache', 'perdita-core' ) );
		if ( $on ) {
			$this->offer(
				$setting,
				$on,
				'early_serve',
				true,
				__( 'Serve before WordPress loads', 'perdita-core' ),
				/* translators: %s: plugin name. */
				array( 'note' => sprintf( __( 'You had %s answering cached pages before WordPress loaded, so you get the same from Perdita.', 'perdita-core' ), $this->label() ) )
			);
		}
	}

	/**
	 * A lifetime in seconds, as whole hours.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $seconds Seconds.
	 */
	protected function offer_ttl( $setting, $seconds ) {
		$seconds = (int) $seconds;
		/* translators: %d: seconds. */
		$found = sprintf( _n( '%d second', '%d seconds', $seconds, 'perdita-core' ), $seconds );
		if ( $seconds <= 0 ) {
			$this->unsupported( $setting, $found, __( 'Pages never expired in that plugin. In Perdita, every cached page expires, so you keep the 10-hour lifetime unless you change the setting.', 'perdita-core' ) );
			return;
		}
		$hours = max( 1, (int) round( $seconds / HOUR_IN_SECONDS ) );
		$opts  = array( 'refused' => __( 'In Perdita, you can keep a page for a year at most.', 'perdita-core' ) );
		if ( $hours * HOUR_IN_SECONDS !== $seconds ) {
			$opts['note'] = __( 'You set the lifetime in whole hours in Perdita, so you get the nearest whole hour.', 'perdita-core' );
		}
		$this->offer( $setting, $found, 'ttl_hours', $hours, __( 'Cache lifetime (hours)', 'perdita-core' ), $opts );
	}

	/**
	 * A garbage collection interval in seconds, as Perdita's schedule.
	 *
	 * @param string $setting Setting name.
	 * @param int    $seconds Seconds, 0 for off.
	 */
	protected function offer_gc_seconds( $setting, $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			$schedule = 'off';
		} elseif ( $seconds <= HOUR_IN_SECONDS ) {
			$schedule = 'hourly';
		} elseif ( $seconds <= 12 * HOUR_IN_SECONDS ) {
			$schedule = 'twicedaily';
		} else {
			$schedule = 'daily';
		}
		/* translators: %d: seconds. */
		$found = $seconds > 0 ? sprintf( _n( 'every %d second', 'every %d seconds', $seconds, 'perdita-core' ), $seconds ) : __( 'off', 'perdita-core' );
		$this->offer( $setting, $found, 'gc_schedule', $schedule, __( 'Sweep expired files', 'perdita-core' ), array( 'note' => __( 'In Perdita, you can sweep hourly, twice a day, or daily, so you get the nearest of the three.', 'perdita-core' ) ) );
	}

	/**
	 * How often the whole site is preloaded again, from seconds to Perdita's
	 * whole hours, rounded up.
	 *
	 * @param string $setting Setting name.
	 * @param int    $seconds Seconds, 0 for never.
	 */
	protected function offer_interval( $setting, $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = $seconds > 0 ? (int) ceil( $seconds / HOUR_IN_SECONDS ) : 0;
		/* translators: %d: minutes. */
		$found = $seconds > 0 ? sprintf( _n( '%d minute', '%d minutes', (int) round( $seconds / 60 ), 'perdita-core' ), (int) round( $seconds / 60 ) ) : __( 'never', 'perdita-core' );
		$this->offer(
			$setting,
			$found,
			'preload_interval_hours',
			$hours,
			__( 'Rebuild everything every (hours)', 'perdita-core' ),
			array(
				'note'    => $seconds % HOUR_IN_SECONDS ? __( 'You set this in whole hours in Perdita, so you get the number rounded up to a whole hour.', 'perdita-core' ) : '',
				'refused' => __( 'In Perdita, you can set 720 hours at most.', 'perdita-core' ),
			)
		);
	}

	/**
	 * Excluded addresses written as regular expression fragments. A plain
	 * path goes in. A real pattern can't, since Perdita matches paths.
	 *
	 * @param string   $setting  Setting name.
	 * @param string[] $patterns Patterns.
	 * @param string[] $ignore   Default patterns that need no import.
	 */
	protected function offer_path_patterns( $setting, array $patterns, array $ignore = array() ) {
		$paths = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_scalar( $pattern ) || '' === trim( (string) $pattern ) ) {
				continue;
			}
			$pattern = trim( (string) $pattern );
			if ( in_array( $pattern, $ignore, true ) ) {
				continue;
			}
			$path = self::literal_path( $pattern );
			if ( null === $path ) {
				$this->skip( $setting, $pattern, __( 'This is a pattern, and in Perdita you exclude plain paths. Add the paths the pattern covers on Perdita, Caching.', 'perdita-core' ), __( 'Never cache these paths', 'perdita-core' ) );
				continue;
			}
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$this->skip( $setting, $pattern, __( 'This matched anywhere in an address. In Perdita, you match a path from its start, so add the full path on Perdita, Caching.', 'perdita-core' ), __( 'Never cache these paths', 'perdita-core' ) );
				continue;
			}
			$paths[] = $path;
		}
		if ( $paths ) {
			$this->offer( $setting, $paths, 'exclude_paths', $paths, __( 'Never cache these paths', 'perdita-core' ) );
		}
	}

	/**
	 * Cookie names written as regular expression fragments.
	 *
	 * @param string   $setting  Setting name.
	 * @param string[] $patterns Patterns.
	 */
	protected function offer_cookie_patterns( $setting, array $patterns ) {
		$names = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_scalar( $pattern ) || '' === trim( (string) $pattern ) ) {
				continue;
			}
			$name = self::literal_path( trim( (string) $pattern ) );
			if ( null === $name || '' === $name ) {
				$this->skip( $setting, $pattern, __( 'This is a pattern, and in Perdita you match part of a cookie name. Add the name on Perdita, Caching.', 'perdita-core' ), __( 'Skip the cache for these cookies', 'perdita-core' ) );
				continue;
			}
			$names[] = $name;
		}
		if ( $names ) {
			$this->offer( $setting, $names, 'reject_cookies', $names, __( 'Skip the cache for these cookies', 'perdita-core' ) );
		}
	}

	/**
	 * A regular expression fragment with no pattern in it, as plain text,
	 * or null. Escaped dots, slashes and dashes count as plain, and a
	 * leading ^ or trailing $ is dropped.
	 *
	 * @param string $pattern   Fragment.
	 * @param bool   $delimited Whether it carries PCRE delimiters (/x/i).
	 * @return string|null
	 */
	public static function literal_path( $pattern, $delimited = false ) {
		$p = (string) $pattern;
		if ( strlen( $p ) > 200 ) {
			return null;
		}
		if ( $delimited ) {
			if ( ! preg_match( '#^([/~\#!|]).*\1[a-z]*$#is', $p, $m ) || strlen( $p ) < 3 ) {
				return null;
			}
			$p = preg_replace( '#^' . preg_quote( $m[1], '#' ) . '|' . preg_quote( $m[1], '#' ) . '[a-z]*$#i', '', $p );
		}
		$p = preg_replace( '/^\^|\$$/', '', (string) $p );
		$p = preg_replace( '/\\\\([.\\/\\-_])/', '$1', (string) $p );
		if ( preg_match( '/[\\\\*+?()\[\]{}|^$]/', (string) $p ) ) {
			return null;
		}
		// An unescaped dot means any character, but a dot in a path is
		// almost always a file extension. Only plain path text passes.
		if ( ! preg_match( '#^[A-Za-z0-9/._~%-]*$#', (string) $p ) ) {
			return null;
		}
		return (string) $p;
	}

	/**
	 * A CDN URL as a bare host, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function cdn_host_from( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '//' ) ) {
			$url = '//' . $url;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( strtolower( $host ) === strtolower( (string) $site ) ) {
			return '';
		}
		return strtolower( $host ) . ( $port ? ':' . (int) $port : '' );
	}

	/**
	 * A comma or newline separated list as an array.
	 *
	 * @param mixed $raw Raw.
	 * @return string[]
	 */
	protected static function split_list( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_filter( $raw, 'is_scalar' ) );
		}
		$out = array();
		foreach ( preg_split( '/[,\r\n]+/', is_scalar( $raw ) ? (string) $raw : '' ) as $item ) {
			$item = trim( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
