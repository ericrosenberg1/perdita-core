<?php
/**
 * Perdita Core smoke tests: the page cache features added to match WP Super
 * Cache. Preload, gzip copies, serve stale while rebuilding, the phone
 * cache, rejected user agents and cookies, garbage collection, the cached
 * pages list, CDN rewriting, feed caching, the debug log, WP-CLI, and the
 * extension points Perdita Pro codes against.
 *
 * Loaded by tests/smoke.php, so $ok() and the counters are the ones it
 * defines. Settings are fed through a pre_option filter, so the stored
 * option is never written. The preload state option, the cron events and
 * every file this fragment creates are restored or removed at the end, and
 * again from a shutdown function if a fatal cuts the run short.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- page cache parity (tests/smoke-caching-parity.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
Perdita_Caching::load( 'tools' );
Perdita_Caching::load( 'cdn' );
Perdita_Caching::load( 'preload' );

/* ------------------------------------------------------------------ */
/* Harness: settings through a filter, and a cleanup that always runs. */
/* ------------------------------------------------------------------ */

$GLOBALS['cpar_settings'] = array( 'enabled' => true );
$cpar_pre_option         = static function () {
	return $GLOBALS['cpar_settings'];
};
add_filter( 'pre_option_' . Perdita_Caching::OPTION, $cpar_pre_option );
$cpar_set = static function ( array $patch ) {
	$GLOBALS['cpar_settings'] = array_merge( array( 'enabled' => true ), $patch );
};

Perdita_Caching::install();
$cpar_dir      = Perdita_Caching::cache_dir();
// Cache artifacts are disposable (the suite purges more than once), so the
// before-and-after comparison is about everything else in the directory.
$cpar_keepers  = static function ( $dir ) {
	$names = array();
	foreach ( (array) glob( $dir . '{,.}[!.]*', GLOB_BRACE ) as $f ) {
		// Purge stamps, purge marks and the running size total are runtime
		// state that comes and goes with every purge.
		if ( ! preg_match( '/\.(html|tmp|purged|html\.(?:json|gz|stale|lock))$|(?:^|\/)(?:purged-all|usage)\.stamp$/', $f ) ) {
			$names[] = basename( $f );
		}
	}
	sort( $names );
	return $names;
};
$cpar_before   = $cpar_keepers( $cpar_dir );
$cpar_preload  = get_option( Perdita_Caching_Preload::OPTION, null );
$cpar_dropin   = Perdita_Caching::dropin_path();
$cpar_made_dropin = false;
$cpar_state    = array(
	'get'    => $_GET,
	'cookie' => $_COOKIE,
	'server' => $_SERVER,
	'user'   => get_current_user_id(),
);

