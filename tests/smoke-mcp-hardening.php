<?php
/**
 * Perdita Core smoke tests: MCP and MCP OAuth hardening.
 *
 * Loaded by tests/smoke.php, after its MCP section, so $mcp_instance is the
 * suite's own Perdita_MCP. Regressions:
 *  - get_post returned the body of a password-protected post to any token.
 *  - a user demoted below the connection capability kept using the tokens
 *    they approved while they still had it.
 *  - token responses carried no Cache-Control: no-store.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- mcp hardening (tests/smoke-mcp-hardening.php) ---\n";

if ( ! isset( $mcp_instance ) || ! ( $mcp_instance instanceof Perdita_MCP ) || ! class_exists( 'Perdita_MCP_OAuth' ) ) {
	echo "SKIP  mcp hardening (the MCP section of smoke.php did not build an instance)\n";
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';
$mh_orig_user = get_current_user_id();
$mh_stale     = get_user_by( 'login', 'perdita_smoke_mh_contrib' );
if ( $mh_stale ) {
	wp_delete_user( $mh_stale->ID ); // Left behind by an interrupted run.
}
$mh_contrib = wp_insert_user(
	array(
		'user_login' => 'perdita_smoke_mh_contrib',
		'user_pass'  => wp_generate_password(),
		'role'       => 'contributor',
	)
);
$mh_post = wp_insert_post(
	array(
		'post_title'    => 'Smoke Protected Post',
		'post_content'  => 'members-only-body-text',
		'post_status'   => 'publish',
		'post_password' => 'secret-word',
		'post_author'   => 1,
	)
);
$mh_get = new ReflectionMethod( 'Perdita_MCP', 'tool_get_post' );
if ( PHP_VERSION_ID < 80100 ) {
	$mh_get->setAccessible( true );
}
wp_set_current_user( $mh_contrib );
$mh_r = $mh_get->invoke( $mcp_instance, array( 'id' => $mh_post ) );
$ok( true === ( $mh_r['isError'] ?? false ) && false === strpos( wp_json_encode( $mh_r ), 'members-only-body-text' ), 'mcp: get_post does not return a password-protected body to a user who cannot edit it' );
wp_set_current_user( 1 );
$mh_r = $mh_get->invoke( $mcp_instance, array( 'id' => $mh_post ) );
$ok( 'members-only-body-text' === ( $mh_r['structuredContent']['content_raw'] ?? '' ), 'mcp: an editor of the post still reads a password-protected body' );
wp_set_current_user( $mh_orig_user );

// Backslashes survive create_post and update_post (JSON is not slashed,
// wp_insert_post() unslashes).
$mh_create = new ReflectionMethod( 'Perdita_MCP', 'tool_create_post' );
$mh_update = new ReflectionMethod( 'Perdita_MCP', 'tool_update_post' );
if ( PHP_VERSION_ID < 80100 ) {
	$mh_create->setAccessible( true );
	$mh_update->setAccessible( true );
}
wp_set_current_user( 1 );
$mh_made = $mh_create->invoke( $mcp_instance, array( 'title' => 'Slash Smoke', 'content' => 'Path C:\\Temp\\x and \\frac{a}{b}', 'status' => 'draft' ) );
$mh_id   = (int) ( $mh_made['structuredContent']['id'] ?? 0 );
$ok( $mh_id > 0 && 'Path C:\\Temp\\x and \\frac{a}{b}' === get_post_field( 'post_content', $mh_id ), 'mcp: create_post keeps backslashes in the content' );
$mh_update->invoke( $mcp_instance, array( 'id' => $mh_id, 'content' => 'Updated C:\\Temp' ) );
clean_post_cache( $mh_id );
$ok( 'Updated C:\\Temp' === get_post_field( 'post_content', $mh_id ), 'mcp: update_post keeps backslashes in the content' );
wp_delete_post( $mh_id, true );
wp_set_current_user( $mh_orig_user );

// Demoted users.
$mh_oauth = isset( $oauth_smoke ) ? $oauth_smoke : new Perdita_MCP_OAuth( perdita_core() );
$mh_store = new ReflectionMethod( 'Perdita_MCP_OAuth', 'store_grant' );
$mh_del   = new ReflectionMethod( 'Perdita_MCP_OAuth', 'delete_grant' );
if ( PHP_VERSION_ID < 80100 ) {
	$mh_store->setAccessible( true );
	$mh_del->setAccessible( true );
}
$mh_token = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'smokedemoted';
$mh_store->invoke(
	$mh_oauth,
	$mh_token,
	array(
		'type'      => 'access',
		'user_id'   => $mh_contrib,
		'client_id' => 'smoke-client',
		'resource'  => Perdita_MCP_OAuth::resource_uri(),
		'scope'     => 'mcp',
		'expires'   => time() + HOUR_IN_SECONDS,
	)
);
$ok( $mh_contrib === $mh_oauth->validate_token( false, $mh_token ), 'mcp-oauth: a contributor\'s token resolves while they can still connect' );
( new WP_User( $mh_contrib ) )->set_role( 'subscriber' );
clean_user_cache( $mh_contrib );
$ok( false === $mh_oauth->validate_token( false, $mh_token ), 'mcp-oauth: the same token stops working once the user is demoted below the connection capability' );
$mh_del->invoke( $mh_oauth, $mh_token );

// The profile screen's Revoke control must not be a nested <form>.
require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp-admin.php';
$mh_record = new ReflectionMethod( 'Perdita_MCP_OAuth', 'record_connection' );
if ( PHP_VERSION_ID < 80100 ) {
	$mh_record->setAccessible( true );
}
( new WP_User( $mh_contrib ) )->set_role( 'contributor' );
clean_user_cache( $mh_contrib );
$mh_record->invoke( $mh_oauth, $mh_contrib, 'smoke-profile-client' );
wp_set_current_user( $mh_contrib );
$mh_admin    = ( new ReflectionClass( 'Perdita_MCP_Admin' ) )->newInstanceWithoutConstructor();
$mh_mcp_was  = perdita_core()->modules->is_enabled( 'mcp' );
$mh_core_opt = get_option( 'perdita_core' );
perdita_core()->modules->set_module_state( 'mcp', true );
ob_start();
$mh_admin->profile_connected_apps( wp_get_current_user() );
$mh_html = (string) ob_get_clean();
if ( ! $mh_mcp_was ) {
	update_option( 'perdita_core', $mh_core_opt );
	perdita_core()->modules->set_module_state( 'mcp', false );
}
if ( '' === $mh_html ) {
	echo "SKIP  mcp profile revoke markup (the MCP module is off on this site)\n";
} else {
	$ok( false === stripos( $mh_html, '<form' ) && false !== strpos( $mh_html, 'perdita_mcp_revoke_connection' ), 'mcp profile: Revoke is a nonce link, not a form nested inside profile.php\'s own form' );
}
wp_set_current_user( $mh_orig_user );
Perdita_MCP_OAuth::revoke_connection( $mh_contrib, 'smoke-profile-client' );

// no-store on token responses.
$mh_resp = new ReflectionMethod( 'Perdita_MCP_OAuth', 'token_response' );
if ( PHP_VERSION_ID < 80100 ) {
	$mh_resp->setAccessible( true );
}
$mh_headers = $mh_resp->invoke( null, array( 'access_token' => 'x' ) )->get_headers();
$ok( 'no-store' === ( $mh_headers['Cache-Control'] ?? '' ), 'mcp-oauth: token responses are marked Cache-Control: no-store' );

wp_delete_post( $mh_post, true );
wp_delete_user( $mh_contrib );
