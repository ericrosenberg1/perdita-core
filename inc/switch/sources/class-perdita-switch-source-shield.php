<?php
/**
 * Shield Security: one option, icwp_wpsf_opts_all, with its free values and
 * its Pro values, and the IP rules in its own tables.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Shield extends Perdita_Switch_Security_Source {

	public function id() {
		return 'shield-core';
	}

	public function label() {
		return 'Shield Security';
	}

	public function plugin_files() {
		return array( 'wp-simple-firewall/icwp-wpsf.php' );
	}

	/**
	 * Every stored option, the Pro value winning over the free one.
	 *
	 * @return array
	 */
	private static function opts() {
		$raw = get_site_option( 'icwp_wpsf_opts_all', array() );
		if ( ! is_array( $raw ) || empty( $raw['values'] ) || ! is_array( $raw['values'] ) ) {
			return array();
		}
		$raw  = self::scalars_only( $raw );
		$free = isset( $raw['values']['free'] ) && is_array( $raw['values']['free'] ) ? $raw['values']['free'] : array();
		$pro  = isset( $raw['values']['pro'] ) && is_array( $raw['values']['pro'] ) ? $raw['values']['pro'] : array();
		return array_merge( $free, $pro );
	}

	/**
	 * The manual IP rules, as addresses or blocks.
	 *
	 * @param string $type MW (bypass) or MB (block).
	 * @return string[]
	 */
	private static function ip_rules( $type ) {
		global $wpdb;
		$rules = self::table( array( 'icwp_wpsf_ip_rules' ) );
		$ips   = self::table( array( 'icwp_wpsf_ips' ) );
		if ( '' === $rules || '' === $ips ) {
			return array();
		}
		$deleted = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $rules, 'deleted_at' ) ) ? ' AND r.deleted_at = 0' : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the condition is a fixed string in this file.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT HEX(i.ip) AS h, r.cidr, r.is_range FROM %i r JOIN %i i ON i.id = r.ip_ref WHERE r.type = %s{$deleted} LIMIT 500", $rules, $ips, $type ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$bin = ctype_xdigit( (string) $row['h'] ) && 0 === strlen( $row['h'] ) % 2 ? hex2bin( $row['h'] ) : false;
			$ip  = false === $bin ? false : @inet_ntop( $bin );
			if ( false === $ip ) {
				continue;
			}
			$out[] = ! empty( $row['is_range'] ) && (int) $row['cidr'] > 0 ? $ip . '/' . (int) $row['cidr'] : $ip;
		}
		return $out;
	}

	public function has_settings() {
		return (bool) self::opts();
	}

	public function plan() {
		$this->begin();
		$o = self::opts();
		if ( ! $o ) {
			return $this->finish();
		}
		if ( isset( $o['global_enable_plugin_features'] ) && 'N' === $o['global_enable_plugin_features'] ) {
			$this->note( __( 'You had Shield\'s master switch off, so none of its protections were running. Nothing moves to Perdita.', 'perdita-core' ) );
			return $this->finish();
		}
		// Shield's defaults, for an option it never wrote.
		$o = array_merge(
			array(
				'block_dir_traversal'     => 'Y',
				'block_sql_queries'       => 'Y',
				'block_author_discovery'  => 'Y',
				'disable_file_editing'    => 'Y',
				'x_frame'                 => 'on_sameorigin',
				'x_xss_protect'           => 'Y',
				'x_content_type'          => 'Y',
				'track_loginfailed'       => 'transgression-single',
				'transgression_limit'     => 10,
				'auto_expire'             => 'hour',
				'enable_core_file_integrity_scan' => 'Y',
			),
			$o
		);
		$y = function ( $k ) use ( $o ) {
			return isset( $o[ $k ] ) && 'Y' === $o[ $k ];
		};

		// Shield blocks an address after enough offenses. A failed login adds 1 or 2 offenses, or blocks at once.
		$per   = array(
			'transgression-single' => 1,
			'transgression-double' => 2,
			'block'                => 0,
		);
		$track = (string) $o['track_loginfailed'];
		$limit = (int) $o['transgression_limit'];
		if ( isset( $per[ $track ] ) && $limit > 0 ) {
			$max = 0 === $per[ $track ] ? 1 : (int) ceil( $limit / $per[ $track ] );
			$this->offer_limit( __( 'Failed login offenses', 'perdita-core' ), true, $max );
			$mins = array(
				'hour'  => 60,
				'day'   => 1440,
				'week'  => 10080,
				'month' => 43200,
			);
			if ( isset( $mins[ $o['auto_expire'] ] ) ) {
				$this->offer_whole_minutes( __( 'Auto block expiration', 'perdita-core' ), $mins[ $o['auto_expire'] ], 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
			}
			$this->note( __( 'Shield counts offenses from several kinds of bad behavior, so Perdita\'s failed-login count is a close match, not an exact one.', 'perdita-core' ) );
		}
		$this->offer_allowlist( __( 'IP bypass list', 'perdita-core' ), self::ip_rules( 'MW' ) );

		$this->offer_flag( __( 'Disable XML-RPC', 'perdita-core' ), $y( 'disable_xmlrpc' ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		$this->offer_flag( __( 'Block username fishing', 'perdita-core' ), $y( 'block_author_discovery' ), 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
		$this->offer_flag( __( 'Anonymous REST API', 'perdita-core' ), $y( 'disable_anonymous_restapi' ), 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ), __( 'Perdita hides the users endpoint from logged-out visitors. Shield turns off the whole anonymous REST API.', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable file editing', 'perdita-core' ), $y( 'disable_file_editing' ), 'disable_file_edit', __( 'Turn off the file editor', 'perdita-core' ) );
		$this->offer_flag( __( 'WP core file scanner', 'perdita-core' ), $y( 'enable_core_file_integrity_scan' ), 'core_check', __( 'Daily core file check', 'perdita-core' ) );
		$headers = $y( 'x_xss_protect' ) || $y( 'x_content_type' ) || ( isset( $o['x_frame'] ) && 'off' !== $o['x_frame'] );
		$this->offer_flag( __( 'Security headers', 'perdita-core' ), $headers, 'headers', __( 'Send security headers', 'perdita-core' ), __( 'Perdita sends one fixed set of headers: X-Frame-Options SAMEORIGIN, nosniff, a referrer policy, a permissions policy, and a report-only Content-Security-Policy. Shield\'s own frame and referrer values don\'t carry over.', 'perdita-core' ) );

		$cats = array();
		if ( $y( 'block_dir_traversal' ) ) {
			$cats['traversal'] = __( 'Directory traversal', 'perdita-core' );
		}
		if ( $y( 'block_sql_queries' ) ) {
			$cats['sqli'] = __( 'SQL queries', 'perdita-core' );
		}
		if ( $y( 'block_php_code' ) ) {
			$cats['rfi'] = __( 'PHP code', 'perdita-core' );
		}
		$this->offer_firewall( __( 'Firewall', 'perdita-core' ), (bool) $cats, $cats );
		if ( $y( 'block_aggressive' ) ) {
			$this->unsupported( __( 'Aggressive scan', 'perdita-core' ), true, __( 'Perdita\'s rules come in fixed categories, with no aggressive set.', 'perdita-core' ) );
		}
		if ( ! empty( $o['page_params_whitelist'] ) ) {
			$this->unsupported( __( 'Firewall exclusions', 'perdita-core' ), $o['page_params_whitelist'], __( 'You add exclusions in Perdita\'s firewall log with the Allow button.', 'perdita-core' ) );
		}

		// Only Perdita Pro covers these.
		$block = self::ip_rules( 'MB' );
		if ( $block ) {
			$this->pro_row( 'blocklist', __( 'Manual IP block rules', 'perdita-core' ), count( $block ) );
		}
		if ( $y( 'enable_google_authenticator' ) || $y( 'enable_email_authentication' ) || $y( 'enable_yubikey' ) || $y( 'enable_passkeys' ) ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), true );
		}
		if ( ! empty( $o['rename_wplogin_path'] ) ) {
			$this->pro_row( 'login_url', __( 'Rename WP login path', 'perdita-core' ), $o['rename_wplogin_path'] );
		}
		if ( $y( 'enable_limiter' ) ) {
			$this->pro_row( 'rate', __( 'Traffic rate limiter', 'perdita-core' ), isset( $o['limit_requests'] ) ? $o['limit_requests'] . ' / ' . ( isset( $o['limit_time_span'] ) ? $o['limit_time_span'] : '' ) : true );
		}
		if ( $y( 'enable_logger' ) ) {
			$this->pro_row( 'audit', __( 'Activity log', 'perdita-core' ), true );
		}
		if ( ! empty( $o['visitor_address_source'] ) && 'AUTO_DETECT_IP' !== $o['visitor_address_source'] ) {
			$this->unsupported( __( 'Visitor IP address source', 'perdita-core' ), $o['visitor_address_source'], __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