$GLOBALS['cpar_cleanup_done'] = false;
$cpar_cleanup                 = static function () use ( $cpar_dir, $cpar_before, $cpar_preload, $cpar_pre_option, &$cpar_made_dropin, $cpar_dropin ) {
	if ( $GLOBALS['cpar_cleanup_done'] ) {
		return;
	}
	$GLOBALS['cpar_cleanup_done'] = true;
	remove_filter( 'pre_option_' . Perdita_Caching::OPTION, $cpar_pre_option );
	if ( null === $cpar_preload ) {
		delete_option( Perdita_Caching_Preload::OPTION );
	} else {
		update_option( Perdita_Caching_Preload::OPTION, $cpar_preload, false );
	}
	delete_transient( Perdita_Caching_Preload::LOCK );
	wp_clear_scheduled_hook( Perdita_Caching::GC_HOOK );
	Perdita_Caching::clear_preload_schedules();
	foreach ( (array) glob( $cpar_dir . '{,.}[!.]*', GLOB_BRACE ) as $cpar_f ) {
		if ( is_file( $cpar_f ) && ! in_array( basename( $cpar_f ), array_merge( $cpar_before, array( '.htaccess', 'index.php' ) ), true ) ) {
			unlink( $cpar_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	if ( $cpar_made_dropin && is_file( $cpar_dropin ) ) {
		unlink( $cpar_dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
};
register_shutdown_function( $cpar_cleanup );

$cpar_host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$cpar_cache = ( new ReflectionClass( 'Perdita_Caching' ) )->newInstanceWithoutConstructor();
$cpar_m     = static function ( $name ) {
	$m = new ReflectionMethod( 'Perdita_Caching', $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m;
};
$cpar_p     = static function ( $name ) {
	$p = new ReflectionProperty( 'Perdita_Caching', $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	return $p;
};
$cpar_page  = static function ( $path, $variant = '', $body = null ) use ( $cpar_host ) {
	$file = Perdita_Caching::file_for_key( 'http://' . $cpar_host . $path, $variant );
	file_put_contents( $file, null === $body ? '<!doctype html><html><body>' . $path . '</body></html>' : $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $file;
};

/* ------------------------------------------------------------------ */
/* 1. Settings: every new key has a default, and sanitize() cleans it.  */
/* ------------------------------------------------------------------ */

$cpar_set( array() );
$cpar_s    = Perdita_Caching::settings();
$cpar_keys = array( 'serve_stale', 'gzip', 'mobile_cache', 'cache_feeds', 'reject_user_agents', 'reject_cookies', 'preload', 'preload_interval_hours', 'gc_schedule', 'cdn_enabled', 'cdn_host', 'cdn_dirs', 'cdn_exclude', 'debug_log', 'debug_log_until' );
$ok( array() === array_diff( $cpar_keys, array_keys( $cpar_s ) ), 'cache parity: settings() returns every new key' );
$ok( false === $cpar_s['serve_stale'] && false === $cpar_s['gzip'] && false === $cpar_s['mobile_cache'] && false === $cpar_s['cache_feeds'] && false === $cpar_s['preload'] && false === $cpar_s['cdn_enabled'] && false === $cpar_s['debug_log'] && 0 === $cpar_s['preload_interval_hours'], 'cache parity: every new feature is off by default' );
$ok( 'daily' === $cpar_s['gc_schedule'] && 1024 === $cpar_s['max_size_mb'] && 'all' === $cpar_s['purge_on_save'], 'cache parity: the cleanup runs daily by default, under a 1 GB cap, and a post save clears everything' );
$ok( array( 'wp-content', 'wp-includes' ) === $cpar_s['cdn_dirs'] && array( '.php' ) === $cpar_s['cdn_exclude'] && array() === $cpar_s['reject_user_agents'] && array() === $cpar_s['reject_cookies'], 'cache parity: the list settings default to arrays' );
$ok( array() === array_diff( $cpar_keys, array_keys( Perdita_Caching::sanitize( array() ) ) ), 'cache parity: sanitize() returns every new key too' );

$cpar_clean = Perdita_Caching::sanitize(
	array(
		'cdn_host'               => ' HTTPS://CDN.Example.com/some/path?x=1 ',
		'cdn_dirs'               => "wp-content\n../../etc\n/wp-includes/\nassets/img\nbad dir!",
		'cdn_exclude'            => ".php\n<b>bold</b>.min.js\n" . str_repeat( 'x', 101 ),
		'reject_user_agents'     => "BadBot\r\n\r\n  Other Crawler  \nBadBot",
		'reject_cookies'         => array( 'member_tier', array( 'nested' ), "two\tparts" ),
		'gc_schedule'            => 'every-second',
		'preload_interval_hours' => 99999,
		'debug_log'              => 1,
	)
);
$ok( 'cdn.example.com' === $cpar_clean['cdn_host'], 'cache parity: the CDN host is reduced to a bare lowercase hostname' );
$ok( '' === Perdita_Caching::sanitize( array( 'cdn_host' => 'cdn.example.com"><script>' ) )['cdn_host'] && '' === Perdita_Caching::sanitize( array( 'cdn_host' => 'javascript:alert(1)' ) )['cdn_host'] && '' === Perdita_Caching::sanitize( array( 'cdn_host' => array( 'x' ) ) )['cdn_host'], 'cache parity: a CDN host with markup, a script scheme or an array is refused' );
$ok( 'cdn.example.com:8443' === Perdita_Caching::sanitize( array( 'cdn_host' => '//cdn.example.com:8443/' ) )['cdn_host'], 'cache parity: a CDN host may carry a port' );
$ok( array( 'wp-content', 'wp-includes', 'assets/img' ) === $cpar_clean['cdn_dirs'], 'cache parity: CDN directories drop "..", stray characters and outer slashes' );
$ok( array( '.php', 'bold.min.js' ) === $cpar_clean['cdn_exclude'], 'cache parity: list entries lose markup, and an entry over 100 characters is dropped' );
$ok( array( 'BadBot', 'Other Crawler' ) === $cpar_clean['reject_user_agents'], 'cache parity: rejected user agents are trimmed, deduplicated and blank lines dropped' );
$ok( array( 'member_tier', 'two parts' ) === $cpar_clean['reject_cookies'], 'cache parity: a list given as an array is cleaned the same way, nested arrays ignored' );
$ok( 'daily' === $cpar_clean['gc_schedule'] && 720 === $cpar_clean['preload_interval_hours'], 'cache parity: an unknown cleanup schedule falls back to daily, and the refresh interval is capped' );
$ok( true === $cpar_clean['debug_log'] && abs( $cpar_clean['debug_log_until'] - ( time() + DAY_IN_SECONDS ) ) <= 5, 'cache parity: turning the debug log on starts a 24 hour clock' );
$cpar_set(
	array(
		'debug_log'       => true,
		'debug_log_until' => time() + 500,
	)
);
$ok( abs( Perdita_Caching::sanitize( array( 'debug_log' => 1 ) )['debug_log_until'] - ( time() + 500 ) ) <= 5, 'cache parity: saving again while the log is on keeps the original deadline' );
$cpar_set(
	array(
		'debug_log'       => true,
		'debug_log_until' => time() - 5,
	)
);
$ok( false === Perdita_Caching::settings()['debug_log'], 'cache parity: the debug log reads as off once its 24 hours are up' );

/* ------------------------------------------------------------------ */
/* 2. File names, variants and phone detection.                         */
/* ------------------------------------------------------------------ */

$cpar_set( array() );
$cpar_key  = 'http://' . $cpar_host . '/parity-names/';
$cpar_base = Perdita_Caching::file_for_key( $cpar_key );
$cpar_var  = Perdita_Caching::file_for_key( $cpar_key, 'mobile' );
$ok( $cpar_dir . sha1( $cpar_key ) . '.html' === $cpar_base, 'cache parity: the shared copy keeps its old file name, so existing caches stay valid' );
$ok( 1 === preg_match( '/^' . sha1( $cpar_key ) . '-[a-f0-9]{12}\.html$/', basename( $cpar_var ) ), 'cache parity: a variant shares the URL hash and adds a 12 character keyed suffix' );
$ok( basename( $cpar_var ) !== sha1( $cpar_key ) . '-' . substr( sha1( 'mobile' ), 0, 12 ) . '.html', 'cache parity: the variant suffix is keyed on the site salt, not a plain hash anyone can compute' );
$ok( false === strpos( Perdita_Caching::file_for_key( $cpar_key, '../../../etc/passwd' ), '..' ), 'cache parity: a variant can never put a path into the file name' );

$ok( Perdita_Caching::is_mobile_ua( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1' ) && Perdita_Caching::is_mobile_ua( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120 Mobile Safari/537.36' ), 'cache parity: iPhone and Android phone user agents are phones' );
$ok( ! Perdita_Caching::is_mobile_ua( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15' ) && ! Perdita_Caching::is_mobile_ua( '' ), 'cache parity: a desktop (and an iPad, which sends a Mac user agent) and an empty user agent get the desktop copy' );

/* ------------------------------------------------------------------ */
/* 3. Purging: the public API, both actions, and stale marking.         */
/* ------------------------------------------------------------------ */

$ok( ( new ReflectionMethod( 'Perdita_Caching', 'purge_urls' ) )->isStatic() && ( new ReflectionMethod( 'Perdita_Caching', 'purge_urls' ) )->isPublic() && ( new ReflectionMethod( 'Perdita_Caching', 'purge_everything' ) )->isStatic() && ( new ReflectionMethod( 'Perdita_Caching', 'purge_everything' ) )->isPublic(), 'cache parity: purge_urls() and purge_everything() are public and static' );

$cpar_events = array();
$cpar_on_all = static function ( $removed ) use ( &$cpar_events ) {
	$cpar_events[] = array( 'all', $removed );
};
$cpar_on_old = static function ( $removed ) use ( &$cpar_events ) {
	$cpar_events[] = array( 'old', $removed );
};
$cpar_on_url = static function ( $url, $count ) use ( &$cpar_events ) {
	$cpar_events[] = array( 'url', $url, $count );
};
add_action( 'perdita_cache_purged_all', $cpar_on_all );
add_action( 'perdita_caching_purged', $cpar_on_old );
add_action( 'perdita_cache_purged_url', $cpar_on_url, 10, 2 );

// purge_urls(), serve stale off: every copy of the URL goes, under both
// schemes, with everything stored beside it. Other pages stay.
$cpar_a   = $cpar_page( '/parity-a/' );
$cpar_am  = $cpar_page( '/parity-a/', 'mobile' );
$cpar_as  = Perdita_Caching::file_for_key( 'https://' . $cpar_host . '/parity-a/' );
file_put_contents( $cpar_as, '<!doctype html><html></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$cpar_b   = $cpar_page( '/parity-b/' );
foreach ( array( '.json', '.gz', '.stale', '.lock' ) as $cpar_ext ) {
	file_put_contents( $cpar_a . $cpar_ext, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
$cpar_events = array();
$cpar_n      = Perdita_Caching::purge_urls( array( home_url( '/parity-a/' ), 'https://another-site.example/parity-b/', 42 ) );
$ok( 3 === $cpar_n && ! is_file( $cpar_a ) && ! is_file( $cpar_am ) && ! is_file( $cpar_as ), 'cache parity: purge_urls() removes the page, its phone copy and its https copy' );
$ok( ! is_file( $cpar_a . '.json' ) && ! is_file( $cpar_a . '.gz' ) && ! is_file( $cpar_a . '.stale' ) && ! is_file( $cpar_a . '.lock' ), 'cache parity: the header sidecar, gzip copy, stale mark and lock go with the page' );
$ok( is_file( $cpar_b ), 'cache parity: purge_urls() ignores a URL on another host and leaves other pages alone' );
$ok( array( array( 'url', home_url( '/parity-a/' ), 3 ) ) === $cpar_events, 'cache parity: perdita_cache_purged_url fires once, with the URL and the count' );

// A copy under a variant another plugin chose is found by the URL's hash.
$cpar_variant = static function () {
	return 'gold-members';
};
add_filter( 'perdita_cache_key_variant', $cpar_variant );
$cpar_c  = $cpar_page( '/parity-c/' );
$cpar_cv = $cpar_page( '/parity-c/', 'x:gold-members' );
$ok( 2 === Perdita_Caching::purge_urls( array( '/parity-c/' ) ) && ! is_file( $cpar_c ) && ! is_file( $cpar_cv ), 'cache parity: purge_urls() takes a bare path and removes copies under a custom variant too' );
remove_filter( 'perdita_cache_key_variant', $cpar_variant );

// Serve stale on: a single-URL purge marks the copy and keeps it.
$cpar_set( array( 'serve_stale' => true ) );
$cpar_d = $cpar_page( '/parity-d/' );
$ok( 1 === Perdita_Caching::purge_urls( array( home_url( '/parity-d/' ) ) ) && is_file( $cpar_d ) && is_file( $cpar_d . '.stale' ), 'cache parity: with serve stale on, a single-URL purge marks the page stale and keeps the file' );
$cpar_first = (int) file_get_contents( $cpar_d . '.stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
file_put_contents( $cpar_d . '.stale', (string) ( time() - 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
Perdita_Caching::purge_urls( array( home_url( '/parity-d/' ) ) );
$ok( $cpar_first > 0 && abs( Perdita_Caching::stale_since( $cpar_d ) - ( time() - 100 ) ) <= 2, 'cache parity: a second purge keeps the time of the first, so the hard cap is not pushed back' );

// The 0.19.8 regression: a FULL purge must delete everything at once,
// stale files included, whatever serve stale says.
$cpar_e = $cpar_page( '/parity-e/' );
file_put_contents( $cpar_e . '.gz', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $cpar_e . '.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $cpar_e . '.lock', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$cpar_log_keep = $cpar_dir . 'debug-parity-keep.log.php';
file_put_contents( $cpar_log_keep, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$cpar_events  = array();
$cpar_removed = $cpar_cache->purge_all();
$cpar_left    = array_filter(
	(array) glob( $cpar_dir . '*' ),
	static function ( $f ) {
		return (bool) preg_match( '/\.html(\.(json|gz|stale|lock))?$/', $f );
	}
);
$ok( array() === $cpar_left, 'cache parity: a full purge with serve stale ON still deletes every page, gzip copy, sidecar, stale mark and lock at once (the 0.19.8 stale-HTML bug)' );
$ok( $cpar_removed >= 3 && is_file( $cpar_dir . 'index.php' ) && is_file( $cpar_dir . '.htaccess' ) && is_file( $cpar_log_keep ), 'cache parity: a full purge counts pages and leaves index.php, .htaccess and the debug log alone' );
$ok( array( array( 'old', $cpar_removed ), array( 'all', $cpar_removed ) ) === $cpar_events, 'cache parity: perdita_caching_purged still fires, and perdita_cache_purged_all fires after it' );
unlink( $cpar_log_keep ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
$ok( false !== strpos( (string) file_get_contents( $cpar_dir . '.htaccess' ), 'Require all denied' ), 'cache parity: the cache directory refuses direct requests on Apache' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// A preload progress write must never purge (it happens every few seconds).
$cpar_f = $cpar_page( '/parity-f/' );
$cpar_cache->purge_on_option_change( 'perdita_cache_preload' );
$ok( is_file( $cpar_f ), 'cache parity: writing the preload state option does not purge the cache' );
$cpar_cache->purge_on_option_change( 'perdita_caching_settings' );
$ok( ! is_file( $cpar_f ), 'cache parity: saving the caching settings still purges it' );

remove_action( 'perdita_cache_purged_all', $cpar_on_all );
remove_action( 'perdita_caching_purged', $cpar_on_old );
remove_action( 'perdita_cache_purged_url', $cpar_on_url, 10 );

/* ------------------------------------------------------------------ */
/* 4. lookup(): hit, stale only under a live lock, and the hard cap.    */
/* ------------------------------------------------------------------ */

$cpar_on  = array(
	'ttl_hours'   => 10,
	'serve_stale' => true,
);
$cpar_off = array(
	'ttl_hours'   => 10,
	'serve_stale' => false,
);
$cpar_g   = $cpar_page( '/parity-g/' );
$cpar_look = static function ( $file, $settings ) {
	clearstatcache();
	return Perdita_Caching::lookup( $file, $settings );
};
$ok( 'hit' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: a fresh file is a hit' );
$ok( 'miss' === $cpar_look( $cpar_dir . 'nope.html', $cpar_on )['state'] && false === $cpar_look( $cpar_dir . 'nope.html', $cpar_on )['exists'], 'cache parity: a missing file is a miss' );
file_put_contents( $cpar_g . '.stale', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$ok( 'miss' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: a page marked stale with no rebuild running is rebuilt, not served' );
file_put_contents( $cpar_g . '.lock', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
clearstatcache();
$ok( 'stale' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: while another request holds the rebuild lock, the old copy is served' );
$ok( 'miss' === $cpar_look( $cpar_g, $cpar_off )['state'], 'cache parity: with serve stale off a marked page is never served' );
touch( $cpar_g . '.lock', time() - Perdita_Caching::LOCK_TTL - 5 );
clearstatcache();
$ok( 'miss' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: a rebuild lock older than 30 seconds no longer holds visitors on the old copy' );
touch( $cpar_g . '.lock' );
file_put_contents( $cpar_g . '.stale', (string) ( time() - Perdita_Caching::STALE_MAX_AGE - 60 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
clearstatcache();
$ok( 'miss' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: past the hard cap a stale page is not served, lock or no lock' );
unlink( $cpar_g . '.stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
touch( $cpar_g, time() - 10 * HOUR_IN_SECONDS - 120 );
clearstatcache();
$ok( 'stale' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: a page that expired two minutes ago is served stale while it is rebuilt' );
touch( $cpar_g, time() - 11 * HOUR_IN_SECONDS );
clearstatcache();
$ok( 'miss' === $cpar_look( $cpar_g, $cpar_on )['state'], 'cache parity: a page that expired an hour ago is past the cap and is not served' );
Perdita_Caching::delete_entry( $cpar_g );
$ok( ! is_file( $cpar_g ) && ! is_file( $cpar_g . '.lock' ), 'cache parity: delete_entry() removes the page and what sits beside it' );
$ok( 0 === Perdita_Caching::delete_entry( ABSPATH . 'wp-config.php' ) && 0 === Perdita_Caching::delete_entry( $cpar_dir . '../../../index.html' ) && is_file( ABSPATH . 'wp-config.php' ), 'cache parity: delete_entry() refuses any path outside the cache directory' );

/* ------------------------------------------------------------------ */
/* 5. write_buffer(): gzip copy, sidecar, stale mark, feeds, non-200.   */
/* ------------------------------------------------------------------ */

$cpar_capture = static function ( $file, $body, array $props = array() ) use ( $cpar_cache, $cpar_p ) {
	foreach ( array_merge(
		array(
			'cache_file'        => $file,
			'cache_url'         => 'http://parity.example/captured/',
			'cache_variant'     => '',
			'is_feed_request'   => false,
			'logged_in_allowed' => false,
			'capture_started'   => microtime( true ),
		),
		$props
	) as $name => $value ) {
		$cpar_p( $name )->setValue( $cpar_cache, $value );
	}
	ob_start(); // Swallows what write_buffer() sends to the "browser".
	ob_start(); // The capture buffer.
	$cpar_p( 'buffering' )->setValue( $cpar_cache, true );
	$cpar_p( 'buffer_level' )->setValue( $cpar_cache, ob_get_level() );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$cpar_cache->write_buffer();
	return (string) ob_get_clean();
};

$cpar_set(
	array(
		'gzip'        => true,
		'serve_stale' => true,
	)
);
$cpar_h    = Perdita_Caching::file_for_key( 'http://' . $cpar_host . '/parity-h/' );
$cpar_html = '<!doctype html><html><body>' . str_repeat( 'gzip me ', 200 ) . '</body></html>';
file_put_contents( $cpar_h, 'old copy' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $cpar_h . '.stale', (string) ( time() - 50 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
touch( $cpar_h . '.stale', time() - 50 );
file_put_contents( $cpar_h . '.lock', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$cpar_sent = $cpar_capture( $cpar_h, $cpar_html, array( 'cache_variant' => 'mobile' ) );
clearstatcache();
$ok( $cpar_html === $cpar_sent && $cpar_html === (string) file_get_contents( $cpar_h ), 'cache parity: the page is stored and the visitor still gets it' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cpar_gz = is_file( $cpar_h . '.gz' ) ? (string) file_get_contents( $cpar_h . '.gz' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( '' !== $cpar_gz && "\x1f\x8b" === substr( $cpar_gz, 0, 2 ) && $cpar_html === gzdecode( $cpar_gz ) && strlen( $cpar_gz ) < strlen( $cpar_html ), 'cache parity: a gzip copy is stored beside the page and decompresses to the same HTML' );
$ok( filemtime( $cpar_h . '.gz' ) >= filemtime( $cpar_h ), 'cache parity: the gzip copy is never older than its page' );
$cpar_side = json_decode( (string) file_get_contents( $cpar_h . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cpar_meta = is_array( $cpar_side ) ? end( $cpar_side ) : null;
$ok( is_array( $cpar_meta ) && 'http://parity.example/captured/' === $cpar_meta['url'] && 'mobile' === $cpar_meta['variant'] && 'page' === $cpar_meta['type'], 'cache parity: the sidecar\'s last entry names the URL, the variant and the type' );
$ok( ! is_file( $cpar_h . '.stale' ) && ! is_file( $cpar_h . '.lock' ), 'cache parity: once the fresh copy is written, the stale mark and the rebuild lock are gone' );

// A purge that lands while the page is rendering keeps its mark.
file_put_contents( $cpar_h . '.stale', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$cpar_capture( $cpar_h, $cpar_html, array( 'capture_started' => time() - 20 ) );
$ok( is_file( $cpar_h . '.stale' ), 'cache parity: a purge that arrives mid-render keeps its stale mark, so the page is rebuilt again' );
unlink( $cpar_h . '.stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

// With gzip off, a leftover gzip copy is removed instead of going stale.
$cpar_set( array( 'gzip' => false ) );
$cpar_capture( $cpar_h, $cpar_html );
$ok( is_file( $cpar_h ) && ! is_file( $cpar_h . '.gz' ), 'cache parity: with gzip copies off, no gzip copy is kept' );

// A body that is not a page is never stored, and the old copy goes.
$cpar_capture( $cpar_h, '{"json":true}' );
$ok( ! is_file( $cpar_h ) && ! is_file( $cpar_h . '.json' ), 'cache parity: a response that is not HTML is not stored, and the old copy is dropped instead of being served stale' );

// Feeds: stored only on a feed request, and the HTML filter never sees them.
$cpar_feed        = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>t</title></channel></rss>';
$cpar_saw_filter  = false;
$cpar_html_filter = static function ( $html ) use ( &$cpar_saw_filter ) {
	$cpar_saw_filter = true;
	return $html;
};
add_filter( 'perdita_cache_output_html', $cpar_html_filter );
$cpar_capture( $cpar_h, $cpar_feed );
$ok( ! is_file( $cpar_h ), 'cache parity: a feed body on an ordinary request is not stored' );
$cpar_saw_filter = false;
$cpar_capture( $cpar_h, $cpar_feed, array( 'is_feed_request' => true ) );
$cpar_side = is_file( $cpar_h . '.json' ) ? json_decode( (string) file_get_contents( $cpar_h . '.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cpar_meta = end( $cpar_side );
$ok( is_file( $cpar_h ) && $cpar_feed === (string) file_get_contents( $cpar_h ) && is_array( $cpar_meta ) && 'feed' === $cpar_meta['type'], 'cache parity: with the feed cache on, a feed is stored as a feed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( false === $cpar_saw_filter, 'cache parity: perdita_cache_output_html (the HTML minifier hook) never runs on a feed' );
$cpar_capture( $cpar_h, $cpar_html );
$ok( true === $cpar_saw_filter, 'cache parity: perdita_cache_output_html still runs on a page' );
remove_filter( 'perdita_cache_output_html', $cpar_html_filter );
Perdita_Caching::delete_entry( $cpar_h );

/* ------------------------------------------------------------------ */
/* 6. is_cacheable(): rejected lists, logged-in filter, feeds.          */
/* ------------------------------------------------------------------ */

$cpar_is  = $cpar_m( 'is_cacheable' );
$cpar_rv  = $cpar_m( 'request_variant' );
$cpar_pin = static function () {
	return false;
};
add_filter( 'perdita_cache_exclude', $cpar_pin, PHP_INT_MAX );
wp_set_current_user( 0 );
$_GET                       = array();
$_COOKIE                    = array();
$_SERVER['HTTP_HOST']       = $cpar_host;
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605';
$cpar_req                   = array(
	'enabled'            => true,
	'ttl_hours'          => 10,
	'exclude_paths'      => array(),
	'reject_user_agents' => array( 'badbot' ),
	'mobile_cache'       => true,
);
$ok( true === $cpar_is->invoke( $cpar_cache, $cpar_req ), 'cache parity: an ordinary anonymous request is cacheable' );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; BadBot/2.1)';
$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ) && 'user agent' === $cpar_p( 'skip_reason' )->getValue( $cpar_cache ), 'cache parity: a rejected user agent skips the cache, matched without regard to case' );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605';

$cpar_set( array( 'reject_cookies' => array( 'member_tier' ) ) );
$ok( in_array( 'member_tier', Perdita_Caching::no_cache_cookies(), true ) && in_array( 'wordpress_logged_in', Perdita_Caching::no_cache_cookies(), true ) && in_array( 'wp-postpass_', Perdita_Caching::no_cache_cookies(), true ), 'cache parity: rejected cookies are added on top of the built-in list' );
$_COOKIE = array( 'site_member_tier_x' => '1' );
$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ) && 'cookie' === $cpar_p( 'skip_reason' )->getValue( $cpar_cache ), 'cache parity: a visitor carrying a rejected cookie skips the cache' );
$_COOKIE = array();
$cpar_set( array() );
$ok( false === strpos( Perdita_Caching::dropin_source(), 'badbot-in-dropin' ), 'cache parity: the drop-in holds no rejected user agent until one is set' );
$cpar_set(
	array(
		'reject_user_agents' => array( 'badbot-in-dropin' ),
		'reject_cookies'     => array( 'cookie-in-dropin' ),
	)
);
$ok( false !== strpos( Perdita_Caching::dropin_source(), 'badbot-in-dropin' ) && false !== strpos( Perdita_Caching::dropin_source(), 'cookie-in-dropin' ), 'cache parity: the drop-in carries the rejected user agents and cookies' );
$cpar_set( array() );

// Phone copy.
$ok( '' === $cpar_rv->invoke( $cpar_cache, $cpar_req ), 'cache parity: a desktop request uses the shared copy' );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
$ok( 'mobile' === $cpar_rv->invoke( $cpar_cache, $cpar_req ), 'cache parity: a phone gets its own variant when the phone cache is on' );
$ok( '' === $cpar_rv->invoke( $cpar_cache, array( 'mobile_cache' => false ) ), 'cache parity: with the phone cache off a phone shares the one copy' );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605';

// perdita_cache_key_variant.
add_filter( 'perdita_cache_key_variant', $cpar_variant );
$ok( 'x:gold-members' === $cpar_rv->invoke( $cpar_cache, $cpar_req ), 'cache parity: perdita_cache_key_variant is mixed into the cache key' );
remove_filter( 'perdita_cache_key_variant', $cpar_variant );

// perdita_cache_allow_logged_in.
$cpar_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( ! empty( $cpar_admins ) ) {
	$cpar_uid = (int) $cpar_admins[0];
	wp_set_current_user( $cpar_uid );
	$_COOKIE = array( 'wordpress_logged_in_abc' => 'x' );
	$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ) && 'logged in' === $cpar_p( 'skip_reason' )->getValue( $cpar_cache ), 'cache parity: a logged-in request is not cacheable by default' );
	$cpar_seen_uid = null;
	$cpar_allow    = static function ( $allow, $user_id ) use ( &$cpar_seen_uid ) {
		$cpar_seen_uid = $user_id;
		return true;
	};
	add_filter( 'perdita_cache_allow_logged_in', $cpar_allow, 10, 2 );
	$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ) && $cpar_uid === $cpar_seen_uid, 'cache parity: perdita_cache_allow_logged_in receives the user id, and without a key variant the request is still refused (it would land in the anonymous copy)' );
	add_filter( 'perdita_cache_key_variant', $cpar_variant );
	$ok( true === $cpar_is->invoke( $cpar_cache, $cpar_req ) && true === $cpar_p( 'logged_in_allowed' )->getValue( $cpar_cache ), 'cache parity: with the filter and a key variant, a logged-in request may be cached' );
	$_COOKIE['woocommerce_cart_hash'] = 'x';
	$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ), 'cache parity: a cart cookie still keeps an allowed logged-in request out' );
	remove_filter( 'perdita_cache_key_variant', $cpar_variant );
	remove_filter( 'perdita_cache_allow_logged_in', $cpar_allow, 10 );
	wp_set_current_user( 0 );
	$_COOKIE = array();
}
$ok( false !== strpos( Perdita_Caching::dropin_source(), 'wordpress_logged_in' ), 'cache parity: the drop-in keeps skipping login cookies' );

// Feeds.
global $wp_query;
$cpar_was_feed     = $wp_query->is_feed;
$wp_query->is_feed = true;
$ok( false === $cpar_is->invoke( $cpar_cache, $cpar_req ) && 'feed' === $cpar_p( 'skip_reason' )->getValue( $cpar_cache ), 'cache parity: a feed is not cacheable while the feed cache is off' );
$ok( true === $cpar_is->invoke( $cpar_cache, array_merge( $cpar_req, array( 'cache_feeds' => true ) ) ) && true === $cpar_p( 'is_feed_request' )->getValue( $cpar_cache ), 'cache parity: with the feed cache on, a feed is cacheable and flagged as one' );
$wp_query->is_feed = $cpar_was_feed;
remove_filter( 'perdita_cache_exclude', $cpar_pin, PHP_INT_MAX );

/* ------------------------------------------------------------------ */
/* 7. The drop-in, in its own PHP process.                              */
/* ------------------------------------------------------------------ */

$ok( '3' === Perdita_Caching::DROPIN_VERSION, 'cache parity: the drop-in version moved to 3 with its new source' );
if ( ! file_exists( $cpar_dropin ) ) {
	$cpar_made_dropin = true;
	file_put_contents( $cpar_dropin, "<?php\n/**\n * " . Perdita_Caching::DROPIN_MARKER . " v1.\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok( 'stale' === Perdita_Caching::dropin_state(), 'cache parity: a version 1 drop-in reads as stale, which is what makes sync_dropin() rewrite it' );
	unlink( $cpar_dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	$cpar_made_dropin = false;
} else {
	echo "SKIP  cache parity: an advanced-cache.php already exists, so the stale drop-in check did not write over it\n";
}

$cpar_php = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
$cpar_tmp = trailingslashit( get_temp_dir() ) . 'perdita-parity-' . wp_generate_password( 6, false ) . '/';
if ( '' !== $cpar_php && wp_mkdir_p( $cpar_tmp ) ) {
	$cpar_set(
		array(
			'gzip'               => true,
			'mobile_cache'       => true,
			'serve_stale'        => true,
			'reject_user_agents' => array( 'BadBot' ),
			'reject_cookies'     => array( 'member_tier' ),
		)
	);
	$cpar_src = str_replace( wp_json_encode( $cpar_dir, JSON_UNESCAPED_SLASHES ), wp_json_encode( $cpar_tmp, JSON_UNESCAPED_SLASHES ), Perdita_Caching::dropin_source() );
	$cpar_set( array() );
	file_put_contents( $cpar_tmp . 'advanced-cache.php', $cpar_src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $cpar_tmp . 'early-serve.on', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$cpar_dkey   = 'https://' . $cpar_host . '/parity-page/';
	$cpar_dfile  = $cpar_tmp . sha1( $cpar_dkey ) . '.html';
	$cpar_dmob   = $cpar_tmp . sha1( $cpar_dkey ) . Perdita_Caching::variant_suffix( 'mobile' ) . '.html';
	$cpar_dbody  = '<!doctype html><p>DESKTOP ' . str_repeat( 'page ', 100 ) . '</p>';
	file_put_contents( $cpar_dfile, $cpar_dbody ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $cpar_dfile . '.gz', gzencode( $cpar_dbody, 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $cpar_dfile . '.json', wp_json_encode( array( 'Content-Type: text/html; charset=UTF-8', array( 'url' => $cpar_dkey ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $cpar_dmob, '<!doctype html><p>PHONE</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	$cpar_run = static function ( array $server = array(), array $cookie = array() ) use ( $cpar_php, $cpar_tmp, $cpar_host ) {
		$server  = array_merge(
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => $cpar_host,
				'HTTPS'          => 'on',
				'REQUEST_URI'    => '/parity-page/',
			),
			$server
		);
		$wrapper = $cpar_tmp . 'run-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$wrapper,
			'<?php define( "ABSPATH", "/" ); $_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); $_GET = array(); $_POST = array(); $_COOKIE = ' . var_export( $cookie, true ) . '; include ' . var_export( $cpar_tmp . 'advanced-cache.php', true ) . '; echo "BYPASS";' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
		$out = shell_exec( escapeshellarg( $cpar_php ) . ' -n ' . escapeshellarg( $wrapper ) . ' 2>&1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec -- the only way to run a drop-in before WordPress, in a test.
		unlink( $wrapper ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return (string) $out;
	};
	$cpar_phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

	$ok( $cpar_dbody === $cpar_run(), 'cache parity drop-in: a cached page is served as plain HTML when the visitor sends no Accept-Encoding' );
	$cpar_out = $cpar_run( array( 'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br' ) );
	$ok( "\x1f\x8b" === substr( $cpar_out, 0, 2 ) && $cpar_dbody === @gzdecode( $cpar_out ), 'cache parity drop-in: with Accept-Encoding: gzip the stored gzip bytes are served, and they decompress to the same page' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$ok( $cpar_dbody === $cpar_run( array( 'HTTP_ACCEPT_ENCODING' => 'gzip;q=0, identity' ) ), 'cache parity drop-in: gzip;q=0 gets the plain copy' );
	touch( $cpar_dfile . '.gz', time() - 500 );
	touch( $cpar_dfile, time() - 100 );
	$ok( $cpar_dbody === $cpar_run( array( 'HTTP_ACCEPT_ENCODING' => 'gzip' ) ), 'cache parity drop-in: a gzip copy older than its page is ignored' );
	touch( $cpar_dfile . '.gz' );
	touch( $cpar_dfile );
	$ok( false !== strpos( $cpar_run( array( 'HTTP_USER_AGENT' => $cpar_phone ) ), 'PHONE' ), 'cache parity drop-in: a phone gets the phone copy' );
	$ok( $cpar_dbody === $cpar_run( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15' ) ), 'cache parity drop-in: an iPad, which sends a Mac user agent, gets the desktop copy' );
	$ok( 'BYPASS' === $cpar_run( array( 'HTTP_USER_AGENT' => 'x badbot y' ) ), 'cache parity drop-in: a rejected user agent goes to WordPress' );
	$ok( 'BYPASS' === $cpar_run( array(), array( 'a_member_tier' => '1' ) ), 'cache parity drop-in: a rejected cookie goes to WordPress' );
	$ok( 'BYPASS' === $cpar_run( array(), array( 'wordpress_logged_in_x' => '1' ) ), 'cache parity drop-in: a login cookie still goes to WordPress' );

	// Stale handling.
	file_put_contents( $cpar_dfile . '.stale', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok( 'BYPASS' === $cpar_run(), 'cache parity drop-in: a page marked stale with no rebuild running goes to WordPress' );
	file_put_contents( $cpar_dfile . '.lock', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok( $cpar_dbody === $cpar_run(), 'cache parity drop-in: while a rebuild lock is live, the stale page is served' );
	file_put_contents( $cpar_dfile . '.stale', (string) ( time() - Perdita_Caching::STALE_MAX_AGE - 60 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok( 'BYPASS' === $cpar_run(), 'cache parity drop-in: past the hard cap the stale page goes to WordPress' );
	file_put_contents( $cpar_dfile . '.stale', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	touch( $cpar_dfile . '.lock', time() - Perdita_Caching::LOCK_TTL - 5 );
	$ok( 'BYPASS' === $cpar_run(), 'cache parity drop-in: a dead rebuild lock stops the stale page being served' );
	unlink( $cpar_dfile . '.stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	touch( $cpar_dfile, time() - 100 * DAY_IN_SECONDS );
	touch( $cpar_dfile . '.lock' );
	$ok( 'BYPASS' === $cpar_run(), 'cache parity drop-in: a page that expired long ago is never served stale' );

	foreach ( (array) glob( $cpar_tmp . '*' ) as $cpar_f ) {
		unlink( $cpar_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	@rmdir( $cpar_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
} else {
	echo "SKIP  cache parity drop-in: no PHP CLI binary to run the drop-in in its own process\n";
}

/* ------------------------------------------------------------------ */
/* 8. CDN rewriting.                                                    */
/* ------------------------------------------------------------------ */

$cpar_cdn  = array(
	'cdn_enabled' => true,
	'cdn_host'    => 'cdn.example.com',
	'cdn_dirs'    => array( 'wp-content', 'wp-includes' ),
	'cdn_exclude' => array( '.php', 'no-cdn' ),
);
$cpar_site = home_url();
$cpar_bare = preg_replace( '#^https?:#', '', $cpar_site );
$cpar_sch  = (string) wp_parse_url( $cpar_site, PHP_URL_SCHEME );
$cpar_in   = implode(
	"\n",
	array(
		'<link rel="stylesheet" href="' . $cpar_site . '/wp-content/themes/x/style.css?ver=1.2">',
		'<script src=\'' . $cpar_bare . '/wp-includes/js/jquery.min.js\'></script>',
		'<img src="' . $cpar_site . '/wp-content/uploads/a.jpg" srcset="' . $cpar_site . '/wp-content/uploads/a-300.jpg 300w, ' . $cpar_site . '/wp-content/uploads/a-600.webp 600w">',
		'<div style="background:url(' . $cpar_site . '/wp-content/uploads/bg.png)"></div>',
		'<a href="' . $cpar_site . '/wp-content/plugins/x/download.php?file=a.jpg">php</a>',
		'<img src="' . $cpar_site . '/wp-content/uploads/no-cdn/b.jpg">',
		'<a href="' . $cpar_site . '/wp-content/uploads/report.html">not static</a>',
		'<a href="' . $cpar_site . '/about/picture.jpg">outside the directories</a>',
		'<img src="https://elsewhere.example/wp-content/uploads/c.jpg">',
		'<script>var d = {"u":"' . str_replace( '/', '\\/', $cpar_site . '/wp-content/uploads/json.jpg' ) . '"};</script>',
	)
);
$cpar_out  = Perdita_Caching_CDN::rewrite( $cpar_in, $cpar_cdn );
$ok( false !== strpos( $cpar_out, 'href="' . $cpar_sch . '://cdn.example.com/wp-content/themes/x/style.css?ver=1.2"' ), 'cache parity cdn: a stylesheet URL moves to the CDN host and keeps its query string' );
$ok( false !== strpos( $cpar_out, "src='//cdn.example.com/wp-includes/js/jquery.min.js'" ), 'cache parity cdn: a protocol-relative script URL moves too' );
$ok( false !== strpos( $cpar_out, '://cdn.example.com/wp-content/uploads/a-300.jpg 300w, ' . $cpar_sch . '://cdn.example.com/wp-content/uploads/a-600.webp 600w' ), 'cache parity cdn: every URL in a srcset moves' );
$ok( false !== strpos( $cpar_out, 'url(' . $cpar_sch . '://cdn.example.com/wp-content/uploads/bg.png)' ), 'cache parity cdn: a URL inside inline CSS moves' );
$ok( false !== strpos( $cpar_out, $cpar_site . '/wp-content/plugins/x/download.php?file=a.jpg' ) && false !== strpos( $cpar_out, $cpar_site . '/wp-content/uploads/no-cdn/b.jpg' ), 'cache parity cdn: a URL containing an excluded string stays on the site' );
$ok( false !== strpos( $cpar_out, $cpar_site . '/wp-content/uploads/report.html' ) && false !== strpos( $cpar_out, $cpar_site . '/about/picture.jpg' ), 'cache parity cdn: a file that is not a static asset, or sits outside the directories, stays' );
$ok( false !== strpos( $cpar_out, 'https://elsewhere.example/wp-content/uploads/c.jpg' ) && false !== strpos( $cpar_out, str_replace( '/', '\\/', $cpar_site . '/wp-content/uploads/json.jpg' ) ), 'cache parity cdn: another site\'s URL and a JSON-escaped URL are left alone' );
$ok( $cpar_in === Perdita_Caching_CDN::rewrite( $cpar_in, array_merge( $cpar_cdn, array( 'cdn_host' => '' ) ) ) && $cpar_in === Perdita_Caching_CDN::rewrite( $cpar_in, array_merge( $cpar_cdn, array( 'cdn_host' => (string) wp_parse_url( $cpar_site, PHP_URL_HOST ) ) ) ), 'cache parity cdn: with no CDN host, or the site\'s own host, nothing changes' );
$cpar_inst = new Perdita_Caching( perdita_core() );
$ok( 2 === has_action( 'template_redirect', array( 'Perdita_Caching', 'start_cdn_buffer' ) ) && 1 === has_action( 'template_redirect', array( $cpar_inst, 'serve_or_capture' ) ), 'cache parity cdn: the rewrite buffer opens right after the capture buffer, so cached and uncached pages match' );
$ok( false !== has_action( Perdita_Caching::GC_HOOK, array( 'Perdita_Caching', 'run_gc' ) ) && false !== has_action( 'perdita_cache_preload_batch', array( 'Perdita_Caching', 'run_preload_batch' ) ) && false !== has_action( 'perdita_cache_preload_start', array( 'Perdita_Caching', 'run_preload_start' ) ), 'cache parity: the cron hooks for cleanup and preload are wired' );
// A throwaway instance: unhook it again, as smoke-perf.php does.
remove_action( 'template_redirect', array( $cpar_inst, 'serve_or_capture' ), 1 );
remove_action( 'save_post', array( $cpar_inst, 'on_save_post' ), 10 );
remove_action( 'comment_post', array( $cpar_inst, 'on_comment' ) );
remove_action( 'transition_comment_status', array( $cpar_inst, 'on_comment_status' ), 10 );
foreach ( array( 'switch_theme', 'customize_save_after', 'upgrader_process_complete' ) as $cpar_hook ) {
	remove_action( $cpar_hook, array( $cpar_inst, 'purge_all' ) );
}
foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $cpar_hook ) {
	remove_action( $cpar_hook, array( $cpar_inst, 'purge_on_option_change' ) );
}
// The CDN rewrite never runs in the Customizer preview or on a feed.
$wp_query->is_feed = true;
$ok( false === Perdita_Caching_CDN::should_rewrite(), 'cache parity cdn: a feed is never rewritten' );
$wp_query->is_feed = $cpar_was_feed;
$cpar_was_preview     = $wp_query->is_preview;
$wp_query->is_preview = true;
$ok( false === Perdita_Caching_CDN::should_rewrite(), 'cache parity cdn: a preview is never rewritten' );
$wp_query->is_preview = $cpar_was_preview;
$ok( true === Perdita_Caching_CDN::should_rewrite(), 'cache parity cdn: an ordinary front-end page is rewritten' );

/* ------------------------------------------------------------------ */
/* 9. Preload.                                                          */
/* ------------------------------------------------------------------ */

$cpar_set( array( 'preload' => true ) );
$cpar_post  = wp_insert_post(
	array(
		'post_title'  => 'Parity Preload Post',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
$cpar_locked = wp_insert_post(
	array(
		'post_title'    => 'Parity Locked Post',
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_password' => 'secret',
	)
);
$cpar_draft = wp_insert_post(
	array(
		'post_title'  => 'Parity Draft Post',
		'post_status' => 'draft',
		'post_type'   => 'post',
	)
);
$cpar_calls = array();
$cpar_http  = static function ( $pre, $args, $url ) use ( &$cpar_calls ) {
	$cpar_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => false !== strpos( $url, 'parity-fails' ) ? 500 : 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $cpar_http, 10, 3 );
$cpar_extra = static function ( $urls, $phase ) {
	// Returned on every batch, on purpose: it must still be built once.
	$urls[] = home_url( '/parity-extra/' );
	$urls[] = 'https://another-site.example/stolen/';
	if ( 'start' === $phase ) {
		$urls[] = home_url( '/parity-fails/' );
	}
	return $urls;
};
add_filter( 'perdita_cache_preload_urls', $cpar_extra, 10, 2 );
$cpar_small = static function () {
	return 3;
};
add_filter( 'perdita_cache_preload_batch_size', $cpar_small );
$cpar_finished = null;
$cpar_on_done  = static function ( $stats ) use ( &$cpar_finished ) {
	$cpar_finished = $stats;
};
add_action( 'perdita_cache_preload_finished', $cpar_on_done );

$ok( true === Perdita_Caching_Preload::start( 'fill' ), 'cache parity preload: a preload starts when the page cache is on' );
$cpar_st = Perdita_Caching_Preload::state( true );
$ok( 'running' === $cpar_st['status'] && $cpar_st['total'] >= 2 && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ), 'cache parity preload: starting records a total and books the first WP-Cron batch' );
$cpar_st = Perdita_Caching_Preload::run_batch();
$ok( 'running' === $cpar_st['status'] && $cpar_st['done'] >= 3 && $cpar_st['done'] < $cpar_st['total'] + 3 && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ), 'cache parity preload: one batch builds a few pages, saves its place and books the next batch' );
$cpar_resume = Perdita_Caching_Preload::state( true );
$ok( $cpar_resume['done'] === $cpar_st['done'] && 'posts' === $cpar_resume['phase'], 'cache parity preload: progress is stored, so a later run resumes instead of starting over' );
$cpar_guard = 0;
while ( 'running' === $cpar_st['status'] && $cpar_guard++ < 500 ) {
	$cpar_st = Perdita_Caching_Preload::run_batch();
}
$cpar_urls = wp_list_pluck( $cpar_calls, 'url' );
$ok( 'done' === $cpar_st['status'] && $cpar_st['finished'] > 0, 'cache parity preload: the preload finishes' );
$ok( in_array( home_url( '/' ), $cpar_urls, true ) && in_array( get_permalink( $cpar_post ), $cpar_urls, true ), 'cache parity preload: the home page and a published post are requested' );
$ok( ! in_array( get_permalink( $cpar_locked ), $cpar_urls, true ) && ! in_array( get_permalink( $cpar_draft ), $cpar_urls, true ), 'cache parity preload: a password-protected post and a draft are never requested' );
$cpar_cat = get_category_link( (int) get_option( 'default_category' ) );
$ok( in_array( $cpar_cat, $cpar_urls, true ), 'cache parity preload: a term archive with posts is requested' );
$ok( 1 === count( array_keys( $cpar_urls, home_url( '/parity-extra/' ), true ) ), 'cache parity preload: a URL added by perdita_cache_preload_urls on every batch is built once' );
$ok( ! in_array( 'https://another-site.example/stolen/', $cpar_urls, true ), 'cache parity preload: a URL on another host is never requested' );
$cpar_bad_ua  = false;
$cpar_cookies = false;
foreach ( $cpar_calls as $cpar_call ) {
	if ( false === strpos( (string) $cpar_call['args']['user-agent'], 'Perdita Cache Preload' ) ) {
		$cpar_bad_ua = true;
	}
	if ( ! empty( $cpar_call['args']['cookies'] ) || isset( $cpar_call['args']['headers']['Cookie'] ) || isset( $cpar_call['args']['headers']['cookie'] ) ) {
		$cpar_cookies = true;
	}
}
$ok( ! $cpar_bad_ua && ! $cpar_cookies && count( $cpar_calls ) > 0, 'cache parity preload: every request names the preloader in its user agent and carries no cookies' );
$ok( is_array( $cpar_finished ) && $cpar_finished['done'] === $cpar_st['done'] && 1 === $cpar_finished['failed'] && $cpar_finished['built'] === $cpar_st['done'] - 1, 'cache parity preload: perdita_cache_preload_finished fires with the counts, the failed page among them' );
$ok( false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ), 'cache parity preload: a finished preload books no further batch' );

// A page that is already fresh is not requested again.
$cpar_calls = array();
$cpar_fresh = $cpar_page( (string) wp_parse_url( get_permalink( $cpar_post ), PHP_URL_PATH ) );
$ok( 'cached' === Perdita_Caching_Preload::build( get_permalink( $cpar_post ), 'fill', Perdita_Caching::settings() ) && array() === $cpar_calls, 'cache parity preload: a page with a fresh copy is skipped' );
$ok( 'built' === Perdita_Caching_Preload::build( get_permalink( $cpar_post ), 'refresh', Perdita_Caching::settings() ) && 1 === count( $cpar_calls ) && ! is_file( $cpar_fresh ), 'cache parity preload: a refresh run purges the copy and requests the page again' );
$cpar_calls = array();
$cpar_set(
	array(
		'preload'      => true,
		'mobile_cache' => true,
	)
);
Perdita_Caching_Preload::build( home_url( '/parity-two-copies/' ), 'fill', Perdita_Caching::settings() );
$ok( 2 === count( $cpar_calls ) && Perdita_Caching::is_mobile_ua( $cpar_calls[1]['args']['user-agent'] ) && ! Perdita_Caching::is_mobile_ua( $cpar_calls[0]['args']['user-agent'] ) && false !== strpos( $cpar_calls[1]['args']['user-agent'], 'Perdita Cache Preload' ), 'cache parity preload: with the phone cache on, each page is requested once per copy' );
$cpar_set(
	array(
		'preload'       => true,
		'exclude_paths' => array( '/parity-private' ),
	)
);
$cpar_calls = array();
Perdita_Caching_Preload::build( home_url( '/parity-private/area/' ), 'fill', Perdita_Caching::settings() );
$ok( array() === $cpar_calls, 'cache parity preload: a path that is never cached is never requested' );
$cpar_set( array( 'preload' => true ) );

// A purged URL is queued and rebuilt by the next batch.
wp_clear_scheduled_hook( Perdita_Caching_Preload::HOOK_BATCH );
$cpar_calls = array();
$cpar_q     = $cpar_page( '/parity-queued/' );
Perdita_Caching::purge_urls( array( home_url( '/parity-queued/' ) ) );
$cpar_st = Perdita_Caching_Preload::state( true );
$ok( array( home_url( '/parity-queued/' ) ) === $cpar_st['queue'] && false !== wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ), 'cache parity preload: a purged URL is queued for a rebuild and a batch is booked' );
Perdita_Caching::run_preload_batch();
$cpar_st = Perdita_Caching_Preload::state( true );
$ok( array( home_url( '/parity-queued/' ) ) === wp_list_pluck( $cpar_calls, 'url' ) && array() === $cpar_st['queue'], 'cache parity preload: the next batch rebuilds the purged URL and empties the queue' );

// A full purge books one restart, a minute out.
wp_clear_scheduled_hook( Perdita_Caching_Preload::HOOK_BATCH );
Perdita_Caching::purge_everything();
Perdita_Caching::purge_everything();
$ok( false !== has_action( 'shutdown', array( 'Perdita_Caching', 'restart_preload' ) ), 'cache parity preload: a full purge defers the restart to shutdown, so many purges make one preload' );
Perdita_Caching::restart_preload();
remove_action( 'shutdown', array( 'Perdita_Caching', 'restart_preload' ) );
$cpar_st   = Perdita_Caching_Preload::state( true );
$cpar_when = wp_next_scheduled( Perdita_Caching_Preload::HOOK_START );
$ok( 'waiting' === $cpar_st['status'] && false !== $cpar_when && $cpar_when > time() + 30, 'cache parity preload: after a full purge a fresh preload is booked for a minute later' );

// Stop.
Perdita_Caching_Preload::start( 'fill' );
Perdita_Caching_Preload::stop();
$cpar_st = Perdita_Caching_Preload::state( true );
$ok( 'stopped' === $cpar_st['status'] && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ) && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_START ), 'cache parity preload: Stop halts the run and unschedules its events' );
$cpar_calls = array();
Perdita_Caching_Preload::run_batch();
$ok( array() === $cpar_calls, 'cache parity preload: a stopped preload builds nothing more' );

// With preloading off, a purge queues nothing.
$cpar_set( array( 'preload' => false ) );
delete_option( Perdita_Caching_Preload::OPTION );
$cpar_q = $cpar_page( '/parity-not-queued/' );
Perdita_Caching::purge_urls( array( home_url( '/parity-not-queued/' ) ) );
Perdita_Caching::purge_everything();
$ok( array() === Perdita_Caching_Preload::state( true )['queue'] && false === has_action( 'shutdown', array( 'Perdita_Caching', 'restart_preload' ) ) && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_BATCH ), 'cache parity preload: with automatic preload off, a purge books nothing' );
$cpar_set( array( 'enabled' => false ) );
$GLOBALS['cpar_settings']['enabled'] = false;
$ok( false === Perdita_Caching_Preload::start( 'fill' ), 'cache parity preload: with the page cache off, a preload does not start' );

// The refresh interval and the cleanup schedule follow the settings.
$cpar_set(
	array(
		'preload'                => true,
		'preload_interval_hours' => 6,
		'gc_schedule'            => 'daily',
	)
);
// The schedules only exist while the caching module is switched on, so
// switch it on for these checks without writing the option.
$cpar_core_on = static function ( $value ) {
	$value = is_array( $value ) ? $value : array();
	if ( ! isset( $value['modules'] ) || ! is_array( $value['modules'] ) ) {
		$value['modules'] = array();
	}
	$value['modules']['caching'] = true;
	return $value;
};
add_filter( 'option_perdita_core', $cpar_core_on );
add_filter( 'default_option_perdita_core', $cpar_core_on );
$cpar_module_on = $cpar_m( 'module_is_on' )->invoke( null );
Perdita_Caching::sync_schedules();
if ( $cpar_module_on ) {
	$cpar_gc_event = wp_get_scheduled_event( Perdita_Caching::GC_HOOK );
	$cpar_refresh  = wp_next_scheduled( Perdita_Caching_Preload::HOOK_START, array( 'refresh' ) );
	$ok( $cpar_gc_event && 'daily' === $cpar_gc_event->schedule, 'cache parity: the cleanup is scheduled at the chosen interval' );
	$ok( false !== $cpar_refresh && abs( $cpar_refresh - ( time() + 6 * HOUR_IN_SECONDS ) ) <= 10, 'cache parity preload: the scheduled refresh is booked at the chosen interval' );
} else {
	$ok( false === wp_next_scheduled( Perdita_Caching::GC_HOOK ) && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_START, array( 'refresh' ) ), 'cache parity: with the caching module switched off, nothing is scheduled' );
	echo "SKIP  cache parity: schedule timing checks need the caching module on in this install\n";
}
$cpar_set(
	array(
		'gc_schedule' => 'off',
		'gc_chosen'   => true,
	)
);
Perdita_Caching::sync_schedules();
remove_filter( 'option_perdita_core', $cpar_core_on );
remove_filter( 'default_option_perdita_core', $cpar_core_on );
$ok( false === wp_next_scheduled( Perdita_Caching::GC_HOOK ) && false === wp_next_scheduled( Perdita_Caching_Preload::HOOK_START, array( 'refresh' ) ), 'cache parity: turning cleanup and preload off unschedules their events' );

remove_filter( 'pre_http_request', $cpar_http, 10 );
remove_filter( 'perdita_cache_preload_urls', $cpar_extra, 10 );
remove_filter( 'perdita_cache_preload_batch_size', $cpar_small );
remove_action( 'perdita_cache_preload_finished', $cpar_on_done );
wp_delete_post( $cpar_post, true );
wp_delete_post( $cpar_locked, true );
wp_delete_post( $cpar_draft, true );

/* ------------------------------------------------------------------ */
/* 10. Garbage collection.                                              */
/* ------------------------------------------------------------------ */

$cpar_set( array( 'ttl_hours' => 10 ) );
Perdita_Caching::purge_everything();
$cpar_new  = $cpar_page( '/parity-gc-fresh/' );
$cpar_old  = $cpar_page( '/parity-gc-old/' );
$cpar_oldm = $cpar_page( '/parity-gc-old/', 'mobile' );
foreach ( array( '.json', '.gz' ) as $cpar_ext ) {
	file_put_contents( $cpar_old . $cpar_ext, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
touch( $cpar_old, time() - 11 * HOUR_IN_SECONDS );
touch( $cpar_oldm, time() - 11 * HOUR_IN_SECONDS );
$cpar_orphan = $cpar_dir . sha1( 'parity-orphan' ) . '.html.json';
file_put_contents( $cpar_orphan, '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
touch( $cpar_orphan, time() - 600 );
$cpar_tmpf = $cpar_dir . sha1( 'parity-tmp' ) . '.html.abc.tmp';
file_put_contents( $cpar_tmpf, 'half' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
touch( $cpar_tmpf, time() - 600 );
clearstatcache();
$cpar_gc = Perdita_Caching::run_gc();
$ok( 2 === $cpar_gc && ! is_file( $cpar_old ) && ! is_file( $cpar_oldm ) && ! is_file( $cpar_old . '.gz' ) && ! is_file( $cpar_old . '.json' ), 'cache parity gc: expired pages go, every copy, with what sits beside them' );
$ok( is_file( $cpar_new ), 'cache parity gc: a fresh page stays' );
$ok( ! is_file( $cpar_orphan ) && ! is_file( $cpar_tmpf ), 'cache parity gc: a sidecar with no page and an abandoned temp file are swept' );
$ok( is_file( $cpar_dir . 'index.php' ) && is_file( $cpar_dir . '.htaccess' ), 'cache parity gc: the guard files stay' );
// With serve stale on, a just-expired page is kept for the stale window.
$cpar_set(
	array(
		'ttl_hours'   => 10,
		'serve_stale' => true,
	)
);
touch( $cpar_new, time() - 10 * HOUR_IN_SECONDS - 60 );
clearstatcache();
$ok( 0 === Perdita_Caching::run_gc() && is_file( $cpar_new ), 'cache parity gc: with serve stale on, a page that just expired is kept for the stale window' );
touch( $cpar_new, time() - 10 * HOUR_IN_SECONDS - Perdita_Caching::STALE_MAX_AGE - 60 );
clearstatcache();
$ok( 1 === Perdita_Caching::run_gc() && ! is_file( $cpar_new ), 'cache parity gc: once the stale window has passed, it goes' );

/* ------------------------------------------------------------------ */
/* 11. Cached pages list.                                               */
/* ------------------------------------------------------------------ */

$cpar_set( array() );
Perdita_Caching::purge_everything();
$cpar_listed = array();
foreach ( array( '/parity-list-apple/', '/parity-list-banana/', '/parity-list-cherry/' ) as $cpar_i => $cpar_path ) {
	$cpar_f = $cpar_page( $cpar_path );
	file_put_contents( $cpar_f . '.json', wp_json_encode( array( 'Content-Type: text/html', array( 'url' => home_url( $cpar_path ), 'variant' => '', 'type' => 'page' ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	touch( $cpar_f, time() - 100 * ( $cpar_i + 1 ) );
	$cpar_listed[ $cpar_path ] = $cpar_f;
}
clearstatcache();
$cpar_list = Perdita_Caching_Tools::list_pages( '', 1, 2 );
$ok( 3 === $cpar_list['total'] && 2 === $cpar_list['pages'] && 2 === count( $cpar_list['rows'] ) && false === $cpar_list['capped'], 'cache parity list: the list is paged' );
$ok( home_url( '/parity-list-apple/' ) === $cpar_list['rows'][0]['url'] && $cpar_list['rows'][0]['age'] >= 100 && $cpar_list['rows'][0]['bytes'] > 0, 'cache parity list: each row carries the URL, age and size, newest first' );
$cpar_page2 = Perdita_Caching_Tools::list_pages( '', 2, 2 );
$ok( 1 === count( $cpar_page2['rows'] ) && home_url( '/parity-list-cherry/' ) === $cpar_page2['rows'][0]['url'], 'cache parity list: the second page holds the rest' );
$cpar_found = Perdita_Caching_Tools::list_pages( 'BANANA', 1, 20 );
$ok( 1 === $cpar_found['total'] && home_url( '/parity-list-banana/' ) === $cpar_found['rows'][0]['url'], 'cache parity list: search matches part of a URL, whatever the case' );
$ok( false === Perdita_Caching_Tools::delete_page( '../../../wp-config' ) && false === Perdita_Caching_Tools::delete_page( 'index' ) && false === Perdita_Caching_Tools::delete_page( str_repeat( 'a', 40 ) . '/../x' ) && is_file( ABSPATH . 'wp-config.php' ), 'cache parity list: delete-one refuses anything but a cache file id' );
$ok( false === Perdita_Caching_Tools::delete_page( str_repeat( 'a', 40 ) ), 'cache parity list: a well-formed id with no file deletes nothing' );
$ok( true === Perdita_Caching_Tools::delete_page( $cpar_found['rows'][0]['id'] ) && ! is_file( $cpar_listed['/parity-list-banana/'] ) && ! is_file( $cpar_listed['/parity-list-banana/'] . '.json' ) && is_file( $cpar_listed['/parity-list-apple/'] ), 'cache parity list: delete-one removes that page and only that page' );

// A cache past the cap: the list stops reading, says so, and a full-URL
// search still finds one page by its hash.
$cpar_many = array();
for ( $cpar_i = 0; $cpar_i <= Perdita_Caching_Tools::LIST_CAP + 20; $cpar_i++ ) {
	$cpar_f = $cpar_dir . sha1( 'parity-bulk-' . $cpar_i ) . '.html';
	file_put_contents( $cpar_f, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$cpar_many[] = $cpar_f;
}
$cpar_t0   = microtime( true );
$cpar_big  = Perdita_Caching_Tools::list_pages( '', 1, 20 );
$cpar_took = microtime( true ) - $cpar_t0;
$ok( true === $cpar_big['capped'] && $cpar_big['total'] <= Perdita_Caching_Tools::LIST_CAP && 20 === count( $cpar_big['rows'] ), 'cache parity list: past ' . number_format( Perdita_Caching_Tools::LIST_CAP ) . ' pages the list stops reading and flags that it did' );
$ok( $cpar_took < 5, 'cache parity list: reading a capped list stays quick (' . round( $cpar_took, 2 ) . 's)' );
$cpar_exact = Perdita_Caching_Tools::list_pages( home_url( '/parity-list-cherry/' ), 1, 20 );
$ok( 1 === count( $cpar_exact['rows'] ) && home_url( '/parity-list-cherry/' ) === $cpar_exact['rows'][0]['url'], 'cache parity list: a full-URL search finds its page in a cache of any size' );
foreach ( $cpar_many as $cpar_f ) {
	unlink( $cpar_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
}
Perdita_Caching::purge_everything();

/* ------------------------------------------------------------------ */
/* 12. Debug log.                                                       */
/* ------------------------------------------------------------------ */

$cpar_log = Perdita_Caching::log_path();
$ok( 0 === strpos( $cpar_log, $cpar_dir ) && 1 === preg_match( '/^debug-[a-f0-9]{16}\.log\.php$/', basename( $cpar_log ) ), 'cache parity log: the log sits in the cache directory under a keyed name that ends in .php' );
$cpar_had_log = is_file( $cpar_log ) ? file_get_contents( $cpar_log ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( null !== $cpar_had_log ) {
	unlink( $cpar_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
}
$cpar_set( array() );
Perdita_Caching::log( 'HIT', 'while off' );
$ok( ! is_file( $cpar_log ), 'cache parity log: with the log off, nothing is written' );
$cpar_set(
	array(
		'debug_log'       => true,
		'debug_log_until' => time() - 1,
	)
);
Perdita_Caching::log( 'HIT', 'after the deadline' );
$ok( ! is_file( $cpar_log ), 'cache parity log: after 24 hours the log is off, with no option write' );
$cpar_set(
	array(
		'debug_log'       => true,
		'debug_log_until' => time() + 100,
	)
);
Perdita_Caching::log( 'HIT', "/a-page/ <?php echo 'x'; ?>\nINJECTED line" );
$cpar_text = (string) file_get_contents( $cpar_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( 0 === strpos( $cpar_text, "<?php exit; ?>\n[" ), 'cache parity log: the file opens with a line that exits, so a direct request shows nothing' );
$ok( 1 === substr_count( $cpar_text, '<?' ) && 2 === substr_count( $cpar_text, "\n" ) && false !== strpos( $cpar_text, 'HIT /a-page/' ), 'cache parity log: a line can not open a PHP tag or start a second line' );
$ok( false !== strpos( Perdita_Caching_Tools::log_tail( 10 ), 'HIT /a-page/' ) && false === strpos( Perdita_Caching_Tools::log_tail( 10 ), 'exit' ), 'cache parity log: the tail shows log lines and never the guard line' );
// Rotation.
file_put_contents( $cpar_log, str_repeat( 'x', Perdita_Caching_Tools::LOG_MAX_BYTES + 10 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
clearstatcache();
Perdita_Caching::log( 'MISS', 'after rotation' );
clearstatcache();
$cpar_old_log = substr( $cpar_log, 0, -8 ) . '.old.log.php';
$ok( is_file( $cpar_old_log ) && filesize( $cpar_log ) < 200 && 0 === strpos( (string) file_get_contents( $cpar_log ), '<?php exit; ?>' ), 'cache parity log: at 1 MB the log rotates into one older file and starts again' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
Perdita_Caching::purge_everything();
$ok( is_file( $cpar_log ), 'cache parity log: a full purge leaves the log in place' );
Perdita_Caching_Tools::log_clear();
$ok( ! is_file( $cpar_log ) && ! is_file( $cpar_old_log ), 'cache parity log: deleting the log removes both files' );
$cpar_dsrc = Perdita_Caching::dropin_source();
$ok( false !== strpos( $cpar_dsrc, basename( $cpar_log ) ), 'cache parity log: while the log is on, the drop-in knows where to write' );
$cpar_set( array() );
$ok( false === strpos( Perdita_Caching::dropin_source(), basename( $cpar_log ) ), 'cache parity log: with the log off, the drop-in holds no log path' );

/* ------------------------------------------------------------------ */
/* 13. Admin screen and WP-CLI.                                         */
/* ------------------------------------------------------------------ */

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching-admin.php';
require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button(), which the screen uses.
$cpar_admin = new Perdita_Caching_Admin( $cpar_cache, perdita_core() );
$cpar_wired = true;
foreach ( array( 'save', 'clear', 'gc', 'preload', 'delete', 'log_clear' ) as $cpar_action ) {
	$cpar_wired = $cpar_wired && false !== has_action( 'admin_post_perdita_caching_' . $cpar_action, array( $cpar_admin, 'handle_' . $cpar_action ) );
}
$ok( $cpar_wired && false !== has_action( 'wp_ajax_perdita_caching_preload_status', array( $cpar_admin, 'ajax_preload_status' ) ), 'cache parity admin: every button has an admin-post handler' );
$ok( false === has_action( 'wp_ajax_nopriv_perdita_caching_preload_status' ), 'cache parity admin: the progress poll is not open to logged-out visitors' );

// Every handler starts with the capability and nonce guard. Each one is
// called for real: logged out, then as an administrator with a wrong nonce,
// and wp_die() must stop it before it touches anything. With the right
// nonce the safe ones run and reach their redirect.
$cpar_die      = static function () {
	return static function () {
		throw new RuntimeException( 'died' );
	};
};
$cpar_redirect = static function () {
	throw new RuntimeException( 'redirected' );
};
add_filter( 'wp_die_handler', $cpar_die );
add_filter( 'wp_die_ajax_handler', $cpar_die );
add_filter( 'wp_redirect', $cpar_redirect );
$cpar_call = static function ( $callback ) {
	ob_start();
	try {
		call_user_func( $callback );
		$how = 'returned';
	} catch ( RuntimeException $e ) {
		$how = $e->getMessage();
	}
	return array( $how, (string) ob_get_clean() );
};
$cpar_set( array( 'debug_log' => true, 'debug_log_until' => time() + 3600 ) );
$cpar_guard_page = $cpar_page( '/parity-guard/' );
$cpar_guard_id   = substr( basename( $cpar_guard_page ), 0, -5 );
Perdita_Caching::log( 'TEST', 'guard' );
$cpar_saved_opt  = get_option( Perdita_Caching::OPTION, 'absent' );
$cpar_pre_state  = get_option( Perdita_Caching_Preload::OPTION, 'absent' );
$cpar_guarded    = true;
$cpar_guard_why  = array();
foreach ( array( 'save', 'clear', 'gc', 'preload', 'delete', 'log_clear' ) as $cpar_action ) {
	foreach ( array( 'logged out', 'wrong nonce' ) as $cpar_who ) {
		wp_set_current_user( 'logged out' === $cpar_who || empty( $cpar_admins ) ? 0 : (int) $cpar_admins[0] );
		$_POST    = array(
			'_wpnonce' => 'logged out' === $cpar_who ? wp_create_nonce( 'perdita_caching_' . $cpar_action ) : 'not-a-nonce',
			'page_id'  => $cpar_guard_id,
			'enabled'  => '1',
		);
		$_REQUEST = $_POST;
		$cpar_res = $cpar_call( array( $cpar_admin, 'handle_' . $cpar_action ) );
		clearstatcache();
		if ( 'died' !== $cpar_res[0] || ! is_file( $cpar_guard_page ) || ! is_file( Perdita_Caching::log_path() ) || get_option( Perdita_Caching::OPTION, 'absent' ) !== $cpar_saved_opt || get_option( Perdita_Caching_Preload::OPTION, 'absent' ) !== $cpar_pre_state ) {
			$cpar_guarded     = false;
			$cpar_guard_why[] = $cpar_action . ' (' . $cpar_who . ': ' . $cpar_res[0] . ')';
		}
	}
}
$ok( $cpar_guarded, 'cache parity admin: every handler stops at wp_die(), before it changes anything, for a visitor without manage_options and for an admin with a wrong nonce' . ( $cpar_guard_why ? ' (' . implode( ', ', $cpar_guard_why ) . ')' : '' ) );
wp_set_current_user( 0 );
add_filter( 'wp_doing_ajax', '__return_true' );
$_REQUEST = array( 'nonce' => wp_create_nonce( 'perdita_caching_preload_status' ) );
$cpar_res = $cpar_call( array( $cpar_admin, 'ajax_preload_status' ) );
$ok( 'died' === $cpar_res[0] && false !== strpos( $cpar_res[1], '"success":false' ), 'cache parity admin: the progress poll answers a visitor without manage_options with an error and nothing else' );
if ( ! empty( $cpar_admins ) ) {
	wp_set_current_user( (int) $cpar_admins[0] );
	$_REQUEST = array( 'nonce' => 'not-a-nonce' );
	$cpar_res = $cpar_call( array( $cpar_admin, 'ajax_preload_status' ) );
	$ok( 'died' === $cpar_res[0] && false !== strpos( $cpar_res[1], '"success":false' ), 'cache parity admin: the progress poll refuses an admin with a wrong nonce' );
	$_REQUEST = array( 'nonce' => wp_create_nonce( 'perdita_caching_preload_status' ) );
	$cpar_res = $cpar_call( array( $cpar_admin, 'ajax_preload_status' ) );
	$ok( 'died' === $cpar_res[0] && false !== strpos( $cpar_res[1], '"success":true' ), 'cache parity admin: control, with the right nonce the progress poll answers' );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	// The right nonce: the handlers that only touch cache files run.
	foreach ( array( 'delete', 'clear', 'gc', 'log_clear' ) as $cpar_action ) {
		$cpar_guard_page = $cpar_page( '/parity-guard/' );
		Perdita_Caching::log( 'TEST', 'guard' );
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'perdita_caching_' . $cpar_action ),
			'page_id'  => $cpar_guard_id,
		);
		$_REQUEST = $_POST;
		$cpar_res = $cpar_call( array( $cpar_admin, 'handle_' . $cpar_action ) );
		clearstatcache();
		$cpar_did = array(
			'delete'    => ! is_file( $cpar_guard_page ),
			'clear'     => ! is_file( $cpar_guard_page ),
			'gc'        => is_file( $cpar_guard_page ),
			'log_clear' => ! is_file( Perdita_Caching::log_path() ),
		);
		$ok( 'redirected' === $cpar_res[0] && $cpar_did[ $cpar_action ], 'cache parity admin: control, with manage_options and the right nonce, ' . $cpar_action . ' does its work and redirects' );
	}
	Perdita_Caching::delete_entry( $cpar_guard_page );
} else {
	remove_filter( 'wp_doing_ajax', '__return_true' );
	echo "SKIP  cache parity admin: no administrator in this install for the nonce checks\n";
}
remove_filter( 'wp_die_handler', $cpar_die );
remove_filter( 'wp_die_ajax_handler', $cpar_die );
remove_filter( 'wp_redirect', $cpar_redirect );
Perdita_Caching_Tools::log_clear();
Perdita_Caching::delete_entry( $cpar_guard_page );
$_POST    = array();
$_REQUEST = array();
wp_set_current_user( 0 );
$cpar_set( array() );

if ( ! empty( $cpar_admins ) ) {
	wp_set_current_user( (int) $cpar_admins[0] );
	$cpar_set(
		array(
			'gzip'            => true,
			'cdn_host'        => 'cdn.example.com',
			'debug_log'       => true,
			'debug_log_until' => time() + 3600,
		)
	);
	$cpar_row = $cpar_page( '/parity-screen/' );
	file_put_contents( $cpar_row . '.json', wp_json_encode( array( array( 'url' => home_url( '/parity-screen/?x="><script>alert(1)</script>' ), 'variant' => 'mobile', 'type' => 'page' ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	ob_start();
	$cpar_admin->render();
	$cpar_screen = (string) ob_get_clean();
	$cpar_fields = true;
	foreach ( array( 'serve_stale', 'gzip', 'mobile_cache', 'cache_feeds', 'preload', 'preload_interval_hours', 'reject_user_agents', 'reject_cookies', 'gc_schedule', 'cdn_enabled', 'cdn_host', 'cdn_dirs', 'cdn_exclude', 'debug_log', 'max_size_mb', 'purge_on_save' ) as $cpar_field ) {
		$cpar_fields = $cpar_fields && false !== strpos( $cpar_screen, 'name="' . $cpar_field . '"' );
	}
	$ok( $cpar_fields, 'cache parity admin: the screen has a field for every new setting' );
	$cpar_forms = true;
	foreach ( array( 'perdita_caching_gc', 'perdita_caching_preload', 'perdita_caching_delete', 'perdita_caching_clear', 'perdita_caching_save' ) as $cpar_action ) {
		$cpar_forms = $cpar_forms && false !== strpos( $cpar_screen, 'name="action" value="' . $cpar_action . '"' );
	}
	$ok( $cpar_forms && substr_count( $cpar_screen, 'name="_wpnonce"' ) >= 5, 'cache parity admin: Delete expired, Preload now, delete-one, Clear and Save each post their own nonce' );
	$ok( false !== strpos( $cpar_screen, 'Cached pages' ) && false !== strpos( $cpar_screen, 'name="cache_s"' ) && false !== strpos( $cpar_screen, '/parity-screen/' ), 'cache parity admin: the cached pages list and its search box are on the screen' );
	$ok( false === strpos( $cpar_screen, '<script>alert(1)</script>' ), 'cache parity admin: a URL in the list is escaped' );
	$ok( 7 <= substr_count( $cpar_screen, 'class="perdita-cache-section"' ), 'cache parity admin: the settings are grouped into titled sections' );
	wp_set_current_user( 0 );
	ob_start();
	$cpar_admin->render();
	$ok( '' === (string) ob_get_clean(), 'cache parity admin: without manage_options the screen prints nothing' );
	Perdita_Caching_Tools::log_clear();
	Perdita_Caching::delete_entry( $cpar_row );
	$cpar_set( array() );
}
foreach ( array( 'save', 'clear', 'gc', 'preload', 'delete', 'log_clear' ) as $cpar_action ) {
	remove_action( 'admin_post_perdita_caching_' . $cpar_action, array( $cpar_admin, 'handle_' . $cpar_action ) );
}
remove_action( 'wp_ajax_perdita_caching_preload_status', array( $cpar_admin, 'ajax_preload_status' ) );
remove_action( 'admin_menu', array( $cpar_admin, 'menu' ) );

// UI copy: no em dash anywhere in the module's strings.
$cpar_dash = array();
foreach ( (array) glob( PERDITA_CORE_DIR . 'inc/modules/caching/*.php' ) as $cpar_f ) {
	if ( false !== strpos( (string) file_get_contents( $cpar_f ), "\xE2\x80\x94" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cpar_dash[] = basename( $cpar_f );
	}
}
$ok( array() === $cpar_dash, 'cache parity: no em dash in the caching module' . ( $cpar_dash ? ' (' . implode( ', ', $cpar_dash ) . ')' : '' ) );

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching-cli.php';
$cpar_cli = true;
foreach ( array( 'status', 'purge', 'preload', 'gc' ) as $cpar_cmd ) {
	$cpar_cli = $cpar_cli && method_exists( 'Perdita_Caching_CLI', $cpar_cmd ) && ( new ReflectionMethod( 'Perdita_Caching_CLI', $cpar_cmd ) )->isPublic();
}
$ok( $cpar_cli, 'cache parity cli: wp perdita cache has status, purge, preload and gc' );
$cpar_main_src = (string) file_get_contents( PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( false !== strpos( $cpar_main_src, "WP_CLI::add_command( 'perdita cache', 'Perdita_Caching_CLI' )" ), 'cache parity cli: the command registers under WP-CLI the way wp perdita indexnow does' );

// Uninstall is checked by running it: see tests/smoke-caching-hardening.php,
// which runs uninstall.php in a child process and puts back what it deletes.

/* ------------------------------------------------------------------ */
/* Restore.                                                             */
/* ------------------------------------------------------------------ */

if ( null !== $cpar_had_log ) {
	// A debug log that was here before this fragment goes back as it was.
	file_put_contents( $cpar_log, $cpar_had_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
$_GET    = $cpar_state['get'];
$_COOKIE = $cpar_state['cookie'];
$_SERVER = $cpar_state['server'];
wp_set_current_user( $cpar_state['user'] );
$cpar_cleanup();
$cpar_after  = $cpar_keepers( $cpar_dir );
$cpar_expect = array_values( array_unique( array_merge( $cpar_before, array( '.htaccess', 'index.php' ) ) ) );
sort( $cpar_expect );
$ok( $cpar_after === $cpar_expect, 'cache parity: the fragment leaves the cache directory as it found it' . ( $cpar_after === $cpar_expect ? '' : ' (' . implode( ', ', array_merge( array_diff( $cpar_after, $cpar_expect ), array_diff( $cpar_expect, $cpar_after ) ) ) . ')' ) );
unset( $GLOBALS['cpar_settings'] );
