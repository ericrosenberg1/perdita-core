<?php
/**
 * Shared parts of the security sources: the login limit, the allowlist, the
 * protections, the firewall, and the rows for what only Perdita Pro covers.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_Security_Source extends Perdita_Switch_Source {

	public function module() {
		return 'security';
	}

	/**
	 * One sentence for the card when Perdita Pro covers what this import
	 * can't.
	 *
	 * @return string
	 */
	public function pro_note() {
		return __( 'With Perdita Pro, you can set up the IP block list, country blocking, two-factor login, and the audit log in Security Pro.', 'perdita-core' );
	}

	/* ---------- the login limit ---------- */

	/**
	 * A duration in seconds as Perdita's whole minutes.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $seconds Seconds.
	 * @param string $key     login_window or login_lockout.
	 * @param string $target  Perdita setting name.
	 */
	protected function offer_minutes( $setting, $seconds, $key, $target ) {
		$seconds = (int) $seconds;
		$minutes = (int) round( $seconds / MINUTE_IN_SECONDS );
		/* translators: %d: seconds. */
		$found = sprintf( _n( '%d second', '%d seconds', $seconds, 'perdita-core' ), $seconds );
		$this->offer(
			$setting,
			$found,
			$key,
			$minutes,
			$target,
			array(
				'note'    => $seconds % MINUTE_IN_SECONDS ? __( 'You set this in whole minutes in Perdita, so you get the nearest whole minute.', 'perdita-core' ) : '',
				'refused' => __( 'In Perdita, you can set 1 minute to 24 hours.', 'perdita-core' ),
			)
		);
	}

	/**
	 * A duration in whole minutes.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $minutes Minutes.
	 * @param string $key     login_window or login_lockout.
	 * @param string $target  Perdita setting name.
	 */
	protected function offer_whole_minutes( $setting, $minutes, $key, $target ) {
		$minutes = (int) $minutes;
		/* translators: %d: minutes. */
		$found = sprintf( _n( '%d minute', '%d minutes', $minutes, 'perdita-core' ), $minutes );
		$this->offer( $setting, $found, $key, $minutes, $target, array( 'refused' => __( 'In Perdita, you can set 1 minute to 24 hours.', 'perdita-core' ) ) );
	}

	/**
	 * Offer the switch and the attempt count.
	 *
	 * @param string $setting Setting name.
	 * @param bool   $on      Whether login limiting was on.
	 * @param mixed  $max     Allowed attempts, or null.
	 */
	protected function offer_limit( $setting, $on, $max ) {
		$this->offer( $setting, (bool) $on, 'login_limit', (bool) $on, __( 'Limit login attempts', 'perdita-core' ) );
		if ( null !== $max ) {
			$this->offer(
				__( 'Allowed retries', 'perdita-core' ),
				$max,
				'login_max',
				(int) $max,
				__( 'Failed logins before a lockout', 'perdita-core' ),
				array( 'refused' => __( 'In Perdita, you can set 1 to 100 attempts.', 'perdita-core' ) )
			);
		}
	}

	/**
	 * A longer lockout once an address has been locked out several times.
	 *
	 * @param string $setting  Setting name.
	 * @param int    $after    Lockouts before the long one.
	 * @param int    $seconds  Length of the long lockout in seconds.
	 * @param string $note     A sentence for the switch row.
	 */
	protected function offer_escalation( $setting, $after, $seconds, $note = '' ) {
		$this->offer( $setting, true, 'login_long_lockout', true, __( 'Escalating lockouts', 'perdita-core' ), array( 'note' => $note ) );
		$this->offer(
			__( 'Lockouts before the longer one', 'perdita-core' ),
			(int) $after,
			'login_long_after',
			(int) $after,
			__( 'Lockouts before the long one', 'perdita-core' ),
			array( 'refused' => __( 'In Perdita, you can set 1 to 100 lockouts.', 'perdita-core' ) )
		);
		$hours = (int) ceil( max( 0, (int) $seconds ) / HOUR_IN_SECONDS );
		/* translators: %d: seconds. */
		$found = sprintf( _n( '%d second', '%d seconds', (int) $seconds, 'perdita-core' ), (int) $seconds );
		$this->offer(
			$setting,
			$found,
			'login_long_hours',
			$hours,
			__( 'Long lockout (hours)', 'perdita-core' ),
			array(
				'note'    => (int) $seconds % HOUR_IN_SECONDS ? __( 'You set this in whole hours in Perdita, so you get the next whole hour.', 'perdita-core' ) : '',
				'refused' => __( 'In Perdita, you can set 1 hour to 30 days.', 'perdita-core' ),
			)
		);
	}

	/* ---------- the allowlist ---------- */

	/**
	 * Cut an exact CIDR block out of a start and end address.
	 *
	 * @param string $start First address.
	 * @param string $end   Last address.
	 * @return string The address, or the block ("10.0.0.0/8"), or '' when the range isn't one exact block.
	 */
	public static function range_to_cidr( $start, $end ) {
		$a = @inet_pton( trim( (string) $start ) );
		$b = @inet_pton( trim( (string) $end ) );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return '';
		}
		if ( strcmp( $a, $b ) > 0 ) {
			$swap = $a;
			$a    = $b;
			$b    = $swap;
		}
		$len    = strlen( $a ) * 8;
		$bit    = static function ( $bin, $i ) {
			return ( ord( $bin[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
		};
		$prefix = 0;
		while ( $prefix < $len && $bit( $a, $prefix ) === $bit( $b, $prefix ) ) {
			++$prefix;
		}
		for ( $i = $prefix; $i < $len; $i++ ) {
			if ( 0 !== $bit( $a, $i ) || 1 !== $bit( $b, $i ) ) {
				return '';
			}
		}
		$ip = inet_ntop( $a );
		if ( false === $ip ) {
			return '';
		}
		return $prefix === $len ? $ip : $ip . '/' . $prefix;
	}

	/**
	 * Turn a trailing-wildcard address ("203.0.113.*") into a block.
	 *
	 * @param string $entry Entry.
	 * @return string The block, or ''.
	 */
	public static function wildcard_to_cidr( $entry ) {
		$parts = explode( '.', trim( (string) $entry ) );
		if ( count( $parts ) < 2 || count( $parts ) > 4 ) {
			return '';
		}
		$fixed = array();
		$star  = false;
		foreach ( $parts as $part ) {
			if ( '*' === $part ) {
				$star = true;
				continue;
			}
			if ( $star || ! ctype_digit( $part ) || (int) $part > 255 ) {
				return '';
			}
			$fixed[] = (int) $part;
		}
		if ( ! $star || ! $fixed || count( $fixed ) > 3 ) {
			return '';
		}
		$bits = count( $fixed ) * 8;
		while ( count( $fixed ) < 4 ) {
			$fixed[] = 0;
		}
		return implode( '.', $fixed ) . '/' . $bits;
	}

	/**
	 * One allowlist entry as an address or block Perdita keeps, or '' when
	 * it isn't one exact address or block. Takes an address, a CIDR block, a
	 * start-end range that is exactly one block, or a trailing wildcard.
	 *
	 * @param string $entry Entry.
	 * @return string
	 */
	public static function allow_entry( $entry ) {
		$entry = is_scalar( $entry ) ? trim( (string) $entry ) : '';
		if ( '' === $entry ) {
			return '';
		}
		if ( false !== strpos( $entry, '*' ) ) {
			$entry = self::wildcard_to_cidr( $entry );
		} elseif ( false === strpos( $entry, '/' ) && 1 === substr_count( $entry, '-' ) ) {
			list( $start, $end ) = explode( '-', $entry, 2 );
			$entry               = self::range_to_cidr( $start, $end );
		}
		return '' === $entry ? '' : Perdita_Security::clean_allow_entry( $entry );
	}

	/**
	 * Allowlisted addresses. Exact IPs and CIDR blocks go in as they are,
	 * start-end ranges and trailing wildcards go in when they are exactly one
	 * block, and any other range is skipped with a reason. A source may call
	 * this once per list: the lists add up in one allowlist.
	 *
	 * @param string   $setting Setting name.
	 * @param string[] $entries Entries.
	 */
	protected function offer_allowlist( $setting, array $entries ) {
		$ips       = array();
		$converted = array();
		$target    = __( 'Never lock out these IPs', 'perdita-core' );
		foreach ( array_slice( $entries, 0, 500 ) as $entry ) {
			$entry = is_scalar( $entry ) ? trim( (string) $entry ) : '';
			if ( '' === $entry ) {
				continue;
			}
			$clean = self::allow_entry( $entry );
			if ( '' === $clean ) {
				$this->skip( $setting, $entry, __( 'Perdita allowlists exact addresses and CIDR blocks. This entry isn\'t either one, so you can add it as separate blocks on Perdita, Security.', 'perdita-core' ), $target );
				continue;
			}
			if ( $clean !== $entry ) {
				$converted[] = $entry . ' = ' . $clean;
			}
			$ips[] = $clean;
		}
		if ( ! $ips ) {
			return;
		}
		$merged = $ips;
		if ( ! empty( $this->building['patch']['ip_allowlist'] ) ) {
			$merged = array_values( array_unique( array_merge( $this->building['patch']['ip_allowlist'], $ips ) ) );
		}
		$note = $converted ? sprintf(
			/* translators: %s: converted entries, such as "10.0.0.0-10.255.255.255 = 10.0.0.0/8". */
			__( 'Converted to CIDR blocks: %s.', 'perdita-core' ),
			implode( ', ', array_slice( $converted, 0, 10 ) )
		) : '';
		if ( $this->offer( $setting, array_values( array_unique( $ips ) ), 'ip_allowlist', $merged, $target, array( 'note' => $note ) ) ) {
			$last = count( $this->building['rows'] ) - 1;
			if ( $last >= 0 && 'import' === $this->building['rows'][ $last ]['status'] ) {
				$this->building['rows'][ $last ]['value'] = self::show( array_values( array_unique( $ips ) ) );
			}
		}
	}

	/* ---------- protections ---------- */

	/**
	 * Offer a switch for something the other plugin had on. A switch that
	 * was off adds no row, so an import never turns off something you set in
	 * Perdita.
	 *
	 * @param string $setting Setting name in the other plugin.
	 * @param bool   $on      Whether it was on.
	 * @param string $key     Perdita key.
	 * @param string $target  Perdita setting name.
	 * @param string $note    A sentence for the row.
	 * @return bool Whether the switch goes in.
	 */
	protected function offer_flag( $setting, $on, $key, $target, $note = '' ) {
		if ( ! $on ) {
			return false;
		}
		// The REST restriction is a choice (off or users), not a switch.
		return $this->offer( $setting, true, $key, 'restrict_rest' === $key ? 'users' : true, $target, array( 'note' => $note ) );
	}

	/**
	 * Perdita's firewall, offered in monitor mode with the categories that
	 * match what the other plugin's firewall had on. A firewall already on
	 * in Perdita stays as it is.
	 *
	 * @param string   $setting Name of the other plugin's firewall setting.
	 * @param bool     $on      Whether its firewall was on.
	 * @param string[] $cats    Perdita category => name of the matching setting in the other plugin.
	 * @param string   $why     A sentence for the firewall row, or ''.
	 */
	protected function offer_firewall( $setting, $on, array $cats, $why = '' ) {
		if ( ! $on ) {
			return;
		}
		$now = Perdita_Security::get_settings();
		if ( ! empty( $now['firewall'] ) ) {
			$this->note( __( 'Perdita\'s firewall is already on, so the import leaves its mode and rules as you set them.', 'perdita-core' ) );
			return;
		}
		$monitor = __( 'The firewall starts in monitor mode. It logs what it would block and blocks nothing. You can switch it to block on Perdita, Security after a few days of clean logs.', 'perdita-core' );
		$this->offer( $setting, true, 'firewall', true, __( 'Firewall', 'perdita-core' ), array( 'note' => trim( $why . ' ' . $monitor ) ) );
		$this->offer( $setting, 'monitor', 'firewall_mode', 'monitor', __( 'Firewall mode', 'perdita-core' ) );
		$names = array(
			'sqli'       => __( 'Block SQL injection', 'perdita-core' ),
			'xss'        => __( 'Block cross-site scripting', 'perdita-core' ),
			'traversal'  => __( 'Block path traversal and file inclusion', 'perdita-core' ),
			'rfi'        => __( 'Block remote file inclusion', 'perdita-core' ),
			'php_upload' => __( 'Block PHP uploads', 'perdita-core' ),
			'bad_bots'   => __( 'Block scanner user agents', 'perdita-core' ),
			'probes'     => __( 'Block probes for .env, .git, and debug.log', 'perdita-core' ),
			'xmlrpc'     => __( 'Block xmlrpc.php early', 'perdita-core' ),
		);
		foreach ( $cats as $cat => $from ) {
			$this->offer( $from, true, 'fw_rules.' . $cat, true, isset( $names[ $cat ] ) ? $names[ $cat ] : $cat );
		}
	}

	/**
	 * A row for something only Perdita Pro covers.
	 *
	 * @param string $what    blocklist, country, 2fa, rules, login_url, audit, rate, usernames, breach, or alerts.
	 * @param string $setting Setting name in the other plugin.
	 * @param mixed  $found   Value, for display.
	 */
	protected function pro_row( $what, $setting, $found ) {
		$why = array(
			'blocklist' => __( 'Perdita Pro keeps an IP block list, so you can add these addresses there.', 'perdita-core' ),
			'country'   => __( 'Perdita Pro blocks countries.', 'perdita-core' ),
			'2fa'       => __( 'Perdita Pro adds two-factor login. Each user sets it up again, because you can\'t copy a second factor safely.', 'perdita-core' ),
			'rules'     => __( 'Perdita Pro takes custom firewall rules, so you can add this one there.', 'perdita-core' ),
			'login_url' => __( 'Perdita Pro can hide the login URL.', 'perdita-core' ),
			'audit'     => __( 'Perdita Pro keeps an audit log.', 'perdita-core' ),
			'rate'      => __( 'Perdita Pro limits requests per minute.', 'perdita-core' ),
			'usernames' => __( 'Perdita Pro blocks login attempts with usernames you list.', 'perdita-core' ),
			'breach'    => __( 'Perdita Pro checks for breached passwords. You can import that from its own card.', 'perdita-core' ),
			'alerts'    => __( 'Perdita Pro sends email alerts for the events you pick.', 'perdita-core' ),
		);
		$this->unsupported( $setting, $found, isset( $why[ $what ] ) ? $why[ $what ] : '' );
	}

	/**
	 * Cloudflare Turnstile on the login screens, when the other plugin ran
	 * it there. Perdita reuses the Turnstile keys from its Forms settings,
	 * and this import never copies a key.
	 *
	 * @param string $setting Setting name in the other plugin.
	 */
	protected function offer_turnstile( $setting ) {
		$this->offer( $setting, true, 'login_turnstile', true, __( 'Cloudflare Turnstile on login', 'perdita-core' ), array( 'note' => __( 'You add your Turnstile keys in Perdita, Forms. The import doesn\'t copy keys, and the check stays off until the keys are there.', 'perdita-core' ) ) );
	}

	/* ---------- reading the other plugin's data ---------- */

	/**
	 * A site option that holds an array, or a JSON string of one, as an array
	 * with every object dropped.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	protected static function site_json_option( $name ) {
		$raw = get_site_option( $name, array() );
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( strlen( $raw ) > 2000000 ) {
				return array();
			}
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? self::scalars_only( $raw ) : array();
	}

	/**
	 * A PHP file that holds data after a header, as that data, without ever
	 * including the file. Reads at most 1 MB.
	 *
	 * @param string $path File.
	 * @param string $mode "line" cuts the first line, "comment" cuts through the header comment that ends with a line holding "*" and "/".
	 * @return string The text after the header, or ''.
	 */
	protected static function php_data_file( $path, $mode ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) || filesize( $path ) > 1048576 ) {
			return '';
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, read as text and never included.
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, '<?php' ) ) {
			return '';
		}
		if ( 'line' === $mode ) {
			$cut = strpos( $raw, "\n" );
			return false === $cut ? '' : substr( $raw, $cut + 1 );
		}
		$cut = strpos( $raw, "\n */\n" );
		return false === $cut ? '' : substr( $raw, $cut + 5 );
	}

	/**
	 * A table of the other plugin, by its possible names.
	 *
	 * @param string[] $names Names without the prefix.
	 * @return string The full table name, or ''.
	 */
	protected static function table( array $names ) {
		global $wpdb;
		foreach ( $names as $name ) {
			foreach ( array_unique( array( $wpdb->base_prefix, $wpdb->prefix ) ) as $prefix ) {
				$t = $prefix . $name;
				if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					return $t;
				}
			}
		}
		return '';
	}

	/**
	 * The number of rows in one of the other plugin's tables.
	 *
	 * @param string $table Full table name from table().
	 * @param string $where A fixed condition, never user input.
	 * @return int
	 */
	protected static function count_rows( $table, $where = '1=1' ) {
		global $wpdb;
		if ( '' === $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name came from SHOW TABLES and the condition is a fixed string in this file.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );
	}

	/**
	 * Lines or comma-separated items in a text value or an array.
	 *
	 * @param mixed $raw Value.
	 * @return string[]
	 */
	protected static function items( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', array_filter( $raw, 'is_scalar' ) ), 'strlen' ) );
		}
		if ( ! is_scalar( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $raw ) ), 'strlen' ) );
	}
}
