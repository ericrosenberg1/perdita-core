<?php
/**
 * MCP (Model Context Protocol) server: the JSON-RPC endpoint, the WordPress
 * tool implementations, and the free-tier API key storage/validation.
 *
 * WHAT THIS IS: MCP (https://modelcontextprotocol.io) is Anthropic's open
 * protocol for connecting AI chat clients (Claude Desktop, claude.ai,
 * ChatGPT, etc.) to external tools. This class implements the "Streamable
 * HTTP" transport in its simplest, stateless form: one REST route accepts a
 * JSON-RPC 2.0 request per HTTP call and returns a single JSON-RPC response.
 * There is no persistent connection, no session, and no server-sent-events
 * stream, every request is independent. That statelessness is deliberate and
 * matches the protocol's own guidance for exactly this kind of server: a
 * normal PHP/WordPress request lifecycle has nowhere to hold a live
 * connection open between calls anyway.
 *
 * PROTOCOL VERSION: this implements 2025-06-18, the version actually spoken
 * by real MCP clients today (Claude Desktop, claude.ai Connectors, and the
 * broader ecosystem), including its `initialize` handshake and its
 * request/response JSON-RPC shapes for tools/list and tools/call. A newer,
 * still-unreleased draft of the spec removes the initialize handshake
 * entirely in favor of per-request metadata; that draft is not yet deployed
 * by any real client, so building against it would produce a server nothing
 * can actually talk to. If a future stable spec revision changes this, this
 * class's initialize()/tools_list()/tools_call() methods are the place to
 * revisit.
 *
 * AUTHENTICATION MODEL (the core safety property of this whole feature): an
 * MCP client authenticates with a bearer token, which this class resolves to
 * a specific WordPress user (see authenticate()). Every tool call then runs
 * with wp_set_current_user() pointed at that exact user, so every
 * current_user_can() check inside a tool reflects that user's real role and
 * capabilities. A client holding a Contributor-bound key can only ever do
 * what that Contributor could do by logging into wp-admin directly. There is
 * no separate, more-permissive "MCP capability" model bolted on top.
 *
 * FREE-TIER AUTH vs PRO OAUTH: this file ships the free-tier mechanism, a
 * single static bearer token ("API key") generated in wp-admin, stored only
 * as a one-way hash (never reversibly encrypted, because Perdita itself
 * never needs to read it back, only compare it, exactly like a password).
 * class-perdita-mcp-oauth.php (Phase 2, loaded from this class's
 * constructor) adds a full OAuth 2.1 authorization server for a one-click
 * "Connect" experience from clients like claude.ai's Connectors panel,
 * entirely additive: it hooks the `perdita_mcp_validate_oauth_token` filter
 * in authenticate() below (documented in detail at that call site) rather
 * than this file needing to know anything about OAuth itself.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_MCP {

	const NS = 'perdita/v1';

	/**
	 * Option name for the stored module state: the API key hash, which user
	 * it's bound to, and when it was generated. The raw key itself is never
	 * stored, only its SHA-256 hash (see generate_api_key() /
	 * class-perdita-mcp-admin.php).
	 */
	const OPTION = 'perdita_mcp_settings';

	/**
	 * Prefix stamped on every generated key so a leaked key is recognizable
	 * at a glance in a log line or a support ticket, the same convention as
	 * Stripe/GitHub tokens (e.g. sk_live_, ghp_).
	 */
	const KEY_PREFIX = 'perdita_mcp_';

	/**
	 * MCP protocol version this server implements. Echoed back verbatim in
	 * initialize responses. See the class docblock for why this targets
	 * 2025-06-18 rather than the unreleased draft revision.
	 */
	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Transient prefix for the per-IP failed-authentication counter used by
	 * rate_limit_check() / rate_limit_record_failure().
	 */
	const RATE_LIMIT_PREFIX = 'perdita_mcp_authfail_';

	/**
	 * Failed-authentication attempts allowed per IP per hour before this
	 * endpoint starts returning 429. A wrong or missing bearer token is
	 * exactly the shape of a brute-force guess against the stored API key,
	 * so this mirrors the login-rate-limit pattern in
	 * Perdita_Security::record_failure() / check_lockout(), reimplemented
	 * locally (see the note on client_ip() below for why this module does
	 * not call into the Security module directly).
	 */
	const RATE_LIMIT_MAX = 20;

	/**
	 * The rate-limit counting window. One hour, matching the cap described
	 * above ("20 per IP per hour").
	 */
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Per-authenticated-identity tool-call budget, independent of the
	 * per-IP auth-failure counter above. That counter only ever increments
	 * on a WRONG or missing credential, so a valid (but leaked, shared, or
	 * simply over-eager) bearer token could otherwise drive unlimited
	 * tool-call volume, unbounded content scraping or spam-post creation,
	 * with zero throttling. Keyed by the authenticated user id rather than
	 * IP, so it follows the credential across networks.
	 */
	const TOOL_CALL_RATE_LIMIT_PREFIX = 'perdita_mcp_toolcalls_';
	const TOOL_CALL_RATE_LIMIT_MAX    = 300;
	const TOOL_CALL_RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Default ceiling, in characters, on each of get_post's content_raw and
	 * content_rendered fields. Both go straight into the calling model's
	 * context window, so one very long post (an imported book chapter, a
	 * page built from hundreds of blocks) used to be able to swallow the
	 * whole conversation with no warning. 60,000 characters is roughly
	 * 15,000 tokens per field: well above any ordinary post, and still a
	 * fraction of every current client's context. A site changes it with
	 * the perdita_mcp_get_post_max_chars filter (0 removes the cap), and a
	 * client can ask for less on any one call with the max_chars argument.
	 * Whenever a field is cut the response says so (truncated: true) and
	 * reports both full lengths, so the model knows what it did not see.
	 */
	const GET_POST_MAX_CHARS = 60000;

	/**
	 * The Perdita core (settings, modules registry, ai router, ...).
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * Also loads and boots the Phase 2 OAuth authorization server
	 * (class-perdita-mcp-oauth.php): that file registers its own REST
	 * routes, its own rewrite rule + front-end handler, and hooks the
	 * perdita_mcp_validate_oauth_token filter documented on authenticate()
	 * below, entirely from its own constructor. Wiring it here (rather than
	 * adding a second class/file slot to this module's descriptor, which
	 * Perdita_Modules::boot() has no mechanism for) is the same pattern this
	 * codebase already uses for a module made of more than one collaborating
	 * class (e.g. the built-in SEO module's boot closure in
	 * class-perdita.php wires Perdita_SEO alongside Perdita_SEO_Admin). This
	 * does not change any free-tier API key behavior in this file: it only
	 * makes the OAuth module reachable at all, since nothing else loads its
	 * file.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		add_action( 'rest_api_init', array( $this, 'routes' ) );

		require_once __DIR__ . '/class-perdita-mcp-oauth.php';
		new Perdita_MCP_OAuth( $core );
	}

	/* ==========================================================
	 * Settings (API key storage)
	 * ========================================================== */

	/**
	 * Default settings shape.
	 *
	 * @return array {
	 *     @type string $key_hash    SHA-256 hex hash of the active API key, or '' if none.
	 *     @type int    $user_id     WP user ID the key is bound to.
	 *     @type int    $generated   Unix timestamp the key was generated.
	 * }
	 */
	public static function defaults() {
		return array(
			'key_hash'  => '',
			'user_id'   => 0,
			'generated' => 0,
		);
	}

	/**
	 * Read stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Whether an API key is currently active.
	 *
	 * @return bool
	 */
	public static function has_key() {
		$s = self::settings();
		return '' !== (string) $s['key_hash'];
	}

	/**
	 * Generate a brand-new API key, bind it to the given user, and store only
	 * its hash. The plaintext key is returned exactly once. It cannot be
	 * recovered afterward, matching the GitHub/Stripe "copy it now" pattern:
	 * this class never keeps the plaintext anywhere (not in the option, not
	 * in a log, not in a transient), so there is nothing later code could
	 * accidentally leak.
	 *
	 * A second call replaces the first key entirely (old hash is
	 * overwritten), so at most one key is ever active. That is a deliberate
	 * v1 simplification: multi-key management (e.g. separate keys per
	 * MCP client) is unnecessary complexity for a first version and can be
	 * added later without changing this storage shape's meaning.
	 *
	 * @param int $user_id WP user ID to bind the new key to.
	 * @return string The plaintext key. Show it to the admin once, then discard it.
	 */
	public static function generate_api_key( $user_id ) {
		// wp_generate_password( $length, $special_chars ): 48 chars, no
		// special characters, so the key is safe to use unescaped in a
		// header value and in a JSON config snippet.
		$random = wp_generate_password( 48, false );
		$key    = self::KEY_PREFIX . $random;

		update_option(
			self::OPTION,
			array(
				'key_hash'  => self::hash_key( $key ),
				'user_id'   => (int) $user_id,
				'generated' => time(),
			),
			false // Never autoloaded: this option isn't needed on requests that don't touch the MCP endpoint, matching class-perdita-ai.php's own API-key-storage convention.
		);

		return $key;
	}

	/**
	 * Revoke the active key: clear the stored hash and binding. A revoked
	 * key can never authenticate again, since authenticate() only ever
	 * compares against whatever hash is currently stored.
	 */
	public static function revoke_api_key() {
		update_option( self::OPTION, self::defaults(), false );
	}

	/**
	 * One-way hash of a key for storage/comparison. This is a bearer
	 * CREDENTIAL, not a reversible secret Perdita ever needs to redisplay or
	 * retransmit (unlike the SMTP password or third-party API keys elsewhere
	 * in this codebase, which use Perdita_Crypto's reversible encryption
	 * because those values must be read back in plaintext to make an
	 * outbound call). The trust model here is the same as a WordPress
	 * password: only ever compared, never decrypted. A plain SHA-256 over
	 * the full 48-byte-random token is appropriate for that (unlike a
	 * user-chosen password, this token already has enormous entropy, so a
	 * slow KDF like bcrypt buys nothing extra here and would only slow down
	 * every API call).
	 *
	 * @param string $key Plaintext key.
	 * @return string Hex-encoded SHA-256 hash.
	 */
	private static function hash_key( $key ) {
		return hash( 'sha256', (string) $key );
	}

	/* ==========================================================
	 * REST route registration
	 * ========================================================== */

	/**
	 * Register the single MCP endpoint. permission_callback is
	 * __return_true: WordPress's own cookie/nonce auth is irrelevant here,
	 * this endpoint is called by an external AI client with no WordPress
	 * session at all. Authentication instead happens inside handle_request(),
	 * via the Authorization: Bearer header, and is what decides which (if
	 * any) WP user the request runs as.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_request' ),
			)
		);
	}

	/**
	 * The MCP endpoint's REST callback. Authenticates the bearer token,
	 * parses the JSON-RPC 2.0 request body, dispatches to the right handler,
	 * and always returns a JSON-RPC-shaped body (never a bare WP_Error or an
	 * ad hoc error shape, so a client only ever has to understand one error
	 * format).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $req ) {
		$auth = $this->authenticate_request( $req );
		if ( is_wp_error( $auth ) ) {
			return $this->auth_error_response( $auth );
		}

		$body = json_decode( $req->get_body(), true );
		if ( ! is_array( $body ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
			return $this->rpc_response(
				null,
				null,
				$this->rpc_error( -32600, __( 'Invalid Request: body must be a JSON-RPC 2.0 object.', 'perdita-core' ) )
			);
		}

		$id     = array_key_exists( 'id', $body ) ? $body['id'] : null;
		$method = isset( $body['method'] ) ? (string) $body['method'] : '';
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		// Every tool call from here on runs as the specific WP user the
		// bearer token is bound to, so every current_user_can() check inside
		// a tool reflects that user's real role. This is the core safety
		// property described in the class docblock, set immediately before
		// any method dispatch and for the duration of this request only
		// (WordPress requests are not reused across users, so there is no
		// "restore previous user" step needed afterward).
		wp_set_current_user( $auth );

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_response( $id, $this->handle_initialize( $params ) );

			case 'notifications/initialized':
				// Per the MCP lifecycle, the client sends this as a
				// notification (no reply expected) once it has processed our
				// initialize response. A notification carries no 'id'; if a
				// client incorrectly sent one with an id anyway, acknowledge
				// with an empty result rather than erroring.
				return null === $id ? new WP_REST_Response( null, 202 ) : $this->rpc_response( $id, array() );

			case 'tools/list':
				return $this->rpc_response( $id, $this->handle_tools_list() );

			case 'tools/call':
				// Distinct from rate_limit_check() above: that counter only
				// ever increments on a wrong/missing credential, so it does
				// nothing to bound a VALID token's call volume. Keyed by the
				// authenticated identity (not IP), so a leaked or shared
				// token is still bounded even from a different network.
				$call_limit_key = self::rate_limit_key( self::TOOL_CALL_RATE_LIMIT_PREFIX, 'user:' . $auth );
				$call_count     = (int) get_transient( $call_limit_key );
				if ( $call_count >= self::TOOL_CALL_RATE_LIMIT_MAX ) {
					return $this->rpc_response(
						$id,
						null,
						$this->rpc_error( -32000, __( 'Too many tool calls for this connection. Please slow down and try again shortly.', 'perdita-core' ) )
					);
				}
				set_transient( $call_limit_key, $call_count + 1, self::TOOL_CALL_RATE_LIMIT_WINDOW );

				$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
				if ( ! in_array( $tool_name, wp_list_pluck( self::tool_definitions( $this->core ), 'name' ), true ) ) {
					// Unknown tool is a protocol-level problem (the request
					// itself names something that doesn't exist), so this is a
					// genuine JSON-RPC error, not a tool result with
					// isError:true. See the class docblock and the Tools spec's
					// "Error Handling" section for why these two cases are kept
					// distinct: a model is less likely to recover from this one
					// than from a tool execution error.
					//
					// The code is -32602 (invalid params), not -32601 (method
					// not found), on purpose: the METHOD here is tools/call,
					// which exists, and the tool name is one of its params.
					// That is the code the MCP spec's own "Error Handling"
					// example uses for "Unknown tool", and what the reference
					// @modelcontextprotocol/sdk server throws
					// (McpError(ErrorCode.InvalidParams, "Tool X not found")).
					// The default branch below is where -32601 belongs: a
					// method this server does not implement at all.
					return $this->rpc_response(
						$id,
						null,
						$this->rpc_error( -32602, sprintf(
							/* translators: %s: requested tool name. */
							__( 'Unknown tool "%s". Call tools/list to see the available tools.', 'perdita-core' ),
							$tool_name
						) )
					);
				}
				return $this->rpc_response( $id, $this->handle_tools_call( $params ) );

			case 'ping':
				return $this->rpc_response( $id, new stdClass() );

			default:
				return $this->rpc_response(
					$id,
					null,
					$this->rpc_error( -32601, sprintf(
						/* translators: %s: JSON-RPC method name. */
						__( 'Method not found: %s', 'perdita-core' ),
						$method
					) )
				);
		}
	}

	/* ==========================================================
	 * JSON-RPC helpers
	 * ========================================================== */

	/**
	 * Build a JSON-RPC 2.0 success or error response. Every response from
	 * this endpoint goes through here, so the shape is always correct:
	 * {"jsonrpc":"2.0","id":...,"result":...} or
	 * {"jsonrpc":"2.0","id":...,"error":{"code":...,"message":...}}.
	 *
	 * @param mixed      $id     The request's id (echoed back verbatim; may be null for pre-parse errors).
	 * @param mixed       $result Result payload, when there is no error.
	 * @param array|null $error  array( 'code' => int, 'message' => string, 'data' => mixed|optional ), or null.
	 * @return WP_REST_Response
	 */
	private function rpc_response( $id, $result = null, $error = null ) {
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
		);
		if ( null !== $error ) {
			$body['error'] = $error;
			// JSON-RPC errors ride on HTTP 200; the JSON-RPC error object
			// itself is what signals failure to the client, per the
			// tools/list & tools/call spec examples (protocol-level errors
			// like "unknown method" are still 200 + an error body, not an
			// HTTP error code, since the HTTP transport succeeded and it's
			// the RPC call within it that failed).
			return new WP_REST_Response( $body, 200 );
		}
		$body['result'] = $result;
		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Build a JSON-RPC error object.
	 *
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Human-readable message.
	 * @param mixed  $data    Optional extra data.
	 * @return array
	 */
	private function rpc_error( $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return $error;
	}

	/* ==========================================================
	 * initialize / tools/list / tools/call handlers
	 * ========================================================== */

	/**
	 * Handle 'initialize': the protocol handshake. Returns our supported
	 * protocol version, declared capabilities (tools only: this v1
	 * deliberately does not implement resources/* or prompts/*, keeping the
	 * surface focused), and server identity.
	 *
	 * @param array $params Client's initialize params (protocolVersion, capabilities, clientInfo). Unused beyond acknowledging the handshake: this stateless server does not need to remember client capabilities between calls.
	 * @return array
	 */
	private function handle_initialize( $params ) {
		unset( $params );
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => array(
					'listChanged' => false,
				),
			),
			'serverInfo'      => array(
				'name'    => 'perdita-mcp',
				'version' => defined( 'PERDITA_CORE_VERSION' ) ? PERDITA_CORE_VERSION : '0.0.0',
			),
			'instructions'    => __( 'Tools for managing this WordPress site: reading and writing posts/pages, searching content, checking site status, and (if the Forms module is enabled) reading form submissions. Every action is limited to what the connected WordPress user is actually allowed to do.', 'perdita-core' ),
		);
	}

	/**
	 * Handle 'tools/list': return every tool definition available to the
	 * currently authenticated user. get_form_entries is only included when
	 * the Forms module is enabled, so a client never sees a tool it cannot
	 * possibly call successfully.
	 *
	 * @return array
	 */
	private function handle_tools_list() {
		return array(
			'tools' => self::tool_definitions( $this->core ),
		);
	}

	/**
	 * Handle 'tools/call': dispatch to the named tool. The caller
	 * (handle_request()) already validated that $params['name'] is a known
	 * tool before invoking this (an unknown name is a JSON-RPC protocol
	 * error there, not a tool result). Failures within a known tool (bad
	 * input value, permission denied) are tool execution errors (a normal
	 * result with isError: true and an actionable text message), per the
	 * MCP spec's two-tier error model, so the calling model can see what to
	 * fix and retry.
	 *
	 * @param array $params tools/call params: 'name' and 'arguments'.
	 * @return array CallToolResult.
	 */
	private function handle_tools_call( $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		switch ( $name ) {
			case 'list_posts':
				return $this->tool_list_posts( $args );
			case 'get_post':
				return $this->tool_get_post( $args );
			case 'create_post':
				return $this->tool_create_post( $args );
			case 'update_post':
				return $this->tool_update_post( $args );
			case 'search_content':
				return $this->tool_search_content( $args );
			case 'get_site_status':
				return $this->tool_get_site_status();
			case 'get_form_entries':
				return $this->tool_get_form_entries( $args );
			default:
				return $this->tool_error( __( 'Tool is registered but not implemented.', 'perdita-core' ) );
		}
	}

	/* ==========================================================
	 * Authentication
	 * ========================================================== */

	/**
	 * Resolve the incoming request's Authorization header to a WP user ID,
	 * applying the rate limiter first. Returns a WP_Error (with 401/429
	 * status data) on any failure, or the request's bearer token on success
	 * so authenticate() can be tested independently of the REST layer.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return int|WP_Error WP user ID on success, WP_Error otherwise.
	 */
	private function authenticate_request( WP_REST_Request $req ) {
		$limited = self::rate_limit_check( self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_MAX );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$header = $req->get_header( 'authorization' );
		$token  = self::extract_bearer_token( $header );

		if ( '' === $token ) {
			self::rate_limit_record( self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_WINDOW );
			return new WP_Error(
				'perdita_mcp_missing_token',
				__( 'This endpoint requires an Authorization: Bearer <token> header.', 'perdita-core' ),
				array( 'status' => 401 )
			);
		}

		$user_id = self::authenticate( $token );
		if ( false === $user_id ) {
			self::rate_limit_record( self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_WINDOW );
			return new WP_Error(
				'perdita_mcp_invalid_token',
				__( 'The bearer token was not recognized. It may be wrong, revoked, or expired.', 'perdita-core' ),
				array( 'status' => 401 )
			);
		}

		return (int) $user_id;
	}

	/**
	 * Pull the token out of an "Authorization: Bearer <token>" header value.
	 *
	 * @param string|null $header Raw header value.
	 * @return string Token, or '' if the header is missing/malformed.
	 */
	private static function extract_bearer_token( $header ) {
		$header = is_string( $header ) ? trim( $header ) : '';
		if ( '' === $header || 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}
		return trim( substr( $header, 7 ) );
	}

	/**
	 * Authenticate a bearer token to a WordPress user ID. This is the single
	 * chokepoint every request's identity flows through, checked in two
	 * steps:
	 *
	 *   (a) Compare against the stored free-tier API key hash
	 *       (constant-time via hash_equals(), never `===`, since a
	 *       timing-based comparison could let an attacker learn the hash
	 *       byte-by-byte). A match returns the WP user ID the key was bound
	 *       to at generation time (see generate_api_key()).
	 *
	 *   (b) EXTENSION POINT FOR PHASE 2 (OAuth 2.1 authorization server):
	 *       `apply_filters( 'perdita_mcp_validate_oauth_token', false, $token )`.
	 *       Nothing hooks this filter yet. It exists so a later Phase 2
	 *       module can validate an OAuth-issued access token (audience,
	 *       expiry, revocation, everything OAuth token validation implies)
	 *       WITHOUT this file needing to change at all: Phase 2 hooks the
	 *       filter from its own module and returns a WP user ID.
	 *       CONTRACT: a hooked callback MUST return a WP user ID (int > 0)
	 *       on a valid, currently-active token, or `false` on anything else
	 *       (invalid signature, expired, revoked, wrong audience, or simply
	 *       "I don't recognize this token, maybe another validator does").
	 *       Returning anything else (0, null, a WP_Error, an array) is
	 *       treated as "not authenticated" by the caller below, so a
	 *       misbehaving Phase 2 hook fails closed, not open.
	 *
	 *   If neither step authenticates, the caller returns a 401.
	 *
	 * @param string $token Raw bearer token from the Authorization header.
	 * @return int|false WP user ID, or false if the token does not authenticate.
	 */
	public static function authenticate( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}

		$settings = self::settings();
		if ( '' !== (string) $settings['key_hash'] ) {
			if ( hash_equals( $settings['key_hash'], self::hash_key( $token ) ) ) {
				$user_id = (int) $settings['user_id'];
				return $user_id > 0 ? $user_id : false;
			}
		}

		/**
		 * Validate an OAuth-issued MCP access token (Phase 2 extension point).
		 *
		 * Nothing hooks this filter in the free tier shipped here. A future
		 * Phase 2 module (a full OAuth 2.1 authorization server, used for a
		 * one-click "Connect" experience from clients like claude.ai's
		 * Connectors panel) hooks this to validate its own tokens.
		 *
		 * @param int|false $result WP user ID on success, false to indicate "not authenticated" (default: false, since nothing hooks this yet).
		 * @param string    $token  The raw bearer token.
		 */
		$oauth_user_id = apply_filters( 'perdita_mcp_validate_oauth_token', false, $token );
		if ( is_int( $oauth_user_id ) && $oauth_user_id > 0 ) {
			return $oauth_user_id;
		}

		return false;
	}

	/**
	 * Build the 401 response for a failed authentication attempt, including
	 * the WWW-Authenticate header the MCP authorization spec requires so a
	 * conforming client knows a bearer token (of some kind) is expected.
	 *
	 * Includes a `resource_metadata="<protected-resource-metadata-url>"`
	 * parameter on this header, per RFC9728 Section 5.1 and the MCP
	 * authorization-server-discovery spec's "WWW-Authenticate Header"
	 * discovery mechanism, pointing an OAuth-aware client at this server's
	 * Protected Resource Metadata document so it can discover the
	 * authorization server (this same site) without needing the well-known
	 * URI probed separately first. The metadata document itself is served
	 * by the Phase 2 OAuth module (class-perdita-mcp-oauth.php); this
	 * parameter is added unconditionally (not only when that module is
	 * active) because the URL always resolves, at worst to a 404, which is
	 * no worse than a client that never had this hint at all, and every
	 * install running this file also loads that module now (see this
	 * class's constructor).
	 *
	 * @param WP_Error $error Error from authenticate_request(), carrying 'status' in its error data.
	 * @return WP_REST_Response
	 */
	private function auth_error_response( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 401;

		$response = $this->rpc_response(
			null,
			null,
			$this->rpc_error( -32001, $error->get_error_message() )
		);
		$response->set_status( $status );

		if ( 401 === $status ) {
			// error_description carries the actionable detail; error stays
			// the fixed RFC 6750 token per the MCP authorization spec.
			$response->header(
				'WWW-Authenticate',
				sprintf(
					'Bearer error="invalid_token", error_description="%s", resource_metadata="%s"',
					self::header_escape( $error->get_error_message() ),
					self::header_escape( home_url( '/.well-known/oauth-protected-resource' ) )
				)
			);
		}

		return $response;
	}

	/**
	 * Escape a value for safe inclusion inside a quoted HTTP header
	 * parameter (strip control characters and escape embedded quotes/
	 * backslashes), since the error message it carries could in principle
	 * contain user-influenced text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function header_escape( $value ) {
		$value = preg_replace( '/[\r\n]+/', ' ', (string) $value );
		return addcslashes( $value, '"\\' );
	}

	/* ==========================================================
	 * Rate limiting (failed-auth attempts per IP)
	 * ========================================================== */

	/**
	 * The client IP used for rate limiting. Reads REMOTE_ADDR directly
	 * rather than depending on the Security module's Perdita_Security::client_ip(),
	 * because that module may not be enabled on a given site (this module's
	 * descriptor does not declare it as a dependency, and folder modules
	 * load lazily/independently per Perdita_Modules). Sites behind a proxy
	 * or load balancer can supply the real client IP via the
	 * perdita_mcp_client_ip filter, mirroring the same proxy caveat
	 * documented on Perdita_Security::client_ip().
	 *
	 * Public static (no $this-> use at all) so Perdita_MCP_OAuth can reuse
	 * the exact same IP resolution for its own, separately-keyed rate
	 * limits on /oauth/register and /oauth/token, without instantiating a
	 * Perdita_MCP object just to call it.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		/**
		 * Filter the resolved client IP used for MCP endpoint rate limiting.
		 * REMOTE_ADDR is the connecting peer, which is the load balancer's
		 * own IP on most hosts behind a proxy, not the real visitor. A site
		 * owner behind such a proxy can hook this to trust a specific
		 * forwarded-for header, the same caveat Perdita_Security::client_ip()
		 * documents: never trust a forwarded header by default, since it is
		 * trivially spoofable by anyone who can reach this endpoint directly.
		 *
		 * @param string $ip The REMOTE_ADDR value (untrusted headers are ignored by default).
		 */
		$ip = apply_filters( 'perdita_mcp_client_ip', $ip );
		return is_string( $ip ) ? trim( $ip ) : '';
	}

	/**
	 * The transient key for an IP's rate-limit counter under a given
	 * prefix. The raw IP is hashed so it never appears verbatim in the
	 * options table, matching Perdita_Security's transient_key() pattern.
	 * $prefix distinguishes independent counters (e.g. this class's own
	 * auth-failure counter vs Perdita_MCP_OAuth's registration/token
	 * counters) so hammering one endpoint never locks out another.
	 *
	 * @param string $prefix Namespacing prefix for this particular counter.
	 * @param string $ip     Client IP.
	 * @return string
	 */
	public static function rate_limit_key( $prefix, $ip ) {
		// One IPv6 subscriber usually holds a whole /64, so each counter
		// keys on the /64 rather than handing every address its own budget.
		$ip = (string) $ip;
		if ( false !== strpos( $ip, ':' ) && false !== ( $packed = @inet_pton( $ip ) ) && 16 === strlen( $packed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.CodeAnalysis.AssignmentInCondition.Found -- invalid input simply keeps the raw string.
			$ip = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}
		return $prefix . md5( $ip );
	}

	/**
	 * Check whether the current client IP has exceeded a rate-limit cap.
	 * Generic across counters: pass this class's own RATE_LIMIT_PREFIX/MAX
	 * for the free-tier auth-failure limiter, or a different prefix/max for
	 * an independent limiter (e.g. Perdita_MCP_OAuth's registration or
	 * token-endpoint counters).
	 *
	 * @param string $prefix Namespacing prefix for this counter.
	 * @param int    $max    Cap before this returns a 429.
	 * @return true|WP_Error True when under the cap, a WP_Error (429) otherwise.
	 */
	public static function rate_limit_check( $prefix, $max ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return true;
		}
		$count = (int) get_transient( self::rate_limit_key( $prefix, $ip ) );
		if ( $count >= $max ) {
			return new WP_Error(
				'perdita_mcp_rate_limited',
				__( 'Too many requests from this address. Please try again later.', 'perdita-core' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Record one rate-limited event (a failed auth attempt, a registration,
	 * whatever the caller's counter represents) for the current client IP
	 * under the given prefix. The counter self-expires after $window, so a
	 * burst only ever blocks that IP for the configured window, never
	 * permanently.
	 *
	 * @param string $prefix Namespacing prefix for this counter.
	 * @param int    $window TTL in seconds for the counter transient.
	 */
	public static function rate_limit_record( $prefix, $window ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}
		$key   = self::rate_limit_key( $prefix, $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, $window );
	}

	/* ==========================================================
	 * Tool definitions (tools/list)
	 * ========================================================== */

	/**
	 * Every tool this server exposes, as MCP Tool objects (name, description,
	 * inputSchema). get_form_entries is included only when the Forms module
	 * is enabled, mirroring how other cross-module checks in this codebase
	 * gate on is_enabled() directly (see Perdita_Modules::is_enabled()).
	 *
	 * Public static (and takes $core explicitly rather than reading
	 * $this->core) so Perdita_MCP_OAuth's consent screen can call it
	 * directly for its tool-list copy, with no Reflection and no need to
	 * instantiate a Perdita_MCP object at all, which previously cascaded
	 * into a second, throwaway Perdita_MCP_OAuth construction mid-request
	 * (re-registering rest_api_init/init/query_vars/template_redirect
	 * hooks a second time). Independently flagged by six review passes.
	 *
	 * @param Perdita $core Core, needed only to check whether the Forms
	 *                       module is enabled.
	 * @return array[] List of tool definitions.
	 */
	public static function tool_definitions( $core ) {
		$tools = array(
			array(
				'name'        => 'list_posts',
				'description' => __( 'List posts or pages on this site, optionally filtered by status or a search keyword. Returns a summary (not full content) for each: use get_post for the full body of a specific item.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'page' ),
							'description' => __( 'Content type to list. Defaults to "post".', 'perdita-core' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'Post status to filter by, e.g. "publish", "draft", "pending", "any". Defaults to every status the connected user is allowed to see.', 'perdita-core' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Optional keyword to filter titles/content by.', 'perdita-core' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Results per page, 1-100. Defaults to 20.', 'perdita-core' ),
						),
						'page'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page number, starting at 1. Defaults to 1.', 'perdita-core' ),
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_post',
				'description' => __( 'Get the full content of a single post or page by ID, including both the rendered HTML and the raw editor content. Very long content is cut at a per-field character ceiling and the response then says truncated: true. The "seo" key carries the stored SEO fields, which need Perdita Pro\'s SEO module to hold anything.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The post or page ID to fetch.', 'perdita-core' ),
						),
						'max_chars' => array(
							'type'        => 'integer',
							'minimum'     => 100,
							'description' => __( 'Optional. Return at most this many characters of content_raw and of content_rendered. The site sets the default ceiling (60,000 unless changed) and this can only lower it. When either field is cut short the response sets truncated: true and reports the full lengths in content_raw_length and content_rendered_length.', 'perdita-core' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'create_post',
				'description' => __( 'Create a new post or page. If the connected user is not allowed to publish content, the new item is always saved as a draft regardless of the requested status, and the response says so explicitly. The optional SEO fields need Perdita Pro\'s SEO module to be stored: without it they are accepted and ignored.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'title'     => array(
								'type'        => 'string',
								'description' => __( 'The title of the new post or page.', 'perdita-core' ),
							),
							'content'   => array(
								'type'        => 'string',
								'description' => __( 'The body content. Accepts HTML or plain paragraphs.', 'perdita-core' ),
							),
							'post_type' => array(
								'type'        => 'string',
								'enum'        => array( 'post', 'page' ),
								'description' => __( 'Content type to create. Defaults to "post".', 'perdita-core' ),
							),
							'status'    => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'publish', 'pending' ),
								'description' => __( 'Desired status. Defaults to "draft". Forced to "draft" if the connected user cannot publish.', 'perdita-core' ),
							),
						),
						self::seo_schema_properties()
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'update_post',
				'description' => __( 'Update the title, content, status, and/or SEO fields of an existing post or page. Only the fields provided are changed. The same publish-permission rule as create_post applies to status changes. The optional SEO fields need Perdita Pro\'s SEO module to be stored: without it they are accepted and ignored.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'id'      => array(
								'type'        => 'integer',
								'description' => __( 'The post or page ID to update.', 'perdita-core' ),
							),
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'New title. Omit to leave unchanged.', 'perdita-core' ),
							),
							'content' => array(
								'type'        => 'string',
								'description' => __( 'New body content. Omit to leave unchanged.', 'perdita-core' ),
							),
							'status'  => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'publish', 'pending' ),
								'description' => __( 'New status. Omit to leave unchanged. Forced to "draft" if the connected user cannot publish.', 'perdita-core' ),
							),
						),
						self::seo_schema_properties()
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'search_content',
				'description' => __( 'Read-only keyword search across posts and pages. Use this to find content by topic before fetching or editing a specific item.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array(
							'type'        => 'string',
							'description' => __( 'Keyword or phrase to search for.', 'perdita-core' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'page' ),
							'description' => __( 'Restrict to one content type. Omit to search both posts and pages.', 'perdita-core' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Results per page, 1-100. Defaults to 20.', 'perdita-core' ),
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_site_status',
				'description' => __( 'Get basic information about this site: theme name and version, WordPress version, site URL, which Perdita modules are enabled, and whether an AI provider is connected. Takes no arguments.', 'perdita-core' ),
				'inputSchema' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
		);

		if ( $core->modules->is_enabled( 'forms' ) ) {
			$tools[] = array(
				'name'        => 'get_form_entries',
				'description' => __( 'Read the submitted entries for a Perdita Forms form. Read-only: this cannot create, edit, or delete a form or its entries.', 'perdita-core' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The form\'s post ID. Find it under Forms in wp-admin.', 'perdita-core' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Entries per page, 1-100. Defaults to 20.', 'perdita-core' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
		}

		return $tools;
	}

	/* ==========================================================
	 * SEO fields on create_post / update_post
	 * ========================================================== */

	/**
	 * The optional SEO properties create_post and update_post accept, merged
	 * into both input schemas.
	 *
	 * This server never writes these itself. It hands them to the
	 * perdita_seo_update_post_fields action, which Perdita Pro's SEO module
	 * listens on, so a site without Pro accepts the arguments and stores
	 * nothing rather than failing the whole call.
	 *
	 * @return array Property definitions, keyed by argument name.
	 */
	private static function seo_schema_properties() {
		return array(
			'seo_title'        => array(
				'type'        => 'string',
				'description' => __( 'SEO title tag, if it should differ from the post title. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'seo_description'  => array(
				'type'        => 'string',
				'description' => __( 'Meta description. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'focus_keyphrase'  => array(
				'type'        => 'string',
				'description' => __( 'The phrase this content is meant to rank for. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'canonical'        => array(
				'type'        => 'string',
				'format'      => 'uri',
				'description' => __( 'Canonical URL, when this content is the copy rather than the original. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'noindex'          => array(
				'type'        => 'boolean',
				'description' => __( 'Ask search engines not to index this item. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'og_title'         => array(
				'type'        => 'string',
				'description' => __( 'Open Graph title for social shares. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'og_description'   => array(
				'type'        => 'string',
				'description' => __( 'Open Graph description for social shares. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
			'schema_type'      => array(
				'type'        => 'string',
				'description' => __( 'Schema.org type for this item, for example Article, NewsArticle, or FAQPage. Needs Perdita Pro\'s SEO module to be stored.', 'perdita-core' ),
			),
		);
	}

	/**
	 * The SEO arguments a call actually sent, unslashed and sanitized. Keys
	 * the caller left out are absent from the result, so a listener can tell
	 * "clear this field" (an empty string was sent) from "leave it alone".
	 *
	 * @param array $args Tool arguments.
	 * @return array Only the provided keys.
	 */
	private static function seo_fields_from_args( array $args ) {
		$fields = array();
		foreach ( array_keys( self::seo_schema_properties() ) as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}
			$value = $args[ $key ];
			if ( 'noindex' === $key ) {
				$fields[ $key ] = (bool) $value;
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value          = (string) $value; // JSON arguments are not slashed, so nothing to unslash.
			$fields[ $key ] = 'canonical' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}
		return $fields;
	}

	/**
	 * Hand a post's SEO fields to whatever stores them, if any were sent.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Sanitized fields from seo_fields_from_args().
	 */
	private static function dispatch_seo_fields( $post_id, array $fields ) {
		if ( empty( $fields ) ) {
			return;
		}

		/**
		 * Store SEO fields sent through the MCP server with a post.
		 *
		 * Only the keys the caller provided are present, already unslashed
		 * and sanitized. Perdita Pro's SEO module is the listener that makes
		 * this do anything; core accepts the fields and drops them.
		 *
		 * @param int   $post_id Post id.
		 * @param array $fields  Provided SEO fields.
		 */
		do_action( 'perdita_seo_update_post_fields', (int) $post_id, $fields );
	}

	/* ==========================================================
	 * Tool result helpers
	 * ========================================================== */

	/**
	 * Build a successful tool result: MCP's CallToolResult shape with a
	 * single text content block. Callers pass the text plus, in
	 * $structured, the equivalent parsed data for clients that use it.
	 *
	 * @param string $text       Human/LLM-readable summary text.
	 * @param mixed  $structured Optional structured payload mirroring $text.
	 * @return array
	 */
	private function tool_result( $text, $structured = null ) {
		$result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);
		if ( null !== $structured ) {
			$result['structuredContent'] = $structured;
		}
		return $result;
	}

	/**
	 * Build a tool execution error: a normal CallToolResult with
	 * isError: true and an actionable message, per the MCP spec's
	 * two-tier error model (this is NOT a JSON-RPC protocol error, it is a
	 * result the calling model sees and can react to/retry from).
	 *
	 * @param string $message Actionable error message: say what's wrong and, where possible, what to do about it.
	 * @return array
	 */
	private function tool_error( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * Whether a post type is one this MCP surface is allowed to touch by
	 * numeric ID. list_posts/search_content already constrain post_type at
	 * the WP_Query level, but get_post/update_post look up an arbitrary ID
	 * directly, and every post type (including attachments, nav menu
	 * items, or any custom post type a plugin registers) shares the SAME
	 * global ID space in wp_posts, so without this check a client could
	 * read or edit content this server's own schema never advertises.
	 *
	 * @param string $post_type The post type to check.
	 * @return bool
	 */
	private static function is_allowed_post_type( $post_type ) {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}

	/* ==========================================================
	 * Tool implementations
	 * ========================================================== */

	/**
	 * list_posts.
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_list_posts( array $args ) {
		$post_type = isset( $args['post_type'] ) && 'page' === $args['post_type'] ? 'page' : 'post';
		$per_page  = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$search    = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$status = isset( $args['status'] ) && '' !== $args['status'] ? sanitize_key( (string) $args['status'] ) : 'any';

		// 'post_status' => 'any' applies NO capability check of its own: it
		// expands to "every status not flagged exclude_from_search", so on
		// its own it happily hands every author's drafts, pending, and
		// private posts to whoever asks. A Subscriber-bound MCP token could
		// read the whole editorial pipeline.
		//
		// Two things fix that, and both are needed. 'perm' => 'readable' is
		// what makes WP_Query gate private statuses on read_private_posts
		// (it applies to the explicit-status path, e.g. a caller asking for
		// status=private, and does nothing at all while the status is
		// 'any'). The author scope below is what actually contains the
		// 'any' case. The trade-off is deliberate: a token bound to a
		// Contributor or Author now lists only that user's own posts
		// through this tool. Published posts stay reachable by id through
		// get_post, which runs its own read_post check.
		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'perm'           => 'readable',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		// Belt and braces on top of 'perm' => 'readable': a user who cannot
		// edit other people's posts only ever sees their own non-public
		// content, so scope the whole query to them. This also stops a
		// future status registered without exclude_from_search from
		// widening what a low-privilege token can list.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->post_summary( $post );
		}

		$text = sprintf(
			/* translators: 1: number of results, 2: post type, 3: total matches, 4: page number */
			__( 'Found %1$d %2$s (of %3$d total), page %4$d.', 'perdita-core' ),
			count( $items ),
			$post_type,
			(int) $query->found_posts,
			$page
		);

		return $this->tool_result(
			$text,
			array(
				'total'    => (int) $query->found_posts,
				'page'     => $page,
				'per_page' => $per_page,
				'items'    => $items,
			)
		);
	}

	/**
	 * get_post.
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_get_post( array $args ) {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( $id <= 0 ) {
			return $this->tool_error( __( 'Provide a valid numeric "id".', 'perdita-core' ) );
		}

		$post = get_post( $id );
		if ( ! $post || ! self::is_allowed_post_type( $post->post_type ) ) {
			return $this->tool_error( sprintf(
				/* translators: %d: post ID. */
				__( 'No post or page found with id %d.', 'perdita-core' ),
				$id
			) );
		}

		// Visibility check: a public post is readable by anyone who can
		// authenticate at all, anything else (draft/private/pending/etc)
		// requires the connected user to actually hold read_post for this
		// specific post. This is what stops an MCP client bound to a
		// low-privilege user from reading another user's private drafts.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $id ) ) {
			return $this->tool_error( __( 'You do not have permission to read this post. It may be a draft or private post owned by another user.', 'perdita-core' ) );
		}
		// A password-protected post is published, so the check above passes,
		// but its body is meant only for visitors who know the password. A
		// bearer request never carries the password cookie, and core's REST
		// API hides such content unless the user can edit the post. Same
		// rule here, or any Contributor with a token could read them all.
		if ( post_password_required( $post ) && ! current_user_can( 'edit_post', $id ) ) {
			return $this->tool_error( __( 'This post is password protected.', 'perdita-core' ) );
		}

		$max_chars = self::get_post_max_chars( $args );
		$raw       = (string) $post->post_content;
		$rendered  = (string) apply_filters( 'the_content', $post->post_content );
		$raw_len   = mb_strlen( $raw );
		$rend_len  = mb_strlen( $rendered );
		$truncated = $max_chars > 0 && ( $raw_len > $max_chars || $rend_len > $max_chars );

		$data = array(
			'id'                      => $post->ID,
			'title'                   => get_the_title( $post ),
			'status'                  => $post->post_status,
			'type'                    => $post->post_type,
			'date'                    => $post->post_date,
			'author'                  => (int) $post->post_author,
			'author_name'             => (string) get_the_author_meta( 'display_name', $post->post_author ),
			'permalink'               => get_permalink( $post ),
			'content_raw'             => self::clip( $raw, $max_chars ),
			'content_rendered'        => self::clip( $rendered, $max_chars ),
			'content_raw_length'      => $raw_len,
			'content_rendered_length' => $rend_len,
			'content_max_chars'       => $max_chars,
			'truncated'               => $truncated,
			'excerpt'                 => get_the_excerpt( $post ),
			/**
			 * The post's stored SEO fields, for MCP clients reading content
			 * before rewriting it. Core has nowhere to keep these, so it
			 * ships an empty array. Perdita Pro's SEO module is the filter
			 * that fills it in.
			 *
			 * @param array $fields  SEO fields, keyed as create_post and
			 *                       update_post accept them.
			 * @param int   $post_id Post id.
			 */
			'seo'                     => (array) apply_filters( 'perdita_seo_get_post_fields', array(), (int) $post->ID ),
		);

		$summary = sprintf(
			/* translators: 1: post title, 2: post status. */
			__( '"%1$s" (%2$s)', 'perdita-core' ),
			$data['title'],
			$data['status']
		);
		if ( $truncated ) {
			$summary .= ' ' . sprintf(
				/* translators: 1: character ceiling, 2: full raw content length, 3: full rendered content length. */
				__( 'Content was cut to %1$d characters per field (the full raw content is %2$d characters, the rendered HTML %3$d). Pass a larger max_chars, up to the site ceiling, to see more.', 'perdita-core' ),
				$max_chars,
				$raw_len,
				$rend_len
			);
		}

		return $this->tool_result( $summary, $data );
	}

	/**
	 * The content ceiling for one get_post call: the site's (filtered)
	 * default, lowered but never raised by the call's own max_chars.
	 *
	 * @param array $args Tool arguments.
	 * @return int Characters per field, 0 for no ceiling.
	 */
	private static function get_post_max_chars( array $args ) {
		/**
		 * The per-field character ceiling get_post applies to content_raw
		 * and content_rendered. Return 0 to remove it.
		 *
		 * @param int $max_chars Default self::GET_POST_MAX_CHARS.
		 */
		$site_max = max( 0, (int) apply_filters( 'perdita_mcp_get_post_max_chars', self::GET_POST_MAX_CHARS ) );

		$requested = isset( $args['max_chars'] ) ? (int) $args['max_chars'] : 0;
		if ( $requested <= 0 ) {
			return $site_max;
		}
		$requested = max( 100, $requested );
		return 0 === $site_max ? $requested : min( $site_max, $requested );
	}

	/**
	 * The first $max_chars characters of $text (whole text when $max_chars
	 * is 0). Multibyte-safe so a cut never lands inside a character.
	 *
	 * @param string $text      Text to clip.
	 * @param int    $max_chars Ceiling, 0 for none.
	 * @return string
	 */
	private static function clip( $text, $max_chars ) {
		if ( $max_chars <= 0 || mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}
		return mb_substr( $text, 0, $max_chars );
	}

	/**
	 * create_post.
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_create_post( array $args ) {
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';
		if ( '' === trim( $title ) ) {
			return $this->tool_error( __( 'The "title" argument is required and cannot be empty.', 'perdita-core' ) );
		}

		$post_type = isset( $args['post_type'] ) && 'page' === $args['post_type'] ? 'page' : 'post';

		$required_cap = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $required_cap ) ) {
			return $this->tool_error( sprintf(
				/* translators: %s: post type. */
				__( 'The connected WordPress user does not have permission to create a %s.', 'perdita-core' ),
				$post_type
			) );
		}

		if ( ! $this->is_valid_status_arg( $args ) ) {
			return $this->tool_error( sprintf(
				/* translators: %s: the invalid status value the caller sent. */
				__( '"%s" is not a valid status. Use "draft", "publish", or "pending".', 'perdita-core' ),
				is_scalar( $args['status'] ) ? (string) $args['status'] : wp_json_encode( $args['status'] )
			) );
		}
		list( $status, $forced_to_draft ) = $this->resolve_requested_status( $args );

		// wp_insert_post() unslashes what it is given, and MCP arguments come
		// from JSON, which is never slashed. Without wp_slash() a backslash in
		// the content (a Windows path, LaTeX, a JSON sample) was dropped.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => sanitize_text_field( $title ),
					'post_content' => wp_kses_post( $content ),
					'post_type'    => $post_type,
					'post_status'  => $status,
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->tool_error( sprintf(
				/* translators: %s: underlying WordPress error message. */
				__( 'Could not create the post: %s', 'perdita-core' ),
				$post_id->get_error_message()
			) );
		}

		self::dispatch_seo_fields( $post_id, self::seo_fields_from_args( $args ) );

		$note = $forced_to_draft
			? __( ' Note: the connected user cannot publish content, so this was saved as a draft instead of the requested status.', 'perdita-core' )
			: '';

		$post = get_post( $post_id );

		return $this->tool_result(
			sprintf(
				/* translators: 1: post type, 2: post ID, 3: status, 4: any forced-draft note. */
				__( 'Created %1$s #%2$d with status "%3$s".%4$s', 'perdita-core' ),
				$post_type,
				$post_id,
				$status,
				$note
			),
			array(
				'id'              => (int) $post_id,
				'status'          => $status,
				'forced_to_draft' => $forced_to_draft,
				'permalink'       => get_permalink( $post_id ),
			)
		);
	}

	/**
	 * update_post.
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_update_post( array $args ) {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( $id <= 0 ) {
			return $this->tool_error( __( 'Provide a valid numeric "id".', 'perdita-core' ) );
		}

		$post = get_post( $id );
		if ( ! $post || ! self::is_allowed_post_type( $post->post_type ) ) {
			return $this->tool_error( sprintf(
				/* translators: %d: post ID. */
				__( 'No post or page found with id %d.', 'perdita-core' ),
				$id
			) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->tool_error( __( 'You do not have permission to edit this post.', 'perdita-core' ) );
		}

		$update = array( 'ID' => $id );

		if ( array_key_exists( 'title', $args ) ) {
			$update['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( array_key_exists( 'content', $args ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['content'] );
		}

		$forced_to_draft = false;
		if ( array_key_exists( 'status', $args ) ) {
			if ( ! $this->is_valid_status_arg( $args ) ) {
				return $this->tool_error( sprintf(
					/* translators: %s: the invalid status value the caller sent. */
					__( '"%s" is not a valid status. Use "draft", "publish", or "pending".', 'perdita-core' ),
					is_scalar( $args['status'] ) ? (string) $args['status'] : wp_json_encode( $args['status'] )
				) );
			}
			list( $status, $forced_to_draft ) = $this->resolve_requested_status( $args );
			$update['post_status'] = $status;
		}

		$seo_fields = self::seo_fields_from_args( $args );

		if ( count( $update ) === 1 && empty( $seo_fields ) ) {
			return $this->tool_error( __( 'Provide at least one of "title", "content", "status", or an SEO field to update.', 'perdita-core' ) );
		}

		// An SEO-only call has nothing for wp_update_post to write, so it
		// skips the post write entirely rather than saving a revision and
		// bumping post_modified for a change that never touched the post row.
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true ); // Unslashed JSON in, see create_post.
			if ( is_wp_error( $result ) ) {
				return $this->tool_error( sprintf(
					/* translators: %s: underlying WordPress error message. */
					__( 'Could not update the post: %s', 'perdita-core' ),
					$result->get_error_message()
				) );
			}
		}

		self::dispatch_seo_fields( $id, $seo_fields );

		$note = $forced_to_draft
			? __( ' Note: the connected user cannot publish content, so the status was set to "draft" instead of the requested status.', 'perdita-core' )
			: '';

		$updated = get_post( $id );

		return $this->tool_result(
			sprintf(
				/* translators: 1: post ID, 2: any forced-draft note. */
				__( 'Updated post #%1$d.%2$s', 'perdita-core' ),
				$id,
				$note
			),
			array(
				'id'              => $id,
				'status'          => $updated->post_status,
				'forced_to_draft' => $forced_to_draft,
				'permalink'       => get_permalink( $id ),
			)
		);
	}

	/**
	 * Resolve the status a create/update call should actually use: honors
	 * the requested status only if the connected user can publish_posts,
	 * otherwise forces "draft" and tells the caller it did so via
	 * forced_to_draft (per the task requirement: never succeed with a
	 * different status than asked without saying so).
	 *
	 * Callers MUST validate that $args['status'], when present, is one of
	 * the three recognized values via is_valid_status_arg() BEFORE calling
	 * this, and return a tool_error() themselves otherwise. This method
	 * assumes the value is already valid: it has no "unrecognized value"
	 * branch of its own, so it can never again silently coerce a typo'd or
	 * garbled status into "draft" with forced_to_draft left false, the
	 * exact bug this split was introduced to close.
	 *
	 * @param array $args Tool arguments (reads 'status').
	 * @return array [ string $status, bool $forced_to_draft ]
	 */
	private function resolve_requested_status( array $args ) {
		$requested = isset( $args['status'] ) ? $args['status'] : 'draft';

		if ( 'publish' === $requested && ! current_user_can( 'publish_posts' ) ) {
			return array( 'draft', true );
		}

		return array( $requested, false );
	}

	/**
	 * Whether $args['status'], if present at all, is one of the three
	 * statuses this server understands. Absence is valid (callers default
	 * to "draft"); presence with any other value is not, and must be
	 * rejected by the caller rather than silently coerced.
	 *
	 * @param array $args Tool arguments.
	 * @return bool
	 */
	private function is_valid_status_arg( array $args ) {
		return ! array_key_exists( 'status', $args )
			|| in_array( $args['status'], array( 'draft', 'publish', 'pending' ), true );
	}

	/**
	 * search_content.
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_search_content( array $args ) {
		$query_str = isset( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		if ( '' === $query_str ) {
			return $this->tool_error( __( 'Provide a non-empty "query" to search for.', 'perdita-core' ) );
		}

		$per_page  = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$post_type = isset( $args['post_type'] ) && in_array( $args['post_type'], array( 'post', 'page' ), true )
			? $args['post_type']
			: array( 'post', 'page' );

		// Same two-part fix as tool_list_posts(): 'perm' => 'readable' for
		// the private-status handling, and an author scope for everyone who
		// cannot edit other people's posts. Without the author scope this
		// search returned every author's drafts and pending posts to any
		// authenticated caller, since 'post_status' => 'any' applies no
		// capability check of its own.
		$query_args = array(
			's'              => $query_str,
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'perm'           => 'readable',
			'posts_per_page' => $per_page,
			'no_found_rows'  => false,
		);
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->post_summary( $post );
		}

		return $this->tool_result(
			sprintf(
				/* translators: 1: number of results, 2: search query, 3: total matches. */
				__( 'Found %1$d result(s) for "%2$s" (of %3$d total matches).', 'perdita-core' ),
				count( $items ),
				$query_str,
				(int) $query->found_posts
			),
			array(
				'total' => (int) $query->found_posts,
				'items' => $items,
			)
		);
	}

	/**
	 * get_site_status.
	 *
	 * @return array CallToolResult.
	 */
	private function tool_get_site_status() {
		global $wp_version;

		$modules = array();
		foreach ( $this->core->modules->all() as $id => $descriptor ) {
			if ( $this->core->modules->is_enabled( $id ) ) {
				$modules[] = array(
					'id'    => $id,
					'label' => (string) $descriptor['label'],
				);
			}
		}

		// Perdita_AI::is_ready() is the actual, existing source of truth for
		// this (also what the onboarding wizard checks) - there is no
		// separate 'ai.api_key_set' settings flag.
		$ai_connected = (bool) $this->core->ai->is_ready();

		// Two versions, because there are two pieces now. theme_version is the
		// Perdita theme's, and it keeps that name and that meaning: an AI
		// client reading it is asking what design layer the site runs.
		// plugin_version is this plugin's, which is what actually decides
		// which MCP tools exist. Reporting the plugin's number under
		// theme_version (which is what a mechanical PERDITA_VERSION ->
		// PERDITA_CORE_VERSION rename produced during the split) would answer
		// a question nobody asked.
		$data = array(
			'theme_name'        => 'Perdita',
			'theme_version'     => defined( 'PERDITA_VERSION' ) ? PERDITA_VERSION : '',
			'plugin_name'       => 'Perdita Core',
			'plugin_version'    => defined( 'PERDITA_CORE_VERSION' ) ? PERDITA_CORE_VERSION : '',
			'wordpress_version' => $wp_version,
			'site_url'          => site_url(),
			'enabled_modules'   => $modules,
			'ai_connected'      => $ai_connected,
		);

		return $this->tool_result(
			sprintf(
				/* translators: 1: theme version, 2: Perdita Core plugin version, 3: WordPress version, 4: number of enabled modules. */
				__( 'Perdita %1$s with Perdita Core %2$s on WordPress %3$s, %4$d module(s) enabled.', 'perdita-core' ),
				$data['theme_version'],
				$data['plugin_version'],
				$data['wordpress_version'],
				count( $modules )
			),
			$data
		);
	}

	/**
	 * get_form_entries. Only reachable when tool_definitions() included it,
	 * i.e. the Forms module is enabled, but this method re-checks
	 * independently since tools/call is a separate request from tools/list
	 * and nothing guarantees a client called tools/list first (or that the
	 * module wasn't disabled in between).
	 *
	 * @param array $args Tool arguments.
	 * @return array CallToolResult.
	 */
	private function tool_get_form_entries( array $args ) {
		if ( ! $this->core->modules->is_enabled( 'forms' ) || ! class_exists( 'Perdita_Forms' ) ) {
			return $this->tool_error( __( 'The Forms module is not enabled on this site, so there are no form entries to read.', 'perdita-core' ) );
		}

		// Matches the capability Perdita_Forms_Admin::entries_page() itself
		// requires for the equivalent wp-admin screen, so an MCP client can
		// never see more than the built-in Entries viewer would show the
		// same user.
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->tool_error( __( 'You do not have permission to view form entries.', 'perdita-core' ) );
		}

		$form_id = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;
		if ( $form_id <= 0 ) {
			return $this->tool_error( __( 'Provide a valid numeric "form_id".', 'perdita-core' ) );
		}

		$form = get_post( $form_id );
		if ( ! $form || Perdita_Forms::CPT !== $form->post_type ) {
			return $this->tool_error( sprintf(
				/* translators: %d: form ID. */
				__( 'No form found with id %d.', 'perdita-core' ),
				$form_id
			) );
		}

		$per_page = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$total    = Perdita_Forms::entry_count( $form_id );
		$rows     = Perdita_Forms::entries( $form_id, $per_page, 0 );

		$entries = array();
		foreach ( $rows as $row ) {
			$decoded   = json_decode( $row->data, true );
			$entries[] = array(
				'id'      => (int) $row->id,
				'created' => $row->created,
				'data'    => is_array( $decoded ) ? $decoded : array(),
			);
		}

		return $this->tool_result(
			sprintf(
				/* translators: 1: number of entries returned, 2: form title, 3: total entry count. */
				__( 'Found %1$d entr(y/ies) for "%2$s" (of %3$d total).', 'perdita-core' ),
				count( $entries ),
				get_the_title( $form ),
				$total
			),
			array(
				'form_id' => $form_id,
				'total'   => $total,
				'entries' => $entries,
			)
		);
	}

	/* ==========================================================
	 * Shared formatting
	 * ========================================================== */

	/**
	 * A lightweight summary of a post for list_posts/search_content: enough
	 * to identify and triage a result without the cost/verbosity of full
	 * content, matching the MCP best-practice of returning focused, concise
	 * tool output rather than everything at once.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function post_summary( $post ) {
		return array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'status'    => $post->post_status,
			'type'      => $post->post_type,
			'date'      => $post->post_date,
			'excerpt'   => get_the_excerpt( $post ),
			'permalink' => get_permalink( $post ),
		);
	}
}
