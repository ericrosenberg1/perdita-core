<?php
/**
 * Perdita Core smoke tests: automatic updates are on by default, and an
 * owner who turns them off keeps them off.
 *
 * Until 0.19.7-beta the updater forced auto-updates off, so the theme moved
 * alone after every release and sites showed "versions are out of step". The
 * updater now adds Core to WordPress's auto_update_plugins list once per site
 * and then leaves the setting to the owner.
 *
 * Loaded by tests/smoke.php. The updater only exists on wp-admin, cron and
 * WP-CLI requests and is absent from a wordpress.org build, so this loads the
 * class itself and skips when the file is not there.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- auto-updates (tests/smoke-auto-update.php) ---\n";

if ( ! file_exists( PERDITA_CORE_DIR . 'inc/class-perdita-core-updater.php' ) ) {
	echo "SKIP  auto-update default (no self-hosted updater in this build)\n";
	return;
}
require_once PERDITA_CORE_DIR . 'inc/class-perdita-core-updater.php';
require_once ABSPATH . 'wp-admin/includes/update.php';

$au_list_was = get_site_option( 'auto_update_plugins', null );
$au_flag_was = get_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG, null );
$au_updater  = new Perdita_Core_Updater();
remove_filter( 'pre_set_site_transient_update_plugins', array( $au_updater, 'check' ) );
remove_filter( 'plugins_api', array( $au_updater, 'details' ) );
remove_filter( 'auto_update_plugin', array( $au_updater, 'auto_update' ) );
remove_filter( 'upgrader_pre_download', array( $au_updater, 'verify_download' ) );
remove_action( 'upgrader_process_complete', array( $au_updater, 'flush' ) );
remove_action( 'init', array( $au_updater, 'maybe_default_auto_updates' ) );

$au_base    = plugin_basename( PERDITA_CORE_FILE );
$au_item    = (object) array( 'plugin' => $au_base );
$au_other   = (object) array( 'plugin' => 'hello.php' );
$au_listed  = function () use ( $au_base ) {
	return in_array( $au_base, (array) get_site_option( 'auto_update_plugins', array() ), true );
};
$au_capture = null;
$au_spy     = function ( $value ) use ( &$au_capture ) {
	$au_capture = $value;
	return $value;
};

// The rig or a host may define AUTOMATIC_UPDATER_DISABLED. Allow plugin
// auto-updates here, and test the site-wide switch on its own below.
add_filter( 'plugins_auto_update_enabled', '__return_true' );

// A site that has never been defaulted, with another plugin already on.
delete_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG );
update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
$au_updater->maybe_default_auto_updates();
$ok( $au_listed(), 'auto-update: the first run turns automatic updates on for Core' );
$ok( in_array( 'hello.php', (array) get_site_option( 'auto_update_plugins', array() ), true ), 'auto-update: turning Core on keeps every other plugin\'s setting' );
$ok( PERDITA_CORE_VERSION === get_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG ), 'auto-update: the first run records that it ran' );
$ok( true === $au_updater->auto_update( true, $au_item ), 'auto-update: a background run updates Core while the setting is on' );

// The Plugins screen asks with null. A non-null answer would read as forced
// and hide the owner's Disable link.
add_filter( 'auto_update_plugin', array( $au_updater, 'auto_update' ), 10, 2 );
$ok( null === wp_is_auto_update_forced_for_item( 'plugin', null, $au_item ), 'auto-update: the Plugins screen keeps its Enable and Disable link for Core' );
remove_filter( 'auto_update_plugin', array( $au_updater, 'auto_update' ) );

// The owner clicks "Disable auto-updates".
update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
$au_updater->maybe_default_auto_updates();
$ok( ! $au_listed(), 'auto-update: an owner who turned automatic updates off stays off' );
$ok( false === $au_updater->auto_update( false, $au_item ), 'auto-update: a background run respects the owner\'s off setting' );

// A host or owner who switched plugin auto-updates off for the whole site.
delete_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG );
update_site_option( 'auto_update_plugins', array() );
remove_filter( 'plugins_auto_update_enabled', '__return_true' );
add_filter( 'plugins_auto_update_enabled', '__return_false' );
$au_updater->maybe_default_auto_updates();
$ok( ! $au_listed() && false === get_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG ), 'auto-update: with plugin auto-updates off site-wide, nothing changes and nothing is recorded' );
remove_filter( 'plugins_auto_update_enabled', '__return_false' );
add_filter( 'plugins_auto_update_enabled', '__return_true' );
$au_updater->maybe_default_auto_updates();
$ok( $au_listed(), 'auto-update: once the site allows plugin auto-updates again, Core is turned on' );

// The developer filter decides when a site adds it.
add_filter( 'perdita_core_auto_update', '__return_false' );
$ok( false === $au_updater->auto_update( true, $au_item ), 'auto-update: the perdita_core_auto_update filter can force updates off' );
remove_filter( 'perdita_core_auto_update', '__return_false' );
add_filter( 'perdita_core_auto_update', '__return_true' );
update_site_option( 'auto_update_plugins', array() );
$ok( true === $au_updater->auto_update( false, $au_item ), 'auto-update: the perdita_core_auto_update filter can force updates on' );
remove_filter( 'perdita_core_auto_update', '__return_true' );
update_site_option( 'auto_update_plugins', array( $au_base ) );
add_filter( 'perdita_core_auto_update', $au_spy );
$au_updater->auto_update( null, $au_item );
remove_filter( 'perdita_core_auto_update', $au_spy );
$ok( true === $au_capture, 'auto-update: the filter receives the owner\'s stored setting when the Plugins screen asks' );

// Another plugin's offer passes through untouched.
$ok( true === $au_updater->auto_update( true, $au_other ) && null === $au_updater->auto_update( null, $au_other ), 'auto-update: another plugin\'s setting passes through untouched' );

// Restore the site's own state.
remove_filter( 'plugins_auto_update_enabled', '__return_true' );
if ( null === $au_list_was ) {
	delete_site_option( 'auto_update_plugins' );
} else {
	update_site_option( 'auto_update_plugins', $au_list_was );
}
if ( null === $au_flag_was ) {
	delete_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG );
} else {
	update_site_option( Perdita_Core_Updater::AUTO_UPDATE_FLAG, $au_flag_was );
}
