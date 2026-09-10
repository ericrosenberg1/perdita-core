<?php
/**
 * Breadcrumbs settings screen: the separator, the home label, the prefix,
 * whether the current item is shown, whether the trail appears on the front
 * page, and whether it is inserted above the content automatically.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Breadcrumbs_Admin {

	/**
	 * Menu slug under the Perdita menu.
	 */
	const PAGE = 'perdita-breadcrumbs';

	/**
	 * The main module instance.
	 *
	 * @var Perdita_Breadcrumbs
	 */
	private $main;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Breadcrumbs $main Main module.
	 * @param Perdita_Core        $core Core.
	 */
	public function __construct( $main, $core ) {
		unset( $core );
		$this->main = $main;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_breadcrumbs_save', array( $this, 'save' ) );
	}

	/**
	 * Submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page( 'perdita', __( 'Breadcrumbs', 'perdita-core' ), __( 'Breadcrumbs', 'perdita-core' ), 'manage_options', self::PAGE, array( $this, 'page' ) );
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
		$s = Perdita_Breadcrumbs::settings();

		echo '<div class="wrap"><h1>' . esc_html__( 'Breadcrumbs', 'perdita-core' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a flash message set by our own redirect().
		if ( isset( $_GET['perdita_msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same flash message.
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Add the trail with the Breadcrumbs block, the [perdita_breadcrumbs] shortcode, or the perdita_breadcrumbs() template tag. The setting below can also place it above the content on every post and page.', 'perdita-core' ) . '</p>';

		$this->form_open( 'perdita_breadcrumbs_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="perdita_bc_separator">' . esc_html__( 'Separator', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita_bc_separator" name="separator" class="small-text" value="' . esc_attr( (string) $s['separator'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="perdita_bc_home">' . esc_html__( 'Home label', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita_bc_home" name="home_label" class="regular-text" value="' . esc_attr( (string) $s['home_label'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="perdita_bc_prefix">' . esc_html__( 'Prefix', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita_bc_prefix" name="prefix" class="regular-text" value="' . esc_attr( (string) $s['prefix'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Optional text in front of the trail, for example "You are here".', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Current item', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="show_current" value="1" ' . checked( ! empty( $s['show_current'] ), true, false ) . ' /> ' . esc_html__( 'Show the page being viewed as the last item', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Front page', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="show_on_front" value="1" ' . checked( ! empty( $s['show_on_front'] ), true, false ) . ' /> ' . esc_html__( 'Show the trail on the front page too', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="perdita_bc_auto">' . esc_html__( 'Automatic placement', 'perdita-core' ) . '</label></th><td>';
		echo '<select id="perdita_bc_auto" name="auto_insert">';
		echo '<option value="none" ' . selected( 'none', $s['auto_insert'], false ) . '>' . esc_html__( 'Off, place it yourself', 'perdita-core' ) . '</option>';
		echo '<option value="before_content" ' . selected( 'before_content', $s['auto_insert'], false ) . '>' . esc_html__( 'Above the content on posts and pages', 'perdita-core' ) . '</option>';
		echo '</select>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Save the settings.
	 */
	public function save() {
		$this->guard( 'perdita_breadcrumbs_save' );
		update_option(
			Perdita_Breadcrumbs::OPTION,
			Perdita_Breadcrumbs::sanitize(
				array(
					'separator'     => isset( $_POST['separator'] ) ? sanitize_text_field( wp_unslash( $_POST['separator'] ) ) : '',
					'home_label'    => isset( $_POST['home_label'] ) ? sanitize_text_field( wp_unslash( $_POST['home_label'] ) ) : '',
					'prefix'        => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '',
					'show_current'  => ! empty( $_POST['show_current'] ),
					'show_on_front' => ! empty( $_POST['show_on_front'] ),
					'auto_insert'   => isset( $_POST['auto_insert'] ) ? sanitize_key( wp_unslash( $_POST['auto_insert'] ) ) : 'none',
				)
			)
		);
		$this->redirect( __( 'Settings saved.', 'perdita-core' ) );
	}
}
