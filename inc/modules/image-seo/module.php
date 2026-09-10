<?php
/**
 * Image SEO module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * 'admin' runs the batch filler and the settings screen, 'rest' catches the
 * block editor's uploads (which never touch is_admin()), and 'front' serves
 * /image-sitemap.xml and adds it to robots.txt.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'image-seo',
	'label'       => __( 'Image SEO', 'perdita-core' ),
	'description' => __( 'Fill in missing alt text from the filename as images are uploaded, backfill the ones already in the library, and publish an image sitemap.', 'perdita-core' ),
	'group'       => 'seo',
	'default'     => false,
	'contexts'    => array( 'admin', 'rest', 'front' ),
	'class'       => 'Perdita_Image_SEO',
	'file'        => 'class-perdita-image-seo.php',
	'admin_class' => 'Perdita_Image_SEO_Admin',
	'admin_file'  => 'class-perdita-image-seo-admin.php',
);
