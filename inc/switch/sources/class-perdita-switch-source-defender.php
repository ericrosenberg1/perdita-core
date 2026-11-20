<?php
/**
 * Defender Security: one wd_* site option per module, each a JSON string.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Defender extends Perdita_Switch_Security_Source {

	public function id() {
		return 'defender-core';
	}

	public function label() {
		return 'Defender Security';
	}

	public function plugin_files() {
		return array( 'defender-security/wp-defender.php', 'wp-defender/wp-defender.php' );
	}

	public function has_settings() {
		return (bool) self::site_json_option( 'wd_login_lockout_settings' ) || (bool) self::site_json_option( 'wd_blacklist_lockout_settings' ) || (bool) self::site_json_option( 'wd_security_headers_settings' );
	}

	/**
	 * A length with its unit, in seconds.
	 *
	 * @param mixed  $n    Number.
	 * @param string $unit seconds, minutes, or hours.
	 * @return int
	 */
	private static function seconds( $n, $unit ) {
		$mult = array(
			'seconds' => 1,
			'minutes' => MINUTE_IN_SECONDS,
			'hours'   => HOUR_IN_SECONDS,
		);
		return (int) $n * ( isset( $mult[ $unit ] ) ? $mult[ $unit ] : 1 );
	}

	/**
	 * "30 days" or "1 year" as seconds, or 0.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function duration( $text ) {
		if ( ! preg_match( '/^\s*(\d+)\s*(day|week|month|year)s?\s*$/i', (string) $text, $m ) ) {
			return 0;
		}
		$unit = array(
			'day'   => DAY_IN_SECONDS,
			'week'  => WEEK_IN_SECONDS,
			'month' => 30 * DAY_IN_SECONDS,
			'year'  => 365 * DAY_IN_SECONDS,
		);
		return (int) $m[1] * $unit[ strtolower( $m[2] ) ];
	}

	public function plan() {
		$this->begin();
		$lock = self::site_json_option( 'wd_login_lockout_settings' );
		$bl   = self::site_json_option( 'wd_blacklist_lockout_settings' );
		$nf   = self::site_json_option( 'wd_notfound_lockout_settings' );
		$ua   = self::site_json_option( 'wd_user_agent_settings' );
		$sh   = self::site_json_option( 'wd_security_headers_settings' );
		$hard = array_merge( (array) ( self::site_json_option( 'hardener_settings' )['fixed'] ?? array() ), (array) ( self::site_json_option( 'wd_hardener_settings' )['fixed'] ?? array() ) );
		if ( ! $lock && ! $bl && ! $sh && ! $hard && ! $ua ) {
			return $this->finish();
		}

		if ( $lock ) {
			$lock = array_merge(
				array(
					'enabled'       => false,
					'attempt'       => 5,
					'timeframe'     => 300,
					'duration'      => 300,
					'duration_unit' => 'seconds',
					'lockout_type'  => 'timeframe',
				),
				$lock
			);
			$on   = ! empty( $lock['enabled'] );
			$this->offer_limit( __( 'Login Protection', 'perdita-core' ), $on, max( 1, (int) $lock['attempt'] ) );
			$this->offer_minutes( __( 'Lockout timeframe', 'perdita-core' ), (int) $lock['timeframe'], 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
			if ( 'permanent' === $lock['lockout_type'] ) {
				$this->pro_row( 'blocklist', __( 'Lockout type: permanent ban', 'perdita-core' ), 'permanent' );
			} else {
				$this->offer_minutes( __( 'Lockout duration', 'perdita-core' ), self::seconds( $lock['duration'], (string) $lock['duration_unit'] ), 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
			}
			if ( $on && ! empty( $lock['username_blacklist'] ) ) {
				$this->pro_row( 'usernames', __( 'Banned usernames', 'perdita-core' ), self::items( preg_split( '/\s+/', (string) $lock['username_blacklist'] ) ) );
			}
		}
		$this->offer_allowlist( __( 'IP allowlist', 'perdita-core' ), self::items( isset( $bl['ip_whitelist'] ) ? preg_split( '/[\s,]+/', (string) $bl['ip_whitelist'] ) : array() ) );
		$deny = self::items( isset( $bl['ip_blacklist'] ) ? preg_split( '/[\s,]+/', (string) $bl['ip_blacklist'] ) : array() );
		if ( $deny ) {
			$this->pro_row( 'blocklist', __( 'IP blocklist', 'perdita-core' ), count( $deny ) );
		}
		foreach ( array( 'country_blacklist', 'country_whitelist' ) as $k ) {
			if ( ! empty( $bl[ $k ] ) ) {
				$this->pro_row( 'country', 'country_blacklist' === $k ? __( 'Blocked countries', 'perdita-core' ) : __( 'Allowed countries', 'perdita-core' ), $bl[ $k ] );
			}
		}
		if ( ! empty( $nf['enabled'] ) ) {
			$this->pro_row( 'rate', __( '404 Detection', 'perdita-core' ), isset( $nf['attempt'] ) ? $nf['attempt'] : true );
		}

		// Hardening: the tweaks Defender marks as fixed.
		$this->offer_flag( __( 'Disable XML-RPC', 'perdita-core' ), in_array( 'disable-xml-rpc', $hard, true ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		$this->offer_flag( __( 'Prevent user enumeration', 'perdita-core' ), in_array( 'prevent-enum-users', $hard, true ), 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable the file editor', 'perdita-core' ), in_array( 'disable-file-editor', $hard, true ), 'disable_file_edit', __( 'Turn off the file editor', 'perdita-core' ) );
		$this->offer_flag( __( 'Hide error reporting and version', 'perdita-core' ), in_array( 'wp-version', $hard, true ), 'hide_wp_version', __( 'Hide the WordPress version', 'perdita-core' ) );
		$this->offer_flag( __( 'Prevent PHP execution', 'perdita-core' ), in_array( 'prevent-php-executed', $hard, true ), 'block_uploads_php', __( 'Block PHP in uploads', 'perdita-core' ), __( 'Perdita writes a marked block to the .htaccess file in your uploads folder, and Undo removes the block.', 'perdita-core' ) );

		// Security headers and HSTS.
		$any = false;
		foreach ( array( 'sh_xframe', 'sh_xss_protection', 'sh_content_type_options', 'sh_referrer_policy', 'sh_feature_policy' ) as $k ) {
			$any = $any || ! empty( $sh[ $k ] );
		}
		$this->offer_flag( __( 'Security headers', 'perdita-core' ), $any, 'headers', __( 'Send security headers', 'perdita-core' ), __( 'Perdita sends one fixed set of headers. Defender\'s own modes, such as DENY for frames, don\'t carry over.', 'perdita-core' ) );
		if ( ! empty( $sh['sh_strict_transport'] ) ) {
			$this->offer_flag( __( 'Strict Transport Security', 'perdita-core' ), true, 'hsts', __( 'Send HSTS', 'perdita-core' ) );
			$age = self::duration( isset( $sh['hsts_cache_duration'] ) ? $sh['hsts_cache_duration'] : '' );
			if ( $age > 0 ) {
				$this->offer( __( 'HSTS cache duration', 'perdita-core' ), $sh['hsts_cache_duration'], 'hsts_max_age', $age, __( 'HSTS max age (seconds)', 'perdita-core' ) );
			}
			$this->offer_flag( __( 'Include subdomains', 'perdita-core' ), ! empty( $sh['include_subdomain'] ), 'hsts_subdomains', __( 'HSTS for subdomains', 'perdita-core' ) );
		}

		// The firewall: Defender's user agent banning is the closest match.
		$cats = array();
		if ( ! empty( $ua['enabled'] ) ) {
			$cats['bad_bots'] = __( 'User Agent Banning', 'perdita-core' );
		}
		$this->offer_firewall( __( 'User Agent Banning', 'perdita-core' ), (bool) $cats, $cats, __( 'Defender\'s firewall is built on IP and user agent lockouts. Perdita\'s checks the content of each request as well.', 'perdita-core' ) );
		if ( ! empty( $ua['enabled'] ) && ( ! empty( $ua['blacklist'] ) || ! empty( $ua['whitelist'] ) ) ) {
			$this->pro_row( 'rules', __( 'User agent lists', 'perdita-core' ), self::items( preg_split( '/\s+/', trim( (string) $ua['blacklist'] ) ) ) );
		}

		// Captcha, scans, and the rest.
		$cap = self::site_json_option( 'wd_recaptcha_settings' );
		if ( ! empty( $cap['enabled'] ) ) {
			if ( isset( $cap['provider'] ) && 'turnstile' === $cap['provider'] && isset( $cap['locations'] ) && in_array( 'login', (array) $cap['locations'], true ) ) {
				$this->offer_turnstile( __( 'Captcha, Cloudflare Turnstile on login', 'perdita-core' ) );
			} else {
				$this->unsupported( __( 'Captcha', 'perdita-core' ), isset( $cap['provider'] ) ? $cap['provider'] : true, __( 'Perdita uses Cloudflare Turnstile on the login screens, not this captcha.', 'perdita-core' ) );
			}
		}
		$scan = self::site_json_option( 'wd_scan_settings' );
		if ( ! empty( $scan['scheduled_scanning'] ) && ! empty( $scan['check_core'] ) ) {
			$this->offer_flag( __( 'Scheduled scan, WordPress core', 'perdita-core' ), true, 'core_check', __( 'Daily core file check', 'perdita-core' ), __( 'Perdita compares WordPress core files with wordpress.org once a day. It doesn\'t scan plugins for malware or known vulnerabilities.', 'perdita-core' ) );
		}
		$mask = self::site_json_option( 'wd_masking_login_settings' );
		if ( ! empty( $mask['enabled'] ) ) {
			$this->pro_row( 'login_url', __( 'Mask Login Area', 'perdita-core' ), isset( $mask['mask_url'] ) ? $mask['mask_url'] : true );
		}
		$tfa = self::site_json_option( 'wd_2auth_settings' );
		if ( ! empty( $tfa['enabled'] ) ) {
			$this->pro_row( '2fa', __( 'Two-Factor Authentication', 'perdita-core' ), true );
		}
		$pw = self::site_json_option( 'wd_password_protection_settings' );
		if ( ! empty( $pw['enabled'] ) ) {
			$this->pro_row( 'breach', __( 'Pwned password check', 'perdita-core' ), true );
		}
		$audit = self::site_json_option( 'wd_audit_settings' );
		if ( ! empty( $audit['enabled'] ) ) {
			$this->pro_row( 'audit', __( 'Audit Logging', 'perdita-core' ), true );
		}
		$glob = self::site_json_option( 'wd_global_ip_settings' );
		$anti = self::site_json_option( 'wd_antibot_global_firewall_settings' );
		if ( ! empty( $glob['enabled'] ) || ! empty( $anti['enabled'] ) ) {
			$this->unsupported( __( 'Global IP list and AntiBot Global Firewall', 'perdita-core' ), true, __( 'You won\'t find a shared threat blocklist in Perdita.', 'perdita-core' ) );
		}
		$ld = self::site_json_option( 'wd_lockdown_settings' );
		if ( isset( $ld['ip_detection_type'] ) && 'manual' === $ld['ip_detection_type'] ) {
			$this->unsupported( __( 'IP detection', 'perdita-core' ), isset( $ld['http_ip_header'] ) ? $ld['http_ip_header'] : 'manual', __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
