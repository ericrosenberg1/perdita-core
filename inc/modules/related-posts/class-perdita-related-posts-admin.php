<?php
/**
 * Related posts settings screen.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Related_Posts_Admin {

	/**
	 * The main module instance (unused directly, kept for the standard
	 * admin-class constructor shape every module follows).
	 *
	 * @var Perdita_Related_Posts
	 */
	private $main;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Related_Posts $main Main module.
	 * @param Perdita                $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_related_posts_save', array( $this, 'save' ) );
	}

	/**
	 * Settings submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page( 'perdita', __( 'Related Posts', 'perdita-core' ), __( 'Related Posts', 'perdita-core' ), 'manage_options', 'perdita-related-posts', array( $this, 'page' ) );
	}

	/**
	 * Render the settings screen.
	 */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Perdita_Related_Posts::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Related Posts', 'perdita-core' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== perdita_notice_text( 'perdita_msg' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_msg' ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Shown automatically after every published post. Matched by shared categories and tags, computed on your own site, nothing sent to a third party.', 'perdita-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="perdita_related_posts_save" />';
		wp_nonce_field( 'perdita_related_posts_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="rp_heading">' . esc_html__( 'Heading', 'perdita-core' ) . '</label></th><td><input type="text" class="regular-text" id="rp_heading" name="heading" value="' . esc_attr( $s['heading'] ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="rp_count">' . esc_html__( 'How many to show', 'perdita-core' ) . '</label></th><td><input type="number" id="rp_count" name="count" value="' . esc_attr( $s['count'] ) . '" min="1" max="6" class="small-text" /></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Match by', 'perdita-core' ) . '</th><td>';
		foreach ( array(
			'both'     => __( 'Categories and tags', 'perdita-core' ),
			'category' => __( 'Categories only', 'perdita-core' ),
			'tag'      => __( 'Tags only', 'perdita-core' ),
		) as $val => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="match_by" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $val ),
				checked( $s['match_by'], $val, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'If a post has too few matches, the newest other posts fill the rest so the section is never empty.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form></div>';
	}

	/**
	 * Save the settings.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'perdita_related_posts_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		$clean = Perdita_Related_Posts::sanitize(
			array(
				'count'    => isset( $_POST['count'] ) ? wp_unslash( $_POST['count'] ) : null,
				'match_by' => isset( $_POST['match_by'] ) ? sanitize_key( wp_unslash( $_POST['match_by'] ) ) : null,
				'heading'  => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : null,
			)
		);
		update_option( Perdita_Related_Posts::OPTION, $clean );
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( __( 'Settings saved.', 'perdita-core' ) ), admin_url( 'admin.php?page=perdita-related-posts' ) ) );
		exit;
	}
}
