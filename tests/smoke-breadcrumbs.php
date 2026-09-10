<?php
/**
 * Breadcrumbs module checks.
 *
 * A fragment of the smoke suite: tests/smoke.php requires every
 * tests/smoke-*.php in its own scope right before printing the summary, so
 * $ok() and the $pass/$fail counters below are the ones defined there.
 *
 * The trail is asserted four ways: a post inside a nested category, a nested
 * page, a term archive, and a search. The last two need a query to look at, so
 * the global WP_Query is swapped for a built one and put straight back.
 *
 * Everything is restored: the settings option, the global query, and every
 * temporary post and term.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once PERDITA_CORE_DIR . 'inc/modules/breadcrumbs/class-perdita-breadcrumbs.php';

$__bc_orig_option = get_option( Perdita_Breadcrumbs::OPTION );
delete_option( Perdita_Breadcrumbs::OPTION );

/* ------------------------------------------------------------------ */
/* 1. Descriptor and defaults                                          */
/* ------------------------------------------------------------------ */

$__bc_desc = include PERDITA_CORE_DIR . 'inc/modules/breadcrumbs/module.php';
$ok( is_array( $__bc_desc ) && 'breadcrumbs' === $__bc_desc['id'], 'breadcrumbs: module.php returns a descriptor with id breadcrumbs' );
$ok( 'seo' === $__bc_desc['group'] && true === $__bc_desc['default'], 'breadcrumbs: it lands in the seo group and is on out of the box' );
$ok(
	array() === array_diff( array( 'front', 'rest', 'admin' ), (array) $__bc_desc['contexts'] ),
	'breadcrumbs: rest is in the contexts, because the block editor renders the block through the server-side-render route'
);
$ok( file_exists( PERDITA_CORE_DIR . 'inc/modules/breadcrumbs/block.json' ), 'breadcrumbs: the block has a block.json beside the class' );

