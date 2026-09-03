<?php
/**
 * Subscriptions module: a slim, real double opt-in email subscription system.
 *
 * A visitor submits an email through the [perdita_subscribe] shortcode, gets a
 * confirmation email with a one-click link, and once confirmed is notified by
 * email whenever a new post is published. Unsubscribing needs no login: the
 * token in the link is the authenticator. It does need a POST, so a link
 * scanner or inbox preview cannot unsubscribe somebody who never clicked.
 * Notification mail also carries the RFC 8058 List-Unsubscribe pair, so the
 * mail client's own Unsubscribe button posts straight to the REST endpoint.
 *
 * ABUSE MODEL. This is a public endpoint that accepts a stranger's email
 * address and sends that address mail, so the interesting threat isn't "spam
 * in my inbox" (the confirmation link never lets an attacker read anything),
 * it's "email-bomb a stranger with confirmation mail" and "learn which
 * addresses are already on the list". Both are handled here:
 *   - Honeypot + per-shortcode nonce + per-IP rate limit gate the endpoint.
 *   - A pending signup only re-sends its confirmation email if the last send
 *     was more than a cooldown window ago, so repeatedly POSTing the same
 *     victim address does not flood their inbox.
 *   - The HTTP response is identical (a generic "check your email" message)
 *     whether the address was new, already pending, already confirmed, or
 *     resubscribing after unsubscribe, so the endpoint never reveals whether
 *     a given address is on the list (no email enumeration).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Subscriptions {

	/**
	 * Option name for stored settings.
	 */
	const OPTION = 'perdita_subscriptions_settings';

	/**
	 * DB schema version, bumped when the table shape changes so install()
	 * (run again via dbDelta) can bring existing sites up to date.
	 */
	const DB_VERSION = '1';

	/**
	 * Cron hook that processes one batch of a new-post notification queue.
	 */
	const CRON_HOOK = 'perdita_subscriptions_send_batch';

	/**
	 * Nonce action for the public subscribe form. A dedicated action (not the
	 * cookie-bound 'wp_rest' nonce) so the form also works for logged-out
	 * visitors with no session.
	 */
	const NONCE_ACTION = 'perdita_subscribe';

	/**
	 * How many subscribers a single batch run emails before rescheduling.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Seconds to wait before running the next batch when more subscribers
	 * remain, so a large list sends in waves without blocking a request.
	 */
	const BATCH_INTERVAL = 60;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor. Register every hook the module needs.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_shortcode( 'perdita_subscribe', array( $this, 'shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_confirm' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_unsubscribe' ) );
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );
		add_filter( 'perdita_cache_exclude', array( $this, 'exclude_from_cache_if_form_rendered' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'proxy_ip_notice' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_proxy_ip_notice' ) );
		}
	}

	/**
	 * Set by shortcode() when the subscribe form is actually rendered into
	 * the page. Request-scoped: the caching module re-applies the
	 * perdita_cache_exclude filter after the buffer is built, so a flag
	 * raised during render is enough to keep the page out of the cache.
	 *
	 * @var bool
	 */
	private static $form_rendered = false;

	/**
	 * Option flag: a forwarded-for/CF-Connecting-IP header was seen while no
	 * 'perdita_subscriptions_client_ip' filter was hooked, so the per-IP
	 * throttle was skipped. 1 means "show the notice", 'dismissed' means the
	 * owner closed it.
	 */
	const PROXY_IP_NOTICE_OPTION = 'perdita_subscriptions_proxy_ip_notice';

	/**
	 * Keep a page carrying the subscribe form out of the page cache.
	 *
	 * The form carries a nonce, and a cache HIT replays the FIRST visitor's
	 * HTML to everyone else: once that captured nonce expires, every signup
	 * on that page fails with "your session expired" until the entry is
	 * rebuilt. The flag is set at render time rather than by scanning
	 * post_content, so a form placed in a widget, a reusable block, or a
	 * template part is covered too.
	 *
	 * @param bool $exclude Whether the caching module already wants to
	 *                      exclude this request.
	 * @return bool
	 */
	public function exclude_from_cache_if_form_rendered( $exclude ) {
		return self::$form_rendered ? true : $exclude;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'from_name'            => get_bloginfo( 'name' ),
			'confirm_subject'      => __( 'Confirm your subscription', 'perdita-core' ),
			'confirm_body'         => __( "Please confirm you'd like to receive email updates from {site_name}.\n\nClick the link below to confirm:\n{confirm_url}\n\nIf you didn't request this, you can ignore this email and you won't be added.", 'perdita-core' ),
			'notify_subject'       => __( 'New post: {post_title}', 'perdita-core' ),
			'notify_body'          => __( "{post_title}\n\n{post_excerpt}\n\nRead the full post:\n{post_url}\n\n---\nUnsubscribe at any time:\n{unsubscribe_url}", 'perdita-core' ),
			'resend_cooldown_mins' => 10,
		);
	}

	/**
	 * Read stored settings merged over defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/* ---------------------------------------------------------------------
	 * Install
	 * ------------------------------------------------------------------- */

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'perdita_subscribers';
	}

	/**
	 * Create the subscribers table and seed default settings. Runs on module
	 * activation. Static so the descriptor can call it without an instance.
	 */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				email VARCHAR(255) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				confirm_token VARCHAR(64) DEFAULT '',
				unsubscribe_token VARCHAR(64) DEFAULT '',
				last_sent DATETIME NULL,
				created DATETIME NOT NULL,
				confirmed_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status),
				KEY confirm_token (confirm_token),
				KEY unsubscribe_token (unsubscribe_token)
			) $charset;"
		);
		update_option( 'perdita_subscriptions_db_version', self::DB_VERSION );

		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defaults() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------- */

	/**
	 * Shortcode: [perdita_subscribe].
	 *
	 * @return string
	 */
	public function shortcode() {
		// A nonce is about to go into this response, so the page must never
		// be stored in the page cache (see
		// exclude_from_cache_if_form_rendered()).
		self::$form_rendered = true;

		wp_enqueue_style( 'perdita-subscriptions', PERDITA_CORE_URL . 'inc/modules/subscriptions/assets/subscriptions.css', array(), PERDITA_CORE_VERSION );
		wp_enqueue_script( 'perdita-subscriptions', PERDITA_CORE_URL . 'inc/modules/subscriptions/assets/subscriptions.js', array(), PERDITA_CORE_VERSION, true );
		wp_localize_script(
			'perdita-subscriptions',
			'PerditaSubscribe',
			array(
				'rest'    => esc_url_raw( rest_url( 'perdita/v1/subscribe' ) ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'strings' => array(
					'networkError' => __( 'Network error. Please try again.', 'perdita-core' ),
				),
			)
		);

		ob_start();
		?>
		<form class="perdita-subscribe" novalidate>
			<input type="hidden" name="perdita_subscribe_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" />
			<div class="perdita-subscribe__hp" aria-hidden="true" style="position:absolute;left:-9999px;">
				<label><?php esc_html_e( 'Leave this field empty', 'perdita-core' ); ?><input type="text" name="perdita_hp" tabindex="-1" autocomplete="off" /></label>
			</div>
			<p class="perdita-subscribe__field">
				<label for="perdita-subscribe-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'perdita-core' ); ?></label>
				<input type="email" id="perdita-subscribe-email" name="email" placeholder="<?php esc_attr_e( 'you@example.com', 'perdita-core' ); ?>" required />
				<button type="submit" class="perdita-subscribe__submit"><?php esc_html_e( 'Subscribe', 'perdita-core' ); ?></button>
			</p>
			<div class="perdita-subscribe__message" role="status" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * REST: signup
	 * ------------------------------------------------------------------- */

	/**
	 * Register the public signup route. Logged-out visitors must be able to
	 * subscribe, so this uses '__return_true' and verifies its own dedicated
	 * nonce (see NONCE_ACTION) instead of relying on cookie-bound REST auth.
	 */
	public function routes() {
		register_rest_route(
			'perdita/v1',
			'/subscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_subscribe' ),
			)
		);

		// RFC 8058 one-click unsubscribe. POST only, on purpose: this is the
		// endpoint named in the List-Unsubscribe header, and a GET-able
		// version of it would be unsubscribed by the first link-scanning
		// mail security appliance or inbox preview that touched the mail.
		// The token is the authenticator (it only ever exists inside mail
		// sent to that address), so there is no nonce and no login.
		register_rest_route(
			'perdita/v1',
			'/unsubscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_one_click_unsubscribe' ),
			)
		);
	}

	/**
	 * The RFC 8058 one-click unsubscribe URL for a token.
	 *
	 * @param string $token Unsubscribe token.
	 * @return string
	 */
	public static function one_click_unsubscribe_url( $token ) {
		// Appended by hand rather than via add_query_arg(): on a site with
		// plain permalinks rest_url() is index.php?rest_route=/perdita/v1/...,
		// and add_query_arg() rebuilds that query with the slashes
		// percent-encoded. WordPress decodes it fine, but the mail client
		// (and anyone reading the header) gets a needlessly ugly URL.
		$base = rest_url( 'perdita/v1/unsubscribe' );
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'token=' . rawurlencode( (string) $token );
	}

	/**
	 * Handle the one-click unsubscribe POST. Mail clients send this with a
	 * 'List-Unsubscribe=One-Click' body and only look at the status code.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_one_click_unsubscribe( WP_REST_Request $req ) {
		$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
		$row   = $this->subscriber_by_unsubscribe_token( $token );

		if ( ! $row ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'This unsubscribe link is invalid.', 'perdita-core' ),
				),
				404
			);
		}

		$this->mark_unsubscribed( $row );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( "You're unsubscribed. You won't get any more emails from this list.", 'perdita-core' ),
			),
			200
		);
	}

	/**
	 * Handle a signup POST.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_subscribe( WP_REST_Request $req ) {
		// The single response used for every "did not blow up" outcome, so a
		// caller can never distinguish new signup / resend / already-confirmed
		// / already-pending from the response shape (avoids email enumeration).
		$generic_ok = new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Check your email to confirm your subscription.', 'perdita-core' ),
			),
			200
		);

		// Honeypot: bots that fill every field get a success-looking response
		// and nothing is stored or emailed.
		if ( ! empty( $req->get_param( 'perdita_hp' ) ) ) {
			return $generic_ok;
		}

		$nonce = $req->get_param( 'perdita_subscribe_nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Your session expired. Reload the page and try again.', 'perdita-core' ),
				),
				403
			);
		}

		if ( $this->is_rate_limited() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Too many attempts. Please try again later.', 'perdita-core' ),
				),
				429
			);
		}

		$email = sanitize_email( wp_unslash( (string) $req->get_param( 'email' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Enter a valid email address.', 'perdita-core' ),
				),
				422
			);
		}

		$this->process_signup( $email );

		return $generic_ok;
	}

	/**
	 * Look up, insert, or resend as needed for a signup attempt. Every branch
	 * either does nothing (already confirmed) or ends with an email sent at
	 * most once per cooldown window, no matter how the caller retries.
	 *
	 * @param string $email Sanitized, validated email.
	 */
	private function process_signup( $email ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB

		if ( $row && 'confirmed' === $row->status ) {
			// Already subscribed. No email, and the caller can't tell this
			// happened since the HTTP response is identical either way.
			return;
		}

		if ( $row && 'pending' === $row->status && ! $this->cooldown_elapsed( $row->last_sent ) ) {
			// A confirmation was already sent recently. This is the core
			// abuse case: someone repeatedly submitting a stranger's address
			// to flood their inbox. Do nothing until the cooldown passes.
			return;
		}

		$token = $this->generate_token();
		$now   = current_time( 'mysql' );

		if ( $row ) {
			// Resend for a still-pending row, or re-subscribe after
			// unsubscribing. Either way: fresh token, fresh timestamp, back
			// to pending.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'        => 'pending',
					'confirm_token' => $token,
					'last_sent'     => $now,
				),
				array( 'id' => $row->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'email'         => $email,
					'status'        => 'pending',
					'confirm_token' => $token,
					'last_sent'     => $now,
					'created'       => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$this->send_confirmation_email( $email, $token );
	}

	/**
	 * Whether the resend cooldown window has passed since a timestamp.
	 *
	 * @param string|null $last_sent MySQL datetime, or null/empty if never sent.
	 * @return bool
	 */
	private function cooldown_elapsed( $last_sent ) {
		if ( empty( $last_sent ) ) {
			return true;
		}
		$settings = $this->get_settings();
		$minutes  = max( 1, (int) $settings['resend_cooldown_mins'] );
		$elapsed  = time() - strtotime( get_gmt_from_date( $last_sent ) . ' UTC' );
		return $elapsed >= ( $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Generate a high-entropy token for confirm/unsubscribe links.
	 *
	 * @return string 64 hex characters.
	 */
	private function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Email the confirmation link.
	 *
	 * @param string $email Recipient.
	 * @param string $token Confirm token.
	 */
	private function send_confirmation_email( $email, $token ) {
		$settings = $this->get_settings();
		$confirm_url = add_query_arg( 'perdita_subscribe_confirm', $token, home_url( '/' ) );

		$subject = $this->replace_tokens(
			$settings['confirm_subject'],
			array( 'site_name' => get_bloginfo( 'name' ) )
		);
		$body = $this->replace_tokens(
			$settings['confirm_body'],
			array(
				'site_name'   => get_bloginfo( 'name' ),
				'confirm_url' => $confirm_url,
			)
		);

		$this->mail( $email, $subject, $body );
	}

	/**
	 * Replace {token} placeholders in a template string.
	 *
	 * @param string $template Template.
	 * @param array  $tokens   Key => value, keys without braces.
	 * @return string
	 */
	private function replace_tokens( $template, array $tokens ) {
		$search  = array();
		$replace = array();
		foreach ( $tokens as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}
		return str_replace( $search, $replace, (string) $template );
	}

	/**
	 * Send mail with the configured From name. Uses wp_mail() so it works
	 * standalone and automatically routes through the SMTP module's
	 * phpmailer_init hook if that module happens to be enabled.
	 *
	 * @param string   $to      Recipient.
	 * @param string   $subject Subject.
	 * @param string   $body    Plain-text body.
	 * @param string[] $headers Extra mail headers, e.g. List-Unsubscribe.
	 */
	private function mail( $to, $subject, $body, array $headers = array() ) {
		$settings  = $this->get_settings();
		$from_name = trim( (string) $settings['from_name'] );

		if ( '' !== $from_name ) {
			$set_name = function ( $name ) use ( $from_name ) {
				return $from_name;
			};
			add_filter( 'wp_mail_from_name', $set_name );
			wp_mail( $to, $subject, $body, $headers );
			remove_filter( 'wp_mail_from_name', $set_name );
			return;
		}

		wp_mail( $to, $subject, $body, $headers );
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the client IP used for rate limiting. Defaults to REMOTE_ADDR
	 * only, never a forwarded header, since an unvetted header would let a
	 * client spoof its own rate limit away. A proxied site can wire in the
	 * real IP via the filter.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'perdita_subscriptions_client_ip', $ip );
	}

	/**
	 * Whether this request arrived through something that rewrote the client
	 * address, so REMOTE_ADDR is a proxy hop and not a visitor. Presence
	 * check only: the header's value stays untrusted.
	 *
	 * @return bool
	 */
	public static function behind_forwarding_proxy() {
		return ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only, the value is never read or trusted.
	}

	/**
	 * Note that the per-IP throttle had to be skipped, so the owner sees one
	 * dismissible notice explaining how to switch it back on. add_option()
	 * is a no-op once the row exists, so a dismissed notice never returns.
	 */
	private static function flag_proxy_ip_detected() {
		add_option( self::PROXY_IP_NOTICE_OPTION, 1, '', false );
	}

	/**
	 * Admin notice: the signup throttle is off because every request looks
	 * like it comes from the same proxy address.
	 */
	public static function proxy_ip_notice() {
		if ( ! current_user_can( 'manage_options' ) || 1 !== (int) get_option( self::PROXY_IP_NOTICE_OPTION ) ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'perdita_dismiss_proxy_ip_notice', 'subscriptions' ), 'perdita_dismiss_proxy_ip_notice' );
		echo '<div class="notice notice-warning"><p><strong>';
		esc_html_e( 'Perdita subscriptions: the per-visitor signup limit is switched off.', 'perdita-core' );
		echo '</strong> ';
		esc_html_e( 'This site is behind Cloudflare or another proxy, so every signup arrives from the same address and a per-IP limit would either throttle all visitors at once or nothing at all. The honeypot, nonce, and the per-address resend cooldown still apply. To switch the limit back on, hook the perdita_subscriptions_client_ip filter and return the real visitor IP your proxy sends.', 'perdita-core' );
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'perdita-core' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Handle the notice's dismiss link.
	 */
	public static function maybe_dismiss_proxy_ip_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified immediately below, once we know this request is the dismissal.
		$which = isset( $_GET['perdita_dismiss_proxy_ip_notice'] ) ? sanitize_key( wp_unslash( $_GET['perdita_dismiss_proxy_ip_notice'] ) ) : '';
		if ( 'subscriptions' !== $which ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'perdita_dismiss_proxy_ip_notice' ) ) {
			return;
		}
		update_option( self::PROXY_IP_NOTICE_OPTION, 'dismissed', false );
	}

	/**
	 * Simple per-IP signup throttle backed by a short-lived transient.
	 * Filterable cap (default 10) per hour, per IP.
	 *
	 * @return bool True if the caller is over the cap.
	 */
	private function is_rate_limited() {
		// Behind a CDN or load balancer, REMOTE_ADDR is the proxy, so every
		// visitor shares ONE bucket: the tenth legitimate signup of the hour
		// locks out the whole site while a spammer rotating real addresses
		// is never separated out. Skip the throttle and say so, unless the
		// owner wired the real client IP in through the filter. A forwarded
		// header is still never trusted on its own: a value the client can
		// set is a rate limit the client can erase.
		if ( ! has_filter( 'perdita_subscriptions_client_ip' ) && self::behind_forwarding_proxy() ) {
			self::flag_proxy_ip_detected();
			return false;
		}

		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return false;
		}
		/** Max signup attempts per IP per hour. */
		$max = (int) apply_filters( 'perdita_subscriptions_rate_limit', 10 );
		if ( $max <= 0 ) {
			return false;
		}
		$key   = 'perdita_subs_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Confirm / unsubscribe
	 * ------------------------------------------------------------------- */

	/**
	 * Handle ?perdita_subscribe_confirm=TOKEN.
	 *
	 * The token is 32 random bytes (64 hex chars), so a direct WHERE
	 * confirm_token = %s lookup is safe: it is not a computed/derivable value
	 * an attacker could brute-force or time an HMAC comparison against, it is
	 * the secret itself, delivered only to the subscriber's inbox.
	 */
	public function maybe_handle_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself is the authenticator, see class docblock.
		if ( empty( $_GET['perdita_subscribe_confirm'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['perdita_subscribe_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// An empty token must never reach the lookup. The column defaults to
		// '' and every already-confirmed row is left holding '' (see the
		// update below), so WHERE confirm_token = '' would match a real
		// subscriber and hand a stranger somebody else's confirmation.
		if ( '' === $token ) {
			wp_die(
				esc_html__( 'This confirmation link is invalid or has already been used.', 'perdita-core' ),
				esc_html__( 'Subscription', 'perdita-core' ),
				array( 'response' => 404 )
			);
		}

		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE confirm_token = %s", $token ) ); // phpcs:ignore WordPress.DB

		if ( ! $row ) {
			wp_die(
				esc_html__( 'This confirmation link is invalid or has already been used.', 'perdita-core' ),
				esc_html__( 'Subscription', 'perdita-core' ),
				array( 'response' => 404 )
			);
		}

		$unsub_token = ! empty( $row->unsubscribe_token ) ? $row->unsubscribe_token : $this->generate_token();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'status'            => 'confirmed',
				'confirmed_at'      => current_time( 'mysql' ),
				'unsubscribe_token' => $unsub_token,
				// Spend the confirmation token. It used to survive the
				// confirmation forever, so a link sitting in an inbox (or a
				// mail archive, or a proxy log) stayed replayable for the
				// life of the subscription and could flip an unsubscribed
				// address back to confirmed.
				'confirm_token'     => '',
			),
			array( 'id' => $row->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$home = esc_url( home_url( '/' ) );
		wp_die(
			sprintf(
				/* translators: %s: link back to the site homepage. */
				esc_html__( "You're subscribed. You'll get an email whenever a new post goes up. %s", 'perdita-core' ),
				'<a href="' . esc_url( $home ) . '">' . esc_html__( 'Return to the site', 'perdita-core' ) . '</a>'
			),
			esc_html__( 'Subscription confirmed', 'perdita-core' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Look up a subscriber by unsubscribe token.
	 *
	 * The token is 32 random bytes (64 hex chars) delivered only inside mail
	 * sent to that address, so a direct equality lookup is safe: it is the
	 * secret itself, not a derivable value. An empty token is rejected
	 * before the query, since the column defaults to '' and a blank lookup
	 * would otherwise match a real row.
	 *
	 * @param string $token Unsubscribe token.
	 * @return object|null Row, or null when nothing matches.
	 */
	private function subscriber_by_unsubscribe_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE unsubscribe_token = %s", $token ) ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Mark a subscriber row unsubscribed. Idempotent.
	 *
	 * @param object $row Subscriber row.
	 */
	private function mark_unsubscribed( $row ) {
		if ( 'unsubscribed' === $row->status ) {
			return;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => 'unsubscribed' ),
			array( 'id' => $row->id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Handle ?perdita_unsubscribe=TOKEN.
	 *
	 * Deliberately requires NO login and NO nonce: the unsubscribe token is
	 * itself the authenticator, and it only ever exists inside mail sent to
	 * that address. It does, however, require a POST. A bare GET that
	 * changes state gets triggered by everything that touches a link without
	 * a human deciding to: mail-security link scanners, inbox previews,
	 * prefetchers, corporate proxies. Those quietly unsubscribed people who
	 * never clicked anything. So GET renders a small confirmation page whose
	 * only control is a POST form carrying the token, and the POST does the
	 * work. Machine-driven one-click unsubscribe keeps working through the
	 * RFC 8058 endpoint in routes(), which is POST-only for the same reason.
	 *
	 * Idempotent: an already-unsubscribed token still shows the same
	 * confirmation instead of an error.
	 */
	public function maybe_handle_unsubscribe() {
		// phpcs:disable WordPress.Security.NonceVerification -- token-authenticated unsubscribe, see docblock.
		$posted = isset( $_POST['perdita_unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['perdita_unsubscribe'] ) ) : '';
		$asked  = isset( $_GET['perdita_unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_GET['perdita_unsubscribe'] ) ) : '';
		// phpcs:enable

		$token = '' !== $posted ? $posted : $asked;
		if ( '' === $token ) {
			return;
		}

		$row = $this->subscriber_by_unsubscribe_token( $token );
		if ( ! $row ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid.', 'perdita-core' ),
				esc_html__( 'Subscription', 'perdita-core' ),
				array( 'response' => 404 )
			);
		}

		if ( '' === $posted ) {
			$this->render_unsubscribe_confirmation( $token );
			return;
		}

		$this->mark_unsubscribed( $row );

		wp_die(
			esc_html__( "You're unsubscribed. You won't get any more emails from this list.", 'perdita-core' ),
			esc_html__( 'Unsubscribed', 'perdita-core' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * The GET half of the unsubscribe flow: confirm, then POST.
	 *
	 * @param string $token Validated unsubscribe token.
	 */
	private function render_unsubscribe_confirmation( $token ) {
		nocache_headers();

		$form = '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
			. '<input type="hidden" name="perdita_unsubscribe" value="' . esc_attr( $token ) . '" />'
			. '<p><button type="submit">' . esc_html__( 'Yes, unsubscribe me', 'perdita-core' ) . '</button></p>'
			. '</form>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'No, keep my subscription', 'perdita-core' ) . '</a></p>';

		wp_die(
			'<p>' . esc_html__( 'Confirm that you want to stop receiving emails from this list.', 'perdita-core' ) . '</p>' . $form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts just above.
			esc_html__( 'Unsubscribe', 'perdita-core' ),
			array( 'response' => 200 )
		);
	}

	/* ---------------------------------------------------------------------
	 * New-post notification
	 * ------------------------------------------------------------------- */

	/**
	 * Hook transition_post_status: when a 'post' newly transitions to
	 * 'publish', queue it and schedule the batch cron rather than sending
	 * synchronously (which could be slow with many subscribers and would
	 * block the publish request).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$queue = get_option( 'perdita_subscriptions_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		if ( ! in_array( $post->ID, $queue, true ) ) {
			$queue[] = $post->ID;
			update_option( 'perdita_subscriptions_queue', $queue, false );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Process one batch: work through the queued posts, sending up to
	 * BATCH_SIZE emails total to confirmed subscribers who have not yet
	 * received this post. Reschedules itself if anything remains.
	 *
	 * DOUBLE-PROCESSING NOTE. If wp-cron fires this event twice in a race,
	 * two runs could both read "not yet sent" for the same subscriber before
	 * either records the send, and that subscriber gets the email twice.
	 * This class accepts that small at-least-once risk rather than adding
	 * row-level locking, because for a low-volume notification email an
	 * occasional duplicate is far cheaper than the complexity of a lock, and
	 * marking sent happens immediately after each individual send (not
	 * batched at the end), which already closes the window to just the
	 * single overlapping request.
	 */
	public function process_batch() {
		$queue = get_option( 'perdita_subscriptions_queue', array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}

		$sent_this_run = 0;
		$remaining_queue = array();

		foreach ( $queue as $post_id ) {
			if ( $sent_this_run >= self::BATCH_SIZE ) {
				$remaining_queue[] = $post_id;
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				// Unpublished again before we got to it, or deleted. Drop it.
				continue;
			}

			$sent_meta = get_post_meta( $post_id, '_perdita_subscriptions_sent', true );
			$sent_ids  = is_array( $sent_meta ) ? $sent_meta : array();

			$budget = self::BATCH_SIZE - $sent_this_run;
			$batch  = $this->confirmed_subscribers_excluding( $sent_ids, $budget );

			foreach ( $batch as $subscriber ) {
				$this->send_post_notification( $subscriber, $post );
				$sent_ids[] = (int) $subscriber->id;
				$sent_this_run++;
			}
			update_post_meta( $post_id, '_perdita_subscriptions_sent', $sent_ids );

			// Are there more confirmed subscribers this post hasn't reached yet?
			$more = $this->confirmed_subscribers_excluding( $sent_ids, 1 );
			if ( $more ) {
				$remaining_queue[] = $post_id;
			}
		}

		update_option( 'perdita_subscriptions_queue', array_values( array_unique( $remaining_queue ) ), false );

		if ( $remaining_queue ) {
			wp_schedule_single_event( time() + self::BATCH_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Confirmed, non-unsubscribed subscribers not already in the given id list.
	 *
	 * @param int[] $exclude_ids Subscriber ids to skip.
	 * @param int   $limit       Max rows.
	 * @return array
	 */
	private function confirmed_subscribers_excluding( array $exclude_ids, $limit ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 0, (int) $limit );
		if ( 0 === $limit ) {
			return array();
		}

		if ( $exclude_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$sql          = "SELECT * FROM $table WHERE status = 'confirmed' AND id NOT IN ($placeholders) ORDER BY id ASC LIMIT %d";
			$args         = array_merge( $exclude_ids, array( $limit ) );
			return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
		}

		$sql = "SELECT * FROM $table WHERE status = 'confirmed' ORDER BY id ASC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Email one subscriber about one post.
	 *
	 * @param object  $subscriber Row from the subscribers table.
	 * @param WP_Post $post       The published post.
	 */
	private function send_post_notification( $subscriber, $post ) {
		$settings = $this->get_settings();

		$unsubscribe_url = add_query_arg( 'perdita_unsubscribe', $subscriber->unsubscribe_token, home_url( '/' ) );
		$excerpt         = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );

		$tokens = array(
			'post_title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			'post_excerpt'    => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES ),
			'post_url'        => get_permalink( $post ),
			'unsubscribe_url' => $unsubscribe_url,
			'site_name'       => get_bloginfo( 'name' ),
		);

		$subject = $this->replace_tokens( $settings['notify_subject'], $tokens );
		$body    = $this->replace_tokens( $settings['notify_body'], $tokens );

		// The unsubscribe link must be in every notification email. If the
		// configured template somehow dropped the placeholder, append it so
		// the promise holds regardless of what an admin typed into settings.
		if ( false === strpos( $settings['notify_body'], '{unsubscribe_url}' ) ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: unsubscribe URL. */
				__( 'Unsubscribe: %s', 'perdita-core' ),
				$unsubscribe_url
			);
		}

		// RFC 8058. The List-Unsubscribe-Post header tells the mail client
		// that the https URI accepts an unattended POST, which is what turns
		// on the "Unsubscribe" button Gmail, Outlook, and Apple Mail put at
		// the top of the message. Gmail and Yahoo both require one-click
		// unsubscribe on bulk mail, and offering it is also the cheapest way
		// to keep an annoyed reader from reporting the mail as spam instead.
		// The URI is POST-only, so a link scanner cannot trip it.
		$headers = array(
			'List-Unsubscribe: <' . self::one_click_unsubscribe_url( $subscriber->unsubscribe_token ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);

		$this->mail( $subscriber->email, $subject, $body, $headers );
	}

	/* ---------------------------------------------------------------------
	 * Counts (admin screen)
	 * ------------------------------------------------------------------- */

	/**
	 * Subscriber counts by status.
	 *
	 * @return array { confirmed: int, pending: int, unsubscribed: int }
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array(
			'confirmed'    => 0,
			'pending'      => 0,
			'unsubscribed' => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $out;
	}
}
