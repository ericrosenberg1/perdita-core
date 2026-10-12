<?php
/**
 * Perdita Core smoke tests: the "describe a section" sidebar loads on the
 * post editor only.
 *
 * enqueue_block_editor_assets also fires on the block widgets screen and in
 * the Customizer. The sidebar depends on wp-edit-post, which pulls in the
 * wp-editor script, and WordPress 7.1 logs a doing_it_wrong notice on
 * widgets.php when that loads beside the widgets editor.
 *
 * Loaded by tests/smoke.php.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- section sidebar screens (tests/smoke-editor-assets.php) ---\n";

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$ea_section = perdita_core()->section;
$ea_screen  = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
$ea_loaded  = function ( $hook ) use ( $ea_section ) {
	wp_dequeue_script( 'perdita-section-editor' );
	$GLOBALS['current_screen'] = WP_Screen::get( $hook ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test sets the admin screen.
	$ea_section->editor_assets();
	return wp_script_is( 'perdita-section-editor', 'enqueued' );
};

$ok( $ea_section instanceof Perdita_Section, 'section sidebar: Perdita_Section is built' );
$ok( true === $ea_loaded( 'post' ), 'section sidebar: enqueued on the post editor' );
$ok( true === $ea_loaded( 'page' ), 'section sidebar: enqueued on the page editor (screen base is still post)' );
$ok( false === $ea_loaded( 'widgets' ), 'section sidebar: not enqueued on the block widgets screen, so wp-editor never loads beside the widgets editor' );
$ok( false === $ea_loaded( 'customize' ), 'section sidebar: not enqueued in the Customizer' );
$ok( false === $ea_loaded( 'site-editor' ), 'section sidebar: not enqueued in the site editor, which has no wp-edit-post sidebar' );

unset( $GLOBALS['current_screen'] );
$ea_section->editor_assets();
$ok( false === wp_script_is( 'perdita-section-editor', 'enqueued' ), 'section sidebar: not enqueued with no admin screen at all' );

wp_dequeue_script( 'perdita-section-editor' );
$GLOBALS['current_screen'] = $ea_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
unset( $ea_section, $ea_screen, $ea_loaded );
