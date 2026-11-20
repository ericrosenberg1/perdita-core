<?php
/**
 * Perdita Core smoke tests: the edge caching pass. Covers the Cache-Control
 * a stored page carries for a CDN (s-maxage), the rule that personal
 * responses always go out private, the Cloudflare Cache Rule expression, the
 * managed host and LiteSpeed switches, the drop-in version, and the WP_CACHE
 * line button.
 *
 * Loaded by tests/smoke.php, so $ok() and the counters are the ones it
 * defines. Pure logic runs against a pre_option filter. The real HTTP checks
 * save the caching settings, request the live rig, and put the settings back.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- edge caching (tests/smoke-caching-edge.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
Perdita_Caching::load( 'edge' );
Perdita_Caching::load( 'hosts' );
Perdita_Caching::load( 'litespeed' );
Perdita_Caching::load( 'tools' );

/* ------------------------------------------------------------------ */
/* Harness.                                                             */
/* ------------------------------------------------------------------ */

$GLOBALS['cse_settings'] = array( 'enabled' => true );
$cse_pre_option          = static function () {
	return $GLOBALS['cse_settings'];
};
$cse_set                 = static function ( array $patch ) {
	$GLOBALS['cse_settings'] = array_merge( array( 'enabled' => true ), $patch );
};
$cse_state_was           = $_SERVER;
$cse_ls_state_was        = get_option( 'perdita_cache_litespeed', null );
$cse_response_lines      = array();
$cse_headers_filter      = static function () use ( &$cse_response_lines ) {
	return $cse_response_lines;
};
$cse_restore_options     = static function ( $name, $was ) {
	if ( null === $was ) {
		delete_option( $name );
	} else {
		update_option( $name, $was );
	}
};

/* ------------------------------------------------------------------ */
/* 1. public_cache_control().                                           */
/* ------------------------------------------------------------------ */

add_filter( 'pre_option_' . Perdita_Caching::OPTION, $cse_pre_option );

$cse_set( array( 'edge_max_age' => 0 ) );
$cse_s = Perdita_Caching::settings();
$ok( 'max-age=300, public' === Perdita_Caching::public_cache_control( $cse_s, 'fresh' ), 'edge: a fresh page with the edge setting at 0 sends max-age=300, public and no s-maxage' );

$cse_set( array( 'edge_max_age' => 3600 ) );
$cse_s = Perdita_Caching::settings();
$ok( false !== strpos( Perdita_Caching::public_cache_control( $cse_s, 'fresh' ), 's-maxage=3600' ), 'edge: a fresh page with the edge setting at 3600 carries s-maxage=3600' );
$ok( 'max-age=300, public, s-maxage=3600' === Perdita_Caching::public_cache_control( $cse_s, 'fresh' ), 'edge: the fresh header is exactly max-age=300, public, s-maxage=3600' );

$cse_stale = Perdita_Caching::public_cache_control( $cse_s, 'stale' );
$ok( 'max-age=0, public' === $cse_stale && false === strpos( $cse_stale, 's-maxage' ), 'edge: a stale page sends max-age=0, public with no s-maxage, even with the edge setting on' );

$cse_set( array( 'edge_max_age' => 3600, 'mobile_cache' => true ) );
$cse_s = Perdita_Caching::settings();
$ok( 0 === Perdita_Caching::edge_max_age( $cse_s ) && false === strpos( Perdita_Caching::public_cache_control( $cse_s, 'fresh' ), 's-maxage' ), 'edge: with the separate phone copy on, no s-maxage goes out, since a CDN keeps one copy per URL' );

$cse_set( array( 'edge_max_age' => 3600 ) );
$cse_raise = static function () {
	return 7200;
};
add_filter( 'perdita_cache_edge_max_age', $cse_raise );
$ok( 7200 === Perdita_Caching::edge_max_age( Perdita_Caching::settings() ), 'edge: the perdita_cache_edge_max_age filter can raise the value' );
remove_filter( 'perdita_cache_edge_max_age', $cse_raise );

