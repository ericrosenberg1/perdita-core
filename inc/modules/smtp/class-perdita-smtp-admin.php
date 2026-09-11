<?php
/**
 * SMTP admin: settings screen, save, test email, and the delivery log.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_SMTP_Admin {

	/**
	 * The SMTP module core.
	 *
	 * @var Perdita_SMTP
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
	const PAGE = 'perdita-smtp';

	/**
	 * Constructor. Wire the menu and the admin-post handlers.
	 *
	 * @param Perdita_SMTP $main SMTP module core.
	 * @param Perdita      $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_smtp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_perdita_smtp_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_perdita_smtp_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Add the settings submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Email (SMTP)', 'perdita-core' ),
			__( 'Email (SMTP)', 'perdita-core' ),
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
	 * Render the settings screen and the recent log.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = Perdita_SMTP::settings();
		$has_pass   = '' !== (string) $s['password'];
		$admin_mail = wp_get_current_user()->user_email;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Email delivery (SMTP)', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Send your site email through an SMTP server so it lands in the inbox instead of the spam folder (or nowhere). Fill in the details from your email host, then send a test.', 'perdita-core' ) . '</p>';

		$this->render_notices();

		// Settings form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="perdita_smtp_save" />';
		wp_nonce_field( 'perdita_smtp_save' );
		echo '<table class="form-table" role="presentation">';

		// From email.
		echo '<tr><th scope="row"><label for="perdita-smtp-from-email">' . esc_html__( 'From email', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="email" id="perdita-smtp-from-email" class="regular-text" name="from_email" value="' . esc_attr( $s['from_email'] ) . '" placeholder="you@yourdomain.com" />';
		echo '<p class="description">' . esc_html__( 'The address your email is sent from. Leave blank to keep WordPress defaults.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// From name.
		echo '<tr><th scope="row"><label for="perdita-smtp-from-name">' . esc_html__( 'From name', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita-smtp-from-name" class="regular-text" name="from_name" value="' . esc_attr( $s['from_name'] ) . '" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'The name your email is sent from. Leave blank to keep WordPress defaults.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Host.
		echo '<tr><th scope="row"><label for="perdita-smtp-host">' . esc_html__( 'SMTP host', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita-smtp-host" class="regular-text" name="host" value="' . esc_attr( $s['host'] ) . '" placeholder="smtp.yourhost.com" />';
		echo '</td></tr>';

		// Port.
		echo '<tr><th scope="row"><label for="perdita-smtp-port">' . esc_html__( 'Port', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" id="perdita-smtp-port" class="small-text" name="port" value="' . esc_attr( (string) $s['port'] ) . '" min="1" max="65535" />';
		echo '<p class="description">' . esc_html__( 'Common ports are 587 (TLS) and 465 (SSL).', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Encryption.
		echo '<tr><th scope="row"><label for="perdita-smtp-encryption">' . esc_html__( 'Encryption', 'perdita-core' ) . '</label></th><td>';
		echo '<select id="perdita-smtp-encryption" name="encryption">';
		$enc_choices = array(
			''    => __( 'None', 'perdita-core' ),
			'tls' => __( 'TLS', 'perdita-core' ),
			'ssl' => __( 'SSL', 'perdita-core' ),
		);
		foreach ( $enc_choices as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['encryption'], $val, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '</td></tr>';

		// Authentication.
		echo '<tr><th scope="row">' . esc_html__( 'Authentication', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="auth" value="1" ' . checked( ! empty( $s['auth'] ), true, false ) . ' /> ' . esc_html__( 'Use a username and password to log in to the mail server.', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		// Username.
		echo '<tr><th scope="row"><label for="perdita-smtp-username">' . esc_html__( 'Username', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita-smtp-username" class="regular-text" name="username" value="' . esc_attr( $s['username'] ) . '" autocomplete="off" />';
		echo '</td></tr>';

		// Password (encrypted, placeholder + remove pattern).
		echo '<tr><th scope="row"><label for="perdita-smtp-password">' . esc_html__( 'Password', 'perdita-core' ) . '</label></th><td>';
		$pass_placeholder = $has_pass ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : '';
		echo '<input type="password" id="perdita-smtp-password" class="regular-text" name="password" value="" autocomplete="new-password" placeholder="' . esc_attr( $pass_placeholder ) . '" />';
		if ( $has_pass ) {
			echo '<label style="margin-left:8px;"><input type="checkbox" name="remove_password" value="1" /> ' . esc_html__( 'Remove password', 'perdita-core' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'The password is encrypted before it is saved, so a database backup alone does not reveal it. Type a new value only when you want to change it.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save settings', 'perdita-core' ) );
		echo '</form>';

		// Test email.
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Send a test email', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: admin email address */ __( 'Send a test message to %s to confirm delivery works.', 'perdita-core' ), $admin_mail ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="perdita_smtp_test" />';
		wp_nonce_field( 'perdita_smtp_test' );
		submit_button( __( 'Send test email', 'perdita-core' ), 'secondary', 'submit', false );
		echo '</form>';

		// Delivery log.
		$this->render_log();

		echo '</div>';
	}

	/**
	 * Render success/error notices from a redirect.
	 */
	private function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag on a redirect, no state change.
		$status = isset( $_GET['perdita_smtp_msg'] ) ? sanitize_key( wp_unslash( $_GET['perdita_smtp_msg'] ) ) : '';
		if ( '' === $status ) {
			return;
		}
		if ( 'saved' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'perdita-core' ) . '</p></div>';
		} elseif ( 'log_cleared' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Log cleared.', 'perdita-core' ) . '</p></div>';
		} elseif ( 'test_ok' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent. Check your inbox.', 'perdita-core' ) . '</p></div>';
		} elseif ( 'test_failed' === $status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of an error string on a redirect.
			$detail = isset( $_GET['perdita_smtp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['perdita_smtp_err'] ) ) : '';
			$line   = __( 'Test email failed to send.', 'perdita-core' );
			if ( '' !== $detail ) {
				$line .= ' ' . $detail;
			}
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $line ) . '</p></div>';
		}
	}

	/**
	 * Render the recent delivery log with a clear button.
	 */
	private function render_log() {
		$rows = Perdita_SMTP::recent_log( 50 );

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Recent email log', 'perdita-core' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No email has been logged yet.', 'perdita-core' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:10px;">';
		echo '<input type="hidden" name="action" value="perdita_smtp_clear_log" />';
		wp_nonce_field( 'perdita_smtp_clear_log' );
		submit_button( __( 'Clear log', 'perdita-core' ), 'delete', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'perdita-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$is_sent = ( 'sent' === $row->status );
			$badge   = $is_sent
				? '<span style="color:#008a20;">' . esc_html__( 'Sent', 'perdita-core' ) . '</span>'
				: '<span style="color:#b32d2e;">' . esc_html__( 'Failed', 'perdita-core' ) . '</span>';
			echo '<tr>';
			echo '<td style="white-space:nowrap;">' . esc_html( $row->created ) . '</td>';
			echo '<td>' . esc_html( $row->recipient ) . '</td>';
			echo '<td>' . esc_html( $row->subject ) . '</td>';
			echo '<td>' . wp_kses( $badge, array( 'span' => array( 'style' => array() ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row->error ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Save settings. Sanitizes every field and encrypts the password.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_smtp_save' );

		$current = Perdita_SMTP::settings();

		// Encryption is one of a fixed set.
		$encryption = isset( $_POST['encryption'] ) ? sanitize_key( wp_unslash( $_POST['encryption'] ) ) : '';
		if ( ! in_array( $encryption, array( '', 'tls', 'ssl' ), true ) ) {
			$encryption = '';
		}

		$port = isset( $_POST['port'] ) ? absint( wp_unslash( $_POST['port'] ) ) : 587;
		if ( $port < 1 || $port > 65535 ) {
			$port = 587;
		}

		$from_email_raw = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
		$from_email     = is_email( $from_email_raw ) ? $from_email_raw : '';

		$new = array(
			'from_email' => $from_email,
			'from_name'  => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
			'host'       => isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '',
			'port'       => $port,
			'encryption' => $encryption,
			'auth'       => ! empty( $_POST['auth'] ),
			'username'   => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
			'password'   => $current['password'], // Keep the stored blob unless changed below.
		);

		// Password: remove takes priority, then a newly typed value, else keep.
		if ( ! empty( $_POST['remove_password'] ) ) {
			$new['password'] = '';
		} else {
			// Do not sanitize a password through text filters, that can corrupt it.
			// It is treated as an opaque secret and encrypted immediately.
			$typed = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque secret, encrypted below, never echoed.
			if ( '' !== $typed ) {
				$new['password'] = Perdita_Crypto::encrypt( $typed );
			}
		}

		update_option( Perdita_SMTP::OPTION, $new );

		$this->redirect_back( array( 'perdita_smtp_msg' => 'saved' ) );
	}

	/**
	 * Send a test email to the current admin and report the result.
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_smtp_test' );

		$to      = wp_get_current_user()->user_email;
		$subject = sprintf( /* translators: %s: site name */ __( 'Perdita SMTP test from %s', 'perdita-core' ), get_bloginfo( 'name' ) );
		$body    = __( 'This is a test email from your site. If you received it, SMTP delivery is working.', 'perdita-core' );

		// Capture a PHPMailer-level error if the send fails.
		$captured = '';
		$grab     = function ( $error ) use ( &$captured ) {
			if ( is_wp_error( $error ) ) {
				$captured = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $grab );
		$sent = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $grab );

		if ( $sent ) {
			$this->redirect_back( array( 'perdita_smtp_msg' => 'test_ok' ) );
		} else {
			$args = array( 'perdita_smtp_msg' => 'test_failed' );
			if ( '' !== $captured ) {
				$args['perdita_smtp_err'] = $captured;
			}
			$this->redirect_back( $args );
		}
	}

	/**
	 * Clear the delivery log.
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_smtp_clear_log' );

		Perdita_SMTP::clear_log();

		$this->redirect_back( array( 'perdita_smtp_msg' => 'log_cleared' ) );
	}

	/**
	 * Redirect back to the settings page with status args.
	 *
	 * @param array $args Query args to add.
	 */
	private function redirect_back( array $args ) {
		$url = add_query_arg( $args, $this->page_url() );
		wp_safe_redirect( $url );
		exit;
	}
}
