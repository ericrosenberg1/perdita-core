<?php
/**
 * Perdita Core smoke tests: form and signup throttles behind a proxy, and
 * array input on scalar form fields.
 *
 * Loaded by tests/smoke.php. Regressions:
 *  - the form and subscription throttles switched themselves off whenever an
 *    X-Forwarded-For header was present, and any client can send one.
 *  - fields[email][]=x fataled the form endpoint with a TypeError.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- throttles and form input (tests/smoke-throttle.php) ---\n";

$thr_server = $_SERVER;
$thr_ip     = '203.0.113.' . wp_rand( 1, 250 );
$_SERVER['REMOTE_ADDR']          = $thr_ip;
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

$thr_forms = new Perdita_Forms();
$thr_limit = new ReflectionMethod( 'Perdita_Forms', 'is_rate_limited' );
if ( PHP_VERSION_ID < 80100 ) {
	$thr_limit->setAccessible( true );
}
$thr_cap = function () {
	return 2;
};
add_filter( 'perdita_form_proxied_rate_limit', $thr_cap );
$thr_form_id = 987654;
$thr_r1      = $thr_limit->invoke( $thr_forms, $thr_form_id );
$thr_r2      = $thr_limit->invoke( $thr_forms, $thr_form_id );
$thr_r3      = $thr_limit->invoke( $thr_forms, $thr_form_id );
$ok( false === $thr_r1 && false === $thr_r2 && true === $thr_r3, 'forms throttle: a request carrying X-Forwarded-For is still counted, not waved through' );
remove_filter( 'perdita_form_proxied_rate_limit', $thr_cap );
delete_transient( 'perdita_fl_' . $thr_form_id . '_px_' . md5( $thr_ip ) );

if ( class_exists( 'Perdita_Subscriptions' ) || file_exists( PERDITA_CORE_DIR . 'inc/modules/subscriptions/class-perdita-subscriptions.php' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/modules/subscriptions/class-perdita-subscriptions.php';
	$thr_subs       = ( new ReflectionClass( 'Perdita_Subscriptions' ) )->newInstanceWithoutConstructor();
	$thr_subs_limit = new ReflectionMethod( 'Perdita_Subscriptions', 'is_rate_limited' );
	if ( PHP_VERSION_ID < 80100 ) {
		$thr_subs_limit->setAccessible( true );
	}
	add_filter( 'perdita_subscriptions_proxied_rate_limit', $thr_cap );
	$thr_s1 = $thr_subs_limit->invoke( $thr_subs );
	$thr_s2 = $thr_subs_limit->invoke( $thr_subs );
	$thr_s3 = $thr_subs_limit->invoke( $thr_subs );
	$ok( false === $thr_s1 && false === $thr_s2 && true === $thr_s3, 'subscriptions throttle: a request carrying X-Forwarded-For is still counted' );
	remove_filter( 'perdita_subscriptions_proxied_rate_limit', $thr_cap );
	delete_transient( 'perdita_subs_rl_px_' . md5( $thr_ip ) );
}
$_SERVER = $thr_server;

$thr_sanitize = new ReflectionMethod( 'Perdita_Forms', 'sanitize_value' );
if ( PHP_VERSION_ID < 80100 ) {
	$thr_sanitize->setAccessible( true );
}
$ok( '' === $thr_sanitize->invoke( $thr_forms, 'email', array( 'x@example.com' ) ), 'forms: an array posted for an email field becomes empty instead of reaching is_email()' );
$ok( array( 'a', 'b' ) === $thr_sanitize->invoke( $thr_forms, 'checkbox', array( 'a', 'b' ) ), 'forms: a checkbox field still accepts several values' );

// The AI section route caps the brief, so a Contributor cannot spend the
// site's AI budget on megabytes of input.
$thr_user = get_current_user_id();
wp_set_current_user( 1 );
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}
$thr_req = new WP_REST_Request( 'POST', '/perdita/v1/section' );
$thr_req->set_param( 'description', str_repeat( 'a', 2001 ) );
$thr_res = rest_do_request( $thr_req );
if ( 404 === $thr_res->get_status() ) {
	echo "SKIP  ai section length cap (route not registered on this site)\n";
} else {
	$ok( 400 === $thr_res->get_status(), 'ai section: a brief over 2,000 characters is refused before any AI call' );
}
wp_set_current_user( $thr_user );
