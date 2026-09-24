<?php
/**
 * MCP SEO field checks.
 *
 * A fragment of the smoke suite: tests/smoke.php requires every
 * tests/smoke-*.php in its own scope right before printing the summary, so
 * $ok() and the $pass/$fail counters below are the ones defined there.
 *
 * The MCP server never stores an SEO field itself. create_post and update_post
 * accept them, sanitize them, and fire perdita_seo_update_post_fields with
 * only the keys the caller sent; get_post merges whatever
 * perdita_seo_get_post_fields returns under a "seo" key. Perdita Pro's SEO
 * module is the listener on both. These assertions stand in for it with a test
 * listener, so the contract holds whether or not Pro is installed.
 *
 * The server is built without its constructor, so nothing here registers a
 * second set of REST routes for the rest of the suite.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp.php';

$__ms_orig_user = get_current_user_id();
wp_set_current_user( 1 );

$__ms_keys = array( 'seo_title', 'seo_description', 'focus_keyphrase', 'canonical', 'noindex', 'og_title', 'og_description', 'schema_type' );

/* ------------------------------------------------------------------ */
/* 1. Tool schemas                                                     */
/* ------------------------------------------------------------------ */

$__ms_defs  = Perdita_MCP::tool_definitions( perdita_core() );
$__ms_byname = array();
foreach ( $__ms_defs as $__ms_def ) {
	$__ms_byname[ $__ms_def['name'] ] = $__ms_def;
}

foreach ( array( 'create_post', 'update_post' ) as $__ms_tool ) {
	$__ms_props = $__ms_byname[ $__ms_tool ]['inputSchema']['properties'] ?? array();
	$ok(
		array() === array_diff( $__ms_keys, array_keys( $__ms_props ) ),
		'mcp seo: ' . $__ms_tool . ' accepts all eight optional SEO fields'
	);
	$ok(
		isset( $__ms_props['title'] ) || isset( $__ms_props['id'] ),
		'mcp seo: ' . $__ms_tool . ' still carries the arguments it had before the SEO fields were added'
	);
	$ok(
		'boolean' === ( $__ms_props['noindex']['type'] ?? '' ) && 'uri' === ( $__ms_props['canonical']['format'] ?? '' ),
		'mcp seo: ' . $__ms_tool . ' types noindex as a boolean and canonical as a URI'
	);
	$ok(
		array() === array_intersect( $__ms_keys, (array) ( $__ms_byname[ $__ms_tool ]['inputSchema']['required'] ?? array() ) ),
		'mcp seo: none of the SEO fields is required on ' . $__ms_tool
	);
	$ok(
		false !== strpos( (string) $__ms_byname[ $__ms_tool ]['description'], 'Perdita Pro' ),
		'mcp seo: the ' . $__ms_tool . ' description says the SEO fields need Perdita Pro'
	);
}

$ok( false !== strpos( (string) ( $__ms_byname['get_post']['description'] ?? '' ), 'Perdita Pro' ), 'mcp seo: the get_post description says the same about the seo key it returns' );

// Everything the old schema promised is untouched: the enum on status, the
// closed object, and the required arguments.
$ok( array( 'title', 'content' ) === ( $__ms_byname['create_post']['inputSchema']['required'] ?? array() ), 'mcp seo: create_post still requires title and content, and nothing else' );
$ok( array( 'id' ) === ( $__ms_byname['update_post']['inputSchema']['required'] ?? array() ), 'mcp seo: update_post still requires only id' );
$ok( false === ( $__ms_byname['update_post']['inputSchema']['additionalProperties'] ?? true ), 'mcp seo: update_post still rejects arguments outside its schema' );
$ok( array( 'draft', 'publish', 'pending' ) === ( $__ms_byname['update_post']['inputSchema']['properties']['status']['enum'] ?? array() ), 'mcp seo: the status enum is unchanged' );

/* ------------------------------------------------------------------ */
/* 2. update_post fires the action with sanitized fields               */
/* ------------------------------------------------------------------ */

$__ms_mcp    = ( new ReflectionClass( 'Perdita_MCP' ) )->newInstanceWithoutConstructor();
$__ms_ref    = new ReflectionClass( 'Perdita_MCP' );
$__ms_update = $__ms_ref->getMethod( 'tool_update_post' );
$__ms_create = $__ms_ref->getMethod( 'tool_create_post' );
$__ms_get    = $__ms_ref->getMethod( 'tool_get_post' );
if ( PHP_VERSION_ID < 80100 ) {
	$__ms_update->setAccessible( true );
	$__ms_create->setAccessible( true );
	$__ms_get->setAccessible( true );
}

