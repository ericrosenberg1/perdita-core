<?php
/**
 * Perdita Core smoke tests: the page cache hardening pass. Each block pins
 * one finding from the adversarial review of the cache parity work:
 * DONOTCACHEPAGE, responses that set cookies, purges racing a render, the
 * phone test, Cache-Control on every outcome, made-up query values and the
 * size cap, WP-CLI purge arguments, uninstall, unnormalized paths, the
 * drop-in's log, the purge triggers, option writes and preload restarts,
 * the CLI preload loop, the preloader's own-URL check, multisite, filter
 * values the drop-in is written with, the gzip copy, and the smart purge
 * on a post save.
 *
 * Loaded by tests/smoke.php, so $ok() and the counters are the ones it
 * defines. Settings go through a pre_option filter. The cron array, the
 * preload state, every post, term and file this fragment makes, and every
 * hook it adds are put back at the end, and again from a shutdown function
 * if a fatal cuts the run short.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- page cache hardening (tests/smoke-caching-hardening.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
Perdita_Caching::load( 'tools' );
Perdita_Caching::load( 'preload' );

/* ------------------------------------------------------------------ */
/* Harness.                                                             */
/* ------------------------------------------------------------------ */

$GLOBALS['chd_settings'] = array( 'enabled' => true );
$chd_pre_option          = static function () {
	return $GLOBALS['chd_settings'];
};
add_filter( 'pre_option_' . Perdita_Caching::OPTION, $chd_pre_option );
$chd_set     = static function ( array $patch ) {
	$GLOBALS['chd_settings'] = array_merge( array( 'enabled' => true ), $patch );
};
$chd_core_on = static function ( $value ) {
	$value = is_array( $value ) ? $value : array();
	if ( ! isset( $value['modules'] ) || ! is_array( $value['modules'] ) ) {
		$value['modules'] = array();
	}
	$value['modules']['caching'] = true;
	return $value;
};
add_filter( 'option_perdita_core', $chd_core_on );
add_filter( 'default_option_perdita_core', $chd_core_on );

Perdita_Caching::install();
$chd_dir     = Perdita_Caching::cache_dir();
$chd_host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$chd_before  = array_map( 'basename', (array) glob( $chd_dir . '{,.}[!.]*', GLOB_BRACE ) );
$chd_cron    = _get_cron_array();
$chd_preload = get_option( Perdita_Caching_Preload::OPTION, null );
$chd_state   = array(
	'get'    => $_GET,
	'cookie' => $_COOKIE,
	'server' => $_SERVER,
	'user'   => get_current_user_id(),
);
$chd_php     = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
$chd_tmp     = trailingslashit( get_temp_dir() ) . 'perdita-hardening-' . wp_generate_password( 6, false ) . '/';
wp_mkdir_p( $chd_tmp );
$GLOBALS['chd_posts']  = array();
$GLOBALS['chd_terms']  = array();
$GLOBALS['chd_server'] = null;

// Every hook this fragment's own Perdita_Caching instance adds is found by
// comparing against this list, and removed at the end.
$chd_hook_ids = static function () {
	$ids = array();
	foreach ( $GLOBALS['wp_filter'] as $hook => $obj ) {
		foreach ( $obj->callbacks as $priority => $callbacks ) {
			foreach ( array_keys( $callbacks ) as $idx ) {
				$ids[ $hook . '|' . $priority . '|' . $idx ] = true;
			}
		}
	}
	return $ids;
};

