<?php
/**
 * Perdita Core smoke tests: the Search Console OAuth callback at the wire.
 *
 * Loaded by tests/smoke.php. tests/smoke-search-console-http.php covers the
 * refresh-token exchange that every report read makes. This covers the other
 * Google call, the one-time authorization_code exchange in
 * Perdita_Search_Console_OAuth::callback(), which is what turns the owner's
 * consent click into the stored refresh token. The token endpoint is answered
 * at pre_http_request with Google's documented shapes
 * (developers.google.com/identity/protocols/oauth2/web-server: a 200 with
 * access_token, expires_in, refresh_token, scope and token_type, or a 400 with
 * error and error_description), and a wp_redirect filter throws so finish()'s
 * exit never runs and the status it redirects with can be read.
 *
 * Everything is restored: the settings option, $_GET, the state transient and
 * the current user.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- search console oauth callback (tests/smoke-search-console-oauth.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/search-console/class-perdita-search-console.php';
require_once PERDITA_CORE_DIR . 'inc/modules/search-console/class-perdita-search-console-oauth.php';

$sco_orig_user = get_current_user_id();
$sco_orig_get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved only to restore it at the end.
$sco_orig_opt  = get_option( Perdita_Search_Console::OPTION, null );

wp_set_current_user( 1 );
$sco_state_key = Perdita_Search_Console_OAuth::STATE_PREFIX . get_current_user_id();

update_option(
	Perdita_Search_Console::OPTION,
	array_merge(
		Perdita_Search_Console::defaults(),
		Perdita_Search_Console::sanitize_client(
			array(
				'client_id'     => 'smoke-client.apps.googleusercontent.com',
				'client_secret' => 'GOCSPX-smoke-secret',
			)
		)
	)
);
// A connection from before: a refused or failed callback has to leave it alone.
Perdita_Search_Console::store_refresh_token( '1//stale-smoke-token' );

$sco_calls  = array();
$sco_answer = array(
	200,
	array(
		'access_token'  => 'ya29.a0smoke',
		'expires_in'    => 3599,
		'refresh_token' => '1//0gsmoke-refresh',
		'scope'         => Perdita_Search_Console_OAuth::SCOPE,
		'token_type'    => 'Bearer',
	),
);
$sco_http   = function ( $pre, $args, $url ) use ( &$sco_calls, &$sco_answer ) {
	if ( 0 !== strpos( $url, Perdita_Search_Console::TOKEN_URL ) ) {
		return $pre;
	}
	$sco_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( is_wp_error( $sco_answer ) ) {
		return $sco_answer;
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
		'body'     => wp_json_encode( $sco_answer[1] ),
		'response' => array(
			'code'    => $sco_answer[0],
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$sco_stop   = function ( $location ) {
	// Hands the redirect back to the test so finish()'s exit never runs.
	throw new RuntimeException( 'perdita-smoke-redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught below, never printed.
};
add_filter( 'pre_http_request', $sco_http, 10, 3 );
add_filter( 'wp_redirect', $sco_stop, 1 );

// callback() needs nothing the constructor sets up, so skip it and register
// none of the admin_post hooks on this run.
$sco_oauth = ( new ReflectionClass( 'Perdita_Search_Console_OAuth' ) )->newInstanceWithoutConstructor();

// One redirect back from Google: the query Google sends and the state this
// site stored before sending the owner there (null for none, as after 15
// minutes). Returns the status, message and page finish() redirects with.
$sco_run    = function ( array $get, $stored_state ) use ( $sco_oauth, $sco_state_key, &$sco_calls ) {
	$sco_calls = array();
	if ( null === $stored_state ) {
		delete_transient( $sco_state_key );
	} else {
		set_transient( $sco_state_key, $stored_state, MINUTE_IN_SECONDS );
	}
	$_GET = $get;
	try {
		$sco_oauth->callback();
	} catch ( RuntimeException $e ) {
		parse_str( (string) wp_parse_url( substr( $e->getMessage(), strlen( 'perdita-smoke-redirect:' ) ), PHP_URL_QUERY ), $q );
		return array(
			'status' => isset( $q['perdita_sc_status'] ) ? (string) $q['perdita_sc_status'] : '',
			'msg'    => isset( $q['perdita_sc_msg'] ) ? (string) $q['perdita_sc_msg'] : '',
			'page'   => isset( $q['page'] ) ? (string) $q['page'] : '',
		);
	}
	return array(
		'status' => '(no redirect)',
		'msg'    => '',
		'page'   => '',
	);
};
$sco_stored = function () {
	return Perdita_Crypto::decrypt( (string) Perdita_Search_Console::settings()['refresh_token'] );
};

// 1. The owner consented: Google sends code and state, the exchange returns a refresh token.
$sco_r = $sco_run(
	array(
		'code'  => '4/0AsmokeAuthCode',
		'state' => 'smoke-state-1',
	),
	'smoke-state-1'
);
$ok( 'connected' === $sco_r['status'] && Perdita_Search_Console_OAuth::SETTINGS_PAGE === $sco_r['page'], 'sc oauth: a good code exchange lands back on the Search Console screen as connected' );
$ok( 1 === count( $sco_calls ) && 'POST' === strtoupper( (string) ( $sco_calls[0]['args']['method'] ?? '' ) ) && Perdita_Search_Console::TOKEN_URL === $sco_calls[0]['url'], 'sc oauth: exactly one POST to oauth2.googleapis.com/token' );
$sco_body = isset( $sco_calls[0]['args']['body'] ) && is_array( $sco_calls[0]['args']['body'] ) ? $sco_calls[0]['args']['body'] : array();
$ok( 'authorization_code' === ( $sco_body['grant_type'] ?? '' ) && '4/0AsmokeAuthCode' === ( $sco_body['code'] ?? '' ) && 'smoke-client.apps.googleusercontent.com' === ( $sco_body['client_id'] ?? '' ) && 'GOCSPX-smoke-secret' === ( $sco_body['client_secret'] ?? '' ), 'sc oauth: the exchange sends grant_type=authorization_code with the code, the client id and the decrypted secret' );
$ok( admin_url( 'admin-post.php?action=perdita_sc_oauth_callback' ) === ( $sco_body['redirect_uri'] ?? '' ) && Perdita_Search_Console::redirect_uri() === ( $sco_body['redirect_uri'] ?? '' ), 'sc oauth: redirect_uri is the admin-post callback the consent screen was sent, byte for byte (Google rejects any other)' );
$ok( '1//0gsmoke-refresh' === $sco_stored(), 'sc oauth: the refresh token is stored encrypted and decrypts back to what Google sent' );
$ok( false === get_transient( $sco_state_key ), 'sc oauth: the state is consumed on use, so the same redirect cannot be replayed' );
$ok( '' === $sco_r['msg'] || false !== strpos( $sco_r['msg'], 'Choose which Search Console property' ), 'sc oauth: the connected message sends the owner on to the property picker' );

// 2. The state Google echoes does not match the one this site stored: refused before any call.
Perdita_Search_Console::store_refresh_token( '1//stale-smoke-token' );
$sco_r = $sco_run(
	array(
		'code'  => '4/0Asmoke',
		'state' => 'someone-elses-state',
	),
	'smoke-state-2'
);
$ok( 'state_mismatch' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: a state that does not match is refused before Google is called' );
$ok( '1//stale-smoke-token' === $sco_stored(), 'sc oauth: a refused callback leaves the stored token alone' );

// 3. The stored state expired (15 minutes) or was never set: same refusal.
$sco_r = $sco_run(
	array(
		'code'  => '4/0Asmoke',
		'state' => 'smoke-state-3',
	),
	null
);
$ok( 'state_mismatch' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: a missing stored state is refused the same way' );

// 4. The owner clicked Cancel on Google's screen: error=access_denied, no code.
$sco_r = $sco_run(
	array(
		'error' => 'access_denied',
		'state' => 'smoke-state-4',
	),
	'smoke-state-4'
);
$ok( 'cancelled' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: Google\'s error=access_denied reads as cancelled, not as a failure' );

// 5. Any other error from Google.
$sco_r = $sco_run(
	array(
		'error' => 'invalid_scope',
		'state' => 'smoke-state-5',
	),
	'smoke-state-5'
);
$ok( 'oauth_error' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: another Google error is reported as oauth_error without a token call' );

// 6. State matches but no code came back.
$sco_r = $sco_run( array( 'state' => 'smoke-state-6' ), 'smoke-state-6' );
$ok( 'no_code' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: a redirect with no code is reported as no_code' );

// 7. Google refuses the code: a reused or expired code, or a redirect_uri that
// does not match the OAuth client. Documented as a 400 invalid_grant.
$sco_answer = array(
	400,
	array(
		'error'             => 'invalid_grant',
		'error_description' => 'Bad Request',
	),
);
$sco_r      = $sco_run(
	array(
		'code'  => '4/0Aused',
		'state' => 'smoke-state-7',
	),
	'smoke-state-7'
);
$ok( 'token_exchange_failed' === $sco_r['status'] && 1 === count( $sco_calls ), 'sc oauth: Google\'s 400 invalid_grant is reported as token_exchange_failed' );
$ok( '1//stale-smoke-token' === $sco_stored(), 'sc oauth: a failed exchange keeps the previous refresh token' );

// 8. A 200 without a refresh_token. Google issues one only on the first
// consent unless prompt=consent is sent, so a half answer must not count as connected.
$sco_answer = array(
	200,
	array(
		'access_token' => 'ya29.only',
		'expires_in'   => 3599,
		'scope'        => Perdita_Search_Console_OAuth::SCOPE,
		'token_type'   => 'Bearer',
	),
);
$sco_r      = $sco_run(
	array(
		'code'  => '4/0Anorefresh',
		'state' => 'smoke-state-8',
	),
	'smoke-state-8'
);
$ok( 'token_exchange_failed' === $sco_r['status'] && '1//stale-smoke-token' === $sco_stored(), 'sc oauth: a 200 with no refresh_token is a failed exchange, not a half connection' );

// 9. Google could not be reached.
$sco_answer = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$sco_r      = $sco_run(
	array(
		'code'  => '4/0Atimeout',
		'state' => 'smoke-state-9',
	),
	'smoke-state-9'
);
$ok( 'network_error' === $sco_r['status'] && 1 === count( $sco_calls ) && '1//stale-smoke-token' === $sco_stored(), 'sc oauth: a transport error is reported as network_error and changes nothing' );

// 10. The client id and secret were removed between consent and callback.
$sco_keep = Perdita_Search_Console::settings();
update_option( Perdita_Search_Console::OPTION, array_merge( Perdita_Search_Console::defaults(), array( 'refresh_token' => $sco_keep['refresh_token'] ) ) );
$sco_answer = array( 200, array( 'refresh_token' => '1//should-never-be-asked-for' ) );
$sco_r      = $sco_run(
	array(
		'code'  => '4/0Anoclient',
		'state' => 'smoke-state-10',
	),
	'smoke-state-10'
);
$ok( 'missing_client' === $sco_r['status'] && 0 === count( $sco_calls ), 'sc oauth: with the OAuth client gone the callback stops before calling Google' );

remove_filter( 'pre_http_request', $sco_http, 10 );
remove_filter( 'wp_redirect', $sco_stop, 1 );
delete_transient( $sco_state_key );
$_GET = $sco_orig_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restoring the superglobal the test changed.
if ( null === $sco_orig_opt ) {
	delete_option( Perdita_Search_Console::OPTION );
} else {
	update_option( Perdita_Search_Console::OPTION, $sco_orig_opt );
}
wp_set_current_user( $sco_orig_user );
