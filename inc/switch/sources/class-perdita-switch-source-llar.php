<?php
/**
 * Limit Login Attempts Reloaded: its local settings, each a limit_login_*
 * option. The plugin runs on its own defaults until the first save, and reads
 * site options when it's network-active on a multisite.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_LLAR extends Perdita_Switch_Security_Source {

	/**
	 * The plugin's own defaults (core/Config.php).
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'allowed_retries'  => 4,
		'lockout_duration' => 1200,
		'allowed_lockouts' => 4,
		'long_duration'    => 86400,
		'valid_duration'   => 86400,
	);

	public function id() {
		return 'limit-login-attempts-reloaded';
	}

	public function label() {
		return 'Limit Login Attempts Reloaded';
	}

	public function plugin_files() {
		return array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' );
	}

	/**
	 * Whether the plugin reads site options: network-active on a multisite,
	 * unless the network allows local options and this site chose them.
	 *
	 * @return bool
	 */
	private static function network_mode() {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ) ) {
			return false;
		}
		return ! ( get_site_option( 'limit_login_allow_local_options', false ) && get_option( 'limit_login_use_local_options', false ) );
	}

	/**
	 * A stored option, or null.
	 *
	 * @param string $name Name without the prefix.
	 * @return mixed
	 */
	private static function stored( $name ) {
		$full = 'limit_login_' . $name;
		return self::network_mode() ? get_site_option( $full, null ) : get_option( $full, null );
	}

	/**
	 * A number, or the plugin's default when none is stored.
	 *
	 * @param string $name Name without the prefix.
	 * @return int
	 */
	private static function number( $name ) {
		$v = self::stored( $name );
		return is_numeric( $v ) ? (int) $v : self::DEFAULTS[ $name ];
	}

	/**
	 * A list option as an array of strings.
	 *
	 * @param string $name Name without the prefix.
	 * @return string[]
	 */
	private static function lines( $name ) {
		$v = self::stored( $name );
		return is_array( $v ) ? array_values( array_filter( $v, 'is_scalar' ) ) : array();
	}

	public function has_settings() {
		foreach ( array( 'allowed_retries', 'lockout_duration', 'allowed_lockouts', 'long_duration', 'valid_duration', 'whitelist', 'blacklist', 'active_app', 'lockout_notify', 'trusted_ip_origins' ) as $name ) {
			if ( null !== self::stored( $name ) ) {
				return true;
			}
		}
		return false;
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() && ! $this->detect()['installed'] ) {
			return $this->finish();
		}
		$app = self::stored( 'active_app' );
		if ( 'custom' === $app ) {
			$this->note( __( 'You use the Limit Login Attempts Reloaded cloud service, and Limit Login Attempts Reloaded keeps those rules on its own servers. You can import only the local settings on this site, and they may be older.', 'perdita-core' ) );
		}
		$this->offer_limit( __( 'Limit Login Attempts Reloaded', 'perdita-core' ), true, max( 1, self::number( 'allowed_retries' ) ) );
		$this->offer_minutes( __( 'Hours until retries are reset', 'perdita-core' ), self::number( 'valid_duration' ), 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		$this->offer_minutes( __( 'Minutes lockout', 'perdita-core' ), self::number( 'lockout_duration' ), 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		if ( self::number( 'allowed_lockouts' ) >= 1 ) {
			$this->offer_escalation(
				__( 'Lockouts increase lockout time', 'perdita-core' ),
				self::number( 'allowed_lockouts' ),
				self::number( 'long_duration' ),
				__( 'The plugin switches to the long lockout after the retries times the lockouts you set. Perdita switches after that many lockouts, so the match is close and not exact.', 'perdita-core' )
			);
		}
		$this->offer_allowlist( __( 'Safelist IPs', 'perdita-core' ), self::lines( 'whitelist' ) );
		$users = self::lines( 'whitelist_usernames' );
		if ( $users ) {
			$this->unsupported( __( 'Safelist usernames', 'perdita-core' ), $users, __( 'In Perdita, you can allowlist IP addresses but not usernames.', 'perdita-core' ) );
		}
		$deny = self::lines( 'blacklist' );
		if ( $deny ) {
			$this->pro_row( 'blocklist', __( 'Denylist IPs', 'perdita-core' ), $deny );
		}
		$names = self::lines( 'blacklist_usernames' );
		if ( $names ) {
			$this->pro_row( 'usernames', __( 'Denylist usernames', 'perdita-core' ), $names );
		}
		if ( self::on( (string) self::stored( 'mfa_enabled' ) ) ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), true );
		}
		$origins = self::stored( 'trusted_ip_origins' );
		if ( is_array( $origins ) && array_diff( $origins, array( 'REMOTE_ADDR' ) ) ) {
			$this->unsupported( __( 'Trusted IP origins', 'perdita-core' ), $origins, __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