$__bc_block_json = json_decode( (string) file_get_contents( PERDITA_CORE_DIR . 'inc/modules/breadcrumbs/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own file from disk in a CLI harness.
$ok( is_array( $__bc_block_json ) && Perdita_Breadcrumbs::BLOCK === ( $__bc_block_json['name'] ?? '' ), 'breadcrumbs: block.json declares the perdita/breadcrumbs block' );
$ok( Perdita_Breadcrumbs::EDITOR_HANDLE === ( $__bc_block_json['editorScript'] ?? '' ), 'breadcrumbs: block.json names the inline editor script handle the class registers' );

$__bc_defaults = Perdita_Breadcrumbs::defaults();
$ok( '›' === $__bc_defaults['separator'], 'breadcrumbs: the default separator is a literal character, not an HTML entity that would be escaped on output' );
$ok( false === $__bc_defaults['show_on_front'] && 'none' === $__bc_defaults['auto_insert'], 'breadcrumbs: nothing is inserted anywhere until it is asked for' );

$__bc_clean = Perdita_Breadcrumbs::sanitize( array( 'auto_insert' => 'wherever', 'home_label' => '   ' ) );
$ok( 'none' === $__bc_clean['auto_insert'], 'breadcrumbs: sanitize() falls an unknown placement back to none' );
$ok( $__bc_defaults['home_label'] === $__bc_clean['home_label'], 'breadcrumbs: sanitize() refuses to store a blank home label' );

/* ------------------------------------------------------------------ */
/* 2. Fixtures                                                         */
/* ------------------------------------------------------------------ */

$__bc_parent_term = wp_insert_term( 'Perdita BC Parent', 'category' );
$__bc_parent_term = is_array( $__bc_parent_term ) ? (int) $__bc_parent_term['term_id'] : 0;
$__bc_child_term  = $__bc_parent_term ? wp_insert_term( 'Perdita BC Child', 'category', array( 'parent' => $__bc_parent_term ) ) : 0;
$__bc_child_term  = is_array( $__bc_child_term ) ? (int) $__bc_child_term['term_id'] : 0;

$__bc_post_id = wp_insert_post(
	array(
		'post_title'   => 'Perdita BC Smoke Post',
		'post_content' => 'x',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
if ( $__bc_child_term ) {
	wp_set_post_terms( $__bc_post_id, array( $__bc_child_term ), 'category' );
}

$__bc_parent_page = wp_insert_post(
	array(
		'post_title'  => 'Perdita BC Parent Page',
		'post_status' => 'publish',
		'post_type'   => 'page',
	)
);
$__bc_child_page  = wp_insert_post(
	array(
		'post_title'  => 'Perdita BC Child Page',
		'post_status' => 'publish',
		'post_type'   => 'page',
		'post_parent' => $__bc_parent_page,
	)
);

/**
 * The titles of a trail, so an assertion reads like the trail does.
 *
 * @param array $trail Trail items.
 * @return string[]
 */
$__bc_titles = function ( array $trail ) {
	return array_map(
		function ( $item ) {
			return isset( $item['title'] ) ? (string) $item['title'] : '';
		},
		$trail
	);
};

/* ------------------------------------------------------------------ */
/* 3. A post inside a nested category                                  */
/* ------------------------------------------------------------------ */

$__bc_post_trail = Perdita_Breadcrumbs::trail( $__bc_post_id );
if ( $__bc_child_term ) {
	$ok(
		array( 'Home', 'Perdita BC Parent', 'Perdita BC Child', 'Perdita BC Smoke Post' ) === $__bc_titles( $__bc_post_trail ),
		'breadcrumbs: a post trail is Home, the whole category chain including parents, then the post'
	);
	$ok( (string) get_term_link( $__bc_parent_term, 'category' ) === $__bc_post_trail[1]['url'], 'breadcrumbs: each category in the chain links to its own archive' );
} else {
	$ok( true, 'breadcrumbs: nested category trail SKIPPED (fixture terms could not be created)' );
	$ok( true, 'breadcrumbs: category links SKIPPED (fixture terms could not be created)' );
}
$ok( home_url( '/' ) === $__bc_post_trail[0]['url'], 'breadcrumbs: the first item is the home page and it is a link' );
$ok( null === $__bc_post_trail[ count( $__bc_post_trail ) - 1 ]['url'], 'breadcrumbs: the last item never carries a url, because it is the thing being looked at' );
$ok( Perdita_Breadcrumbs::trail( $__bc_post_id ) === Perdita_Breadcrumbs::trail( get_post( $__bc_post_id ) ), 'breadcrumbs: trail() takes a post id or a post object and answers the same either way' );

/* ------------------------------------------------------------------ */
/* 4. A nested page                                                    */
/* ------------------------------------------------------------------ */

$__bc_page_trail = Perdita_Breadcrumbs::trail( $__bc_child_page );
$ok(
	array( 'Home', 'Perdita BC Parent Page', 'Perdita BC Child Page' ) === $__bc_titles( $__bc_page_trail ),
	'breadcrumbs: a page trail walks its ancestors, top down'
);
$ok( (string) get_permalink( $__bc_parent_page ) === $__bc_page_trail[1]['url'], 'breadcrumbs: the ancestor page is a link to itself' );

/* ------------------------------------------------------------------ */
/* 5. A term archive and a search, driven off a swapped global query   */
/* ------------------------------------------------------------------ */

$__bc_orig_query     = $GLOBALS['wp_query'];
$__bc_orig_the_query = $GLOBALS['wp_the_query'];

if ( $__bc_child_term ) {
	$GLOBALS['wp_query'] = new WP_Query( array( 'cat' => $__bc_child_term ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a test harness standing in a query for the duration of one assertion, restored immediately below.
	$__bc_term_trail     = Perdita_Breadcrumbs::trail();
	$GLOBALS['wp_query'] = $__bc_orig_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- putting the real query straight back.

	$ok(
		array( 'Home', 'Perdita BC Parent', 'Perdita BC Child' ) === $__bc_titles( $__bc_term_trail ),
		'breadcrumbs: a term archive trail includes the term parents above it'
	);
	$ok( null === $__bc_term_trail[2]['url'] && '' !== (string) $__bc_term_trail[1]['url'], 'breadcrumbs: on a term archive the term itself is the current item and its parent is a link' );
} else {
	$ok( true, 'breadcrumbs: term archive trail SKIPPED (fixture terms could not be created)' );
	$ok( true, 'breadcrumbs: term archive links SKIPPED (fixture terms could not be created)' );
}

$GLOBALS['wp_query'] = new WP_Query( array( 's' => 'perdita breadcrumb probe' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- same pattern, restored immediately below.
$__bc_search_trail   = Perdita_Breadcrumbs::trail();
$GLOBALS['wp_query'] = $__bc_orig_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- putting the real query straight back.
$GLOBALS['wp_the_query'] = $__bc_orig_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- and the main-query reference with it.

$ok( 2 === count( $__bc_search_trail ), 'breadcrumbs: a search trail is Home and the search itself' );
$ok( false !== strpos( (string) $__bc_search_trail[1]['title'], 'perdita breadcrumb probe' ), 'breadcrumbs: the search item names what was searched for' );
$ok( null === $__bc_search_trail[1]['url'], 'breadcrumbs: the search item is the current item' );

/* ------------------------------------------------------------------ */
/* 6. Rendering                                                        */
/* ------------------------------------------------------------------ */

$__bc_html = Perdita_Breadcrumbs::render( array( 'post' => $__bc_post_id ) );
$ok( false !== strpos( $__bc_html, '<nav class="perdita-breadcrumbs" aria-label="Breadcrumb">' ), 'breadcrumbs: the markup is a nav labelled Breadcrumb' );
$ok( false !== strpos( $__bc_html, '<ol>' ) && substr_count( $__bc_html, '<li>' ) === count( $__bc_post_trail ), 'breadcrumbs: it is an ordered list with one item per step' );
$ok( 1 === substr_count( $__bc_html, 'aria-current="page"' ), 'breadcrumbs: exactly one item is marked as the current page' );
$ok( false !== strpos( $__bc_html, 'aria-current="page">Perdita BC Smoke Post</span>' ), 'breadcrumbs: the current item is the post, and it is not a link' );

$__bc_html2 = Perdita_Breadcrumbs::render( array( 'post' => $__bc_post_id ) );
$ok( substr_count( $__bc_html . $__bc_html2, 'perdita-breadcrumbs-css' ) <= 1, 'breadcrumbs: the inline CSS is printed at most once per request' );

$__bc_no_current = Perdita_Breadcrumbs::render( array( 'post' => $__bc_post_id, 'show_current' => false ) );
$ok( false === strpos( $__bc_no_current, 'Perdita BC Smoke Post' ), 'breadcrumbs: show_current false drops the current item from the markup' );
$ok( $__bc_post_trail === Perdita_Breadcrumbs::trail( $__bc_post_id ), 'breadcrumbs: and trail() is untouched by it, so Perdita Pro schema still gets the whole chain' );

$__bc_prefixed = Perdita_Breadcrumbs::render( array( 'post' => $__bc_post_id, 'prefix' => 'You are here', 'separator' => '/' ) );
$ok( false !== strpos( $__bc_prefixed, 'You are here' ) && false !== strpos( $__bc_prefixed, '>/</span>' ), 'breadcrumbs: the prefix and separator overrides reach the markup' );

$__bc_escape_post = wp_insert_post(
	array(
		'post_title'  => 'Perdita BC <script>alert(1)</script>',
		'post_status' => 'publish',
		'post_type'   => 'page',
	)
);
$__bc_escaped     = Perdita_Breadcrumbs::render( array( 'post' => $__bc_escape_post ) );
$ok( false === strpos( $__bc_escaped, '<script>' ), 'breadcrumbs: a title with markup in it is escaped on output' );
wp_delete_post( $__bc_escape_post, true );

$ok( function_exists( 'perdita_breadcrumbs' ), 'breadcrumbs: the perdita_breadcrumbs() template tag is defined' );
$ok( Perdita_Breadcrumbs::render( array( 'post' => $__bc_post_id ) ) === perdita_breadcrumbs( array( 'post' => $__bc_post_id ), false ), 'breadcrumbs: the template tag returns the markup without printing it when asked not to' );
ob_start();
$__bc_returned = perdita_breadcrumbs( array( 'post' => $__bc_post_id ) );
$__bc_printed  = ob_get_clean();
$ok( '' !== $__bc_printed && $__bc_printed === $__bc_returned, 'breadcrumbs: the template tag prints by default and returns the same string' );

/* ------------------------------------------------------------------ */
/* 7. Shortcode and block, when the module is actually booted          */
/* ------------------------------------------------------------------ */

if ( perdita_core()->modules->is_enabled( 'breadcrumbs' ) ) {
	$ok( shortcode_exists( 'perdita_breadcrumbs' ), 'breadcrumbs: the [perdita_breadcrumbs] shortcode is registered from inside the module' );
	$ok(
		class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( Perdita_Breadcrumbs::BLOCK ),
		'breadcrumbs: the perdita/breadcrumbs block is registered'
	);
	$__bc_block = WP_Block_Type_Registry::get_instance()->get_registered( Perdita_Breadcrumbs::BLOCK );
	$ok( $__bc_block && is_callable( $__bc_block->render_callback ), 'breadcrumbs: the block renders server side, so the editor and the front end cannot drift apart' );
} else {
	$ok( true, 'breadcrumbs: shortcode registration SKIPPED (module switched off on this site)' );
	$ok( true, 'breadcrumbs: block registration SKIPPED (module switched off on this site)' );
	$ok( true, 'breadcrumbs: server-side render SKIPPED (module switched off on this site)' );
}

/* ------------------------------------------------------------------ */
/* Cleanup                                                             */
/* ------------------------------------------------------------------ */

wp_delete_post( $__bc_post_id, true );
wp_delete_post( $__bc_child_page, true );
wp_delete_post( $__bc_parent_page, true );
if ( $__bc_child_term ) {
	wp_delete_term( $__bc_child_term, 'category' );
}
if ( $__bc_parent_term ) {
	wp_delete_term( $__bc_parent_term, 'category' );
}
if ( false === $__bc_orig_option ) {
	delete_option( Perdita_Breadcrumbs::OPTION );
} else {
	update_option( Perdita_Breadcrumbs::OPTION, $__bc_orig_option );
}
