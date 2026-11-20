<?php
/**
 * Loginizer: the brute force settings in loginizer_options, the off switch
 * in loginizer_disable_brute, the lists in loginizer_whitelist and
 * loginizer_blacklist, and the hardening options beside them.
 *
 * Loginizer runs on its own defaults until the first save, and treats an
 * empty or 0 value as the default, so this source does too.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Loginizer extends Perdita_Switch_Security_Source {

	public function id() {
		return 'loginizer';
	}

	public function label() {
		return 'Loginizer';
	}

	public function plugin_files() {
		return array( 'loginizer/loginizer.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'loginizer_options' ) );
	}

	/**
	 * An option value, or the default when it is empty or 0, the way the
	 * plugin reads it.
	 *
	 * @param array  $o       Options.
	 * @param string $key     Key.
	 * @param int    $default Default.
	 * @return int
	 */
	private static function value( array $o, $key, $default ) {
		return empty( $o[ $key ] ) || ! is_numeric( $o[ $key ] ) ? $default : (int) $o[ $key ];
	}

	/**
	 * The list rows as entries: an address, or "start-end".
	 *
	 * @param string $name Option name.
	 * @return string[]
	 */
	private static function list_rows( $name ) {
		$out = array();
		foreach ( self::array_option( $name ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['start'] ) || ! is_scalar( $row['start'] ) ) {
				continue;
			}
			$start = trim( (string) $row['start'] );
			$end   = isset( $row['end'] ) && is_scalar( $row['end'] ) ? trim( (string) $row['end'] ) : $start;
			$out[] = $start === $end ? $start : $start . '-' . $end;
		}
		return $out;
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() && ! $this->detect()['installed'] ) {
			return $this->finish();
		}
		$o  = self::array_option( 'loginizer_options' );
		$on = ! get_option( 'loginizer_disable_brute' );
		$this->offer_limit( __( 'Brute force protection', 'perdita-core' ), $on, self::value( $o, 'max_retries', 3 ) );
		$this->offer_minutes( __( 'Reset retries', 'perdita-core' ), self::value( $o, 'reset_retries', 86400 ), 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		$this->offer_minutes( __( 'Lockout time', 'perdita-core' ), self::value( $o, 'lockout_time', 900 ), 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		$this->offer_escalation(
			__( 'Extended lockout', 'perdita-core' ),
			self::value( $o, 'max_lockouts', 5 ),
			self::value( $o, 'lockouts_extend', 86400 ),
			__( 'Loginizer counts lockouts until its reset time, and Perdita counts them over 24 hours, so the match is close and not exact.', 'perdita-core' )
		);
		$this->offer_allowlist( __( 'Whitelist IP', 'perdita-core' ), self::list_rows( 'loginizer_whitelist' ) );
		if ( ! empty( $o['trusted_ips'] ) ) {
			$this->unsupported( __( 'Allow only whitelisted IPs to log in', 'perdita-core' ), true, __( 'Perdita can\'t limit login to a list of addresses. Your whitelist is imported as the addresses that are never locked out.', 'perdita-core' ) );
		}
		$black = self::list_rows( 'loginizer_blacklist' );
		if ( $black ) {
			$this->pro_row( 'blocklist', __( 'Blacklist IP', 'perdita-core' ), count( $black ) );
		}

		$enum = self::array_option( 'loginizer_user_enum' );
		$this->offer_flag( __( 'Hide login errors', 'perdita-core' ), ! empty( $enum['hide_login_errors'] ), 'login_generic_errors', __( 'One message for every failed login', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable author enumeration', 'perdita-core' ), ! empty( $enum['disable_author_enum'] ), 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable REST users', 'perdita-core' ), ! empty( $enum['disable_rest_users'] ), 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ) );
		$sec = self::array_option( 'loginizer_security' );
		$this->offer_flag( __( 'Disable XML-RPC', 'perdita-core' ), ! empty( $sec['xmlrpc_disable'] ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		if ( ! empty( $sec['pingbacks_disable'] ) && empty( $sec['xmlrpc_disable'] ) ) {
			$this->unsupported( __( 'Disable pingbacks', 'perdita-core' ), true, __( 'Perdita turns XML-RPC off completely or leaves it on.', 'perdita-core' ) );
		}
		if ( ! empty( $sec['login_slug'] ) ) {
			$this->pro_row( 'login_url', __( 'Rename login page', 'perdita-core' ), $sec['login_slug'] );
		}
		$names = self::array_option( 'loginizer_username_blacklist' );
		if ( $names ) {
			$this->pro_row( 'usernames', __( 'Username blacklist', 'perdita-core' ), $names );
		}
		$cap = self::array_option( 'loginizer_captcha' );
		if ( ! empty( $cap['captcha_status'] ) && ! empty( $cap['captcha_login'] ) ) {
			if ( 4 === (int) $cap['captcha_status'] ) {
				$this->offer_turnstile( __( 'Captcha on login, Cloudflare Turnstile', 'perdita-core' ) );
			} else {
				$this->unsupported( __( 'Captcha on login', 'perdita-core' ), (int) $cap['captcha_status'], __( 'Perdita uses Cloudflare Turnstile on the login screens, not this captcha.', 'perdita-core' ) );
			}
		}
		$tfa = self::array_option( 'loginizer_2fa' );
		if ( ! empty( $tfa['2fa_app'] ) || ! empty( $tfa['2fa_email'] ) ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), true );
		}
		if ( (int) get_option( 'loginizer_ip_method', 0 ) > 0 ) {
			$this->unsupported( __( 'Get the IP address from', 'perdita-core' ), (int) get_option( 'loginizer_ip_method', 0 ), __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
