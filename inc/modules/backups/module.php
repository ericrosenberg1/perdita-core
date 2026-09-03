<?php
/**
 * Backups module descriptor.
 *
 * Included on every request for discovery, so it stays tiny: just the returned
 * array, no logic. The heavy classes named below load lazily, only when the
 * module is enabled and the context matches (admin screens and cron runs).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'backups',
	'label'       => 'Backups',
	'description' => 'On-demand and scheduled backups of your database and files, with one-click restore. Free backups are stored locally.',
	'group'       => 'infrastructure',
	'default'     => false,
	'contexts'    => array( 'admin', 'cron' ),
	'class'       => 'Perdita_Backups',
	'file'        => 'class-perdita-backups.php',
	'admin_class' => 'Perdita_Backups_Admin',
	'admin_file'  => 'class-perdita-backups-admin.php',
	'activate'    => array( 'Perdita_Backups', 'install' ),
);
