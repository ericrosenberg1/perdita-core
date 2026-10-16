<?php
/**
 * Perdita Core smoke tests: PageSpeed Insights at the wire.
 *
 * Loaded by tests/smoke.php. The main suite only covers the API key
 * handling. This drives run_test() with the request intercepted at
 * pre_http_request, checks the query PSI receives (a single-encoded url, the
 * decrypted key, the strategy) and answers in the documented v5 runPagespeed
 * shape: field data in loadingExperience.metrics, lab data in
 * lighthouseResult.audits, and Google's error envelope on a 4xx.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- pagespeed google request (tests/smoke-pagespeed-http.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/pagespeed/class-perdita-pagespeed.php';

$psh_orig = get_option( Perdita_Pagespeed::OPTION );
update_option( Perdita_Pagespeed::OPTION, Perdita_Pagespeed::sanitize( array( 'api_key' => 'AIza-smoke-psi-key' ), '' ) );
Perdita_Pagespeed::clear_cache();

$psh_calls  = array();
$psh_answer = array();
$psh_hook   = function ( $pre, $args, $url ) use ( &$psh_calls, &$psh_answer ) {
	if ( 0 !== strpos( $url, Perdita_Pagespeed::API_URL ) ) {
		return $pre;
	}
	$psh_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array(
		'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
		'body'     => wp_json_encode( $psh_answer[1] ),
		'response' => array(
			'code'    => $psh_answer[0],
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $psh_hook, 10, 3 );

$psh_lab = array(
	'categories' => array( 'performance' => array( 'score' => 0.87 ) ),
	'audits'     => array(
		'largest-contentful-paint' => array( 'numericValue' => 3120.4 ),
		'cumulative-layout-shift'  => array( 'numericValue' => 0.0412 ),
		'total-blocking-time'      => array( 'numericValue' => 210 ),
	),
);

// Field data present: Chrome UX Report percentiles win over the lab run.
$psh_answer = array(
	200,
	array(
		'id'                => home_url( '/' ),
		'loadingExperience' => array(
			'metrics' => array(
				'LARGEST_CONTENTFUL_PAINT_MS'   => array(
					'percentile' => 2400,
					'category'   => 'FAST',
				),
				'CUMULATIVE_LAYOUT_SHIFT_SCORE' => array(
					'percentile' => 5,
					'category'   => 'FAST',
				),
				'INTERACTION_TO_NEXT_PAINT'     => array(
					'percentile' => 180,
					'category'   => 'FAST',
				),
			),
		),
		'lighthouseResult'  => $psh_lab,
	),
);
$psh_res    = Perdita_Pagespeed::run_test( 'desktop', true );
$psh_q      = array();
wp_parse_str( (string) wp_parse_url( $psh_calls[0]['url'] ?? '', PHP_URL_QUERY ), $psh_q );
$ok( 1 === count( $psh_calls ) && 'GET' === ( $psh_calls[0]['args']['method'] ?? '' ), 'pagespeed http: one GET to the runPagespeed endpoint' );
$ok( Perdita_Pagespeed::url_under_test() === ( $psh_q['url'] ?? '' ), 'pagespeed http: PSI receives the page URL encoded once, not twice' );
$ok( 'AIza-smoke-psi-key' === ( $psh_q['key'] ?? '' ) && 'desktop' === ( $psh_q['strategy'] ?? '' ) && 'performance' === ( $psh_q['category'] ?? '' ), 'pagespeed http: the query carries the decrypted key, the strategy and the performance category' );
$ok( is_array( $psh_res ) && 87 === $psh_res['score'] && false === $psh_res['cached'], 'pagespeed http: a 0.87 Lighthouse score reads as 87' );
$ok( is_array( $psh_res ) && 2.4 === $psh_res['lcp']['value'] && 'field' === $psh_res['lcp']['source'], 'pagespeed http: field LCP 2400 ms reads as 2.4 s' );
$ok( is_array( $psh_res ) && 0.05 === $psh_res['cls']['value'] && 'field' === $psh_res['cls']['source'], 'pagespeed http: field CLS percentile 5 reads as 0.05' );
$ok( is_array( $psh_res ) && 'inp' === $psh_res['tbt_inp']['metric'] && 180.0 === $psh_res['tbt_inp']['value'], 'pagespeed http: field INP is used when Google has it' );

$psh_calls = array();
$psh_again = Perdita_Pagespeed::run_test( 'desktop' );
$ok( array() === $psh_calls && is_array( $psh_again ) && true === $psh_again['cached'], 'pagespeed http: a second run within the cache window makes no request' );

// No field data (a low-traffic page): the lab audits fill in.
$psh_answer = array(
	200,
	array(
		'loadingExperience' => array( 'id' => home_url( '/' ) ),
		'lighthouseResult'  => $psh_lab,
	),
);
$psh_res    = Perdita_Pagespeed::run_test( 'mobile', true );
$ok( is_array( $psh_res ) && 3.12 === $psh_res['lcp']['value'] && 'lab' === $psh_res['lcp']['source'], 'pagespeed http: lab LCP 3120.4 ms reads as 3.12 s' );
$ok( is_array( $psh_res ) && 0.041 === $psh_res['cls']['value'] && 'tbt' === $psh_res['tbt_inp']['metric'] && 210.0 === $psh_res['tbt_inp']['value'], 'pagespeed http: lab CLS and TBT fill in without field data' );

// Google's error envelope on a rejected key.
Perdita_Pagespeed::clear_cache();
$psh_answer = array(
	400,
	array(
		'error' => array(
			'code'    => 400,
			'message' => 'API key not valid. Please pass a valid API key.',
			'status'  => 'INVALID_ARGUMENT',
		),
	),
);
$psh_res    = Perdita_Pagespeed::run_test( 'mobile', true );
$ok( is_wp_error( $psh_res ) && 'API key not valid. Please pass a valid API key.' === $psh_res->get_error_message(), 'pagespeed http: Google\'s own error message reaches the admin' );
$psh_calls = array();
Perdita_Pagespeed::run_test( 'mobile' );
$ok( 1 === count( $psh_calls ), 'pagespeed http: a failed run is not cached' );

// A home URL with its own query string, as a language plugin can produce.
$psh_lang = function ( $url ) {
	return add_query_arg(
		array(
			'lang' => 'fr',
			'x'    => '1',
		),
		$url
	);
};
add_filter( 'home_url', $psh_lang );
Perdita_Pagespeed::clear_cache();
$psh_answer = array(
	200,
	array( 'lighthouseResult' => $psh_lab ),
);
$psh_calls  = array();
Perdita_Pagespeed::run_test( 'mobile', true );
$psh_q = array();
wp_parse_str( (string) wp_parse_url( $psh_calls[0]['url'] ?? '', PHP_URL_QUERY ), $psh_q );
$psh_want = Perdita_Pagespeed::url_under_test();
$psh_got  = $psh_q['url'] ?? '';
$ok( false !== strpos( $psh_want, '&x=1' ) && $psh_want === $psh_got && 'mobile' === ( $psh_q['strategy'] ?? '' ), 'pagespeed http: a home URL with its own query string reaches PSI whole' );
remove_filter( 'home_url', $psh_lang );
Perdita_Pagespeed::clear_cache();

remove_filter( 'pre_http_request', $psh_hook, 10 );
Perdita_Pagespeed::clear_cache();
if ( false === $psh_orig ) {
	delete_option( Perdita_Pagespeed::OPTION );
} else {
	update_option( Perdita_Pagespeed::OPTION, $psh_orig );
}
unset( $psh_lang, $psh_want, $psh_got, $psh_orig, $psh_calls, $psh_answer, $psh_hook, $psh_lab, $psh_res, $psh_q, $psh_again );
