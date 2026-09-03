<?php
/**
 * Search Console module: settings, token refresh, and the API data pull.
 *
 * WHY OAUTH, NOT AN API KEY (unlike every other module's credential):
 * Google Search Console's API only exposes a user's own verified-property
 * data behind OAuth2 (scope webmasters.readonly). There is no API-key path
 * for user-owned data, so this module can't follow the plain-API-key pattern
 * every other Perdita module uses.
 *
 * WHY BRING-YOUR-OWN OAUTH APP, NOT A SHARED PERDITA APP: standing up a
 * single shared OAuth client for every Perdita install would need Eric to
 * personally complete Google's app-verification process (privacy policy
 * review, consent-screen review, days to weeks) for the sensitive Search
 * Console scope. That hasn't happened. So each site owner creates their own
 * free Google Cloud project + OAuth Client ID (Web application) and pastes
 * the Client ID/Secret in here. Perdita then runs a standard OAuth2
 * authorization-code flow (confidential client, not PKCE) against it. See
 * class-perdita-search-console-oauth.php for the flow itself.
 *
 * This class holds the settings contract (defaults, read, sanitize) plus the
 * token-refresh helper and the Search Console API calls. The OAuth
 * start/callback handlers and the admin screen live in their own classes.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Search_Console {

	/**
	 * Option name for stored settings (client id/secret, refresh token, chosen
	 * property). The client secret and refresh token are encrypted at rest
	 * with Perdita_Crypto before they ever reach this option.
	 */
	const OPTION = 'perdita_search_console_settings';

	/**
	 * Transient key prefix for cached Search Console data, suffixed with a
	 * hash of the chosen site URL so switching properties can't serve stale
	 * data from a different property.
	 */
	const CACHE_PREFIX = 'perdita_sc_data_';

	/**
	 * How long a data pull stays cached before the dashboard will fetch fresh
	 * data on its own. "Refresh now" bypasses this.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Search Console reporting window, matching the API's typical freshness
	 * window (data older than this is reliable, the last couple of days often
	 * are not yet finalized).
	 */
	const REPORT_DAYS = 28;

	/**
	 * OAuth token endpoint, used both for the authorization-code exchange
	 * (in the oauth class) and for refresh-token exchanges (here).
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Search Console (Webmasters) API base.
	 */
	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'client_id'     => '',
			'client_secret' => '', // Encrypted blob at rest.
			'refresh_token' => '', // Encrypted blob at rest.
			'site_url'      => '', // Chosen Search Console property (urlprefix or sc-domain:).
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
	 * Whether both halves of the OAuth client are saved, which is what gates
	 * showing the "Connect to Search Console" button.
	 *
	 * @return bool
	 */
	public static function has_client() {
		$s = self::settings();
		return '' !== trim( (string) $s['client_id'] ) && '' !== (string) $s['client_secret'];
	}

	/**
	 * Whether a Google account is connected (a refresh token is stored).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$s = self::settings();
		return '' !== (string) $s['refresh_token'];
	}

	/**
	 * Whether a Search Console property has been chosen.
	 *
	 * @return bool
	 */
	public static function has_site() {
		$s = self::settings();
		return '' !== trim( (string) $s['site_url'] );
	}

	/**
	 * Sanitize the client id/secret half of settings on write. Static so the
	 * admin class can reuse it without a front-end instance. The secret is
	 * encrypted here so it is never held in plaintext outside this method.
	 *
	 * @param array $input Raw input (client_id, client_secret, remove_secret).
	 * @return array Clean partial settings: client_id, client_secret.
	 */
	public static function sanitize_client( $input ) {
		$current = self::settings();
		$input   = is_array( $input ) ? $input : array();

		$client_id = isset( $input['client_id'] ) ? sanitize_text_field( (string) $input['client_id'] ) : '';

		$client_secret = $current['client_secret'];
		if ( ! empty( $input['remove_secret'] ) ) {
			$client_secret = '';
		} else {
			// Never run a secret through text-sanitizing filters, that can
			// corrupt it. Treat it as opaque and encrypt immediately.
			$typed = isset( $input['client_secret'] ) ? (string) $input['client_secret'] : '';
			if ( '' !== $typed ) {
				$client_secret = Perdita_Crypto::encrypt( $typed );
			}
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * The redirect URI Google sends the user back to. Must exactly match an
	 * "Authorized redirect URI" configured on the Google Cloud OAuth client,
	 * which is why the admin screen shows this exact string for the site
	 * owner to paste into Google Cloud Console.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=perdita_sc_oauth_callback' );
	}

	/**
	 * Store a newly obtained refresh token, encrypted at rest. This is the
	 * long-lived credential, treated as sensitive as a password.
	 *
	 * @param string $refresh_token Plaintext refresh token from Google.
	 */
	public static function store_refresh_token( $refresh_token ) {
		$settings                 = self::settings();
		$settings['refresh_token'] = Perdita_Crypto::encrypt( (string) $refresh_token );
		update_option( self::OPTION, $settings );
	}

	/**
	 * Store the chosen Search Console property.
	 *
	 * @param string $site_url Property URL (urlprefix like https://example.com/, or sc-domain:example.com).
	 */
	public static function store_site_url( $site_url ) {
		$settings             = self::settings();
		$settings['site_url'] = sanitize_text_field( (string) $site_url );
		update_option( self::OPTION, $settings );
	}

	/**
	 * Disconnect: revoke the refresh token at Google's end, then wipe every
	 * piece of connection state (refresh token, chosen property, cached
	 * data). The client id/secret are left in place so the owner can
	 * reconnect without re-entering them.
	 *
	 * @return bool Whether the revoke call reached Google without a transport error (a clean local wipe happens either way).
	 */
	public static function disconnect() {
		$settings      = self::settings();
		$refresh_token = Perdita_Crypto::decrypt( $settings['refresh_token'] );
		$revoked_ok    = true;

		if ( '' !== $refresh_token ) {
			$res = wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array( 'token' => $refresh_token ),
				)
			);
			$revoked_ok = ! is_wp_error( $res );
		}

		$site_url               = $settings['site_url'];
		$settings['refresh_token'] = '';
		$settings['site_url']      = '';
		update_option( self::OPTION, $settings );

		if ( '' !== $site_url ) {
			delete_transient( self::cache_key( $site_url ) );
		}

		return $revoked_ok;
	}

	/**
	 * Exchange the stored encrypted refresh token for a fresh access token.
	 * Called right before every Search Console API request, since access
	 * tokens are short-lived (~1hr) and are never persisted long-term.
	 *
	 * @return string|WP_Error Access token, or a WP_Error. The error code
	 *                          'invalid_grant' means Google considers the
	 *                          refresh token revoked/expired, so the caller
	 *                          should prompt a reconnect rather than retry.
	 */
	public static function get_access_token() {
		$settings      = self::settings();
		$refresh_token = Perdita_Crypto::decrypt( $settings['refresh_token'] );

		if ( '' === $refresh_token ) {
			return new WP_Error( 'no_refresh_token', __( 'Search Console is not connected.', 'perdita-core' ) );
		}
		if ( '' === trim( (string) $settings['client_id'] ) || '' === $settings['client_secret'] ) {
			return new WP_Error( 'no_client', __( 'The Google OAuth Client ID and Secret are not configured.', 'perdita-core' ) );
		}
		$client_secret = Perdita_Crypto::decrypt( $settings['client_secret'] );
		if ( '' === $client_secret ) {
			return new WP_Error( 'no_client', __( 'The Google OAuth Client Secret could not be read.', 'perdita-core' ) );
		}

		$res = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => trim( (string) $settings['client_id'] ),
					'client_secret' => $client_secret,
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'token_refresh_failed';
			// Google returns 'invalid_grant' when the refresh token has been
			// revoked (disconnected from the Google Account settings page) or
			// has expired. Surface that exact code so callers can clear the
			// dead token and prompt a reconnect instead of repeating a raw
			// API error on every page load.
			if ( 'invalid_grant' === $error ) {
				return new WP_Error( 'invalid_grant', __( 'Google says this connection is no longer valid. Please reconnect.', 'perdita-core' ) );
			}
			return new WP_Error( 'token_refresh_failed', __( 'Could not refresh the Google access token.', 'perdita-core' ) );
		}

		return (string) $data['access_token'];
	}

	/**
	 * If a WP_Error from get_access_token() (or an API call) is an
	 * 'invalid_grant', clear the now-useless stored refresh token so the
	 * dashboard stops trying to use it and shows a reconnect prompt instead.
	 *
	 * @param WP_Error $error Error to inspect.
	 */
	private static function maybe_clear_dead_token( $error ) {
		if ( is_wp_error( $error ) && 'invalid_grant' === $error->get_error_code() ) {
			$settings                 = self::settings();
			$settings['refresh_token'] = '';
			update_option( self::OPTION, $settings );
		}
	}

	/**
	 * List the Search Console properties (sites) the connected Google
	 * account has access to.
	 *
	 * @return array|WP_Error List of site entries (each with 'siteUrl' and 'permissionLevel'), or a WP_Error.
	 */
	public static function list_sites() {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			self::maybe_clear_dead_token( $token );
			return $token;
		}

		$res = wp_remote_get(
			self::API_BASE . '/sites',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'sc_sites_failed', __( 'Could not read your Search Console properties from Google.', 'perdita-core' ) );
		}

		return is_array( $data ) && isset( $data['siteEntry'] ) && is_array( $data['siteEntry'] ) ? $data['siteEntry'] : array();
	}

	/**
	 * If exactly one of the connected account's properties matches this
	 * site's home_url() (a urlprefix property, scheme/host, or a matching
	 * sc-domain: property), return it. Otherwise return ''.
	 *
	 * @param array $sites List of site entries from list_sites().
	 * @return string Matching siteUrl, or ''.
	 */
	public static function auto_match_site( array $sites ) {
		$home = home_url( '/' );
		$host = wp_parse_url( $home, PHP_URL_HOST );

		foreach ( $sites as $site ) {
			if ( empty( $site['siteUrl'] ) ) {
				continue;
			}
			$candidate = (string) $site['siteUrl'];
			if ( trailingslashit( $candidate ) === trailingslashit( $home ) ) {
				return $candidate;
			}
			if ( $host && 'sc-domain:' . $host === $candidate ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * The transient key used to cache a data pull for a given property.
	 * Suffixed with a hash of the site URL so switching the chosen property
	 * can never accidentally serve another property's cached numbers.
	 *
	 * @param string $site_url Property URL.
	 * @return string
	 */
	private static function cache_key( $site_url ) {
		return self::CACHE_PREFIX . md5( (string) $site_url );
	}

	/**
	 * Pull the last REPORT_DAYS of Search Console data for the chosen
	 * property: site-wide totals plus the top queries. Cached in a transient
	 * so the dashboard does not hit the API on every admin page load.
	 *
	 * @param bool $force_refresh Bypass the cache and pull fresh data.
	 * @return array|WP_Error {
	 *     @type array  $totals  clicks, impressions, ctr, position.
	 *     @type array  $queries Up to 10 rows: keys (query), clicks, impressions, ctr, position.
	 *     @type string $start   Report start date (Y-m-d).
	 *     @type string $end     Report end date (Y-m-d).
	 * }
	 */
	public static function get_data( $force_refresh = false ) {
		$settings = self::settings();
		$site_url = trim( (string) $settings['site_url'] );
		if ( '' === $site_url ) {
			return new WP_Error( 'no_site', __( 'No Search Console property is selected yet.', 'perdita-core' ) );
		}

		$key = self::cache_key( $site_url );
		if ( ! $force_refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			self::maybe_clear_dead_token( $token );
			return $token;
		}

		// Search Console's own data typically finalizes a couple of days
		// behind "today", so end the window 2 days back rather than today.
		$end   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( '-2 days -' . ( self::REPORT_DAYS - 1 ) . ' days' ) );

		$totals = self::query_analytics( $token, $site_url, $start, $end, array() );
		if ( is_wp_error( $totals ) ) {
			self::maybe_clear_dead_token( $totals );
			return $totals;
		}

		$by_query = self::query_analytics( $token, $site_url, $start, $end, array( 'query' ), 10 );
		if ( is_wp_error( $by_query ) ) {
			self::maybe_clear_dead_token( $by_query );
			return $by_query;
		}

		$totals_row = ! empty( $totals[0] ) ? $totals[0] : array(
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
		);

		$queries = array();
		foreach ( $by_query as $row ) {
			$queries[] = array(
				'query'       => isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '',
				'clicks'      => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (float) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
				'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0,
			);
		}

		$result = array(
			'totals'  => array(
				'clicks'      => isset( $totals_row['clicks'] ) ? (float) $totals_row['clicks'] : 0,
				'impressions' => isset( $totals_row['impressions'] ) ? (float) $totals_row['impressions'] : 0,
				'ctr'         => isset( $totals_row['ctr'] ) ? (float) $totals_row['ctr'] : 0,
				'position'    => isset( $totals_row['position'] ) ? (float) $totals_row['position'] : 0,
			),
			'queries' => $queries,
			'start'   => $start,
			'end'     => $end,
		);

		set_transient( $key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * One searchAnalytics/query call.
	 *
	 * @param string $token    Bearer access token.
	 * @param string $site_url Property URL.
	 * @param string $start    Start date (Y-m-d).
	 * @param string $end      End date (Y-m-d).
	 * @param array  $dims     Dimensions, e.g. array( 'query' ).
	 * @param int    $row_limit Max rows to request.
	 * @return array|WP_Error The 'rows' array (possibly empty), or a WP_Error.
	 */
	private static function query_analytics( $token, $site_url, $start, $end, array $dims, $row_limit = 1 ) {
		$endpoint = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$body = array(
			'startDate' => $start,
			'endDate'   => $end,
			'rowLimit'  => max( 1, (int) $row_limit ),
		);
		if ( ! empty( $dims ) ) {
			$body['dimensions'] = array_values( $dims );
		}

		$res = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'sc_query_failed', __( 'Could not read Search Console data from Google.', 'perdita-core' ) );
		}

		return is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
	}

	/**
	 * Clear the cached data for the currently chosen property, used by the
	 * "Refresh now" action (which then re-pulls via get_data( true )) and by
	 * disconnect().
	 */
	public static function clear_cache() {
		$settings = self::settings();
		$site_url = trim( (string) $settings['site_url'] );
		if ( '' !== $site_url ) {
			delete_transient( self::cache_key( $site_url ) );
		}
	}
}
