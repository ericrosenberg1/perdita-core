<?php
/**
 * Perdita Core smoke tests: Switch to Perdita against hostile input. The
 * wp-config.php edit on corrupt and unusual configs, the .htaccess block
 * cut, atomic writes, symlinks and name collisions in the backup folder,
 * the wrong-nonce and wrong-role matrix, the batch lock, and Undo after
 * later changes.
 *
 * Every file here is a temp copy in its own folder. Nothing touches the
 * live wp-config.php or .htaccess.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- Switch to Perdita: hostile configs, files, roles and the batch (tests/smoke-switch-safety.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-admin.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Perdita_Switch_Target::load( 'caching' );
Perdita_Switch::sources( true );

$pdsf_tmp = trailingslashit( get_temp_dir() ) . 'pdsf-' . wp_generate_password( 10, false );
wp_mkdir_p( $pdsf_tmp );
$pdsf_rm = static function ( $dir ) use ( &$pdsf_rm ) {
	if ( is_link( $dir ) || is_file( $dir ) ) {
		unlink( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$pdsf_rm( $dir . '/' . $entry );
		}
	}
	chmod( $dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
};
// Files this run may create outside the temp folder, removed again when
// they weren't there before: the backup folder and its guards, and the
// page cache's purge stamps (a finished batch purges the cache).
$pdsf_made = array();
foreach ( array( Perdita_Switch_Backups::dir(), Perdita_Caching::cache_dir() . 'purged-all.stamp', Perdita_Caching::cache_dir() . 'usage.stamp' ) as $pdsf_p ) {
	$pdsf_made[ $pdsf_p ] = file_exists( $pdsf_p );
}
register_shutdown_function(
	static function () use ( $pdsf_rm, $pdsf_tmp, $pdsf_made ) {
		$pdsf_rm( $pdsf_tmp );
		foreach ( $pdsf_made as $path => $was ) {
			if ( $was || ! file_exists( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) && array() !== array_diff( (array) scandir( $path ), array( '.', '..', '.htaccess', 'index.php', 'index.html', 'web.config' ) ) ) {
				continue; // Copies another test still owns.
			}
			$pdsf_rm( untrailingslashit( $path ) );
		}
	}
);
$pdsf_put = static function ( $path, $body ) {
	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};
$pdsf_get = static function ( $path ) {
	return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
};
$pdsf_parses = static function ( $src ) {
	try {
		token_get_all( (string) $src, TOKEN_PARSE );
		return true;
	} catch ( \Throwable $e ) {
		return false;
	}
};
$pdsf_line = Perdita_Switch_Leftovers::WP_CACHE_LINE;
$pdsf_add  = static function ( $src ) {
	return Perdita_Switch_Leftovers::config_with_wp_cache( $src );
};
// The stock wp-config-sample.php shape, one line at a time.
$pdsf_stock = implode(
	"\n",
	array(
		'<?php',
		"define( 'DB_NAME', 'db' );",
		"define( 'DB_PASSWORD', 's3cret' );",
		"\$table_prefix = 'wp_';",
		"define( 'WP_DEBUG', false );",
		'',
		'/* Add any custom values between this line and the "stop editing" line. */',
		'',
		'',
		'',
		"/* That's all, stop editing! Happy publishing. */",
		'',
		'/** Absolute path to the WordPress directory. */',
		"if ( ! defined( 'ABSPATH' ) ) {",
		"\tdefine( 'ABSPATH', __DIR__ . '/' );",
		'}',
		'',
		'/** Sets up WordPress vars and included files. */',
		"require_once ABSPATH . 'wp-settings.php';",
		'',
	)
);

/* =============================================================
 * wp-config.php: where the line goes, and when it doesn't.
 * ============================================================= */

$pdsf_new = $pdsf_add( $pdsf_stock );
$ok( is_string( $pdsf_new ) && $pdsf_parses( $pdsf_new ) && str_replace( $pdsf_line . "\n", '', $pdsf_new ) === $pdsf_stock && strpos( $pdsf_new, $pdsf_line ) < strpos( $pdsf_new, 'Add any custom values' ), 'wp-config: the line goes above the first "custom values" marker, and nothing else changes' );

