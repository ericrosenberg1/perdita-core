<?php
/**
 * Search Console admin: settings screen, site picker, and the dashboard.
 *
 * A submenu under Perdita for the Google OAuth Client ID/Secret, the
 * "Connect to Search Console" button, the property picker, and the resulting
 * clicks/impressions/queries dashboard. Every state-changing action goes
 * through admin-post.php with a capability check and a nonce; the OAuth
 * start/callback handlers themselves live in
 * class-perdita-search-console-oauth.php.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Search_Console_Admin {

	/**
	 * The Search Console module core.
	 *
	 * @var Perdita_Search_Console
	 */
	private $main;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * The settings-page slug.
	 */
	const PAGE = 'perdita-search-console';

	/**
	 * admin-post actions this class handles directly (OAuth start/callback
	 * are registered by Perdita_Search_Console_OAuth instead).
	 */
	const ACTION_SAVE_CLIENT = 'perdita_sc_save_client';
	const ACTION_SET_SITE    = 'perdita_sc_set_site';
	const ACTION_REFRESH     = 'perdita_sc_refresh';
	const ACTION_DISCONNECT  = 'perdita_sc_disconnect';

	/**
	 * Constructor. Wires the menu, the admin-post handlers, and the OAuth
	 * class (kept separate for clarity, but instantiated from here since the
	 * module system only calls this admin class directly).
	 *
	 * @param Perdita_Search_Console $main Module core.
	 * @param Perdita                $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		new Perdita_Search_Console_OAuth();

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_CLIENT, array( $this, 'handle_save_client' ) );
		add_action( 'admin_post_' . self::ACTION_SET_SITE, array( $this, 'handle_set_site' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Register the settings submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Search Console', 'perdita-core' ),
			__( 'Search Console', 'perdita-core' ),
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

	/**
	 * Redirect back to the settings page with query args.
	 *
	 * @param array $args Query args to add.
	 */
	private function redirect_back( array $args ) {
		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
		exit;
	}

	/* ---------- rendering ---------- */

	/**
	 * Render the settings screen: setup guide, client id/secret form,
	 * connect button, site picker, and the data dashboard.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Perdita_Search_Console::settings();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Search Console', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pull search clicks, impressions, and top queries from Google Search Console into wp-admin. This uses your own Google Cloud OAuth app, so no credentials are shared with Perdita or any other site.', 'perdita-core' ) . '</p>';

		$this->render_notices();
		$this->render_setup_guide();
		$this->render_client_form( $s );

		if ( Perdita_Search_Console::has_client() ) {
			$this->render_connection( $s );
		}

		echo '</div>';
	}

	/**
	 * Render redirect-carried success/error notices.
	 */
	private function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags on a redirect, no state change.
		$status = isset( $_GET['perdita_sc_status'] ) ? sanitize_key( wp_unslash( $_GET['perdita_sc_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message on a redirect, no state change.
		$msg = isset( $_GET['perdita_sc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['perdita_sc_msg'] ) ) : '';

		if ( '' === $status && '' === $msg ) {
			return;
		}

		$is_error = ! in_array( $status, array( 'saved', 'connected', 'site_set', 'refreshed', 'disconnected', 'cancelled' ), true );
		// 'cancelled' (the visitor declined Google's consent screen) is a
		// neutral outcome, not an error, so it gets the info style.
		$class = $is_error ? 'notice-error' : ( 'cancelled' === $status ? 'notice-info' : 'notice-success' );

		$text = '' !== $msg ? $msg : __( 'Done.', 'perdita-core' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Render the numbered setup guide for creating a Google Cloud OAuth
	 * client. Shown above the fields since a mismatched redirect URI is the
	 * single most common Google OAuth support failure, and the exact string
	 * is easiest to get right by copying it straight from this page.
	 */
	private function render_setup_guide() {
		$redirect_uri = Perdita_Search_Console::redirect_uri();
		?>
		<div class="card" style="max-width:720px; padding:1px 20px; margin-top:16px;">
			<h2><?php esc_html_e( 'Setup guide: create your own Google OAuth app', 'perdita-core' ); ?></h2>
			<p>
				<?php esc_html_e( 'Google only allows access to a site\'s Search Console data through OAuth, and requires each app to be reviewed before it can be shared across sites. To avoid that review delay, Search Console connects using your own free Google Cloud project instead of a shared Perdita app. It takes a few minutes and only you can use the credentials you create.', 'perdita-core' ); ?>
			</p>
			<ol>
				<li><?php esc_html_e( 'Create (or choose) a project in Google Cloud Console.', 'perdita-core' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: API name */
						esc_html__( 'Enable the %s for that project.', 'perdita-core' ),
						'<strong>' . esc_html__( 'Google Search Console API', 'perdita-core' ) . '</strong>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Configure the OAuth consent screen. Choose "External", and add your own Google email as a test user. That\'s enough for a single-user install like this one, no Google review is required for test users.', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Create an OAuth Client ID of type "Web application".', 'perdita-core' ); ?></li>
				<li>
					<?php esc_html_e( 'Add this exact URL to the client\'s "Authorized redirect URIs" list. A mismatch here is the single most common reason Google OAuth setup fails, so copy it exactly:', 'perdita-core' ); ?>
					<br />
					<input type="text" readonly="readonly" onclick="this.select();" aria-label="<?php echo esc_attr__( 'OAuth redirect URI to paste into Google Cloud Console', 'perdita-core' ); ?>" value="<?php echo esc_attr( $redirect_uri ); ?>" class="large-text code" style="margin-top:6px;" />
				</li>
				<li><?php esc_html_e( 'Copy the Client ID and Client Secret from Google Cloud Console into the fields below.', 'perdita-core' ); ?></li>
			</ol>
			<p>
				<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open Google Cloud Console: Credentials', 'perdita-core' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Client ID / Client Secret form.
	 *
	 * @param array $s Current settings.
	 */
	private function render_client_form( array $s ) {
		$has_secret = '' !== (string) $s['client_secret'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px; margin-top:16px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CLIENT ); ?>" />
			<?php wp_nonce_field( self::ACTION_SAVE_CLIENT ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="perdita-sc-client-id"><?php esc_html_e( 'Google OAuth Client ID', 'perdita-core' ); ?></label></th>
					<td>
						<input type="text" id="perdita-sc-client-id" class="large-text code" name="client_id" value="<?php echo esc_attr( $s['client_id'] ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="perdita-sc-client-secret"><?php esc_html_e( 'Google OAuth Client Secret', 'perdita-core' ); ?></label></th>
					<td>
						<?php $secret_placeholder = $has_secret ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : ''; ?>
						<input type="password" id="perdita-sc-client-secret" class="large-text code" name="client_secret" value="" autocomplete="new-password" placeholder="<?php echo $secret_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?>" />
						<?php if ( $has_secret ) : ?>
							<label style="display:block; margin-top:6px;">
								<input type="checkbox" name="remove_secret" value="1" />
								<?php esc_html_e( 'Remove the saved secret (this also disconnects Search Console)', 'perdita-core' ); ?>
							</label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'The secret is encrypted before it is saved, so a database backup alone does not reveal it. Type a new value only when you want to change it.', 'perdita-core' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Google OAuth credentials', 'perdita-core' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the connect button, site picker, and dashboard, once the OAuth
	 * client id/secret are saved.
	 *
	 * @param array $s Current settings.
	 */
	private function render_connection( array $s ) {
		echo '<hr style="max-width:720px;" />';

		if ( ! Perdita_Search_Console::is_connected() ) {
			$this->render_connect_button();
			return;
		}

		if ( ! Perdita_Search_Console::has_site() ) {
			$this->render_site_picker();
			return;
		}

		$this->render_dashboard( $s );
	}

	/**
	 * Render the "Connect to Search Console" button, only reachable once the
	 * Client ID/Secret are both saved.
	 */
	private function render_connect_button() {
		echo '<h2>' . esc_html__( 'Connect your Google account', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'You will be sent to Google to choose the account that has access to this site\'s Search Console property, and to grant read-only access.', 'perdita-core' ) . '</p>';
		echo '<a href="' . esc_url( Perdita_Search_Console_OAuth::connect_url() ) . '" class="button button-primary">' . esc_html__( 'Connect to Search Console', 'perdita-core' ) . '</a>';
	}

	/**
	 * Render the property picker: auto-selected notice, or a dropdown when
	 * more than one (or zero exact) matches are available.
	 */
	private function render_site_picker() {
		echo '<h2>' . esc_html__( 'Choose your Search Console property', 'perdita-core' ) . '</h2>';

		$sites = Perdita_Search_Console::list_sites();
		if ( is_wp_error( $sites ) ) {
			$this->render_api_error( $sites );
			$this->render_disconnect_button();
			return;
		}

		if ( empty( $sites ) ) {
			echo '<p>' . esc_html__( 'This Google account has no verified Search Console properties. Verify a property in Search Console first, then reload this page.', 'perdita-core' ) . '</p>';
			$this->render_disconnect_button();
			return;
		}

		$auto = Perdita_Search_Console::auto_match_site( $sites );
		if ( '' !== $auto ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: matched Search Console property URL */
					__( 'This matches a verified property: %s', 'perdita-core' ),
					$auto
				)
			) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SET_SITE ) . '" />';
		wp_nonce_field( self::ACTION_SET_SITE );

		echo '<select name="site_url">';
		foreach ( $sites as $site ) {
			if ( empty( $site['siteUrl'] ) ) {
				continue;
			}
			$url = (string) $site['siteUrl'];
			printf( '<option value="%s" %s>%s</option>', esc_attr( $url ), selected( $auto, $url, false ), esc_html( $url ) );
		}
		echo '</select> ';

		submit_button( __( 'Use this property', 'perdita-core' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<p style="margin-top:12px;">';
		$this->render_disconnect_button();
		echo '</p>';
	}

	/**
	 * Render the summary tiles and top-queries table for the chosen
	 * property, plus "Refresh now" and "Disconnect".
	 *
	 * @param array $s Current settings.
	 */
	private function render_dashboard( array $s ) {
		echo '<h2>' . esc_html__( 'Search performance', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: Search Console property URL */
				__( 'Property: %s', 'perdita-core' ),
				$s['site_url']
			)
		) . '</p>';

		$data = Perdita_Search_Console::get_data();

		if ( is_wp_error( $data ) ) {
			$this->render_api_error( $data );
		} else {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: report start date, 2: report end date */
					__( 'Showing %1$s to %2$s.', 'perdita-core' ),
					$data['start'],
					$data['end']
				)
			) . '</p>';

			$this->render_tiles( $data['totals'] );
			$this->render_queries_table( $data['queries'] );
		}

		echo '<p style="margin-top:16px;">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REFRESH ) . '" />';
		wp_nonce_field( self::ACTION_REFRESH );
		submit_button( __( 'Refresh now', 'perdita-core' ), 'secondary', 'submit', false );
		echo '</form>';

		$this->render_disconnect_button();
		echo '</p>';
	}

	/**
	 * Render the four summary tiles: clicks, impressions, average CTR,
	 * average position.
	 *
	 * @param array $totals Totals row from get_data().
	 */
	private function render_tiles( array $totals ) {
		$tiles = array(
			__( 'Clicks', 'perdita-core' )      => number_format_i18n( (float) $totals['clicks'] ),
			__( 'Impressions', 'perdita-core' ) => number_format_i18n( (float) $totals['impressions'] ),
			__( 'Average CTR', 'perdita-core' ) => number_format_i18n( (float) $totals['ctr'] * 100, 1 ) . '%',
			__( 'Average position', 'perdita-core' ) => number_format_i18n( (float) $totals['position'], 1 ),
		);

		echo '<div style="display:flex; flex-wrap:wrap; gap:16px; margin:16px 0;">';
		foreach ( $tiles as $label => $value ) {
			echo '<div class="card" style="padding:12px 16px; min-width:150px;">';
			echo '<div style="font-size:22px; font-weight:600;">' . esc_html( $value ) . '</div>';
			echo '<div class="description">' . esc_html( $label ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the top-10 queries table.
	 *
	 * @param array $queries Query rows from get_data().
	 */
	private function render_queries_table( array $queries ) {
		echo '<h3>' . esc_html__( 'Top queries', 'perdita-core' ) . '</h3>';

		if ( empty( $queries ) ) {
			echo '<p>' . esc_html__( 'No query data for this period yet.', 'perdita-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Query', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Clicks', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Impressions', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'perdita-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $queries as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['query'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $row['clicks'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $row['impressions'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $row['ctr'] * 100, 1 ) ) . '%</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a Disconnect button (form + confirm), reused from a few call
	 * sites (site picker, dashboard, and API-error fallback).
	 */
	private function render_disconnect_button() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Disconnect Search Console? This revokes access at Google and clears the cached data.', 'perdita-core' ) ) ) . ');">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT ) . '" />';
		wp_nonce_field( self::ACTION_DISCONNECT );
		submit_button( __( 'Disconnect', 'perdita-core' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render a WP_Error from the API layer as an admin notice. Special-cases
	 * 'invalid_grant' (Google-side revoke/expiry) with a reconnect prompt
	 * instead of the raw error, since that condition would otherwise repeat
	 * on every page load.
	 *
	 * @param WP_Error $error Error to render.
	 */
	private function render_api_error( $error ) {
		if ( 'invalid_grant' === $error->get_error_code() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Google says this connection is no longer valid (it may have been revoked from your Google Account settings). Please reconnect below.', 'perdita-core' ) . '</p></div>';
			$this->render_connect_button();
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
	}

	/* ---------- admin-post handlers ---------- */

	/**
	 * Save the Client ID / Client Secret. Removing the secret also clears the
	 * connection, since a stored refresh token is useless without the client
	 * that created it.
	 */
	public function handle_save_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_SAVE_CLIENT );

		$raw   = array(
			'client_id'     => isset( $_POST['client_id'] ) ? wp_unslash( $_POST['client_id'] ) : '', // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in Perdita_Search_Console::sanitize_client().
			'client_secret' => isset( $_POST['client_secret'] ) ? (string) wp_unslash( $_POST['client_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- opaque secret, encrypted in sanitize_client(), never echoed.
			'remove_secret' => ! empty( $_POST['remove_secret'] ),
		);
		$clean = Perdita_Search_Console::sanitize_client( $raw );

		$settings      = Perdita_Search_Console::settings();
		$secret_before = $settings['client_secret'];

		$settings['client_id']     = $clean['client_id'];
		$settings['client_secret'] = $clean['client_secret'];

		// The secret changed or was removed: any existing refresh token was
		// minted against the old client and can no longer be refreshed, so
		// clear the connection rather than leave a dead, silently-failing one.
		if ( $clean['client_secret'] !== $secret_before || $raw['remove_secret'] ) {
			$settings['refresh_token'] = '';
			$settings['site_url']      = '';
		}

		update_option( Perdita_Search_Console::OPTION, $settings );

		$this->redirect_back(
			array(
				'perdita_sc_status' => 'saved',
				'perdita_sc_msg'    => rawurlencode( __( 'Google OAuth credentials saved.', 'perdita-core' ) ),
			)
		);
	}

	/**
	 * Store the chosen Search Console property and clear any stale cache.
	 */
	public function handle_set_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_SET_SITE );

		$site_url = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( '' === $site_url ) {
			$this->redirect_back(
				array(
					'perdita_sc_status' => 'no_site_chosen',
					'perdita_sc_msg'    => rawurlencode( __( 'Choose a property from the list.', 'perdita-core' ) ),
				)
			);
		}

		Perdita_Search_Console::store_site_url( $site_url );

		$this->redirect_back(
			array(
				'perdita_sc_status' => 'site_set',
				'perdita_sc_msg'    => rawurlencode( __( 'Property saved. Pulling your search data now.', 'perdita-core' ) ),
			)
		);
	}

	/**
	 * Force a fresh data pull, bypassing the transient cache.
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_REFRESH );

		$data = Perdita_Search_Console::get_data( true );

		if ( is_wp_error( $data ) ) {
			$this->redirect_back(
				array(
					'perdita_sc_status' => $data->get_error_code(),
					'perdita_sc_msg'    => rawurlencode( $data->get_error_message() ),
				)
			);
		}

		$this->redirect_back(
			array(
				'perdita_sc_status' => 'refreshed',
				'perdita_sc_msg'    => rawurlencode( __( 'Search Console data refreshed.', 'perdita-core' ) ),
			)
		);
	}

	/**
	 * Disconnect: revoke at Google, then wipe the local refresh token, chosen
	 * property, and cached data. The client id/secret are left as-is.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_DISCONNECT );

		Perdita_Search_Console::clear_cache();
		Perdita_Search_Console::disconnect();

		$this->redirect_back(
			array(
				'perdita_sc_status' => 'disconnected',
				'perdita_sc_msg'    => rawurlencode( __( 'Search Console disconnected.', 'perdita-core' ) ),
			)
		);
	}
}
