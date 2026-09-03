<?php
/**
 * Search Console module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The heavy classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * Admin-only on purpose: this module reads the site owner's own Search
 * Console data and shows it in wp-admin. There is nothing to output on the
 * front end, so 'front' is left out of contexts to keep a front-end request
 * from ever touching the OAuth/API code.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'search-console',
	'label'       => __( 'Search Console', 'perdita-core' ),
	'description' => __( 'See search clicks, impressions, and top queries from Google Search Console right in wp-admin. Bring your own Google Cloud OAuth app, no shared credentials.', 'perdita-core' ),
	'group'       => 'seo',
	'default'     => false,
	'contexts'    => array( 'admin' ),
	'class'       => 'Perdita_Search_Console',
	'file'        => 'class-perdita-search-console.php',
	'admin_class' => 'Perdita_Search_Console_Admin',
	'admin_file'  => 'class-perdita-search-console-admin.php',
);