$cse_huge = static function () {
	return 99999999;
};
add_filter( 'perdita_cache_edge_max_age', $cse_huge );
$ok( Perdita_Caching::EDGE_MAX_AGE_CAP === Perdita_Caching::edge_max_age( Perdita_Caching::settings() ), 'edge: a filter value past the cap is cut to EDGE_MAX_AGE_CAP' );
remove_filter( 'perdita_cache_edge_max_age', $cse_huge );

$cse_neg = static function () {
	return -50;
};
add_filter( 'perdita_cache_edge_max_age', $cse_neg );
$ok( 0 === Perdita_Caching::edge_max_age( Perdita_Caching::settings() ), 'edge: a negative filter value becomes 0' );
remove_filter( 'perdita_cache_edge_max_age', $cse_neg );

$cse_set( array( 'edge_max_age' => 99999999 ) );
$ok( Perdita_Caching::EDGE_MAX_AGE_CAP === Perdita_Caching::settings()['edge_max_age'], 'edge: a saved value past the cap reads back at the cap' );

/* ------------------------------------------------------------------ */
/* 2. is_private_cache_control().                                       */
/* ------------------------------------------------------------------ */

foreach ( array( 'private, no-store, max-age=0', 'no-store', 'private', 'No-Store', 'max-age=0, PRIVATE' ) as $cse_v ) {
	$ok( true === Perdita_Caching::is_private_cache_control( $cse_v ), 'edge: "' . $cse_v . '" counts as private' );
}
foreach ( array( 'public, max-age=300', 'max-age=300, public, s-maxage=600', '' ) as $cse_v ) {
	$ok( false === Perdita_Caching::is_private_cache_control( $cse_v ), 'edge: "' . $cse_v . '" does not count as private' );
}

/* ------------------------------------------------------------------ */
/* 3. A personal response never goes out public.                        */
/* ------------------------------------------------------------------ */

// header() can't be observed in the CLI, so this reads the decision the
// callback makes: it replaces any Cache-Control that is not private.
$cse_rh = new ReflectionMethod( 'Perdita_Caching', 'response_header' );
if ( PHP_VERSION_ID < 80100 ) {
	$cse_rh->setAccessible( true );
}
add_filter( 'perdita_cache_response_headers', $cse_headers_filter );
$cse_response_lines = array( 'X-Test: 1', 'Cache-Control: public, max-age=3600' );
$cse_seen           = (string) $cse_rh->invoke( null, 'Cache-Control' );
$ok( 'public, max-age=3600' === $cse_seen, 'edge: the page cache reads a queued Cache-Control from the response headers filter' );
foreach ( Perdita_Caching::PERSONAL_SKIPS as $cse_reason ) {
	$ok( false === Perdita_Caching::is_private_cache_control( $cse_seen ), 'edge: a public header queued before a "' . $cse_reason . '" response is not private, so it gets replaced' );
}
$ok( 4 === count( Perdita_Caching::PERSONAL_SKIPS ) && in_array( 'logged in', Perdita_Caching::PERSONAL_SKIPS, true ) && in_array( 'password-protected', Perdita_Caching::PERSONAL_SKIPS, true ) && in_array( 'DONOTCACHEPAGE', Perdita_Caching::PERSONAL_SKIPS, true ) && in_array( 'cookie', Perdita_Caching::PERSONAL_SKIPS, true ), 'edge: the personal skip reasons are logged in, password-protected, DONOTCACHEPAGE, and cookie' );
$cse_response_lines = array( 'Cache-Control: private, no-store, max-age=0' );
$ok( true === Perdita_Caching::is_private_cache_control( (string) $cse_rh->invoke( null, 'Cache-Control' ) ), 'edge: a private header already queued is left as it is' );
$cse_fatal = false;
try {
	$cse_response_lines = array( 'Cache-Control: public, max-age=3600' );
	Perdita_Caching::enforce_personal_no_store();
} catch ( Throwable $cse_e ) {
	$cse_fatal = true;
}
$ok( ! $cse_fatal, 'edge: the header callback runs without error on a public Cache-Control' );
$cse_response_lines = array();
remove_filter( 'perdita_cache_response_headers', $cse_headers_filter );

/* ------------------------------------------------------------------ */
/* 4. Real HTTP against the rig.                                        */
/* ------------------------------------------------------------------ */

