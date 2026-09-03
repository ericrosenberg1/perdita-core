<?php
/**
 * MCP Server admin: generate/revoke the free-tier API key, show the server
 * URL, and a plain-language setup guide for pasting the connection into an
 * MCP-compatible AI client.
 *
 * Every state-changing action here (generate, revoke) goes through
 * admin-post.php behind a capability check and a nonce, matching every other
 * module's admin screen in this codebase (see
 * class-perdita-search-console-admin.php). The generated key is shown to the
 * admin exactly once, in a copy-able read-only field, immediately after
 * generation: it is never stored in $_GET/$_POST beyond that one redirect
 * (it rides in a short-lived, single-read transient keyed to the current
 * user instead, see KEY_DISPLAY_PREFIX below), never logged, and never
 * appears again after this one page load.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_MCP_Admin {

	/**
	 * The MCP module core.
	 *
	 * @var Perdita_MCP
	 */
	private $main;

	/**
	 * The Perdita core.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * The settings-page slug.
	 */
	const PAGE = 'perdita-mcp';

	/**
	 * admin-post actions.
	 */
	const ACTION_GENERATE = 'perdita_mcp_generate_key';
	const ACTION_REVOKE   = 'perdita_mcp_revoke_key';

	/**
	 * admin-post action for revoking one connected OAuth app (Phase 2).
	 * Nonce is per-client_id (see render_connected_apps()), so a stray
	 * cross-site request forged against one client's revoke button can never
	 * be replayed to revoke a different client this same admin has open in
	 * another tab.
	 */
	const ACTION_REVOKE_CONNECTION = 'perdita_mcp_revoke_connection';

	/**
	 * Transient prefix used to hand the plaintext key from the generate
	 * handler to the very next page render, and only that one. Keyed per
	 * user so one admin's freshly generated key can never be shown to a
	 * different admin loading the same page concurrently. The transient is
	 * deleted the moment it's read (see maybe_show_new_key()), and expires
	 * on its own after a short window even if the page is never reloaded, so
	 * the plaintext key never lingers in the database.
	 */
	const KEY_DISPLAY_PREFIX = 'perdita_mcp_newkey_';

	/**
	 * How long the one-time display transient may live before it self-expires,
	 * for the case where an admin generates a key and never reloads the page.
	 */
	const KEY_DISPLAY_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Perdita_MCP $main Module core.
	 * @param Perdita     $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION_GENERATE, array( $this, 'handle_generate' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE_CONNECTION, array( $this, 'handle_revoke_connection' ) );
		// Any logged-in user's own Profile screen, not just admins: see
		// profile_connected_apps()'s docblock for why.
		add_action( 'show_user_profile', array( $this, 'profile_connected_apps' ) );
	}

	/**
	 * Register the settings submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'MCP Server', 'perdita-core' ),
			__( 'MCP Server', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ---------- rendering ---------- */

	/**
	 * Render the settings screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MCP Server', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Let Claude, ChatGPT, or any MCP-compatible AI client manage this site directly from a chat conversation. It can read and write posts and pages, search content, and check site status, all limited to what your connected WordPress account is allowed to do.', 'perdita-core' ) . '</p>';

		$this->render_notices();

		$new_key = $this->maybe_get_new_key();
		if ( '' !== $new_key ) {
			$this->render_new_key( $new_key );
		}

		$this->render_key_status();
		$this->render_setup_guide();
		$this->render_connected_apps();

		echo '</div>';
	}

	/**
	 * Render redirect-carried success notices.
	 */
	private function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message on a redirect, no state change.
		$msg = isset( $_GET['perdita_mcp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['perdita_mcp_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Read (and immediately delete) the one-time plaintext key transient left
	 * by handle_generate(), if this page load is the redirect right after
	 * generating one. Deleting on read means even a page refresh will not
	 * show the key a second time, matching the "you won't see it again"
	 * warning shown alongside it.
	 *
	 * @return string The plaintext key, or '' if none is pending display.
	 */
	private function maybe_get_new_key() {
		$transient_key = self::KEY_DISPLAY_PREFIX . get_current_user_id();
		$key           = get_transient( $transient_key );
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}
		delete_transient( $transient_key );
		return $key;
	}

	/**
	 * Render the one-time "here is your new key" panel.
	 *
	 * @param string $key Plaintext key.
	 */
	private function render_new_key( $key ) {
		echo '<div class="notice notice-warning" style="padding:16px;">';
		echo '<h2 id="perdita-mcp-new-key-heading" style="margin-top:0;">' . esc_html__( 'Your new API key', 'perdita-core' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Copy this key now. For your security, it will not be shown again.', 'perdita-core' ) . '</strong></p>';
		echo '<input type="text" readonly="readonly" onclick="this.select();" aria-labelledby="perdita-mcp-new-key-heading" value="' . esc_attr( $key ) . '" class="large-text code" style="font-size:14px;" />';
		echo '</div>';
	}

	/**
	 * Render the current key status (active/none) plus the Generate/Revoke
	 * buttons and the server URL.
	 */
	private function render_key_status() {
		$has_key = Perdita_MCP::has_key();

		echo '<h2>' . esc_html__( 'API key', 'perdita-core' ) . '</h2>';

		if ( $has_key ) {
			$settings = Perdita_MCP::settings();
			$user     = get_userdata( (int) $settings['user_id'] );
			$when     = (int) $settings['generated'] > 0
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $settings['generated'] )
				: __( 'unknown time', 'perdita-core' );

			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: WordPress user display name, 2: date/time the key was generated. */
					__( 'A key is active, bound to %1$s, generated %2$s.', 'perdita-core' ),
					$user ? $user->display_name : __( '(deleted user)', 'perdita-core' ),
					$when
				)
			) . '</p>';
			echo '<p class="description">' . esc_html__( 'Only the key\'s one-way hash is stored. It cannot be displayed again, only replaced or revoked.', 'perdita-core' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No API key has been generated yet.', 'perdita-core' ) . '</p>';
		}

		echo '<p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-right:8px;" onsubmit="return ' . ( $has_key ? 'confirm(' . esc_attr( wp_json_encode( __( 'Generate a new key? The current key will stop working immediately.', 'perdita-core' ) ) ) . ')' : 'true' ) . ';">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_GENERATE ) . '" />';
		wp_nonce_field( self::ACTION_GENERATE );
		submit_button( $has_key ? __( 'Regenerate key', 'perdita-core' ) : __( 'Generate API key', 'perdita-core' ), 'primary', 'submit', false );
		echo '</form>';

		if ( $has_key ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Revoke the API key? Any connected AI client will stop working until a new key is generated and reconfigured.', 'perdita-core' ) ) ) . ');">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVOKE ) . '" />';
			wp_nonce_field( self::ACTION_REVOKE );
			submit_button( __( 'Revoke key', 'perdita-core' ), 'delete', 'submit', false );
			echo '</form>';
		}

		echo '</p>';

		echo '<h3 id="perdita-mcp-server-url-heading">' . esc_html__( 'Server URL', 'perdita-core' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Paste this exact URL into your AI client\'s MCP server configuration.', 'perdita-core' ) . '</p>';
		echo '<input type="text" readonly="readonly" onclick="this.select();" aria-labelledby="perdita-mcp-server-url-heading" value="' . esc_attr( rest_url( 'perdita/v1/mcp' ) ) . '" class="large-text code" />';
	}

	/**
	 * Render the plain-language setup guide, including a Claude Desktop
	 * config JSON snippet.
	 *
	 * HONESTY NOTE ON THE JSON SHAPE BELOW: Claude Desktop's
	 * claude_desktop_config.json historically only accepted LOCAL
	 * (stdio/command-launched) MCP servers directly in mcpServers, and reaches
	 * a remote HTTP server like this one through a local stdio bridge
	 * process (e.g. npx mcp-remote) rather than a native "url + custom
	 * header" entry. The exact current shape of Claude Desktop's native
	 * remote-MCP config (if/when it supports one directly, without a bridge)
	 * was not independently re-verified against a live, current build while
	 * writing this screen, so the snippet below documents the mcp-remote
	 * bridge form, which is the documented, working way to connect a custom
	 * remote server with a bearer-token header as of this writing, and notes
	 * this explicitly rather than presenting a guessed "native" shape as
	 * certain. Any MCP client that supports a remote server with a custom
	 * Authorization header natively can use the URL and header directly
	 * without a bridge.
	 */
	private function render_setup_guide() {
		$url = rest_url( 'perdita/v1/mcp' );
		?>
		<div class="card" style="max-width:760px; padding:1px 20px; margin-top:24px;">
			<h2><?php esc_html_e( 'Setup guide', 'perdita-core' ); ?></h2>
			<p>
				<?php esc_html_e( 'An MCP server lets an AI chat client call tools on this site (read/write posts and pages, search content, check status) instead of you copying and pasting into wp-admin. Most clients need the server URL above and your API key as a bearer token.', 'perdita-core' ); ?>
			</p>

			<h3 id="perdita-mcp-claude-desktop-heading"><?php esc_html_e( 'Claude Desktop', 'perdita-core' ); ?></h3>
			<p>
				<?php esc_html_e( 'Claude Desktop connects to a remote server like this one through a small local bridge command (mcp-remote), which forwards your bearer token as a header. Add this to your claude_desktop_config.json, then restart Claude Desktop:', 'perdita-core' ); ?>
			</p>
<?php
			$config = array(
				'mcpServers' => array(
					'perdita' => array(
						'command' => 'npx',
						'args'    => array( '-y', 'mcp-remote', $url, '--header', 'Authorization: Bearer YOUR_API_KEY_HERE' ),
					),
				),
			);
			?>
			<textarea readonly="readonly" onclick="this.select();" aria-labelledby="perdita-mcp-claude-desktop-heading" class="large-text code" rows="10" style="font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT ) ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Replace YOUR_API_KEY_HERE with the key from above. This snippet documents the mcp-remote bridge approach, the widely-used way to connect a custom remote MCP server with a bearer-token header to Claude Desktop. If your version of Claude Desktop (or another client) supports adding a remote server with a custom header natively, use the URL and header directly instead, without the bridge command.', 'perdita-core' ); ?>
			</p>

			<h3><?php esc_html_e( 'Any other MCP-compatible client', 'perdita-core' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: literal example header value, wrapped in <code>. */
					esc_html__( 'Configure the server URL above, and set an %s header using your API key.', 'perdita-core' ),
					'<code>Authorization: Bearer &lt;your key&gt;</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * "Connected apps" section: the Pro one-click-connect experience, now
	 * real. Shows the Authorization Server discovery URL for manual
	 * troubleshooting (e.g. pasting into a client that asks for it directly
	 * rather than auto-discovering it), and every OAuth client the CURRENT
	 * WP user has connected, each with a working Revoke button. This
	 * replaces what was previously an inert "Coming in Perdita Premium"
	 * card (see git history / the module's earlier state) now that
	 * class-perdita-mcp-oauth.php actually implements the flow.
	 *
	 * Deliberately scoped to the current user's own connections, not every
	 * user's site-wide: each WordPress user authorizes an MCP client with
	 * their OWN login and their OWN capabilities (see
	 * class-perdita-mcp-oauth.php's file docblock), so "my connected apps"
	 * is the correct mental model here, the same way a Google account shows
	 * you your own connected third-party apps, not every account's.
	 */
	private function render_connected_apps() {
		if ( ! class_exists( 'Perdita_MCP_OAuth' ) ) {
			return; // Defensive: the module always loads this alongside Perdita_MCP (see its constructor), but never assume that from an admin-rendering context.
		}

		echo '<div class="card" style="max-width:760px;border-left:4px solid #2563eb;margin-top:24px;padding:1px 20px;">';
		echo '<h2>' . esc_html__( 'Pro: one-click connect from claude.ai or ChatGPT', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Instead of generating and pasting an API key, an AI client that supports remote MCP servers with OAuth can connect with one click. It sends you through a normal WordPress login (if you are not already signed in) and a consent screen, then connects using your own account\'s permissions.', 'perdita-core' ) . '</p>';

		echo '<h3 id="perdita-mcp-oauth-issuer-heading">' . esc_html__( 'Authorization server URL', 'perdita-core' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Most clients discover this automatically from the server URL above. Paste this into a client that asks for the authorization server address directly.', 'perdita-core' ) . '</p>';
		echo '<input type="text" readonly="readonly" onclick="this.select();" aria-labelledby="perdita-mcp-oauth-issuer-heading" value="' . esc_attr( Perdita_MCP_OAuth::issuer() . '/.well-known/oauth-authorization-server' ) . '" class="large-text code" />';

		echo '<h3>' . esc_html__( 'Connected apps', 'perdita-core' ) . '</h3>';
		$connections = Perdita_MCP_OAuth::connections_for_user( get_current_user_id() );
		if ( empty( $connections ) ) {
			echo '<p>' . esc_html__( 'No AI client has connected via OAuth yet.', 'perdita-core' ) . '</p>';
		} else {
			$clients = Perdita_MCP_OAuth::all_clients();
			// client_name is attacker-influenced text (any registered OAuth
			// client sets its own, unvalidated for length), so this scrolls
			// horizontally rather than clipping or pushing the layout wide.
			echo '<div style="max-width:720px;overflow-x:auto;">';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Client', 'perdita-core' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'First authorized', 'perdita-core' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Last used', 'perdita-core' ) . '</th>';
			echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'perdita-core' ) . '</span></th>';
			echo '</tr></thead><tbody>';
			foreach ( $connections as $conn ) {
				$client_id   = (string) $conn['client_id'];
				$client_name = isset( $clients[ $client_id ]['client_name'] ) ? $clients[ $client_id ]['client_name'] : $client_id;
				echo '<tr>';
				echo '<td>' . esc_html( $client_name ) . '</td>';
				echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ), (int) $conn['first_authorized'] ) ) . '</td>';
				echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $conn['last_used'] ) ) . '</td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Revoke this app\'s access? It will stop working immediately.', 'perdita-core' ) ) ) . ');">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVOKE_CONNECTION ) . '" />';
				echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '" />';
				wp_nonce_field( self::ACTION_REVOKE_CONNECTION . '_' . $client_id );
				submit_button(
					__( 'Revoke', 'perdita-core' ),
					'delete small',
					'submit',
					false,
					array(
						/* translators: %s: the connected app's client name, so multiple Revoke buttons on the same screen each have a distinct accessible name. */
						'aria-label' => sprintf( __( 'Revoke access for %s', 'perdita-core' ), $client_name ),
					)
				);
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		echo '</div>';
	}

	/* ---------- admin-post handlers ---------- */

	/**
	 * Generate (or regenerate) the API key, bound to the current admin.
	 * Stashes the plaintext in a short-lived, per-user, single-read
	 * transient so the very next page load (this same redirect) can display
	 * it once. The key itself is never put in the redirect URL or any other
	 * place that could end up in a browser history entry, access log, or
	 * Referer header.
	 */
	public function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_GENERATE );

		$key = Perdita_MCP::generate_api_key( get_current_user_id() );
		set_transient( self::KEY_DISPLAY_PREFIX . get_current_user_id(), $key, self::KEY_DISPLAY_TTL );

		wp_safe_redirect(
			add_query_arg(
				'perdita_mcp_msg',
				rawurlencode( __( 'New API key generated.', 'perdita-core' ) ),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Revoke the active API key.
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_REVOKE );

		Perdita_MCP::revoke_api_key();

		wp_safe_redirect(
			add_query_arg(
				'perdita_mcp_msg',
				rawurlencode( __( 'API key revoked.', 'perdita-core' ) ),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Revoke one connected OAuth app (Phase 2) for the CURRENT user. Gated on
	 * being logged in, not on manage_options: a connection is created by
	 * whichever WP user authorized it through the /authorize consent screen
	 * (which itself only requires is_user_logged_in(), see
	 * class-perdita-mcp-oauth.php), so a Contributor or Editor who connects
	 * an AI client to their own account must be able to revoke it themselves
	 * too, the same self-service expectation WordPress core itself sets for
	 * a user's own Application Passwords on their profile screen. The real
	 * security boundary here is OWNERSHIP, not role: verified below by
	 * scoping the lookup to connections_for_user( get_current_user_id() )
	 * rather than trusting the posted client_id to belong to this user just
	 * because it was submitted. See profile_connected_apps() for the
	 * non-admin-reachable entry point into this same handler.
	 */
	public function handle_revoke_connection() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		check_admin_referer( self::ACTION_REVOKE_CONNECTION . '_' . $client_id );

		$user_id = get_current_user_id();

		if ( '' !== $client_id && Perdita_MCP_OAuth::user_owns_connection( $user_id, $client_id ) ) {
			Perdita_MCP_OAuth::revoke_connection( $user_id, $client_id );
		}

		// Send an admin back to the MCP settings screen; anyone else (who
		// cannot reach that manage_options-gated page at all) back to their
		// own profile screen, where profile_connected_apps() renders the
		// same list.
		$redirect_to = current_user_can( 'manage_options' ) ? $this->page_url() : admin_url( 'profile.php' );

		wp_safe_redirect(
			add_query_arg(
				'perdita_mcp_msg',
				rawurlencode( __( 'App access revoked.', 'perdita-core' ) ),
				$redirect_to
			)
		);
		exit;
	}

	/* ---------- profile screen: self-service for non-admin users ---------- */

	/**
	 * Render the current user's connected OAuth apps on their own Profile
	 * screen (wp-admin/profile.php), reachable by every logged-in user
	 * regardless of role. This is deliberately hooked to show_user_profile
	 * only, not edit_user_profile: the former fires only when a user is
	 * viewing their OWN profile, which is exactly the scope
	 * connections_for_user( get_current_user_id() ) already assumes. It is
	 * NOT hooked to edit_user_profile (an admin viewing someone ELSE's
	 * profile), since rendering "your own connections" there would be
	 * confusing at best (it would show the viewing admin's connections, not
	 * the profile being viewed).
	 *
	 * @param WP_User $user The user whose profile is being viewed (always the current user, see above).
	 */
	public function profile_connected_apps( $user ) {
		unset( $user );
		if ( ! class_exists( 'Perdita_MCP_OAuth' ) || ! perdita_core()->modules->is_enabled( 'mcp' ) ) {
			return;
		}

		$connections = Perdita_MCP_OAuth::connections_for_user( get_current_user_id() );
		if ( empty( $connections ) ) {
			return; // Nothing to show; don't clutter the profile screen for a user who has never connected anything.
		}

		$clients = Perdita_MCP_OAuth::all_clients();
		echo '<h2>' . esc_html__( 'Connected AI apps (MCP)', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'AI clients you have connected via OAuth to manage this site using your own WordPress permissions.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $connections as $conn ) {
			$client_id   = (string) $conn['client_id'];
			$client_name = isset( $clients[ $client_id ]['client_name'] ) ? $clients[ $client_id ]['client_name'] : $client_id;
			echo '<tr><th scope="row">' . esc_html( $client_name ) . '</th><td>';
			echo esc_html(
				sprintf(
					/* translators: 1: date first authorized, 2: date last used. */
					__( 'Connected %1$s, last used %2$s.', 'perdita-core' ),
					date_i18n( get_option( 'date_format' ), (int) $conn['first_authorized'] ),
					date_i18n( get_option( 'date_format' ), (int) $conn['last_used'] )
				)
			);
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:8px;" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Revoke this app\'s access? It will stop working immediately.', 'perdita-core' ) ) ) . ');">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVOKE_CONNECTION ) . '" />';
			echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '" />';
			wp_nonce_field( self::ACTION_REVOKE_CONNECTION . '_' . $client_id );
			submit_button(
				__( 'Revoke', 'perdita-core' ),
				'delete small',
				'submit',
				false,
				array(
					/* translators: %s: the connected app's client name, so multiple Revoke buttons on the same screen each have a distinct accessible name. */
					'aria-label' => sprintf( __( 'Revoke access for %s', 'perdita-core' ), $client_name ),
				)
			);
			echo '</form>';
			echo '</td></tr>';
		}
		echo '</table>';
	}
}
