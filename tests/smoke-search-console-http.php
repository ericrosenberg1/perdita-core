<?php
/**
 * Perdita Core smoke tests: Search Console's Google calls at the wire.
 *
 * Loaded by tests/smoke.php. The main suite only covers Search Console's
 * settings handling. This drives get_data() end to end with every Google
 * request intercepted at pre_http_request: the refresh-token exchange,
 * both searchAnalytics queries, and the failure paths that must clear a
 * dead token or refuse to cache a failed read.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- search console google requests (tests/smoke-search-console-http.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/search-console/class-perdita-search-console.php';

$sch_orig = get_option( Perdita_Search_Console::OPTION );
$sch_site = 'sc-domain:example.test';
update_option( Perdita_Search_Console::OPTION, array_merge( Perdita_Search_Console::defaults(), Perdita_Search_Console::sanitize_client( array( 'client_id' => 'smoke-client.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-smoke-secret' ) ) ) );
Perdita_Search_Console::store_refresh_token( '1//smoke-refresh-token' );
Perdita_Search_Console::store_site_url( $sch_site );
Perdita_Search_Console::clear_cache();

$sch_calls = array();
$sch_token = array( 200, array( 'access_token' => 'ya29.smoke', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'token_type' => 'Bearer' ) );
$sch_query = array( 200, null );
$sch_hook  = function ( $pre, $args, $url ) use ( &$sch_calls, &$sch_token, &$sch_query ) {
	if ( 0 === strpos( $url, Perdita_Search_Console::TOKEN_URL ) ) {
		$answer = $sch_token;
	} elseif ( 0 === strpos( $url, Perdita_Search_Console::API_BASE ) ) {
		$answer = $sch_query;
		if ( null === $answer[1] ) {
			$req    = json_decode( (string) $args['body'], true );
			$answer = array(
				200,
				empty( $req['dimensions'] )
					? array( 'rows' => array( array( 'clicks' => 42, 'impressions' => 1200, 'ctr' => 0.035, 'position' => 8.4 ) ), 'responseAggregationType' => 'byProperty' )
					: array( 'rows' => array( array( 'keys' => array( 'perdita theme' ), 'clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 3.2 ) ), 'responseAggregationType' => 'byProperty' ),
			);
		}
	} else {
		return $pre;
	}
	$sch_calls[] = array( 'url' => $url, 'args' => $args );
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $answer[1] ),
		'response' => array( 'code' => $answer[0], 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $sch_hook, 10, 3 );

$sch_data = Perdita_Search_Console::get_data( true );
$ok( 3 === count( $sch_calls ), 'search console http: one token refresh and two searchAnalytics queries' );
$sch_tok = $sch_calls[0]['args'] ?? array();
$ok( Perdita_Search_Console::TOKEN_URL === ( $sch_calls[0]['url'] ?? '' ) && 'POST' === ( $sch_tok['method'] ?? '' ), 'search console http: the token refresh POSTs to oauth2.googleapis.com/token' );
$ok( 'refresh_token' === ( $sch_tok['body']['grant_type'] ?? '' ) && '1//smoke-refresh-token' === ( $sch_tok['body']['refresh_token'] ?? '' ), 'search console http: the refresh grant carries the decrypted refresh token' );
$ok( 'GOCSPX-smoke-secret' === ( $sch_tok['body']['client_secret'] ?? '' ) && 'smoke-client.apps.googleusercontent.com' === ( $sch_tok['body']['client_id'] ?? '' ), 'search console http: the refresh sends the client id and the decrypted client secret' );

$sch_q = $sch_calls[1] ?? array( 'url' => '', 'args' => array() );
$ok( Perdita_Search_Console::API_BASE . '/sites/sc-domain%3Aexample.test/searchAnalytics/query' === $sch_q['url'], 'search console http: a domain property is percent-encoded into the query path' );
$ok( 'Bearer ya29.smoke' === ( $sch_q['args']['headers']['Authorization'] ?? '' ), 'search console http: the query sends the fresh access token as a Bearer header' );
$sch_qbody = json_decode( (string) ( $sch_q['args']['body'] ?? '' ), true );
$ok( is_array( $sch_qbody ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sch_qbody['startDate'] ?? '' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sch_qbody['endDate'] ?? '' ) && $sch_qbody['startDate'] < $sch_qbody['endDate'], 'search console http: the query body carries a Y-m-d date range' );
$sch_q2 = json_decode( (string) ( $sch_calls[2]['args']['body'] ?? '' ), true );
$ok( array( 'query' ) === ( $sch_q2['dimensions'] ?? null ) && 10 === ( $sch_q2['rowLimit'] ?? null ), 'search console http: the second query asks for the top 10 queries' );

$ok( is_array( $sch_data ) && 42.0 === $sch_data['totals']['clicks'] && 1200.0 === $sch_data['totals']['impressions'], 'search console http: totals come from the first query\'s row' );
$ok( is_array( $sch_data ) && 'perdita theme' === ( $sch_data['queries'][0]['query'] ?? '' ) && 12.0 === $sch_data['queries'][0]['clicks'], 'search console http: the top query row is parsed from keys[0]' );

$sch_calls = array();
$ok( $sch_data === Perdita_Search_Console::get_data() && array() === $sch_calls, 'search console http: a second read comes from the cache with no request' );

// A 403 from the API (the account lost access to the property).
$sch_query = array( 403, array( 'error' => array( 'code' => 403, 'message' => 'User does not have sufficient permission for site', 'status' => 'PERMISSION_DENIED' ) ) );
Perdita_Search_Console::clear_cache();
$sch_err = Perdita_Search_Console::get_data( true );
$ok( is_wp_error( $sch_err ) && 'sc_query_failed' === $sch_err->get_error_code(), 'search console http: a 403 query becomes sc_query_failed' );
$sch_calls = array();
Perdita_Search_Console::get_data();
$ok( count( $sch_calls ) > 0, 'search console http: a failed read is not cached, the next view asks Google again' );
$ok( Perdita_Search_Console::is_connected(), 'search console http: a query failure keeps the refresh token' );

// Google revoked the grant.
$sch_token = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
$sch_err   = Perdita_Search_Console::get_data( true );
$ok( is_wp_error( $sch_err ) && 'invalid_grant' === $sch_err->get_error_code(), 'search console http: invalid_grant surfaces as its own error code' );
$ok( ! Perdita_Search_Console::is_connected(), 'search console http: invalid_grant clears the dead refresh token so the screen prompts a reconnect' );

// Any other token failure keeps the token.
Perdita_Search_Console::store_refresh_token( '1//smoke-refresh-token' );
$sch_token = array( 500, array( 'error' => 'internal_failure' ) );
$sch_err   = Perdita_Search_Console::get_data( true );
$ok( is_wp_error( $sch_err ) && 'token_refresh_failed' === $sch_err->get_error_code() && Perdita_Search_Console::is_connected(), 'search console http: a 500 from the token endpoint is token_refresh_failed and keeps the refresh token' );

remove_filter( 'pre_http_request', $sch_hook, 10 );
Perdita_Search_Console::clear_cache();
if ( false === $sch_orig ) {
	delete_option( Perdita_Search_Console::OPTION );
} else {
	update_option( Perdita_Search_Console::OPTION, $sch_orig );
}
unset( $sch_orig, $sch_site, $sch_calls, $sch_token, $sch_query, $sch_hook, $sch_data, $sch_tok, $sch_q, $sch_qbody, $sch_q2, $sch_err );
