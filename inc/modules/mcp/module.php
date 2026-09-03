<?php
/**
 * MCP Server module descriptor.
 *
 * Included on every request for discovery, so this stays tiny: it returns
 * metadata only. The heavy classes named below load lazily, and only when the
 * module is enabled and the request matches one of its contexts.
 *
 * Unlike every other admin-only module in this codebase, this one needs
 * 'rest' in contexts, not just 'admin': the whole point of the module is a
 * public REST endpoint (/wp-json/perdita/v1/mcp) that an external AI client
 * calls directly, with no wp-admin page load involved at all. See
 * Perdita_Modules::in_context() / Perdita_Modules::context() for how the
 * REST_REQUEST constant drives that context detection. Leaving 'rest' out
 * here would mean the endpoint 404s (route never registered) even after an
 * admin enables the module, which would be a confusing, silent failure.
 *
 * 'front' is ALSO required, on top of 'admin' and 'rest', as of the Phase 2
 * OAuth authorization server (class-perdita-mcp-oauth.php): the two
 * .well-known metadata documents (RFC8414/RFC9728) and the interactive
 * /authorize consent screen are not REST routes at all (register_rest_route()
 * cannot produce a bare /.well-known/ path, and /authorize is a browser-
 * navigated HTML page, not a JSON API a client fetches), so they only ever
 * run on a normal front-end request. Without 'front' here, Perdita_MCP_OAuth
 * would never boot on the very request types its own rewrite rule targets,
 * the same class of silent-404 bug 'rest' was added to prevent for the JSON-
 * RPC endpoint itself.
 *
 * Off by default: this exposes a content-editing surface to the public
 * internet (gated by a bearer token or, as of Phase 2, an OAuth-issued
 * token), so it is opt-in rather than a default a site owner has to notice
 * and turn off.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'mcp',
	'label'       => __( 'MCP Server (AI Assistant Connector)', 'perdita-core' ),
	'description' => __( 'Lets Claude, ChatGPT, or any MCP-compatible AI client manage this site (posts, pages, forms) over a secure API, instead of only through wp-admin.', 'perdita-core' ),
	'group'       => 'infrastructure',
	'default'     => false,
	'contexts'    => array( 'admin', 'rest', 'front' ),
	'class'       => 'Perdita_MCP',
	'file'        => 'class-perdita-mcp.php',
	'admin_class' => 'Perdita_MCP_Admin',
	'admin_file'  => 'class-perdita-mcp-admin.php',
	// Perdita_Modules::activate() only require_once's this descriptor's own
	// 'file' (class-perdita-mcp.php) before invoking 'activate', so the
	// OAuth class file is not guaranteed loaded yet at this point (its
	// require_once normally happens inside Perdita_MCP's constructor, which
	// a static activate-callback call never runs). The closure below loads
	// it explicitly first, then calls Perdita_MCP_OAuth::activate() to
	// register the rewrite rules and flush them, exactly like
	// Perdita_Backups' 'activate' => array( 'Perdita_Backups', 'install' )
	// pattern, just with one extra require for the second class.
	'activate'    => function ( $core ) {
		require_once __DIR__ . '/class-perdita-mcp-oauth.php';
		Perdita_MCP_OAuth::activate( $core );
	},
);
