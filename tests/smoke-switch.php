<?php
/**
 * Perdita Core smoke tests: Switch to Perdita, the registry, the PHP config
 * reader, the page cache sources, leftovers, deactivate and reactivate, the
 * screen's handler and notices.
 *
 * Loaded by tests/smoke.php. Every option, file, plugin and cron event this
 * touches is put back at the end, and by a shutdown function too if a test
 * dies on the way.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- Switch to Perdita: registry, page cache, leftovers and the screen (tests/smoke-switch.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-admin.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
Perdita_Switch_Target::load( 'caching' );

/* ---------- sandbox: never the live wp-config.php or .htaccess ---------- */

// Every edit this file makes to wp-config.php and .htaccess lands on copies
// in a temp folder, through the plugin's own path filters, so a failed run
// can't break the site the suite runs on.
$pdsw_live_cfg = Perdita_Switch_Leftovers::config_path();
$pdsw_live_ht  = trailingslashit( get_home_path() ) . '.htaccess';
$pdsw_live_was = array(
	$pdsw_live_cfg => is_file( $pdsw_live_cfg ) ? md5_file( $pdsw_live_cfg ) : null,
	$pdsw_live_ht  => is_file( $pdsw_live_ht ) ? md5_file( $pdsw_live_ht ) : null,
);
$pdsw_sandbox  = trailingslashit( get_temp_dir() ) . 'pdsw-files-' . wp_generate_password( 10, false );
wp_mkdir_p( $pdsw_sandbox );
$pdsw_cfg_copy = $pdsw_sandbox . '/wp-config.php';
$pdsw_ht_copy  = $pdsw_sandbox . '/.htaccess';
copy( $pdsw_live_cfg, $pdsw_cfg_copy );
file_put_contents( $pdsw_ht_copy, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$pdsw_cfg_filter = static function () use ( $pdsw_cfg_copy ) {
	return $pdsw_cfg_copy;
};
$pdsw_ht_filter  = static function () use ( $pdsw_ht_copy ) {
	return $pdsw_ht_copy;
};
add_filter( 'perdita_switch_wp_config_path', $pdsw_cfg_filter );
add_filter( 'perdita_switch_htaccess_path', $pdsw_ht_filter );
register_shutdown_function(
	static function () use ( $pdsw_sandbox ) {
		foreach ( (array) glob( $pdsw_sandbox . '/{,.}[!.]*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) || is_link( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		if ( is_dir( $pdsw_sandbox ) ) {
			rmdir( $pdsw_sandbox ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
);

/* ---------- snapshot and restore ---------- */

$pdsw_missing = '__pdsw_missing__';
$pdsw_names   = array(
	'perdita_core',
	'perdita_settings',
	'perdita_caching_settings',
	'perdita_switch_state',
	'perdita_switch_snapshots',
	'active_plugins',
	'cron',
	'ossdl_off_cdn_url',
	'ossdl_off_include_dirs',
	'ossdl_off_exclude',
	'ossdl_cname',
	'WpFastestCache',
	'WpFastestCacheExclude',
	'WpFastestCacheCDN',
	'litespeed.conf.cache',
	'litespeed.conf.cache-ttl_pub',
	'litespeed.conf.cache-mobile',
	'litespeed.conf.cache-exc',
	'litespeed.conf.cache-exc_useragents',
	'litespeed.conf.cache-exc_cookies',
	'litespeed.conf.crawler',
	'litespeed.conf.crawler-crawl_interval',
	'litespeed.conf.cdn',
	'litespeed.conf.cdn-mapping',
	'cache_enabler',
);
$pdsw_saved = array();
foreach ( $pdsw_names as $pdsw_n ) {
	$pdsw_saved[ $pdsw_n ] = get_option( $pdsw_n, $pdsw_missing );
}
$pdsw_wpsc_cfg = WP_CONTENT_DIR . '/wp-cache-config.php';
$pdsw_w3_dir   = WP_CONTENT_DIR . '/w3tc-config';
$pdsw_files    = array(
	$pdsw_wpsc_cfg,
	$pdsw_w3_dir . '/master.php',
	Perdita_Switch_Leftovers::dropin_path(),
	$pdsw_ht_copy,
	$pdsw_cfg_copy,
	Perdita_Caching::cache_dir() . 'early-serve.on',
);
$pdsw_file_was = array();
foreach ( $pdsw_files as $pdsw_f ) {
	$pdsw_file_was[ $pdsw_f ] = is_file( $pdsw_f ) ? (string) file_get_contents( $pdsw_f ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
$pdsw_had_w3_dir = is_dir( $pdsw_w3_dir );
// Backup copies: the folder, and the copies already in it, before the run.
$pdsw_bk_dir     = Perdita_Switch_Backups::dir();
$pdsw_bk_had_dir = is_dir( $pdsw_bk_dir );
$pdsw_bk_before  = wp_list_pluck( Perdita_Switch_Backups::all(), 'name' );
$pdsw_bks        = static function ( $kind ) {
	return (array) glob( Perdita_Switch_Backups::dir() . $kind . '-*.bak' );
};
$pdsw_stub_dirs  = array();
$pdsw_users      = array();
$pdsw_user_was   = get_current_user_id();

$GLOBALS['pdsw_cleanup_done'] = false;
$pdsw_cleanup                 = static function () use ( $pdsw_saved, $pdsw_missing, $pdsw_file_was, $pdsw_had_w3_dir, $pdsw_w3_dir, &$pdsw_stub_dirs, &$pdsw_users, $pdsw_user_was, $pdsw_bk_dir, $pdsw_bk_had_dir, $pdsw_bk_before ) {
	if ( $GLOBALS['pdsw_cleanup_done'] ) {
		return;
	}
	$GLOBALS['pdsw_cleanup_done'] = true;
	delete_transient( Perdita_Switch::JOB );
	foreach ( $pdsw_saved as $name => $value ) {
		if ( $pdsw_missing === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	foreach ( $pdsw_file_was as $path => $body ) {
		if ( null === $body ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		} elseif ( ! is_file( $path ) || (string) file_get_contents( $path ) !== $body ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
	// Backup copies this run made, and the folder when it made that too.
	foreach ( Perdita_Switch_Backups::all() as $backup ) {
		$path = $pdsw_bk_dir . $backup['name'];
		if ( in_array( $backup['name'], $pdsw_bk_before, true ) ) {
			continue;
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) glob( $path . '/*' ) as $inner ) {
				unlink( $inner ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} elseif ( is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	if ( ! $pdsw_bk_had_dir && is_dir( $pdsw_bk_dir ) ) {
		foreach ( array( 'index.php', 'index.html', '.htaccess', 'web.config' ) as $guard ) {
			if ( is_file( $pdsw_bk_dir . $guard ) ) {
				unlink( $pdsw_bk_dir . $guard ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $pdsw_bk_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	if ( ! $pdsw_had_w3_dir && is_dir( $pdsw_w3_dir ) ) {
		rmdir( $pdsw_w3_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	foreach ( $pdsw_stub_dirs as $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $stub ) {
			unlink( $stub ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
	wp_clean_plugins_cache( false );
	foreach ( $pdsw_users as $uid ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $uid );
	}
	wp_set_current_user( $pdsw_user_was );
	Perdita_Switch::sources( true );
};
register_shutdown_function( $pdsw_cleanup );

$pdsw_rows = static function ( array $plan, $target = null, $status = null ) {
	$out = array();
	foreach ( $plan['rows'] as $row ) {
		if ( ( null === $target || $row['target'] === $target ) && ( null === $status || $row['status'] === $status ) ) {
			$out[] = $row;
		}
	}
	return $out;
};
$pdsw_plan = static function ( $id ) {
	Perdita_Switch::sources( true );
	return Perdita_Switch::get( $id )->plan();
};
$pdsw_put  = static function ( $path, $body ) {
	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};

/* =============================================================
 * The registry.
 * ============================================================= */

$pdsw_all = Perdita_Switch::sources( true );
$ok( 18 === count( $pdsw_all ), 'registry: Core registers 18 sources' );
$pdsw_bad_ok = true;
foreach ( $pdsw_all as $pdsw_id => $pdsw_src ) {
	if ( $pdsw_id !== $pdsw_src->id() || ! in_array( $pdsw_src->module(), array( 'caching', 'seo', 'smtp', 'analytics', 'security' ), true ) || ! $pdsw_src->plugin_files() ) {
		$pdsw_bad_ok = false;
	}
}
$ok( $pdsw_bad_ok, 'registry: every source has a matching id, a Core module and plugin files' );

$pdsw_filter = static function ( $list ) {
	$list['not-a-source'] = new stdClass();
	$list['mismatch']     = $list['yoast'];
	$list['Bad Id']       = $list['yoast'];
	unset( $list['loginizer'] );
	return $list;
};
add_filter( 'perdita_switch_sources', $pdsw_filter );
$pdsw_f = Perdita_Switch::sources( true );
remove_filter( 'perdita_switch_sources', $pdsw_filter );
$ok( ! isset( $pdsw_f['not-a-source'], $pdsw_f['mismatch'], $pdsw_f['Bad Id'], $pdsw_f['loginizer'] ) && isset( $pdsw_f['yoast'] ), 'registry: the filter can remove a source, and anything that isn\'t a source under its own id is dropped' );

/* =============================================================
 * The PHP config reader never runs the file.
 * ============================================================= */

$pdsw_canary = get_temp_dir() . 'pdsw-canary-' . wp_generate_password( 8, false );
$pdsw_cfg    = Perdita_Switch_PHP_Config::parse(
	implode(
		"\n",
		array(
			'<?php',
			'$cache_enabled = true;',
			'$cache_max_time = 7200; // two hours',
			'$cache_rejected_uri = array ( 0 => \'wp-.*\\\\.php\', 1 => \'index\\\\.php\', 2 => \'/cart/\', );',
			'$wp_cache_pages[ "feed" ] = 0;',
			'$neg = -5;',
			'$pi = 3.5;',
			'$short = [ "a", [ "b" => null ] ];',
			'$called = system( "touch ' . $pdsw_canary . '" );',
			'$tick = `touch ' . $pdsw_canary . '`;',
			'include "' . $pdsw_canary . '";',
			'$interp = "x{$cache_enabled}";',
			'$const = ABSPATH;',
			'$obj = new ArrayObject( array() );',
			'$concat = "a" . "b";',
			'if ( true ) { $cache_enabled = false; }',
			'$after = "kept";',
			'$heredoc = <' . '<<EOT',
			'text',
			'EOT;',
			'$last = 1;',
		)
	)
);
$ok( true === $pdsw_cfg['cache_enabled'] && 7200 === $pdsw_cfg['cache_max_time'] && array( 'wp-.*\\.php', 'index\\.php', '/cart/' ) === $pdsw_cfg['cache_rejected_uri'], 'php config: scalars and arrays read as literals, escapes decoded' );
$ok( array( 'feed' => 0 ) === $pdsw_cfg['wp_cache_pages'] && -5 === $pdsw_cfg['neg'] && 3.5 === $pdsw_cfg['pi'] && array( 'a', array( 'b' => null ) ) === $pdsw_cfg['short'], 'php config: keyed assignment, negatives, floats and short arrays' );
$ok( ! array_intersect( array( 'called', 'tick', 'interp', 'const', 'obj', 'concat', 'heredoc' ), array_keys( $pdsw_cfg ) ), 'php config: calls, backticks, interpolation, constants, objects, concatenation and heredocs are skipped' );
$ok( 'kept' === $pdsw_cfg['after'] && 1 === $pdsw_cfg['last'] && true === $pdsw_cfg['cache_enabled'], 'php config: an assignment inside an if block doesn\'t count, and reading carries on after it' );
$ok( ! file_exists( $pdsw_canary ), 'php config: nothing in the file ran' );
$ok( array() === Perdita_Switch_PHP_Config::parse( '<?php $x = 1;' . str_repeat( ' ', Perdita_Switch_PHP_Config::MAX_BYTES ) ) && array() === Perdita_Switch_PHP_Config::parse_file( WP_CONTENT_DIR . '/no-such-file.php' ), 'php config: an oversize source or a missing file reads as empty' );
$pdsw_deep = '<?php $d = ' . str_repeat( 'array(', 12 ) . '1' . str_repeat( ')', 12 ) . '; $e = 2;';
$pdsw_dp   = Perdita_Switch_PHP_Config::parse( $pdsw_deep );
$ok( ! isset( $pdsw_dp['d'] ) && 2 === $pdsw_dp['e'], 'php config: nesting past the limit is skipped' );

/* =============================================================
 * WP Super Cache.
 * ============================================================= */

update_option( 'ossdl_off_cdn_url', 'https://cdn.example.com' );
update_option( 'ossdl_off_include_dirs', 'wp-content,wp-includes' );
update_option( 'ossdl_off_exclude', '.php,.js' );
update_option( 'ossdl_cname', '' );
$pdsw_put(
	$pdsw_wpsc_cfg,
	implode(
		"\n",
		array(
			'<?php',
			'$cache_enabled = true;',
			'$cache_max_time = 5400;',
			'$cache_schedule_type = \'interval\';',
			'$cache_time_interval = 600;',
			'$cache_rejected_uri = array ( 0 => \'wp-.*\\\\.php\', 1 => \'index\\\\.php\', 2 => \'/cart/\', 3 => \'\\/checkout\\/\', 4 => \'shop.*\', 5 => \'my-account\', );',
			'$cache_rejected_user_agent = array ( 0 => \'bot\', 1 => \'"><img src=x onerror=alert(1)>\', );',
			'$wpsc_rejected_cookies = array ( 0 => \'wordpress_logged_in\', 1 => \'wp_woocommerce_.*\', );',
			'$wp_cache_mobile_enabled = 1;',
			'$cache_compression = 1;',
			'$wp_cache_preload_on = 1;',
			'$wp_cache_preload_interval = 90;',
			'$wp_cache_pages[ "feed" ] = 0;',
			'$wp_cache_not_logged_in = 0;',
			'$ossdlcdn = 1;',
			'$cache_path = WP_CONTENT_DIR . \'/cache/\';',
		)
	)
);
$pdsw_p     = $pdsw_plan( 'wp-super-cache' );
$pdsw_patch = $pdsw_p['patch'];
$ok( true === $pdsw_patch['enabled'] && true === $pdsw_patch['early_serve'] && 2 === $pdsw_patch['ttl_hours'], 'wpsc: on, early serving, and 5,400 seconds rounds to 2 hours' );
$ok( 'hourly' === $pdsw_patch['gc_schedule'], 'wpsc: a 10 minute sweep maps to hourly' );
$ok( array( '/cart/', '/checkout/' ) === $pdsw_patch['exclude_paths'], 'wpsc: plain paths import, with escaped slashes unescaped and WordPress\'s defaults left out' );
$ok( 2 === count( $pdsw_rows( $pdsw_p, 'Never cache these paths', 'skip' ) ), 'wpsc: a pattern and a path without a leading slash show as refused' );
$ok( array( 'wordpress_logged_in' ) === $pdsw_patch['reject_cookies'] && 1 === count( $pdsw_rows( $pdsw_p, 'Skip the cache for these cookies', 'skip' ) ), 'wpsc: a plain cookie name imports and a pattern doesn\'t' );
$ok( true === $pdsw_patch['mobile_cache'] && true === $pdsw_patch['gzip'] && true === $pdsw_patch['preload'] && 2 === $pdsw_patch['preload_interval_hours'] && true === $pdsw_patch['cache_feeds'], 'wpsc: phones, gzip, preload every 90 minutes as 2 hours, and feeds' );
$ok( true === $pdsw_patch['cdn_enabled'] && 'cdn.example.com' === $pdsw_patch['cdn_host'] && array( 'wp-content', 'wp-includes' ) === $pdsw_patch['cdn_dirs'] && array( '.php', '.js' ) === $pdsw_patch['cdn_exclude'], 'wpsc: the CDN settings import from the ossdl options' );
$ok( (bool) $pdsw_rows( $pdsw_p, '', 'none' ), 'wpsc: caching for known users shows as no match' );
$ok( array( 'bot' ) === $pdsw_patch['reject_user_agents'] && 1 === count( $pdsw_rows( $pdsw_p, 'Skip the cache for these user agents', 'skip' ) ), 'wpsc: a user agent the module would store altered (markup stripped) is refused, not imported changed' );
$ok( ! isset( $pdsw_patch['cache_path'] ), 'wpsc: a value built from a constant never reaches the plan' );

$pdsw_caching_before = get_option( 'perdita_caching_settings', $pdsw_missing );
$pdsw_module_before  = Perdita_Core::module_state( 'caching' );
$pdsw_res            = Perdita_Switch::import( 'wp-super-cache', array( 'enable_module' => true ) );
$pdsw_now            = Perdita_Caching::settings();
$ok( $pdsw_res['ok'] && true === $pdsw_now['enabled'] && 2 === $pdsw_now['ttl_hours'] && array( '/cart/', '/checkout/' ) === $pdsw_now['exclude_paths'], 'wpsc: import saves through the caching module\'s own sanitize' );
$ok( true === Perdita_Core::module_state( 'caching' ), 'wpsc: "turn on Caching too" turns the module on' );
$ok( is_file( $pdsw_wpsc_cfg ) && false !== strpos( (string) file_get_contents( $pdsw_wpsc_cfg ), '$cache_max_time = 5400;' ), 'wpsc: its config file is untouched' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$pdsw_snap = Perdita_Switch::snapshot( 'wp-super-cache' );
update_option( 'perdita_caching_settings', array_merge( $pdsw_now, array( 'ttl_hours' => 33 ) ) );
Perdita_Switch::import( 'wp-super-cache' );
$ok( Perdita_Switch::snapshot( 'wp-super-cache' ) === $pdsw_snap, 'wpsc: importing again keeps the first snapshot' );
$pdsw_res = Perdita_Switch::undo( 'wp-super-cache' );
$ok( $pdsw_res['ok'] && get_option( 'perdita_caching_settings', $pdsw_missing ) === $pdsw_caching_before, 'wpsc: undo puts the caching settings back to before the first import' );
$ok( Perdita_Core::module_state( 'caching' ) === $pdsw_module_before, 'wpsc: undo puts the module back the way it was' );

/* =============================================================
 * W3 Total Cache, WP Fastest Cache, LiteSpeed Cache, Cache Enabler.
 * ============================================================= */

wp_mkdir_p( $pdsw_w3_dir );
$pdsw_put(
	$pdsw_w3_dir . '/master.php',
	'<?php exit; ?>' . wp_json_encode(
		array(
			'pgcache.enabled'               => true,
			'pgcache.lifetime'              => 3600,
			'pgcache.file.gc'               => 86400,
			'pgcache.reject.uri'            => array( 'wp-.*\\.php', 'index\\.php', '/members/' ),
			'pgcache.reject.ua'             => array( 'curl' ),
			'pgcache.reject.cookie'         => array( 'wptouch_switch_toggle' ),
			'pgcache.cache.feed'            => false,
			'pgcache.prime.enabled'         => true,
			'pgcache.prime.interval'        => 900,
			'browsercache.enabled'          => true,
			'browsercache.html.compression' => true,
			'mobile.enabled'                => true,
			'mobile.rgroups'                => array( 'phones' => array( 'enabled' => true ) ),
			'minify.enabled'                => true,
			'cdn.enabled'                   => true,
			'cdn.engine'                    => 'mirror',
			'cdn.mirror.domain'             => array( 'cdn1.example.net', 'cdn2.example.net' ),
			'cdn.reject.files'              => array( '{uploads_dir}/wpcf7_captcha/*' ),
		)
	)
);
$pdsw_p     = $pdsw_plan( 'w3-total-cache' );
$pdsw_patch = $pdsw_p['patch'];
$ok( true === $pdsw_patch['enabled'] && 1 === $pdsw_patch['ttl_hours'] && 'daily' === $pdsw_patch['gc_schedule'] && array( '/members/' ) === $pdsw_patch['exclude_paths'], 'w3tc: the JSON after the exit line reads, with lifetime, sweep and exclusions' );
$ok( true === $pdsw_patch['gzip'] && true === $pdsw_patch['mobile_cache'] && true === $pdsw_patch['preload'] && 1 === $pdsw_patch['preload_interval_hours'] && false === $pdsw_patch['cache_feeds'], 'w3tc: gzip, phones, priming and feeds' );
$ok( 'cdn1.example.net' === $pdsw_patch['cdn_host'] && (bool) $pdsw_rows( $pdsw_p, '', 'none' ), 'w3tc: the first mirror host imports and the second shows as no match' );

update_option( 'WpFastestCache', wp_json_encode( array( 'wpFastestCacheStatus' => 'on', 'wpFastestCacheMobileTheme' => 'on', 'wpFastestCachePreload' => 'on', 'wpFastestCacheLBC' => 'on' ) ) );
update_option(
	'WpFastestCacheExclude',
	wp_json_encode(
		array(
			array( 'prefix' => 'startwith', 'content' => 'members', 'type' => 'page' ),
			array( 'prefix' => 'exact', 'content' => 'about', 'type' => 'page' ),
			array( 'prefix' => 'contain', 'content' => 'Lighthouse', 'type' => 'useragent' ),
			array( 'prefix' => 'contain', 'content' => 'cart_hash', 'type' => 'cookie' ),
			array( 'prefix' => 'homepage', 'content' => 'homepage', 'type' => 'page' ),
		)
	)
);
update_option( 'WpFastestCacheCDN', wp_json_encode( array( array( 'cdnurl' => 'cdn.fastest.example', 'status' => 'true' ) ) ) );
$pdsw_p     = $pdsw_plan( 'wp-fastest-cache' );
$pdsw_patch = $pdsw_p['patch'];
$ok( true === $pdsw_patch['enabled'] && true === $pdsw_patch['mobile_cache'] && true === $pdsw_patch['preload'], 'wp fastest cache: status, mobile theme and preload from the JSON option' );
$ok( array( '/members' ) === $pdsw_patch['exclude_paths'] && array( 'Lighthouse' ) === $pdsw_patch['reject_user_agents'] && array( 'cart_hash' ) === $pdsw_patch['reject_cookies'], 'wp fastest cache: "starts with" pages, user agents and cookies import' );
$ok( 'cdn.fastest.example' === $pdsw_patch['cdn_host'] && (bool) $pdsw_rows( $pdsw_p, 'Never cache these paths', 'skip' ), 'wp fastest cache: the CDN imports and an exact-address rule shows as refused' );

update_option( 'litespeed.conf.cache', 1 );
update_option( 'litespeed.conf.cache-ttl_pub', 604800 );
update_option( 'litespeed.conf.cache-mobile', 0 );
update_option( 'litespeed.conf.cache-exc', wp_json_encode( array( '/basket/', '^/exact$' ) ) );
update_option( 'litespeed.conf.cache-exc_useragents', wp_json_encode( array( 'Pingdom' ) ) );
update_option( 'litespeed.conf.cache-exc_cookies', wp_json_encode( array( 'nocache_cookie' ) ) );
update_option( 'litespeed.conf.crawler', 1 );
update_option( 'litespeed.conf.crawler-crawl_interval', 302400 );
update_option( 'litespeed.conf.cdn', 1 );
update_option( 'litespeed.conf.cdn-mapping', wp_json_encode( array( array( 'url' => 'https://ls.cdn.example/', 'inc_img' => 1 ) ) ) );
$pdsw_p     = $pdsw_plan( 'litespeed-cache' );
$pdsw_patch = $pdsw_p['patch'];
$ok( true === $pdsw_patch['enabled'] && 168 === $pdsw_patch['ttl_hours'] && false === $pdsw_patch['mobile_cache'], 'litespeed: on, a week as 168 hours, and no phone copy' );
$ok( array( '/basket/', '/exact' ) === $pdsw_patch['exclude_paths'] && array( 'Pingdom' ) === $pdsw_patch['reject_user_agents'] && array( 'nocache_cookie' ) === $pdsw_patch['reject_cookies'], 'litespeed: URIs, user agents and cookies from JSON lists' );
$ok( 84 === $pdsw_patch['preload_interval_hours'] && 'ls.cdn.example' === $pdsw_patch['cdn_host'], 'litespeed: the crawler interval and the CDN mapping' );

update_option(
	'cache_enabler',
	array(
		'cache_expires'       => 1,
		'cache_expiry_time'   => 48,
		'mobile_cache'        => 1,
		'compress_cache'      => 0,
		'excluded_page_paths' => '/^(\/basket\/|\/checkout\/)$/',
		'excluded_cookies'    => '/^(?!wordpress_test_cookie)(comment_author|wp-postpass|wordpress_logged_in)/',
	)
);
$pdsw_p     = $pdsw_plan( 'cache-enabler' );
$pdsw_patch = $pdsw_p['patch'];
$ok( 48 === $pdsw_patch['ttl_hours'] && true === $pdsw_patch['mobile_cache'] && false === $pdsw_patch['gzip'], 'cache enabler: expiry, phones and compression' );
$ok( array( '/basket/', '/checkout/' ) === $pdsw_patch['exclude_paths'], 'cache enabler: a delimited regex of plain alternatives becomes plain paths' );
$ok( ! isset( $pdsw_patch['reject_cookies'] ) && (bool) $pdsw_rows( $pdsw_p, 'Skip the cache for these cookies', 'skip' ), 'cache enabler: a regex with a lookahead is refused whole' );

/* =============================================================
 * Deactivate, reactivate and leftovers, with a stand-in plugin.
 * ============================================================= */

$pdsw_stub_dir    = WP_PLUGIN_DIR . '/wp-super-cache';
$pdsw_stub_exists = is_dir( $pdsw_stub_dir );
if ( ! $pdsw_stub_exists ) {
	wp_mkdir_p( $pdsw_stub_dir );
	$pdsw_stub_dirs[] = $pdsw_stub_dir;
	$pdsw_put( $pdsw_stub_dir . '/wp-cache.php', "<?php\n/**\n * Plugin Name: WP Super Cache (Perdita test stand-in)\n */\n" );
	wp_clean_plugins_cache( false );
}
if ( $pdsw_stub_exists ) {
	echo "SKIP  deactivate and leftovers (a real wp-super-cache folder is installed)\n";
} else {
	activate_plugin( 'wp-super-cache/wp-cache.php' );
	$pdsw_det = Perdita_Switch::get( 'wp-super-cache' )->detect();
	$ok( $pdsw_det['installed'] && $pdsw_det['active'] && $pdsw_det['has_settings'], 'deactivate: the stand-in shows as installed, active and with settings' );
	$ok( true !== Perdita_Switch::deactivate_allowed( 'wp-super-cache' ), 'deactivate: not offered before an import or a skip' );
	$ok( 0 === count( Perdita_Switch_Leftovers::find( Perdita_Switch::get( 'wp-super-cache' ), array( 'WP SUPER CACHE' ), array( 'WPSuperCache' ) ) ), 'leftovers: nothing is reported while the plugin is active' );

	// Notices: one line while it's active with settings, gone once dismissed.
	$pdsw_admin = wp_insert_user(
		array(
			'user_login' => 'pdsw_admin_' . wp_generate_password( 6, false ),
			'user_email' => 'pdsw-admin-' . wp_generate_password( 6, false ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => 'administrator',
		)
	);
	$pdsw_sub   = wp_insert_user(
		array(
			'user_login' => 'pdsw_sub_' . wp_generate_password( 6, false ),
			'user_email' => 'pdsw-sub-' . wp_generate_password( 6, false ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$pdsw_users = array( $pdsw_admin, $pdsw_sub );
	$ok( isset( Perdita_Switch::nudges( $pdsw_admin )['wp-super-cache'] ), 'notices: an active plugin with settings gets a notice' );
	Perdita_Switch::dismiss( $pdsw_admin, 'wp-super-cache' );
	$ok( ! isset( Perdita_Switch::nudges( $pdsw_admin )['wp-super-cache'] ) && isset( Perdita_Switch::nudges( $pdsw_sub )['wp-super-cache'] ), 'notices: dismissing hides it for that person only, for good' );

	// The handler: subscriber, bad nonce, then the real thing.
	$pdsw_admin_ui = new Perdita_Switch_Admin();
	$pdsw_die      = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) );
		};
	};
	$pdsw_redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	add_filter( 'wp_die_handler', $pdsw_die );
	add_filter( 'wp_redirect', $pdsw_redirect, 1 );
	$pdsw_call = static function ( array $req ) use ( $pdsw_admin_ui ) {
		$_POST    = $req;
		$_REQUEST = $req;
		try {
			$pdsw_admin_ui->handle();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'returned';
	};
	wp_set_current_user( $pdsw_sub );
	$pdsw_out = $pdsw_call( array( 'do' => 'skip', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'skip', 'wp-super-cache' ) ) ) );
	$ok( 0 === strpos( $pdsw_out, 'die:403' ) && ! Perdita_Switch::state( 'wp-super-cache' )['skipped'], 'handler: a subscriber is refused, even with a nonce of their own' );
	wp_set_current_user( $pdsw_admin );
	$pdsw_out = $pdsw_call( array( 'do' => 'skip', 'source' => 'wp-super-cache', '_wpnonce' => 'bad' ) );
	$ok( 0 === strpos( $pdsw_out, 'die:' ) && ! Perdita_Switch::state( 'wp-super-cache' )['skipped'], 'handler: a bad nonce is refused' );
	$pdsw_out = $pdsw_call( array( 'do' => 'skip', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'import', 'wp-super-cache' ) ) ) );
	$ok( 0 === strpos( $pdsw_out, 'die:' ), 'handler: a nonce for another action is refused' );

	// One notice for every plugin waiting, and one Dismiss for all of them.
	set_current_screen( 'plugins' );
	delete_user_meta( $pdsw_admin, Perdita_Switch::DISMISSED );
	ob_start();
	$pdsw_admin_ui->notices();
	$pdsw_note = (string) ob_get_clean();
	$ok( 1 === substr_count( $pdsw_note, 'class="notice' ) && false !== strpos( $pdsw_note, 'WP Super Cache' ) && false === strpos( $pdsw_note, 'Perdita Perdita' ), 'notices: one notice names every plugin waiting' );
	$pdsw_out = $pdsw_call( array( 'do' => 'dismiss', 'source' => 'all', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'dismiss', 'all' ) ) ) );
	$ok( 0 === strpos( $pdsw_out, 'redirect:' ) && ! Perdita_Switch::nudges( $pdsw_admin ), 'notices: its Dismiss hides all of them for that person' );
	$pdsw_out = $pdsw_call( array( 'do' => 'dismiss', 'source' => 'all', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'dismiss', 'wp-super-cache' ) ) ) );
	$ok( 0 === strpos( $pdsw_out, 'die:' ), 'notices: dismissing all needs its own nonce' );
	set_current_screen( 'front' );
	$pdsw_out = $pdsw_call( array( 'do' => 'skip', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'skip', 'wp-super-cache' ) ) ) );
	$ok( 0 === strpos( $pdsw_out, 'redirect:' ) && Perdita_Switch::state( 'wp-super-cache' )['skipped'], 'handler: an admin with the right nonce skips the import' );
	$ok( true === Perdita_Switch::deactivate_allowed( 'wp-super-cache' ), 'deactivate: offered after the skip' );
	$pdsw_out = $pdsw_call( array( 'do' => 'deactivate', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'deactivate', 'wp-super-cache' ) ) ) );
	$ok( is_plugin_active( 'wp-super-cache/wp-cache.php' ) && false !== strpos( $pdsw_out, 'perdita_err' ), 'handler: deactivate without the confirmation box does nothing' );
	$pdsw_out = $pdsw_call( array( 'do' => 'deactivate', 'confirm' => '1', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'deactivate', 'wp-super-cache' ) ) ) );
	$ok( ! is_plugin_active( 'wp-super-cache/wp-cache.php' ) && is_file( $pdsw_stub_dir . '/wp-cache.php' ) && is_file( $pdsw_wpsc_cfg ), 'handler: confirmed deactivate deactivates, and deletes nothing' );

	// WP Super Cache writes $cache_enabled = false on deactivation, so an
	// inactive copy that says "off" must not switch Perdita's cache off.
	$pdsw_cfg_now = (string) file_get_contents( $pdsw_wpsc_cfg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_put( $pdsw_wpsc_cfg, preg_replace( '/^\$cache_enabled = [^;]*;/m', '$cache_enabled = false;', $pdsw_cfg_now ) );
	$pdsw_p = Perdita_Switch::get( 'wp-super-cache' )->plan();
	$ok( ! array_key_exists( 'enabled', $pdsw_p['patch'] ) && ! array_key_exists( 'early_serve', $pdsw_p['patch'] ) && (bool) $pdsw_rows( $pdsw_p, 'Page cache', 'skip' ), 'wpsc: "off" on an inactive copy leaves Perdita\'s cache switch alone' );
	$pdsw_put( $pdsw_wpsc_cfg, $pdsw_cfg_now );

	// Rendering: the screen escapes what the other plugin stored.
	ob_start();
	$pdsw_admin_ui->page();
	$pdsw_html = (string) ob_get_clean();
	$ok( false !== strpos( $pdsw_html, 'id="pds-wp-super-cache"' ) && false !== strpos( $pdsw_html, 'Reactivate WP Super Cache' ), 'screen: the card renders and offers Reactivate' );
	$ok( false === strpos( $pdsw_html, 'id="_wpnonce"' ) && false === strpos( $pdsw_html, 'id="submit"' ), 'screen: no repeated id attributes across its many forms' );
	$ok( false === strpos( $pdsw_html, '<img src=x' ) && false === strpos( $pdsw_html, 'onerror=alert(1)>' ), 'screen: a hostile value from the other plugin is never printed as markup' );

	// Leftovers after deactivation.
	$pdsw_put( Perdita_Switch_Leftovers::dropin_path(), "<?php\n# WP SUPER CACHE 1.2\nreturn;\n" );
	$pdsw_ht = Perdita_Switch_Leftovers::htaccess_path();
	$pdsw_put( $pdsw_ht, "# BEGIN WPSuperCache\nRewriteEngine On\n# END WPSuperCache\n# BEGIN WordPress\nkeep me\n# END WordPress\n" );
	$pdsw_left = Perdita_Switch::get( 'wp-super-cache' )->leftovers();
	$pdsw_ids  = wp_list_pluck( $pdsw_left, 'id' );
	$ok( in_array( 'dropin', $pdsw_ids, true ) && in_array( 'htaccess:WPSuperCache', $pdsw_ids, true ), 'leftovers: its advanced-cache.php and .htaccess block show once it\'s inactive' );
	$pdsw_conf = wp_list_pluck( $pdsw_left, 'confirm', 'id' );
	$ok( isset( $pdsw_conf['dropin'], $pdsw_conf['htaccess:WPSuperCache'] ) && false !== strpos( $pdsw_conf['dropin'], Perdita_Switch_Backups::display_dir() ) && false !== strpos( $pdsw_conf['htaccess:WPSuperCache'], Perdita_Switch_Backups::display_dir() ) && false === strpos( implode( ' ', $pdsw_conf ), 'beside' ), 'leftovers: each confirmation box names the backup folder, never "beside the file"' );
	$pdsw_d_on  = Perdita_Switch_Leftovers::dropin_detail( 'WP Super Cache', true );
	$pdsw_d_off = Perdita_Switch_Leftovers::dropin_detail( 'WP Super Cache', false );
	$ok( false !== strpos( $pdsw_d_on, 'WP_CACHE is on' ) && false !== strpos( $pdsw_d_on, 'on every request' ) && false !== strpos( $pdsw_d_off, 'WP_CACHE is off' ) && false !== strpos( $pdsw_d_off, 'skips the file' ) && $pdsw_d_on !== $pdsw_d_off, 'leftovers: the drop-in text says what WordPress does with WP_CACHE on and with it off' );
	$pdsw_bk_n = count( $pdsw_bks( 'htaccess' ) );
	$pdsw_fix  = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'htaccess:WPSuperCache' );
	$pdsw_hb   = (string) file_get_contents( $pdsw_ht ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_hbk  = $pdsw_bks( 'htaccess' );
	$ok( $pdsw_fix['ok'] && false === strpos( $pdsw_hb, 'WPSuperCache' ) && false !== strpos( $pdsw_hb, "# BEGIN WordPress\nkeep me" ) && count( $pdsw_hbk ) === $pdsw_bk_n + 1, 'leftovers: the block goes, the rest stays, and a backup copy lands in the backup folder' );
	$ok( ! glob( dirname( $pdsw_ht ) . '/.htaccess.perdita-*' ) && ! glob( dirname( $pdsw_ht ) . '/.htaccess*.bak' ) && ! glob( dirname( $pdsw_ht ) . '/.*.tmp' ), 'leftovers: no backup copy or temp file sits beside .htaccess' );
	$pdsw_hbk_last = (string) end( $pdsw_hbk );
	$ok( '' !== $pdsw_hbk_last && false !== strpos( (string) file_get_contents( $pdsw_hbk_last ), '# BEGIN WPSuperCache' ) && '0600' === substr( sprintf( '%o', fileperms( $pdsw_hbk_last ) ), -4 ) && false !== strpos( $pdsw_fix['message'], basename( $pdsw_hbk_last ) ), 'leftovers: the .htaccess copy is the old file, 0600, and the message names it' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( '' !== $pdsw_hbk_last && false === strpos( (string) file_get_contents( $pdsw_hbk_last ), 'keep me' ) && false !== strpos( basename( $pdsw_hbk_last ), 'htaccess-block-' ), 'leftovers: the backup holds only the removed block, never the rest of .htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_fix = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'htaccess:WordPress' );
	$ok( ! $pdsw_fix['ok'] && false !== strpos( (string) file_get_contents( $pdsw_ht ), '# BEGIN WordPress' ), 'leftovers: a block the plugin doesn\'t own is refused' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_fix = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'dropin' );
	$ok( $pdsw_fix['ok'] && ( ! is_file( Perdita_Switch_Leftovers::dropin_path() ) || false === strpos( (string) file_get_contents( Perdita_Switch_Leftovers::dropin_path() ), 'WP SUPER CACHE' ) ), 'leftovers: its drop-in is removed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_dbk = $pdsw_bks( 'advanced-cache' );
	$ok( $pdsw_dbk && false !== strpos( (string) file_get_contents( (string) end( $pdsw_dbk ) ), 'WP SUPER CACHE' ), 'leftovers: the removed drop-in has a backup copy in the backup folder' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_put( Perdita_Switch_Leftovers::dropin_path(), "<?php\n// " . Perdita_Caching::DROPIN_MARKER . "\n// WP SUPER CACHE mentioned in a comment\n" );
	$ok( ! Perdita_Switch_Leftovers::dropin_is( array( 'WP SUPER CACHE' ) ), 'leftovers: Perdita\'s own drop-in never counts as another plugin\'s' );

	// WP_CACHE: the line goes in only with a backup beside wp-config.php.
	update_option( 'perdita_caching_settings', array_merge( Perdita_Caching::settings(), array( 'enabled' => true, 'early_serve' => true ) ) );
	$pdsw_item = Perdita_Switch_Leftovers::wp_cache_item();
	$pdsw_cfgp = Perdita_Switch_Leftovers::config_path();
	if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
		echo "SKIP  WP_CACHE line (WP_CACHE is on here)\n";
	} elseif ( ! $pdsw_item || empty( $pdsw_item['removable'] ) ) {
		$ok( $pdsw_item && "define( 'WP_CACHE', true );" === $pdsw_item['line'], 'leftovers: an unwritable wp-config.php gets the one line to add' );
	} else {
		$pdsw_fix  = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'wp_cache' );
		$pdsw_body = (string) file_get_contents( $pdsw_cfgp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pdsw_bk   = $pdsw_bks( 'wp-config' );
		$pdsw_note = (array) glob( Perdita_Switch_Backups::dir() . 'wp-config-line-*.bak' );
		$pdsw_nb   = $pdsw_note ? (string) file_get_contents( (string) end( $pdsw_note ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ok( $pdsw_fix['ok'] && false !== strpos( $pdsw_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ) && $pdsw_note && false !== strpos( $pdsw_fix['message'], 'Added by Perdita' ), 'leftovers: WP_CACHE added, and the message says which line to delete to undo it' );
		$ok( ! preg_grep( '/wp-config-\d{8}-/', $pdsw_bk ) && false !== strpos( $pdsw_nb, Perdita_Switch_Leftovers::WP_CACHE_LINE ) && false === strpos( $pdsw_nb, 'DB_PASSWORD' ) && false === strpos( $pdsw_nb, 'AUTH_KEY' ) && false === strpos( $pdsw_nb, DB_NAME ) && '0600' === substr( sprintf( '%o', fileperms( (string) end( $pdsw_note ) ) ), -4 ), 'leftovers: no copy of wp-config.php lands under uploads, only a 0600 note with the one line' );
		$ok( ! glob( dirname( $pdsw_cfgp ) . '/wp-config-perdita-backup-*' ) && ! glob( dirname( $pdsw_cfgp ) . '/*.bak' ) && ! glob( dirname( $pdsw_cfgp ) . '/.*.tmp' ), 'leftovers: no backup copy or temp file sits beside wp-config.php' );
		$ok( $pdsw_body === substr( $pdsw_file_was[ $pdsw_cfgp ], 0, strpos( $pdsw_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ) ) . Perdita_Switch_Leftovers::WP_CACHE_LINE . "\n" . substr( $pdsw_file_was[ $pdsw_cfgp ], strpos( $pdsw_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ) ), 'leftovers: the edit adds exactly the one line and keeps every other byte' );
		$ok( strpos( $pdsw_body, Perdita_Switch_Leftovers::WP_CACHE_LINE ) < strpos( $pdsw_body, 'wp-settings.php' ), 'leftovers: the line sits above wp-settings.php' );
		$pdsw_put( $pdsw_cfgp, $pdsw_file_was[ $pdsw_cfgp ] );
	}
	$pdsw_php = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
	if ( $pdsw_php && function_exists( 'shell_exec' ) ) {
		$pdsw_tmp = tempnam( get_temp_dir(), 'pdsw' );
		$pdsw_src = array(
			'<?php',
			"define( 'WP_CACHE', true );",
			'$_SERVER["HTTP_HOST"] = "localhost";',
			'require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';',
			"require_once PERDITA_CORE_DIR . 'inc/switch/switch.php';",
			"\$r = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'wp_cache' );",
			"echo ( \$r['ok'] ? 'OK:' : 'NO:' ) . \$r['message'];",
		);
		file_put_contents( $pdsw_tmp, implode( "\n", $pdsw_src ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$pdsw_cfg_before = (string) file_get_contents( $pdsw_cfgp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pdsw_bk_was     = count( $pdsw_bks( 'wp-config' ) );
		$pdsw_child      = (string) shell_exec( escapeshellarg( $pdsw_php ) . ' ' . escapeshellarg( $pdsw_tmp ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec
		unlink( $pdsw_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$ok( false !== strpos( $pdsw_child, 'OK:WP_CACHE is already on' ) && (string) file_get_contents( $pdsw_cfgp ) === $pdsw_cfg_before && count( $pdsw_bks( 'wp-config' ) ) === $pdsw_bk_was, 'leftovers: with WP_CACHE already on, Add the line says so and leaves wp-config.php alone' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	} else {
		echo "SKIP  WP_CACHE already on (no CLI PHP)\n";
	}

	// Backup copies: the folder, the names, and the screen's list.
	$pdsw_bdir = Perdita_Switch_Backups::dir();
	$ok( 0 === strpos( wp_normalize_path( $pdsw_bdir ), wp_normalize_path( trailingslashit( wp_get_upload_dir()['basedir'] ) ) ) && 'perdita-switch-backups/' === substr( $pdsw_bdir, -23 ), 'backups: the folder is perdita-switch-backups/ under the uploads base' );
	$ok( is_file( $pdsw_bdir . 'index.php' ) && is_file( $pdsw_bdir . 'web.config' ) && false !== strpos( (string) file_get_contents( $pdsw_bdir . '.htaccess' ), 'Require all denied' ) && false !== strpos( (string) file_get_contents( $pdsw_bdir . '.htaccess' ), 'Deny from all' ), 'backups: the folder carries an index.php, a deny-all .htaccess for Apache 2.2 and 2.4, and a web.config' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$pdsw_all = Perdita_Switch_Backups::all();
	$pdsw_bad = array();
	foreach ( $pdsw_all as $pdsw_b ) {
		if ( false !== stripos( $pdsw_b['name'], '.php' ) || ( ! $pdsw_b['dir'] && '.bak' !== substr( $pdsw_b['name'], -4 ) ) || ! preg_match( '/-[a-f0-9]{20}(\.bak)?$/', $pdsw_b['name'] ) ) {
			$pdsw_bad[] = $pdsw_b['name'];
		}
	}
	$ok( count( $pdsw_all ) >= 3 && ! $pdsw_bad, 'backups: every copy ends in .bak with a 20-character token and never carries .php (' . implode( ', ', $pdsw_bad ) . ')' );
	$ok( Perdita_Switch_Backups::name( 'wp-config' ) !== Perdita_Switch_Backups::name( 'wp-config' ) && 0 === strpos( Perdita_Switch_Backups::name( 'WP Config.PHP' ), 'wp-config-php-' ), 'backups: two names for the same kind differ, and a kind is slugged' );
	$pdsw_src_dir = trailingslashit( get_temp_dir() ) . 'pdsw-dir-' . wp_generate_password( 6, false );
	wp_mkdir_p( $pdsw_src_dir );
	file_put_contents( $pdsw_src_dir . '/example.com.php', "<?php\n\$secret = 'x';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$pdsw_dcopy = Perdita_Switch_Backups::save_dir( $pdsw_src_dir, 'wp-rocket-config' );
	$pdsw_dlist = '' !== $pdsw_dcopy ? array_map( 'basename', (array) glob( $pdsw_bdir . $pdsw_dcopy . '/*' ) ) : array();
	$ok( '' !== $pdsw_dcopy && array( 'example-com-php.bak' ) === $pdsw_dlist && '0700' === substr( sprintf( '%o', fileperms( $pdsw_bdir . $pdsw_dcopy ) ), -4 ) && "<?php\n\$secret = 'x';\n" === file_get_contents( $pdsw_bdir . $pdsw_dcopy . '/example-com-php.bak' ), 'backups: a folder copy keeps each file\'s bytes under a .bak name, in a 0700 folder' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	unlink( $pdsw_src_dir . '/example.com.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	rmdir( $pdsw_src_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	$ok( '' === Perdita_Switch_Backups::save_file( $pdsw_bdir . 'no-such-file', 'x' ), 'backups: a missing file saves nothing' );

	// The screen lists the copies and fires the three hooks in place.
	$pdsw_hooks = array();
	$pdsw_h_before = static function ( $sources ) use ( &$pdsw_hooks ) {
		$pdsw_hooks[] = array( 'before', is_array( $sources ) && isset( $sources['wp-super-cache'] ) );
		echo '<div id="pdsw-hook-before"></div>';
	};
	$pdsw_h_card = static function ( $source, $state ) use ( &$pdsw_hooks ) {
		$pdsw_hooks[] = array( 'card', $source instanceof Perdita_Switch_Source ? $source->id() : '', is_array( $state ) && array_key_exists( 'imported', $state ) );
		echo '<div class="pdsw-hook-card" data-id="' . esc_attr( $source->id() ) . '"></div>';
	};
	$pdsw_h_after = static function ( $sources ) use ( &$pdsw_hooks ) {
		$pdsw_hooks[] = array( 'after', is_array( $sources ) );
		echo '<div id="pdsw-hook-after"></div>';
	};
	add_action( 'perdita_switch_screen_before', $pdsw_h_before );
	add_action( 'perdita_switch_card_after', $pdsw_h_card, 10, 2 );
	add_action( 'perdita_switch_screen_after', $pdsw_h_after );
	ob_start();
	$pdsw_admin_ui->page();
	$pdsw_html = (string) ob_get_clean();
	remove_action( 'perdita_switch_screen_before', $pdsw_h_before );
	remove_action( 'perdita_switch_card_after', $pdsw_h_card, 10 );
	remove_action( 'perdita_switch_screen_after', $pdsw_h_after );
	$pdsw_card_at = strpos( $pdsw_html, 'id="pds-wp-super-cache"' );
	$pdsw_hook_at = strpos( $pdsw_html, '<div class="pdsw-hook-card" data-id="wp-super-cache">' );
	$pdsw_next_at = false !== $pdsw_card_at ? strpos( $pdsw_html, '<div class="pds-card" id="pds-', $pdsw_card_at + 1 ) : false;
	$pdsw_card_ok = false !== $pdsw_card_at && false !== $pdsw_hook_at && $pdsw_hook_at > $pdsw_card_at && ( false === $pdsw_next_at || $pdsw_hook_at < $pdsw_next_at );
	$ok( $pdsw_card_ok && $pdsw_hook_at > strpos( $pdsw_html, 'Reactivate WP Super Cache', (int) $pdsw_card_at ) && '</div>' === substr( $pdsw_html, $pdsw_hook_at + strlen( '<div class="pdsw-hook-card" data-id="wp-super-cache"></div>' ), 6 ), 'hooks: perdita_switch_card_after fires after the card\'s own controls and inside its wrapper' );
	$pdsw_cards = array_values( array_filter( $pdsw_hooks, static function ( $h ) { return 'card' === $h[0]; } ) );
	$ok( $pdsw_cards && ! array_filter( $pdsw_cards, static function ( $h ) { return '' === $h[1] || ! $h[2]; } ) && substr_count( $pdsw_html, '<div class="pds-card" id="pds-' ) - substr_count( $pdsw_html, 'id="pds-job"' ) - substr_count( $pdsw_html, 'id="pds-backups"' ) === count( $pdsw_cards ), 'hooks: perdita_switch_card_after passes ( Perdita_Switch_Source, state array ) once per card' );
	$pdsw_b_at = strpos( $pdsw_html, '<div id="pdsw-hook-before">' );
	$pdsw_a_at = strpos( $pdsw_html, '<div id="pdsw-hook-after">' );
	$ok( false !== $pdsw_b_at && $pdsw_b_at > strpos( $pdsw_html, 'class="pds-intro"' ) && $pdsw_b_at < strpos( $pdsw_html, '<div class="pds-card"' ) && array( 'before', true ) === $pdsw_hooks[0], 'hooks: perdita_switch_screen_before fires after the introduction and before the first card, with the sources' );
	$ok( false !== $pdsw_a_at && $pdsw_a_at > strpos( $pdsw_html, 'id="pds-backups"' ) && '</div>' === substr( rtrim( $pdsw_html ), -6 ) && array( 'after', true ) === end( $pdsw_hooks ), 'hooks: perdita_switch_screen_after fires after the cards and the backup copies, inside the screen' );
	$ok( false !== strpos( $pdsw_html, 'id="pds-backups"' ) && false !== strpos( $pdsw_html, 'Delete backup copies' ) && false !== strpos( $pdsw_html, esc_html( $pdsw_all[0]['name'] ) ) && false !== strpos( $pdsw_html, Perdita_Switch_Backups::display_dir() ), 'screen: Backup copies lists each copy, names the folder, and offers Delete backup copies' );
	$ok( false === strpos( $pdsw_html, 'beside any file' ) && false === strpos( $pdsw_html, 'still loads on every request' ), 'screen: no leftover text claims a copy sits beside the file or that the drop-in always loads' );
	if ( ! $pdsw_bk_before ) {
		$pdsw_out = $pdsw_call( array( 'do' => 'delete_backups', 'source' => 'all', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'delete_backups', 'wp-super-cache' ) ) ) );
		$ok( 0 === strpos( $pdsw_out, 'die:' ) && Perdita_Switch_Backups::all(), 'backups: Delete backup copies needs its own nonce' );
		$pdsw_out = $pdsw_call( array( 'do' => 'delete_backups', 'source' => 'all', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'delete_backups', 'all' ) ) ) );
		$ok( 0 === strpos( $pdsw_out, 'redirect:' ) && false !== strpos( $pdsw_out, '#pds-backups' ) && ! Perdita_Switch_Backups::all() && is_file( $pdsw_bdir . '.htaccess' ) && is_file( $pdsw_bdir . 'index.php' ), 'backups: Delete backup copies deletes every copy and keeps the folder closed' );
		ob_start();
		$pdsw_admin_ui->page();
		$ok( false === strpos( (string) ob_get_clean(), 'id="pds-backups"' ), 'screen: Backup copies hides once no copy is left' );
	} else {
		echo "SKIP  Delete backup copies (this site already had backup copies)\n";
	}

	// Reactivate.
	$pdsw_put( Perdita_Switch_Leftovers::dropin_path(), "<?php\n/**\n * " . Perdita_Caching::DROPIN_MARKER . "\n */\n" );
	$pdsw_ours = in_array( Perdita_Caching::dropin_state(), array( 'ours', 'stale' ), true );
	$pdsw_out  = $pdsw_call( array( 'do' => 'reactivate', 'source' => 'wp-super-cache', '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( 'reactivate', 'wp-super-cache' ) ) ) );
	$ok( is_plugin_active( 'wp-super-cache/wp-cache.php' ) && Perdita_Switch::state( 'wp-super-cache' )['reactivated'], 'handler: Reactivate turns it back on' );
	$ok( $pdsw_ours && ! is_file( Perdita_Switch_Leftovers::dropin_path() ), 'reactivate: Perdita\'s drop-in steps aside first, so the plugin can write its own' );
	$ok( false !== strpos( rawurldecode( str_replace( '+', ' ', $pdsw_out ) ), 'own cache was switched off when you deactivated' ), 'reactivate: the message says to switch WP Super Cache\'s cache back on' );
	$pdsw_fix = Perdita_Switch::get( 'wp-super-cache' )->fix_leftover( 'dropin' );
	$ok( ! $pdsw_fix['ok'], 'leftovers: nothing is removed while the plugin is active again' );

	remove_filter( 'wp_die_handler', $pdsw_die );
	remove_filter( 'wp_redirect', $pdsw_redirect, 1 );
	$_POST    = array();
	$_REQUEST = array();
	deactivate_plugins( 'wp-super-cache/wp-cache.php', true );
}

/* ---------- restore ---------- */

$pdsw_cleanup();
$pdsw_same = true;
foreach ( $pdsw_saved as $pdsw_n => $pdsw_was ) {
	if ( get_option( $pdsw_n, $pdsw_missing ) !== $pdsw_was ) {
		$pdsw_same = false;
		echo "      changed: $pdsw_n\n";
	}
}
foreach ( $pdsw_file_was as $pdsw_path => $pdsw_body ) {
	if ( ( is_file( $pdsw_path ) ? (string) file_get_contents( $pdsw_path ) : null ) !== $pdsw_body ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pdsw_same = false;
		echo "      changed: $pdsw_path\n";
	}
}
$ok( $pdsw_same && ( $pdsw_stub_exists || ! is_dir( WP_PLUGIN_DIR . '/wp-super-cache' ) ), 'switch: every option, file and plugin is back the way it was' );
$pdsw_live_same = true;
foreach ( $pdsw_live_was as $pdsw_path => $pdsw_md5 ) {
	if ( ( is_file( $pdsw_path ) ? md5_file( $pdsw_path ) : null ) !== $pdsw_md5 ) {
		$pdsw_live_same = false;
	}
}
$ok( $pdsw_live_same, 'switch: the live wp-config.php and .htaccess were never touched (every edit went to a temp copy)' );
remove_filter( 'perdita_switch_wp_config_path', $pdsw_cfg_filter );
remove_filter( 'perdita_switch_htaccess_path', $pdsw_ht_filter );