$pdsf_src = "<?php\n\$note = \"/* That's all, stop editing! */\";\n\$x = 1;\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n";
$pdsf_new = $pdsf_add( $pdsf_src );
$ok( is_string( $pdsf_new ) && false !== strpos( $pdsf_new, "\$x = 1;\n" . $pdsf_line . "\n/* That's all" ) && false !== strpos( $pdsf_new, "\$note = \"/* That's all, stop editing! */\";" ), 'wp-config: the marker text inside a string never counts, so the string stays as it was' );

$pdsf_src = "<?php\n\$t = <<<" . "EOT\nrequire_once ABSPATH . 'wp-settings.php';\nThat's all, stop editing\nEOT;\nrequire_once ABSPATH . 'wp-settings.php';\n";
$pdsf_new = $pdsf_add( $pdsf_src );
$ok( is_string( $pdsf_new ) && $pdsf_parses( $pdsf_new ) && false !== strpos( $pdsf_new, "EOT;\n" . $pdsf_line . "\nrequire_once" ), 'wp-config: the marker text inside a heredoc never counts' );

$ok( null === $pdsf_add( "<?php\nif ( true ) {\n\trequire_once ABSPATH . 'wp-settings.php';\n}\n" ), 'wp-config: a require inside braces is no place for the line' );
$ok( null === $pdsf_add( "<?php\nif ( true ) require_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: a require that hangs off an if is no place for the line' );
$ok( null === $pdsf_add( "<?php\nif ( true ) :\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\nendif;\n" ), 'wp-config: alternative syntax (if: endif) is left alone' );
$ok( null === $pdsf_add( "<?php\ndefine( 'WP_CACHE', false );\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: an existing define( WP_CACHE ) is left to the owner' );
$ok( null === $pdsf_add( "<?php\ndefined( 'WP_CACHE' ) || define( 'WP_CACHE', true );\nrequire_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: a guarded define is left to the owner' );
$ok( null === $pdsf_add( "<?php\nconst WP_CACHE = false;\nrequire_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: const WP_CACHE is left to the owner' );
$ok( null === $pdsf_add( "<?php\ndefine( \"WP_CACHE\", false );\nrequire_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: a double-quoted WP_CACHE is left to the owner' );
$pdsf_new = $pdsf_add( "<?php\n// define( 'WP_CACHE', true );\nrequire_once ABSPATH . 'wp-settings.php';\n" );
$ok( is_string( $pdsf_new ) && $pdsf_parses( $pdsf_new ), 'wp-config: a commented-out WP_CACHE line doesn\'t block the edit' );
$ok( null === $pdsf_add( "<?php\ndefine( 'DB_NAME', 'db' \n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n" ), 'wp-config: a file that doesn\'t parse is never edited' );
$ok( null === $pdsf_add( "<?php\ndefine( 'DB_NAME', 'db' );\n" ), 'wp-config: a file with no marker and no wp-settings.php require (one that loads another config) is left to the owner' );
$ok( null === $pdsf_add( '' ) && null === $pdsf_add( 'not php at all' ), 'wp-config: an empty or non-PHP file is never edited' );

$pdsf_crlf = str_replace( "\n", "\r\n", $pdsf_stock );
$pdsf_new  = $pdsf_add( $pdsf_crlf );
$ok( is_string( $pdsf_new ) && false !== strpos( $pdsf_new, $pdsf_line . "\r\n" ) && 0 === preg_match( "/(?<!\r)\n/", $pdsf_new ) && str_replace( $pdsf_line . "\r\n", '', $pdsf_new ) === $pdsf_crlf, 'wp-config: Windows line endings stay Windows line endings, with no bare newline added' );

$pdsf_bom = "\xEF\xBB\xBF" . $pdsf_stock;
$pdsf_new = $pdsf_add( $pdsf_bom );
$ok( is_string( $pdsf_new ) && 0 === strpos( $pdsf_new, "\xEF\xBB\xBF<?php" ) && str_replace( $pdsf_line . "\n", '', $pdsf_new ) === $pdsf_bom, 'wp-config: a byte order mark stays at the start' );

$pdsf_new = $pdsf_add( "<?php\n\$a = 1; /* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n?>\n<!-- trailing html -->\n" );
$ok( is_string( $pdsf_new ) && $pdsf_parses( $pdsf_new ) && false !== strpos( $pdsf_new, "<!-- trailing html -->\n" ), 'wp-config: a marker mid-line and a closing tag with HTML after it still give a file that parses' );

$pdsf_new = $pdsf_add( "<?php\n/**\n * Notes. That's all, stop editing! appears in this docblock.\n */\ndefine( 'X', 1 );\nrequire_once ABSPATH . 'wp-settings.php';" );
$ok( is_string( $pdsf_new ) && $pdsf_parses( $pdsf_new ) && strpos( $pdsf_new, $pdsf_line ) < strpos( $pdsf_new, '/**' ), 'wp-config: with no closing newline or tag, the line still lands at the top level and parses' );

$ok( Perdita_Switch_Leftovers::php_defines_wp_cache( "<?php define( 'WP_CACHE', true );" ) && ! Perdita_Switch_Leftovers::php_defines_wp_cache( "<?php if ( WP_CACHE ) { echo 1; }" ) && ! Perdita_Switch_Leftovers::php_defines_wp_cache( "<?php # define( 'WP_CACHE', true );" ), 'wp-config: a define counts, a read or a comment doesn\'t' );

/* ---------- the blocker and the write ---------- */

$pdsf_cfg = $pdsf_tmp . '/wp-config.php';
$pdsf_put( $pdsf_cfg, $pdsf_stock );
chmod( $pdsf_cfg, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
$ok( '' === Perdita_Switch_Leftovers::config_blocker( $pdsf_cfg ), 'wp-config: a writable stock config can be edited' );
$ok( '' !== Perdita_Switch_Leftovers::config_blocker( $pdsf_tmp . '/missing.php' ), 'wp-config: a missing file gets the manual step' );

$pdsf_link = $pdsf_tmp . '/linked-config.php';
symlink( $pdsf_cfg, $pdsf_link );
$ok( '' !== Perdita_Switch_Leftovers::config_blocker( $pdsf_link ) && ! Perdita_Switch_Leftovers::write_atomic( $pdsf_link, 'x', $pdsf_stock ), 'wp-config: a symlinked config is never written through' );

$pdsf_new = $pdsf_add( $pdsf_stock );
$ok( ! Perdita_Switch_Leftovers::write_atomic( $pdsf_cfg, $pdsf_new, $pdsf_stock . ' ' ) && $pdsf_stock === $pdsf_get( $pdsf_cfg ), 'write: a file that changed since it was read is left alone' );
$ok( ! Perdita_Switch_Leftovers::write_atomic( $pdsf_cfg, "<?php\ndefine( 'X', ", $pdsf_stock ) && $pdsf_stock === $pdsf_get( $pdsf_cfg ), 'write: PHP that wouldn\'t parse is never written' );
$ok( Perdita_Switch_Leftovers::write_atomic( $pdsf_cfg, $pdsf_new, $pdsf_stock ) && $pdsf_new === $pdsf_get( $pdsf_cfg ), 'write: the new contents land whole' );
clearstatcache();
$ok( '0640' === substr( sprintf( '%o', fileperms( $pdsf_cfg ) ), -4 ), 'write: the file keeps its permissions' );
$ok( ! glob( $pdsf_tmp . '/.*.tmp' ), 'write: no temp file is left beside it' );

$pdsf_ro = $pdsf_tmp . '/ro';
wp_mkdir_p( $pdsf_ro );
$pdsf_put( $pdsf_ro . '/wp-config.php', $pdsf_stock );
chmod( $pdsf_ro, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
if ( ! is_writable( $pdsf_ro ) ) {
	$ok( '' !== Perdita_Switch_Leftovers::config_blocker( $pdsf_ro . '/wp-config.php' ) && ! Perdita_Switch_Leftovers::write_atomic( $pdsf_ro . '/wp-config.php', $pdsf_new, $pdsf_stock ) && $pdsf_stock === $pdsf_get( $pdsf_ro . '/wp-config.php' ), 'write: a folder that can\'t take a temp file gets the manual step, and the config stays whole' );
} else {
	echo "SKIP  read-only folder (running as root)\n";
}
chmod( $pdsf_ro, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

// The whole path, through the WP Super Cache card, on a copy.
$pdsf_cfg_filter = static function () use ( $pdsf_cfg ) {
	return $pdsf_cfg;
};
add_filter( 'perdita_switch_wp_config_path', $pdsf_cfg_filter );
$pdsf_caching_was = get_option( 'perdita_caching_settings', null );
update_option( 'perdita_caching_settings', array_merge( Perdita_Caching::settings(), array( 'enabled' => true, 'early_serve' => true ) ) );
$pdsf_hostile = "<?php\n\$s = 'require_once ABSPATH . \\'wp-settings.php\\';';\nif ( getenv( 'X' ) ) {\n\tdefine( 'WP_DEBUG', true );\n}\n/* That's all, stop editing! */\nrequire_once ABSPATH . 'wp-settings.php';\n";
$pdsf_put( $pdsf_cfg, $pdsf_hostile );
$pdsf_bk_before = wp_list_pluck( Perdita_Switch_Backups::all(), 'name' );
if ( ! Perdita_Caching::wp_cache_on() ) {
	$pdsf_item = Perdita_Switch_Leftovers::wp_cache_item();
	$pdsf_res  = Perdita_Switch_Leftovers::fix( Perdita_Switch::get( 'wp-super-cache' ), 'wp_cache', array(), array() );
	$pdsf_body = $pdsf_get( $pdsf_cfg );
	$ok( $pdsf_item && $pdsf_item['removable'] && $pdsf_res['ok'] && $pdsf_parses( $pdsf_body ) && 1 === substr_count( $pdsf_body, $pdsf_line ) && strpos( $pdsf_body, $pdsf_line ) > strpos( $pdsf_body, "\n}\n" ), 'wp-config: through the card, the line lands after the if block and outside the string' );
	$pdsf_new_bk = array_diff( wp_list_pluck( Perdita_Switch_Backups::all(), 'name' ), $pdsf_bk_before );
	$pdsf_leak   = false;
	foreach ( $pdsf_new_bk as $pdsf_b ) {
		if ( is_file( Perdita_Switch_Backups::dir() . $pdsf_b ) && false !== strpos( $pdsf_get( Perdita_Switch_Backups::dir() . $pdsf_b ), 'getenv' ) ) {
			$pdsf_leak = true;
		}
	}
	$ok( 1 === count( $pdsf_new_bk ) && ! $pdsf_leak && 0 === strpos( (string) reset( $pdsf_new_bk ), 'wp-config-line-' ), 'wp-config: the backup folder gets a note with the line, never a copy of the config' );
	foreach ( $pdsf_new_bk as $pdsf_b ) {
		wp_delete_file( Perdita_Switch_Backups::dir() . $pdsf_b );
	}
	$pdsf_put( $pdsf_cfg, "<?php\ndefine( 'WP_CACHE', false );\nrequire_once ABSPATH . 'wp-settings.php';\n" );
	$pdsf_item = Perdita_Switch_Leftovers::wp_cache_item();
	$pdsf_res  = Perdita_Switch_Leftovers::fix( Perdita_Switch::get( 'wp-super-cache' ), 'wp_cache', array(), array() );
	$ok( $pdsf_item && ! $pdsf_item['removable'] && false !== strpos( $pdsf_item['detail'], 'change that line to true' ) && ! $pdsf_res['ok'] && false === strpos( $pdsf_get( $pdsf_cfg ), 'Added by Perdita' ), 'wp-config: WP_CACHE set to false already gets the manual step, and the file stays as it was' );
} else {
	echo "SKIP  WP_CACHE card (WP_CACHE is on here)\n";
}
remove_filter( 'perdita_switch_wp_config_path', $pdsf_cfg_filter );
if ( null === $pdsf_caching_was ) {
	delete_option( 'perdita_caching_settings' );
} else {
	update_option( 'perdita_caching_settings', $pdsf_caching_was );
}

/* =============================================================
 * .htaccess blocks.
 * ============================================================= */

$pdsf_wp_block = "# BEGIN WordPress\r\n<IfModule mod_rewrite.c>\r\nRewriteEngine On\r\nRewriteRule ^index\\.php$ - [L]\r\n</IfModule>\r\n# END WordPress\r\n";
$pdsf_ht       = "# BEGIN WPSuperCache\r\nRewriteEngine On\r\nRewriteRule x y\r\n# END WPSuperCache\r\n\r\n" . $pdsf_wp_block . "AddType x y\r\n";
$pdsf_cut      = Perdita_Switch_Leftovers::strip_block( $pdsf_ht, 'WPSuperCache' );
$ok( "\r\n" . $pdsf_wp_block . "AddType x y\r\n" === $pdsf_cut, '.htaccess: the block goes and every other byte stays, Windows line endings and WordPress\'s block included' );
$ok( null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WPSuperCache\nA\n# END WordPress\n# END WPSuperCache\n", 'WPSuperCache' ), '.htaccess: a block that crosses another block\'s marker is refused' );
$ok( null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WordPress\n# BEGIN WPSuperCache\nA\n# END WordPress\nB\n# END WPSuperCache\n", 'WPSuperCache' ), '.htaccess: a block that starts inside WordPress\'s block and ends past it is refused' );
$ok( null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WPSuperCache\n# BEGIN WPSuperCache\nA\n# END WPSuperCache\n", 'WPSuperCache' ), '.htaccess: a nested BEGIN is refused' );
$ok( null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WPSuperCache\nA\n", 'WPSuperCache' ), '.htaccess: a block with no END is refused' );
$ok( null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WPSuperCacheX\nA\n# END WPSuperCacheX\n", 'WPSuperCache' ), '.htaccess: a marker that only starts with the name isn\'t the block' );
$ok( "keep\n" === Perdita_Switch_Leftovers::strip_block( "# BEGIN WpFastestCache\nA\n# END WpFastestCache\nkeep\n# BEGIN WpFastestCache\nB\n# END WpFastestCache\n", 'WpFastestCache' ), '.htaccess: a block written twice goes both times' );
$ok( "# BEGIN WordPress\n# BEGIN LSCACHE\n# END WordPress\n" === Perdita_Switch_Leftovers::strip_block( "# BEGIN WordPress\n# BEGIN LSCACHE\n# END WordPress\n", 'NON_LSCACHE' ) || null === Perdita_Switch_Leftovers::strip_block( "# BEGIN WordPress\n# BEGIN LSCACHE\n# END WordPress\n", 'NON_LSCACHE' ), '.htaccess: a marker that isn\'t there changes nothing' );

/* =============================================================
 * The drop-in, the backup folder, symlinks and collisions.
 * ============================================================= */

$pdsf_src_dir = $pdsf_tmp . '/rocket-config';
$pdsf_outside = $pdsf_tmp . '/outside-secret.txt';
wp_mkdir_p( $pdsf_src_dir );
$pdsf_put( $pdsf_outside, 'outside' );
$pdsf_put( $pdsf_src_dir . '/a.b.php', 'one' );
$pdsf_put( $pdsf_src_dir . '/a-b.php', 'two' );
symlink( $pdsf_outside, $pdsf_src_dir . '/link.php' );
$pdsf_copy = Perdita_Switch_Backups::save_dir( $pdsf_src_dir, 'pdsf-dir' );
$pdsf_list = '' !== $pdsf_copy ? (array) glob( Perdita_Switch_Backups::dir() . $pdsf_copy . '/*' ) : array();
$pdsf_bodies = array();
foreach ( $pdsf_list as $pdsf_f ) {
	$pdsf_bodies[] = $pdsf_get( $pdsf_f );
}
sort( $pdsf_bodies );
$ok( '' !== $pdsf_copy && array( 'one', 'two' ) === $pdsf_bodies, 'backups: a.b.php and a-b.php both keep their copy, and a symlink in the folder is never followed' );

// A symlinked folder that looks like a backup copy: deleting it removes the
// link, never the files it points at.
$pdsf_victim = $pdsf_tmp . '/victim';
wp_mkdir_p( $pdsf_victim );
$pdsf_put( $pdsf_victim . '/keep.txt', 'keep' );
$pdsf_fake = Perdita_Switch_Backups::dir() . Perdita_Switch_Backups::name( 'pdsf-link', false );
symlink( $pdsf_victim, $pdsf_fake );
// delete_all() runs delete_path() on each copy. Call it on this one only,
// so a site's own backup copies stay.
$pdsf_del = new ReflectionMethod( 'Perdita_Switch_Backups', 'delete_path' );
if ( PHP_VERSION_ID < 80100 ) {
	$pdsf_del->setAccessible( true );
}
$pdsf_del->invoke( null, $pdsf_fake );
if ( '' !== $pdsf_copy ) {
	$pdsf_del->invoke( null, Perdita_Switch_Backups::dir() . $pdsf_copy );
}
$ok( ! is_link( $pdsf_fake ) && is_file( $pdsf_victim . '/keep.txt' ), 'backups: Delete backup copies removes a symlinked copy\'s link, never the files behind it' );

// Another page cache is active: its advanced-cache.php may be in use, so
// Perdita won't remove it even when it mentions the old plugin.
$pdsf_dropin     = Perdita_Switch_Leftovers::dropin_path();
$pdsf_dropin_was = is_file( $pdsf_dropin ) ? $pdsf_get( $pdsf_dropin ) : null;
$pdsf_active_was = get_option( 'active_plugins' );
$pdsf_put( $pdsf_dropin, "<?php\n// W3 Total Cache compatible. Mentions WP SUPER CACHE too.\n" );
update_option( 'active_plugins', array_merge( (array) $pdsf_active_was, array( 'w3-total-cache/w3-total-cache.php' ) ) );
$pdsf_res = Perdita_Switch_Leftovers::fix( Perdita_Switch::get( 'wp-super-cache' ), 'dropin', array( 'WP SUPER CACHE' ), array() );
update_option( 'active_plugins', $pdsf_active_was );
$ok( ! $pdsf_res['ok'] && is_file( $pdsf_dropin ) && false !== strpos( $pdsf_res['message'], 'W3 Total Cache' ), 'drop-in: with another page cache active, its advanced-cache.php stays' );
if ( null === $pdsf_dropin_was ) {
	wp_delete_file( $pdsf_dropin );
} else {
	$pdsf_put( $pdsf_dropin, $pdsf_dropin_was );
}

/* =============================================================
 * The settings reader on hostile files.
 * ============================================================= */

$pdsf_canary = $pdsf_tmp . '/ran';
$pdsf_evil   = implode(
	"\n",
	array(
		'<?php',
		'$a = 1;',
		'if ( 1 ) :',
		'$b = touch( ' . var_export( $pdsf_canary, true ) . ' );',
		'endif;',
	)
);
$ok( array() === Perdita_Switch_PHP_Config::parse( $pdsf_evil ) && ! file_exists( $pdsf_canary ), 'php config: alternative syntax makes the reader skip the whole file, and nothing runs' );
$ok( array( 'n' => 1000000, 'f' => 1.5 ) === Perdita_Switch_PHP_Config::parse( "<?php\n\$n = 1_000_000;\n\$f = 1_5e-1 * 10;\n\$f = 1.5;" ), 'php config: numbers with underscores read as written, and arithmetic is skipped' );
$pdsf_cfgx = Perdita_Switch_PHP_Config::parse( "<?php\n\$s = \"\xff\xfe bad utf8\";\n\$x = \"{\$o->m()}\";\n\$y = \$\$z;\n\$w = PHP_VERSION;\n" );
$ok( isset( $pdsf_cfgx['s'] ) && ! isset( $pdsf_cfgx['x'] ) && ! isset( $pdsf_cfgx['y'] ) && ! isset( $pdsf_cfgx['w'] ), 'php config: invalid UTF-8 stays text, and method calls in strings, variable variables, and constants are skipped' );
$pdsf_show = Perdita_Switch_Source::show( "abc\u{202E}gpj.exe\u{200B}<script>x</script>" );
$ok( false === strpos( $pdsf_show, "\u{202E}" ) && false === strpos( $pdsf_show, "\u{200B}" ) && false === strpos( $pdsf_show, '<script' ), 'preview: direction overrides, zero-width characters, and tags never reach the table' );

/* =============================================================
 * Roles and nonces: every action, every wrong key.
 * ============================================================= */

$pdsf_users = array();
foreach ( array( 'subscriber', 'editor', 'administrator' ) as $pdsf_role ) {
	$pdsf_users[ $pdsf_role ] = wp_insert_user(
		array(
			'user_login' => 'pdsf_' . $pdsf_role . '_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => $pdsf_role,
		)
	);
}
$pdsf_user_was = get_current_user_id();
$pdsf_state_was = get_option( Perdita_Switch::STATE, null );
$pdsf_ui        = new Perdita_Switch_Admin();
$pdsf_die       = static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new RuntimeException( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) );
	};
};
$pdsf_redirect  = static function ( $location ) {
	throw new RuntimeException( 'redirect:' . $location );
};
add_filter( 'wp_die_handler', $pdsf_die );
add_filter( 'wp_redirect', $pdsf_redirect, 1 );
$pdsf_call = static function ( array $req ) use ( $pdsf_ui ) {
	$_POST    = $req;
	$_REQUEST = $req;
	try {
		$pdsf_ui->handle();
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
	return 'returned';
};
$pdsf_actions = array( 'import', 'skip', 'undo', 'deactivate', 'reactivate', 'leftover', 'batch', 'dismiss' );
$pdsf_bad     = array();
foreach ( $pdsf_actions as $pdsf_do ) {
	$pdsf_base = array(
		'do'      => $pdsf_do,
		'source'  => 'wp-super-cache',
		'confirm' => '1',
		'item'    => 'dropin',
	);
	foreach ( array( 'subscriber', 'editor' ) as $pdsf_role ) {
		wp_set_current_user( $pdsf_users[ $pdsf_role ] );
		$pdsf_out = $pdsf_call( $pdsf_base + array( '_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( $pdsf_do, 'wp-super-cache' ) ) ) );
		if ( 0 !== strpos( $pdsf_out, 'die:403' ) ) {
			$pdsf_bad[] = $pdsf_role . '/' . $pdsf_do . '=' . $pdsf_out;
		}
	}
	wp_set_current_user( $pdsf_users['administrator'] );
	$pdsf_other = 'import' === $pdsf_do ? 'skip' : 'import';
	foreach (
		array(
			'other-action' => wp_create_nonce( Perdita_Switch_Admin::nonce( $pdsf_other, 'wp-super-cache' ) ),
			'other-source' => wp_create_nonce( Perdita_Switch_Admin::nonce( $pdsf_do, 'w3-total-cache' ) ),
			'none'         => '',
		) as $pdsf_kind => $pdsf_nonce
	) {
		$pdsf_out = $pdsf_call( $pdsf_base + array( '_wpnonce' => $pdsf_nonce ) );
		if ( 0 !== strpos( $pdsf_out, 'die:' ) ) {
			$pdsf_bad[] = 'admin/' . $pdsf_do . '/' . $pdsf_kind . '=' . $pdsf_out;
		}
	}
}
$ok( ! $pdsf_bad && get_option( Perdita_Switch::STATE, null ) === $pdsf_state_was, 'handler: subscribers and editors get a 403 on every action, and an admin with a nonce for another action, another source, or none gets nothing done (' . implode( ', ', $pdsf_bad ) . ')' );
wp_set_current_user( $pdsf_users['administrator'] );
foreach ( array( 'delete_backups', 'dismiss' ) as $pdsf_do ) {
	$pdsf_out = $pdsf_call(
		array(
			'do'       => $pdsf_do,
			'source'   => 'all',
			'_wpnonce' => wp_create_nonce( Perdita_Switch_Admin::nonce( $pdsf_do, 'wp-super-cache' ) ),
		)
	);
	$ok( 0 === strpos( $pdsf_out, 'die:' ), 'handler: ' . $pdsf_do . ' for all needs the nonce minted for all' );
}
remove_filter( 'wp_die_handler', $pdsf_die );
remove_filter( 'wp_redirect', $pdsf_redirect, 1 );
$_POST    = array();
$_REQUEST = array();
wp_set_current_user( $pdsf_user_was );
foreach ( $pdsf_users as $pdsf_uid ) {
	if ( ! is_wp_error( $pdsf_uid ) ) {
		wp_delete_user( $pdsf_uid );
	}
}

/* =============================================================
 * The batch: one runner at a time, idempotent, and one job at a time.
 * ============================================================= */

$pdsf_snap_was = get_option( Perdita_Switch::SNAPSHOTS, null );
$pdsf_seo_was  = get_option( 'perdita_seo', null );
$pdsf_state_was = get_option( Perdita_Switch::STATE, null );
$pdsf_term     = wp_insert_term( 'pdsf term ' . wp_generate_password( 6, false ), 'category' );
$pdsf_tid      = is_wp_error( $pdsf_term ) ? 0 : (int) $pdsf_term['term_id'];
if ( $pdsf_tid ) {
	update_term_meta( $pdsf_tid, 'rank_math_title', 'Imported title' );
	delete_transient( Perdita_Switch::JOB );
	delete_option( Perdita_Switch::LOCK );
	Perdita_Switch::start_batch( 'rank-math', 'import' );
	add_option( Perdita_Switch::LOCK, time(), '', false );
	$pdsf_done = Perdita_Switch::run_batch( 0 );
	$pdsf_job  = Perdita_Switch::job();
	$ok( false === $pdsf_done && $pdsf_job && 0 === (int) $pdsf_job['done'] && '' === (string) get_term_meta( $pdsf_tid, '_perdita_seo_title', true ), 'batch: while another request holds the lock, a second runner does nothing' );
	$ok( '' !== Perdita_Switch::busy_with_other( 'seopress' ) && '' === Perdita_Switch::busy_with_other( 'rank-math' ), 'batch: another source can\'t start its import or undo while this batch runs' );
	$pdsf_imp = Perdita_Switch::import( 'seopress' );
	$ok( ! $pdsf_imp['ok'] && false !== strpos( $pdsf_imp['message'], 'Rank Math' ) && Perdita_Switch::job() && 'rank-math' === Perdita_Switch::job()['source'], 'batch: an import from another source waits, and the running batch keeps going' );
	update_option( Perdita_Switch::LOCK, time() - 600, false );
	$pdsf_done = Perdita_Switch::run_batch( 5 );
	$pdsf_meta = get_term_meta( $pdsf_tid );
	$ok( $pdsf_done && ! get_option( Perdita_Switch::LOCK ) && ! wp_next_scheduled( Perdita_Switch::CRON ), 'batch: a lock left by a request that died is taken over, and the finished batch leaves no lock and no cron event' );
	Perdita_Switch::start_batch( 'rank-math', 'import' );
	Perdita_Switch::run_batch( 5 );
	$pdsf_meta2 = get_term_meta( $pdsf_tid );
	$ok( $pdsf_meta === $pdsf_meta2 && 1 === count( get_term_meta( $pdsf_tid, '_perdita_switch_from', false ) ), 'batch: running the same batch twice leaves the term exactly as the first run did' );
	Perdita_Switch::start_batch( 'rank-math', 'undo' );
	Perdita_Switch::run_batch( 5 );
	wp_delete_term( $pdsf_tid, 'category' );
}
delete_transient( Perdita_Switch::JOB );
wp_clear_scheduled_hook( Perdita_Switch::CRON );

/* =============================================================
 * Undo after later changes.
 * ============================================================= */

$pdsf_gap_was = get_option( 'gap_options', null );
$pdsf_an_was  = get_option( 'perdita_analytics_settings', null );
update_option( 'gap_options', array( 'gap_id' => 'G-PDSF12345' ) );
$pdsf_res = Perdita_Switch::import( 'ga-google-analytics' );
$ok( $pdsf_res['ok'] && ! Perdita_Switch::changed_since_import( 'ga-google-analytics' ), 'undo: right after an import, Undo has nothing newer to lose' );
$pdsf_an = get_option( 'perdita_analytics_settings' );
$pdsf_an['measurement_id'] = 'G-LATER9999';
update_option( 'perdita_analytics_settings', $pdsf_an );
$ok( Perdita_Switch::changed_since_import( 'ga-google-analytics' ), 'undo: a change after the import is noticed, so the screen can warn before Undo throws it away' );
Perdita_Switch::undo( 'ga-google-analytics' );
foreach ( array( 'gap_options' => $pdsf_gap_was, 'perdita_analytics_settings' => $pdsf_an_was, Perdita_Switch::SNAPSHOTS => $pdsf_snap_was, Perdita_Switch::STATE => $pdsf_state_was, 'perdita_seo' => $pdsf_seo_was ) as $pdsf_n => $pdsf_v ) {
	if ( null === $pdsf_v ) {
		delete_option( $pdsf_n );
	} else {
		update_option( $pdsf_n, $pdsf_v );
	}
}
$ok( false !== strpos( Perdita_Switch_Target::after_import_note( 'smtp' ), 'test email' ), 'import: after an SMTP import, the message asks for a test email' );
