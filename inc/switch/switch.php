<?php
/**
 * Loads Switch to Perdita where it can run: wp-admin (the screen and the
 * plugin notices), cron (the resumable meta batch) and WP-CLI.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-perdita-switch-source.php';
require_once __DIR__ . '/class-perdita-switch-target.php';
require_once __DIR__ . '/class-perdita-switch-php-config.php';
require_once __DIR__ . '/class-perdita-switch-backups.php';
require_once __DIR__ . '/class-perdita-switch-leftovers.php';
require_once __DIR__ . '/class-perdita-switch.php';

add_action(
	Perdita_Switch::CRON,
	function () {
		Perdita_Switch::run_batch( 20 );
	}
);

if ( is_admin() ) {
	require_once __DIR__ . '/class-perdita-switch-admin.php';
	new Perdita_Switch_Admin();
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/class-perdita-switch-cli.php';
	WP_CLI::add_command( 'perdita switch', 'Perdita_Switch_CLI' );
}
