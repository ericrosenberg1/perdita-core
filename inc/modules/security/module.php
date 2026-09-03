<?php
/**
 * Security hardening module descriptor.
 *
 * Included on every request for discovery, so it stays tiny: just the returned
 * array, no logic. The heavy classes named below load lazily, only when the
 * module is enabled and the context matches.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'security',
	'label'       => __( 'Security hardening', 'perdita-core' ),
	'description' => __( 'Login rate limiting, security headers, disable the file editor, and lock down XML-RPC and author enumeration.', 'perdita-core' ),
	'group'       => 'security',
	'default'     => false,
	// Login limiting hooks the login page, headers run on the front end, and
	// the file-editor lock has to be set during theme load for wp-admin, so
	// the module runs in every context.
	'contexts'    => array( 'always' ),
	'class'       => 'Perdita_Security',
	'file'        => 'class-perdita-security.php',
	'admin_class' => 'Perdita_Security_Admin',
	'admin_file'  => 'class-perdita-security-admin.php',
);
