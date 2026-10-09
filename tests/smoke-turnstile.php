<?php
/**
 * Perdita Core smoke tests: the contact form's Turnstile check at the wire.
 *
 * Loaded by tests/smoke.php. Calls check_turnstile() with a real
 * WP_REST_Request, intercepts the siteverify call at pre_http_request,
 * asserts what goes out, and answers in Cloudflare's documented shapes.
 *
 * Pinned on purpose, pending Eric's decision: a secret Cloudflare rejects
 * (invalid-input-secret) still turns the visitor away. If that changes to
 * fail open, change that one assertion with it.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- forms turnstile siteverify (tests/smoke-turnstile.php) ---\n";

$ts_orig  = get_option( 'perdita_forms_settings', null );
$ts_forms = ( new ReflectionClass( 'Perdita_Forms' ) )->newInstanceWithoutConstructor();
$ts_check = new ReflectionMethod( 'Perdita_Forms', 'check_turnstile' );
if ( PHP_VERSION_ID < 80100 ) {
	$ts_check->setAccessible( true );
}
$ts_secret = '0x4AAAAAAAsmoke' . wp_generate_password( 12, false );
update_option(
	'perdita_forms_settings',
	array_merge(
		is_array( $ts_orig ) ? $ts_orig : array(),
		array(
			'turnstile_site_key'   => '0x4AAAAAAAsite',
			'turnstile_secret_key' => Perdita_Crypto::encrypt( $ts_secret ),
		)
	)
);

$ts_seen   = array();
$ts_answer = null;
$ts_hook   = function ( $pre, $args, $url ) use ( &$ts_seen, &$ts_answer ) {
	if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) {
		return $pre;
	}
	$ts_seen[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $ts_answer;
};
$ts_json = function ( $code, $body ) {
	return array(
		'headers'  => array( 'content-type' => is_array( $body ) ? 'application/json' : 'text/html' ),
		'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$ts_run = function ( $token ) use ( $ts_check, $ts_forms ) {
	$req = new WP_REST_Request( 'POST', '/perdita/v1/forms/submit' );
	if ( null !== $token ) {
		$req->set_param( 'cf-turnstile-response', $token );
	}
	return $ts_check->invoke( $ts_forms, $req );
};
add_filter( 'pre_http_request', $ts_hook, 10, 3 );

$ts_answer = $ts_json(
	200,
	array(
		'success'      => true,
		'challenge_ts' => gmdate( 'c' ),
		'hostname'     => 'example.test',
		'error-codes'  => array(),
	)
);
$ok( '' === $ts_run( 'XXXX.DUMMY.TOKEN' ), 'turnstile: a success verdict lets the message through' );
$ts_req = $ts_seen ? $ts_seen[0] : array( 'url' => '', 'args' => array() );
$ok( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $ts_req['url'] && 'POST' === ( $ts_req['args']['method'] ?? '' ), 'turnstile: POSTs to the siteverify endpoint' );
$ok( $ts_secret === ( $ts_req['args']['body']['secret'] ?? '' ), 'turnstile: sends the decrypted secret, not the stored ciphertext' );
$ok( 'XXXX.DUMMY.TOKEN' === ( $ts_req['args']['body']['response'] ?? '' ), 'turnstile: sends the visitor\'s token as response' );
$ok( array_key_exists( 'remoteip', $ts_req['args']['body'] ?? array() ), 'turnstile: sends remoteip' );

$ts_answer = $ts_json( 200, array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );
$ok( '' !== $ts_run( 'bad-token' ), 'turnstile: a failed verdict on a bad token rejects the message' );
$ts_answer = $ts_json( 200, array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) ) );
$ok( '' !== $ts_run( 'reused-token' ), 'turnstile: a replayed token is rejected' );
$ts_answer = $ts_json( 200, array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) ) );
$ok( '' !== $ts_run( 'XXXX.DUMMY.TOKEN' ), 'turnstile: a secret Cloudflare rejects still turns the visitor away (open decision, see header)' );

$ts_answer = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$ok( '' === $ts_run( 'XXXX.DUMMY.TOKEN' ), 'turnstile: a transport failure fails open' );
$ts_answer = $ts_json( 503, '<html><body>Service Unavailable</body></html>' );
$ok( '' === $ts_run( 'XXXX.DUMMY.TOKEN' ), 'turnstile: a 503 HTML error page is an outage and fails open, not "did not pass"' );
$ts_answer = $ts_json( 429, '<html><body>Too Many Requests</body></html>' );
$ok( '' === $ts_run( 'XXXX.DUMMY.TOKEN' ), 'turnstile: a 429 is an outage and fails open' );

$ts_seen = array();
$ok( '' !== $ts_run( null ) && array() === $ts_seen, 'turnstile: no token is rejected without calling Cloudflare' );

update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => '', 'turnstile_secret_key' => '' ) );
$ts_seen = array();
$ok( '' === $ts_run( null ) && array() === $ts_seen, 'turnstile: with no keys configured the check is off and nothing is sent' );

remove_filter( 'pre_http_request', $ts_hook, 10 );
if ( null === $ts_orig ) {
	delete_option( 'perdita_forms_settings' );
} else {
	update_option( 'perdita_forms_settings', $ts_orig );
}
unset( $ts_orig, $ts_forms, $ts_check, $ts_secret, $ts_seen, $ts_answer, $ts_hook, $ts_json, $ts_run, $ts_req );
