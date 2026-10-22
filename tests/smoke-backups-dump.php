<?php
/**
 * Perdita Core smoke tests: the Backups module's dump and import round trip.
 *
 * Loaded by tests/smoke.php. Until 0.19.13-beta every "%" in the database
 * went into the dump as WordPress's random placeholder escape
 * ("{64 hex}"), because esc_sql() adds it and only $wpdb->prepare() inside
 * the same request removes it. A restore brought back "/{hash}postname{hash}/"
 * as the permalink structure and broke every serialized value holding a "%".
 * This round-trips a scratch table through the real export and import code
 * and never touches any other table.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- backups: dump and import round trip (tests/smoke-backups-dump.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/backups/class-perdita-backups.php';
if ( ! method_exists( 'Perdita_Backups', 'sql_value' ) ) {
	echo "SKIP  backups dump (sql_value() not available)\n";
	return;
}

global $wpdb;
$bkd_table = $wpdb->prefix . 'perdita_smoke_dump';
$bkd_q     = '`' . $bkd_table . '`';
$bkd_value = "50% off /%postname%/ a:1:{s:3:\"pct\";s:4:\"100%\";} it's \\ done;";

$ok( 'NULL' === Perdita_Backups::sql_value( null ), 'backups dump: NULL stays NULL' );
$ok( "'100%'" === Perdita_Backups::sql_value( '100%' ), 'backups dump: a "%" is written as "%", not $wpdb\'s placeholder' );
$ok( 1 !== preg_match( '/\{[0-9a-f]{64}\}/', Perdita_Backups::sql_value( $bkd_value ) ), 'backups dump: no placeholder hash in an escaped value' );

$bkd_ref    = new ReflectionClass( 'Perdita_Backups' );
$bkd_import = $bkd_ref->getMethod( 'import_database' );
if ( PHP_VERSION_ID < 80100 ) {
	$bkd_import->setAccessible( true );
}
$bkd_module = $bkd_ref->newInstanceWithoutConstructor();
$bkd_dir    = trailingslashit( get_temp_dir() ) . 'perdita-smoke-dump-' . wp_generate_password( 8, false );
wp_mkdir_p( $bkd_dir );

$bkd_read = function () use ( $wpdb, $bkd_q ) {
	return $wpdb->get_var( 'SELECT v FROM ' . $bkd_q . ' WHERE id = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
};
$bkd_dump = function ( $header, $literal ) use ( $bkd_q ) {
	return $header
		. "SET FOREIGN_KEY_CHECKS=0;\n"
		. 'DROP TABLE IF EXISTS ' . $bkd_q . ";\n"
		. 'CREATE TABLE ' . $bkd_q . " (id int NOT NULL, v longtext, PRIMARY KEY (id));\n"
		. 'INSERT INTO ' . $bkd_q . ' (`id`, `v`) VALUES (1, ' . $literal . ");\n"
		. "SET FOREIGN_KEY_CHECKS=1;\n";
};

// A dump written today: the format line, and a value straight from sql_value().
file_put_contents( $bkd_dir . '/new.sql', $bkd_dump( Perdita_Backups::DUMP_FORMAT_LINE . "\n-- Perdita Backups database dump\n", Perdita_Backups::sql_value( $bkd_value ) ) );
$bkd_res = $bkd_import->invoke( $bkd_module, $bkd_dir . '/new.sql' );
$ok( true === $bkd_res && $bkd_value === $bkd_read(), 'backups dump: a value with "%", quotes, a backslash and a semicolon survives export and import byte for byte' );

// A dump from 0.19.12-beta or earlier: no format line, placeholder in the file.
$bkd_old = "'" . str_replace( '%', '{' . hash( 'sha256', 'old request' ) . '}', $wpdb->remove_placeholder_escape( esc_sql( $bkd_value ) ) ) . "'";
file_put_contents( $bkd_dir . '/old.sql', $bkd_dump( "-- Perdita Backups database dump\n", $bkd_old ) );
$bkd_res = $bkd_import->invoke( $bkd_module, $bkd_dir . '/old.sql' );
$ok( true === $bkd_res && $bkd_value === $bkd_read(), 'backups dump: restoring an older dump turns its placeholder hashes back into "%"' );

// A new-format dump is taken as written, even with text that looks like a placeholder.
$bkd_brace = 'kept {' . hash( 'sha256', 'literal' ) . '} as is';
file_put_contents( $bkd_dir . '/brace.sql', $bkd_dump( Perdita_Backups::DUMP_FORMAT_LINE . "\n", Perdita_Backups::sql_value( $bkd_brace ) ) );
$bkd_import->invoke( $bkd_module, $bkd_dir . '/brace.sql' );
$ok( $bkd_brace === $bkd_read(), 'backups dump: a new dump never rewrites a brace-wrapped hash in real content' );

$wpdb->query( 'DROP TABLE IF EXISTS ' . $bkd_q ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
array_map( 'unlink', (array) glob( $bkd_dir . '/*' ) );
@rmdir( $bkd_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
