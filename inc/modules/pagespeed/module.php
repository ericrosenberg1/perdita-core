<?php
/**
 * PageSpeed Insights module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The heavy classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * Admin-only on purpose: this module reads Google's public PageSpeed
 * Insights API about the site's own public pages and shows the result in
 * wp-admin. There is nothing to output on the front end, so 'front' is left
 * out of contexts to keep a front-end request from ever touching this code.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'pagespeed',
	'label'       => __( 'PageSpeed Insights', 'perdita-core' ),
	'description' => __( 'Core Web Vitals and a performance score for your homepage, straight from Google PageSpeed Insights. See a toggle\'s real effect instead of guessing.', 'perdita-core' ),
	'group'       => 'performance',
	'default'     => false,
	'contexts'    => array( 'admin' ),
	'class'       => 'Perdita_Pagespeed',
	'file'        => 'class-perdita-pagespeed.php',
	'admin_class' => 'Perdita_Pagespeed_Admin',
	'admin_file'  => 'class-perdita-pagespeed-admin.php',
);