$GLOBALS['chd_cleanup_done'] = false;
$chd_cleanup                 = static function () use ( $chd_dir, $chd_before, $chd_cron, $chd_preload, $chd_pre_option, $chd_core_on, $chd_tmp ) {
	if ( $GLOBALS['chd_cleanup_done'] ) {
		return;
	}
	$GLOBALS['chd_cleanup_done'] = true;
	if ( is_resource( $GLOBALS['chd_server'] ) ) {
		proc_terminate( $GLOBALS['chd_server'] );
		proc_close( $GLOBALS['chd_server'] );
	}
	foreach ( $GLOBALS['chd_posts'] as $chd_id ) {
		wp_delete_post( $chd_id, true );
	}
	foreach ( $GLOBALS['chd_terms'] as $chd_tid ) {
		wp_delete_term( $chd_tid, 'category' );
	}
	remove_filter( 'pre_option_' . Perdita_Caching::OPTION, $chd_pre_option );
	remove_filter( 'option_perdita_core', $chd_core_on );
	remove_filter( 'default_option_perdita_core', $chd_core_on );
	if ( null === $chd_preload ) {
		delete_option( Perdita_Caching_Preload::OPTION );
	} else {
		update_option( Perdita_Caching_Preload::OPTION, $chd_preload, false );
	}
	delete_transient( Perdita_Caching_Preload::LOCK );
	_set_cron_array( $chd_cron );
	foreach ( (array) glob( $chd_dir . '{,.}[!.]*', GLOB_BRACE ) as $chd_f ) {
		if ( is_file( $chd_f ) && ! in_array( basename( $chd_f ), array_merge( $chd_before, array( '.htaccess', 'index.php' ) ), true ) ) {
			unlink( $chd_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	foreach ( (array) glob( $chd_tmp . '{,.}[!.]*', GLOB_BRACE ) as $chd_f ) {
		if ( is_file( $chd_f ) ) {
			unlink( $chd_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	if ( is_dir( $chd_tmp ) ) {
		rmdir( $chd_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
};
register_shutdown_function( $chd_cleanup );

$chd_cache = ( new ReflectionClass( 'Perdita_Caching' ) )->newInstanceWithoutConstructor();
$chd_m     = static function ( $name, $cls = 'Perdita_Caching' ) {
	$m = new ReflectionMethod( $cls, $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m;
};
$chd_p     = static function ( $name ) {
	$p = new ReflectionProperty( 'Perdita_Caching', $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	return $p;
};
$chd_key   = static function ( $path ) use ( $chd_host ) {
	return 'http://' . $chd_host . $path;
};
$chd_page  = static function ( $path, $body = null ) use ( $chd_key ) {
	$f = Perdita_Caching::file_for_key( $chd_key( $path ) );
	file_put_contents( $f, null === $body ? '<!doctype html><html><body>' . $path . '</body></html>' : $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $f;
};
$chd_has   = static function ( $path ) use ( $chd_key ) {
	clearstatcache();
	return is_file( Perdita_Caching::file_for_key( $chd_key( $path ) ) );
};
// Run write_buffer() on a body, as if a request had captured it. $during
// runs after the capture began and before the shutdown write, which is
// where a render does its work.
$chd_capture = static function ( $path, $body, array $props = array(), $during = null ) use ( $chd_cache, $chd_p, $chd_key ) {
	foreach ( array_merge(
		array(
			'cache_file'        => Perdita_Caching::file_for_key( $chd_key( $path ) ),
			'cache_url'         => $chd_key( $path ),
			'cache_variant'     => '',
			'is_feed_request'   => false,
			'logged_in_allowed' => false,
			'capture_started'   => microtime( true ),
		),
		$props
	) as $name => $value ) {
		$chd_p( $name )->setValue( $chd_cache, $value );
	}
	ob_start();
	ob_start();
	$chd_p( 'buffering' )->setValue( $chd_cache, true );
	$chd_p( 'buffer_level' )->setValue( $chd_cache, ob_get_level() );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( is_callable( $during ) ) {
		call_user_func( $during );
	}
	$chd_cache->write_buffer();
	return (string) ob_get_clean();
};
$chd_html = static function ( $tag ) {
	return '<!doctype html><html><body>' . $tag . ' ' . str_repeat( 'x', 300 ) . '</body></html>';
};
// A child PHP process that loads this WordPress and prints one JSON line.
$chd_child = static function ( $code ) use ( $chd_php, $chd_tmp ) {
	$script = $chd_tmp . 'child-' . wp_generate_password( 6, false ) . '.php';
	file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$script,
		'<?php error_reporting( E_ALL & ~E_DEPRECATED ); define( "WP_USE_THEMES", false ); require ' . var_export( ABSPATH . 'wp-load.php', true ) . '; ' . $code // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	);
	$out = (string) shell_exec( escapeshellarg( $chd_php ) . ' ' . escapeshellarg( $script ) . ' 2>&1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec -- a second WordPress process, for a race and for code that ends the process.
	unlink( $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	if ( preg_match( '/^CHD_JSON:(.*)$/m', $out, $m ) ) {
		$data = json_decode( $m[1], true );
		return is_array( $data ) ? $data : array( 'raw' => $out );
	}
	return array( 'raw' => substr( $out, -2000 ) );
};

wp_set_current_user( 0 );
$_GET    = array();
$_POST   = array();
$_COOKIE = array();
Perdita_Caching::purge_everything();

/* ------------------------------------------------------------------ */
/* 2. A response that sets a cookie is never stored.                    */
/* ------------------------------------------------------------------ */

$chd_headers = array();
$chd_hfilter = static function () use ( &$chd_headers ) {
	return $chd_headers;
};
add_filter( 'perdita_cache_response_headers', $chd_hfilter );
$chd_set( array() );

$chd_headers = array( 'Content-Type: text/html; charset=UTF-8', 'Set-Cookie: shop_session=abc123; path=/; HttpOnly' );
$chd_sent    = $chd_capture( '/hard-cookie/', $chd_html( 'cookie' ) );
$ok( ! $chd_has( '/hard-cookie/' ) && $chd_html( 'cookie' ) === $chd_sent, 'cache hardening 2: an anonymous response that sets a cookie is sent but never stored' );
$ok( array( 'shop_session' ) === Perdita_Caching::response_cookie_names(), 'cache hardening 2: the cookie is named by its name, not its value' );
$chd_allow = static function ( $names ) {
	$names[] = 'shop_session';
	return $names;
};
add_filter( 'perdita_cache_allow_set_cookie', $chd_allow );
$chd_capture( '/hard-cookie/', $chd_html( 'cookie' ) );
$ok( $chd_has( '/hard-cookie/' ), 'cache hardening 2: perdita_cache_allow_set_cookie lets a named cookie through' );
$chd_headers[] = 'Set-Cookie: other=1';
Perdita_Caching::purge_everything();
$chd_capture( '/hard-cookie/', $chd_html( 'cookie' ) );
$ok( ! $chd_has( '/hard-cookie/' ) && array( 'other' ) === Perdita_Caching::response_cookie_names(), 'cache hardening 2: a second, unlisted cookie still keeps the page out' );
remove_filter( 'perdita_cache_allow_set_cookie', $chd_allow );
$chd_headers = array( 'Cache-Control: private, max-age=60' );
$chd_capture( '/hard-private/', $chd_html( 'private' ) );
$ok( ! $chd_has( '/hard-private/' ), 'cache hardening 5: a response a plugin marked private is not stored' );
$chd_headers = array();
remove_filter( 'perdita_cache_response_headers', $chd_hfilter );

/* ------------------------------------------------------------------ */
/* 3. A purge that lands during a render wins.                          */
/* ------------------------------------------------------------------ */

$chd_capture( '/hard-race/', $chd_html( 'control' ) );
$ok( $chd_has( '/hard-race/' ), 'cache hardening 3: control, a render with no purge is stored' );
Perdita_Caching::delete_entry( Perdita_Caching::file_for_key( $chd_key( '/hard-race/' ) ) );
$chd_capture(
	'/hard-race/',
	$chd_html( 'old' ),
	array(),
	static function () {
		Perdita_Caching::purge_everything();
	}
);
$ok( ! $chd_has( '/hard-race/' ), 'cache hardening 3: a full purge during the render keeps the page it rendered out of the cache' );
$chd_capture(
	'/hard-race/',
	$chd_html( 'old' ),
	array(),
	static function () use ( $chd_key ) {
		Perdita_Caching::purge_urls( array( $chd_key( '/hard-race/' ) ) );
	}
);
$ok( ! $chd_has( '/hard-race/' ), 'cache hardening 3: a purge of that one URL during the render does the same' );
$chd_capture(
	'/hard-race/',
	$chd_html( 'old' ),
	array(),
	static function () use ( $chd_key ) {
		Perdita_Caching::purge_urls( array( $chd_key( '/hard-race-other/' ) ) );
	}
);
$ok( $chd_has( '/hard-race/' ), 'cache hardening 3: a purge of some other URL does not stop this one' );
Perdita_Caching::delete_entry( Perdita_Caching::file_for_key( $chd_key( '/hard-race/' ) ) );
// The purge lands after the verdict, while the file is written.
$chd_late = static function ( $html ) {
	Perdita_Caching::purge_everything();
	return $html;
};
add_filter( 'perdita_cache_output_html', $chd_late );
$chd_capture( '/hard-race/', $chd_html( 'old' ) );
remove_filter( 'perdita_cache_output_html', $chd_late );
$ok( ! $chd_has( '/hard-race/' ), 'cache hardening 3: a purge that lands while the file is written removes what was written' );
// With serve stale on, the purge marks the old copy, and a render that
// began before the purge leaves the mark for the next request.
$chd_set( array( 'serve_stale' => true ) );
$chd_page( '/hard-race/', $chd_html( 'previous' ) );
$chd_capture(
	'/hard-race/',
	$chd_html( 'old' ),
	array(),
	static function () use ( $chd_key ) {
		Perdita_Caching::purge_urls( array( $chd_key( '/hard-race/' ) ) );
	}
);
$chd_rfile = Perdita_Caching::file_for_key( $chd_key( '/hard-race/' ) );
clearstatcache();
$ok( is_file( $chd_rfile . '.stale' ) && $chd_html( 'previous' ) === (string) file_get_contents( $chd_rfile ), 'cache hardening 3: with serve stale on, the old copy keeps its stale mark and the racing render is not written over it' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$chd_set( array() );
Perdita_Caching::delete_entry( $chd_rfile );
// Another process purges while this one renders: the stamp on disk counts.
if ( '' !== $chd_php ) {
	$chd_res = array();
	$chd_capture(
		'/hard-race/',
		$chd_html( 'old' ),
		array(),
		static function () use ( $chd_child, &$chd_res ) {
			$chd_res = $chd_child( 'require_once PERDITA_CORE_DIR . "inc/modules/caching/class-perdita-caching.php"; Perdita_Caching::purge_everything(); echo "CHD_JSON:" . wp_json_encode( array( "purged" => true ) ) . "\n";' );
		}
	);
	$ok( ! empty( $chd_res['purged'] ) && ! $chd_has( '/hard-race/' ), 'cache hardening 3: a full purge from another PHP process during the render keeps the page out' . ( empty( $chd_res['purged'] ) ? ' (' . wp_json_encode( $chd_res ) . ')' : '' ) );
	$chd_stamp = (float) file_get_contents( $chd_dir . Perdita_Caching::PURGE_STAMP ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( true === Perdita_Caching::purged_since( 'http://x/', $chd_stamp - 1 ) && false === Perdita_Caching::purged_since( 'http://x/', $chd_stamp + 1 ) && false === Perdita_Caching::purged_since( 'http://x/', 0 ), 'cache hardening 3: purged_since() compares the stamp with when the render began' );
} else {
	echo "SKIP  cache hardening 3: the cross-process race needs a CLI PHP binary\n";
}

/* ------------------------------------------------------------------ */
/* 4. The phone test is wp_is_mobile()'s, here and in the drop-in.      */
/* ------------------------------------------------------------------ */

$chd_uas    = array(
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
	'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
	'Mozilla/5.0 (iPad; CPU OS 12_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
	'Mozilla/5.0 (Linux; U; Android 4.0.3; en-us; KFTT Build/IML74K) AppleWebKit/535.19 Silk/3.4 Safari/535.19',
	'Mozilla/5.0 (X11; Linux armv7l; Kindle/3.0)',
	'Opera/9.80 (J2ME/MIDP; Opera Mini/9.80) Presto/2.12',
	'Mozilla/5.0 (BlackBerry; U; BlackBerry 9900)',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
	'a mobile agent in lower case',
	'',
);
$chd_agree  = true;
$chd_expect = array();
unset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
foreach ( $chd_uas as $chd_ua ) {
	$_SERVER['HTTP_USER_AGENT'] = $chd_ua;
	$chd_expect[ $chd_ua ]      = (bool) wp_is_mobile();
	$chd_agree                  = $chd_agree && $chd_expect[ $chd_ua ] === Perdita_Caching::is_mobile_request( $_SERVER );
}
$ok( $chd_agree, 'cache hardening 4: the phone test answers like wp_is_mobile() for phones, Android tablets, an old iPad, Kindle, Silk, Opera Mini, BlackBerry, desktops and an empty agent' );
$_SERVER['HTTP_USER_AGENT']       = $chd_uas[0];
$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?0';
$chd_hint0                        = (bool) wp_is_mobile() === Perdita_Caching::is_mobile_request( $_SERVER ) && ! Perdita_Caching::is_mobile_request( $_SERVER );
$_SERVER['HTTP_USER_AGENT']       = $chd_uas[7];
$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?1';
$ok( $chd_hint0 && (bool) wp_is_mobile() === Perdita_Caching::is_mobile_request( $_SERVER ) && Perdita_Caching::is_mobile_request( $_SERVER ), 'cache hardening 4: the Sec-CH-UA-Mobile hint wins over the user agent, as in wp_is_mobile()' );
unset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
$chd_more = static function ( $needles ) {
	$needles[] = 'SmartTV';
	return $needles;
};
add_filter( 'perdita_cache_mobile_user_agents', $chd_more );
$ok( Perdita_Caching::is_mobile_ua( 'Some SmartTV browser' ) && in_array( 'SmartTV', Perdita_Caching::dropin_filter_values()['mobile_ua'], true ), 'cache hardening 4: perdita_cache_mobile_user_agents still adds to the list, and the drop-in gets the same list' );
remove_filter( 'perdita_cache_mobile_user_agents', $chd_more );
// A plugin that filters wp_is_mobile() can disagree with the request.
$chd_is                     = $chd_m( 'is_cacheable' );
$_SERVER['HTTP_HOST']       = $chd_host;
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_USER_AGENT'] = $chd_uas[7];
$chd_req                    = array(
	'enabled'            => true,
	'ttl_hours'          => 10,
	'exclude_paths'      => array(),
	'reject_user_agents' => array(),
	'mobile_cache'       => true,
);
$chd_pin = static function () {
	return false;
};
add_filter( 'perdita_cache_exclude', $chd_pin, PHP_INT_MAX );
$ok( true === $chd_is->invoke( $chd_cache, $chd_req ), 'cache hardening 4: control, a desktop request with the phone cache on is cacheable' );
add_filter( 'wp_is_mobile', '__return_true' );
$ok( false === $chd_is->invoke( $chd_cache, $chd_req ) && 0 === strpos( (string) $chd_p( 'skip_reason' )->getValue( $chd_cache ), 'phone test' ), 'cache hardening 4: when a wp_is_mobile filter disagrees with the phone test, the request is not cached in either copy' );
$ok( true === Perdita_Caching::dropin_filter_values()['mobile_filtered'], 'cache hardening 4: the drop-in learns that wp_is_mobile is filtered' );
remove_filter( 'wp_is_mobile', '__return_true' );

/* ------------------------------------------------------------------ */
/* 9. Paths that aren't plain and normalized are never keyed.           */
/* 6. Made-up page numbers and arrays are never keyed.                  */
/* ------------------------------------------------------------------ */

$chd_clean_ok = '/' === Perdita_Caching::clean_path( '/' ) && '/a/b/' === Perdita_Caching::clean_path( '/a/b/?x=1#y' ) && '/.well/' === Perdita_Caching::clean_path( '/.well/' ) && '/a/..b/' === Perdita_Caching::clean_path( '/a/..b/' );
foreach ( array( '//hello-world/', '/a//b/', '/a/../b/', '/a/%2E%2e/b/', '/a/./b/', '/a\\b/', "/a\x00b/", '/a b/', '/a%00/', 'hello/' ) as $chd_bad ) {
	$chd_clean_ok = $chd_clean_ok && '' === Perdita_Caching::clean_path( $chd_bad );
}
$ok( $chd_clean_ok, 'cache hardening 9: clean_path() keeps plain paths and refuses "//", empty, "." and ".." segments (plain or encoded), backslashes, NUL and spaces' );
$_SERVER['HTTP_USER_AGENT'] = $chd_uas[7];
$chd_req['mobile_cache']    = false;
$_SERVER['REQUEST_URI']     = '//hello-world/';
$ok( false === $chd_is->invoke( $chd_cache, $chd_req ) && 'path' === $chd_p( 'skip_reason' )->getValue( $chd_cache ), 'cache hardening 9: a request for //hello-world/ is not cached, where it used to be keyed as the home page' );
$_SERVER['REQUEST_URI'] = '/';
$ok( array() === Perdita_Caching::keys_for_url( 'http://' . $chd_host . '//hello-world/' ) && array() === Perdita_Caching::keys_for_url( 'https://elsewhere.example/a/' ), 'cache hardening 9: a purge of an unnormalized path or another host purges nothing' );
$ok( array( 'https://' . $chd_host . '/a/?paged=2', 'http://' . $chd_host . '/a/?paged=2' ) === Perdita_Caching::keys_for_url( home_url( '/a/?utm_source=x&paged=2' ) ), 'cache hardening 6: a purge builds the same key a request does, query and all' );

$chd_qok = Perdita_Caching::query_value_ok( 'paged', '2' ) && Perdita_Caching::query_value_ok( 'page', '123456789' ) && Perdita_Caching::query_value_ok( 'lang', 'en-US' );
foreach ( array( array( 'paged', '1' ), array( 'paged', '0' ), array( 'paged', '02' ), array( 'paged', 'abc' ), array( 'paged', '2abc' ), array( 'paged', '1234567890' ), array( 'page', array( '2' ) ), array( 'lang', array( 'en' ) ), array( 'lang', '' ), array( 'lang', str_repeat( 'a', 65 ) ), array( 'lang', 'a b' ) ) as $chd_q ) {
	$chd_qok = $chd_qok && ! Perdita_Caching::query_value_ok( $chd_q[0], $chd_q[1] );
}
$ok( $chd_qok, 'cache hardening 6: a page number is digits only and above 1, other values are short tokens, and an array is never a value' );
foreach ( array( array( 'paged' => 'abc' ), array( 'paged' => '1' ), array( 'paged' => '07' ), array( 'page' => array( '2' ) ) ) as $chd_q ) {
	$_GET = $chd_q;
	$ok( false === $chd_is->invoke( $chd_cache, $chd_req ) && 'query string' === $chd_p( 'skip_reason' )->getValue( $chd_cache ), 'cache hardening 6: ?' . http_build_query( $chd_q ) . ' is not cached, so it can neither mint a file nor land on the plain URL' );
}
$_GET = array( 'paged' => '3' );
$ok( true === $chd_is->invoke( $chd_cache, $chd_req ) && $chd_key( '/?paged=3' ) === $chd_m( 'request_key' )->invoke( $chd_cache ), 'cache hardening 6: ?paged=3 is cached under its own key' );
$_GET = array();
remove_filter( 'perdita_cache_exclude', $chd_pin, PHP_INT_MAX );

/* ------------------------------------------------------------------ */
/* 6. The size cap, and cleanup on by default.                          */
/* ------------------------------------------------------------------ */

$chd_set( array() );
$ok( 1024 * MB_IN_BYTES === Perdita_Caching::max_bytes(), 'cache hardening 6: the cache is capped at 1 GB by default' );
$chd_cap = static function () {
	return 5000;
};
add_filter( 'perdita_cache_max_bytes', $chd_cap );
wp_unschedule_hook( Perdita_Caching::GC_HOOK . '_soon' );
Perdita_Caching::write_usage( 4900 );
$chd_capture( '/hard-full/', $chd_html( 'full' ) );
$ok( ! $chd_has( '/hard-full/' ) && false !== wp_next_scheduled( Perdita_Caching::GC_HOOK . '_soon' ), 'cache hardening 6: a full cache stores nothing more and books a cleanup within a minute' );
Perdita_Caching::write_usage( 0 );
$chd_capture( '/hard-full/', $chd_html( 'full' ) );
$ok( $chd_has( '/hard-full/' ) && Perdita_Caching::usage() === Perdita_Caching::entry_bytes( Perdita_Caching::file_for_key( $chd_key( '/hard-full/' ) ) ), 'cache hardening 6: with room, the page is stored and the running total grows by its size' );
$chd_capture( '/hard-full/', $chd_html( 'full' ) );
$ok( Perdita_Caching::usage() === Perdita_Caching::entry_bytes( Perdita_Caching::file_for_key( $chd_key( '/hard-full/' ) ) ), 'cache hardening 6: storing the same page again does not count it twice' );
Perdita_Caching::purge_everything();
$ok( 0 === Perdita_Caching::usage(), 'cache hardening 6: a full purge resets the running total' );
// The cleanup trims the oldest pages to 90% of the cap.
$chd_files = array();
for ( $chd_i = 0; $chd_i < 5; $chd_i++ ) {
	$chd_files[ $chd_i ] = $chd_page( '/hard-gc-' . $chd_i . '/', str_repeat( 'g', 2000 ) );
	touch( $chd_files[ $chd_i ], time() - ( 5 - $chd_i ) * 60 );
}
file_put_contents( $chd_dir . str_repeat( 'a', 40 ) . '.purged', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
touch( $chd_dir . str_repeat( 'a', 40 ) . '.purged', time() - Perdita_Caching::PURGE_MARK_TTL - 60 );
file_put_contents( $chd_dir . str_repeat( 'b', 40 ) . '.purged', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
Perdita_Caching_Tools::gc();
clearstatcache();
$ok( ! is_file( $chd_files[0] ) && ! is_file( $chd_files[1] ) && ! is_file( $chd_files[2] ) && is_file( $chd_files[3] ) && is_file( $chd_files[4] ) && 4000 === Perdita_Caching::usage(), 'cache hardening 6: over the cap, the cleanup deletes the oldest pages until they fit in 90% of it, and resets the running total to what is left' );
$ok( ! is_file( $chd_dir . str_repeat( 'a', 40 ) . '.purged' ) && is_file( $chd_dir . str_repeat( 'b', 40 ) . '.purged' ), 'cache hardening 3: the cleanup removes purge marks older than an hour and keeps recent ones' );
remove_filter( 'perdita_cache_max_bytes', $chd_cap );
Perdita_Caching::purge_everything();

$chd_set( array( 'gc_schedule' => 'off' ) );
$ok( 'daily' === Perdita_Caching::settings()['gc_schedule'], 'cache hardening 6: an install that kept the old "off" default now cleans up daily' );
$chd_set(
	array(
		'gc_schedule' => 'off',
		'gc_chosen'   => true,
	)
);
$ok( 'off' === Perdita_Caching::settings()['gc_schedule'], 'cache hardening 6: an "off" chosen on the screen stays off' );
$ok( true === Perdita_Caching::sanitize( array( 'gc_schedule' => 'off' ) )['gc_chosen'] && 'daily' === Perdita_Caching::sanitize( array() )['gc_schedule'] && 'smart' === Perdita_Caching::sanitize( array( 'purge_on_save' => 'smart' ) )['purge_on_save'] && 'all' === Perdita_Caching::sanitize( array( 'purge_on_save' => 'bogus' ) )['purge_on_save'] && 0 === Perdita_Caching::sanitize( array( 'max_size_mb' => -5 ) )['max_size_mb'], 'cache hardening 6: sanitize() records a chosen schedule, defaults to daily, and cleans the cap and the save mode' );
$chd_set( array() );
wp_unschedule_hook( Perdita_Caching::GC_HOOK );
Perdita_Caching::sync_schedules();
$chd_gc_event = wp_get_scheduled_event( Perdita_Caching::GC_HOOK );
$ok( $chd_gc_event && 'daily' === $chd_gc_event->schedule, 'cache hardening 6: with nothing saved, the daily cleanup is scheduled' );
wp_unschedule_hook( Perdita_Caching::GC_HOOK );

/* ------------------------------------------------------------------ */
/* 17. A gzip copy never outlives its page.                             */
/* ------------------------------------------------------------------ */

$chd_set( array( 'gzip' => true ) );
$chd_gf = $chd_page( '/hard-gz/', 'OLD PAGE' );
file_put_contents( $chd_gf . '.gz', gzencode( 'OLD PAGE' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$chd_capture( '/hard-gz/', $chd_html( 'new' ) );
clearstatcache();
$ok( $chd_html( 'new' ) === gzdecode( (string) file_get_contents( $chd_gf . '.gz' ) ), 'cache hardening 17: a page written in the same second as the old gzip copy gets its own gzip copy' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
file_put_contents( $chd_gf . '.gz', gzencode( 'OLD PAGE' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$chd_set( array() );
$chd_capture( '/hard-gz/', $chd_html( 'newer' ) );
clearstatcache();
$ok( ! is_file( $chd_gf . '.gz' ) && $chd_html( 'newer' ) === (string) file_get_contents( $chd_gf ), 'cache hardening 17: with no new gzip copy, the old one is deleted before the page is written' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
Perdita_Caching::delete_entry( $chd_gf );

/* ------------------------------------------------------------------ */
/* 11 and 12. What clears the cache, and what doesn't.                  */
/* ------------------------------------------------------------------ */

$chd_ids_before = $chd_hook_ids();
$chd_inst       = new Perdita_Caching( perdita_core() );
$chd_seed       = static function () use ( $chd_page ) {
	$chd_page( '/' );
	$chd_page( '/hard-unrelated/' );
	$chd_page( '/hard-custom-list/' );
};
$chd_cleared    = static function () use ( $chd_has ) {
	return ! $chd_has( '/hard-unrelated/' );
};
$chd_set( array() );
// Fire a hook with only this fragment's instance listening, so WordPress's
// own callbacks on it (update checks, theme switches) don't run here.
$chd_fire = static function ( $hook, array $args ) use ( $chd_inst ) {
	$orig = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : null;
	$only = new WP_Hook();
	foreach ( null !== $orig ? $orig->callbacks : array() as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] === $chd_inst ) {
				$only->add_filter( $hook, $cb['function'], $prio, $cb['accepted_args'] );
			}
		}
	}
	$GLOBALS['wp_filter'][ $hook ] = $only;
	do_action_ref_array( $hook, $args );
	if ( null === $orig ) {
		unset( $GLOBALS['wp_filter'][ $hook ] );
	} else {
		$GLOBALS['wp_filter'][ $hook ] = $orig;
	}
};

$chd_events = array(
	'activated_plugin'          => array( 'hello.php', false ),
	'deactivated_plugin'        => array( 'hello.php', false ),
	'switch_theme'              => array( 'Twenty', null, null ),
	'customize_save_after'      => array( null ),
	'upgrader_process_complete' => array( null, array( 'type' => 'plugin' ) ),
	'wp_update_nav_menu'        => array( 1 ),
	'wp_delete_nav_menu'        => array( 1 ),
);
foreach ( $chd_events as $chd_hook => $chd_args ) {
	$chd_seed();
	$chd_fire( $chd_hook, $chd_args );
	$ok( $chd_cleared(), 'cache hardening 11: ' . $chd_hook . ' clears every cached page' );
}
foreach ( array( 'blogname', 'blogdescription', 'permalink_structure', 'sidebars_widgets', 'widget_text', 'theme_mods_perdita', 'show_on_front', 'posts_per_page', 'date_format', 'site_icon', 'perdita_analytics_settings', 'perdita_caching_settings', 'perdita_settings' ) as $chd_opt ) {
	$chd_seed();
	$chd_fire( 'updated_option', array( $chd_opt, 'a', 'b' ) );
	$ok( $chd_cleared(), 'cache hardening 11: a change to ' . $chd_opt . ' clears every cached page' );
}
$chd_seed();
$chd_tagline = get_option( 'blogdescription' );
update_option( 'blogdescription', $chd_tagline . ' hardening' );
$ok( $chd_cleared(), 'cache hardening 11: saving the tagline for real clears the cache' );
update_option( 'blogdescription', $chd_tagline );
foreach ( array( 'perdita_subscriptions_queue', 'perdita_subscriptions_pro_queue', 'perdita_search_console_settings', 'perdita_mcp_oauth_grants', 'perdita_indexnow', 'perdita_cache_preload', 'perdita_forms_db_version', 'perdita_media_pro_lock', 'perdita_media_pro_state', 'perdita_spam_pro_stats', 'perdita_forms_turnstile_key_error', 'perdita_cache_pro_user_cache_seen', 'perdita_subscriptions_pro_digest_run', 'perdita_seo_pro_keyphrase_migrated', 'perdita_security_pro_baseline_time', 'perdita_security_pro_scan_results', 'perdita_forms_pro_integration_failure', 'perdita_core_auto_updates_defaulted', 'perdita_forms_proxy_ip_notice', 'perdita_seo_llms_rules', 'cron', 'rewrite_rules', '_transient_doing_cron', 'active_plugins_backup', 'some_other_plugin_option' ) as $chd_opt ) {
	$chd_seed();
	$chd_fire( 'updated_option', array( $chd_opt, 'a', 'b' ) );
	$chd_fire( 'added_option', array( $chd_opt, 'b' ) );
	$ok( ! $chd_cleared(), 'cache hardening 12: a write to ' . $chd_opt . ' leaves the cache alone' );
}
$chd_veto = static function ( $verdict, $option ) {
	return 'perdita_analytics_settings' === $option ? false : $verdict;
};
add_filter( 'perdita_cache_option_purges', $chd_veto, 10, 2 );
$chd_seed();
$chd_fire( 'updated_option', array( 'perdita_analytics_settings', 'a', 'b' ) );
$ok( ! $chd_cleared(), 'cache hardening 12: perdita_cache_option_purges has the last word' );
remove_filter( 'perdita_cache_option_purges', $chd_veto, 10 );
$chd_add_opt = static function ( $names ) {
	$names[] = 'my_footer_*';
	return $names;
};
add_filter( 'perdita_cache_purge_options', $chd_add_opt );
$chd_seed();
$chd_fire( 'updated_option', array( 'my_footer_text', 'a', 'b' ) );
$ok( $chd_cleared(), 'cache hardening 12: perdita_cache_purge_options adds a core or third-party option, by prefix too' );
remove_filter( 'perdita_cache_purge_options', $chd_add_opt );

// Terms.
$chd_seed();
$chd_term = wp_insert_term( 'Hardening Term ' . wp_generate_password( 4, false ), 'category' );
$chd_tid  = is_array( $chd_term ) ? (int) $chd_term['term_id'] : 0;
if ( $chd_tid ) {
	$GLOBALS['chd_terms'][] = $chd_tid;
}
$ok( $chd_tid > 0 && $chd_cleared(), 'cache hardening 11: creating a category clears the cache' );
$chd_seed();
wp_update_term( $chd_tid, 'category', array( 'name' => 'Hardening Term Renamed' ) );
$ok( $chd_cleared(), 'cache hardening 11: renaming a category clears the cache' );

// Posts, in the default "clear everything" mode.
$chd_seed();
$chd_post = wp_insert_post(
	array(
		'post_title'   => 'Hardening Post',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => 'one',
	)
);
$GLOBALS['chd_posts'][] = $chd_post;
$ok( $chd_post > 0 && $chd_cleared(), 'cache hardening: publishing a post clears the cache' );
$chd_seed();
wp_update_post(
	array(
		'ID'           => $chd_post,
		'post_content' => 'two',
	)
);
$ok( $chd_cleared(), 'cache hardening: by default, editing a published post still clears every page' );

// Smart mode.
$chd_set( array( 'purge_on_save' => 'smart' ) );
$chd_list = array();
$chd_urls = static function ( $urls, $post ) use ( &$chd_list ) {
	$urls[]   = home_url( '/hard-custom-list/' );
	$chd_list = $urls;
	return $urls;
};
add_filter( 'perdita_cache_purge_urls_for_post', $chd_urls, 10, 2 );
$chd_seed();
wp_update_post(
	array(
		'ID'           => $chd_post,
		'post_content' => 'three',
	)
);
$ok( ! $chd_cleared() && ! $chd_has( '/' ) && ! $chd_has( '/hard-custom-list/' ), 'cache hardening smart: editing a published post clears its own pages and lists, a filter-added list included, and leaves an unrelated page' );
$chd_want = array( get_permalink( $chd_post ), home_url( '/' ), get_author_posts_url( (int) get_post_field( 'post_author', $chd_post ) ), get_year_link( (int) get_the_date( 'Y', $chd_post ) ), get_feed_link(), get_post_comments_feed_link( $chd_post ) );
foreach ( wp_get_post_categories( $chd_post ) as $chd_cat ) {
	$chd_want[] = get_category_link( $chd_cat );
}
$ok( array() === array_diff( $chd_want, $chd_list ), 'cache hardening smart: the list holds the post, the home page, its author, year and category archives, and the feeds' . ( array_diff( $chd_want, $chd_list ) ? ' (missing ' . implode( ', ', array_diff( $chd_want, $chd_list ) ) . ')' : '' ) );
$chd_seed();
wp_update_post(
	array(
		'ID'         => $chd_post,
		'post_title' => 'Hardening Post Retitled',
	)
);
$ok( $chd_cleared(), 'cache hardening smart: a new title clears everything, since lists everywhere print it' );
$chd_seed();
wp_set_post_categories( $chd_post, array( $chd_tid ) );
$ok( $chd_cleared(), 'cache hardening smart: moving a published post to another category clears everything' );
$chd_seed();
wp_update_post(
	array(
		'ID'          => $chd_post,
		'post_status' => 'draft',
	)
);
$ok( $chd_cleared(), 'cache hardening smart: unpublishing clears everything' );
$chd_seed();
wp_update_post(
	array(
		'ID'           => $chd_post,
		'post_content' => 'draft edit',
	)
);
$ok( ! $chd_cleared() && $chd_has( '/' ), 'cache hardening smart: editing a draft clears nothing' );
$chd_seed();
wp_update_post(
	array(
		'ID'          => $chd_post,
		'post_status' => 'publish',
	)
);
$ok( $chd_cleared(), 'cache hardening smart: publishing clears everything' );
$chd_draft = wp_insert_post(
	array(
		'post_title'  => 'Hardening Draft',
		'post_status' => 'draft',
		'post_type'   => 'post',
	)
);
$GLOBALS['chd_posts'][] = $chd_draft;
$chd_seed();
wp_delete_post( $chd_draft, true );
$ok( ! $chd_cleared(), 'cache hardening 11: deleting a draft for good clears nothing' );
$chd_seed();
wp_delete_post( $chd_post, true );
$ok( $chd_cleared(), 'cache hardening 11: deleting a published post for good, which fires no save_post, clears everything' );
remove_filter( 'perdita_cache_purge_urls_for_post', $chd_urls, 10 );
$chd_set( array() );
$chd_seed();
wp_delete_term( $chd_tid, 'category' );
$ok( $chd_cleared(), 'cache hardening 11: deleting a category clears the cache' );

// This fragment's instance goes, hooks and all.
foreach ( $GLOBALS['wp_filter'] as $chd_hook => $chd_obj ) {
	foreach ( $chd_obj->callbacks as $chd_prio => $chd_cbs ) {
		foreach ( $chd_cbs as $chd_idx => $chd_cb ) {
			if ( ! isset( $chd_ids_before[ $chd_hook . '|' . $chd_prio . '|' . $chd_idx ] ) ) {
				remove_filter( $chd_hook, $chd_cb['function'], $chd_prio );
			}
		}
	}
}
$ok( $chd_hook_ids() == $chd_ids_before, 'cache hardening: the test instance leaves no hook behind' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same keys, any order.
Perdita_Caching::purge_everything();

/* ------------------------------------------------------------------ */
/* 12. A purge during a preload keeps the run going.                    */
/* 14. The preloader only asks this site's own origin.                  */
/* ------------------------------------------------------------------ */

$chd_set( array( 'preload' => true ) );
update_option(
	Perdita_Caching_Preload::OPTION,
	array_merge(
		Perdita_Caching_Preload::blank_state(),
		array(
			'status' => 'running',
			'run'    => 'hardrun',
			'phase'  => 'posts',
			'cursor' => 57,
			'done'   => 40,
			'total'  => 100,
		)
	),
	false
);
wp_unschedule_hook( Perdita_Caching_Preload::HOOK_BATCH );
wp_unschedule_hook( Perdita_Caching_Preload::HOOK_START );
Perdita_Caching_Preload::restart_soon();
$chd_pl = Perdita_Caching_Preload::state( true );
$ok( 'running' === $chd_pl['status'] && 'hardrun' === $chd_pl['run'] && 57 === $chd_pl['cursor'] && 40 === $chd_pl['done'] && true === $chd_pl['again'] && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ) && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_START ), 'cache hardening 12: a full purge during a preload keeps the run where it is and asks for one more pass' );
$chd_pl['phase'] = 'done';
update_option( Perdita_Caching_Preload::OPTION, $chd_pl, false );
delete_transient( Perdita_Caching_Preload::LOCK );
$chd_pl = Perdita_Caching_Preload::run_batch();
$ok( 'waiting' === $chd_pl['status'] && false === $chd_pl['again'] && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_START ), 'cache hardening 12: when that run ends, one more fill pass is booked' );
update_option( Perdita_Caching_Preload::OPTION, array_merge( Perdita_Caching_Preload::blank_state(), array( 'status' => 'done' ) ), false );
wp_unschedule_hook( Perdita_Caching_Preload::HOOK_START );
Perdita_Caching_Preload::restart_soon();
$chd_pl = Perdita_Caching_Preload::state( true );
$ok( 'waiting' === $chd_pl['status'] && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_START ), 'cache hardening 12: with no run under way, a purge still books a fresh preload' );
wp_unschedule_hook( Perdita_Caching_Preload::HOOK_START );
wp_unschedule_hook( Perdita_Caching_Preload::HOOK_BATCH );

$chd_home = home_url( '/' );
$chd_alt  = 0 === strpos( $chd_home, 'https:' ) ? 'http:' . substr( $chd_home, 6 ) : 'https:' . substr( $chd_home, 5 );
$ok( Perdita_Caching_Preload::is_own_url( home_url( '/a/' ) ) && ! Perdita_Caching_Preload::is_own_url( $chd_alt ) && ! Perdita_Caching_Preload::is_own_url( 'http://' . $chd_host . ':8081/' ) && ! Perdita_Caching_Preload::is_own_url( 'http://user:pw@' . $chd_host . '/' ) && ! Perdita_Caching_Preload::is_own_url( 'ftp://' . $chd_host . '/' ) && ! Perdita_Caching_Preload::is_own_url( '//' . $chd_host . '/' ), 'cache hardening 14: the preloader asks only for URLs on the site\'s own scheme, host and port' );
$chd_set( array() );

/* ------------------------------------------------------------------ */
/* 15. Multisite: early serving stays off, with a reason.               */
/* 16. The drop-in gets the filter values the front end sees.           */
/* ------------------------------------------------------------------ */

$chd_dropin_was = Perdita_Caching::dropin_state();
add_filter( 'perdita_cache_is_multisite', '__return_true' );
$chd_set( array( 'early_serve' => true ) );
$chd_flag = $chd_dir . 'early-serve.on';
file_put_contents( $chd_flag, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$ok( 'multisite' === Perdita_Caching::sync_dropin() && ! is_file( $chd_flag ) && $chd_dropin_was === Perdita_Caching::dropin_state(), 'cache hardening 15: on a network, sync_dropin() turns early serving off and writes no drop-in' );
require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching-admin.php';
$chd_admin = ( new ReflectionClass( 'Perdita_Caching_Admin' ) )->newInstanceWithoutConstructor();
$ok( false !== stripos( (string) $chd_m( 'dropin_message', 'Perdita_Caching_Admin' )->invoke( $chd_admin, 'multisite' ), 'multisite' ), 'cache hardening 15: the Caching screen says why early serving is off on a network' );
remove_filter( 'perdita_cache_is_multisite', '__return_true' );

// Front end: a whitelist arg only the front end adds.
$chd_front = static function ( $args ) {
	$args[] = 'lang';
	return $args;
};
$chd_admin_print = Perdita_Caching::dropin_fingerprint( Perdita_Caching::dropin_filter_values() );
file_put_contents( $chd_flag, $chd_admin_print ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$ok( true === Perdita_Caching::check_dropin_config() && is_file( $chd_flag ), 'cache hardening 16: control, when the front end sees what the drop-in has, early serving stays on' );
add_filter( 'perdita_cache_query_whitelist', $chd_front );
$ok( false === Perdita_Caching::check_dropin_config() && ! is_file( $chd_flag ) && is_file( $chd_dir . Perdita_Caching::FRONT_CONFIG_FILE ), 'cache hardening 16: when the front end sees other filter values, it saves them and turns early serving off' );
$chd_front_print = Perdita_Caching::dropin_fingerprint( Perdita_Caching::dropin_filter_values() );
remove_filter( 'perdita_cache_query_whitelist', $chd_front );
// Admin (cron counts the same): the filter is gone, the saved values win.
add_filter( 'wp_doing_cron', '__return_true' );
$chd_cfg = Perdita_Caching::dropin_filter_config();
$ok( in_array( 'lang', $chd_cfg['allow'], true ) && $chd_front_print === Perdita_Caching::dropin_fingerprint( $chd_cfg ) && false !== strpos( Perdita_Caching::dropin_source(), '"lang"' ), 'cache hardening 16: the next drop-in, written outside the front end, carries the front end\'s values' );
remove_filter( 'wp_doing_cron', '__return_true' );
file_put_contents( $chd_flag, $chd_front_print ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
add_filter( 'perdita_cache_query_whitelist', $chd_front );
$ok( true === Perdita_Caching::check_dropin_config() && is_file( $chd_flag ), 'cache hardening 16: and the front end then agrees with it' );
remove_filter( 'perdita_cache_query_whitelist', $chd_front );
unlink( $chd_flag ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
unlink( $chd_dir . Perdita_Caching::FRONT_CONFIG_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
$chd_set( array() );

/* ------------------------------------------------------------------ */
/* Drop-in: 4, 6, 9 and 10, run before WordPress in a child process.   */
/* ------------------------------------------------------------------ */

if ( '' !== $chd_php ) {
	$chd_set(
		array(
			'mobile_cache'    => true,
			'debug_log'       => true,
			'debug_log_until' => time() + 3600,
		)
	);
	$chd_src = str_replace( $chd_dir, $chd_tmp, Perdita_Caching::dropin_source() );
	add_filter( 'wp_is_mobile', '__return_false' );
	$chd_src_filtered = str_replace( $chd_dir, $chd_tmp, Perdita_Caching::dropin_source() );
	remove_filter( 'wp_is_mobile', '__return_false' );
	$chd_log = str_replace( $chd_dir, $chd_tmp, Perdita_Caching::log_path() );
	$chd_set( array() );
	file_put_contents( $chd_tmp . 'advanced-cache.php', $chd_src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $chd_tmp . 'advanced-cache-filtered.php', $chd_src_filtered ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $chd_tmp . 'early-serve.on', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$chd_dkey = 'https://' . $chd_host . '/hard-page/';
	file_put_contents( $chd_tmp . sha1( $chd_dkey ) . '.html', 'DESKTOP' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $chd_tmp . sha1( $chd_dkey ) . Perdita_Caching::variant_suffix( 'mobile' ) . '.html', 'PHONE' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $chd_tmp . sha1( $chd_dkey . '?paged=2' ) . '.html', 'PAGED2' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $chd_tmp . sha1( 'https://' . $chd_host . '/' ) . '.html', 'HOME' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	$chd_drun = static function ( $uri, array $server = array(), $which = 'advanced-cache.php' ) use ( $chd_php, $chd_tmp, $chd_host ) {
		$query = (string) wp_parse_url( 'http://x' . $uri, PHP_URL_QUERY );
		$get   = array();
		parse_str( $query, $get );
		$server  = array_merge(
			array(
				'REQUEST_METHOD'  => 'GET',
				'HTTP_HOST'       => $chd_host,
				'HTTPS'           => 'on',
				'REQUEST_URI'     => $uri,
				'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
			),
			$server
		);
		$wrapper = $chd_tmp . 'run-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$wrapper,
			'<?php define( "ABSPATH", "/" ); $_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); $_GET = ' . var_export( $get, true ) . '; $_POST = array(); $_COOKIE = array(); include ' . var_export( $chd_tmp . $which, true ) . '; echo "BYPASS";' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
		$out = shell_exec( escapeshellarg( $chd_php ) . ' -n ' . escapeshellarg( $wrapper ) . ' 2>&1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec -- the only way to run a drop-in before WordPress, in a test.
		unlink( $wrapper ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return (string) $out;
	};

	$ok( 'DESKTOP' === $chd_drun( '/hard-page/' ), 'cache hardening drop-in: control, a desktop gets the desktop copy' );
	$chd_dagree = true;
	foreach ( $chd_uas as $chd_ua ) {
		$chd_got     = $chd_drun( '/hard-page/', array( 'HTTP_USER_AGENT' => $chd_ua ) );
		$chd_dagree  = $chd_dagree && ( $chd_expect[ $chd_ua ] ? 'PHONE' : 'DESKTOP' ) === $chd_got;
	}
	$ok( $chd_dagree, 'cache hardening 4: the drop-in picks the phone copy for exactly the agents wp_is_mobile() calls mobile' );
	$ok( 'DESKTOP' === $chd_drun( '/hard-page/', array( 'HTTP_USER_AGENT' => $chd_uas[0], 'HTTP_SEC_CH_UA_MOBILE' => '?0' ) ) && 'PHONE' === $chd_drun( '/hard-page/', array( 'HTTP_SEC_CH_UA_MOBILE' => '?1' ) ), 'cache hardening 4: the drop-in reads the Sec-CH-UA-Mobile hint first' );
	$ok( 'BYPASS' === $chd_drun( '/hard-page/', array(), 'advanced-cache-filtered.php' ), 'cache hardening 4: with wp_is_mobile filtered, the drop-in leaves the phone question to WordPress' );
	$ok( 'BYPASS' === $chd_drun( '//hard-page/' ) && 'BYPASS' === $chd_drun( '//' ) && 'BYPASS' === $chd_drun( '/x/../hard-page/' ) && 'BYPASS' === $chd_drun( '/hard-page//' ) && 'BYPASS' === $chd_drun( '/%2e%2e/hard-page/' ), 'cache hardening 9: the drop-in sends "//", ".." and empty segments to WordPress instead of keying them' );
	$ok( 'PAGED2' === $chd_drun( '/hard-page/?paged=2' ) && 'BYPASS' === $chd_drun( '/hard-page/?paged=02' ) && 'BYPASS' === $chd_drun( '/hard-page/?paged=1' ) && 'BYPASS' === $chd_drun( '/hard-page/?paged=abc' ) && 'BYPASS' === $chd_drun( '/hard-page/?page[]=2' ) && 'BYPASS' === $chd_drun( '/hard-page/?paged[x]=2' ), 'cache hardening 6: the drop-in serves ?paged=2 and refuses made-up page numbers and arrays' );

	// 10: the log rotates at 1 MB.
	file_put_contents( $chd_log, "<?php exit; ?>\n" . str_repeat( 'L', 1048577 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$chd_drun( '/hard-page/' );
	$chd_old = substr( $chd_log, 0, -8 ) . '.old.log.php';
	clearstatcache();
	$ok( ! is_file( $chd_log ) && is_file( $chd_old ) && filesize( $chd_old ) > 1048576, 'cache hardening 10: the drop-in rotates its debug log once it passes 1 MB' );
	file_put_contents( $chd_log, "<?php exit; ?>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$chd_drun( '/hard-page/' );
	$ok( false !== strpos( (string) file_get_contents( $chd_log ), 'HIT early /hard-page/' ), 'cache hardening 10: a fresh log takes the next line' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( 0 === strpos( $chd_src, '<?php' ) && false !== strpos( $chd_src, Perdita_Caching::DROPIN_MARKER . ' v3' ), 'cache hardening: the drop-in says it is version 3' );
} else {
	echo "SKIP  cache hardening drop-in: needs a CLI PHP binary\n";
}

/* ------------------------------------------------------------------ */
/* 7 and 13. WP-CLI, in a child process with a stand-in WP_CLI.         */
/* 8. Uninstall, in a child process that restores what it deletes.      */
/* ------------------------------------------------------------------ */

if ( '' !== $chd_php ) {
	$chd_cli = $chd_child(
		implode(
			"\n",
			array(
				'class WP_CLI {',
				'	public static $out = array();',
				'	public static function success( $m ) { self::$out[] = "success: " . $m; }',
				'	public static function log( $m ) { self::$out[] = "log: " . $m; }',
				'	public static function warning( $m ) { self::$out[] = "warning: " . $m; }',
				'	public static function error( $m ) { self::$out[] = "error: " . $m; throw new Exception( $m ); }',
				'	public static function add_command() {}',
				'	public static function get_runner() { return (object) array( "config" => array( "url" => home_url( "/chd-cli-a/" ) ) ); }',
				'}',
				'set_time_limit( 60 );',
				'$GLOBALS["chd_off"] = false;',
				'add_filter( "pre_option_perdita_caching_settings", function () { return array( "enabled" => ! $GLOBALS["chd_off"], "preload" => true ); } );',
				'add_filter( "option_perdita_core", function ( $v ) { $v = is_array( $v ) ? $v : array(); $v["modules"]["caching"] = true; return $v; } );',
				'$bak = array( "pre" => get_option( "perdita_cache_preload", null ), "cron" => _get_cron_array() );',
				'register_shutdown_function( function () use ( $bak ) { if ( null === $bak["pre"] ) { delete_option( "perdita_cache_preload" ); } else { update_option( "perdita_cache_preload", $bak["pre"], false ); } _set_cron_array( $bak["cron"] ); delete_transient( "perdita_cache_preload_lock" ); } );',
				'require_once PERDITA_CORE_DIR . "inc/modules/caching/class-perdita-caching.php";',
				'Perdita_Caching::load( "preload" );',
				'require_once PERDITA_CORE_DIR . "inc/modules/caching/class-perdita-caching-cli.php";',
				'Perdita_Caching::install();',
				'$seed = function ( $p ) { file_put_contents( Perdita_Caching::file_for_key( "http://" . wp_parse_url( home_url(), PHP_URL_HOST ) . $p ), "<html>" . $p ); };',
				'$has = function ( $p ) { clearstatcache(); return is_file( Perdita_Caching::file_for_key( "http://" . wp_parse_url( home_url(), PHP_URL_HOST ) . $p ) ); };',
				'$cli = new Perdita_Caching_CLI();',
				'$r = array();',
				'$seed( "/chd-cli-a/" ); $seed( "/chd-cli-b/" );',
				'$cli->purge( array( home_url( "/chd-cli-a/" ) ), array() );',
				'$r["positional"] = ! $has( "/chd-cli-a/" ) && $has( "/chd-cli-b/" );',
				'$seed( "/chd-cli-a/" );',
				'$cli->purge( array(), array( "page-url" => home_url( "/chd-cli-b/" ) ) );',
				'$r["flag"] = $has( "/chd-cli-a/" ) && ! $has( "/chd-cli-b/" );',
				'$seed( "/chd-cli-b/" );',
				'$cli->purge( array(), array() );',
				'$r["global_url_ignored"] = ! $has( "/chd-cli-a/" ) && ! $has( "/chd-cli-b/" );',
				'add_filter( "pre_http_request", function () { return array( "headers" => array(), "body" => "", "response" => array( "code" => 200, "message" => "OK" ), "cookies" => array() ); } );',
				'add_filter( "schedule_event", function ( $e ) { if ( $e && "perdita_cache_preload_batch" === $e->hook ) { $GLOBALS["chd_off"] = true; } return $e; } );',
				'$t = microtime( true );',
				'try { $cli->preload( array(), array() ); } catch ( Exception $e ) { WP_CLI::$out[] = "caught: " . $e->getMessage(); }',
				'$r["loop_exited"] = true;',
				'$r["loop_seconds"] = round( microtime( true ) - $t, 2 );',
				'$r["out"] = array_slice( WP_CLI::$out, -4 );',
				'echo "CHD_JSON:" . wp_json_encode( $r ) . "\n";',
			)
		)
	);
	$ok( ! empty( $chd_cli['positional'] ), 'cache hardening 7: wp perdita cache purge <url> purges that one page' . ( empty( $chd_cli['positional'] ) ? ' (' . wp_json_encode( $chd_cli ) . ')' : '' ) );
	$ok( ! empty( $chd_cli['flag'] ), 'cache hardening 7: --page-url=<url> does the same' );
	$ok( ! empty( $chd_cli['global_url_ignored'] ), 'cache hardening 7: WP-CLI\'s global --url never narrows the purge to one page on a single site' );
	$ok( ! empty( $chd_cli['loop_exited'] ) && $chd_cli['loop_seconds'] < 30, 'cache hardening 13: a CLI preload that stops moving ends instead of looping forever' );

	$chd_rows_before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'perdita\\_%' OR option_name LIKE '\\_transient\\_%perdita%' OR option_name = 'cron'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$chd_un          = $chd_child(
		implode(
			"\n",
			array(
				'global $wpdb;',
				'$like = "option_name LIKE \'perdita\\\\_%\' OR option_name LIKE \'\\\\_transient\\\\_%perdita%\' OR option_name = \'cron\'";',
				'$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE $like", ARRAY_A );',
				'$GLOBALS["chd_restored"] = false;',
				'$restore = function () use ( $wpdb, $like, $rows ) { if ( $GLOBALS["chd_restored"] ) { return; } $GLOBALS["chd_restored"] = true; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE $like" ); foreach ( $rows as $row ) { $wpdb->insert( $wpdb->options, $row ); } wp_cache_flush(); };',
				'register_shutdown_function( $restore );',
				'wp_schedule_single_event( time() + 3600, "perdita_cache_preload_start", array( "refresh" ) );',
				'wp_schedule_single_event( time() + 3600, "perdita_cache_preload_start" );',
				'wp_schedule_single_event( time() + 3600, "perdita_cache_preload_batch" );',
				'wp_schedule_single_event( time() + 3600, "perdita_cache_gc_soon" );',
				'wp_schedule_event( time() + 3600, "daily", "perdita_cache_gc" );',
				'set_transient( "perdita_cache_preload_lock", 1, 600 );',
				'update_option( "perdita_cache_preload", array( "status" => "running" ), false );',
				'define( "WP_UNINSTALL_PLUGIN", "perdita-core/perdita-core.php" );',
				'include PERDITA_CORE_DIR . "uninstall.php";',
				'wp_cache_flush();',
				'$r = array(',
				'	"refresh" => false === wp_next_scheduled( "perdita_cache_preload_start", array( "refresh" ) ),',
				'	"start" => false === wp_next_scheduled( "perdita_cache_preload_start" ),',
				'	"batch" => false === wp_next_scheduled( "perdita_cache_preload_batch" ),',
				'	"gc" => false === wp_next_scheduled( "perdita_cache_gc" ),',
				'	"gc_soon" => false === wp_next_scheduled( "perdita_cache_gc_soon" ),',
				'	"lock" => false === get_transient( "perdita_cache_preload_lock" ),',
				'	"state" => false === get_option( "perdita_cache_preload" ),',
				');',
				'$restore();',
				'echo "CHD_JSON:" . wp_json_encode( $r ) . "\n";',
			)
		)
	);
	wp_cache_flush();
	$chd_rows_after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'perdita\\_%' OR option_name LIKE '\\_transient\\_%perdita%' OR option_name = 'cron'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ok( ! empty( $chd_un['refresh'] ) && ! empty( $chd_un['start'] ) && ! empty( $chd_un['batch'] ), 'cache hardening 8: uninstall removes every preload event, the scheduled refresh with its argument included' . ( empty( $chd_un['refresh'] ) ? ' (' . wp_json_encode( $chd_un ) . ')' : '' ) );
	$ok( ! empty( $chd_un['gc'] ) && ! empty( $chd_un['gc_soon'] ), 'cache hardening 8: uninstall removes both cleanup events' );
	$ok( ! empty( $chd_un['lock'] ) && ! empty( $chd_un['state'] ), 'cache hardening 8: uninstall removes the preload state and its lock' );
	$ok( $chd_rows_before === $chd_rows_after, 'cache hardening 8: the uninstall check put every row it deleted back' );
} else {
	echo "SKIP  cache hardening 7, 8 and 13: need a CLI PHP binary\n";
}

/* ------------------------------------------------------------------ */
/* 1 and 5, over HTTP: DONOTCACHEPAGE and Cache-Control on every path. */
/* ------------------------------------------------------------------ */

$chd_port   = 0;
$chd_router = $chd_tmp . 'router.php';
$chd_cfgf   = $chd_tmp . 'cfg.json';
$chd_cfg_w  = static function ( array $settings ) use ( $chd_cfgf ) {
	file_put_contents( $chd_cfgf, (string) wp_json_encode( array( 'settings' => array_merge( array( 'enabled' => true ), $settings ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};
if ( '' !== $chd_php && function_exists( 'proc_open' ) ) {
	$chd_cfg_w( array() );
	file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$chd_router,
		implode(
			"\n",
			array(
				'<?php',
				'$chd_cfg = json_decode( (string) file_get_contents( ' . var_export( $chd_cfgf, true ) . ' ), true );', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				'$chd_probe = isset( $_SERVER["HTTP_X_PERDITA_PROBE"] ) ? $_SERVER["HTTP_X_PERDITA_PROBE"] : "";',
				'$GLOBALS["wp_filter"] = array();',
				'$chd_add = function ( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS["wp_filter"][ $hook ][ $prio ][] = array( "function" => $cb, "accepted_args" => $args ); };',
				'$chd_add( "pre_option_perdita_caching_settings", function () use ( $chd_cfg ) { return $chd_cfg["settings"]; } );',
				'$chd_core = function ( $v ) { $v = is_array( $v ) ? $v : array(); if ( empty( $v["modules"] ) || ! is_array( $v["modules"] ) ) { $v["modules"] = array(); } $v["modules"]["caching"] = true; return $v; };',
				'$chd_add( "option_perdita_core", $chd_core );',
				'$chd_add( "default_option_perdita_core", $chd_core );',
				'if ( "dnc" === $chd_probe ) { $chd_add( "wp", function () { define( "DONOTCACHEPAGE", true ); } ); }',
				'if ( "dnc_late" === $chd_probe ) { $chd_add( "template_include", function ( $t ) { define( "DONOTCACHEPAGE", true ); return $t; } ); }',
				'if ( "cookie" === $chd_probe || "cookie_ok" === $chd_probe ) { $chd_add( "template_redirect", function () { setcookie( "chd_probe", "1" ); }, 5 ); }',
				'if ( "cookie_ok" === $chd_probe ) { $chd_add( "perdita_cache_allow_set_cookie", function ( $n ) { $n[] = "chd_probe"; return $n; } ); }',
				'if ( "redirect" === $chd_probe ) { $chd_add( "template_redirect", function () { wp_redirect( home_url( "/elsewhere/" ), 302 ); exit; }, 5 ); }',
				'if ( "private" === $chd_probe ) { $chd_add( "template_redirect", function () { header( "Cache-Control: private, max-age=60" ); }, 5 ); }',
				'chdir( ' . var_export( ABSPATH, true ) . ' );', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				'require ' . var_export( ABSPATH . 'index.php', true ) . ';', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			)
		)
	);
	for ( $chd_try = 0; $chd_try < 5 && ! is_resource( $GLOBALS['chd_server'] ); $chd_try++ ) {
		$chd_port  = wp_rand( 20000, 40000 );
		$chd_probe = @fsockopen( '127.0.0.1', $chd_port, $chd_en, $chd_es, 0.2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
		if ( $chd_probe ) {
			fclose( $chd_probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			continue;
		}
		$chd_pipes = array();
		$GLOBALS['chd_server'] = proc_open( // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
			array( $chd_php, '-S', '127.0.0.1:' . $chd_port, '-t', ABSPATH, $chd_router ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', $chd_tmp . 'server.log', 'a' ),
				2 => array( 'file', $chd_tmp . 'server.log', 'a' ),
			),
			$chd_pipes
		);
	}
	$chd_up = false;
	for ( $chd_i = 0; $chd_i < 50 && is_resource( $GLOBALS['chd_server'] ); $chd_i++ ) {
		$chd_sock = @fsockopen( '127.0.0.1', $chd_port, $chd_en, $chd_es, 0.2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
		if ( $chd_sock ) {
			fclose( $chd_sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$chd_up = true;
			break;
		}
		usleep( 100000 );
	}
	$chd_get = static function ( $path, $probe = '' ) use ( $chd_port, $chd_host ) {
		$sock = @fsockopen( '127.0.0.1', $chd_port, $en, $es, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
		if ( ! $sock ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
			);
		}
		stream_set_timeout( $sock, 60 );
		fwrite( $sock, 'GET ' . $path . " HTTP/1.0\r\nHost: " . $chd_host . "\r\nUser-Agent: Mozilla/5.0 (Macintosh) Safari/605\r\n" . ( '' !== $probe ? 'X-Perdita-Probe: ' . $probe . "\r\n" : '' ) . "Connection: close\r\n\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$raw = (string) stream_get_contents( $sock );
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$parts   = explode( "\r\n\r\n", $raw, 2 );
		$lines   = explode( "\r\n", $parts[0] );
		$headers = array();
		foreach ( array_slice( $lines, 1 ) as $line ) {
			$at = strpos( $line, ':' );
			if ( false !== $at ) {
				$headers[ strtolower( trim( substr( $line, 0, $at ) ) ) ][] = trim( substr( $line, $at + 1 ) );
			}
		}
		return array(
			'status'  => (int) substr( $lines[0], 9, 3 ),
			'headers' => $headers,
			'body'    => isset( $parts[1] ) ? $parts[1] : '',
		);
	};
	$chd_h = static function ( $res, $name ) {
		return isset( $res['headers'][ $name ] ) ? implode( ', ', $res['headers'][ $name ] ) : '';
	};
	if ( $chd_up ) {
		Perdita_Caching::purge_everything();
		$chd_r1 = $chd_get( '/' );
		$chd_r2 = $chd_get( '/' );
		$ok( 200 === $chd_r1['status'] && 'MISS' === $chd_h( $chd_r1, 'x-cache' ) && 'HIT' === $chd_h( $chd_r2, 'x-cache' ) && $chd_has( '/' ), 'cache hardening http: control, the home page is stored on the first request and served on the second' . ( 200 !== $chd_r1['status'] ? ' (status ' . $chd_r1['status'] . ', ' . $chd_h( $chd_r1, 'x-cache' ) . ')' : '' ) );
		$ok( false !== stripos( $chd_h( $chd_r1, 'cache-control' ), 'public' ) && false !== strpos( $chd_h( $chd_r1, 'cache-control' ), 'max-age=300' ) && 1 === count( $chd_r1['headers']['cache-control'] ?? array() ), 'cache hardening 5: a stored page goes out public for five minutes, with one Cache-Control header' );

		Perdita_Caching::purge_everything();
		$chd_r = $chd_get( '/', 'dnc' );
		$ok( ! $chd_has( '/' ) && '' === $chd_h( $chd_r, 'x-cache' ), 'cache hardening 1: a page that defines DONOTCACHEPAGE before the template is neither served from nor stored in the cache' );
		$chd_r = $chd_get( '/', 'dnc_late' );
		$ok( ! $chd_has( '/' ) && 'MISS' === $chd_h( $chd_r, 'x-cache' ) && false === stripos( $chd_h( $chd_r, 'cache-control' ), 'public' ) && false !== stripos( $chd_h( $chd_r, 'cache-control' ), 'no-store' ), 'cache hardening 1 and 5: a page that defines DONOTCACHEPAGE while it renders is not stored, and goes out private, no-store' );
		$chd_set( array( 'serve_stale' => true ) );
		$chd_cfg_w( array( 'serve_stale' => true ) );
		$chd_get( '/' );
		Perdita_Caching::purge_urls( array( home_url( '/' ) ) );
		$chd_hf = Perdita_Caching::file_for_key( $chd_key( '/' ) );
		file_put_contents( $chd_hf . '.lock', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$chd_r = $chd_get( '/', 'dnc' );
		$ok( 'STALE' !== $chd_h( $chd_r, 'x-cache' ), 'cache hardening 1: a stale copy is never served to a page that says DONOTCACHEPAGE' );
		$chd_r = $chd_get( '/' );
		$ok( 'STALE' === $chd_h( $chd_r, 'x-cache' ), 'cache hardening 1: control, the same stale copy goes to an ordinary request while the rebuild lock holds' );
		$chd_set( array() );
		$chd_cfg_w( array() );

		Perdita_Caching::purge_everything();
		$chd_r = $chd_get( '/', 'cookie' );
		$ok( ! $chd_has( '/' ) && ! empty( $chd_r['headers']['set-cookie'] ) && false !== stripos( $chd_h( $chd_r, 'cache-control' ), 'no-store' ) && false === stripos( $chd_h( $chd_r, 'cache-control' ), 'public' ), 'cache hardening 2 and 5: an anonymous response that sets a cookie is not stored and goes out private, no-store' );
		$chd_r = $chd_get( '/', 'cookie_ok' );
		$ok( $chd_has( '/' ), 'cache hardening 2: over HTTP, perdita_cache_allow_set_cookie lets a listed cookie through' );

		Perdita_Caching::purge_everything();
		$chd_r = $chd_get( '/', 'redirect' );
		$ok( 302 === $chd_r['status'] && ! $chd_has( '/' ) && false === stripos( $chd_h( $chd_r, 'cache-control' ), 'public' ), 'cache hardening 5: a redirect gets no public Cache-Control from the cache' . ( 302 !== $chd_r['status'] ? ' (status ' . $chd_r['status'] . ')' : '' ) );
		$chd_r = $chd_get( '/', 'private' );
		$ok( ! $chd_has( '/' ) && 'private, max-age=60' === $chd_h( $chd_r, 'cache-control' ), 'cache hardening 5: a stricter Cache-Control a plugin sent is kept as it is, and the page is not stored' );
		$chd_r = $chd_get( '/?paged=abc' );
		$ok( '' === $chd_h( $chd_r, 'x-cache' ) && ! $chd_has( '/' ), 'cache hardening 6: over HTTP, ?paged=abc skips the cache and writes nothing' );
		$chd_r = $chd_get( '//' );
		$ok( '' === $chd_h( $chd_r, 'x-cache' ) && ! $chd_has( '/' ), 'cache hardening 9: over HTTP, a // request is not keyed as the home page' );
		Perdita_Caching::purge_everything();
	} else {
		echo 'SKIP  cache hardening http: the test server did not start (' . esc_html( (string) @file_get_contents( $chd_tmp . 'server.log' ) ) . ")\n"; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
	if ( is_resource( $GLOBALS['chd_server'] ) ) {
		proc_terminate( $GLOBALS['chd_server'] );
		proc_close( $GLOBALS['chd_server'] );
		$GLOBALS['chd_server'] = null;
	}
} else {
	echo "SKIP  cache hardening http: needs a CLI PHP binary and proc_open\n";
}

/* ------------------------------------------------------------------ */
/* Restore.                                                             */
/* ------------------------------------------------------------------ */

$_GET    = $chd_state['get'];
$_COOKIE = $chd_state['cookie'];
$_SERVER = $chd_state['server'];
wp_set_current_user( $chd_state['user'] );
$chd_cleanup();
$ok( _get_cron_array() == $chd_cron, 'cache hardening: the cron array is back as it was' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
unset( $GLOBALS['chd_settings'] );