remove_filter( 'pre_option_' . Perdita_Caching::OPTION, $cse_pre_option );

$cse_opt_was    = get_option( Perdita_Caching::OPTION, null );
$cse_home       = home_url( '/' );
$cse_reachable  = false;
// Raw HTTP to the rig's server, with the site's own Host header, so a rig
// whose site URL has no port (the setup tests/README.md asks for) still
// answers. PERDITA_TEST_HTTP_PORT names the port the server listens on.
$cse_get        = static function ( $url, $cookie = '' ) {
	$parts = wp_parse_url( $url );
	$host  = (string) ( $parts['host'] ?? 'localhost' );
	$port  = getenv( 'PERDITA_TEST_HTTP_PORT' ) ? (int) getenv( 'PERDITA_TEST_HTTP_PORT' ) : (int) ( $parts['port'] ?? 80 );
	$sock  = @fsockopen( '127.0.0.1', $port, $en, $es, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
	if ( ! $sock ) {
		return null;
	}
	stream_set_timeout( $sock, 30 );
	$host_line = $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	fwrite( $sock, 'GET ' . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . " HTTP/1.0\r\nHost: " . $host_line . "\r\nAccept: text/html\r\nUser-Agent: Mozilla/5.0 (Macintosh) Safari/605\r\n" . ( '' !== $cookie ? 'Cookie: ' . $cookie . "\r\n" : '' ) . "Connection: close\r\n\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	$raw = (string) stream_get_contents( $sock );
	fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	$head    = explode( "\r\n", explode( "\r\n\r\n", $raw, 2 )[0] );
	$headers = array();
	foreach ( array_slice( $head, 1 ) as $line ) {
		$at = strpos( $line, ':' );
		if ( false !== $at ) {
			$name             = strtolower( trim( substr( $line, 0, $at ) ) );
			$headers[ $name ] = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . trim( substr( $line, $at + 1 ) ) : trim( substr( $line, $at + 1 ) );
		}
	}
	return array(
		'headers'  => $headers,
		'response' => array( 'code' => (int) substr( $head[0], 9, 3 ) ),
		'body'     => '',
	);
};
$cse_cc         = static function ( $res ) {
	return null === $res ? null : (string) wp_remote_retrieve_header( $res, 'cache-control' );
};

update_option(
	Perdita_Caching::OPTION,
	array_merge(
		Perdita_Caching::defaults(),
		array(
			'enabled'      => true,
			'edge_max_age' => 3600,
			'mobile_cache' => false,
			'early_serve'  => false,
			'litespeed'    => false,
		)
	)
);
Perdita_Caching::purge_everything();

$cse_probe     = $cse_get( $cse_home );
$cse_reachable = null !== $cse_probe && 200 === (int) wp_remote_retrieve_response_code( $cse_probe );
if ( ! $cse_reachable ) {
	echo "SKIP  edge HTTP checks (the rig at " . $cse_home . " did not answer 200)\n";
} else {
	$cse_second = $cse_get( $cse_home );
	$cse_cc2    = $cse_cc( $cse_second );
	$ok( 'HIT' === strtoupper( (string) wp_remote_retrieve_header( $cse_second, 'x-cache' ) ), 'edge http: the second anonymous request is a cache HIT' );
	$ok( false !== strpos( (string) $cse_cc2, 'public' ) && false !== strpos( (string) $cse_cc2, 's-maxage=3600' ), 'edge http: an anonymous HIT carries Cache-Control public and s-maxage=3600 (got "' . $cse_cc2 . '")' );

	$cse_personal = array(
		'a logged-in cookie'      => 'wordpress_logged_in_x=1',
		'a WooCommerce cart'      => 'woocommerce_items_in_cart=1',
		'a comment author cookie' => 'comment_author_x=1',
		'a post password cookie'  => 'wp-postpass_x=1',
	);
	foreach ( $cse_personal as $cse_label => $cse_cookie ) {
		$cse_res = $cse_get( $cse_home, $cse_cookie );
		$cse_val = (string) $cse_cc( $cse_res );
		$ok( null !== $cse_res && false !== strpos( $cse_val, 'private' ) && false !== strpos( $cse_val, 'no-store' ) && false === strpos( $cse_val, 's-maxage' ) && false === strpos( $cse_val, 'public' ), 'edge http: a request with ' . $cse_label . ' gets private, no-store and no s-maxage (got "' . $cse_val . '")' );
	}

	$cse_res = $cse_get( add_query_arg( 's', 'x', $cse_home ) );
	$cse_val = (string) $cse_cc( $cse_res );
	$ok( null !== $cse_res && false !== strpos( $cse_val, 'private' ) && false !== strpos( $cse_val, 'no-store' ) && false === strpos( $cse_val, 's-maxage' ), 'edge http: a search request (?s=x) goes out private, no-store (got "' . $cse_val . '")' );

	// With the edge setting off, no s-maxage reaches a CDN.
	update_option( Perdita_Caching::OPTION, array_merge( Perdita_Caching::settings(), array( 'edge_max_age' => 0 ) ) );
	Perdita_Caching::purge_everything();
	$cse_get( $cse_home );
	$cse_off = $cse_get( $cse_home );
	$cse_val = (string) $cse_cc( $cse_off );
	$ok( false !== strpos( $cse_val, 'public' ) && false === strpos( $cse_val, 's-maxage' ), 'edge http: with the edge setting at 0, an anonymous HIT carries no s-maxage (got "' . $cse_val . '")' );

	// Early serve needs WP_CACHE and a written drop-in.
	if ( Perdita_Caching::wp_cache_on() ) {
		update_option( Perdita_Caching::OPTION, array_merge( Perdita_Caching::settings(), array( 'edge_max_age' => 3600, 'early_serve' => true ) ) );
		Perdita_Caching::purge_everything();
		$cse_get( $cse_home );
		$cse_early = $cse_get( $cse_home );
		$cse_val   = (string) $cse_cc( $cse_early );
		$ok( false !== strpos( $cse_val, 's-maxage=3600' ), 'edge http: with early serve on, an anonymous HIT still carries s-maxage=3600 (got "' . $cse_val . '")' );
		$cse_res = $cse_get( $cse_home, 'wordpress_logged_in_x=1' );
		$cse_val = (string) $cse_cc( $cse_res );
		$ok( false !== strpos( $cse_val, 'private' ) && false === strpos( $cse_val, 's-maxage' ), 'edge http: with early serve on, a logged-in cookie still gets private and no s-maxage (got "' . $cse_val . '")' );
	} else {
		echo "SKIP  edge http early serve (WP_CACHE is not defined on this site)\n";
	}
}
Perdita_Caching::purge_everything();
$cse_restore_options( Perdita_Caching::OPTION, $cse_opt_was );
add_filter( 'pre_option_' . Perdita_Caching::OPTION, $cse_pre_option );

/* ------------------------------------------------------------------ */
/* 5. The Cloudflare rule expression.                                   */
/* ------------------------------------------------------------------ */

$cse_set( array( 'enabled' => true, 'mobile_cache' => false, 'reject_cookies' => array( 'my_owner_cookie' ) ) );
$cse_expr = Perdita_Caching_Edge::expression();
$ok( is_string( $cse_expr ) && 0 === strpos( $cse_expr, '(' ), 'edge rule: the expression is a string that starts with an opening parenthesis' );
if ( is_string( $cse_expr ) ) {
	$cse_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$ok( false !== strpos( $cse_expr, 'http.host eq "' . $cse_host . '"' ), 'edge rule: it matches this site\'s host name' );
	$ok( false !== strpos( $cse_expr, 'http.request.uri.query eq ""' ), 'edge rule: it matches only requests with no query string' );
	foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php' ) as $cse_path ) {
		$ok( false !== strpos( $cse_expr, 'not starts_with(http.request.uri.path, "' . $cse_path . '")' ), 'edge rule: it skips ' . $cse_path );
	}
	foreach ( array( 'wordpress_logged_in', 'wp-postpass_', 'comment_author', 'woocommerce_', 'wp_woocommerce_session', 'perdita_cart', 'my_owner_cookie' ) as $cse_cookie ) {
		$ok( false !== strpos( $cse_expr, 'not lower(http.cookie) contains "' . $cse_cookie . '"' ), 'edge rule: it skips a visitor with the "' . $cse_cookie . '" cookie' );
	}
	$ok( strlen( $cse_expr ) <= Perdita_Caching_Edge::MAX_EXPRESSION, 'edge rule: the expression is within 4096 characters' );
}

$cse_set( array( 'enabled' => true, 'reject_cookies' => array( 'bad"cookie', 'good_cookie' ) ) );
$cse_expr = Perdita_Caching_Edge::expression();
$ok( is_string( $cse_expr ) && false === strpos( $cse_expr, 'bad"cookie' ) && false === strpos( $cse_expr, 'bad' ) && false !== strpos( $cse_expr, '"good_cookie"' ), 'edge rule: a cookie value with a double quote is dropped and the good one stays' );
$ok( '' === Perdita_Caching_Edge::literal( 'a"b' ) && '' === Perdita_Caching_Edge::literal( "a\nb" ) && '"ok"' === Perdita_Caching_Edge::literal( 'ok' ), 'edge rule: a string literal refuses quotes and control characters' );

$cse_long = array();
for ( $cse_i = 0; $cse_i < 400; $cse_i++ ) {
	$cse_long[] = 'cookie_number_' . $cse_i . '_padding';
}
$cse_set( array( 'enabled' => true, 'reject_cookies' => $cse_long ) );
$cse_expr = Perdita_Caching_Edge::expression();
$ok( is_wp_error( $cse_expr ) && 'perdita_edge_long' === $cse_expr->get_error_code(), 'edge rule: an expression past 4096 characters gives an error instead of a rule' );

$cse_set( array( 'enabled' => true, 'mobile_cache' => false ) );
$ok( true === Perdita_Caching_Edge::check_settings(), 'edge rule: settings with the page cache on and no phone copy pass the check' );
$cse_set( array( 'enabled' => true, 'mobile_cache' => true ) );
$cse_chk = Perdita_Caching_Edge::check_settings();
$ok( is_wp_error( $cse_chk ) && 'perdita_edge_mobile' === $cse_chk->get_error_code(), 'edge rule: the check fails with the separate phone copy on' );
$cse_set( array( 'enabled' => false ) );
$cse_chk = Perdita_Caching_Edge::check_settings();
$ok( is_wp_error( $cse_chk ) && 'perdita_edge_off' === $cse_chk->get_error_code(), 'edge rule: the check fails with the page cache off' );

/* ------------------------------------------------------------------ */
/* 6. Managed hosts.                                                    */
/* ------------------------------------------------------------------ */

$cse_set( array( 'enabled' => true ) );
$cse_host_filter = static function () {
	return 'wpengine';
};
$ok( '' === Perdita_Caching::other_page_cache(), 'hosts: with no managed host declared, no other page cache is named' );
add_filter( 'perdita_cache_managed_host', $cse_host_filter );
$ok( 'WP Engine' === Perdita_Caching::other_page_cache(), 'hosts: declaring wpengine makes the page cache stand down for WP Engine' );
remove_filter( 'perdita_cache_managed_host', $cse_host_filter );

$cse_bad_host = static function () {
	return 'Bad Host!';
};
add_filter( 'perdita_cache_managed_host', $cse_bad_host );
$ok( '' === Perdita_Caching::other_page_cache() && '' === Perdita_Caching::managed_host(), 'hosts: a host label with spaces or capitals is refused' );
remove_filter( 'perdita_cache_managed_host', $cse_bad_host );
$ok( '' === Perdita_Caching::other_page_cache(), 'hosts: removing the filter puts it back to no host' );

// A WpeCommon test double, only when no real one is loaded.
$cse_fake_wpe = false;
if ( ! class_exists( 'WpeCommon', false ) ) {
	$cse_fake_wpe = true;
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine,Squiz.Commenting.ClassComment.Missing -- test double.
	eval( 'class WpeCommon { public static $calls = array(); public static function purge_varnish_cache( $post_id = null ) { self::$calls[] = $post_id; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a test double defined only when WP Engine\'s class is absent.
}
if ( $cse_fake_wpe ) {
	$cse_post = wp_insert_post(
		array(
			'post_title'  => 'Edge host test',
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
	add_filter( 'perdita_cache_managed_host', $cse_host_filter );
	Perdita_Caching_Hosts::reset();
	WpeCommon::$calls = array();
	Perdita_Caching_Hosts::purge_all();
	$ok( array( null ) === WpeCommon::$calls, 'hosts: purge_all on WP Engine calls purge_varnish_cache once with no post id' );
	Perdita_Caching_Hosts::purge_all();
	$ok( 1 === count( WpeCommon::$calls ), 'hosts: a second purge_all in the same request does nothing' );

	Perdita_Caching_Hosts::reset();
	WpeCommon::$calls = array();
	Perdita_Caching_Hosts::purge_url( get_permalink( $cse_post ) );
	$ok( array( (int) $cse_post ) === WpeCommon::$calls, 'hosts: purge_url for a post on WP Engine passes the post id' );
	remove_filter( 'perdita_cache_managed_host', $cse_host_filter );
	Perdita_Caching_Hosts::reset();
	WpeCommon::$calls = array();
	Perdita_Caching_Hosts::purge_all();
	$ok( array() === WpeCommon::$calls, 'hosts: with no managed host, purge_all never calls WP Engine' );
	wp_delete_post( $cse_post, true );
} else {
	echo "SKIP  hosts purge calls (a real WpeCommon class is loaded)\n";
}
Perdita_Caching_Hosts::reset();
$ok( 'WP Engine' === Perdita_Caching_Hosts::label( 'wpengine' ) && 'Foo' === Perdita_Caching_Hosts::label( 'foo' ), 'hosts: a known host has a display name and an unknown one is capitalized' );

/* ------------------------------------------------------------------ */
/* 7. LiteSpeed.                                                        */
/* ------------------------------------------------------------------ */

$cse_set( array( 'enabled' => true, 'litespeed' => true, 'mobile_cache' => false ) );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
delete_option( 'perdita_cache_litespeed' );
$ok( false === Perdita_Caching::litespeed_active( Perdita_Caching::settings() ), 'litespeed: not active when the server is not LiteSpeed' );

$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
$ok( false === Perdita_Caching::litespeed_active( Perdita_Caching::settings() ), 'litespeed: not active on LiteSpeed until the live check has passed' );
update_option( 'perdita_cache_litespeed', array( 'ok' => true ) );
$ok( true === Perdita_Caching::litespeed_active( Perdita_Caching::settings() ), 'litespeed: active with the setting on, a LiteSpeed server, and a passed check' );
$cse_set( array( 'enabled' => true, 'litespeed' => false ) );
$ok( false === Perdita_Caching::litespeed_active( Perdita_Caching::settings() ), 'litespeed: not active with the setting off' );
$cse_set( array( 'enabled' => true, 'litespeed' => true, 'mobile_cache' => true ) );
$ok( false === Perdita_Caching::litespeed_active( Perdita_Caching::settings() ), 'litespeed: not active with the separate phone copy on' );
$cse_set( array( 'enabled' => true, 'litespeed' => true ) );

$cse_site = Perdita_Caching::litespeed_site_tag();
$cse_hdr  = Perdita_Caching_LiteSpeed::purge_header( array( $cse_site, $cse_site . '_abc123' ) );
$ok( 'public, tag=' . $cse_site === $cse_hdr, 'litespeed: a purge that names the site tag collapses to the site tag alone (got "' . $cse_hdr . '")' );
$cse_hdr2 = Perdita_Caching_LiteSpeed::purge_header( array( $cse_site . '_abc123' ) );
$ok( 'public, tag=' . $cse_site . '_abc123' === $cse_hdr2, 'litespeed: a purge for one page keeps that page\'s tag' );

$cse_rules = implode( "\n", Perdita_Caching_LiteSpeed::htaccess_rules() );
$ok( false !== strpos( $cse_rules, 'CacheLookup on' ), 'litespeed: the .htaccess block turns CacheLookup on' );
$ok( 1 === preg_match( '/RewriteCond %\{HTTP_COOKIE\} \([^)]*wordpress_logged_in[^)]*\)/', $cse_rules ), 'litespeed: the .htaccess block has a cookie condition that includes wordpress_logged_in' );
$ok( false !== strpos( $cse_rules, 'QUERY_STRING' ), 'litespeed: the .htaccess block also skips requests with a query string' );

$_SERVER = $cse_state_was;
$cse_restore_options( 'perdita_cache_litespeed', $cse_ls_state_was );

/* ------------------------------------------------------------------ */
/* 8. The drop-in.                                                      */
/* ------------------------------------------------------------------ */

$cse_set( array( 'enabled' => true ) );
$ok( '5' === Perdita_Caching::DROPIN_VERSION, 'drop-in: the version is 5' );
$ok( false !== strpos( Perdita_Caching::dropin_source(), 's-maxage' ), 'drop-in: its source can send s-maxage' );

/* ------------------------------------------------------------------ */
/* 9. The WP_CACHE line button.                                         */
/* ------------------------------------------------------------------ */

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$cse_live_cfg    = Perdita_Switch_Leftovers::config_path();
$cse_live_before = '' !== $cse_live_cfg && is_file( $cse_live_cfg ) ? md5_file( $cse_live_cfg ) : '';
$cse_tmp         = trailingslashit( get_temp_dir() ) . 'perdita-edge-' . wp_generate_password( 6, false ) . '/';
wp_mkdir_p( $cse_tmp );
$cse_cfg         = $cse_tmp . 'wp-config.php';
$cse_stock       = "<?php\ndefine( 'DB_NAME', 'x' );\n\n/* That's all, stop editing! Happy publishing. */\nrequire_once ABSPATH . 'wp-settings.php';\n";
file_put_contents( $cse_cfg, $cse_stock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
chmod( $cse_cfg, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
$cse_cfg_filter  = static function () use ( $cse_cfg ) {
	return $cse_cfg;
};
$cse_added_was   = get_option( Perdita_Switch_Leftovers::WP_CACHE_ADDED, null );
$cse_bk_before   = wp_list_pluck( Perdita_Switch_Backups::all(), 'name' );

$cse_set( array( 'enabled' => true, 'early_serve' => false ) );
add_filter( 'perdita_switch_wp_config_path', $cse_cfg_filter );
$cse_add  = Perdita_Caching_Tools::wp_cache_add();
$cse_body = (string) file_get_contents( $cse_cfg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( ! empty( $cse_add['ok'] ) && 1 === substr_count( $cse_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ), 'tools: adding WP_CACHE writes the Perdita line once to the config copy' );
$ok( true === Perdita_Switch_Leftovers::php_defines_wp_cache( $cse_body ), 'tools: the edited config copy defines WP_CACHE' );
try {
	token_get_all( $cse_body, TOKEN_PARSE );
	$cse_parses = true;
} catch ( Throwable $cse_e ) {
	$cse_parses = false;
}
$ok( $cse_parses, 'tools: the edited config copy is still valid PHP' );

$cse_rem  = Perdita_Caching_Tools::wp_cache_remove();
$cse_body = (string) file_get_contents( $cse_cfg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( ! empty( $cse_rem['ok'] ) && false === strpos( $cse_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ), 'tools: removing WP_CACHE takes the line out again with early serve off' );
$ok( $cse_stock === $cse_body, 'tools: after the add and the remove the config copy is byte for byte what it was' );
remove_filter( 'perdita_switch_wp_config_path', $cse_cfg_filter );

foreach ( array_diff( wp_list_pluck( Perdita_Switch_Backups::all(), 'name' ), $cse_bk_before ) as $cse_note ) {
	wp_delete_file( Perdita_Switch_Backups::dir() . $cse_note );
}
$cse_restore_options( Perdita_Switch_Leftovers::WP_CACHE_ADDED, $cse_added_was );
wp_delete_file( $cse_cfg );
@rmdir( $cse_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
$cse_live_after = '' !== $cse_live_cfg && is_file( $cse_live_cfg ) ? md5_file( $cse_live_cfg ) : '';
$ok( $cse_live_before === $cse_live_after, 'tools: the live wp-config.php is unchanged by these tests' );

/* ------------------------------------------------------------------ */
/* Clean up.                                                            */
/* ------------------------------------------------------------------ */

remove_filter( 'pre_option_' . Perdita_Caching::OPTION, $cse_pre_option );
$_SERVER = $cse_state_was;
