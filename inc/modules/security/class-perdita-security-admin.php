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
	 * The admin-post action for the Check now button.
	 */
	const CHECK_ACTION = 'perdita_security_core_check_now';

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
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'check_now' ) );
	}

	/**
	 * The screen's layout sheet. Controls and badges come from the shared
	 * admin sheet.
	 */
	public function assets() {
		if ( class_exists( 'Perdita_Core_Admin_UI' ) ) {
			Perdita_Core_Admin_UI::enqueue_assets();
		}
		wp_enqueue_style( 'perdita-security-admin', PERDITA_CORE_URL . 'assets/css/perdita-security-admin.css', array(), PERDITA_CORE_VERSION );
	}

	/**
	 * Register the Security submenu under the Perdita menu.
	 */
	public function menu() {
		$hook = add_submenu_page(
			'perdita',
			__( 'Security', 'perdita-core' ),
			__( 'Security', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'assets' ) );
		}
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

		echo '<div class="wrap perdita-security">';
		echo '<h1>' . esc_html__( 'Security hardening', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Each protection is off until you turn it on, so nothing changes on your site until you choose it. The two that can lock people out or break a page have safety notes below.', 'perdita-core' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice, no state change.
		if ( '' !== perdita_notice_text( 'perdita_msg' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_msg' ) ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice, no state change.
		if ( '' !== perdita_notice_text( 'perdita_err' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_err' ) ) . '</p></div>';
		}

		$this->status_panel( $s );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );

		// The firewall screen draws its own section. It runs whether or not
		// that file is present.
		if ( class_exists( 'Perdita_Security_Firewall_Admin' ) && method_exists( 'Perdita_Security_Firewall_Admin', 'render_section' ) ) {
			Perdita_Security_Firewall_Admin::render_section( $s );
		}
		$this->section_login( $s );
		$this->section_login_extras( $s );
		$this->section_headers( $s );
		$this->section_toggles( $s );
		$this->section_hardening( $s );
		$this->section_core_check( $s );

		submit_button( __( 'Save security settings', 'perdita-core' ) );
		echo '</form>';
		$this->core_check_form();
		echo '</div>';
	}

	/**
	 * Login rate-limiting section.
	 *
	 * @param array $s Settings.
	 */
	private function section_login( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Login rate limiting', 'perdita-core' ) . '</h2>';
		echo '<p class="description perdita-security-note">' . esc_html__( 'Safety note: with this on, you lock out an IP address after too many failed logins, and every lockout expires. Add your own IP address to the allowlist below so you never lock yourself out. Behind Cloudflare, you get each visitor\'s own address automatically. Behind another proxy or CDN, pass the visitor\'s address through the perdita_security_client_ip filter, since you get REMOTE_ADDR otherwise.', 'perdita-core' ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="login_limit" value="1" ' . checked( ! empty( $s['login_limit'] ), true, false ) . ' /> ' . esc_html__( 'Limit failed login attempts', 'perdita-core' ) . '</label> <span class="perdita-badge is-ok">' . esc_html__( 'Recommended', 'perdita-core' ) . '</span>';
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
		echo '<p class="description">' . esc_html__( 'One entry per line: an IP address or a range such as 198.51.100.0/24 or 2001:db8::/32 (IPv4 and IPv6). Perdita never locks these out. Add your own IP so you keep access.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * Security-headers section.
	 *
	 * @param array $s Settings.
	 */
	private function section_headers( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Security headers', 'perdita-core' ) . ' <span class="perdita-badge">' . esc_html__( 'Advanced', 'perdita-core' ) . '</span></h2>';
		echo '<p class="description perdita-security-note">' . esc_html__( 'Safety note: with a Content-Security-Policy that\'s too strict, parts of your site stop working, so you start in Report-Only. In Report-Only, you see each violation in the browser console and block nothing yet. Switch to Enforce once the console is clean.', 'perdita-core' ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="headers" value="1" ' . checked( ! empty( $s['headers'] ), true, false ) . ' /> ' . esc_html__( 'Send security response headers on the front end', 'perdita-core' ) . '</label> <span class="perdita-badge is-ok">' . esc_html__( 'Recommended', 'perdita-core' ) . '</span>';
		echo '<ul class="perdita-security-list">';
		echo '<li>' . esc_html__( 'X-Frame-Options: stops your pages from being loaded inside an iframe on someone else\'s site, which blocks clickjacking attacks that trick a visitor into clicking something they cannot actually see.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'X-Content-Type-Options: stops the browser from guessing a file\'s type, which closes off a class of attack where a file disguised as an image or text file gets executed as script instead.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Referrer-Policy: limits how much of your URL gets sent as the "referrer" when a visitor clicks a link to another site, so page paths and query strings do not leak to third parties.', 'perdita-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Permissions-Policy: turns off browser features (like camera, microphone, and geolocation) that this site does not use, so a compromised script cannot invoke them.', 'perdita-core' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'These four are safe defaults for nearly every site. The Content-Security-Policy below is the one setting here that needs real testing before you enforce it, which is why it is marked Advanced.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'HSTS', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="hsts" value="1" ' . checked( ! empty( $s['hsts'] ), true, false ) . ' /> ' . esc_html__( 'Tell browsers to use HTTPS only (Strict-Transport-Security)', 'perdita-core' ) . '</label>';
		echo '<p>' . esc_html__( 'Remember for', 'perdita-core' ) . ' <input type="number" min="0" max="63072000" name="hsts_max_age" value="' . esc_attr( (int) $s['hsts_max_age'] ) . '" class="perdita-security-seconds" /> ' . esc_html__( 'seconds (31536000 is one year)', 'perdita-core' ) . '</p>';
		echo '<p><label><input type="checkbox" name="hsts_subdomains" value="1" ' . checked( ! empty( $s['hsts_subdomains'] ), true, false ) . ' /> ' . esc_html__( 'Include every subdomain', 'perdita-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Turn this on only when your whole site, and every subdomain if you tick the box, loads over HTTPS. Browsers keep the rule for the time you set, so a mistake takes that long to undo. The header goes out over HTTPS only.', 'perdita-core' ) . '</p>';
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
		echo '<label><input type="checkbox" name="disable_file_edit" value="1" ' . checked( ! empty( $s['disable_file_edit'] ), true, false ) . ' /> ' . esc_html__( 'Disable the dashboard file editor', 'perdita-core' ) . '</label> <span class="perdita-badge is-ok">' . esc_html__( 'Recommended', 'perdita-core' ) . '</span>';
		echo '<p class="description">' . esc_html__( 'Hides and blocks Appearance > Theme File Editor and Plugins > Plugin File Editor, so a stolen admin login cannot edit theme or plugin code.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'XML-RPC', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="disable_xmlrpc" value="1" ' . checked( ! empty( $s['disable_xmlrpc'] ), true, false ) . ' /> ' . esc_html__( 'Turn off XML-RPC and remove pingback methods', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Leave this off if you use the Jetpack or WordPress mobile apps, which rely on XML-RPC.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Author enumeration', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="block_author_enum" value="1" ' . checked( ! empty( $s['block_author_enum'] ), true, false ) . ' /> ' . esc_html__( 'Block author scans', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Sends ?author=1 probes to the home page for logged-out visitors. To hide the REST users list too, see Hardening.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}


	/* ---------- status panel ---------- */

	/**
	 * The rows of the status panel: label, whether it is on, and the next
	 * step. The firewall row appears only when the firewall code is loaded.
	 *
	 * @param array $s Settings.
	 * @return array[] Each row: label, on (bool), note.
	 */
	public function status_rows( $s ) {
		$rows = array();
		$on   = static function ( $key ) use ( $s ) {
			return ! empty( $s[ $key ] );
		};

		if ( class_exists( 'Perdita_Firewall_Config' ) ) {
			$mode   = isset( $s['firewall_mode'] ) && 'block' === $s['firewall_mode'];
			$rows[] = array(
				'label' => __( 'Firewall', 'perdita-core' ),
				'on'    => $on( 'firewall' ),
				'note'  => $on( 'firewall' )
					? ( $mode ? __( 'Blocking bad requests.', 'perdita-core' ) : __( 'Monitoring only. Check the log, then switch to Block.', 'perdita-core' ) )
					: __( 'Turn it on in the Firewall section below. Start in monitor mode.', 'perdita-core' ),
			);
		}

		$rows[] = array(
			'label' => __( 'Login limit', 'perdita-core' ),
			'on'    => $on( 'login_limit' ),
			'note'  => $on( 'login_limit' ) ? __( 'Locking out an IP after too many failures.', 'perdita-core' ) : __( 'Turn it on, and add your own IP to the allowlist first.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'Longer lockouts', 'perdita-core' ),
			'on'    => $on( 'login_long_lockout' ),
			'note'  => $on( 'login_long_lockout' )
				? ( $on( 'login_limit' ) ? __( 'Repeat offenders wait longer.', 'perdita-core' ) : __( 'Needs the login limit on.', 'perdita-core' ) )
				: __( 'Turn it on so repeat offenders wait longer.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'One login error message', 'perdita-core' ),
			'on'    => $on( 'login_generic_errors' ),
			'note'  => $on( 'login_generic_errors' ) ? __( 'Visitors cannot tell a real username from a wrong one.', 'perdita-core' ) : __( 'Turn it on so a wrong username and a wrong password look the same.', 'perdita-core' ),
		);

		$turnstile_ready = null !== Perdita_Security::turnstile_keys();
		if ( $on( 'login_turnstile' ) ) {
			$note = $turnstile_ready ? __( 'Challenging the login, registration, and lost password forms.', 'perdita-core' ) : __( 'On, but waiting for your Turnstile keys. Add them under Forms, Spam Protection.', 'perdita-core' );
		} else {
			$note = $turnstile_ready ? __( 'Your Turnstile keys are in place. Turn it on.', 'perdita-core' ) : __( 'Add Turnstile keys under Forms, Spam Protection first.', 'perdita-core' );
		}
		$rows[] = array(
			'label' => __( 'Turnstile on login forms', 'perdita-core' ),
			'on'    => $on( 'login_turnstile' ) && $turnstile_ready,
			'note'  => $note,
		);

		// A challenge can't run over XML-RPC, so a password guess there skips it.
		if ( $on( 'login_turnstile' ) && ! $on( 'disable_xmlrpc' ) ) {
			$rows[] = array(
				'label' => __( 'Turnstile and XML-RPC', 'perdita-core' ),
				'on'    => false,
				'note'  => __( 'Warning: visitors can guess passwords over XML-RPC with no Turnstile check. Turn on "XML-RPC off" below unless you use Jetpack or the WordPress apps.', 'perdita-core' ),
			);
		}

		$rows[] = array(
			'label' => __( 'Security headers', 'perdita-core' ),
			'on'    => $on( 'headers' ),
			'note'  => $on( 'headers' ) ? __( 'Sending four headers on the front end.', 'perdita-core' ) : __( 'Turn it on. The Content-Security-Policy starts in Report-Only.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'HSTS', 'perdita-core' ),
			'on'    => $on( 'hsts' ),
			'note'  => $on( 'hsts' ) ? ( is_ssl() ? __( 'Telling browsers to use HTTPS only.', 'perdita-core' ) : __( 'On, but you opened this screen over HTTP. The header goes out only over HTTPS.', 'perdita-core' ) ) : __( 'Turn on HSTS once your whole site loads over HTTPS.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'File editor off', 'perdita-core' ),
			'on'    => $on( 'disable_file_edit' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			'note'  => ( $on( 'disable_file_edit' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) ? __( 'The dashboard cannot edit theme or plugin code.', 'perdita-core' ) : __( 'Turn it on.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'XML-RPC off', 'perdita-core' ),
			'on'    => $on( 'disable_xmlrpc' ),
			'note'  => $on( 'disable_xmlrpc' ) ? __( 'XML-RPC and pingbacks are closed.', 'perdita-core' ) : __( 'Turn it on unless you use Jetpack or the WordPress apps.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'Author scans blocked', 'perdita-core' ),
			'on'    => $on( 'block_author_enum' ),
			'note'  => $on( 'block_author_enum' ) ? __( '?author=1 probes go to the home page.', 'perdita-core' ) : __( 'Turn it on.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'REST users list hidden', 'perdita-core' ),
			'on'    => isset( $s['restrict_rest'] ) && 'users' === $s['restrict_rest'],
			'note'  => ( isset( $s['restrict_rest'] ) && 'users' === $s['restrict_rest'] ) ? __( 'Logged-out visitors cannot list usernames.', 'perdita-core' ) : __( 'Choose "Hide the users list" under Hardening.', 'perdita-core' ),
		);
		$same = $this->admins_showing_login();
		if ( $same ) {
			$rows[] = array(
				'label' => __( 'Administrator display names', 'perdita-core' ),
				'on'    => false,
				'note'  => sprintf(
					/* translators: %s: comma-separated login names. */
					__( 'Visitors can read the display name in feeds and on author pages, and for %s it matches the login name. Under Users, Profile, set "Display name publicly as" to something else.', 'perdita-core' ),
					implode( ', ', $same )
				),
			);
		}
		$rows[] = array(
			'label' => __( 'WordPress version hidden', 'perdita-core' ),
			'on'    => $on( 'hide_wp_version' ),
			'note'  => $on( 'hide_wp_version' ) ? __( 'The generator tag and core version numbers are gone.', 'perdita-core' ) : __( 'Turn it on under Hardening.', 'perdita-core' ),
		);
		$block_ok = $on( 'block_uploads_php' ) && Perdita_Security::uploads_block_present();
		$rows[]   = array(
			'label' => __( 'PHP blocked in uploads', 'perdita-core' ),
			'on'    => $block_ok,
			'note'  => $block_ok
				? __( 'The rule is in your uploads .htaccess file. nginx needs its own rule.', 'perdita-core' )
				: ( $on( 'block_uploads_php' ) ? __( 'The rule is not in the file. Save again, or add the nginx rule below.', 'perdita-core' ) : __( 'Turn it on under Hardening.', 'perdita-core' ) ),
		);
		$rows[] = array(
			'label' => __( 'Application passwords off', 'perdita-core' ),
			'on'    => $on( 'disable_app_passwords' ),
			'note'  => $on( 'disable_app_passwords' ) ? __( 'No one can make an application password.', 'perdita-core' ) : __( 'Turn this on if no app or tool on your site uses them.', 'perdita-core' ),
		);
		$rows[] = array(
			'label' => __( 'Daily core file check', 'perdita-core' ),
			'on'    => $on( 'core_check' ),
			'note'  => $on( 'core_check' ) ? Perdita_Security::core_check_summary() : __( 'Turn it on, then press Check now.', 'perdita-core' ),
		);
		return $rows;
	}

	/**
	 * Login names of administrators whose public display name is the login
	 * name itself. Feeds print the display name, so the login leaks there.
	 *
	 * @return string[] Up to 5 login names.
	 */
	private function admins_showing_login() {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 100,
				'fields' => array( 'user_login', 'display_name' ),
			)
		);
		$names = array();
		foreach ( $users as $user ) {
			if ( '' !== $user->user_login && strtolower( $user->user_login ) === strtolower( $user->display_name ) ) {
				$names[] = $user->user_login;
			}
		}
		return array_slice( $names, 0, 5 );
	}

	/**
	 * The status panel at the top of the screen.
	 *
	 * @param array $s Settings.
	 */
	private function status_panel( $s ) {
		echo '<h2>' . esc_html__( 'Status', 'perdita-core' ) . '</h2>';
		echo '<table class="widefat striped perdita-security-status" role="presentation"><tbody>';
		foreach ( $this->status_rows( $s ) as $row ) {
			echo '<tr>';
			echo '<th scope="row">' . esc_html( $row['label'] ) . '</th>';
			echo '<td>' . ( $row['on'] ? '<span class="perdita-badge is-ok">' . esc_html__( 'On', 'perdita-core' ) . '</span>' : '<span class="perdita-badge">' . esc_html__( 'Off', 'perdita-core' ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( $row['note'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * More login protections: one message, longer lockouts, Turnstile.
	 *
	 * @param array $s Settings.
	 */
	private function section_login_extras( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Login protections', 'perdita-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'One error message', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="login_generic_errors" value="1" ' . checked( ! empty( $s['login_generic_errors'] ), true, false ) . ' /> ' . esc_html__( 'Show the same message for a wrong username, a wrong email, and a wrong password', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Visitors see "The username or password you entered is incorrect." The lockout notice and two-factor prompts keep their own messages, and XML-RPC and REST requests are left alone. Visitors also get the same lost password result for every username and email, and no email goes out for an unknown one.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Longer lockouts', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="login_long_lockout" value="1" ' . checked( ! empty( $s['login_long_lockout'] ), true, false ) . ' /> ' . esc_html__( 'Lock out repeat offenders for longer', 'perdita-core' ) . '</label>';
		echo '<p>' . esc_html__( 'After', 'perdita-core' ) . ' <input type="number" min="1" max="100" name="login_long_after" value="' . esc_attr( (int) $s['login_long_after'] ) . '" class="small-text" /> ' . esc_html__( 'lockouts inside 24 hours, the next one lasts', 'perdita-core' ) . ' <input type="number" min="1" max="720" name="login_long_hours" value="' . esc_attr( (int) $s['login_long_hours'] ) . '" class="small-text" /> ' . esc_html__( 'hours.', 'perdita-core' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Needs the login limit above. Every lockout still ends on its own, and Perdita never locks out an allowlisted IP.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		$ready = null !== Perdita_Security::turnstile_keys();
		echo '<tr><th scope="row">' . esc_html__( 'Turnstile', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="login_turnstile" value="1" ' . checked( ! empty( $s['login_turnstile'] ), true, false ) . ' /> ' . esc_html__( 'Add a Cloudflare Turnstile check to the login, registration, and lost password forms', 'perdita-core' ) . '</label>';
		if ( $ready ) {
			echo '<p class="description">' . esc_html__( 'Your Turnstile keys from Forms are in place. Perdita never challenges XML-RPC, REST, application password, or WP-CLI requests. If Cloudflare is down, visitors still get through.', 'perdita-core' ) . '</p>';
		} else {
			echo '<p class="description"><strong>' . esc_html__( 'This stays off until you add Turnstile keys.', 'perdita-core' ) . '</strong> ' . esc_html__( 'Paste the site key and secret key under Forms, Spam Protection. The login forms use those same keys.', 'perdita-core' ) . '</p>';
		}
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * Hardening section.
	 *
	 * @param array $s Settings.
	 */
	private function section_hardening( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Hardening', 'perdita-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'WordPress version', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="hide_wp_version" value="1" ' . checked( ! empty( $s['hide_wp_version'] ), true, false ) . ' /> ' . esc_html__( 'Hide the WordPress version number', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Removes the generator tag from your pages and feeds, and the version number from core script and style links. Your plugin and theme links keep their own version numbers.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'PHP in uploads', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="block_uploads_php" value="1" ' . checked( ! empty( $s['block_uploads_php'] ), true, false ) . ' /> ' . esc_html__( 'Block PHP files in the uploads folder', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'On Apache and LiteSpeed, Perdita adds a marked block to the .htaccess file in your uploads folder when you save. Unticking this box, turning the module off, or deactivating Perdita removes the block, and no other line in the file changes.', 'perdita-core' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'On nginx, a .htaccess file does nothing. Add this rule to your server config instead:', 'perdita-core' ) . '</p>';
		echo '<p><code>' . esc_html( Perdita_Security::uploads_nginx_rule() ) . '</code></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Application passwords', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="disable_app_passwords" value="1" ' . checked( ! empty( $s['disable_app_passwords'] ), true, false ) . ' /> ' . esc_html__( 'Turn off application passwords', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Leave this off if an app, a deploy tool, or the WordPress mobile apps sign in to your site with one.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		$rest = isset( $s['restrict_rest'] ) ? $s['restrict_rest'] : 'off';
		echo '<tr><th scope="row"><label for="ps-restrict-rest">' . esc_html__( 'REST API', 'perdita-core' ) . '</label></th><td>';
		echo '<select id="ps-restrict-rest" name="restrict_rest">';
		echo '<option value="off" ' . selected( $rest, 'off', false ) . '>' . esc_html__( 'Leave the REST API unchanged', 'perdita-core' ) . '</option>';
		echo '<option value="users" ' . selected( $rest, 'users', false ) . '>' . esc_html__( 'Hide the users list from logged-out visitors', 'perdita-core' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The users option stops /wp-json/wp/v2/users from listing usernames. Logged-in users keep the users list, and so does the block editor.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * Core file check section.
	 *
	 * @param array $s Settings.
	 */
	private function section_core_check( $s ) {
		echo '<hr /><h2>' . esc_html__( 'Core file check', 'perdita-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Daily check', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="core_check" value="1" ' . checked( ! empty( $s['core_check'] ), true, false ) . ' /> ' . esc_html__( 'Compare WordPress core files with wordpress.org every day', 'perdita-core' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Finds core files that changed or went missing, and PHP files in wp-admin and wp-includes that WordPress does not ship. Perdita skips the wp-content folder.', 'perdita-core' ) . '</p>';
		echo '<p id="ps-core-status">' . esc_html( Perdita_Security::core_check_summary() ) . '</p>';
		$r = get_option( Perdita_Security::CORE_CHECK_OPTION, array() );
		if ( is_array( $r ) && ! empty( $r['status'] ) && 'problems' === $r['status'] ) {
			$groups = array(
				'changed' => __( 'Changed files', 'perdita-core' ),
				'missing' => __( 'Missing files', 'perdita-core' ),
				'unknown' => __( 'Unknown PHP files', 'perdita-core' ),
			);
			foreach ( $groups as $k => $label ) {
				if ( ! empty( $r[ $k ] ) ) {
					echo '<p><strong>' . esc_html( $label ) . '</strong></p><ul class="perdita-security-list">';
					foreach ( (array) $r[ $k ] as $f ) {
						echo '<li><code>' . esc_html( $f ) . '</code></li>';
					}
					echo '</ul>';
				}
			}
		}
		echo '<p><button type="submit" class="button" form="perdita-core-check-form">' . esc_html__( 'Check now', 'perdita-core' ) . '</button></p>';
		echo '</td></tr>';
		echo '</table>';
	}

	/**
	 * The Check now handler's own form. Printed after the settings form,
	 * because forms cannot nest.
	 */
	private function core_check_form() {
		echo '<form id="perdita-core-check-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CHECK_ACTION ) . '" />';
		wp_nonce_field( self::CHECK_ACTION );
		echo '</form>';
	}

	/**
	 * Run the core check now.
	 */
	public function check_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( self::CHECK_ACTION );
		$r    = Perdita_Security::run_core_check( true );
		$key  = 'error' === $r['status'] ? 'perdita_err' : 'perdita_msg';
		$text = 'error' === $r['status'] ? (string) $r['error'] : Perdita_Security::core_check_summary();
		wp_safe_redirect( add_query_arg( $key, rawurlencode( $text ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
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

		$out     = Perdita_Security::defaults();
		$current = Perdita_Security::get_settings();
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above, each value is validated in its own sanitize method.

		// Firewall keys keep what is stored unless the firewall screen
		// supplies its own validated values.
		foreach ( array_keys( $out ) as $key ) {
			if ( 0 === strpos( $key, 'fw_' ) || 0 === strpos( $key, 'firewall' ) ) {
				$out[ $key ] = $current[ $key ];
			}
		}
		if ( class_exists( 'Perdita_Security_Firewall_Admin' ) && method_exists( 'Perdita_Security_Firewall_Admin', 'sanitize' ) ) {
			$fw = Perdita_Security_Firewall_Admin::sanitize( $post );
			if ( is_array( $fw ) ) {
				$out = array_merge( $out, $fw );
			}
		}

		$out['headers']           = ! empty( $_POST['headers'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$out['disable_file_edit'] = ! empty( $_POST['disable_file_edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$out['disable_xmlrpc']    = ! empty( $_POST['disable_xmlrpc'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$out                      = array_merge( $out, Perdita_Security::sanitize_protections( $post ) );

		// The login limits and the allowlist go through the same check the
		// Switch to Perdita import uses.
		$out = array_merge(
			$out,
			Perdita_Security::sanitize_login(
				array(
					'login_limit'   => ! empty( $post['login_limit'] ),
					'login_max'     => isset( $post['login_max'] ) ? (int) $post['login_max'] : 5,
					'login_window'  => isset( $post['login_window'] ) ? (int) $post['login_window'] : 15,
					'login_lockout' => isset( $post['login_lockout'] ) ? (int) $post['login_lockout'] : 15,
					'ip_allowlist'  => isset( $post['ip_allowlist'] ) ? $post['ip_allowlist'] : '',
				)
			)
		);

		$mode                = isset( $_POST['csp_mode'] ) ? sanitize_key( wp_unslash( $_POST['csp_mode'] ) ) : 'report-only';
		$out['csp_mode']     = in_array( $mode, array( 'off', 'report-only', 'enforce' ), true ) ? $mode : 'report-only';
		$out['csp']          = isset( $_POST['csp'] ) ? $this->sanitize_csp( wp_unslash( $_POST['csp'] ) ) : Perdita_Security::default_csp(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_csp().

		update_option( Perdita_Security::OPTION, $out );

		// The uploads .htaccess block follows the box.
		$block = Perdita_Security::sync_uploads_block( ! empty( $out['block_uploads_php'] ) );
		$msg   = __( 'Security settings saved.', 'perdita-core' );
		if ( is_wp_error( $block ) ) {
			$msg .= ' ' . $block->get_error_message();
		}

		wp_safe_redirect(
			add_query_arg(
				'perdita_msg',
				rawurlencode( $msg ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
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
