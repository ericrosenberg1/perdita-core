<?php
/**
 * Shared parts of the login limit sources.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_Security_Source extends Perdita_Switch_Source {

	public function module() {
		return 'security';
	}

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
	 * Allowlisted addresses: exact IPs go in, ranges and CIDR blocks don't.
	 *
	 * @param string   $setting Setting name.
	 * @param string[] $entries Entries.
	 */
	protected function offer_allowlist( $setting, array $entries ) {
		$ips    = array();
		$target = __( 'Never lock out these IPs', 'perdita-core' );
		foreach ( $entries as $entry ) {
			$entry = is_scalar( $entry ) ? trim( (string) $entry ) : '';
			if ( '' === $entry ) {
				continue;
			}
			if ( false === filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				$this->skip( $setting, $entry, __( 'In Perdita, you can allowlist exact addresses but not ranges. Add each address on Perdita, Security.', 'perdita-core' ), $target );
				continue;
			}
			$ips[] = $entry;
		}
		if ( $ips ) {
			$this->offer( $setting, $ips, 'ip_allowlist', $ips, $target );
		}
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
}
