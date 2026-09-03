<?php
/**
 * Analytics module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The heavy classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'analytics',
	'label'       => 'Analytics and consent',
	'description' => 'Google Analytics 4 with a cookie-consent banner that gates tracking until the visitor agrees.',
	'group'       => 'infrastructure',
	'default'     => false,
	'contexts'    => array( 'front', 'admin' ),
	'class'       => 'Perdita_Analytics',
	'file'        => 'class-perdita-analytics.php',
	'admin_class' => 'Perdita_Analytics_Admin',
	'admin_file'  => 'class-perdita-analytics-admin.php',
);
