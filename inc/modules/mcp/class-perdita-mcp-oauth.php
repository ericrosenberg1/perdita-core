<?php
/**
 * MCP OAuth 2.1 authorization server (Phase 2 / Pro tier).
 *
 * WHAT THIS IS: Perdita itself acting as a spec-compliant OAuth 2.1
 * authorization server, so hosted MCP clients (claude.ai's Connectors panel,
 * ChatGPT's equivalent, or any other remote-MCP-aware client) can complete a
 * standard "one-click Connect" flow instead of an admin generating and
 * pasting a static API key. This is NOT "log in with Anthropic" or "log in
 * with OpenAI" and does not touch either company's consumer accounts in any
 * way: the identity being verified at every step is a WordPress user logging
 * into THEIR OWN site with THEIR OWN WordPress login. Anthropic/OpenAI only
 * enter the picture as the authors of MCP clients that happen to speak
 * standard OAuth 2.1 against whatever server a user points them at, exactly
 * like they'd speak it against any other self-hosted OAuth-protected API.
 * This is a fundamentally different role than
 * inc/class-perdita-openrouter-oauth.php (where Perdita is the OAuth CLIENT
 * connecting to OpenRouter's account system): here, Perdita is the
 * authorization SERVER, and the "third party" is the MCP client software,
 * not an account/identity/billing provider. See the module's docblock in
 * module.php and the project's ROADMAP for the free-tier/Pro-tier split.
 *
 * ARCHITECTURE, IN ONE PASS:
 *
 *   1. Discovery: two `.well-known` JSON documents (Authorization Server
 *      Metadata per RFC8414, Protected Resource Metadata per RFC9728), served
 *      at the literal site root via a custom rewrite rule, since
 *      register_rest_route() can only ever produce paths under the REST API
 *      base and can never serve a bare /.well-known/ path. See
 *      register_rewrite()/serve_well_known() below for exactly how.
 *
 *   2. Registration: `POST perdita/v1/oauth/register`, a normal REST route
 *      implementing RFC7591 Dynamic Client Registration. This is the
 *      pragmatic, currently-interoperable choice: the specification draft
 *      this project was built against now marks DCR "deprecated" in favor of
 *      the newer Client ID Metadata Documents (CIMD) mechanism, but CIMD
 *      requires the AUTHORIZATION SERVER to fetch and trust an arbitrary
 *      HTTPS URL supplied by an unauthenticated caller (a real SSRF surface
 *      for a WordPress site potentially living on shared/cheap hosting,
 *      explicitly flagged as a risk in the spec's own security-considerations
 *      page), and, more importantly, is not yet what real MCP clients send
 *      today. claude.ai's custom connector setup flow (documented at
 *      support.claude.com's "Getting started with custom connectors using
 *      remote MCP" article, checked while building this) offers manually
 *      pasting a pre-issued OAuth Client ID/Secret as an *optional* advanced
 *      setting, implying the default path is an automatic one, which in
 *      today's ecosystem means DCR, the mechanism essentially every existing
 *      MCP reference server and the mcp-remote bridge already implement.
 *      Building CIMD-only right now would produce a server most real clients
 *      cannot actually complete a connection against. DCR remains explicitly
 *      supported for exactly this "backwards compatibility" reason per the
 *      spec's own client-registration page, so that's what this ships.
 *
 *   3. Authorization: `GET/POST /.../oauth/authorize` (a front-end,
 *      non-REST page, see WHY FRONT CONTEXT below), the interactive step: log
 *      in as a WP user if not already, see a consent screen, approve or deny.
 *
 *   4. Token: `POST perdita/v1/oauth/token`, a normal REST route:
 *      authorization_code exchange (with PKCE verification and RFC8707
 *      resource/audience binding) and refresh_token rotation.
 *
 *   5. Validation: hooks Phase 1's `perdita_mcp_validate_oauth_token` filter
 *      (declared and documented in class-perdita-mcp.php) so
 *      Perdita_MCP::authenticate() can resolve an OAuth access token to a WP
 *      user id without that file changing at all.
 *
 *   6. Revocation: connected-clients list + per-connection revoke, rendered
 *      into the admin screen by class-perdita-mcp-admin.php.
 *
 * WHY FRONT CONTEXT: the well-known documents and the /authorize page are
 * NOT REST routes (a bare .well-known/ path is unreachable through
 * register_rest_route(), and /authorize is an interactive HTML page a
 * browser navigates to directly, not a JSON API a client fetches). Both need
 * to run on a normal front-end request, which is the 'front' module context,
 * not 'rest'. See module.php's updated `contexts` array and the "rewrite
 * rule" section below for how this is wired so it never silently 404s, the
 * exact bug Phase 1 hit once already with REST-only context timing.
 *
 * STORAGE: everything (registered clients, authorization codes, access
 * tokens, refresh tokens) lives in a single wp_options row
 * (OPTION_CLIENTS / OPTION_GRANTS below), not a dedicated $wpdb table. This
 * codebase has no existing dbDelta()/CREATE TABLE precedent anywhere
 * (checked class-perdita-backups.php and class-perdita-sales.php, the two
 * modules with the most elaborate storage needs here: both use options and
 * the filesystem, never a custom table), and a self-hosted single-site
 * install's realistic client/token volume (a handful of connected AI
 * clients per site, not a multi-tenant SaaS) never approaches the row count
 * where option-blob lookups would out-cost a real table with indexes. If a
 * site somehow accumulates enough clients/tokens for this to matter, that's
 * a straightforward future migration, not a reason to add this codebase's
 * first custom table for what is, today, a small structured list.
 *
 * Every write to one of those option blobs is a read-modify-write of the
 * whole array, so two near-simultaneous requests (a token exchange for one
 * client landing while another client refreshes, say) used to be able to
 * overwrite each other's unrelated keys. mutate_option() now serialises
 * those writes behind a short database lock (acquire_lock(), the same
 * INSERT IGNORE test-and-set WP_Upgrader::create_lock() uses) and re-reads
 * the row from the database inside the lock, so every write starts from
 * what is actually stored, never from a copy cached earlier in the request.
 *
 * SECURITY MODEL: read the class docblock of class-perdita-mcp.php first
 * (bearer token -> exact WP user -> every current_user_can() reflects that
 * user's real capabilities). Everything below is *only* about how a token
 * gets minted and to whom; once minted, an OAuth-issued token behaves
 * identically to the free-tier API key from Perdita_MCP's point of view.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_MCP_OAuth {

	/**
	 * Option storing every registered OAuth client, keyed by client_id.
	 * Shape per entry, see register_client_record().
	 */
	const OPTION_CLIENTS = 'perdita_mcp_oauth_clients';

	/**
	 * Option storing every live authorization code, access token, and
	 * refresh token, keyed by a lookup key derived from each secret's hash
	 * (never the plaintext, see hash_token()). A single option is used
	 * rather than three, since all three share the same shape (bound to
	 * user_id/client_id/resource/scope/expiry) and the same
	 * lookup-by-hash/prune-expired logic.
	 */
	const OPTION_GRANTS = 'perdita_mcp_oauth_grants';

	/**
	 * Query var this module's rewrite rule sets, read on template_redirect to
	 * detect a /.well-known/... request before WordPress's normal template
	 * loading takes over. Prefixed so it can never collide with a real query
	 * var another plugin/theme registers.
	 */
	const QUERY_VAR = 'perdita_mcp_wellknown';

	/**
	 * The front-end slug this module answers the interactive /authorize step
	 * on. Deliberately NOT under /wp-json/: this is a browser-navigated HTML
	 * page, not a JSON API endpoint, so it belongs on the normal front-end
	 * routing path (see the QUERY_VAR mechanism below), consistent with how
	 * class-perdita-sales.php's ?perdita_sales_dl=1 friendly download URL
	 * hooks template_redirect for its own non-REST, non-admin endpoint.
	 */
	const AUTHORIZE_QUERY_VAR = 'perdita_mcp_authorize';

	/**
	 * Prefix stamped on generated client_ids and access/refresh tokens, for
	 * the same at-a-glance-recognizable-in-a-log reason as
	 * Perdita_MCP::KEY_PREFIX.
	 */
	const CLIENT_ID_PREFIX     = 'perdita_mcpc_';
	const ACCESS_TOKEN_PREFIX  = 'perdita_mcpat_';
	const REFRESH_TOKEN_PREFIX = 'perdita_mcprt_';

	/**
	 * Rate limits for the two unauthenticated-to-WordPress REST routes this
	 * class registers. Neither had any throttling before: /oauth/register
	 * has permission_callback => __return_true and could otherwise grow
	 * OPTION_CLIENTS without bound from a single IP, and /oauth/token has no
	 * cap on failed grant/client-credential attempts. Kept as separate
	 * counters (own prefixes) from Perdita_MCP::RATE_LIMIT_PREFIX so
	 * hammering one of these routes never locks out a legitimate free-tier
	 * API key user sharing the same IP (e.g. a NAT'd office network).
	 */
	const REGISTER_RATE_LIMIT_PREFIX = 'perdita_mcp_oauth_register_';
	const REGISTER_RATE_LIMIT_MAX    = 10;
	const TOKEN_RATE_LIMIT_PREFIX    = 'perdita_mcp_oauth_tokenfail_';
	const TOKEN_RATE_LIMIT_MAX       = 20;
	const OAUTH_RATE_LIMIT_WINDOW    = HOUR_IN_SECONDS;

	/**
	 * Authorization code lifetime. RFC 6749 recommends a short window since a
	 * code is a one-time bearer value in a redirect URL. 90 seconds is
	 * comfortably inside the "~60-120 seconds" the task called for and the
	 * spec's "MUST expire shortly" guidance, while leaving room for the
	 * client's own token-exchange HTTP round trip.
	 */
	const CODE_TTL = 90;

	/**
	 * Access token lifetime: 1 hour. Short-lived per the security
	 * considerations page ("Authorization servers SHOULD issue short-lived
	 * access tokens to reduce the impact of leaked tokens"), long enough that
	 * a normal chat session doesn't need a mid-conversation refresh.
	 */
	const ACCESS_TOKEN_TTL = HOUR_IN_SECONDS;

	/**
	 * Refresh token lifetime: 30 days. Long-lived so a "Connect once" client
	 * doesn't need the user to re-consent every hour, but still bounded
	 * rather than eternal, so a forgotten connection eventually dies on its
	 * own even if never explicitly revoked.
	 */
	const REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * How long a pending authorization request (the bundle of client_id,
	 * redirect_uri, code_challenge, resource, scope, state validated at
	 * /authorize's GET step) survives across the login-then-return round
	 * trip, stashed server-side keyed to the browser session rather than
	 * carried in a hidden form field, so a tampered resubmission can't smuggle
	 * in a different redirect_uri/client_id than what was actually shown on
	 * the consent screen.
	 */
	const PENDING_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefix for a pending authorization request, keyed by a
	 * random per-attempt id carried in the consent form (not by user id: the
	 * same browser may not be logged in yet when the pending request is
	 * first stashed, see authorize()).
	 */
	const PENDING_PREFIX = 'perdita_mcp_oauth_pending_';

	/**
	 * The Perdita core.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor. Registers the REST routes (registration + token
	 * endpoints), the rewrite rule + template_redirect handler (well-known
	 * documents + the /authorize page), and hooks into Phase 1's token
	 * validation extension point.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_front_request' ) );

		// Phase 1's extension point (class-perdita-mcp.php authenticate()):
		// hooked from here, not by editing that file, so the free tier stays
		// fully self-contained and this addition is purely additive.
		add_filter( 'perdita_mcp_validate_oauth_token', array( $this, 'validate_token' ), 10, 2 );
	}

	/* ==========================================================
	 * Rewrite rule: making bare /.well-known/... paths reachable
	 * ========================================================== */

	/**
	 * Register the rewrite rule that maps the two well-known metadata paths,
	 * and the /authorize page, to this module's own query var, so
	 * template_redirect can recognize and answer them directly rather than
	 * falling through to WordPress's normal template hierarchy (which would
	 * 404, since none of these correspond to a real post/page/archive).
	 *
	 * WHY A REWRITE RULE AND NOT register_rest_route(): register_rest_route()
	 * unconditionally prefixes every route with the REST API base
	 * (typically /wp-json/), so it is structurally incapable of producing a
	 * bare /.well-known/oauth-authorization-server path at the site root.
	 * RFC8414/RFC9728 both require exactly that root-relative path shape
	 * (well-known URIs are always resolved against the origin, never a
	 * sub-application base), so there is no way to satisfy the spec through
	 * the REST API layer at all here: a rewrite rule that runs before
	 * WordPress's template loader is the only mechanism that can intercept
	 * an arbitrary root path.
	 *
	 * WHY THIS MUST RUN ON 'init' (not deferred further): rewrite rules must
	 * be registered on every request for WP_Rewrite to recognize them when
	 * matching the current request's path, exactly like every other
	 * add_rewrite_rule() call in WordPress (core CPT rewrites, etc). This is
	 * a normal add_action('init', ...) registration, distinct from
	 * flush_rewrite_rules() (which regenerates the compiled .htaccess/rules
	 * cache and only needs to run once, on activation, see the activate()
	 * static method below and module.php's 'activate' descriptor key).
	 */
	public function register_rewrite() {
		self::add_rules();
	}

	/**
	 * The actual add_rewrite_rule() calls, factored out so register_rewrite()
	 * (the normal per-request 'init' registration) and activate() (which
	 * needs the SAME rules registered immediately, mid-request, before its
	 * own flush_rewrite_rules() call below) share one source of truth
	 * instead of two copies that could silently drift apart.
	 */
	private static function add_rules() {
		// Root-level well-known documents.
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . self::QUERY_VAR . '=authorization-server',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . self::QUERY_VAR . '=protected-resource-root',
			'top'
		);
		// Per RFC9728's path-insertion convention referenced by the MCP
		// discovery spec: a resource at /wp-json/perdita/v1/mcp can also be
		// discovered at /.well-known/oauth-protected-resource<that same
		// path>. Registered as a literal rule (not a dynamic regex over the
		// REST base) since the MCP endpoint's path is fixed
		// (Perdita_MCP::NS . '/mcp'), not configurable per site.
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/wp-json/' . preg_quote( Perdita_MCP::NS, '/' ) . '/mcp/?$',
			'index.php?' . self::QUERY_VAR . '=protected-resource-mcp',
			'top'
		);

		// The interactive consent screen. A plain front-end slug (not under
		// .well-known, nothing in the spec requires a specific path for
		// this, only that its URL be exactly what authorization_endpoint in
		// the metadata document says).
		add_rewrite_rule(
			'^perdita-mcp-oauth/authorize/?$',
			'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Declare the query vars the rewrite rules above feed into, so WordPress
	 * actually populates them on $wp_query/get_query_var() rather than
	 * silently dropping an unrecognized query string key.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Module activation: flush rewrite rules so the paths above become
	 * reachable immediately, without requiring the site owner to separately
	 * visit Settings > Permalinks (a rewrite rule that is registered in code
	 * but not yet flushed into WordPress's compiled rules is invisible to
	 * real requests, a classic, easy-to-miss failure mode this project has
	 * already hit once with the REST/init context-timing bug, see
	 * class-perdita.php's docblock on its two boot_modules() hookups).
	 * Wired via module.php's 'activate' descriptor key, called by
	 * Perdita_Modules::activate() the moment this module is switched on (not
	 * only on theme activation).
	 *
	 * @param Perdita $core Core (unused directly, kept for the activate-callback signature Perdita_Modules::activate() invokes).
	 */
	public static function activate( $core ) {
		unset( $core );
		// register_rewrite() registers the rules on 'init' for every future
		// request; the module may be enabling mid-request right now (from
		// the admin-post handler flipping modules.mcp on), so register them
		// once immediately here too, otherwise flush_rewrite_rules() below
		// would flush a rule set that doesn't include these yet.
		global $wp_rewrite;
		if ( ! did_action( 'init' ) || empty( $wp_rewrite ) ) {
			// Too early to touch $wp_rewrite safely; init will register the
			// rules and a normal admin page load's flush (below) still runs
			// after init on this same request in the common case (module
			// enabled via admin-post, which fires after init).
			return;
		}
		self::add_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * template_redirect dispatcher: recognize a well-known metadata request
	 * or the /authorize page and answer it directly, bypassing the rest of
	 * WordPress's template loading entirely. Every branch here exits (raw
	 * JSON output or the HTML consent page), since none of these correspond
	 * to a real WP_Query result WordPress could otherwise render.
	 */
	public function maybe_handle_front_request() {
		$which = get_query_var( self::QUERY_VAR );
		if ( '' !== $which && false !== $which ) {
			$this->serve_well_known( (string) $which );
			return;
		}
		if ( get_query_var( self::AUTHORIZE_QUERY_VAR ) ) {
			$this->authorize();
		}
	}

	/**
	 * Emit one of the two metadata documents as raw JSON, per RFC8414/RFC9728.
	 *
	 * @param string $which 'authorization-server' | 'protected-resource-root' | 'protected-resource-mcp'.
	 */
	private function serve_well_known( $which ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' ); // Discovery documents are public and unauthenticated by design; a client fetches these cross-origin from its own app context.

		if ( 'authorization-server' === $which ) {
			echo wp_json_encode( $this->authorization_server_metadata() );
			exit;
		}

		if ( 'protected-resource-root' === $which || 'protected-resource-mcp' === $which ) {
			echo wp_json_encode( $this->protected_resource_metadata() );
			exit;
		}

		status_header( 404 );
		echo wp_json_encode( array( 'error' => 'not_found' ) );
		exit;
	}

	/**
	 * This server's issuer identifier: the site's own root URL, with no
	 * trailing slash (per the authorization spec's canonical-URI guidance,
	 * "implementations SHOULD consistently use the form without the trailing
	 * slash"). Every well-known document and every token's implicit audience
	 * is anchored to this one value.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * This MCP server's canonical resource URI, i.e. the exact endpoint an
	 * access token must be bound to (RFC8707 audience). Delegates to
	 * rest_url() so this can never drift from the real route Perdita_MCP
	 * registers.
	 *
	 * @return string
	 */
	public static function resource_uri() {
		return untrailingslashit( rest_url( Perdita_MCP::NS . '/mcp' ) );
	}

	/**
	 * Authorization Server Metadata (RFC8414).
	 *
	 * @return array
	 */
	private function authorization_server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => $this->authorize_url(),
			'token_endpoint'                         => rest_url( Perdita_MCP::NS . '/oauth/token' ),
			'registration_endpoint'                  => rest_url( Perdita_MCP::NS . '/oauth/register' ),
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'       => array( 'S256' ), // 'plain' deliberately never advertised, see authorize()'s PKCE validation.
			'token_endpoint_auth_methods_supported'  => array( 'none', 'client_secret_post' ),
			'scopes_supported'                       => array( 'mcp' ),
			'service_documentation'                  => 'https://modelcontextprotocol.io',
		);
	}

	/**
	 * Protected Resource Metadata (RFC9728).
	 *
	 * @return array
	 */
	private function protected_resource_metadata() {
		return array(
			'resource'              => self::resource_uri(),
			'authorization_servers' => array( self::issuer() ),
			'scopes_supported'      => array( 'mcp' ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * The authorize endpoint's public URL.
	 *
	 * @return string
	 */
	private function authorize_url() {
		return home_url( '/perdita-mcp-oauth/authorize/' );
	}

	/* ==========================================================
	 * REST routes: registration + token endpoint
	 * ========================================================== */

	/**
	 * Register the two REST-reachable OAuth routes. permission_callback is
	 * __return_true on both, matching Perdita_MCP::routes(): these are
	 * called by external, unauthenticated-to-WordPress OAuth client software,
	 * not by a logged-in browser session, so WordPress's cookie/nonce auth
	 * is not the right gate. Each handler validates its own inputs instead.
	 */
	public function routes() {
		register_rest_route(
			Perdita_MCP::NS,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_register' ),
			)
		);

		register_rest_route(
			Perdita_MCP::NS,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_token' ),
			)
		);
	}

	/**
	 * RFC7591 Dynamic Client Registration handler. Accepts a JSON body,
	 * validates redirect_uris strictly, stores the new client, and returns
	 * the client_id (+ client_secret, for confidential clients) in the
	 * RFC7591 response shape.
	 *
	 * PUBLIC vs CONFIDENTIAL: a client that declares
	 * token_endpoint_auth_method=none is registered as a PUBLIC client (no
	 * secret issued, at all: MCP's client ecosystem is overwhelmingly
	 * single-page-app/CLI-shaped software that cannot keep a secret
	 * confidential, exactly the case OAuth 2.1 designed the
	 * PKCE-without-a-client-secret path for), relying entirely on PKCE for
	 * proof of possession at the token endpoint. Any other declared
	 * auth method (or none declared at all, the RFC7591 default is
	 * client_secret_basic) gets a client_secret, hashed at rest the same way
	 * as every other bearer credential in this file.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_register( WP_REST_Request $req ) {
		$limited = Perdita_MCP::rate_limit_check( self::REGISTER_RATE_LIMIT_PREFIX, self::REGISTER_RATE_LIMIT_MAX );
		if ( is_wp_error( $limited ) ) {
			return $this->oauth_error_response( 429, 'rate_limited', $limited->get_error_message() );
		}
		// Every registration attempt counts toward the cap, not only invalid
		// ones: the abuse this guards against is unbounded storage growth
		// from well-formed registrations, not credential guessing.
		Perdita_MCP::rate_limit_record( self::REGISTER_RATE_LIMIT_PREFIX, self::OAUTH_RATE_LIMIT_WINDOW );

		$body = json_decode( $req->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $this->oauth_error_response( 400, 'invalid_client_metadata', __( 'Request body must be a JSON object.', 'perdita-core' ) );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_values( $body['redirect_uris'] ) : array();
		if ( empty( $redirect_uris ) ) {
			return $this->oauth_error_response( 400, 'invalid_redirect_uri', __( 'At least one redirect_uris entry is required.', 'perdita-core' ) );
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || ! $this->is_allowed_redirect_uri( $uri ) ) {
				return $this->oauth_error_response(
					400,
					'invalid_redirect_uri',
					sprintf(
						/* translators: %s: the rejected redirect URI. */
						__( 'redirect_uris entry "%s" is not allowed. Every redirect URI must be HTTPS, or http://localhost / http://127.0.0.1 for local development clients.', 'perdita-core' ),
						is_string( $uri ) ? $uri : wp_json_encode( $uri )
					)
				);
			}
		}

		$client_name = isset( $body['client_name'] ) && is_string( $body['client_name'] )
			? sanitize_text_field( $body['client_name'] )
			: __( '(unnamed MCP client)', 'perdita-core' );

		$grant_types = isset( $body['grant_types'] ) && is_array( $body['grant_types'] )
			? array_values( array_intersect( $body['grant_types'], array( 'authorization_code', 'refresh_token' ) ) )
			: array( 'authorization_code', 'refresh_token' );
		if ( empty( $grant_types ) ) {
			$grant_types = array( 'authorization_code' );
		}

		$auth_method = isset( $body['token_endpoint_auth_method'] ) && is_string( $body['token_endpoint_auth_method'] )
			? $body['token_endpoint_auth_method']
			: 'client_secret_post';

		$is_public = 'none' === $auth_method;

		$client_id = self::CLIENT_ID_PREFIX . wp_generate_password( 32, false );
		$secret    = '';
		$secret_hash = '';
		if ( ! $is_public ) {
			$secret      = wp_generate_password( 48, false );
			$secret_hash = self::hash_token( $secret );
		}

		// Piggyback a prune pass on every new registration (the same
		// "no dedicated cron needed" pattern all_grants() uses for expired
		// tokens): without this, OPTION_CLIENTS grows forever, since
		// registration is unauthenticated and open to anyone by design
		// (RFC7591). Only removes clients that never completed a single
		// token exchange (no reason to keep them at all) and are old
		// enough that they're not mid-flow right now.
		$record  = array(
			'client_name'   => $client_name,
			'redirect_uris' => array_map( 'esc_url_raw', $redirect_uris ),
			'grant_types'   => $grant_types,
			'auth_method'   => $is_public ? 'none' : 'client_secret_post',
			'secret_hash'   => $secret_hash,
			'created'       => time(),
		);
		$clients = self::mutate_option(
			self::OPTION_CLIENTS,
			static function ( array $clients ) use ( $client_id, $record ) {
				$clients               = self::prune_stale_clients( $clients );
				$clients[ $client_id ] = $record;
				return $clients;
			}
		);
		if ( null === $clients ) {
			return $this->storage_busy_response();
		}

		$response = array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $is_public ? 'none' : 'client_secret_post',
			'client_id_issued_at'        => time(),
		);
		if ( ! $is_public ) {
			// The plaintext secret is returned exactly once, in this
			// registration response, per RFC7591's own model, identical in
			// spirit to Perdita_MCP::generate_api_key()'s "shown once, never
			// stored reversibly" rule: only secret_hash is persisted above.
			$response['client_secret'] = $secret;
		}

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * Validate a redirect URI at registration time (RFC7591) and at
	 * authorize time (below): HTTPS always allowed; http://localhost and
	 * http://127.0.0.1 (with any port) allowed as the documented exception
	 * for locally-run MCP clients (CLI tools, local bridges like
	 * mcp-remote) that cannot obtain a TLS certificate for a loopback
	 * address, matching the authorization spec's own Communication Security
	 * requirement ("All redirect URIs MUST be either localhost or use
	 * HTTPS") verbatim, not a looser interpretation of it.
	 *
	 * @param string $uri Candidate redirect URI.
	 * @return bool
	 */
	private function is_allowed_redirect_uri( $uri ) {
		$uri = trim( (string) $uri );
		if ( '' === $uri ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return false !== filter_var( $uri, FILTER_VALIDATE_URL );
		}
		if ( 'http' === $parts['scheme'] && in_array( $parts['host'], array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return false !== filter_var( $uri, FILTER_VALIDATE_URL );
		}
		return false;
	}

	/* ==========================================================
	 * /authorize (interactive, front-end)
	 * ========================================================== */

	/**
	 * The authorize endpoint. GET shows the consent screen (forcing login
	 * first if needed); POST handles the Allow/Deny form submission. Every
	 * branch that can fail does so by redirecting back to the client with an
	 * OAuth error (per RFC 6749 4.1.2.1), except for the failures where doing
	 * so would itself be unsafe (an unregistered client_id or a redirect_uri
	 * that doesn't exactly match, see below): those render a plain WordPress
	 * error page instead of redirecting anywhere, because redirecting is
	 * exactly the open-redirect vector the security-considerations page
	 * warns about ("Authorization servers MUST validate exact redirect URIs
	 * against pre-registered values to prevent redirection attacks" and MUST
	 * NOT redirect to an untrusted URI).
	 */
	private function authorize() {
		// Every path through this dispatcher (the consent screen, the
		// fatal-error dead end, and the POST decision handler) renders on a
		// normal front-end request, where WP core's X-Frame-Options header
		// (sent only on wp-login.php/wp-admin via login_init/admin_init)
		// does not apply. Registration is open to anyone (RFC7591), so
		// without this, an attacker could register a plausible client and
		// iframe the real consent screen for a clickjacking attempt against
		// a logged-in site owner. Set once here so no render path can miss it.
		header( 'X-Frame-Options: DENY' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' === $method ) {
			$this->authorize_handle_decision();
			return;
		}

		$this->authorize_show_consent();
	}

	/**
	 * GET /authorize: validate the request parameters against a registered
	 * client, force login if needed, stash a pending-request record, and
	 * render the consent screen.
	 */
	private function authorize_show_consent() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this is the OAuth authorization request itself, arriving as a GET from the MCP client's browser redirect, not a WP admin action; there is no prior nonce to check. The Allow/Deny POST below is what carries a real nonce.
		$client_id            = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri         = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state                = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code_challenge       = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
		$resource             = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '';
		$response_type        = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
		$scope                = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'mcp';
		// phpcs:enable

		$client = $this->get_client( $client_id );

		// Unregistered client_id, or a redirect_uri that is not an EXACT
		// match to one this client actually registered: both cases render a
		// plain error page rather than redirecting anywhere, because we
		// cannot safely trust redirect_uri enough to bounce the browser to
		// it yet (that trust is exactly what "exact match against a
		// pre-registered value" establishes). This is the hard security
		// requirement from the task and the spec: no prefix/partial match,
		// full string equality only.
		if ( ! $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			$this->render_fatal_error(
				__( 'This authorization request could not be verified.', 'perdita-core' ),
				__( 'The requesting application is not recognized, or its redirect address does not exactly match what was registered. Nothing has been shared, and no redirect will be attempted.', 'perdita-core' )
			);
			return;
		}

		// From here on, redirect_uri is trusted (exact match against the
		// client's own registered list), so every further failure reports
		// back to the client via its own redirect_uri with a standard OAuth
		// error, per RFC 6749 Section 4.1.2.1, rather than a dead-end page.
		if ( 'code' !== $response_type ) {
			$this->redirect_with_error( $redirect_uri, $state, 'unsupported_response_type', __( 'Only response_type=code is supported.', 'perdita-core' ) );
			return;
		}

		// PKCE is mandatory (OAuth 2.1) and S256-only: 'plain' or a missing
		// challenge are both rejected outright, never silently downgraded.
		if ( '' === $code_challenge || 'S256' !== $code_challenge_method ) {
			$this->redirect_with_error( $redirect_uri, $state, 'invalid_request', __( 'A code_challenge with code_challenge_method=S256 is required. The plain method and missing PKCE are not supported.', 'perdita-core' ) );
			return;
		}

		// RFC8707 audience binding, checked here too (not only at the token
		// endpoint): a resource parameter naming anything other than this
		// server's own canonical MCP URI is rejected up front, so a
		// confused-deputy attempt to mint a code destined for a different
		// resource never even reaches the consent screen. An absent
		// resource is tolerated at this step (some clients only send it at
		// the token step), but the token endpoint is the strict, unskippable
		// enforcement point since that's where the actual token is minted.
		if ( '' !== $resource && self::resource_uri() !== untrailingslashit( $resource ) ) {
			$this->redirect_with_error( $redirect_uri, $state, 'invalid_target', __( 'The requested resource does not match this server.', 'perdita-core' ) );
			return;
		}

		// Require a logged-in WordPress user. wp_login_url()'s redirect_to
		// brings the browser straight back to this exact GET (full query
		// string preserved), so the consent screen renders immediately after
		// a successful login with no separate "resume" step needed.
		if ( ! is_user_logged_in() ) {
			$current_url = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// Being logged in is not enough. Client registration is open to
		// anyone (RFC 7591), so without a capability gate any account on the
		// site -- a Subscriber from an open-registration blog, a customer
		// account -- could walk this flow and mint itself a working MCP
		// bearer token. The tools then run as that user, which is a smaller
		// blast radius than an admin token but still an authenticated API
		// surface nobody granted. Gate on the capability that marks a user
		// as someone with any editorial business here at all.
		if ( ! self::current_user_can_authorize() ) {
			if ( '' === $redirect_uri ) {
				$this->render_fatal_error(
					__( 'This account cannot connect applications.', 'perdita-core' ),
					__( 'Your WordPress account does not have permission to connect an application to this site. Ask an administrator if you need access.', 'perdita-core' )
				);
				return;
			}
			$this->redirect_with_error(
				$redirect_uri,
				$state,
				'access_denied',
				__( 'This WordPress account does not have permission to connect an application to this site.', 'perdita-core' )
			);
			return;
		}

		// Stash the validated request server-side, keyed by a fresh random
		// id carried through the consent form, so the POST handler below
		// re-reads the exact values shown to the user here rather than
		// trusting anything resubmitted from the browser. This is the same
		// "don't trust round-tripped state, trust what the server stashed"
		// principle as Perdita_Search_Console_OAuth's CSRF state transient.
		$pending_id = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::PENDING_PREFIX . $pending_id,
			array(
				'client_id'      => $client_id,
				'redirect_uri'   => $redirect_uri,
				'state'          => $state,
				'code_challenge' => $code_challenge,
				'resource'       => '' !== $resource ? untrailingslashit( $resource ) : self::resource_uri(),
				'scope'          => '' !== $scope ? $scope : 'mcp',
				'user_id'        => get_current_user_id(),
			),
			self::PENDING_TTL
		);

		$this->render_consent_screen( $client, $pending_id, $redirect_uri );
	}

	/**
	 * The capability a WordPress user must hold to approve an MCP
	 * connection. Defaults to 'edit_posts' (Contributor and up), which is
	 * the lowest role that has any editorial reason to drive this server's
	 * tools at all. Filterable so a site can widen or tighten it.
	 *
	 * @return string A WordPress capability name.
	 */
	public static function required_capability() {
		/**
		 * Filter the capability required to approve an MCP OAuth connection.
		 *
		 * @param string $capability Capability name. Default 'edit_posts'.
		 */
		return (string) apply_filters( 'perdita_mcp_oauth_required_cap', 'edit_posts' );
	}

	/**
	 * Whether the current user may approve an MCP connection. Public and
	 * static so the consent screen, and a test, can ask the same question
	 * the same way.
	 *
	 * @return bool
	 */
	public static function current_user_can_authorize() {
		return current_user_can( self::required_capability() );
	}

	/**
	 * Render the consent screen: names the requesting client, states plainly
	 * what it would be able to do (reusing Phase 1's own tool list/
	 * description, per the task requirement to not invent new copy), and
	 * offers real Allow/Deny buttons, each a POST with its own nonce.
	 *
	 * @param array  $client     The registered client record (from get_client()).
	 * @param string $pending_id The pending-request id to round-trip in the form.
	 * @param string $redirect_uri The validated redirect_uri, shown to the user so they can see exactly where approval sends them (per the security-considerations page's "MUST clearly display the redirect URI hostname during authorization").
	 */
	private function render_consent_screen( array $client, $pending_id, $redirect_uri ) {
		nocache_headers();
		// Perdita_MCP::tool_definitions() is public static for exactly this
		// call: constructing a new Perdita_MCP instance here (as this used
		// to) re-runs its constructor, which unconditionally spins up a
		// second Perdita_MCP_OAuth and re-registers its hooks mid-request.
		$tools = Perdita_MCP::tool_definitions( $this->core );

		$redirect_host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$user          = wp_get_current_user();

		get_template_part( 'templates/header' );
		?>
		<main id="main" class="site-main" tabindex="-1">
		<div class="perdita-mcp-oauth-consent" style="max-width:640px;margin:48px auto;padding:32px;border:1px solid #dcdcde;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
			<h1 style="margin-top:0;font-size:1.4em;">
				<?php
				printf(
					/* translators: %s: the requesting OAuth client's name. */
					esc_html__( '%s wants to connect to this site', 'perdita-core' ),
					esc_html( $client['client_name'] )
				);
				?>
			</h1>
			<p>
				<?php
				printf(
					/* translators: %s: WordPress display name of the currently logged-in user. */
					esc_html__( 'Signed in as %s.', 'perdita-core' ),
					esc_html( $user->display_name )
				);
				?>
			</p>
			<p><?php esc_html_e( 'If you approve, this application will be able to, using your own WordPress permissions:', 'perdita-core' ); ?></p>
			<ul>
				<?php foreach ( $tools as $tool ) : ?>
					<li>
						<strong><?php echo esc_html( $tool['name'] ); ?></strong>
						<?php
						printf(
							/* translators: %s: tool description. The leading ": " separates the tool name (just before this) from its description; adjust the punctuation/spacing for your locale as needed. */
							esc_html__( ': %s', 'perdita-core' ),
							esc_html( $tool['description'] )
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the redirect URI's hostname. */
					esc_html__( 'After you decide, you will be sent back to: %s', 'perdita-core' ),
					esc_html( (string) $redirect_host )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $this->authorize_url() ); ?>" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( 'perdita_mcp_oauth_consent_' . $pending_id ); ?>
				<input type="hidden" name="pending_id" value="<?php echo esc_attr( $pending_id ); ?>" />
				<input type="hidden" name="decision" value="allow" />
				<button type="submit" class="button button-primary" style="padding:8px 20px;font-size:1em;"><?php esc_html_e( 'Allow', 'perdita-core' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( $this->authorize_url() ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'perdita_mcp_oauth_consent_' . $pending_id ); ?>
				<input type="hidden" name="pending_id" value="<?php echo esc_attr( $pending_id ); ?>" />
				<input type="hidden" name="decision" value="deny" />
				<button type="submit" class="button" style="padding:8px 20px;font-size:1em;"><?php esc_html_e( 'Deny', 'perdita-core' ); ?></button>
			</form>
		</div>
		</main>
		<?php
		get_template_part( 'templates/footer' );
		exit;
	}

	/**
	 * POST /authorize: the Allow/Deny decision. Re-reads the pending request
	 * from the server-side transient (never from resubmitted form fields for
	 * anything security-relevant), verifies the nonce and that the deciding
	 * user is the same one the pending request was stashed for, then either
	 * mints a code and redirects with it, or redirects with access_denied.
	 */
	private function authorize_handle_decision() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified explicitly below via check_admin_referer() once pending_id is known, not by the automated sniff's expected pattern.
		$pending_id = isset( $_POST['pending_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pending_id'] ) ) : '';
		$decision   = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		// phpcs:enable

		if ( '' === $pending_id || ! check_admin_referer( 'perdita_mcp_oauth_consent_' . $pending_id ) ) {
			$this->render_fatal_error(
				__( 'This authorization request could not be verified.', 'perdita-core' ),
				__( 'The confirmation could not be verified. Please start the connection again from the AI client.', 'perdita-core' )
			);
			return;
		}

		$pending = get_transient( self::PENDING_PREFIX . $pending_id );
		delete_transient( self::PENDING_PREFIX . $pending_id ); // Single-use: a resubmitted or replayed decision can never reuse the same pending request.

		if ( ! is_array( $pending ) ) {
			$this->render_fatal_error(
				__( 'This authorization request has expired.', 'perdita-core' ),
				__( 'Please start the connection again from the AI client.', 'perdita-core' )
			);
			return;
		}

		if ( ! is_user_logged_in() || get_current_user_id() !== (int) $pending['user_id'] ) {
			$this->render_fatal_error(
				__( 'This authorization request could not be verified.', 'perdita-core' ),
				__( 'The signed-in user changed since this request started. Please start the connection again.', 'perdita-core' )
			);
			return;
		}

		// Re-check the capability at the moment the code is actually minted,
		// not just when the consent screen was drawn. The two are separate
		// requests, and the pending record survives a role change (or a
		// filter change) in between. This is the last gate before a
		// credential exists, so it is the one that has to hold.
		if ( ! self::current_user_can_authorize() ) {
			$this->redirect_with_error( $pending['redirect_uri'], $pending['state'], 'access_denied', __( 'This WordPress account does not have permission to connect an application to this site.', 'perdita-core' ) );
			return;
		}

		if ( 'allow' !== $decision ) {
			$this->redirect_with_error( $pending['redirect_uri'], $pending['state'], 'access_denied', __( 'The user denied the request.', 'perdita-core' ) );
			return;
		}

		// Mint a single-use authorization code bound to every value the
		// eventual token exchange must re-validate: user, client, exact
		// redirect_uri, PKCE challenge, resource (audience), and scope.
		$code   = self::ACCESS_TOKEN_PREFIX . 'code_' . wp_generate_password( 40, false );
		$stored = $this->store_grant(
			$code,
			array(
				'type'           => 'code',
				'user_id'        => (int) $pending['user_id'],
				'client_id'      => $pending['client_id'],
				'redirect_uri'   => $pending['redirect_uri'],
				'code_challenge' => $pending['code_challenge'],
				'resource'       => $pending['resource'],
				'scope'          => $pending['scope'],
				'expires'        => time() + self::CODE_TTL,
				'used'           => false,
			)
		);
		if ( ! $stored ) {
			// The storage lock could not be taken, so no code exists to
			// redeem. temporarily_unavailable is RFC6749 4.1.2.1's code for
			// exactly this, and the user can simply approve again.
			$this->redirect_with_error( $pending['redirect_uri'], $pending['state'], 'temporarily_unavailable', __( 'The site was busy saving another connection. Please try again.', 'perdita-core' ) );
			return;
		}

		$redirect = add_query_arg(
			array_filter(
				array(
					'code'  => rawurlencode( $code ),
					'state' => '' !== $pending['state'] ? rawurlencode( $pending['state'] ) : null,
				)
			),
			$pending['redirect_uri']
		);

		// wp_safe_redirect() is the wrong call here on purpose: it only
		// allows same-site targets (home_url()/allowed_redirect_hosts) and
		// silently substitutes admin_url() otherwise, which would mean every
		// successful authorization dead-ends on wp-admin instead of handing
		// the code back to the AI client. $redirect_uri's safety was already
		// established above by exact-match validation against the client's
		// own registered list (see the comment at the top of this method's
		// caller), a stronger and more specific check than wp_safe_redirect()'s
		// generic same-site allowlist, so an intentionally cross-origin
		// wp_redirect() is correct.
		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Redirect back to the client's (already exact-match-validated)
	 * redirect_uri with a standard OAuth error per RFC 6749 Section 4.1.2.1.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $state        The client's original state, echoed back verbatim.
	 * @param string $error        RFC 6749 error code (e.g. 'access_denied', 'invalid_request').
	 * @param string $description  Human-readable detail.
	 */
	private function redirect_with_error( $redirect_uri, $state, $error, $description ) {
		$redirect = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => rawurlencode( $description ),
					'state'             => '' !== $state ? rawurlencode( $state ) : null,
				)
			),
			$redirect_uri
		);
		// wp_redirect(), not wp_safe_redirect(): see the comment in
		// authorize_handle_decision() above, same reasoning applies here.
		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Render a plain, dead-end error page for the cases where redirecting
	 * anywhere at all would be unsafe (client/redirect_uri could not be
	 * verified). No wp_die() (which can be filtered/themed unpredictably by
	 * other plugins); a minimal, dependency-free page is deliberate here
	 * since this can be reached before we trust anything about the request.
	 *
	 * @param string $title Heading.
	 * @param string $detail Body text.
	 */
	private function render_fatal_error( $title, $detail ) {
		nocache_headers();
		status_header( 400 );
		get_template_part( 'templates/header' );
		echo '<main id="main" class="site-main" tabindex="-1">';
		echo '<div style="max-width:640px;margin:48px auto;padding:32px;">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html( $detail ) . '</p>';
		// A dead-end page needs an in-content way back: the header nav may
		// not be reachable/obvious to every keyboard or screen-reader user
		// landing here directly from an AI client's failed connection attempt.
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Go to the homepage', 'perdita-core' ) . '</a></p>';
		echo '</div>';
		echo '</main>';
		get_template_part( 'templates/footer' );
		exit;
	}

	/* ==========================================================
	 * /oauth/token
	 * ========================================================== */

	/**
	 * Token endpoint: authorization_code exchange or refresh_token grant.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_token( WP_REST_Request $req ) {
		$limited = Perdita_MCP::rate_limit_check( self::TOKEN_RATE_LIMIT_PREFIX, self::TOKEN_RATE_LIMIT_MAX );
		if ( is_wp_error( $limited ) ) {
			return $this->oauth_error_response( 429, 'rate_limited', $limited->get_error_message() );
		}

		$params = $this->parse_token_request_body( $req );

		$grant_type = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		if ( 'authorization_code' === $grant_type ) {
			$response = $this->token_exchange_code( $params );
		} elseif ( 'refresh_token' === $grant_type ) {
			$response = $this->token_refresh( $params );
		} else {
			$response = $this->oauth_error_response( 400, 'unsupported_grant_type', __( 'grant_type must be authorization_code or refresh_token.', 'perdita-core' ) );
		}

		// Only failed attempts count toward the cap (mirroring
		// Perdita_MCP::authenticate_request()'s own "only guessing wrong
		// counts" rule), so a legitimate client refreshing repeatedly is
		// never throttled, only one sending bad codes/credentials/grants.
		if ( $response instanceof WP_REST_Response && $response->get_status() >= 400 ) {
			Perdita_MCP::rate_limit_record( self::TOKEN_RATE_LIMIT_PREFIX, self::OAUTH_RATE_LIMIT_WINDOW );
		}

		return $response;
	}

	/**
	 * Parse the token endpoint's request body. Real MCP clients send this as
	 * application/x-www-form-urlencoded (the RFC6749 standard shape); this
	 * also tolerates a JSON body for robustness, since WP_REST_Request's own
	 * get_params() already merges query/body/JSON transparently for us.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	private function parse_token_request_body( WP_REST_Request $req ) {
		$params = $req->get_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * grant_type=authorization_code.
	 *
	 * @param array $params Parsed request params.
	 * @return WP_REST_Response
	 */
	private function token_exchange_code( array $params ) {
		$code          = isset( $params['code'] ) ? (string) $params['code'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$code_verifier = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';
		$resource      = isset( $params['resource'] ) ? untrailingslashit( (string) $params['resource'] ) : '';

		$client = $this->authenticate_client_credentials( $params );
		if ( is_wp_error( $client ) ) {
			return $this->oauth_error_response( 401, 'invalid_client', $client->get_error_message() );
		}

		// A client that registered without 'authorization_code' among its
		// declared grant_types has no business redeeming a code at all,
		// regardless of whether one somehow exists for it.
		if ( ! in_array( 'authorization_code', $client['grant_types'], true ) ) {
			return $this->oauth_error_response( 400, 'unauthorized_client', __( 'This client is not registered for the authorization_code grant.', 'perdita-core' ) );
		}

		if ( '' === $code ) {
			return $this->oauth_error_response( 400, 'invalid_request', __( 'code is required.', 'perdita-core' ) );
		}

		$grant = $this->get_grant( $code );
		if ( ! $grant || 'code' !== $grant['type'] ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'The authorization code is unknown.', 'perdita-core' ) );
		}

		// Single-use: a code already redeemed once is dead, regardless of
		// whether this second attempt presents matching credentials. This
		// stops a stolen/leaked code from being replayed even if the thief
		// somehow also obtained everything else needed to redeem it.
		if ( ! empty( $grant['used'] ) ) {
			$this->delete_grant( $code );
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This authorization code has already been used.', 'perdita-core' ) );
		}

		if ( (int) $grant['expires'] < time() ) {
			$this->delete_grant( $code );
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This authorization code has expired.', 'perdita-core' ) );
		}

		if ( $grant['client_id'] !== $client['client_id'] ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This authorization code was not issued to this client.', 'perdita-core' ) );
		}

		// Exact redirect_uri match against what was used at the authorize
		// step, per RFC 6749 Section 4.1.3, closing the door on a code
		// obtained via one redirect_uri being redeemed by presenting a
		// different one.
		if ( ! hash_equals( $grant['redirect_uri'], $redirect_uri ) ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'redirect_uri does not match the value used to obtain this code.', 'perdita-core' ) );
		}

		// PKCE verification: SHA-256 the presented verifier, base64url
		// encode it, and compare (constant-time) against the stored
		// challenge from the authorize step. This is the proof that
		// whoever is redeeming this code is the same party that started the
		// flow, the core protection PKCE provides against a stolen
		// authorization code being redeemed by an attacker who intercepted
		// it in transit but never had the verifier.
		if ( '' === $code_verifier || ! hash_equals( $grant['code_challenge'], self::pkce_challenge_from_verifier( $code_verifier ) ) ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'code_verifier does not match the code_challenge presented at authorization.', 'perdita-core' ) );
		}

		// RFC8707 audience binding, strictly enforced here (unlike the
		// tolerant check at /authorize): a resource parameter, if present,
		// must name exactly this server's canonical MCP URI. Absent is
		// tolerated (the token is still scoped to the resource recorded on
		// the grant from the authorize step), but a MISMATCHED resource is
		// rejected outright, which is what stops a token being issued for
		// use against a different protected resource than the one the user
		// actually consented to.
		$resource_error = $this->check_resource_match( $resource );
		if ( null !== $resource_error ) {
			return $resource_error;
		}

		// Atomically claim the code before marking it used. The
		// 'used' flag above is a get_option()->mutate->update_option()
		// read-modify-write, which two concurrent redemptions of the SAME
		// code can both pass before either writes: they would then both
		// reach issue_token_pair() and mint two independent token pairs
		// from one single-use code. add_option() is a real INSERT against
		// the option_name UNIQUE key, so exactly one caller can ever win
		// it. Same test-and-set claim_refresh_token_for_rotation() uses on
		// the refresh side.
		if ( ! self::claim_code_for_redemption( $code ) ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This authorization code has already been used.', 'perdita-core' ) );
		}

		// Mark used (rather than deleting immediately) so the "already
		// used" error above can distinguish a replay from an unknown code,
		// which is a strictly more informative failure for legitimate
		// client retry logic without weakening single-use enforcement.
		$this->mark_grant_used( $code );

		$pair = $this->issue_token_pair( (int) $grant['user_id'], $grant['client_id'], $grant['resource'], $grant['scope'] );
		if ( null === $pair ) {
			return $this->storage_busy_response();
		}
		return new WP_REST_Response( $pair, 200 );
	}

	/**
	 * grant_type=refresh_token. Validates the refresh token and issues a
	 * fresh access token. Rotates the refresh token as well (issues a new
	 * one, invalidates the old), the stronger of the two options OAuth 2.1
	 * allows for public clients, so a leaked-but-unused-yet refresh token
	 * has a shrinking window of usefulness to an attacker with every
	 * legitimate refresh.
	 *
	 * @param array $params Parsed request params.
	 * @return WP_REST_Response
	 */
	private function token_refresh( array $params ) {
		$refresh_token = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		$resource      = isset( $params['resource'] ) ? untrailingslashit( (string) $params['resource'] ) : '';

		$client = $this->authenticate_client_credentials( $params );
		if ( is_wp_error( $client ) ) {
			return $this->oauth_error_response( 401, 'invalid_client', $client->get_error_message() );
		}

		// Mirrors the same check in token_exchange_code(): a client that
		// never declared 'refresh_token' among its grant_types shouldn't be
		// able to use one even if it somehow got hold of a token shaped
		// like one.
		if ( ! in_array( 'refresh_token', $client['grant_types'], true ) ) {
			return $this->oauth_error_response( 400, 'unauthorized_client', __( 'This client is not registered for the refresh_token grant.', 'perdita-core' ) );
		}

		if ( '' === $refresh_token ) {
			return $this->oauth_error_response( 400, 'invalid_request', __( 'refresh_token is required.', 'perdita-core' ) );
		}

		$grant = $this->get_grant( $refresh_token );
		if ( ! $grant || 'refresh' !== $grant['type'] ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'The refresh token is unknown or was already revoked.', 'perdita-core' ) );
		}
		if ( (int) $grant['expires'] < time() ) {
			$this->delete_grant( $refresh_token );
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'The refresh token has expired.', 'perdita-core' ) );
		}
		if ( $grant['client_id'] !== $client['client_id'] ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This refresh token was not issued to this client.', 'perdita-core' ) );
		}
		$resource_error = $this->check_resource_match( $resource );
		if ( null !== $resource_error ) {
			return $resource_error;
		}

		// Atomically claim this refresh token before rotating it. Every
		// other write in this file follows a get_option()->mutate->
		// update_option() pattern, which is NOT safe against two concurrent
		// requests racing to consume the SAME refresh_token (e.g. a client
		// retrying a timed-out refresh): both could pass every check above
		// before either deletes the grant, minting two independent token
		// pairs from what's supposed to be a single-use token. add_option()
		// is different: it's a real INSERT against the option_name UNIQUE
		// key, enforced by the database itself, so only one of two
		// simultaneous callers can ever win it.
		if ( ! self::claim_refresh_token_for_rotation( $refresh_token ) ) {
			return $this->oauth_error_response( 400, 'invalid_grant', __( 'This refresh token has already been used.', 'perdita-core' ) );
		}

		// Rotate: this exact refresh token is consumed, a new one is issued
		// alongside the new access token.
		if ( ! $this->delete_grant( $refresh_token ) ) {
			// The storage lock could not be taken, so nothing was rotated
			// and nothing was issued. Release the claim so the client's
			// retry can go through, and tell it to retry.
			delete_option( self::refresh_claim_option_name( self::hash_token( $refresh_token ) ) );
			return $this->storage_busy_response();
		}
		// The grant is gone now, so a later request presenting this same
		// token fails the get_grant() check above instead; the claim row
		// only needed to survive the brief window before that delete took
		// effect, so remove it immediately rather than leaving it to
		// accumulate forever.
		delete_option( self::refresh_claim_option_name( self::hash_token( $refresh_token ) ) );

		$pair = $this->issue_token_pair( (int) $grant['user_id'], $grant['client_id'], $grant['resource'], $grant['scope'] );
		if ( null === $pair ) {
			return $this->storage_busy_response();
		}
		return new WP_REST_Response( $pair, 200 );
	}

	/**
	 * The option name used to atomically claim an authorization code for
	 * one-time redemption. Keyed by the same one-way hash the grant itself
	 * is stored under, so pruning a grant can delete its claim row without
	 * ever having seen the plaintext code again.
	 *
	 * @param string $code_hash sha256 of the plaintext authorization code.
	 * @return string
	 */
	private static function code_claim_option_name( $code_hash ) {
		return 'perdita_mcp_oauth_code_claim_' . $code_hash;
	}

	/**
	 * Attempt to atomically claim an authorization code for redemption.
	 * Returns false when another concurrent request already claimed it.
	 *
	 * @param string $code Plaintext authorization code.
	 * @return bool True if this call won the race.
	 */
	private static function claim_code_for_redemption( $code ) {
		return add_option( self::code_claim_option_name( self::hash_token( $code ) ), time(), '', false );
	}

	/**
	 * The option name used to atomically claim a refresh token for
	 * one-time rotation. Keyed by the same one-way hash the grant itself is
	 * stored under (see hash_token()), so pruning an expired refresh grant
	 * can delete its claim row without ever seeing the plaintext again.
	 *
	 * @param string $refresh_token_hash sha256 of the plaintext refresh token.
	 * @return string
	 */
	private static function refresh_claim_option_name( $refresh_token_hash ) {
		return 'perdita_mcp_oauth_refresh_claim_' . $refresh_token_hash;
	}

	/**
	 * Attempt to atomically claim a refresh token for rotation. add_option()
	 * fails (returns false) if the option already exists, a real
	 * database-enforced test-and-set unlike this file's usual
	 * get_option()/update_option() read-modify-write, so exactly one
	 * concurrent caller can ever win this for a given refresh token.
	 *
	 * @param string $refresh_token Plaintext refresh token.
	 * @return bool True if this call won the race (proceed with rotation), false if another request already claimed it.
	 */
	private static function claim_refresh_token_for_rotation( $refresh_token ) {
		return add_option( self::refresh_claim_option_name( self::hash_token( $refresh_token ) ), time(), '', false );
	}

	/**
	 * Mint and store a fresh access token + refresh token pair, and return
	 * the RFC6749 Section 5.1 token response shape.
	 *
	 * @param int    $user_id   WP user id the tokens are bound to.
	 * @param string $client_id OAuth client id the tokens are bound to.
	 * @param string $resource  Canonical resource URI the access token is scoped to (RFC8707 audience).
	 * @param string $scope     Granted scope string.
	 * @return array|null The token response, or null if the storage lock could not be taken (nothing was issued).
	 */
	private function issue_token_pair( $user_id, $client_id, $resource, $scope ) {
		$access_token  = self::ACCESS_TOKEN_PREFIX . wp_generate_password( 48, false );
		$refresh_token = self::REFRESH_TOKEN_PREFIX . wp_generate_password( 48, false );

		// One locked write for the pair, so the access token and its
		// refresh token land together or not at all.
		$stored = $this->store_grants(
			array(
				$access_token  => array(
					'type'      => 'access',
					'user_id'   => (int) $user_id,
					'client_id' => $client_id,
					'resource'  => $resource,
					'scope'     => $scope,
					'expires'   => time() + self::ACCESS_TOKEN_TTL,
				),
				$refresh_token => array(
					'type'      => 'refresh',
					'user_id'   => (int) $user_id,
					'client_id' => $client_id,
					'resource'  => $resource,
					'scope'     => $scope,
					'expires'   => time() + self::REFRESH_TOKEN_TTL,
				),
			)
		);
		if ( ! $stored ) {
			return null;
		}

		// Record a human-readable "connected app" entry for the admin
		// screen's revoke list, separate from the grants themselves (a
		// grant's lifecycle is per-token; a connection's lifecycle is
		// per-user-per-client and should stay visible even between token
		// refreshes).
		$this->record_connection( (int) $user_id, $client_id );

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh_token,
			'scope'         => $scope,
		);
	}

	/**
	 * Authenticate the token endpoint caller's client credentials: resolves
	 * client_id (from the body, per RFC7591 public-client convention, since
	 * MCP's client population is overwhelmingly public/PKCE-only) and, for a
	 * confidential client, verifies client_secret with hash_equals().
	 *
	 * @param array $params Parsed request params.
	 * @return array|WP_Error The client record on success, WP_Error otherwise.
	 */
	private function authenticate_client_credentials( array $params ) {
		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		if ( '' === $client_id ) {
			return new WP_Error( 'invalid_client', __( 'client_id is required.', 'perdita-core' ) );
		}

		$client = $this->get_client( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', __( 'Unknown client_id.', 'perdita-core' ) );
		}

		if ( 'none' === $client['auth_method'] ) {
			// Public client: PKCE alone is the proof of possession, no
			// secret was ever issued to compare against.
			return $client;
		}

		$provided_secret = isset( $params['client_secret'] ) ? (string) $params['client_secret'] : '';
		if ( '' === $provided_secret || ! hash_equals( $client['secret_hash'], self::hash_token( $provided_secret ) ) ) {
			return new WP_Error( 'invalid_client', __( 'Invalid client_secret.', 'perdita-core' ) );
		}

		return $client;
	}

	/**
	 * RFC8707 audience check shared by both token-endpoint grant types
	 * (authorization_code exchange and refresh): a resource parameter, if
	 * present, must name exactly this server's canonical MCP URI. Absent
	 * is tolerated (the token stays scoped to whatever resource was
	 * recorded on the grant), but a MISMATCHED resource is rejected
	 * outright, which is what stops a token being issued for use against a
	 * different protected resource than the one actually consented to.
	 *
	 * @param string $resource The resource parameter from the request, already untrailingslashit()'d by the caller.
	 * @return WP_REST_Response|null The error response if the resource doesn't match, null if it's fine to proceed.
	 */
	private function check_resource_match( $resource ) {
		if ( '' !== $resource && self::resource_uri() !== $resource ) {
			return $this->oauth_error_response( 400, 'invalid_target', __( 'The requested resource does not match this server.', 'perdita-core' ) );
		}
		return null;
	}

	/**
	 * Derive the S256 PKCE code_challenge from a code_verifier, per RFC7636
	 * Section 4.2: BASE64URL-ENCODE(SHA256(ASCII(code_verifier))).
	 *
	 * @param string $verifier The client-presented code_verifier.
	 * @return string
	 */
	private static function pkce_challenge_from_verifier( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE, matches Perdita_OpenRouter_OAuth::start()'s identical construction.
	}

	/**
	 * Build an RFC6749 Section 5.2 OAuth error response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $error  RFC6749 error code.
	 * @param string $description Human-readable detail.
	 * @return WP_REST_Response
	 */
	private function oauth_error_response( $status, $error, $description ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	/**
	 * The response for a write that could not take the storage lock within
	 * LOCK_WAIT_SECONDS. Nothing was changed, so the client should simply
	 * try again: 503 is what OAuth clients and HTTP libraries already treat
	 * as retryable, and temporarily_unavailable is the RFC6749 error code
	 * with that meaning.
	 *
	 * @return WP_REST_Response
	 */
	private function storage_busy_response() {
		$response = $this->oauth_error_response( 503, 'temporarily_unavailable', __( 'The site is busy saving another connection. Try again in a moment.', 'perdita-core' ) );
		$response->header( 'Retry-After', '2' );
		return $response;
	}

	/* ==========================================================
	 * Phase 1 integration: perdita_mcp_validate_oauth_token
	 * ========================================================== */

	/**
	 * Validate an OAuth-issued access token for Perdita_MCP::authenticate().
	 * CONTRACT (see class-perdita-mcp.php's docblock on this exact filter):
	 * return a WP user id (int > 0) on a valid, current, non-expired,
	 * non-revoked token, or the passed-through $default (false) on anything
	 * else, including "not one of our tokens" so the free-tier key check
	 * upstream of this filter remains authoritative for its own tokens.
	 *
	 * @param int|false $default Passed-through default (false).
	 * @param string    $token   Raw bearer token.
	 * @return int|false
	 */
	public function validate_token( $default, $token ) {
		if ( 0 !== strpos( (string) $token, self::ACCESS_TOKEN_PREFIX ) ) {
			return $default; // Not shaped like one of ours; let another validator (or the 401 fallback) handle it.
		}

		$grant = $this->get_grant( (string) $token );
		if ( ! $grant || 'access' !== $grant['type'] ) {
			return $default;
		}
		if ( (int) $grant['expires'] < time() ) {
			$this->delete_grant( (string) $token ); // Prune on the way out; also handled by garbage collection below, but no reason to wait.
			return $default;
		}
		// Audience check: an access token is only ever valid at the exact
		// resource it was minted for. Since this filter is only ever
		// invoked from within Perdita_MCP::authenticate(), which is only
		// ever called for requests to this server's own MCP endpoint, the
		// audience is implicitly always self::resource_uri() here, but the
		// comparison is made explicit anyway rather than assumed, so this
		// method stays correct even if it is ever called from a different
		// context in the future.
		if ( self::resource_uri() !== $grant['resource'] ) {
			return $default;
		}

		$user_id = (int) $grant['user_id'];
		return $user_id > 0 ? $user_id : $default;
	}

	/* ==========================================================
	 * Storage: write serialisation
	 * ========================================================== */

	/**
	 * How long a writer waits for the storage lock before giving up, in
	 * seconds. A locked section here is one option read plus one option
	 * write, a few milliseconds, so a wait this long is only ever exhausted
	 * when the database itself has stopped answering.
	 */
	const LOCK_WAIT_SECONDS = 5;

	/**
	 * A lock row older than this belongs to a request that died between
	 * taking the lock and releasing it (a fatal, a killed worker) and is
	 * taken over rather than waited on. Comfortably longer than any real
	 * locked section, comfortably shorter than a request timeout.
	 */
	const LOCK_STALE_SECONDS = 15;

	/**
	 * Every write to OPTION_CLIENTS, OPTION_GRANTS, and OPTION_CONNECTIONS
	 * goes through here. Each of those options is one serialised array, so
	 * a write is always a read-modify-write of the whole thing, and two
	 * requests doing that at once (a token exchange for one client landing
	 * while another client refreshes, or a registration during a revoke)
	 * would each write back a copy missing the other's change. This takes a
	 * short database lock for the option, re-reads the row from the
	 * database INSIDE the lock, hands that current array to $mutate, and
	 * persists whatever comes back, so a write always starts from what is
	 * actually stored, never from a copy WordPress cached earlier in this
	 * request.
	 *
	 * @param string   $option Option name.
	 * @param callable $mutate Receives the current array, returns the array to store (a non-array return stores nothing).
	 * @return array|null The array now stored, or null if the lock could not be taken within LOCK_WAIT_SECONDS (nothing was written).
	 */
	private static function mutate_option( $option, callable $mutate ) {
		$lock = self::acquire_lock( $option );
		if ( false === $lock ) {
			return null;
		}
		try {
			$current = self::fresh_option( $option );
			$next    = $mutate( $current );
			if ( ! is_array( $next ) ) {
				return $current;
			}
			if ( $next !== $current ) {
				update_option( $option, $next, false );
			}
			return $next;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Read an option straight from the database, evicting every copy
	 * WordPress may be holding from earlier in this request first. Without
	 * this, get_option() inside the lock would return whatever it cached
	 * before the lock was taken, which is exactly the stale read the lock
	 * exists to prevent. Mirrors the cache bookkeeping delete_option()
	 * itself does: the per-option entry, the notoptions negative cache, and
	 * the alloptions blob in case the row was ever autoloaded.
	 *
	 * @param string $option Option name.
	 * @return array The stored array, or an empty array when the option is missing or malformed.
	 */
	private static function fresh_option( $option ) {
		wp_cache_delete( $option, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		$alloptions = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $alloptions ) && isset( $alloptions[ $option ] ) ) {
			unset( $alloptions[ $option ] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		}

		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Take the write lock for one option, waiting up to $wait seconds.
	 *
	 * The lock is a row in wp_options inserted with INSERT IGNORE against
	 * the table's UNIQUE option_name key, the same construction
	 * WP_Upgrader::create_lock() uses for core updates: the database itself
	 * guarantees that exactly one of any number of simultaneous inserts
	 * succeeds. Nothing built on get-then-set can promise that. A transient
	 * is read, then written (two callers can both see "absent"), and
	 * wp_cache_add() is only atomic on a persistent object cache, which
	 * most sites running this plugin do not have.
	 *
	 * The row carries the time it was taken so a lock left behind by a
	 * request that died mid-write is taken over after LOCK_STALE_SECONDS
	 * instead of blocking every write until someone clears it by hand.
	 *
	 * @param string     $option The option the lock guards.
	 * @param float|null $wait   Seconds to keep trying; defaults to LOCK_WAIT_SECONDS, 0 means one attempt.
	 * @return string|false The lock's option name (pass it to release_lock()), or false if it could not be taken in time.
	 */
	private static function acquire_lock( $option, $wait = null ) {
		global $wpdb;

		$lock     = $option . '_lock';
		$deadline = microtime( true ) + ( null === $wait ? self::LOCK_WAIT_SECONDS : (float) $wait );

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE against the UNIQUE key is the atomic test-and-set; the options API has no equivalent.
			$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')", $lock, (string) time() ) );
			if ( $taken ) {
				return $lock;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row is read for its age and must never come from a cache.
			$held_since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $lock ) );
			if ( $held_since > 0 && ( time() - $held_since ) > self::LOCK_STALE_SECONDS ) {
				// Delete only that exact stale row (matched on its value),
				// so a live lock taken between the two queries is never
				// stolen, then go straight back to the insert.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
				$wpdb->delete(
					$wpdb->options,
					array(
						'option_name'  => $lock,
						'option_value' => (string) $held_since,
					)
				);
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 50000 );
		}
	}

	/**
	 * Release a lock taken by acquire_lock(). A direct delete rather than
	 * delete_option(): the row was never read through the options API, so
	 * there is no cache entry to keep in step, and the caching module's
	 * "any perdita_ option changed" purge has no reason to fire for it.
	 *
	 * @param string $lock The option name acquire_lock() returned.
	 */
	private static function release_lock( $lock ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire_lock().
		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock ) );
	}

	/* ==========================================================
	 * Storage: clients
	 * ========================================================== */

	/**
	 * All registered clients, keyed by client_id.
	 *
	 * @return array[]
	 */
	public static function all_clients() {
		$clients = get_option( self::OPTION_CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * How long a registered client is kept if it has never completed a
	 * single token exchange. Registration itself is unauthenticated and
	 * open to anyone (RFC7591), so without this OPTION_CLIENTS would grow
	 * without bound from registrations that never went anywhere (an
	 * abandoned setup attempt, or outright junk registrations).
	 */
	const STALE_CLIENT_MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Remove clients older than STALE_CLIENT_MAX_AGE that have never
	 * recorded a connection (self::all_connections() only ever gets an
	 * entry for a client_id after a REAL, successful token issuance, see
	 * record_connection()), so a client mid-flow right now, or one that
	 * completed OAuth even once, is never touched by this.
	 *
	 * @param array[] $clients The current clients array (not re-read, so
	 *                          the caller controls exactly what gets pruned
	 *                          against).
	 * @return array[] The pruned clients array (not yet persisted; the
	 *                  caller is responsible for update_option()).
	 */
	private static function prune_stale_clients( array $clients ) {
		$connected_client_ids = array();
		foreach ( self::all_connections() as $conn ) {
			if ( is_array( $conn ) && isset( $conn['client_id'] ) ) {
				$connected_client_ids[ (string) $conn['client_id'] ] = true;
			}
		}

		$now = time();
		foreach ( $clients as $client_id => $data ) {
			$age_ok       = is_array( $data ) && isset( $data['created'] ) && ( $now - (int) $data['created'] ) > self::STALE_CLIENT_MAX_AGE;
			$never_used   = ! isset( $connected_client_ids[ (string) $client_id ] );
			if ( $age_ok && $never_used ) {
				unset( $clients[ $client_id ] );
			}
		}
		return $clients;
	}

	/**
	 * Look up one registered client by id.
	 *
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	private function get_client( $client_id ) {
		if ( '' === (string) $client_id ) {
			return null;
		}
		$clients = self::all_clients();
		if ( ! isset( $clients[ $client_id ] ) ) {
			return null;
		}
		$record              = $clients[ $client_id ];
		$record['client_id'] = $client_id;
		return $record;
	}

	/* ==========================================================
	 * Storage: grants (codes, access tokens, refresh tokens)
	 * ========================================================== */

	/**
	 * One-way hash of a code/token for storage/lookup, identical rationale
	 * and construction to Perdita_MCP's hash_key(): these are bearer
	 * credentials, generated with enormous entropy, only ever compared
	 * (never decrypted), so a plain SHA-256 is the right tool, not a slow
	 * password KDF.
	 *
	 * @param string $token Plaintext code/token.
	 * @return string Hex-encoded SHA-256 hash, used as the storage key.
	 */
	private static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Store a grant record (code, access token, or refresh token), keyed by
	 * its hash, never its plaintext value, matching the one-way-hash trust
	 * model documented on Perdita_MCP::hash_key(): nothing in this file ever
	 * needs to read a code/token's plaintext back out of storage, only
	 * compare an incoming candidate against what's stored, so there is
	 * nothing to gain from (and real risk in) storing it reversibly.
	 *
	 * @param string $plaintext Plaintext code/token.
	 * @param array  $data      Bound data (type, user_id, client_id, resource, scope, expires, ...).
	 * @return bool False only if the storage lock could not be taken, in which case nothing was stored.
	 */
	private function store_grant( $plaintext, array $data ) {
		return $this->store_grants( array( $plaintext => $data ) );
	}

	/**
	 * Store several grants in one locked write. issue_token_pair() uses
	 * this so an access token and its refresh token land together or not
	 * at all.
	 *
	 * @param array $grants Plaintext code/token => bound data, see store_grant().
	 * @return bool False only if the storage lock could not be taken, in which case nothing was stored.
	 */
	private function store_grants( array $grants ) {
		return self::mutate_grants(
			static function ( array $stored ) use ( $grants ) {
				foreach ( $grants as $plaintext => $data ) {
					$stored[ self::hash_token( $plaintext ) ] = $data;
				}
				return $stored;
			}
		);
	}

	/**
	 * Look up a grant by its plaintext code/token, hashing to compare.
	 * Expired grants are never returned (all_grants() hides them), and the
	 * next locked write drops them from storage for good, so the stored
	 * option never accumulates dead rows on a site with no garbage
	 * collection cron (there isn't one; pruning piggybacks on normal use,
	 * the same "no dedicated cron needed" simplicity as Perdita_MCP's
	 * rate-limit transients self-expiring).
	 *
	 * @param string $plaintext Plaintext code/token.
	 * @return array|null
	 */
	private function get_grant( $plaintext ) {
		$grants = self::all_grants();
		$key    = self::hash_token( $plaintext );
		return isset( $grants[ $key ] ) ? $grants[ $key ] : null;
	}

	/**
	 * Mark a code grant used (single-use enforcement), without deleting it
	 * outright, so a replay attempt gets an informative "already used"
	 * error rather than an ambiguous "unknown code" one. The atomic claim
	 * row (claim_code_for_redemption()) is what actually enforces single
	 * use, so a caller need not act on a false return here.
	 *
	 * @param string $plaintext Plaintext code.
	 * @return bool False only if the storage lock could not be taken.
	 */
	private function mark_grant_used( $plaintext ) {
		$key = self::hash_token( $plaintext );
		return self::mutate_grants(
			static function ( array $grants ) use ( $key ) {
				if ( isset( $grants[ $key ] ) ) {
					$grants[ $key ]['used'] = true;
				}
				return $grants;
			}
		);
	}

	/**
	 * Delete one grant, and the single-use claim row of a code grant with
	 * it.
	 *
	 * @param string $plaintext Plaintext code/token.
	 * @return bool False only if the storage lock could not be taken, in which case the grant is still on file.
	 */
	private function delete_grant( $plaintext ) {
		$key  = self::hash_token( $plaintext );
		$type = '';
		$done = self::mutate_grants(
			static function ( array $grants ) use ( $key, &$type ) {
				$type = isset( $grants[ $key ]['type'] ) ? (string) $grants[ $key ]['type'] : '';
				unset( $grants[ $key ] );
				return $grants;
			}
		);
		if ( $done && 'code' === $type ) {
			delete_option( self::code_claim_option_name( $key ) );
		}
		return $done;
	}

	/**
	 * Request-scoped cache of the (expired-entries-hidden) grants array. A
	 * single MCP tool call or token-endpoint request can hit all_grants()
	 * several times (get_grant() then store_grant()/mark_grant_used()/
	 * delete_grant() in the same call), each of which previously did its
	 * own full get_option()+deserialize+prune pass; this reuses one read
	 * for the lifetime of the request. Every write goes through
	 * mutate_grants(), which re-reads under the lock and then replaces this
	 * cache with exactly what it persisted, so the cache can never drift
	 * from what is stored.
	 *
	 * @var array[]|null
	 */
	private static $grants_cache = null;

	/**
	 * All live grants. Expired entries are hidden here (in memory only; a
	 * read never writes, see mutate_grants() for where the stored array
	 * actually shrinks).
	 *
	 * @return array[]
	 */
	private static function all_grants() {
		if ( null !== self::$grants_cache ) {
			return self::$grants_cache;
		}

		$grants             = get_option( self::OPTION_GRANTS, array() );
		self::$grants_cache = self::prune_expired_grants( is_array( $grants ) ? $grants : array(), false );
		return self::$grants_cache;
	}

	/**
	 * Drop expired grants from an array. Reads call this to hide expired
	 * entries in memory, and every locked write calls it with $drop_claims
	 * so the stored array shrinks as a side effect of normal use and the
	 * single-use claim rows of expired codes and refresh tokens (see
	 * claim_code_for_redemption() and claim_refresh_token_for_rotation())
	 * go with them instead of accumulating forever.
	 *
	 * @param array[] $grants      Grants keyed by token hash.
	 * @param bool    $drop_claims Also delete the claim rows of the expired entries.
	 * @return array[] The grants that are still live.
	 */
	private static function prune_expired_grants( array $grants, $drop_claims ) {
		$now  = time();
		$kept = array();
		foreach ( $grants as $key => $data ) {
			if ( is_array( $data ) && isset( $data['expires'] ) && (int) $data['expires'] >= $now ) {
				$kept[ $key ] = $data;
				continue;
			}
			if ( ! $drop_claims || ! is_array( $data ) || ! isset( $data['type'] ) ) {
				continue;
			}
			if ( 'code' === $data['type'] ) {
				delete_option( self::code_claim_option_name( $key ) );
			} elseif ( 'refresh' === $data['type'] ) {
				delete_option( self::refresh_claim_option_name( $key ) );
			}
		}
		return $kept;
	}

	/**
	 * Apply one change to the stored grants under the storage lock, pruning
	 * expired entries in the same write, and keep the request-scoped cache
	 * in step with exactly what was persisted.
	 *
	 * @param callable $mutate Receives the current (pruned) grants array, returns the array to store.
	 * @return bool False if the storage lock could not be taken (nothing was written).
	 */
	private static function mutate_grants( callable $mutate ) {
		$stored = self::mutate_option(
			self::OPTION_GRANTS,
			static function ( array $grants ) use ( $mutate ) {
				return $mutate( self::prune_expired_grants( $grants, true ) );
			}
		);
		if ( null === $stored ) {
			return false;
		}
		self::$grants_cache = $stored;
		return true;
	}

	/* ==========================================================
	 * Connected apps: admin-facing list + revocation
	 * ========================================================== */

	/**
	 * Option holding the human-readable "connected apps" list shown in
	 * wp-admin: one entry per (user_id, client_id) pair that has ever
	 * completed a token exchange, independent of the underlying grants'
	 * per-token lifecycle (a refresh does not create a new "connection", it
	 * just updates last_used on the existing one).
	 */
	const OPTION_CONNECTIONS = 'perdita_mcp_oauth_connections';

	/**
	 * Record (or update) a connected-app entry after a successful token
	 * issuance.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $client_id OAuth client id.
	 */
	private function record_connection( $user_id, $client_id ) {
		$key = $user_id . ':' . $client_id;
		// Bookkeeping only: if the lock cannot be taken the tokens are still
		// valid, and the next issuance for this pair records it again.
		self::mutate_option(
			self::OPTION_CONNECTIONS,
			static function ( array $connections ) use ( $key, $user_id, $client_id ) {
				$existing            = isset( $connections[ $key ] ) ? $connections[ $key ] : array();
				$connections[ $key ] = array(
					'user_id'          => (int) $user_id,
					'client_id'        => $client_id,
					'first_authorized' => isset( $existing['first_authorized'] ) ? $existing['first_authorized'] : time(),
					'last_used'        => time(),
				);
				return $connections;
			}
		);
	}

	/**
	 * All connected-app entries.
	 *
	 * @return array[]
	 */
	public static function all_connections() {
		$connections = get_option( self::OPTION_CONNECTIONS, array() );
		return is_array( $connections ) ? $connections : array();
	}

	/**
	 * Connected apps for a specific WP user (what the admin screen shows:
	 * every user manages/sees their own connections, not every user's).
	 *
	 * @param int $user_id WP user id.
	 * @return array[]
	 */
	public static function connections_for_user( $user_id ) {
		$out = array();
		foreach ( self::all_connections() as $key => $conn ) {
			if ( (int) $conn['user_id'] === (int) $user_id ) {
				$out[ $key ] = $conn;
			}
		}
		return $out;
	}

	/**
	 * Whether a specific user has an existing connection to a specific
	 * client, the exact ownership check the admin-post revoke handler
	 * needs before honoring a client-submitted client_id. A direct isset()
	 * against the same "$user_id:$client_id" key record_connection()/
	 * revoke_connection() already use, rather than looping
	 * connections_for_user()'s full result just to test membership.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $client_id OAuth client id.
	 * @return bool
	 */
	public static function user_owns_connection( $user_id, $client_id ) {
		return isset( self::all_connections()[ $user_id . ':' . $client_id ] );
	}

	/**
	 * Revoke a connection: deletes every stored grant (code/access/refresh)
	 * bound to this exact (user_id, client_id) pair, and removes the
	 * connected-app entry. This is the mechanism behind the admin screen's
	 * per-connection "Revoke" button; deleting the grants themselves (not
	 * merely the connection-list entry) is what actually invalidates the
	 * client's live access and refresh tokens against the real MCP
	 * endpoint, since Perdita_MCP::authenticate() -> validate_token() above
	 * looks the presented token up in exactly this storage on every request.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $client_id OAuth client id.
	 * @return bool False if either write could not take the storage lock, in which case the client may still hold live tokens and the caller should say so.
	 */
	public static function revoke_connection( $user_id, $client_id ) {
		$grants_done = self::mutate_grants(
			static function ( array $grants ) use ( $user_id, $client_id ) {
				foreach ( $grants as $key => $data ) {
					if ( is_array( $data ) && (int) ( $data['user_id'] ?? 0 ) === (int) $user_id && ( $data['client_id'] ?? '' ) === $client_id ) {
						unset( $grants[ $key ] );
					}
				}
				return $grants;
			}
		);

		$connections = self::mutate_option(
			self::OPTION_CONNECTIONS,
			static function ( array $connections ) use ( $user_id, $client_id ) {
				unset( $connections[ $user_id . ':' . $client_id ] );
				return $connections;
			}
		);

		return $grants_done && null !== $connections;
	}
}