$__ms_seen     = array();
$__ms_listener = function ( $post_id, $fields ) use ( &$__ms_seen ) {
	$__ms_seen[] = array(
		'post_id' => $post_id,
		'fields'  => $fields,
	);
};
add_action( 'perdita_seo_update_post_fields', $__ms_listener, 10, 2 );

$__ms_post_id = wp_insert_post(
	array(
		'post_title'   => 'Perdita MCP SEO Smoke Post',
		'post_content' => 'x',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

$__ms_result = $__ms_update->invoke(
	$__ms_mcp,
	array(
		'id'              => $__ms_post_id,
		'title'           => 'Perdita MCP SEO Smoke Post edited',
		'seo_title'       => "  A <b>bold</b> title\n",
		'seo_description' => 'Why this page exists.',
		'focus_keyphrase' => 'perdita breadcrumbs',
		'canonical'       => 'https://example.com/canonical/?a=1&b=2',
		'noindex'         => 1,
		'schema_type'     => 'Article',
	)
);

$ok( false === ( $__ms_result['isError'] ?? true ), 'mcp seo: update_post with SEO fields succeeds' );
$ok( 'Perdita MCP SEO Smoke Post edited' === get_post_field( 'post_title', $__ms_post_id ), 'mcp seo: the ordinary post write still happens alongside them' );
$ok( 1 === count( $__ms_seen ), 'mcp seo: perdita_seo_update_post_fields fires exactly once for the call' );

$__ms_fired = $__ms_seen ? $__ms_seen[0] : array( 'post_id' => 0, 'fields' => array() );
$ok( (int) $__ms_post_id === $__ms_fired['post_id'] && is_int( $__ms_fired['post_id'] ), 'mcp seo: the action carries the post id as an integer' );
$ok( 'A bold title' === ( $__ms_fired['fields']['seo_title'] ?? '' ), 'mcp seo: a string field arrives through sanitize_text_field, tags and stray whitespace gone' );
$ok( 'https://example.com/canonical/?a=1&b=2' === ( $__ms_fired['fields']['canonical'] ?? '' ), 'mcp seo: canonical arrives through esc_url_raw, so it is a storable URL and not display-escaped' );
$ok( true === ( $__ms_fired['fields']['noindex'] ?? null ), 'mcp seo: noindex is cast to a real boolean' );
$ok( ! array_key_exists( 'og_title', $__ms_fired['fields'] ) && ! array_key_exists( 'og_description', $__ms_fired['fields'] ), 'mcp seo: fields the caller left out are absent, so a listener can tell "clear this" from "leave it alone"' );
$ok( array() === array_diff( array_keys( $__ms_fired['fields'] ), $__ms_keys ), 'mcp seo: nothing but the recognized SEO keys reaches the listener' );

// An empty string is a value, not an omission.
$__ms_seen = array();
$__ms_update->invoke( $__ms_mcp, array( 'id' => $__ms_post_id, 'seo_description' => '' ) );
$ok( 1 === count( $__ms_seen ) && array_key_exists( 'seo_description', $__ms_seen[0]['fields'] ) && '' === $__ms_seen[0]['fields']['seo_description'], 'mcp seo: an SEO-only update works, and an empty string is passed through as a deliberate clear' );

// A call with nothing at all in it is still an error, as it always was.
$__ms_seen  = array();
$__ms_empty = $__ms_update->invoke( $__ms_mcp, array( 'id' => $__ms_post_id ) );
$ok( true === ( $__ms_empty['isError'] ?? false ) && array() === $__ms_seen, 'mcp seo: update_post with no fields at all is still an error and fires nothing' );

// The capability gate is untouched: a user who cannot edit the post gets
// nowhere, SEO fields or not.
$__ms_sub_id = wp_insert_user(
	array(
		'user_login' => 'perdita_smoke_mcp_seo_sub',
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
if ( ! is_wp_error( $__ms_sub_id ) ) {
	wp_set_current_user( $__ms_sub_id );
	$__ms_seen   = array();
	$__ms_denied = $__ms_update->invoke( $__ms_mcp, array( 'id' => $__ms_post_id, 'seo_title' => 'should never land' ) );
	$ok( true === ( $__ms_denied['isError'] ?? false ) && array() === $__ms_seen, 'mcp seo: a user who cannot edit the post cannot reach the SEO action either' );
	wp_set_current_user( 1 );
	wp_delete_user( $__ms_sub_id );
} else {
	$ok( true, 'mcp seo: capability gate SKIPPED (the fixture user could not be created)' );
}

/* ------------------------------------------------------------------ */
/* 3. create_post fires the same action                                */
/* ------------------------------------------------------------------ */

$__ms_seen     = array();
$__ms_created  = $__ms_create->invoke(
	$__ms_mcp,
	array(
		'title'           => 'Perdita MCP SEO Created',
		'content'         => 'x',
		'status'          => 'draft',
		'seo_title'       => 'Created title',
		'og_description'  => 'Shared blurb',
	)
);
$__ms_new_id   = $__ms_created['structuredContent']['id'] ?? 0;
$ok( $__ms_new_id > 0 && 1 === count( $__ms_seen ), 'mcp seo: create_post fires perdita_seo_update_post_fields after the post exists' );
$ok( (int) $__ms_new_id === ( $__ms_seen[0]['post_id'] ?? 0 ), 'mcp seo: it carries the id of the post it just created, not zero' );
$ok( array( 'seo_title', 'og_description' ) === array_keys( $__ms_seen[0]['fields'] ?? array() ), 'mcp seo: and only the two fields that call actually sent' );

$__ms_seen = array();
$__ms_plain = $__ms_create->invoke( $__ms_mcp, array( 'title' => 'Perdita MCP SEO Plain', 'content' => 'x', 'status' => 'draft' ) );
$__ms_plain_id = $__ms_plain['structuredContent']['id'] ?? 0;
$ok( $__ms_plain_id > 0 && array() === $__ms_seen, 'mcp seo: a create with no SEO fields fires nothing, so a site without Pro pays nothing for the feature' );

remove_action( 'perdita_seo_update_post_fields', $__ms_listener, 10 );

/* ------------------------------------------------------------------ */
/* 4. get_post merges the read filter                                  */
/* ------------------------------------------------------------------ */

$__ms_read = $__ms_get->invoke( $__ms_mcp, array( 'id' => $__ms_post_id ) );
if ( has_filter( 'perdita_seo_get_post_fields' ) ) {
	// Perdita Pro's SEO module stores these fields, so "nothing stores
	// them" is not true on this site. Its own suite covers that path.
	$ok( is_array( $__ms_read['structuredContent']['seo'] ?? null ), 'mcp seo: get_post returns the seo array a storing module supplies' );
} else {
	$ok( array() === ( $__ms_read['structuredContent']['seo'] ?? null ), 'mcp seo: get_post returns an empty seo array when nothing stores SEO fields' );
}

$__ms_reader = function ( $fields, $post_id ) use ( $__ms_post_id ) {
	if ( (int) $post_id !== (int) $__ms_post_id ) {
		return $fields;
	}
	return array(
		'seo_title' => 'Stored title',
		'noindex'   => true,
	);
};
add_filter( 'perdita_seo_get_post_fields', $__ms_reader, 10, 2 );
$__ms_read = $__ms_get->invoke( $__ms_mcp, array( 'id' => $__ms_post_id ) );
remove_filter( 'perdita_seo_get_post_fields', $__ms_reader, 10 );

$ok( 'Stored title' === ( $__ms_read['structuredContent']['seo']['seo_title'] ?? '' ), 'mcp seo: whatever perdita_seo_get_post_fields returns is merged in under the seo key' );
$ok( true === ( $__ms_read['structuredContent']['seo']['noindex'] ?? null ), 'mcp seo: including a boolean, unchanged' );
$ok( isset( $__ms_read['structuredContent']['content_raw'], $__ms_read['structuredContent']['permalink'] ), 'mcp seo: and the rest of the get_post payload is exactly as it was' );

/* ------------------------------------------------------------------ */
/* Cleanup                                                             */
/* ------------------------------------------------------------------ */

wp_delete_post( $__ms_post_id, true );
if ( $__ms_new_id ) {
	wp_delete_post( $__ms_new_id, true );
}
if ( $__ms_plain_id ) {
	wp_delete_post( $__ms_plain_id, true );
}
wp_set_current_user( $__ms_orig_user );
