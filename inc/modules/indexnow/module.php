<?php
/**
 * IndexNow module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * Every context but 'always' is listed on purpose. 'front' serves the
 * /<key>.txt verification file and catches WP-CLI publishes (WP-CLI is
 * neither admin nor REST nor cron, so it boots as 'front'). 'admin' and
 * 'rest' catch the classic editor and the block editor saving a post.
 * 'cron' runs the debounced submission itself.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'indexnow',
	'label'       => __( 'IndexNow', 'perdita-core' ),
	'description' => __( 'Tell Bing, Yandex, and every other IndexNow search engine the moment a post is published, updated, or or removed. Nothing to sign up for.', 'perdita-core' ),
	'group'       => 'seo',
	'default'     => true,
	'contexts'    => array( 'front', 'admin', 'rest', 'cron' ),
	'class'       => 'Perdita_IndexNow',
	'file'        => 'class-perdita-indexnow.php',
	'admin_class' => 'Perdita_IndexNow_Admin',
	'admin_file'  => 'class-perdita-indexnow-admin.php',
);
