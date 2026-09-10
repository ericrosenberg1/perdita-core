<?php
/**
 * Breadcrumbs module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * 'front' renders the trail. 'admin' registers the block for the editor and
 * serves the settings screen. 'rest' is needed because the block editor
 * renders the block through the server-side-render REST route, which runs
 * outside both 'front' and 'admin'.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'breadcrumbs',
	'label'       => __( 'Breadcrumbs', 'perdita-core' ),
	'description' => __( 'A breadcrumb trail for posts, pages, archives, and search, as a block, a shortcode, a template tag, or inserted automatically above the content.', 'perdita-core' ),
	'group'       => 'seo',
	'default'     => true,
	'contexts'    => array( 'front', 'rest', 'admin' ),
	'class'       => 'Perdita_Breadcrumbs',
	'file'        => 'class-perdita-breadcrumbs.php',
	'admin_class' => 'Perdita_Breadcrumbs_Admin',
	'admin_file'  => 'class-perdita-breadcrumbs-admin.php',
);
