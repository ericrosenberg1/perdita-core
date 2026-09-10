<?php
/**
 * IndexNow settings screen: the key and its file, a live check that the
 * file is reachable, a manual submit box, the enable toggle, the engine
 * list, and the last 50 submissions.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_IndexNow_Admin {

	/**
	 * Menu slug under the Perdita menu.
	 */
	const PAGE = 'perdita-indexnow';

	/**
	 * The main module instance.
	 *
	 * @var Perdita_IndexNow
	 */
	private $main;

	/**
	 * Constructor.
	 *
	 * @param Perdita_IndexNow $main Main module.
	 * @param Perdita_Core     $core Core.
	 */
	public function __construct( $main, $core ) {
		unset( $core );
		$this->main = $main;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_indexnow_save', array( $this, 'save' ) );
		add_action( 'admin_post_perdita_indexnow_check', array( $this, 'check' ) );
		add_action( 'admin_post_perdita_indexnow_submit', array( $this, 'submit' ) );
		add_action( 'admin_post_perdita_indexnow_regenerate', array( $this, 'regenerate' ) );
	}

	/**
	 * Submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page( 'perdita', __( 'IndexNow', 'perdita-core' ), __( 'IndexNow', 'perdita-core' ), 'manage_options', self::PAGE, array( $this, 'page' ) );
	}

	/**
	 * Open an admin-post form with its nonce.
	 *
	 * @param string $action admin_post_* action.
	 */
	private function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	/**
	 * Capability and nonce gate for every handler.
	 *
	 * @param string $action admin_post_* action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param string $msg Message.
	 */
	private function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Perdita_IndexNow::settings();
		$key     = Perdita_IndexNow::key();
		$key_url = Perdita_IndexNow::key_file_url();

		echo '<div class="wrap"><h1>' . esc_html__( 'IndexNow', 'perdita-core' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a flash message set by our own redirect().
		if ( isset( $_GET['perdita_msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same flash message.
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'IndexNow tells Bing, Yandex, Seznam, Naver, and Yep about a URL the moment it is published, updated, or removed. Google does not use IndexNow, so the sitemap is pinged on publish as well.', 'perdita-core' ) . '</p>';

		if ( 1 !== (int) get_option( 'blog_public' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This site discourages search engines (Settings, Reading), so nothing is submitted until that is switched off.', 'perdita-core' ) . '</p></div>';
		}

		// Key and key file.
		echo '<h2>' . esc_html__( 'Key file', 'perdita-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Key', 'perdita-core' ) . '</th><td><code>' . esc_html( $key ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Key file URL', 'perdita-core' ) . '</th><td><a href="' . esc_url( $key_url ) . '" target="_blank" rel="noopener">' . esc_html( $key_url ) . '</a><p class="description">' . esc_html__( 'Served by this plugin, no file to upload. Engines fetch it to confirm the key belongs to this site.', 'perdita-core' ) . '</p></td></tr>';
		echo '</table>';
		echo '<p>';
		$this->form_open( 'perdita_indexnow_check' );
		submit_button( __( 'Check the key file', 'perdita-core' ), 'secondary', 'submit', false );
		echo ' ';
		echo '</form> ';
		$this->form_open( 'perdita_indexnow_regenerate' );
		submit_button( __( 'Generate a new key', 'perdita-core' ), 'secondary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Generate a new key? Engines will verify the new file on the next submission.', 'perdita-core' ) ) . "');" ) );
		echo '</form></p>';

		// Settings.
		echo '<h2>' . esc_html__( 'Settings', 'perdita-core' ) . '</h2>';
		$this->form_open( 'perdita_indexnow_save' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Automatic submission', 'perdita-core' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $s['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Submit URLs when a post is published, updated, trashed, or deleted', 'perdita-core' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="perdita_indexnow_engines">' . esc_html__( 'Engines', 'perdita-core' ) . '</label></th><td><textarea id="perdita_indexnow_engines" name="engines" rows="3" class="large-text code">' . esc_textarea( implode( "\n", (array) $s['engines'] ) ) . '</textarea><p class="description">' . esc_html__( 'One hostname per line. api.indexnow.org shares every submission with all participating engines, so the default is enough for most sites.', 'perdita-core' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form>';

		// Manual submit.
		echo '<h2>' . esc_html__( 'Submit URLs now', 'perdita-core' ) . '</h2>';
		$this->form_open( 'perdita_indexnow_submit' );
		echo '<p><textarea name="urls" rows="5" class="large-text code" placeholder="' . esc_attr( home_url( '/example-post/' ) ) . '"></textarea></p>';
		echo '<p class="description">' . esc_html__( 'One URL per line. Only URLs on this site are sent. The result appears in the log below.', 'perdita-core' ) . '</p>';
		submit_button( __( 'Submit', 'perdita-core' ), 'primary', 'submit', true );
		echo '</form>';

		// Log.
		echo '<h2>' . esc_html__( 'Log', 'perdita-core' ) . '</h2>';
		if ( empty( $s['log'] ) ) {
			echo '<p>' . esc_html__( 'Nothing submitted yet.', 'perdita-core' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'perdita-core' ) . '</th><th>' . esc_html__( 'Engine', 'perdita-core' ) . '</th><th>' . esc_html__( 'URLs', 'perdita-core' ) . '</th><th>' . esc_html__( 'Status', 'perdita-core' ) . '</th><th>' . esc_html__( 'First URLs', 'perdita-core' ) . '</th></tr></thead><tbody>';
			foreach ( $s['log'] as $row ) {
				$status = $row['status'] ? (string) $row['status'] : ( '' !== $row['error'] ? $row['error'] : '?' );
				$label  = self::status_label( (int) $row['status'] );
				echo '<tr>';
				echo '<td>' . esc_html( $row['time'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) : '' ) . '</td>';
				echo '<td>' . esc_html( $row['engine'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $row['count'] ) . '</td>';
				echo '<td>' . esc_html( $status . ( $label ? ' ' . $label : '' ) ) . '</td>';
				echo '<td>' . esc_html( implode( ' ', (array) $row['urls'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * A short reading of an IndexNow HTTP status.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 200:
			case 202:
				return __( '(accepted)', 'perdita-core' );
			case 400:
				return __( '(bad request)', 'perdita-core' );
			case 403:
				return __( '(key not verified, check the key file)', 'perdita-core' );
			case 422:
				return __( '(URL not on this host)', 'perdita-core' );
			case 429:
				return __( '(too many requests)', 'perdita-core' );
			default:
				return '';
		}
	}

	/**
	 * Save the enable flag and the engine list.
	 */
	public function save() {
		$this->guard( 'perdita_indexnow_save' );
		$engines = isset( $_POST['engines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['engines'] ) ) : '';
		Perdita_IndexNow::save(
			array(
				'enabled' => ! empty( $_POST['enabled'] ),
				'engines' => preg_split( '/[\r\n,]+/', $engines ),
			)
		);
		$this->redirect( __( 'Settings saved.', 'perdita-core' ) );
	}

	/**
	 * Fetch the key file over HTTP and confirm the key comes back.
	 */
	public function check() {
		$this->guard( 'perdita_indexnow_check' );
		$url      = Perdita_IndexNow::key_file_url();
		$response = wp_remote_get( $url, array( 'timeout' => Perdita_IndexNow::TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			$this->redirect( sprintf(
				/* translators: %s: error message. */
				__( 'Could not fetch the key file: %s', 'perdita-core' ),
				$response->get_error_message()
			) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( 200 === $code && $body === Perdita_IndexNow::key() ) {
			$this->redirect( __( 'The key file is reachable and returns the key.', 'perdita-core' ) );
		}
		$this->redirect( sprintf(
			/* translators: 1: HTTP status, 2: the first characters of the body. */
			__( 'The key file did not verify (HTTP %1$d, body starts "%2$s"). A cache or security layer may be answering that URL instead of this plugin.', 'perdita-core' ),
			$code,
			mb_substr( $body, 0, 40 )
		) );
	}

	/**
	 * Submit the URLs from the textarea right away.
	 */
	public function submit() {
		$this->guard( 'perdita_indexnow_submit' );
		$raw     = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$urls    = preg_split( '/\s+/', trim( $raw ) );
		$results = Perdita_IndexNow::submit( is_array( $urls ) ? $urls : array() );
		if ( empty( $results ) ) {
			$this->redirect( __( 'Nothing sent: no valid URLs on this site were given.', 'perdita-core' ) );
		}
		$parts = array();
		foreach ( $results as $row ) {
			$parts[] = sprintf( '%1$s: %2$s', $row['engine'], $row['status'] ? 'HTTP ' . $row['status'] : $row['error'] );
		}
		$this->redirect( sprintf(
			/* translators: 1: number of URLs, 2: per-engine results. */
			__( 'Submitted %1$d URL(s). %2$s', 'perdita-core' ),
			(int) $results[0]['count'],
			implode( ', ', $parts )
		) );
	}

	/**
	 * Replace the key.
	 */
	public function regenerate() {
		$this->guard( 'perdita_indexnow_regenerate' );
		Perdita_IndexNow::regenerate_key();
		$this->redirect( __( 'A new key was generated.', 'perdita-core' ) );
	}
}
