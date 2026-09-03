<?php
/**
 * Sales module descriptor.
 *
 * This file is included on every request for discovery, so it stays tiny: just
 * the returned array, no logic. The heavy classes named below load lazily, only
 * when the module is enabled and the context matches.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'sales',
	'label'       => __( 'Sales (digital and physical products)', 'perdita-core' ),
	'description' => __( 'A lightweight store for digital downloads and simple physical products, with Stripe checkout.', 'perdita-core' ),
	'group'       => 'commerce',
	'default'     => false,
	'contexts'    => array( 'front', 'admin', 'rest' ),
	'class'       => 'Perdita_Sales',
	'file'        => 'class-perdita-sales.php',
	'admin_class' => 'Perdita_Sales_Admin',
	'admin_file'  => 'class-perdita-sales-admin.php',
	'activate'    => array( 'Perdita_Sales', 'install' ),
);
