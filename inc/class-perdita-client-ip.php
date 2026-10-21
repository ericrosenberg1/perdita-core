<?php
/**
 * The visitor's IP address for rate limits, lockouts and logs.
 *
 * REMOTE_ADDR is the address that connected to the web server. Behind
 * Cloudflare that's a Cloudflare edge, shared by thousands of visitors, so a
 * login lockout keyed on it locked out everyone on that edge and let an
 * attacker spread across edges never trip it. Cloudflare sends the real
 * address in CF-Connecting-IP. That header is only believed when the
 * connection itself came from one of Cloudflare's published ranges, because
 * anyone can send the header straight to the origin.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Client_IP {

	/**
	 * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/),
	 * checked 2026-10-21. Filter perdita_cloudflare_ip_ranges to update them
	 * without a release.
	 *
	 * @return string[] CIDR blocks.
	 */
	public static function cloudflare_ranges() {
		$ranges = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		/**
		 * Cloudflare edge ranges whose CF-Connecting-IP header is trusted.
		 *
		 * @param string[] $ranges CIDR blocks.
		 */
		return array_values( array_filter( (array) apply_filters( 'perdita_cloudflare_ip_ranges', $ranges ), 'is_string' ) );
	}

	/**
	 * Whether an address falls inside a CIDR block. IPv4 and IPv6.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr Block such as 162.158.0.0/15.
	 * @return bool
	 */
	public static function in_range( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed range just doesn't match.
		$addr  = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed address just doesn't match.
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		$full = intdiv( $bits, 8 );
		if ( substr( $net, 0, $full ) !== substr( $addr, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $net[ $full ] & $mask ) === ( $addr[ $full ] & $mask );
	}

	/**
	 * The visitor address Cloudflare reported, when this connection really
	 * came from Cloudflare. Empty otherwise.
	 *
	 * @return string
	 */
	public static function from_cloudflare() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		$header = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
		if ( '' === $remote || '' === $header || false === filter_var( $header, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		foreach ( self::cloudflare_ranges() as $cidr ) {
			if ( self::in_range( $remote, $cidr ) ) {
				return $header;
			}
		}
		return '';
	}

	/**
	 * The best address for this visitor: Cloudflare's report when it's
	 * verified, else REMOTE_ADDR.
	 *
	 * @return string
	 */
	public static function get() {
		$cf = self::from_cloudflare();
		if ( '' !== $cf ) {
			return $cf;
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
	}
}
