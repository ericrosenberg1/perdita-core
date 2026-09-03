<?php
/**
 * Security hardening: Wordfence-lite protections for a Perdita site.
 *
 * Every feature is independently toggleable and OFF by default, so turning the
 * module on changes nothing until an admin opts into each protection. The two
 * features that can lock people out or break a site are built defensively:
 *   - Login rate limiting always honors the IP allowlist and is transient
 *     based, so a lockout always self-expires. An allowlisted IP is never
 *     locked out, so an admin cannot brick their own access.
 *   - The Content-Security-Policy defaults to Report-Only when the headers
 *     feature is enabled, so it can never silently break a site. An enforcing
 *     CSP is only ever sent when the admin explicitly chooses Enforce.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Security {

	/**
	 * Option that stores this module's settings.
	 */
	const OPTION = 'perdita_security_settings';

	/**
	 * Transient prefix for per-IP login failure counters and lockouts.
	 */
	const TRANSIENT_PREFIX = 'perdita_sec_login_';

	/**
	 * The Perdita core (settings, ai, seo store, crypto helpers, ...).
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Merged settings for this module.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor. Reads settings and registers the hooks for each enabled
	 * protection.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core     = $core;
		$this->settings = self::get_settings();

		// 3. Disable the dashboard file editor. This has to happen during theme
		// load (this constructor runs there), which is early enough for wp-admin
		// to pick it up. It hides and blocks Appearance > Theme File Editor and
		// Plugins > Plugin File Editor. Guarded so we never redefine it.
		if ( ! empty( $this->settings['disable_file_edit'] ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// 1. Login rate limiting.
		if ( ! empty( $this->settings['login_limit'] ) ) {
			add_filter( 'authenticate', array( $this, 'check_lockout' ), 30 );
			add_action( 'wp_login_failed', array( $this, 'record_failure' ) );
			add_action( 'wp_login', array( $this, 'clear_failures' ), 10, 2 );
		}

		// 2. Security headers.
		if ( ! empty( $this->settings['headers'] ) ) {
			add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		}

		// 4. XML-RPC lockdown.
		if ( ! empty( $this->settings['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'strip_pingback_methods' ) );
		}

		// 5. Author enumeration.
		if ( ! empty( $this->settings['block_author_enum'] ) ) {
			add_action( 'template_redirect', array( $this, 'block_author_scan' ) );
			add_filter( 'rest_endpoints', array( $this, 'restrict_rest_users' ) );
		}
	}

	/* ---------- settings ---------- */

	/**
	 * Defaults for this module. Every protection is OFF so enabling the module
	 * changes nothing until the admin opts into each one. When the headers
	 * feature is turned on, the CSP still defaults to Report-Only.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'login_limit'       => false,
			'login_max'         => 5,
			'login_window'      => 15, // Minutes to remember failures.
			'login_lockout'     => 15, // Minutes to block after too many failures.
			'ip_allowlist'      => array(),
			'headers'           => false,
			'csp_mode'          => 'report-only', // off | report-only | enforce.
			'csp'               => self::default_csp(),
			'disable_file_edit' => false,
			'disable_xmlrpc'    => false,
			'block_author_enum' => false,
		);
	}

	/**
	 * A sensible, editable default Content-Security-Policy. Deliberately
	 * permissive enough to keep a normal WordPress site working (inline styles
	 * and scripts are common in themes and plugins), while still blocking
	 * object embeds and framing by other origins.
	 *
	 * @return string
	 */
	public static function default_csp() {
		return "default-src 'self'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; connect-src 'self' https:; frame-ancestors 'self'; object-src 'none'; base-uri 'self'";
	}

	/**
	 * Read and normalize the stored settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/* ---------- 1. login rate limiting ---------- */

	/**
	 * The client IP for rate limiting. Defaults to REMOTE_ADDR and never trusts
	 * a forwarded header on its own. Sites behind a proxy or load balancer can
	 * wire in the real client IP through the filter.
	 *
	 * @return string
	 */
	public function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		/**
		 * Filter the resolved client IP used for login rate limiting.
		 *
		 * @param string $ip The REMOTE_ADDR value (untrusted headers are ignored by default).
		 */
		$ip = apply_filters( 'perdita_security_client_ip', $ip );
		return is_string( $ip ) ? trim( $ip ) : '';
	}

	/**
	 * Whether an IP is on the never-lock-out allowlist. Matching is exact on the
	 * normalized string, so an admin who lists their own IP can never be locked
	 * out of their own site.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public function is_allowlisted( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$list = isset( $this->settings['ip_allowlist'] ) ? (array) $this->settings['ip_allowlist'] : array();
		return in_array( $ip, $list, true );
	}

	/**
	 * The transient key for an IP. The raw IP is hashed so the address never
	 * appears in the options table verbatim and the key length stays bounded.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function transient_key( $ip ) {
		return self::TRANSIENT_PREFIX . md5( $ip );
	}

	/**
	 * On the authenticate filter: block an IP that has hit the failure limit.
	 * Allowlisted IPs are always let through. The block is transient based, so
	 * it self-expires after the lockout window with no cron or cleanup.
	 *
	 * @param WP_User|WP_Error|null $user Prior result in the filter chain.
	 * @return WP_User|WP_Error|null
	 */
	public function check_lockout( $user ) {
		$ip = $this->client_ip();
		if ( '' === $ip || $this->is_allowlisted( $ip ) ) {
			return $user;
		}

		$record = get_transient( $this->transient_key( $ip ) );
		if ( is_array( $record ) && ! empty( $record['locked_until'] ) && time() < (int) $record['locked_until'] ) {
			$minutes = max( 1, (int) ceil( ( (int) $record['locked_until'] - time() ) / 60 ) );
			return new WP_Error(
				'perdita_security_locked',
				sprintf(
					/* translators: %d: number of minutes remaining in the lockout. */
					esc_html( _n( 'Too many failed login attempts. Try again in %d minute.', 'Too many failed login attempts. Try again in %d minutes.', $minutes, 'perdita-core' ) ),
					$minutes
				)
			);
		}

		return $user;
	}

	/**
	 * On a failed login: increment the failure counter for the IP, and start a
	 * lockout once it crosses the configured maximum. Allowlisted IPs are never
	 * counted or locked out.
	 */
	public function record_failure() {
		$ip = $this->client_ip();
		if ( '' === $ip || $this->is_allowlisted( $ip ) ) {
			return;
		}

		$max     = max( 1, (int) $this->settings['login_max'] );
		$window  = max( 1, (int) $this->settings['login_window'] ) * MINUTE_IN_SECONDS;
		$lockout = max( 1, (int) $this->settings['login_lockout'] ) * MINUTE_IN_SECONDS;
		$key     = $this->transient_key( $ip );

		$record = get_transient( $key );
		if ( ! is_array( $record ) ) {
			$record = array(
				'count'        => 0,
				'locked_until' => 0,
			);
		}
		$record['count'] = (int) $record['count'] + 1;

		if ( $record['count'] >= $max ) {
			// Start (or extend) the lockout. The transient TTL is the longer of
			// the remaining window and the lockout so the record always outlives
			// the block and self-expires afterward.
			$record['locked_until'] = time() + $lockout;
			set_transient( $key, $record, max( $window, $lockout ) );
			return;
		}

		set_transient( $key, $record, $window );
	}

	/**
	 * On a successful login: clear the failure counter for the IP so a real user
	 * starts clean.
	 *
	 * @param string       $user_login Username (unused).
	 * @param WP_User|null $user       The user (unused).
	 */
	public function clear_failures( $user_login = '', $user = null ) {
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return;
		}
		delete_transient( $this->transient_key( $ip ) );
	}

	/* ---------- 2. security headers ---------- */

	/**
	 * Send the security response headers on the front end. The CSP is only ever
	 * sent as enforcing when the admin explicitly chose Enforce, and only ever
	 * as report-only otherwise, so it can never silently break a live site.
	 */
	public function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );

		$mode = isset( $this->settings['csp_mode'] ) ? $this->settings['csp_mode'] : 'report-only';
		$csp  = isset( $this->settings['csp'] ) ? trim( (string) $this->settings['csp'] ) : '';
		if ( '' === $csp || 'off' === $mode ) {
			return;
		}

		if ( 'enforce' === $mode ) {
			header( 'Content-Security-Policy: ' . $csp );
		} else {
			// Report-Only is the default. It reports violations to the browser
			// console but never blocks anything, so a live site cannot break.
			header( 'Content-Security-Policy-Report-Only: ' . $csp );
		}
	}

	/* ---------- 4. XML-RPC ---------- */

	/**
	 * Remove the pingback methods from the XML-RPC surface, which are the ones
	 * abused for reflection and port-scan attacks.
	 *
	 * @param array $methods Registered XML-RPC methods.
	 * @return array
	 */
	public function strip_pingback_methods( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	/* ---------- 5. author enumeration ---------- */

	/**
	 * Block ?author=<n> enumeration scans on the front end for logged-out
	 * visitors. A logged-in user (who can already see authors) is not affected,
	 * and admin requests are left alone.
	 */
	public function block_author_scan() {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		// On plain permalinks there is no pretty author archive to fall back
		// to: ?author=<n> IS the author archive URL WordPress itself
		// generates (get_author_posts_url() emits exactly that), so blocking
		// it here does not stop enumeration, it deletes the site's author
		// archives and sends every link to them home. Enumeration on a plain
		// permalink site is closed by the REST users restriction below
		// instead. Leave the query alone.
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}

		// Only a raw ?author=<n> query is an enumeration probe. A pretty author
		// archive URL resolves through the rewrite rules and is left alone.
		$raw = isset( $_GET['author'] ) ? wp_unslash( $_GET['author'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read of a query var.
		if ( '' === $raw ) {
			return;
		}
		if ( is_numeric( $raw ) || preg_match( '/^\s*\d/', (string) $raw ) ) {
			// 302, not 301. A permanent redirect is cached by browsers, CDNs,
			// and proxies, so turning this module off (or switching back to
			// plain permalinks) would leave visitors bouncing off a redirect
			// nothing on the server sends any more, with no way to clear it.
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}

	/**
	 * Remove the REST users collection endpoint for logged-out requests, so an
	 * unauthenticated caller cannot list the site's users (and their slugs) via
	 * /wp/v2/users. Logged-in requests keep the endpoint, which the block editor
	 * and other core features rely on.
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public function restrict_rest_users( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	}
}
