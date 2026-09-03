<?php
/**
 * Subscriptions module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The heavy classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'subscriptions',
	'label'       => __( 'Email subscriptions', 'perdita-core' ),
	'description' => __( 'Let visitors subscribe by email and get notified when you publish a new post.', 'perdita-core' ),
	'group'       => 'content',
	'default'     => false,
	'contexts'    => array( 'always' ),
	'class'       => 'Perdita_Subscriptions',
	'file'        => 'class-perdita-subscriptions.php',
	'admin_class' => 'Perdita_Subscriptions_Admin',
	'admin_file'  => 'class-perdita-subscriptions-admin.php',
	'activate'    => array( 'Perdita_Subscriptions', 'install' ),
);
