<?php
/**
 * Search Console "connect your Google account" OAuth (authorization code).
 *
 * Unlike inc/class-perdita-openrouter-oauth.php (PKCE, a public client with no
 * secret), this is a standard OAuth2 authorization-code flow for a
 * confidential client: the site owner's own Google Cloud OAuth Client ID and
 * Secret (see Perdita_Search_Console::has_client()), because Google's
 * Search Console API requires that shape of client and does not support PKCE
 * alone for this scope.
 *
 * CSRF PROTECTION: this deliberately replicates the state-param pattern from
 * Perdita_OpenRouter_OAuth::start()/callback(). An earlier review pass on
 * that flow flagged a missing OAuth state param as a High-severity CSRF
 * finding (an attacker could otherwise trick a logged-in admin into
 * completing an OAuth redirect the admin never started, binding the
 * attacker's own Google connection to the site). The fix there, and the
 * requirement here, is a random per-user state value stashed server-side
 * before redirecting to Google, and verified with hash_equals() when Google
 * redirects back.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Search_Console_OAuth {

	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * OAuth scope: read-only access to the user's own verified Search
	 * Console properties. Never request a write scope here, this module only
	 * reads.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * admin-post action names.
	 */
	const ACTION_START    = 'perdita_sc_oauth_start';
	const ACTION_CALLBACK = 'perdita_sc_oauth_callback';

	/**
	 * Transient key prefix for the CSRF state, keyed per-user like the
	 * OpenRouter flow's verifier transient.
	 */
	const STATE_PREFIX = 'perdita_sc_oauth_state_';

	/**
	 * How long the CSRF state stays valid. Fifteen minutes is generous for a
	 * human to complete the Google consent screen, short enough that a state
	 * value is not a long-lived reusable secret.
	 */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The settings-page slug to redirect back to with a status message.
	 */
	const SETTINGS_PAGE = 'perdita-search-console';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'callback' ) );
	}

	/**
	 * The URL for the "Connect to Search Console" button. Wrapped in a nonce
	 * so the click itself is verified (checked in start()) before we ever
	 * redirect to Google.
	 *
	 * @return string
	 */
	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_START ), self::ACTION_START );
	}

	/**
	 * The redirect URI registered (by the site owner) on the Google Cloud
	 * OAuth client. Delegates to the main class so the admin screen and the
	 * actual redirect always show/use the identical string.
	 *
	 * @return string
	 */
	private function callback_url() {
		return Perdita_Search_Console::redirect_uri();
	}

	/**
	 * Start the flow: capability + nonce check, mint a CSRF state, stash it,
	 * redirect to Google's consent screen.
	 */
	public function start() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION_START ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		$settings = Perdita_Search_Console::settings();
		$client_id = trim( (string) $settings['client_id'] );
		if ( '' === $client_id || '' === (string) $settings['client_secret'] ) {
			$this->finish( 'missing_client', __( 'Save a Google OAuth Client ID and Client Secret first.', 'perdita-core' ) );
		}

		// Random state, echoed back by Google on the callback redirect, so the
		// callback can confirm this specific redirect corresponds to a flow
		// this admin user actually started (see the file header for why this
		// is a hard requirement, not optional, in this codebase).
		$state = bin2hex( random_bytes( 16 ) );
		set_transient( self::STATE_PREFIX . get_current_user_id(), $state, self::STATE_TTL );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( $this->callback_url() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( self::SCOPE ),
				'access_type'   => 'offline', // Required to get a refresh_token back.
				'prompt'        => 'consent', // Forces a fresh refresh_token even on a reconnect (Google only issues one on the first consent otherwise).
				'state'         => $state,
			),
			self::AUTH_URL
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external OAuth endpoint.
		exit;
	}

	/**
	 * Handle Google's redirect back: verify state, exchange the code for
	 * tokens, store the refresh token, then move on to the site-picker step.
	 *
	 * Every failure path (denied consent, bad/missing state, network error,
	 * bad response) redirects back to the settings screen with a friendly
	 * message. None of them are allowed to fatal or blank-page, since this
	 * runs on an unauthenticated-looking GET from Google's redirect.
	 */
	public function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth redirect carries no nonce; the stored state is the CSRF proof, verified with hash_equals() below.
		$error       = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		$code        = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state_param = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable

		$stored_state = get_transient( self::STATE_PREFIX . get_current_user_id() );
		delete_transient( self::STATE_PREFIX . get_current_user_id() );
		$stored_state = is_string( $stored_state ) ? $stored_state : '';

		// The user declined consent on Google's screen. Google redirects here
		// with error=access_denied and no code, this is an expected outcome,
		// not a failure, so it gets a calm message rather than an error page.
		if ( 'access_denied' === $error ) {
			$this->finish( 'cancelled', __( 'Search Console connection was cancelled.', 'perdita-core' ) );
		}
		if ( '' !== $error ) {
			$this->finish( 'oauth_error', __( 'Google returned an error while connecting Search Console. Please try again.', 'perdita-core' ) );
		}

		// CSRF check: the state Google echoes back must match the one this
		// site generated and stored before redirecting, verified in constant
		// time. This is the same defense-in-depth pattern as
		// Perdita_OpenRouter_OAuth::callback(), required here because this
		// flow has no PKCE verifier to fall back on.
		if ( '' === $stored_state || '' === $state_param || ! hash_equals( $stored_state, $state_param ) ) {
			$this->finish( 'state_mismatch', __( 'Search Console connection could not be verified. Please try connecting again.', 'perdita-core' ) );
		}

		if ( '' === $code ) {
			$this->finish( 'no_code', __( 'Google did not return an authorization code. Please try connecting again.', 'perdita-core' ) );
		}

		$settings      = Perdita_Search_Console::settings();
		$client_id     = trim( (string) $settings['client_id'] );
		$client_secret = Perdita_Crypto::decrypt( $settings['client_secret'] );

		if ( '' === $client_id || '' === $client_secret ) {
			$this->finish( 'missing_client', __( 'The Google OAuth Client ID/Secret are no longer available. Please re-enter them and try again.', 'perdita-core' ) );
		}

		$res = wp_remote_post(
			Perdita_Search_Console::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $this->callback_url(),
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			$this->finish( 'network_error', __( 'Could not reach Google to finish connecting. Please try again.', 'perdita-core' ) );
		}

		$code_status = (int) wp_remote_retrieve_response_code( $res );
		$data        = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code_status || ! is_array( $data ) || empty( $data['refresh_token'] ) ) {
			// A common cause here is a redirect_uri that does not exactly
			// match what's registered on the Google Cloud OAuth client, or an
			// authorization code that already expired/was reused. Either way,
			// this is not a fatal error, just an incomplete connection.
			$this->finish( 'token_exchange_failed', __( 'Google did not return a connection token. Double check the redirect URI on your OAuth Client matches exactly, then try again.', 'perdita-core' ) );
		}

		// Store the long-lived refresh token, encrypted at rest. The
		// short-lived access_token returned alongside it is not persisted;
		// callers fetch a fresh one from the refresh token whenever they need
		// to call the API (see Perdita_Search_Console::get_access_token()).
		Perdita_Search_Console::store_refresh_token( (string) $data['refresh_token'] );

		$this->finish( 'connected', __( 'Google account connected. Choose which Search Console property matches this site below.', 'perdita-core' ) );
	}

	/**
	 * Redirect back to the Search Console settings page with a status code
	 * and message. Centralizing this keeps every callback exit path
	 * consistent (no fatals, no blank screens).
	 *
	 * @param string $status Short machine status, used for notice styling.
	 * @param string $msg    Human-readable message.
	 */
	private function finish( $status, $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SETTINGS_PAGE,
					'perdita_sc_status' => $status,
					'perdita_sc_msg'    => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
