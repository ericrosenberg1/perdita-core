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
	 * Transient prefixes for the owner unlock link: one per link (keyed on
	 * the token's hash), and one per address for the hourly send limit.
	 */
	const UNLOCK_PREFIX      = 'perdita_sec_unlock_';
	const UNLOCK_LIMIT_PREFIX = 'perdita_sec_unlock_rl_';

	/**
	 * Unlock emails an address can ask for each hour.
	 */
	const UNLOCK_SENDS_PER_HOUR = 3;

	/**
	 * Option that holds the last core file check.
	 */
	const CORE_CHECK_OPTION = 'perdita_security_core_check';

	/**
	 * Cron hook for the daily core file check.
	 */
	const CORE_CHECK_CRON = 'perdita_security_core_check_run';

	/**
	 * Markers around the block this module owns in uploads/.htaccess.
	 */
	const UPLOADS_BEGIN = '# BEGIN Perdita Security uploads';
	const UPLOADS_END   = '# END Perdita Security uploads';

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
	 * The Turnstile verdict for this request, once checked.
	 *
	 * @var string|null
	 */
	private $turnstile_memo = null;

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
			// Owner recovery: an emailed link that clears the lockout.
			add_action( 'login_form_perdita_unlock', array( $this, 'handle_unlock' ) );
			add_filter( 'login_message', array( $this, 'unlock_login_message' ) );
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
			// Priority 1: redirect_canonical sits on template_redirect at 10 and
			// turns /?author=1 into /author/admin/ before a later hook runs.
			add_action( 'template_redirect', array( $this, 'block_author_scan' ), 1 );
			add_filter( 'oembed_response_data', array( $this, 'strip_oembed_author' ) );
			add_filter( 'wp_sitemaps_add_provider', array( $this, 'drop_users_sitemap' ), 10, 2 );
		}

		// 6. REST users restriction. get_settings() maps an older
		// block_author_enum site to 'users', so it keeps what it had.
		if ( 'users' === $this->settings['restrict_rest'] ) {
			add_filter( 'rest_endpoints', array( $this, 'restrict_rest_users' ) );
		}

		// 7. Same message for every failed login.
		if ( ! empty( $this->settings['login_generic_errors'] ) ) {
			add_filter( 'authenticate', array( $this, 'generic_login_error' ), 99 );
			// After the Turnstile check (10), so a failed challenge still shows.
			add_action( 'lostpassword_post', array( $this, 'hide_unknown_account' ), 99, 2 );
		}

		// 8. Turnstile on login, registration, and lost password.
		if ( ! empty( $this->settings['login_turnstile'] ) ) {
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue_turnstile' ) );
			add_action( 'login_form', array( $this, 'render_turnstile' ) );
			add_action( 'register_form', array( $this, 'render_turnstile' ) );
			add_action( 'lostpassword_form', array( $this, 'render_turnstile' ) );
			add_filter( 'authenticate', array( $this, 'check_login_turnstile' ), 21, 3 );
			add_filter( 'registration_errors', array( $this, 'check_register_turnstile' ) );
			add_action( 'lostpassword_post', array( $this, 'check_lostpassword_turnstile' ) );
		}

		// 9. Hardening.
		if ( ! empty( $this->settings['hide_wp_version'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string', 99 );
			foreach ( array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' ) as $type ) {
				add_filter( 'get_the_generator_' . $type, '__return_empty_string', 99 );
			}
			add_filter( 'style_loader_src', array( $this, 'strip_core_version' ), 99 );
			add_filter( 'script_loader_src', array( $this, 'strip_core_version' ), 99 );
		}
		if ( ! empty( $this->settings['disable_app_passwords'] ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
		}

		// 10. HSTS, over HTTPS only.
		if ( ! empty( $this->settings['hsts'] ) ) {
			add_action( 'send_headers', array( $this, 'send_hsts' ) );
			add_action( 'login_init', array( $this, 'send_hsts' ) );
		}

		// 11. Daily core file check.
		add_action( self::CORE_CHECK_CRON, array( __CLASS__, 'run_core_check' ) );
		$scheduled = wp_next_scheduled( self::CORE_CHECK_CRON );
		if ( ! empty( $this->settings['core_check'] ) ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CORE_CHECK_CRON );
			}
		} elseif ( $scheduled ) {
			wp_clear_scheduled_hook( self::CORE_CHECK_CRON );
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

			// Login protections.
			'login_generic_errors' => false,
			'login_long_lockout'   => false,
			'login_long_after'     => 4, // Lockouts inside 24 hours before the long one.
			'login_long_hours'     => 24,
			'login_turnstile'      => false,

			// Hardening.
			'hide_wp_version'       => false,
			'block_uploads_php'     => false,
			'disable_app_passwords' => false,
			'restrict_rest'         => 'off', // off | users.

			// HSTS, sent only over HTTPS.
			'hsts'            => false,
			'hsts_max_age'    => 31536000,
			'hsts_subdomains' => false,

			// Daily compare of core files with wordpress.org.
			'core_check' => false,

			// Firewall keys. The firewall screen owns their validation.
			'firewall'         => false,
			'firewall_mode'    => 'monitor', // monitor | block.
			'firewall_load'    => 'plugin', // plugin | mu | prepend.
			'fw_rules'         => array(
				'sqli'       => true,
				'xss'        => true,
				'traversal'  => true,
				'rfi'        => true,
				'php_upload' => true,
				'bad_bots'   => true,
				'probes'     => true,
				'xmlrpc'     => true,
			),
			'fw_trust_roles'   => 'edit_posts',
			'fw_exclusions'    => array(),
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
		$settings = array_merge( self::defaults(), $saved );
		// Before restrict_rest existed, block_author_enum also hid the REST
		// users endpoint. A site that never saved the new key keeps that.
		if ( ! array_key_exists( 'restrict_rest', $saved ) && ! empty( $saved['block_author_enum'] ) ) {
			$settings['restrict_rest'] = 'users';
		}
		return $settings;
	}

	/**
	 * Every setting key this class validates itself. The firewall keys
	 * (firewall, firewall_mode, firewall_load, fw_*) belong to the firewall
	 * screen.
	 *
	 * @return string[]
	 */
	public static function own_keys() {
		return array_values(
			array_filter(
				array_keys( self::defaults() ),
				static function ( $key ) {
					return 0 !== strpos( $key, 'fw_' ) && 0 !== strpos( $key, 'firewall' );
				}
			)
		);
	}

	/**
	 * Validate one allowlist entry: an exact IPv4 or IPv6 address, or a CIDR
	 * block such as 203.0.113.0/24 or 2001:db8::/32. A /0 block is refused,
	 * since it would switch the limiter off for everyone.
	 *
	 * @param mixed $entry Raw entry.
	 * @return string The trimmed entry, or '' when it is not valid.
	 */
	public static function clean_allow_entry( $entry ) {
		$entry = is_scalar( $entry ) ? trim( (string) $entry ) : '';
		if ( '' === $entry ) {
			return '';
		}
		if ( false === strpos( $entry, '/' ) ) {
			return false !== filter_var( $entry, FILTER_VALIDATE_IP ) ? $entry : '';
		}
		list( $addr, $bits ) = explode( '/', $entry, 2 );
		if ( '' === $bits || ! ctype_digit( $bits ) || false === filter_var( $addr, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$max = false !== filter_var( $addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? 32 : 128;
		$bits = (int) $bits;
		return ( $bits >= 1 && $bits <= $max ) ? $entry : '';
	}

	/**
	 * Whether an address matches any allowlist entry, exact or CIDR.
	 *
	 * @param string   $ip   Address.
	 * @param string[] $list Entries.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( '' === (string) $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		foreach ( (array) $list as $entry ) {
			$entry = self::clean_allow_entry( $entry );
			if ( '' !== $entry && Perdita_Client_IP::in_range( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate the new protections. Checkboxes are on when present and
	 * non-empty, numbers are clamped, and restrict_rest is off or users.
	 * Firewall keys are not touched here.
	 *
	 * @param array $input Raw values keyed by setting name.
	 * @return array Clean values for the keys this class owns, minus the
	 *               login limit keys sanitize_login() and the CSP fields
	 *               cover.
	 */
	public static function sanitize_protections( array $input ) {
		$int = static function ( $key, $min, $max, $default ) use ( $input ) {
			return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? max( $min, min( $max, (int) $input[ $key ] ) ) : $default;
		};
		$rest = isset( $input['restrict_rest'] ) && is_scalar( $input['restrict_rest'] ) ? sanitize_key( (string) $input['restrict_rest'] ) : 'off';
		return array(
			'login_generic_errors'  => ! empty( $input['login_generic_errors'] ),
			'login_long_lockout'    => ! empty( $input['login_long_lockout'] ),
			'login_long_after'      => $int( 'login_long_after', 1, 100, 4 ),
			'login_long_hours'      => $int( 'login_long_hours', 1, 720, 24 ),
			'login_turnstile'       => ! empty( $input['login_turnstile'] ),
			'hide_wp_version'       => ! empty( $input['hide_wp_version'] ),
			'block_uploads_php'     => ! empty( $input['block_uploads_php'] ),
			'disable_app_passwords' => ! empty( $input['disable_app_passwords'] ),
			'restrict_rest'         => in_array( $rest, array( 'off', 'users' ), true ) ? $rest : 'off',
			'hsts'                  => ! empty( $input['hsts'] ),
			'hsts_max_age'          => $int( 'hsts_max_age', 0, 63072000, 31536000 ),
			'hsts_subdomains'       => ! empty( $input['hsts_subdomains'] ),
			'core_check'            => ! empty( $input['core_check'] ),
			'block_author_enum'     => ! empty( $input['block_author_enum'] ),
		);
	}

	/**
	 * Validate the login-limit settings: the switch, the attempt count
	 * (1 to 100), the failure window and the lockout (1 to 1440 minutes
	 * each), and the allowlist, which keeps exact IPv4 and IPv6 addresses
	 * and CIDR blocks. The settings screen and the Switch to Perdita import both save
	 * through this.
	 *
	 * @param array $input login_limit, login_max, login_window, login_lockout,
	 *                     ip_allowlist (one address per line, or an array).
	 * @return array The same five keys, clean.
	 */
	public static function sanitize_login( array $input ) {
		$minutes = function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? max( 1, min( 1440, (int) $input[ $key ] ) ) : 15;
		};
		$raw = isset( $input['ip_allowlist'] ) ? $input['ip_allowlist'] : '';
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_filter( $raw, 'is_scalar' ) );
		}
		$ips = array();
		foreach ( (array) preg_split( '/[\r\n]+/', is_scalar( $raw ) ? (string) $raw : '' ) as $line ) {
			$ip = self::clean_allow_entry( sanitize_text_field( (string) $line ) );
			if ( '' !== $ip && ! in_array( $ip, $ips, true ) ) {
				$ips[] = $ip;
			}
		}
		return array(
			'login_limit'   => ! empty( $input['login_limit'] ),
			'login_max'     => isset( $input['login_max'] ) && is_scalar( $input['login_max'] ) ? max( 1, min( 100, (int) $input['login_max'] ) ) : 5,
			'login_window'  => $minutes( 'login_window' ),
			'login_lockout' => $minutes( 'login_lockout' ),
			'ip_allowlist'  => $ips,
		);
	}

	/* ---------- 1. login rate limiting ---------- */

	/**
	 * The client IP for rate limiting. Defaults to REMOTE_ADDR, or the
	 * visitor address a verified Cloudflare edge reports, and never trusts any
	 * other forwarded header on its own. Sites behind a proxy or load balancer can
	 * wire in the real client IP through the filter.
	 *
	 * @return string
	 */
	public function client_ip() {
		// Behind Cloudflare, REMOTE_ADDR is an edge shared by every visitor
		// on it. Perdita_Client_IP takes CF-Connecting-IP, but only from a
		// connection inside Cloudflare's published ranges.
		$ip = Perdita_Client_IP::get();
		/**
		 * Filter the resolved client IP used for login rate limiting.
		 *
		 * @param string $ip REMOTE_ADDR, or a verified Cloudflare visitor address (other forwarded headers are ignored).
		 */
		$ip = apply_filters( 'perdita_security_client_ip', $ip );
		return is_string( $ip ) ? trim( $ip ) : '';
	}

	/**
	 * Whether an IP is on the never-lock-out allowlist. An entry is an exact
	 * address or a CIDR block, so an admin who lists their own IP or range can
	 * never be locked out of their own site.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public function is_allowlisted( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$list = isset( $this->settings['ip_allowlist'] ) ? (array) $this->settings['ip_allowlist'] : array();
		return self::ip_in_list( $ip, $list );
	}

	/**
	 * The transient key for an IP. The raw IP is hashed so the address never
	 * appears in the options table verbatim and the key length stays bounded.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function transient_key( $ip ) {
		return self::TRANSIENT_PREFIX . md5( self::lock_id( $ip ) );
	}

	/**
	 * What a lockout is counted against. An IPv4 address counts on its own.
	 * An IPv6 address counts as its /64, since one connection owns the whole
	 * block and can pick any address inside it. An IPv4-mapped IPv6 address
	 * counts as the IPv4 address. The allowlist still checks the full address.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private static function lock_id( $ip ) {
		if ( false === strpos( $ip, ':' ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return $ip;
		}
		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $bin, 0, 12 ) ) {
			return implode( '.', array_values( unpack( 'C4', substr( $bin, 12 ) ) ) );
		}
		return bin2hex( substr( $bin, 0, 8 ) ) . '/64';
	}

	/**
	 * When an address's lockout ends, or 0 when it is not locked out.
	 *
	 * @param string $ip Client IP.
	 * @return int Unix time.
	 */
	private function locked_until( $ip ) {
		$record = get_transient( $this->transient_key( $ip ) );
		if ( is_array( $record ) && ! empty( $record['locked_until'] ) && time() < (int) $record['locked_until'] ) {
			return (int) $record['locked_until'];
		}
		return 0;
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

		$until = $this->locked_until( $ip );
		if ( $until ) {
			$minutes = max( 1, (int) ceil( ( $until - time() ) / 60 ) );
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
		$now = time();
		if ( (int) $record['locked_until'] > $now ) {
			// A failure during a lockout changes nothing. Otherwise one guess
			// every few minutes from a shared address would keep the owner
			// out for good. The lockout ends when it was set to end.
			return;
		}
		$record['count'] = (int) $record['count'] + 1;

		if ( $record['count'] >= $max ) {
			// A new lockout. Count it, and once enough have piled up inside
			// 24 hours, make this one the long one. The transient TTL is the
			// longer of the window and the lockout, so the record outlives
			// the block and self-expires afterward.
			$length                 = $this->next_lockout_length( $ip, $lockout );
			$until                  = $now + $length;
			$record['locked_until'] = $until;
			set_transient( $key, $record, max( $window, $until - $now ) );
			return;
		}

		set_transient( $key, $record, $window );
	}

	/**
	 * The key for an IP's lockout history.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function history_key( $ip ) {
		return self::TRANSIENT_PREFIX . 'h_' . md5( self::lock_id( $ip ) );
	}

	/**
	 * Log a new lockout for this IP and return how long it lasts. With
	 * escalation on, a lockout that follows login_long_after earlier ones
	 * inside 24 hours lasts login_long_hours. Every record expires with the
	 * transient, so nothing needs cleanup.
	 *
	 * @param string $ip      Client IP.
	 * @param int    $regular Regular lockout length in seconds.
	 * @return int Seconds.
	 */
	private function next_lockout_length( $ip, $regular ) {
		if ( empty( $this->settings['login_long_lockout'] ) ) {
			return $regular;
		}
		$now     = time();
		$key     = $this->history_key( $ip );
		$history = get_transient( $key );
		$history = is_array( $history ) ? array_values( array_filter( array_map( 'intval', $history ), static function ( $t ) use ( $now ) {
			return $t > $now - DAY_IN_SECONDS;
		} ) ) : array();
		$after   = max( 1, (int) $this->settings['login_long_after'] );
		$long    = count( $history ) >= $after;
		$history[] = $now;
		set_transient( $key, $history, DAY_IN_SECONDS );
		if ( $long ) {
			return max( $regular, max( 1, (int) $this->settings['login_long_hours'] ) * HOUR_IN_SECONDS );
		}
		return $regular;
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
	 * Drop the author's name and archive link from an oEmbed response. The
	 * archive link carries the login name.
	 *
	 * @param array $data oEmbed response data.
	 * @return array
	 */
	public function strip_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/**
	 * Remove the users sitemap, which lists every author archive URL.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Sitemap provider.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public function drop_users_sitemap( $provider, $name = '' ) {
		return 'users' === $name ? false : $provider;
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

	/* ---------- 6. one message for every failed login ---------- */

	/**
	 * The one message a failed login gets.
	 *
	 * @return string
	 */
	public static function generic_login_message() {
		return __( 'The username or password you entered is incorrect.', 'perdita-core' );
	}

	/**
	 * On the authenticate filter, after core (20), the lockout check, and the
	 * two-factor checks (30 and 40): swap the unknown username, unknown
	 * email, and wrong password errors for one message. Any other error
	 * (the lockout, a missing two-factor code, an empty field, a Turnstile
	 * failure) passes through untouched, and so does a XML-RPC or REST request, so an
	 * application password client keeps its own errors.
	 *
	 * @param WP_User|WP_Error|null $user Prior result in the filter chain.
	 * @return WP_User|WP_Error|null
	 */
	public function generic_login_error( $user ) {
		if ( ! ( $user instanceof WP_Error ) || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
			return $user;
		}
		$codes = $user->get_error_codes();
		if ( ! $codes || array_diff( $codes, array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
			return $user;
		}
		return new WP_Error( 'incorrect_password', self::generic_login_message() );
	}

	/**
	 * On lostpassword_post, after the Turnstile check: an unknown login or
	 * email gets the same outcome as a known one, a redirect to the "check
	 * your email" screen with no mail sent. Other errors (an empty field, a
	 * failed challenge) keep their own messages, and the "no account" errors
	 * are dropped so they never show next to them.
	 *
	 * @param WP_Error       $errors    Errors so far.
	 * @param WP_User|false  $user_data The user found, or false.
	 */
	public function hide_unknown_account( $errors, $user_data = false ) {
		if ( ! ( $errors instanceof WP_Error ) || ! $this->is_login_form_post() ) {
			return;
		}
		$errors->remove( 'invalid_email' );
		$errors->remove( 'invalidcombo' );
		if ( $errors->has_errors() || $user_data ) {
			return;
		}
		// The same redirect core sends after a mail goes out.
		$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : add_query_arg( 'checkemail', 'confirm', wp_login_url() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-auth form with no nonce, and wp_safe_redirect() validates the target.
		/** This filter is documented in wp-login.php */
		$redirect_to = apply_filters( 'lostpassword_redirect', $redirect_to );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/* ---------- 6b. owner unlock link ---------- */

	/**
	 * The login screen messages for the unlock flow: the result of a request
	 * or a link click, and the "email me an unlock link" form while this
	 * address is locked out.
	 *
	 * @param string $message Message HTML so far.
	 * @return string
	 */
	public function unlock_login_message( $message ) {
		$state = isset( $_GET['perdita_unlock'] ) ? sanitize_key( wp_unslash( $_GET['perdita_unlock'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$texts = array(
			'sent'    => __( 'If that username or email belongs to an administrator, Perdita sent an unlock link. Use it within 15 minutes.', 'perdita-core' ),
			'done'    => __( 'Your address is unlocked. You can log in now.', 'perdita-core' ),
			'invalid' => __( 'That unlock link expired or was already used.', 'perdita-core' ),
		);
		if ( isset( $texts[ $state ] ) ) {
			$message .= '<p class="message">' . esc_html( $texts[ $state ] ) . '</p>';
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display check.
		$ip     = $this->client_ip();
		if ( ( '' === $action || 'login' === $action ) && '' !== $ip && ! $this->is_allowlisted( $ip ) && $this->locked_until( $ip ) ) {
			$message .= '<form method="post" action="' . esc_url( add_query_arg( 'action', 'perdita_unlock', wp_login_url() ) ) . '" class="perdita-unlock" style="margin:0 0 16px;padding:16px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 3px rgba(0,0,0,.04)">';
			$message .= '<p style="margin:0 0 8px"><label for="perdita-unlock-user">' . esc_html__( 'Own this site? Enter your username or email.', 'perdita-core' ) . '</label></p>';
			$message .= '<p style="margin:0 0 8px"><input type="text" name="perdita_unlock_user" id="perdita-unlock-user" class="input" value="" autocomplete="username" style="width:100%;box-sizing:border-box"></p>';
			$message .= '<p style="margin:0"><button type="submit" class="button">' . esc_html__( 'Email me an unlock link', 'perdita-core' ) . '</button></p>';
			$message .= '</form>';
		}
		return $message;
	}

	/**
	 * wp-login.php?action=perdita_unlock. A POST asks for a link. A GET with
	 * a key uses one. Both end in a redirect to the login screen.
	 */
	public function handle_unlock() {
		$ip     = $this->client_ip();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' === $method ) {
			$this->send_unlock_link( $ip );
			$this->unlock_redirect( 'sent' );
		}

		$key = isset( $_GET['key'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time key is the credential.
		if ( '' === $key || '' === $ip ) {
			$this->unlock_redirect( '' );
		}
		$token = self::UNLOCK_PREFIX . hash( 'sha256', $key );
		$data  = get_transient( $token );
		delete_transient( $token ); // Single use, whatever happens next.
		$user  = is_array( $data ) && ! empty( $data['user'] ) ? get_userdata( (int) $data['user'] ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			$this->unlock_redirect( 'invalid' );
		}
		delete_transient( $this->transient_key( $ip ) );
		$this->unlock_redirect( 'done' );
	}

	/**
	 * Redirect to the login screen with a result code.
	 *
	 * @param string $state sent, done, invalid, or empty.
	 */
	private function unlock_redirect( $state ) {
		$url = wp_login_url();
		if ( '' !== $state ) {
			$url = add_query_arg( 'perdita_unlock', $state, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Mail an unlock link to the account the form named, when it belongs to
	 * someone who can manage options. The caller answers the same way
	 * whatever happens here. An address gets 3 sends an hour, counted whether
	 * or not the account exists.
	 *
	 * @param string $ip Client IP.
	 */
	private function send_unlock_link( $ip ) {
		if ( '' === $ip ) {
			return;
		}
		$limit_key = self::UNLOCK_LIMIT_PREFIX . md5( self::lock_id( $ip ) );
		$now       = time();
		$limit     = get_transient( $limit_key );
		if ( ! is_array( $limit ) || empty( $limit['until'] ) || (int) $limit['until'] <= $now ) {
			$limit = array(
				'count' => 0,
				'until' => $now + HOUR_IN_SECONDS,
			);
		}
		if ( (int) $limit['count'] >= self::UNLOCK_SENDS_PER_HOUR ) {
			return;
		}
		$limit['count'] = (int) $limit['count'] + 1;
		set_transient( $limit_key, $limit, max( 1, (int) $limit['until'] - $now ) );

		$login = isset( $_POST['perdita_unlock_user'] ) ? sanitize_text_field( wp_unslash( $_POST['perdita_unlock_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- pre-auth form, no nonce exists yet. The hourly limit covers abuse.
		if ( '' === $login ) {
			return;
		}
		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return;
		}

		$key = wp_generate_password( 32, false, false );
		set_transient( self::UNLOCK_PREFIX . hash( 'sha256', $key ), array( 'user' => (int) $user->ID ), 15 * MINUTE_IN_SECONDS );
		$url  = add_query_arg(
			array(
				'action' => 'perdita_unlock',
				'key'    => $key,
			),
			wp_login_url()
		);
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Your unlock link', 'perdita-core' ), $site ),
			sprintf(
				/* translators: 1: site name, 2: unlock URL. */
				__( "Someone asked to unlock a login address on %1\$s. Open the link below from the device you want to unlock, within 15 minutes. You can use it once.\n\n%2\$s\n\nIf you didn't ask for this, you can ignore this email.", 'perdita-core' ),
				$site,
				$url
			)
		);
	}

	/* ---------- 7. Turnstile on the login screens ---------- */

	/**
	 * The Forms Turnstile keys: array( site key, secret ), or null when
	 * either is missing. An unreadable secret counts as missing.
	 *
	 * @return array|null
	 */
	public static function turnstile_keys() {
		$s      = get_option( 'perdita_forms_settings', array() );
		$s      = is_array( $s ) ? $s : array();
		$site   = isset( $s['turnstile_site_key'] ) ? trim( (string) $s['turnstile_site_key'] ) : '';
		$stored = isset( $s['turnstile_secret_key'] ) ? (string) $s['turnstile_secret_key'] : '';
		if ( '' === $site || '' === $stored || ! class_exists( 'Perdita_Crypto' ) ) {
			return null;
		}
		$secret = Perdita_Crypto::decrypt_strict( $stored );
		if ( ! is_string( $secret ) || '' === $secret ) {
			return null;
		}
		return array( $site, $secret );
	}

	/**
	 * Whether Turnstile will run on the login screens: the setting is on and
	 * the Forms keys exist.
	 *
	 * @return bool
	 */
	public function turnstile_active() {
		return ! empty( $this->settings['login_turnstile'] ) && null !== self::turnstile_keys();
	}

	/**
	 * Whether this request is a form post to wp-login.php. XML-RPC, REST,
	 * application passwords, and WP-CLI never are.
	 *
	 * @return bool
	 */
	private function is_login_form_post() {
		global $pagenow;
		if ( defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return 'wp-login.php' === $pagenow && 'POST' === $method;
	}

	/**
	 * Load Cloudflare's script on the login screens.
	 */
	public function enqueue_turnstile() {
		if ( ! $this->turnstile_active() ) {
			return;
		}
		wp_enqueue_script( 'perdita-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent, WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party endpoint, versionless by design.
	}

	/**
	 * Print the widget inside the login, register, and lost password forms.
	 */
	public function render_turnstile() {
		$keys = $this->turnstile_active() ? self::turnstile_keys() : null;
		if ( $keys ) {
			echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $keys[0] ) . '" style="margin:0 0 16px"></div>';
		}
	}

	/**
	 * Check the challenge once per request. Fails open the way Forms does:
	 * a verify endpoint outage, a 429 or 5xx, and a rejected site secret all
	 * let the visitor through (the secret case flags the owner notice, and
	 * perdita_forms_turnstile_fail_open can turn it off). A failed challenge
	 * on a good secret blocks.
	 *
	 * @return string '' when the visitor passes, or the message to show.
	 */
	private function turnstile_verdict() {
		if ( null !== $this->turnstile_memo ) {
			return $this->turnstile_memo;
		}
		$memo = '';
		$keys = self::turnstile_keys();
		if ( ! $keys ) {
			return $this->turnstile_memo = $memo;
		}
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- pre-auth form, no nonce exists yet.
		if ( '' === $token ) {
			return $this->turnstile_memo = __( 'Please complete the anti-spam check and try again.', 'perdita-core' );
		}
		$resp = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- a server-side Turnstile token check, not an offloaded asset.
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $keys[1],
					'response' => $token,
					'remoteip' => $this->client_ip(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $this->turnstile_memo = $memo;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 429 === $code || $code >= 500 ) {
			return $this->turnstile_memo = $memo;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( is_array( $data ) && ! empty( $data['success'] ) ) {
			if ( class_exists( 'Perdita_Forms' ) ) {
				Perdita_Forms::clear_turnstile_key_error();
			}
			return $this->turnstile_memo = $memo;
		}
		if ( class_exists( 'Perdita_Forms' ) && Perdita_Forms::is_turnstile_key_rejection( $data ) ) {
			Perdita_Forms::flag_turnstile_key_error();
			// This filter is the same one Forms reads. Default true.
			if ( (bool) apply_filters( 'perdita_forms_turnstile_fail_open', true ) ) {
				return $this->turnstile_memo = $memo;
			}
		}
		$memo = __( 'The anti-spam check did not pass. Please try again.', 'perdita-core' );
		return $this->turnstile_memo = $memo;
	}

	/**
	 * On the authenticate filter (priority 21, after core's password check):
	 * a login form post needs a passing challenge. Nothing else is
	 * challenged.
	 *
	 * @param WP_User|WP_Error|null $user     Prior result.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function check_login_turnstile( $user, $username = '', $password = '' ) {
		if ( '' === (string) $username || '' === (string) $password || ! $this->turnstile_active() || ! $this->is_login_form_post() ) {
			return $user;
		}
		$message = $this->turnstile_verdict();
		return '' === $message ? $user : new WP_Error( 'perdita_turnstile', $message );
	}

	/**
	 * On the registration form.
	 *
	 * @param WP_Error $errors Errors so far.
	 * @return WP_Error
	 */
	public function check_register_turnstile( $errors ) {
		if ( $errors instanceof WP_Error && $this->turnstile_active() && $this->is_login_form_post() ) {
			$message = $this->turnstile_verdict();
			if ( '' !== $message ) {
				$errors->add( 'perdita_turnstile', $message );
			}
		}
		return $errors;
	}

	/**
	 * On the lost password form.
	 *
	 * @param WP_Error $errors Errors so far.
	 */
	public function check_lostpassword_turnstile( $errors ) {
		if ( $errors instanceof WP_Error && $this->turnstile_active() && $this->is_login_form_post() ) {
			$message = $this->turnstile_verdict();
			if ( '' !== $message ) {
				$errors->add( 'perdita_turnstile', $message );
			}
		}
	}

	/* ---------- 8. hardening ---------- */

	/**
	 * Drop the ver= argument from a core asset URL when it equals the
	 * WordPress version. Plugin and theme assets keep theirs.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function strip_core_version( $src ) {
		global $wp_version;
		if ( ! is_string( $src ) || false === strpos( $src, 'ver=' ) ) {
			return $src;
		}
		$core = ( 0 === strpos( $src, includes_url() ) ) || ( 0 === strpos( $src, admin_url() ) );
		if ( ! $core ) {
			return $src;
		}
		$query = (string) wp_parse_url( $src, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( isset( $args['ver'] ) && (string) $args['ver'] === (string) $wp_version ) {
			return remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * The block this module owns in uploads/.htaccess. It refuses PHP and
	 * related files, including a double extension like shell.php.jpg.
	 *
	 * @return string With a trailing newline.
	 */
	public static function uploads_block() {
		return self::UPLOADS_BEGIN . "\n"
			. '<FilesMatch "(?i)\.(php[0-9]?|phtml|phar|pht|phps)(\.|$)">' . "\n"
			. "\t<IfModule mod_authz_core.c>\n"
			. "\t\tRequire all denied\n"
			. "\t</IfModule>\n"
			. "\t<IfModule !mod_authz_core.c>\n"
			. "\t\tOrder allow,deny\n"
			. "\t\tDeny from all\n"
			. "\t</IfModule>\n"
			. "</FilesMatch>\n"
			. self::UPLOADS_END . "\n";
	}

	/**
	 * The nginx rule an owner adds to the server config, since nginx ignores
	 * .htaccess.
	 *
	 * @return string
	 */
	public static function uploads_nginx_rule() {
		$u    = wp_get_upload_dir();
		$path = (string) wp_parse_url( $u['baseurl'], PHP_URL_PATH );
		return 'location ~* ^' . rtrim( $path, '/' ) . '/.*\.(php[0-9]?|phtml|phar|pht|phps)(\.|$) { deny all; }';
	}

	/**
	 * Cut this module's block out of .htaccess text. Every other byte stays.
	 *
	 * @param string $content File text.
	 * @return string
	 */
	public static function strip_uploads_block( $content ) {
		$content = (string) $content;
		$start   = strpos( $content, self::UPLOADS_BEGIN );
		if ( false === $start ) {
			return $content;
		}
		$end = strpos( $content, self::UPLOADS_END, $start );
		if ( false === $end ) {
			return $content; // A broken block is left for the owner.
		}
		$end += strlen( self::UPLOADS_END );
		if ( "\r\n" === substr( $content, $end, 2 ) ) {
			$end += 2;
		} elseif ( "\n" === substr( $content, $end, 1 ) ) {
			$end += 1;
		}
		$before = substr( $content, 0, $start );
		if ( "\n\n" === substr( $before, -2 ) ) {
			$before = substr( $before, 0, -1 ); // The blank line this module added.
		}
		return $before . substr( $content, $end );
	}

	/**
	 * Write or remove the uploads block. Lines outside the block are never
	 * changed. The file goes away only when nothing is left in it.
	 *
	 * @param bool $enabled True to write the block, false to remove it.
	 * @return true|WP_Error
	 */
	public static function sync_uploads_block( $enabled ) {
		$u = wp_get_upload_dir();
		if ( ! empty( $u['error'] ) || empty( $u['basedir'] ) ) {
			return $enabled ? new WP_Error( 'perdita_uploads_dir', __( 'WordPress cannot find your uploads folder.', 'perdita-core' ) ) : true;
		}
		$file    = trailingslashit( $u['basedir'] ) . '.htaccess';
		$exists  = is_file( $file );
		$content = $exists ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		if ( false === $content ) {
			return $enabled ? new WP_Error( 'perdita_uploads_read', __( 'Perdita cannot read the .htaccess file in your uploads folder.', 'perdita-core' ) ) : true;
		}

		$base = self::strip_uploads_block( $content );
		if ( ! $enabled ) {
			if ( $base === $content ) {
				return true;
			}
			if ( '' === trim( $base ) ) {
				wp_delete_file( $file );
				return is_file( $file ) ? new WP_Error( 'perdita_uploads_write', __( 'Perdita cannot change the .htaccess file in your uploads folder.', 'perdita-core' ) ) : true;
			}
			return self::write_file_atomic( $file, $base );
		}

		if ( '' !== $base && "\n" !== substr( $base, -1 ) ) {
			$base .= "\n";
		}
		$new = ( '' === $base ? '' : $base . "\n" ) . self::uploads_block();
		if ( $new === $content ) {
			return true;
		}
		return self::write_file_atomic( $file, $new );
	}

	/**
	 * Whether the uploads block is in the file now.
	 *
	 * @return bool
	 */
	public static function uploads_block_present() {
		$u = wp_get_upload_dir();
		if ( empty( $u['basedir'] ) ) {
			return false;
		}
		$file = trailingslashit( $u['basedir'] ) . '.htaccess';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$c = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return is_string( $c ) && false !== strpos( $c, self::UPLOADS_BEGIN ) && false !== strpos( $c, self::UPLOADS_END );
	}

	/**
	 * Write a file through a temp file in the same folder, then rename, so a
	 * reader never sees half a file. Keeps the old permissions.
	 *
	 * @param string $file    Target.
	 * @param string $content New text.
	 * @return true|WP_Error
	 */
	private static function write_file_atomic( $file, $content ) {
		$dir = dirname( $file );
		wp_mkdir_p( $dir );
		$fail = new WP_Error( 'perdita_uploads_write', __( 'Perdita cannot change the .htaccess file in your uploads folder. Check the permissions on that folder.', 'perdita-core' ) );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) || ( is_file( $file ) && ! is_writable( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a direct check, no credentials flow here.
			return $fail;
		}
		$mode = is_file( $file ) ? ( fileperms( $file ) & 0777 ) : 0644;
		$tmp  = $dir . '/.htaccess.perdita-' . wp_generate_password( 8, false );
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file in the same folder, renamed into place.
			return $fail;
		}
		chmod( $tmp, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod -- keeps the old file's permissions.
		if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap.
			wp_delete_file( $tmp );
			return $fail;
		}
		return true;
	}

	/* ---------- 9. HSTS ---------- */

	/**
	 * Send Strict-Transport-Security. Only over HTTPS: a browser ignores it
	 * on HTTP, and a proxy that forwards it would pin the wrong scheme.
	 */
	public function send_hsts() {
		$value = $this->hsts_value();
		if ( '' !== $value && ! headers_sent() ) {
			header( 'Strict-Transport-Security: ' . $value );
		}
	}

	/**
	 * The header value, or '' on a plain HTTP request or with HSTS off.
	 *
	 * @return string
	 */
	public function hsts_value() {
		if ( empty( $this->settings['hsts'] ) || ! is_ssl() ) {
			return '';
		}
		$value = 'max-age=' . max( 0, (int) $this->settings['hsts_max_age'] );
		if ( ! empty( $this->settings['hsts_subdomains'] ) ) {
			$value .= '; includeSubDomains';
		}
		return $value;
	}

	/* ---------- 10. core file check ---------- */

	/**
	 * The checksums for this WordPress version, cached for a day. Tries the
	 * site's locale, then en_US, like core does.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @param bool   $fresh   Skip the cache.
	 * @return array|WP_Error File => md5.
	 */
	private static function core_checksums( $version, $locale, $fresh = false ) {
		$key = 'perdita_sec_sums_' . md5( $version . '|' . $locale );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && $cached ) {
				return $cached;
			}
		}
		foreach ( array_unique( array( $locale, 'en_US' ) ) as $try ) {
			$url  = add_query_arg(
				array(
					'version' => $version,
					'locale'  => $try,
				),
				'https://api.wordpress.org/core/checksums/1.0/'
			);
			$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
			if ( is_wp_error( $resp ) ) {
				return new WP_Error( 'perdita_core_check_http', __( 'Perdita could not reach wordpress.org to get the file list. It will try again on the next check.', 'perdita-core' ) );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'perdita_core_check_http', __( 'wordpress.org did not answer with a file list. Perdita will try again on the next check.', 'perdita-core' ) );
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $body ) && isset( $body['checksums'][ $version ] ) && is_array( $body['checksums'][ $version ] ) && $body['checksums'][ $version ] ) {
				set_transient( $key, $body['checksums'][ $version ], DAY_IN_SECONDS );
				return $body['checksums'][ $version ];
			}
		}
		return new WP_Error( 'perdita_core_check_none', __( 'wordpress.org has no file list for this WordPress version, so Perdita cannot compare your files.', 'perdita-core' ) );
	}

	/**
	 * Compare WordPress core files with wordpress.org's checksums and save
	 * the result in the perdita_security_core_check option. wp-content is
	 * skipped. Reports changed files, missing files, and PHP files in
	 * wp-admin and wp-includes that core does not ship.
	 *
	 * @param bool $fresh Skip the cached checksums.
	 * @return array The saved result.
	 */
	public static function run_core_check( $fresh = false ) {
		global $wp_version;
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$version = (string) $wp_version;
		$locale  = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$result  = array(
			'time'    => time(),
			'version' => $version,
			'status'  => 'ok',
			'checked' => 0,
			'changed' => array(),
			'missing' => array(),
			'unknown' => array(),
			'error'   => '',
		);

		$sums = self::core_checksums( $version, $locale, (bool) $fresh );
		if ( is_wp_error( $sums ) ) {
			$result['status'] = 'error';
			$result['error']  = $sums->get_error_message();
			update_option( self::CORE_CHECK_OPTION, $result, false );
			return $result;
		}

		/**
		 * The folder that holds wp-admin and wp-includes. Tests point it at a
		 * temp copy.
		 *
		 * @param string $root Folder with a trailing slash.
		 */
		$root  = trailingslashit( (string) apply_filters( 'perdita_security_core_check_root', ABSPATH ) );
		$limit = 200;
		foreach ( $sums as $file => $md5 ) {
			$file = (string) $file;
			if ( 0 === strpos( $file, 'wp-content/' ) || false !== strpos( $file, '..' ) ) {
				continue;
			}
			++$result['checked'];
			$path = $root . $file;
			if ( ! is_file( $path ) ) {
				$result['missing'][] = $file;
			} elseif ( md5_file( $path ) !== $md5 ) {
				$result['changed'][] = $file;
			}
		}
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			if ( ! is_dir( $root . $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $info ) {
				if ( ! $info->isFile() || 'php' !== strtolower( $info->getExtension() ) ) {
					continue;
				}
				$rel = str_replace( '\\', '/', substr( $info->getPathname(), strlen( $root ) ) );
				if ( ! isset( $sums[ $rel ] ) ) {
					$result['unknown'][] = $rel;
				}
			}
		}
		sort( $result['changed'] );
		sort( $result['missing'] );
		sort( $result['unknown'] );
		$result['counts'] = array(
			'changed' => count( $result['changed'] ),
			'missing' => count( $result['missing'] ),
			'unknown' => count( $result['unknown'] ),
		);
		foreach ( array( 'changed', 'missing', 'unknown' ) as $list ) {
			$result[ $list ] = array_slice( $result[ $list ], 0, $limit );
		}
		if ( array_sum( $result['counts'] ) > 0 ) {
			$result['status'] = 'problems';
		}
		update_option( self::CORE_CHECK_OPTION, $result, false );
		return $result;
	}

	/**
	 * One sentence for the last core check.
	 *
	 * @return string
	 */
	public static function core_check_summary() {
		$r = get_option( self::CORE_CHECK_OPTION, array() );
		if ( ! is_array( $r ) || empty( $r['time'] ) ) {
			return __( 'No check has run yet.', 'perdita-core' );
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r['time'] );
		if ( 'error' === $r['status'] ) {
			/* translators: 1: date and time, 2: the reason. */
			return sprintf( __( 'Last check %1$s did not finish. %2$s', 'perdita-core' ), $when, (string) $r['error'] );
		}
		if ( 'ok' === $r['status'] ) {
			/* translators: 1: date and time, 2: number of files. */
			return sprintf( __( 'Last check %1$s: all %2$d core files match wordpress.org.', 'perdita-core' ), $when, (int) $r['checked'] );
		}
		$c = isset( $r['counts'] ) ? $r['counts'] : array(
			'changed' => count( $r['changed'] ),
			'missing' => count( $r['missing'] ),
			'unknown' => count( $r['unknown'] ),
		);
		return sprintf(
			/* translators: 1: date and time, 2: changed files, 3: missing files, 4: unknown PHP files. */
			__( 'Last check %1$s: %2$d changed, %3$d missing, and %4$d unknown PHP files.', 'perdita-core' ),
			$when,
			(int) $c['changed'],
			(int) $c['missing'],
			(int) $c['unknown']
		);
	}

	/**
	 * Undo everything this module put outside its own option: the uploads
	 * block and the daily check. Runs when the module goes off, when Core
	 * deactivates, and on uninstall.
	 */
	public static function teardown() {
		self::sync_uploads_block( false );
		wp_clear_scheduled_hook( self::CORE_CHECK_CRON );
	}
}
