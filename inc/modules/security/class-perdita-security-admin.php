<?php
/**
 * Security hardening admin: a settings screen under the Perdita menu with a
 * checkbox per protection, the login-limit numbers, the never-lock-out IP
 * allowlist, and the CSP mode plus editable policy string.
 *
 * Saving goes through admin-post.php and is guarded by a capability check and a
 * nonce. Every value is sanitized on write and escaped on output.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Security_Admin {

	/**
	 * The admin-post action for saving.
	 */
	const SAVE_ACTION = 'perdita_security_save';

	/**
	 * The settings-page slug.
	 */
	const PAGE = 'perdita-security';

	/**
	 * The security module core.
	 *
	 * @var Perdita_Security
	 */
	private $main;

	/**
	 * The Perdita core.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Security $main The module core.
	 * @param Perdita          $core The Perdita core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
	}

	/**
	 * Register the Security submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Security', 'perdita-core' ),
			__( 'Security', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ---------- render ---------- */

	/**
	 * Render the settings screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Perdita_Security::get_settings();

		echo '<style>.perdita-recommended-flag{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#edfaef;color:#005c12;}.perdita-advanced-flag{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#f0f0f1;color:#50575e;}</style>';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Security hardening', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Each protection is off until you turn it on, so nothing changes on your site until you choose it. The two that can lock people out or break a page have safety notes below.', 'perdita-core' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice, no state change.
		if ( isset( $_GET['perdita_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );

		$this->section_login( $s );
		$this->section_headers( $s );
		$this->section_toggles( $s );

		submit_button( __( 'Save security settings', 'perdita-core' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Login rate-limiting section.
	 *
	 * @param array $s Settings.
	 */
	private function section_login( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Login rate limiting', 'perdita-core' ) . '</h2>';
		echo '<p class="description" style="max-width:760px;">' . esc_html__( 'Safety note: this blocks a client IP after too many failed logins, and it always self-expires. Add your own IP to the allowlist below so you can never lock yourself out. Behind a proxy or CDN, wire the real client IP through the perdita_security_client_ip filter, since the default only reads REMOTE_ADDR.', 'perdita-core' ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="login_limit" value="1" ' . checked( ! empty( $s['login_limit'] ), true, false ) . ' /> ' . esc_html__( 'Limit failed login attempts', 'perdita-core' ) . '</label> <span class="perdita-recommended-flag">' . esc_html__( '(Recommended)', 'perdita-core' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-login-max">' . esc_html__( 'Allowed failures', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="100" id="ps-login-max" name="login_max" value="' . esc_attr( (int) $s['login_max'] ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'failed attempts before a lockout starts', 'perdita-core' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-login-window">' . esc_html__( 'Failure window', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="1440" id="ps-login-window" name="login_window" value="' . esc_attr( (int) $s['login_window'] ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'minutes to remember failed attempts', 'perdita-core' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-login-lockout">' . esc_html__( 'Lockout length', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="1440" id="ps-login-lockout" name="login_lockout" value="' . esc_attr( (int) $s['login_lockout'] ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'minutes to block further attempts', 'perdita-core' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-allowlist">' . esc_html__( 'IP allowlist', 'perdita-core' ) . '</label></th><td>';
		$list = isset( $s['ip_allowlist'] ) ? (array) $s['ip_allowlist'] : array();
		echo '<textarea id="ps-allowlist" name="ip_allowlist" rows="4" class="large-text code" placeholder="203.0.113.10">' . esc_textarea( implode( "\n", $list ) ) . '</textarea>';
		// Same client_ip() the rate limiter itself checks against (including any
		// site-specific perdita_security_client_ip filter), so the button always
		// offers the address that would actually matter, not a guess. The IP
		// only ever lives in an escaped data-* attribute, read via the DOM, so
		// it's never concatenated into inline JS source.
		$my_ip = $this->main->client_ip();
		if ( '' !== $my_ip ) {
			echo '<p><button type="button" class="button" id="perdita-add-my-ip" data-ip="' . esc_attr( $my_ip ) . '">' . esc_html__( 'Add my current IP address', 'perdita-core' ) . '</button></p>';
			echo '<script>document.getElementById("perdita-add-my-ip").addEventListener("click",function(){var t=document.getElementById("ps-allowlist"),ip=this.getAttribute("data-ip"),v=t.value.trim();t.value=v?v+"\n"+ip:ip;});</script>';
		}
		echo '<p class="description">' . esc_html__( 'One IP address per line. These are never locked out. Add your own IP so you keep access.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * Security-headers section.
	 *
	 * @param array $s Settings.
	 */
	private function section_headers( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Security headers', 'perdita-core' ) . ' <span class="perdita-advanced-flag">' . esc_html__( '(Advanced)', 'perdita-core' ) . '</span></h2>';
		echo '<p class="description" style="max-width:760px;">' . esc_html__( 'Safety note: the Content-Security-Policy can break a site if it is too strict, so it starts in Report-Only. Report-Only logs violations in the browser console without blocking anything. Only switch to Enforce once the console is clean.', 'perdita-core' ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="headers" value="1" ' . checked( ! empty( $s['headers'] ), true, false ) . ' /> ' . esc_html__( 'Send security response headers on the front end', 'perdita-core' ) . '</label> <span class="perdita-recommended-flag">' . esc_html__( '(Recommended)', 'perdita-core' ) . '</span>';
		echo '<ul style="list-style:disc;margin:8px 0 4px 20px;color:#3c434a;">';
		echo '<li>' . esc_html__( 'X-Frame-Options: stops your pages from being loaded inside an iframe on someone else\'s site, which blocks clickjacking attacks that trick a visitor into clicking something they cannot actually see.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'X-Content-Type-Options: stops the browser from guessing a file\'s type, which closes off a class of attack where a file disguised as an image or text file gets executed as script instead.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Referrer-Policy: limits how much of your URL gets sent as the "referrer" when a visitor clicks a link to another site, so page paths and query strings do not leak to third parties.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Permissions-Policy: turns off browser features (like camera, microphone, and geolocation) that this site does not use, so a compromised script cannot invoke them.', 'perdita-core' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'These four are safe defaults for nearly every site. The Content-Security-Policy below is the one setting here that needs real testing before you enforce it, which is why it is marked Advanced.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		$mode  = isset( $s['csp_mode'] ) ? $s['csp_mode'] : 'report-only';
		$modes = array(
			'off'         => __( 'Off (do not send a CSP)', 'perdita-core' ),
			'report-only' => __( 'Report-Only (recommended, never blocks)', 'perdita-core' ),
			'enforce'     => __( 'Enforce (blocks anything not allowed)', 'perdita-core' ),
		);
		echo '<tr><th scope="row"><label for="ps-csp-mode">' . esc_html__( 'Content-Security-Policy mode', 'perdita-core' ) . '</label></th><td>';
		echo '<select id="ps-csp-mode" name="csp_mode">';
		foreach ( $modes as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $mode, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Report-Only is the default while the headers feature is on. It will not enforce until you choose Enforce.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-csp">' . esc_html__( 'Content-Security-Policy', 'perdita-core' ) . '</label></th><td>';
		$csp = isset( $s['csp'] ) ? (string) $s['csp'] : '';
		echo '<textarea id="ps-csp" name="csp" rows="4" class="large-text code">' . esc_textarea( $csp ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The policy string. The default is permissive enough for most sites. Tighten it once you have tested in Report-Only.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * The remaining on/off protections.
	 *
	 * @param array $s Settings.
	 */
	private function section_toggles( $s ) {
		echo '<hr /><h2>' . esc_html__( 'More protections', 'perdita-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'File editor', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="disable_file_edit" value="1" ' . checked( ! empty( $s['disable_file_edit'] ), true, false ) . ' /> ' . esc_html__( 'Disable the dashboard file editor', 'perdita-core' ) . '</label> <span class="perdita-recommended-flag">' . esc_html__( '(Recommended)', 'perdita-core' ) . '</span>';
		echo '<p class="description">' . esc_html__( 'Hides and blocks Appearance > Theme File Editor and Plugins > Plugin File Editor, so a stolen admin login cannot edit theme or plugin code.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'XML-RPC', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="disable_xmlrpc" value="1" ' . checked( ! empty( $s['disable_xmlrpc'] ), true, false ) . ' /> ' . esc_html__( 'Turn off XML-RPC and remove pingback methods', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Leave this off if you use the Jetpack or WordPress mobile apps, which rely on XML-RPC.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Author enumeration', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="block_author_enum" value="1" ' . checked( ! empty( $s['block_author_enum'] ), true, false ) . ' /> ' . esc_html__( 'Block author scans and hide the users REST endpoint from logged-out visitors', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Stops ?author=1 probes and stops /wp-json/wp/v2/users from listing usernames to anyone who is not logged in.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	/* ---------- save ---------- */

	/**
	 * Persist the settings. Guarded by capability and nonce, sanitized field by
	 * field before writing.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		$out = Perdita_Security::defaults();

		$out['login_limit']       = ! empty( $_POST['login_limit'] );
		$out['headers']           = ! empty( $_POST['headers'] );
		$out['disable_file_edit'] = ! empty( $_POST['disable_file_edit'] );
		$out['disable_xmlrpc']    = ! empty( $_POST['disable_xmlrpc'] );
		$out['block_author_enum'] = ! empty( $_POST['block_author_enum'] );

		$out['login_max']     = isset( $_POST['login_max'] ) ? max( 1, min( 100, (int) $_POST['login_max'] ) ) : 5;
		$out['login_window']  = isset( $_POST['login_window'] ) ? max( 1, min( 1440, (int) $_POST['login_window'] ) ) : 15;
		$out['login_lockout'] = isset( $_POST['login_lockout'] ) ? max( 1, min( 1440, (int) $_POST['login_lockout'] ) ) : 15;

		$out['ip_allowlist'] = $this->parse_allowlist( isset( $_POST['ip_allowlist'] ) ? wp_unslash( $_POST['ip_allowlist'] ) : '' ); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- validated per-line below.

		$mode                = isset( $_POST['csp_mode'] ) ? sanitize_key( wp_unslash( $_POST['csp_mode'] ) ) : 'report-only';
		$out['csp_mode']     = in_array( $mode, array( 'off', 'report-only', 'enforce' ), true ) ? $mode : 'report-only';
		$out['csp']          = isset( $_POST['csp'] ) ? $this->sanitize_csp( wp_unslash( $_POST['csp'] ) ) : Perdita_Security::default_csp(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in sanitize_csp().

		update_option( Perdita_Security::OPTION, $out );

		wp_safe_redirect(
			add_query_arg(
				'perdita_msg',
				rawurlencode( __( 'Security settings saved.', 'perdita-core' ) ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}

	/**
	 * Parse the allowlist textarea into a clean list of valid IPs, one per line.
	 * Anything that is not a valid IPv4/IPv6 address is dropped.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array
	 */
	private function parse_allowlist( $raw ) {
		$lines = preg_split( '/[\r\n]+/', (string) $raw );
		$ips   = array();
		foreach ( (array) $lines as $line ) {
			$ip = trim( sanitize_text_field( $line ) );
			if ( '' === $ip ) {
				continue;
			}
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $ips, true ) ) {
				$ips[] = $ip;
			}
		}
		return $ips;
	}

	/**
	 * Sanitize the CSP string. A policy is a single header line, so newlines and
	 * control characters are stripped and the header-splitting characters are
	 * removed. An empty result falls back to the shipped default.
	 *
	 * @param string $raw Raw policy.
	 * @return string
	 */
	private function sanitize_csp( $raw ) {
		$csp = (string) $raw;
		$csp = str_replace( array( "\r", "\n", "\t" ), ' ', $csp );
		$csp = preg_replace( '/[^\P{C}]+/u', '', $csp ); // Strip any remaining control chars.
		$csp = str_replace( array( '<', '>' ), '', $csp );
		$csp = trim( preg_replace( '/\s+/', ' ', (string) $csp ) );
		return '' !== $csp ? $csp : Perdita_Security::default_csp();
	}
}
