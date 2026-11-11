<?php
/**
 * IndexNow: the key-file check at the wire.
 *
 * Loaded by tests/smoke.php. The IndexNow screen's "Check the key file" button
 * fetches this site's own /{key}.txt over HTTP and tells the owner whether the
 * key came back, which is how the IndexNow protocol proves ownership
 * (indexnow.org/documentation: the key file is a text/plain document whose
 * body is the key). Nothing covered that path before. check() runs on
 * admin-post, redirects and exits, so the fetch is answered at
 * pre_http_request with the shapes a real host sends (the key as text/plain,
 * the key with a trailing newline, a cached HTML page in its place, a 503, no
 * connection) and a wp_redirect filter throws to hand back the message the
 * admin would read.
 *
 * Everything is restored: the option, $_REQUEST and the current user.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- indexnow key-file check (tests/smoke-indexnow-check.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/indexnow/class-perdita-indexnow.php';
require_once PERDITA_CORE_DIR . 'inc/modules/indexnow/class-perdita-indexnow-admin.php';

$inc_orig_user = get_current_user_id();
$inc_orig_req  = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved only to restore it at the end.
$inc_orig_opt  = get_option( Perdita_IndexNow::OPTION, null );

wp_set_current_user( 1 );

$inc_key = Perdita_IndexNow::key();
$inc_url = Perdita_IndexNow::key_file_url();
$ok( Perdita_IndexNow::is_valid_key( $inc_key ) && home_url( '/' . $inc_key . '.txt' ) === $inc_url, 'indexnow check: the key file is /{key}.txt on this site, and the key is 32 hex characters' );

// What the fetch of the key file answers: array( status, body, headers ) or a WP_Error.
$inc_answer = null;
$inc_calls  = array();
$inc_http   = function ( $pre, $args, $url ) use ( &$inc_answer, &$inc_calls, $inc_url ) {
	if ( $url !== $inc_url ) {
		return $pre;
	}
	$inc_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( is_wp_error( $inc_answer ) ) {
		return $inc_answer;
	}
	return array(
		'headers'  => $inc_answer[2],
		'body'     => $inc_answer[1],
		'response' => array(
			'code'    => $inc_answer[0],
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$inc_stop   = function ( $location ) {
	// Hands the redirect back to the test so check()'s exit never runs.
	throw new RuntimeException( 'perdita-smoke-redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught below, never printed.
};
add_filter( 'pre_http_request', $inc_http, 10, 3 );
add_filter( 'wp_redirect', $inc_stop, 1 );

// check() reads nothing from the constructor's dependencies, so skip it and
// register none of the admin hooks on this run.
$inc_admin = ( new ReflectionClass( 'Perdita_IndexNow_Admin' ) )->newInstanceWithoutConstructor();

// Run the check as the admin's own button press and return the message and
// the screen it lands on.
$inc_run = function () use ( $inc_admin, &$inc_calls ) {
	$inc_calls            = array();
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'perdita_indexnow_check' );
	try {
		$inc_admin->check();
	} catch ( RuntimeException $e ) {
		$location = substr( $e->getMessage(), strlen( 'perdita-smoke-redirect:' ) );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		return array(
			'page' => isset( $q['page'] ) ? (string) $q['page'] : '',
			'msg'  => isset( $q['perdita_msg'] ) ? (string) $q['perdita_msg'] : '',
		);
	}
	return array(
		'page' => '',
		'msg'  => '(no redirect)',
	);
};

// 1. The host serves the key as text/plain: verified.
$inc_answer = array( 200, $inc_key, array( 'content-type' => 'text/plain; charset=utf-8' ) );
$inc_r      = $inc_run();
$ok( 'The key file is reachable and returns the key.' === $inc_r['msg'] && Perdita_IndexNow_Admin::PAGE === $inc_r['page'], 'indexnow check: a text/plain body equal to the key verifies and lands back on the IndexNow screen' );
$ok( 1 === count( $inc_calls ) && Perdita_IndexNow::TIMEOUT === (int) ( $inc_calls[0]['args']['timeout'] ?? 0 ), 'indexnow check: one GET of the key file with the module\'s own timeout' );

// 2. A trailing newline, which most servers add to a text file, still verifies.
$inc_answer = array( 200, $inc_key . "\n", array( 'content-type' => 'text/plain' ) );
$inc_r      = $inc_run();
$ok( 'The key file is reachable and returns the key.' === $inc_r['msg'], 'indexnow check: a trailing newline after the key is fine' );

// 3. A page cache or security layer answers the URL with HTML instead.
$inc_answer = array( 200, "<!DOCTYPE html>\n<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>", array( 'content-type' => 'text/html' ) );
$inc_r      = $inc_run();
$ok( 0 === strpos( $inc_r['msg'], 'The key file did not verify (HTTP 200, body starts "<!DOCTYPE html>' ) && false !== strpos( $inc_r['msg'], 'A cache or security layer may be answering that URL' ), 'indexnow check: an HTML body on a 200 is reported with the status and the first characters, and names the likely cause' );
$ok( false === strpos( $inc_r['msg'], 'Checking your browser' ), 'indexnow check: the body excerpt is cut at 40 characters, so a long interstitial does not flood the notice' );

// 4. The host returns an error status.
$inc_answer = array( 503, 'Service Unavailable', array() );
$inc_r      = $inc_run();
$ok( 0 === strpos( $inc_r['msg'], 'The key file did not verify (HTTP 503, body starts "Service Unavailable")' ), 'indexnow check: a 503 is reported with its status and body' );

// 5. The right key under a different case does not verify: the protocol compares bytes.
$inc_answer = array( 200, strtoupper( $inc_key ), array( 'content-type' => 'text/plain' ) );
$inc_r      = $inc_run();
$ok( 0 === strpos( $inc_r['msg'], 'The key file did not verify (HTTP 200' ), 'indexnow check: the key must come back byte for byte, an upper-cased copy fails' );

// 6. No connection at all.
$inc_answer = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
$inc_r      = $inc_run();
$ok( 'Could not fetch the key file: cURL error 6: Could not resolve host' === $inc_r['msg'], 'indexnow check: a transport error is reported with WordPress\'s own message' );

remove_filter( 'pre_http_request', $inc_http, 10 );
remove_filter( 'wp_redirect', $inc_stop, 1 );
$_REQUEST = $inc_orig_req; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restoring the superglobal the test changed.
if ( null === $inc_orig_opt ) {
	delete_option( Perdita_IndexNow::OPTION );
} else {
	update_option( Perdita_IndexNow::OPTION, $inc_orig_opt );
}
wp_set_current_user( $inc_orig_user );
