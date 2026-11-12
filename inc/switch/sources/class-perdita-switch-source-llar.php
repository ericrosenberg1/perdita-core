<?php
/**
 * Limit Login Attempts Reloaded: its local settings, each a limit_login_*
 * option.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_LLAR extends Perdita_Switch_Security_Source {

	public function id() {
		return 'limit-login-attempts-reloaded';
	}

	public function label() {
		return 'Limit Login Attempts Reloaded';
	}

	public function plugin_files() {
		return array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' );
	}

	public function has_settings() {
		return false !== get_option( 'limit_login_allowed_retries', false );
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() ) {
			return $this->finish();
		}
		$scalar = function ( $name ) {
			$value = get_option( $name, null );
			return is_scalar( $value ) ? $value : null;
		};
		if ( 'custom' === $scalar( 'limit_login_active_app' ) ) {
			$this->note( __( 'You use the Limit Login Attempts Reloaded cloud service, and Limit Login Attempts Reloaded keeps those rules on its own servers. You can import only the local settings on this site, and they may be older.', 'perdita-core' ) );
		}
		$this->offer_limit( __( 'Limit Login Attempts Reloaded', 'perdita-core' ), true, $scalar( 'limit_login_allowed_retries' ) );
		if ( null !== $scalar( 'limit_login_valid_duration' ) ) {
			$this->offer_minutes( __( 'Hours until retries are reset', 'perdita-core' ), $scalar( 'limit_login_valid_duration' ), 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		}
		if ( null !== $scalar( 'limit_login_lockout_duration' ) ) {
			$this->offer_minutes( __( 'Minutes lockout', 'perdita-core' ), $scalar( 'limit_login_lockout_duration' ), 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		}
		$list = get_option( 'limit_login_whitelist', array() );
		if ( is_array( $list ) && $list ) {
			$this->offer_allowlist( __( 'Safelist IPs', 'perdita-core' ), $list );
		}
		$users = get_option( 'limit_login_whitelist_usernames', array() );
		if ( is_array( $users ) && $users ) {
			$this->unsupported( __( 'Safelist usernames', 'perdita-core' ), array_filter( $users, 'is_scalar' ), __( 'In Perdita, you can allowlist IP addresses but not usernames.', 'perdita-core' ) );
		}
		if ( null !== $scalar( 'limit_login_allowed_lockouts' ) ) {
			$this->unsupported( __( 'Lockouts increase lockout time', 'perdita-core' ), $scalar( 'limit_login_allowed_lockouts' ), __( 'In Perdita, every lockout lasts the same length of time.', 'perdita-core' ) );
		}
		$blacklist = get_option( 'limit_login_blacklist', array() );
		if ( is_array( $blacklist ) && $blacklist ) {
			$this->unsupported( __( 'Denylist IPs', 'perdita-core' ), array_filter( $blacklist, 'is_scalar' ), __( 'You can\'t keep a permanent IP block list in Perdita.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
