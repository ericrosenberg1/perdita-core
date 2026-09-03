<?php
/**
 * Caching module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only, no logic. The heavy classes named below load lazily, and only
 * when the module is enabled and the request matches one of its contexts.
 *
 * The 'always' context is deliberate. On the front end the class serves and
 * captures cached pages. In the admin it registers the purge hooks (save_post,
 * comment changes, theme switch, design settings write) so edits clear the
 * cache right away.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'caching',
	'label'       => __( 'Caching and performance', 'perdita-core' ),
	'description' => __( 'Simple page caching with smart clearing, plus browser-cache headers. Skips carts, logged-in users, and forms automatically.', 'perdita-core' ),
	'group'       => 'performance',
	'default'     => false,
	'contexts'    => array( 'always' ),
	'class'       => 'Perdita_Caching',
	'file'        => 'class-perdita-caching.php',
	'admin_class' => 'Perdita_Caching_Admin',
	'admin_file'  => 'class-perdita-caching-admin.php',
	'activate'    => array( 'Perdita_Caching', 'install' ),
);
