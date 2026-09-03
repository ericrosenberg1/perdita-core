<?php
/**
 * Perdita SEO admin: a top-level menu with Dashboard, Titles & Meta, Social,
 * Default Images, and Tools (import from Yoast / All in One SEO).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_SEO_Admin {

	/**
	 * SEO store.
	 *
	 * @var Perdita_SEO_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Perdita_SEO_Store $store SEO store.
	 */
	public function __construct( Perdita_SEO_Store $store ) {
		$this->store = $store;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_perdita_seo_general', array( $this, 'save_general' ) );
		add_action( 'admin_post_perdita_seo_titles', array( $this, 'save_titles' ) );
		add_action( 'admin_post_perdita_seo_social', array( $this, 'save_social' ) );
		add_action( 'admin_post_perdita_seo_images', array( $this, 'save_images' ) );
		add_action( 'admin_post_perdita_seo_import', array( $this, 'do_import' ) );
	}

	/**
	 * Menu + submenus.
	 */
	public function menu() {
		// Position 57: sorts just below the main Perdita menu (56).
		add_menu_page( __( 'Perdita SEO', 'perdita-core' ), __( 'Perdita SEO', 'perdita-core' ), 'manage_options', 'perdita-seo', array( $this, 'page_dashboard' ), 'dashicons-search', 57 );
		add_submenu_page( 'perdita-seo', __( 'Dashboard', 'perdita-core' ), __( 'Dashboard', 'perdita-core' ), 'manage_options', 'perdita-seo', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'perdita-seo', __( 'Titles & Meta', 'perdita-core' ), __( 'Titles & Meta', 'perdita-core' ), 'manage_options', 'perdita-seo-titles', array( $this, 'page_titles' ) );
		add_submenu_page( 'perdita-seo', __( 'Social', 'perdita-core' ), __( 'Social', 'perdita-core' ), 'manage_options', 'perdita-seo-social', array( $this, 'page_social' ) );
		add_submenu_page( 'perdita-seo', __( 'Default Images', 'perdita-core' ), __( 'Default Images', 'perdita-core' ), 'manage_options', 'perdita-seo-images', array( $this, 'page_images' ) );
		add_submenu_page( 'perdita-seo', __( 'Tools', 'perdita-core' ), __( 'Tools', 'perdita-core' ), 'manage_options', 'perdita-seo-tools', array( $this, 'page_tools' ) );
	}

	/**
	 * Enqueue the media picker on Perdita SEO screens.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'perdita-seo' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'perdita-admin-media', PERDITA_CORE_URL . 'assets/js/admin-media.js', array( 'jquery' ), PERDITA_CORE_VERSION, true );
	}

	/* ---------- shared bits ---------- */

	private function open( $title ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		$this->nav();
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['perdita_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}
		if ( Perdita_SEO::other_seo_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another SEO plugin (Yoast, AIOSEO, or Rank Math) is active, so Perdita SEO is not outputting tags to avoid duplicates. Import its settings under Tools, then deactivate it to let Perdita manage SEO.', 'perdita-core' ) . '</p></div>';
		}
	}

	private function nav() {
		$tabs = array(
			'perdita-seo'        => __( 'Dashboard', 'perdita-core' ),
			'perdita-seo-titles' => __( 'Titles & Meta', 'perdita-core' ),
			'perdita-seo-social' => __( 'Social', 'perdita-core' ),
			'perdita-seo-images' => __( 'Default Images', 'perdita-core' ),
			'perdita-seo-tools'  => __( 'Tools', 'perdita-core' ),
		);
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'perdita-seo'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	private function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	private function image_field( $label, $name, $id ) {
		$src = $id ? wp_get_attachment_image_url( (int) $id, 'medium' ) : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" id="f-' . esc_attr( $name ) . '" value="' . esc_attr( (int) $id ) . '" />';
		echo '<img id="p-' . esc_attr( $name ) . '" src="' . esc_url( $src ) . '" alt="" style="display:block;max-height:80px;width:auto;margin-bottom:8px;border-radius:6px;background:#f6f7f7;" ' . ( $src ? '' : 'hidden' ) . ' />';
		echo '<button type="button" class="button perdita-media-pick" data-input="f-' . esc_attr( $name ) . '" data-preview="p-' . esc_attr( $name ) . '">' . esc_html__( 'Choose image', 'perdita-core' ) . '</button> ';
		echo '<button type="button" class="button-link perdita-media-clear" data-input="f-' . esc_attr( $name ) . '" data-preview="p-' . esc_attr( $name ) . '" ' . ( $id ? '' : 'hidden' ) . '>' . esc_html__( 'Remove', 'perdita-core' ) . '</button>';
		echo '</td></tr>';
	}

	private function premium() {
		?>
		<div class="card" style="max-width:760px;border-left:4px solid #2563eb;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Coming in Perdita Premium', 'perdita-core' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;color:#3c434a;">
				<li><?php esc_html_e( 'Per-post SEO editor with live Google and social previews', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Per-post-type title and meta templates, plus richer schema (Article, Product, FAQ, HowTo, LocalBusiness, Recipe)', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Redirect manager and 404 monitor, with automatic redirects on slug changes', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Import per-post meta and redirects from Yoast, AIOSEO, and Rank Math', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'AI bulk meta-description generation and internal-link suggestions', 'perdita-core' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Everything on this page is free, forever. Premium adds depth for bigger sites.', 'perdita-core' ); ?></p>
		</div>
		<?php
	}

	private function redirect( $page, $msg ) {
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
	}

	/* ---------- pages ---------- */

	public function page_dashboard() {
		$this->open( __( 'Perdita SEO', 'perdita-core' ) );
		?>
		<p><?php esc_html_e( 'A complete, free SEO setup for small sites: titles, meta, social cards, schema, and sensible defaults. No budget required.', 'perdita-core' ); ?></p>
		<?php
		$this->form_open( 'perdita_seo_general' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( (bool) $this->store->get( 'enabled', true ), true, false ) . ' /> ' . esc_html__( 'Let Perdita manage this site\'s SEO output', 'perdita-core' ) . '</label></p>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form><hr />';

		$links = array(
			'perdita-seo-titles' => __( 'Set your title format, homepage title and description, schema, and noindex rules', 'perdita-core' ),
			'perdita-seo-social' => __( 'Link your social profiles and pick the Twitter/X card style', 'perdita-core' ),
			'perdita-seo-images' => __( 'Set the default image used when a page has no featured image', 'perdita-core' ),
			'perdita-seo-tools'  => __( 'Import your settings from Yoast or All in One SEO', 'perdita-core' ),
		);
		echo '<ul>';
		foreach ( $links as $slug => $desc ) {
			printf( '<li><a href="%s"><strong>%s</strong></a> · %s</li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( ucwords( str_replace( 'perdita-seo-', '', $slug ) ) ), esc_html( $desc ) );
		}
		echo '</ul>';
		$this->premium();
		echo '</div>';
	}

	public function page_titles() {
		$this->open( __( 'Titles & Meta', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_titles' );
		echo '<table class="form-table" role="presentation">';
		/* translators: %title%, %sitename%, and %sep% are literal tokens the user types into the Title format field, not sprintf placeholders. */
		$this->text_row( __( 'Title format', 'perdita-core' ), 'title_template', $this->store->get( 'title_template' ), sprintf( /* translators: 1, 2, 3: the literal tokens %title%, %sitename%, %sep% the user types into the Title format field. */ __( 'Tokens: %1$s %2$s %3$s', 'perdita-core' ), '%title%', '%sitename%', '%sep%' ) );
		$this->text_row( __( 'Separator', 'perdita-core' ), 'separator', $this->store->get( 'separator' ), '', 'small-text' );
		$this->text_row( __( 'Homepage title', 'perdita-core' ), 'home_title', $this->store->get( 'home_title' ), __( 'Leave blank to use the site name.', 'perdita-core' ) );
		$this->textarea_row( __( 'Homepage description', 'perdita-core' ), 'home_description', $this->store->get( 'home_description' ) );
		$this->textarea_row( __( 'Default meta description', 'perdita-core' ), 'default_description', $this->store->get( 'default_description' ), __( 'Used when a page has no excerpt or content to pull from.', 'perdita-core' ) );
		$this->select_row( __( 'Site represents a', 'perdita-core' ), 'schema_type', $this->store->get( 'schema_type' ), array( 'Organization' => __( 'Organization', 'perdita-core' ), 'Person' => __( 'Person', 'perdita-core' ) ) );
		$this->text_row( __( 'Organization / person name', 'perdita-core' ), 'org_name', $this->store->get( 'org_name' ), __( 'Leave blank to use the site name.', 'perdita-core' ) );
		$this->image_field( __( 'Schema logo', 'perdita-core' ), 'org_logo_id', $this->store->get( 'org_logo_id' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Search visibility', 'perdita-core' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Hide from search engines', 'perdita-core' ) . '</th><td>';
		$n = $this->store->get( 'noindex', array() );
		foreach ( array(
			'archive_date'      => __( 'Date archives', 'perdita-core' ),
			'archive_author'    => __( 'Author archives', 'perdita-core' ),
			'archive_tag'       => __( 'Tag archives', 'perdita-core' ),
			'archive_category'  => __( 'Category archives', 'perdita-core' ),
			'archive_post_type' => __( 'Custom post type archives', 'perdita-core' ),
			'search'            => __( 'Search results', 'perdita-core' ),
		) as $key => $label ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="noindex_' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $n[ $key ] ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr></table>';
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	public function page_social() {
		$this->open( __( 'Social', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_social' );
		echo '<p>' . esc_html__( 'Linked profiles power your schema sameAs and social cards. Use full URLs (the Twitter/X field also accepts a @handle).', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$s = $this->store->get( 'social', array() );
		$this->text_row( 'X / Twitter', 'social_twitter', isset( $s['twitter'] ) ? $s['twitter'] : '', __( '@handle or full URL', 'perdita-core' ) );
		$this->text_row( 'Facebook', 'social_facebook', isset( $s['facebook'] ) ? $s['facebook'] : '' );
		$this->text_row( 'Instagram', 'social_instagram', isset( $s['instagram'] ) ? $s['instagram'] : '' );
		$this->text_row( 'LinkedIn', 'social_linkedin', isset( $s['linkedin'] ) ? $s['linkedin'] : '' );
		$this->text_row( 'YouTube', 'social_youtube', isset( $s['youtube'] ) ? $s['youtube'] : '' );
		$this->select_row( __( 'Twitter / X card style', 'perdita-core' ), 'twitter_card', $this->store->get( 'twitter_card' ), array( 'summary_large_image' => __( 'Large image', 'perdita-core' ), 'summary' => __( 'Small thumbnail', 'perdita-core' ) ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public function page_images() {
		$this->open( __( 'Default Images', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_images' );
		echo '<p>' . esc_html__( 'This image is used for Open Graph and Twitter cards when a page has no featured image. Recommended 1200x630.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->image_field( __( 'Default social image', 'perdita-core' ), 'default_image_id', $this->store->get( 'default_image_id' ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public function page_tools() {
		$this->open( __( 'Tools', 'perdita-core' ) );
		$has_yoast  = (bool) get_option( 'wpseo_titles' ) || defined( 'WPSEO_VERSION' );
		$has_aioseo = (bool) get_option( 'aioseo_options' ) || defined( 'AIOSEO_VERSION' );
		echo '<p>' . esc_html__( 'Bring your global SEO settings over from another plugin. This imports site-wide titles, social profiles, and default images. Per-post meta import is a Premium feature.', 'perdita-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Import from Yoast SEO', 'perdita-core' ) . '</h2>';
		echo '<p>' . ( $has_yoast ? esc_html__( 'Yoast settings detected.', 'perdita-core' ) : esc_html__( 'No Yoast settings found on this site.', 'perdita-core' ) ) . '</p>';
		$this->form_open( 'perdita_seo_import' );
		echo '<input type="hidden" name="source" value="yoast" />';
		submit_button( __( 'Import from Yoast', 'perdita-core' ), 'secondary', 'submit', false );
		echo '</form><hr />';

		echo '<h2>' . esc_html__( 'Import from All in One SEO', 'perdita-core' ) . '</h2>';
		echo '<p>' . ( $has_aioseo ? esc_html__( 'AIOSEO settings detected.', 'perdita-core' ) : esc_html__( 'No AIOSEO settings found on this site.', 'perdita-core' ) ) . '</p>';
		$this->form_open( 'perdita_seo_import' );
		echo '<input type="hidden" name="source" value="aioseo" />';
		submit_button( __( 'Import from AIOSEO', 'perdita-core' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/* ---------- field helpers ---------- */

	private function text_row( $label, $name, $value, $desc = '', $class = 'regular-text' ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="' . esc_attr( $class ) . '" id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function textarea_row( $label, $name, $value, $desc = '' ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function select_row( $label, $name, $value, $choices ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $val => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $value, $val, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}

	/* ---------- save handlers ---------- */

	public function save_general() {
		$this->guard( 'perdita_seo_general' );
		$this->store->save( array( 'enabled' => ! empty( $_POST['enabled'] ) ) );
		$this->redirect( 'perdita-seo', __( 'Saved.', 'perdita-core' ) );
	}

	public function save_titles() {
		$this->guard( 'perdita_seo_titles' );
		$schema_type_in = wp_unslash( $_POST['schema_type'] ?? '' );
		$this->store->save(
			array(
				'title_template'      => sanitize_text_field( wp_unslash( $_POST['title_template'] ?? '' ) ),
				'separator'           => sanitize_text_field( wp_unslash( $_POST['separator'] ?? '-' ) ),
				'home_title'          => sanitize_text_field( wp_unslash( $_POST['home_title'] ?? '' ) ),
				'home_description'    => sanitize_textarea_field( wp_unslash( $_POST['home_description'] ?? '' ) ),
				'default_description' => sanitize_textarea_field( wp_unslash( $_POST['default_description'] ?? '' ) ),
				'schema_type'         => in_array( $schema_type_in, array( 'Organization', 'Person' ), true ) ? $schema_type_in : 'Organization',
				'org_name'            => sanitize_text_field( wp_unslash( $_POST['org_name'] ?? '' ) ),
				'org_logo_id'         => absint( $_POST['org_logo_id'] ?? 0 ),
				'noindex'             => array(
					'archive_date'      => ! empty( $_POST['noindex_archive_date'] ),
					'archive_author'    => ! empty( $_POST['noindex_archive_author'] ),
					'archive_tag'       => ! empty( $_POST['noindex_archive_tag'] ),
					'archive_category'  => ! empty( $_POST['noindex_archive_category'] ),
					'archive_post_type' => ! empty( $_POST['noindex_archive_post_type'] ),
					'search'            => ! empty( $_POST['noindex_search'] ),
				),
			)
		);
		$this->redirect( 'perdita-seo-titles', __( 'Titles and meta saved.', 'perdita-core' ) );
	}

	public function save_social() {
		$this->guard( 'perdita_seo_social' );
		$twitter_card_in = wp_unslash( $_POST['twitter_card'] ?? '' );
		$this->store->save(
			array(
				'twitter_card' => in_array( $twitter_card_in, array( 'summary', 'summary_large_image' ), true ) ? $twitter_card_in : 'summary_large_image',
				'social'       => array(
					'twitter'   => sanitize_text_field( wp_unslash( $_POST['social_twitter'] ?? '' ) ),
					'facebook'  => esc_url_raw( wp_unslash( $_POST['social_facebook'] ?? '' ) ),
					'instagram' => esc_url_raw( wp_unslash( $_POST['social_instagram'] ?? '' ) ),
					'linkedin'  => esc_url_raw( wp_unslash( $_POST['social_linkedin'] ?? '' ) ),
					'youtube'   => esc_url_raw( wp_unslash( $_POST['social_youtube'] ?? '' ) ),
				),
			)
		);
		$this->redirect( 'perdita-seo-social', __( 'Social profiles saved.', 'perdita-core' ) );
	}

	public function save_images() {
		$this->guard( 'perdita_seo_images' );
		$this->store->save( array( 'default_image_id' => absint( $_POST['default_image_id'] ?? 0 ) ) );
		$this->redirect( 'perdita-seo-images', __( 'Default image saved.', 'perdita-core' ) );
	}

	public function do_import() {
		$this->guard( 'perdita_seo_import' );
		$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$n      = 'yoast' === $source ? $this->store->import_yoast() : ( 'aioseo' === $source ? $this->store->import_aioseo() : 0 );
		$msg    = $n
			? sprintf( /* translators: 1: count, 2: source */ __( 'Imported %1$d settings from %2$s.', 'perdita-core' ), $n, 'yoast' === $source ? 'Yoast' : 'AIOSEO' )
			: __( 'Nothing to import (no settings found).', 'perdita-core' );
		$this->redirect( 'perdita-seo-tools', $msg );
	}
}
