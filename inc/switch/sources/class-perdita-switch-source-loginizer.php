<?php
/**
 * Loginizer: the brute force settings in loginizer_options, the off switch
 * in loginizer_disable_brute and the allowlist in loginizer_whitelist.
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

	public function plan() {
		$this->begin();
		$o = self::array_option( 'loginizer_options' );
		if ( ! $o ) {
			return $this->finish();
		}
		$on = ! get_option( 'loginizer_disable_brute' );
		$this->offer_limit( __( 'Brute force protection', 'perdita-core' ), $on, isset( $o['max_retries'] ) ? $o['max_retries'] : null );
		if ( isset( $o['reset_retries'] ) ) {
			$this->offer_minutes( __( 'Reset retries', 'perdita-core' ), $o['reset_retries'], 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		}
		if ( isset( $o['lockout_time'] ) ) {
			$this->offer_minutes( __( 'Lockout time', 'perdita-core' ), $o['lockout_time'], 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		}
		$list = self::array_option( 'loginizer_whitelist' );
		$ips  = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['start'] ) ) {
				continue;
			}
			$start = (string) $row['start'];
			$end   = isset( $row['end'] ) ? (string) $row['end'] : $start;
			$ips[] = $start === $end ? $start : $start . ' - ' . $end;
		}
		if ( $ips ) {
			$this->offer_allowlist( __( 'Whitelist IP', 'perdita-core' ), $ips );
		}
		if ( ! empty( $o['max_lockouts'] ) ) {
			$this->unsupported( __( 'Max lockouts', 'perdita-core' ), $o['max_lockouts'], __( 'In Perdita, every lockout lasts the same length of time.', 'perdita-core' ) );
		}
		$black = self::array_option( 'loginizer_blacklist' );
		if ( $black ) {
			$this->unsupported( __( 'Blacklist IP', 'perdita-core' ), count( $black ), __( 'You can\'t keep a permanent IP block list in Perdita.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
