<?php
/**
 * Related posts module descriptor.
 *
 * This file is included on every request for discovery, so it stays tiny:
 * just the returned array, no logic. The class file loads lazily, only when
 * the module is enabled and the context matches.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'related_posts',
	'label'       => __( 'Related posts', 'perdita-core' ),
	'description' => __( 'Show related posts after each article, matched by shared categories and tags. No external service, no data leaves the site.', 'perdita-core' ),
	'group'       => 'content',
	'default'     => false,
	'contexts'    => array( 'front', 'admin' ),
	'class'       => 'Perdita_Related_Posts',
	'file'        => 'class-perdita-related-posts.php',
	'admin_class' => 'Perdita_Related_Posts_Admin',
	'admin_file'  => 'class-perdita-related-posts-admin.php',
);
