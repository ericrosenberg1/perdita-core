<?php
/**
 * SMTP module descriptor.
 *
 * This file is included on every request for discovery, so it stays tiny: just
 * the returned array, no logic. The heavy classes named below load lazily, only
 * when the module is enabled and the context matches.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'smtp',
	'label'       => __( 'Email delivery (SMTP)', 'perdita-core' ),
	'description' => __( 'Send site email through SMTP so it reaches the inbox. Credentials are encrypted at rest.', 'perdita-core' ),
	'group'       => 'infrastructure',
	'default'     => false,
	'contexts'    => array( 'always' ),
	'class'       => 'Perdita_SMTP',
	'file'        => 'class-perdita-smtp.php',
	'admin_class' => 'Perdita_SMTP_Admin',
	'admin_file'  => 'class-perdita-smtp-admin.php',
	'activate'    => array( 'Perdita_SMTP', 'install' ),
);
