<?php
/**
 * Signed admin notices.
 *
 * Admin screens report the result of a save by redirecting with the message
 * in the query string (?perdita_msg=Settings%20saved.). Any link could carry
 * any text there, and it rendered as a green success notice on a real
 * wp-admin screen: a ready-made phishing prop ("Your license expired, sign
 * in again at ..."). Redirects this plugin family makes are now signed on
 * their way out (the wp_redirect filter below), and a screen shows the text
 * only when the signature matches the current user. A crafted link shows
 * nothing.
 *
 * Shared by the Perdita theme, Perdita Core and Perdita Pro. Each ships this
 * same file, and whichever loads first defines the functions.
 *
 * @package Perdita
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'perdita_notice_params' ) ) {

	/**
	 * Query parameters that carry free-text notices.
	 *
	 * @return string[]
	 */
	function perdita_notice_params() {
		return array( 'perdita_msg', 'perdita_err', 'perdita_mcp_msg', 'perdita_mcp_err', 'perdita_sc_msg', 'perdita_smtp_err' );
	}

	/**
	 * Signature for one notice value, bound to the current user.
	 *
	 * @param string $param Parameter name.
	 * @param string $value Decoded value.
	 * @return string
	 */
	function perdita_notice_signature( $param, $value ) {
		return substr( wp_hash( 'perdita-notice|' . get_current_user_id() . '|' . $param . '|' . $value, 'nonce' ), 0, 24 );
	}

	/**
	 * wp_redirect filter: add a signature for every notice parameter in a
	 * redirect this request makes. Runs on the already-sanitized location,
	 * so the signed value is exactly what the next request receives.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	function perdita_sign_notice_redirect( $location ) {
		if ( ! is_string( $location ) || false === strpos( $location, 'perdita_' ) || ! is_user_logged_in() ) {
			return $location;
		}
		$location = wp_sanitize_redirect( $location );
		$query    = (string) wp_parse_url( $location, PHP_URL_QUERY );
		if ( '' === $query ) {
			return $location;
		}
		parse_str( $query, $args );
		$sigs = array();
		foreach ( perdita_notice_params() as $param ) {
			if ( isset( $args[ $param ] ) && is_string( $args[ $param ] ) ) {
				$sigs[ $param . '_sig' ] = perdita_notice_signature( $param, $args[ $param ] );
			}
		}
		return $sigs ? add_query_arg( $sigs, $location ) : $location;
	}
	add_filter( 'wp_redirect', 'perdita_sign_notice_redirect', 99 );

	/**
	 * The notice text in $_GET[ $param ], or '' when it is missing or was not
	 * signed for this user by one of our own redirects.
	 *
	 * @param string $param Parameter name.
	 * @return string Sanitized text, still to be escaped on output.
	 */
	function perdita_notice_text( $param ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signature below is the check.
		if ( ! isset( $_GET[ $param ], $_GET[ $param . '_sig' ] ) || ! is_string( $_GET[ $param ] ) || ! is_string( $_GET[ $param . '_sig' ] ) ) {
			return '';
		}
		$value = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified against the signature, then sanitized below.
		$sig   = sanitize_text_field( wp_unslash( $_GET[ $param . '_sig' ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! hash_equals( perdita_notice_signature( $param, $value ), $sig ) ) {
			return '';
		}
		return sanitize_text_field( $value );
	}
}
