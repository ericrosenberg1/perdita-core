<?php
/**
 * Perdita SEO admin: a top-level menu with Dashboard, Titles & Meta, Robots,
 * Social, Webmaster Tools, Feeds, Tools (robots.txt, llms.txt), and Import
 * (Yoast, All in One SEO, Genesis).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Perdita SEO settings screens.
 */
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
		add_action( 'admin_post_perdita_seo_robots', array( $this, 'save_robots' ) );
		add_action( 'admin_post_perdita_seo_social', array( $this, 'save_social' ) );
		add_action( 'admin_post_perdita_seo_webmaster', array( $this, 'save_webmaster' ) );
		add_action( 'admin_post_perdita_seo_feeds', array( $this, 'save_feeds' ) );
		add_action( 'admin_post_perdita_seo_tools', array( $this, 'save_tools' ) );
		add_action( 'admin_post_perdita_seo_import', array( $this, 'do_import' ) );
	}

	/**
	 * Menu + submenus.
	 */
	public function menu() {
		// Position 57: sorts just below the main Perdita menu (56).
		add_menu_page( __( 'Perdita SEO', 'perdita-core' ), __( 'Perdita SEO', 'perdita-core' ), 'manage_options', 'perdita-seo', array( $this, 'page_dashboard' ), 'dashicons-search', 57 );
		foreach ( $this->tabs() as $slug => $label ) {
			add_submenu_page( 'perdita-seo', $label, $label, 'manage_options', $slug, array( $this, 'page_' . $this->page_method( $slug ) ) );
		}
	}

	/**
	 * Tab slugs and labels, in menu order.
	 *
	 * @return array
	 */
	private function tabs() {
		return array(
			'perdita-seo'           => __( 'Dashboard', 'perdita-core' ),
			'perdita-seo-titles'    => __( 'Titles & Meta', 'perdita-core' ),
			'perdita-seo-robots'    => __( 'Robots', 'perdita-core' ),
			'perdita-seo-social'    => __( 'Social', 'perdita-core' ),
			'perdita-seo-webmaster' => __( 'Webmaster Tools', 'perdita-core' ),
			'perdita-seo-feeds'     => __( 'Feeds', 'perdita-core' ),
			'perdita-seo-tools'     => __( 'Tools', 'perdita-core' ),
			'perdita-seo-import'    => __( 'Import', 'perdita-core' ),
		);
	}

	/**
	 * The page_* method suffix for a tab slug.
	 *
	 * @param string $slug Tab slug.
	 * @return string
	 */
	private function page_method( $slug ) {
		$suffix = str_replace( 'perdita-seo', '', $slug );
		return '' === $suffix ? 'dashboard' : ltrim( $suffix, '-' );
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

	/**
	 * Open the wrap, print the tab nav, and show any saved notice.
	 *
	 * @param string $title Screen title.
	 */
	private function open( $title ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		$this->nav();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect notice, not form data.
		if ( '' !== perdita_notice_text( 'perdita_msg' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_msg' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect notice, not form data.
		}
		if ( Perdita_SEO::other_seo_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another SEO plugin (Yoast, AIOSEO, or Rank Math) is active, so Perdita SEO is not outputting tags to avoid duplicates. Import its settings under Import, then deactivate it to let Perdita manage SEO.', 'perdita-core' ) . '</p></div>';
		}
	}

	/**
	 * Print the tab strip, marking the current screen.
	 */
	private function nav() {
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'perdita-seo'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
		foreach ( $this->tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Open a form that posts to admin-post.php with its nonce.
	 *
	 * @param string $action admin_post action name, also the nonce action.
	 */
	private function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	/**
	 * One media-picker row: a hidden id, a preview, and the buttons.
	 *
	 * @param string $label Row label.
	 * @param string $name  Field name.
	 * @param int    $id    Attachment id, 0 for none.
	 */
	private function image_field( $label, $name, $id ) {
		$src = $id ? wp_get_attachment_image_url( (int) $id, 'medium' ) : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" id="f-' . esc_attr( $name ) . '" value="' . esc_attr( (int) $id ) . '" />';
		echo '<img id="p-' . esc_attr( $name ) . '" src="' . esc_url( $src ) . '" alt="" style="display:block;max-height:80px;width:auto;margin-bottom:8px;border-radius:6px;background:#f6f7f7;" ' . ( $src ? '' : 'hidden' ) . ' />';
		echo '<button type="button" class="button perdita-media-pick" data-input="f-' . esc_attr( $name ) . '" data-preview="p-' . esc_attr( $name ) . '">' . esc_html__( 'Choose image', 'perdita-core' ) . '</button> ';
		echo '<button type="button" class="button-link perdita-media-clear" data-input="f-' . esc_attr( $name ) . '" data-preview="p-' . esc_attr( $name ) . '" ' . ( $id ? '' : 'hidden' ) . '>' . esc_html__( 'Remove', 'perdita-core' ) . '</button>';
		echo '</td></tr>';
	}

	/**
	 * The card listing what Perdita Premium adds.
	 */
	private function premium() {
		?>
		<div class="card" style="max-width:760px;border-left:4px solid #2563eb;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Coming in Perdita Premium', 'perdita-core' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;color:#3c434a;">
				<li><?php esc_html_e( 'Per-post SEO editor with live Google and social previews', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Richer schema (Product, FAQ, HowTo, LocalBusiness, Recipe) and breadcrumbs', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Redirect manager and 404 monitor, with automatic redirects on slug changes', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Import per-post titles, descriptions, canonical URLs, and noindex flags from Yoast, AIOSEO, and Genesis', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'AI bulk meta-description generation and internal-link suggestions', 'perdita-core' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Everything on this page is free, forever. Premium adds depth for bigger sites.', 'perdita-core' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Send the browser back to a tab with a one-line notice.
	 *
	 * @param string $page Admin page slug.
	 * @param string $msg  Notice text.
	 */
	private function redirect( $page, $msg ) {
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/**
	 * Stop anyone without manage_options or a valid nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
	}

	/**
	 * Public post types that get their own templates, attachments excluded.
	 *
	 * @return WP_Post_Type[]
	 */
	private function post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Public taxonomies that get their own templates.
	 *
	 * @return WP_Taxonomy[]
	 */
	private function taxonomies() {
		$taxes = get_taxonomies( array( 'public' => true ), 'objects' );
		unset( $taxes['post_format'] );
		return $taxes;
	}

	/**
	 * The "blank falls back to this" help line for a template field. The
	 * token stays outside the translatable string so a percent sign never
	 * reaches a translator as a printf placeholder.
	 *
	 * @param string $tokens The fallback template, printed literally.
	 * @return string
	 */
	private function blank_uses( $tokens ) {
		/* translators: %s: a template such as %title% %sep% %sitename%, printed literally. */
		return sprintf( __( 'Blank uses %s.', 'perdita-core' ), $tokens );
	}

	/**
	 * The help line listing every template token.
	 *
	 * @return string
	 */
	private function tokens_help() {
		$names = array_map(
			function ( $n ) {
				return '%' . $n . '%';
			},
			Perdita_SEO_Variables::names()
		);
		/* translators: %s: comma separated list of template tokens. */
		return sprintf( __( 'Tokens: %s', 'perdita-core' ), implode( ' ', $names ) );
	}

	/* ---------- pages ---------- */

	/**
	 * The Dashboard tab: the master switch and links to the rest.
	 */
	public function page_dashboard() {
		$this->open( __( 'Perdita SEO', 'perdita-core' ) );
		?>
		<p><?php esc_html_e( 'A complete, free SEO setup for small sites: titles, meta, robots, social cards, schema, feeds, and sensible defaults. No budget required.', 'perdita-core' ); ?></p>
		<?php
		$this->form_open( 'perdita_seo_general' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( (bool) $this->store->get( 'enabled', true ), true, false ) . ' /> ' . esc_html__( 'Let Perdita manage this site\'s SEO output', 'perdita-core' ) . '</label></p>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form><hr />';

		$links = array(
			'perdita-seo-titles'    => __( 'Title and description templates for every post type and taxonomy, schema identity', 'perdita-core' ),
			'perdita-seo-robots'    => __( 'What search engines may index, plus snippet and preview limits', 'perdita-core' ),
			'perdita-seo-social'    => __( 'Social profiles, the default share image, and card styles', 'perdita-core' ),
			'perdita-seo-webmaster' => __( 'Verify the site with Google, Bing, Pinterest, Yandex, and Baidu', 'perdita-core' ),
			'perdita-seo-feeds'     => __( 'Feed footers and feed on/off switches', 'perdita-core' ),
			'perdita-seo-tools'     => __( 'robots.txt editor and the llms.txt index for AI crawlers', 'perdita-core' ),
			'perdita-seo-import'    => __( 'Import your settings from Yoast, All in One SEO, or Genesis', 'perdita-core' ),
		);
		$tabs  = $this->tabs();
		echo '<ul>';
		foreach ( $links as $slug => $desc ) {
			printf( '<li><a href="%s"><strong>%s</strong></a> · %s</li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $tabs[ $slug ] ), esc_html( $desc ) );
		}
		echo '</ul>';
		$this->premium();
		echo '</div>';
	}

	/**
	 * The Titles & Meta tab: templates per view, per post type, and per taxonomy, plus the schema identity.
	 */
	public function page_titles() {
		$this->open( __( 'Titles & Meta', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_titles' );
		echo '<p class="description">' . esc_html( $this->tokens_help() ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->text_row( __( 'Separator', 'perdita-core' ), 'separator', $this->store->get( 'separator' ), '', 'small-text' );
		$this->text_row( __( 'Default title format', 'perdita-core' ), 'title_template', $this->store->get( 'title_template' ), __( 'Used by any post type without its own format below.', 'perdita-core' ) );
		$this->text_row( __( 'Homepage title', 'perdita-core' ), 'home_title', $this->store->get( 'home_title' ), __( 'Leave blank for the site name and tagline.', 'perdita-core' ) );
		$this->textarea_row( __( 'Homepage description', 'perdita-core' ), 'home_description', $this->store->get( 'home_description' ) );
		$this->textarea_row( __( 'Default meta description', 'perdita-core' ), 'default_description', $this->store->get( 'default_description' ), __( 'Used when a page has no excerpt or content to pull from.', 'perdita-core' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Post types', 'perdita-core' ) . '</h2>';
		/* translators: %s: the name of a template token, printed literally. */
		echo '<p class="description">' . esc_html( sprintf( __( 'The description format defaults to %s, which is the manual excerpt or the first 155 characters of the content.', 'perdita-core' ), '%excerpt%' ) ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( $this->post_types() as $slug => $type ) {
			$row = $this->store->get( 'post_types.' . $slug, array() );
			$row = is_array( $row ) ? $row : array();
			$this->text_row( sprintf( '%s: %s', $type->labels->singular_name, __( 'title', 'perdita-core' ) ), 'pt[' . $slug . '][title]', isset( $row['title'] ) ? $row['title'] : '', sprintf( /* translators: %s: the default template. */ __( 'Blank uses the default title format (%s).', 'perdita-core' ), $this->store->get( 'title_template' ) ) );
			$this->text_row( sprintf( '%s: %s', $type->labels->singular_name, __( 'description', 'perdita-core' ) ), 'pt[' . $slug . '][description]', isset( $row['description'] ) ? $row['description'] : '', $this->blank_uses( '%excerpt%' ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Taxonomies', 'perdita-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A term with its own SEO title or description (set when editing the term) overrides these.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( $this->taxonomies() as $slug => $tax ) {
			$row = $this->store->get( 'taxonomies.' . $slug, array() );
			$row = is_array( $row ) ? $row : array();
			$this->text_row( sprintf( '%s: %s', $tax->labels->singular_name, __( 'title', 'perdita-core' ) ), 'tax[' . $slug . '][title]', isset( $row['title'] ) ? $row['title'] : '', $this->blank_uses( '%term_title% %sep% %sitename%' ) );
			$this->text_row( sprintf( '%s: %s', $tax->labels->singular_name, __( 'description', 'perdita-core' ) ), 'tax[' . $slug . '][description]', isset( $row['description'] ) ? $row['description'] : '', $this->blank_uses( '%term_description%' ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Other views', 'perdita-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->text_row( __( 'Date and author archives', 'perdita-core' ), 'archive_title', $this->store->get( 'archive_title' ), __( 'Also used for post type archives.', 'perdita-core' ) );
		$this->text_row( __( 'Search results', 'perdita-core' ), 'search_title', $this->store->get( 'search_title' ), sprintf( /* translators: 1: the %title% token, 2: the %search_term% token. */ __( '%1$s is the "Search results for" line WordPress builds. %2$s is the bare query.', 'perdita-core' ), '%title%', '%search_term%' ) );
		$this->text_row( __( '404 page', 'perdita-core' ), '404_title', $this->store->get( '404_title' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Schema identity', 'perdita-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row(
			__( 'Site represents a', 'perdita-core' ),
			'schema_type',
			$this->store->get( 'schema_type' ),
			array(
				'Organization' => __( 'Organization', 'perdita-core' ),
				'Person'       => __( 'Person', 'perdita-core' ),
			)
		);
		$this->text_row( __( 'Organization / person name', 'perdita-core' ), 'org_name', $this->store->get( 'org_name' ), __( 'Leave blank to use the site name.', 'perdita-core' ) );
		$this->image_field( __( 'Schema logo', 'perdita-core' ), 'org_logo_id', $this->store->get( 'org_logo_id' ) );
		echo '</table>';
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The Robots tab: what stays out of the index, and the preview limits.
	 */
	public function page_robots() {
		$this->open( __( 'Robots', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_robots' );
		echo '<p>' . esc_html__( 'These print through the robots meta tag. Anything hidden here still gets crawled and followed, it just stays out of the index.', 'perdita-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Hide from search engines', 'perdita-core' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Archives', 'perdita-core' ) . '</th><td>';
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
		echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="noindex_paginated" value="1" ' . checked( (bool) $this->store->get( 'noindex_paginated' ), true, false ) . ' /> ' . esc_html__( 'Page 2 and later of any list', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'perdita-core' ) . '</th><td>';
		foreach ( $this->post_types() as $slug => $type ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="pt[' . esc_attr( $slug ) . '][noindex]" value="1" ' . checked( $this->store->post_type_settings( $slug )['noindex'], true, false ) . ' /> ' . esc_html( $type->labels->name ) . '</label>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Taxonomies', 'perdita-core' ) . '</th><td>';
		foreach ( $this->taxonomies() as $slug => $tax ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tax[' . esc_attr( $slug ) . '][noindex]" value="1" ' . checked( $this->store->taxonomy_settings( $slug )['noindex'], true, false ) . ' /> ' . esc_html( $tax->labels->name ) . '</label>';
		}
		echo '</td></tr>';
		$this->select_row(
			__( 'Attachment pages', 'perdita-core' ),
			'attachments',
			$this->store->get( 'attachments', 'redirect' ),
			array(
				'redirect' => __( 'Redirect to the parent post (recommended)', 'perdita-core' ),
				'noindex'  => __( 'Serve them, hidden from search engines', 'perdita-core' ),
			)
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Preview limits', 'perdita-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->number_row( __( 'Max snippet', 'perdita-core' ), 'max_snippet', (int) $this->store->get( 'max_snippet', -1 ), __( 'Characters of text a search result may show. -1 for no limit.', 'perdita-core' ) );
		$this->select_row(
			__( 'Max image preview', 'perdita-core' ),
			'max_image_preview',
			$this->store->get( 'max_image_preview', 'large' ),
			array(
				'large'    => __( 'Large', 'perdita-core' ),
				'standard' => __( 'Standard', 'perdita-core' ),
				'none'     => __( 'None', 'perdita-core' ),
			)
		);
		$this->number_row( __( 'Max video preview', 'perdita-core' ), 'max_video_preview', (int) $this->store->get( 'max_video_preview', -1 ), __( 'Seconds of video a search result may play. -1 for no limit.', 'perdita-core' ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The Social tab: linked profiles, the app id, the default share image, and the card style.
	 */
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
		$this->text_row( __( 'Facebook App ID', 'perdita-core' ), 'facebook_app_id', $this->store->get( 'facebook_app_id' ), __( 'Optional. Prints fb:app_id so Facebook Insights can attribute shares.', 'perdita-core' ) );
		$this->select_row(
			__( 'Twitter / X card style', 'perdita-core' ),
			'twitter_card',
			$this->store->get( 'twitter_card' ),
			array(
				'summary_large_image' => __( 'Large image', 'perdita-core' ),
				'summary'             => __( 'Small thumbnail', 'perdita-core' ),
			)
		);
		$this->image_field( __( 'Default social image', 'perdita-core' ), 'default_image_id', $this->store->get( 'default_image_id' ) );
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'The default image is used for Open Graph and Twitter cards when a page has no featured image. Recommended 1200x630. Posts also print article:published_time, article:modified_time, article:author, article:section (primary category), and one article:tag per tag automatically. An author\'s X handle on their profile becomes twitter:creator.', 'perdita-core' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The Webmaster Tools tab: one verification token per search engine.
	 */
	public function page_webmaster() {
		$this->open( __( 'Webmaster Tools', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_webmaster' );
		echo '<p>' . esc_html__( 'Paste the verification token, or the whole meta tag the console gives you. Either works. Tags print on the front page only.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->text_row( __( 'Google Search Console', 'perdita-core' ), 'verify_google', $this->store->get( 'verify_google' ), 'google-site-verification' );
		$this->text_row( __( 'Bing Webmaster Tools', 'perdita-core' ), 'verify_bing', $this->store->get( 'verify_bing' ), 'msvalidate.01' );
		$this->text_row( __( 'Pinterest', 'perdita-core' ), 'verify_pinterest', $this->store->get( 'verify_pinterest' ), 'p:domain_verify' );
		$this->text_row( __( 'Yandex Webmaster', 'perdita-core' ), 'verify_yandex', $this->store->get( 'verify_yandex' ), 'yandex-verification' );
		$this->text_row( __( 'Baidu Webmaster Tools', 'perdita-core' ), 'verify_baidu', $this->store->get( 'verify_baidu' ), 'baidu-site-verification' );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The Feeds tab: the per-item footers and the feed off switches.
	 */
	public function page_feeds() {
		$this->open( __( 'Feeds', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_feeds' );
		echo '<p>' . esc_html__( 'Text added to every item in your RSS feeds. Scrapers that republish your feed carry the link back with it. Placeholders: %%POSTLINK%% %%POSTTITLE%% %%AUTHORLINK%% %%BLOGLINK%% %%BLOGTITLE%% %%BLOGDESC%%. Links and basic HTML are allowed.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->textarea_row( __( 'Before each item', 'perdita-core' ), 'rss_before', $this->store->get( 'rss_before' ) );
		$this->textarea_row( __( 'After each item', 'perdita-core' ), 'rss_after', $this->store->get( 'rss_after' ), __( 'For example: The post %%POSTLINK%% appeared first on %%BLOGLINK%%.', 'perdita-core' ) );
		$this->checkbox_row( __( 'Comments feed', 'perdita-core' ), 'rss_disable_comments_feed', (bool) $this->store->get( 'rss_disable_comments_feed' ), __( 'Turn off the comments feeds (requests redirect to the post or home)', 'perdita-core' ) );
		$this->checkbox_row( __( 'All feeds', 'perdita-core' ), 'rss_disable_all', (bool) $this->store->get( 'rss_disable_all' ), __( 'Turn off every feed (requests redirect to the home page with a 301)', 'perdita-core' ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The Tools tab: the robots.txt editor and the llms.txt toggles.
	 */
	public function page_tools() {
		$this->open( __( 'Tools', 'perdita-core' ) );
		$this->form_open( 'perdita_seo_tools' );

		echo '<h2>robots.txt</h2>';
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical robots.txt file exists in the site root. The web server serves that file directly, so nothing saved here takes effect until it is removed.', 'perdita-core' ) . '</p></div>';
		}
		$current = (string) $this->store->get( 'robots_txt' );
		$default = Perdita_SEO::default_robots_txt();
		echo '<p>' . esc_html__( 'Leave blank to keep the robots.txt WordPress generates (shown below as the starting point). Anything saved here replaces it. The Sitemap line is kept unless you write your own.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="f_robots_txt">' . esc_html__( 'robots.txt', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="10" id="f_robots_txt" name="robots_txt" placeholder="' . esc_attr( $default ) . '">' . esc_textarea( '' !== $current ? $current : $default ) . '</textarea>';
		printf( '<p class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_html__( 'Live file:', 'perdita-core' ), esc_url( home_url( '/robots.txt' ) ), esc_html( home_url( '/robots.txt' ) ) );
		echo '</td></tr></table>';

		echo '<h2>llms.txt</h2>';
		echo '<p>' . esc_html__( 'An index of your public content for AI crawlers: one line per page with a short summary, plus the sitemap. The full variant inlines post bodies and is off unless you turn it on.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( '/llms.txt', 'llms_txt', (bool) $this->store->get( 'llms_txt', true ), __( 'Serve the index', 'perdita-core' ), home_url( '/llms.txt' ) );
		$this->checkbox_row( '/llms-full.txt', 'llms_full_txt', (bool) $this->store->get( 'llms_full_txt' ), __( 'Serve the full text of up to 100 recent posts', 'perdita-core' ), home_url( '/llms-full.txt' ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The Import tab: pull global settings out of Yoast, AIOSEO, or Genesis.
	 */
	public function page_import() {
		$this->open( __( 'Import', 'perdita-core' ) );
		$has_yoast   = (bool) get_option( 'wpseo_titles' ) || defined( 'WPSEO_VERSION' );
		$has_aioseo  = (bool) get_option( 'aioseo_options' ) || defined( 'AIOSEO_VERSION' );
		$has_genesis = (bool) get_option( 'genesis-seo-settings' );
		echo '<p>' . esc_html__( 'Bring your global SEO settings over from another plugin or from the Genesis Framework. This imports site-wide titles, social profiles, default images, and noindex rules. Per-post meta import is a Premium feature.', 'perdita-core' ) . '</p>';

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
		echo '</form><hr />';

		echo '<h2>' . esc_html__( 'Import from Genesis', 'perdita-core' ) . '</h2>';
		echo '<p>' . ( $has_genesis ? esc_html__( 'Genesis SEO settings detected.', 'perdita-core' ) : esc_html__( 'No Genesis SEO settings found on this site.', 'perdita-core' ) ) . '</p>';
		$this->form_open( 'perdita_seo_import' );
		echo '<input type="hidden" name="source" value="genesis" />';
		submit_button( __( 'Import from Genesis', 'perdita-core' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/* ---------- field helpers ---------- */

	/**
	 * One text input row.
	 *
	 * @param string $label Row label.
	 * @param string $name  Field name.
	 * @param string $value Current value.
	 * @param string $desc  Help text.
	 * @param string $css_class Input class.
	 */
	private function text_row( $label, $name, $value, $desc = '', $css_class = 'regular-text' ) {
		$id = 'f_' . sanitize_key( str_replace( array( '[', ']' ), array( '_', '' ), $name ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="' . esc_attr( $css_class ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * One textarea row.
	 *
	 * @param string $label Row label.
	 * @param string $name  Field name.
	 * @param string $value Current value.
	 * @param string $desc  Help text.
	 */
	private function textarea_row( $label, $name, $value, $desc = '' ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * One select row.
	 *
	 * @param string $label   Row label.
	 * @param string $name    Field name.
	 * @param string $value   Current value.
	 * @param array  $choices value => label.
	 */
	private function select_row( $label, $name, $value, $choices ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $val => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $value, $val, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}

	/**
	 * One number input row, floored at -1 so the no-limit value is typeable.
	 *
	 * @param string $label Row label.
	 * @param string $name  Field name.
	 * @param int    $value Current value.
	 * @param string $desc  Help text.
	 */
	private function number_row( $label, $name, $value, $desc = '' ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="-1" step="1" id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '" />';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * One checkbox row, with an optional link to what it controls.
	 *
	 * @param string $label   Row label.
	 * @param string $name    Field name.
	 * @param bool   $checked Whether it is on.
	 * @param string $text    Label beside the box.
	 * @param string $link    Optional URL shown after the label.
	 */
	private function checkbox_row( $label, $name, $checked, $text, $link = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $checked, true, false ) . ' /> ' . esc_html( $text ) . '</label>';
		if ( '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a>';
		}
		echo '</td></tr>';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() runs check_admin_referer() before every reader below touches $_POST.

	/**
	 * Read the pt[slug][field] / tax[slug][field] groups from a form into a
	 * store patch, accepting only known slugs and the named fields. Blank
	 * templates are stored blank so the fallback applies again.
	 *
	 * @param string   $key    POST key (pt or tax).
	 * @param string[] $slugs  Allowed slugs.
	 * @param string[] $fields Fields to read from each row.
	 * @return array slug => row.
	 */
	private function read_groups( $key, array $slugs, array $fields ) {
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$out = array();
		foreach ( $slugs as $slug ) {
			$row   = isset( $raw[ $slug ] ) && is_array( $raw[ $slug ] ) ? $raw[ $slug ] : array();
			$clean = array();
			foreach ( $fields as $field ) {
				if ( 'noindex' === $field ) {
					$clean['noindex'] = ! empty( $row['noindex'] );
				} else {
					$clean[ $field ] = Perdita_SEO_Store::sanitize_template( $row[ $field ] ?? '' );
				}
			}
			$out[ $slug ] = $clean;
		}
		return $out;
	}

	/* ---------- save handlers ---------- */

	/**
	 * Save the master switch.
	 */
	public function save_general() {
		$this->guard( 'perdita_seo_general' );
		$this->store->save( array( 'enabled' => ! empty( $_POST['enabled'] ) ) );
		$this->redirect( 'perdita-seo', __( 'Saved.', 'perdita-core' ) );
	}

	/**
	 * Save every title and description template, plus the schema identity.
	 */
	public function save_titles() {
		$this->guard( 'perdita_seo_titles' );
		$schema_type_in = wp_unslash( $_POST['schema_type'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against an allow list on this line.
		$this->store->save(
			array(
				'title_template'      => Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['title_template'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
				'separator'           => sanitize_text_field( wp_unslash( $_POST['separator'] ?? '-' ) ),
				'home_title'          => Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['home_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
				'home_description'    => sanitize_textarea_field( wp_unslash( $_POST['home_description'] ?? '' ) ),
				'default_description' => sanitize_textarea_field( wp_unslash( $_POST['default_description'] ?? '' ) ),
				'archive_title'       => Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['archive_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
				'search_title'        => Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['search_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
				'404_title'           => Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['404_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
				'schema_type'         => in_array( $schema_type_in, array( 'Organization', 'Person' ), true ) ? $schema_type_in : 'Organization',
				'org_name'            => sanitize_text_field( wp_unslash( $_POST['org_name'] ?? '' ) ),
				'org_logo_id'         => absint( $_POST['org_logo_id'] ?? 0 ),
				'post_types'          => $this->read_groups( 'pt', array_keys( $this->post_types() ), array( 'title', 'description' ) ),
				'taxonomies'          => $this->read_groups( 'tax', array_keys( $this->taxonomies() ), array( 'title', 'description' ) ),
			)
		);
		$this->redirect( 'perdita-seo-titles', __( 'Titles and meta saved.', 'perdita-core' ) );
	}

	/**
	 * Save the indexing rules and the preview limits.
	 */
	public function save_robots() {
		$this->guard( 'perdita_seo_robots' );
		$attachments_in = wp_unslash( $_POST['attachments'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against an allow list on this line.
		$preview_in     = wp_unslash( $_POST['max_image_preview'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against an allow list on this line.
		$this->store->save(
			array(
				'noindex'           => array(
					'archive_date'      => ! empty( $_POST['noindex_archive_date'] ),
					'archive_author'    => ! empty( $_POST['noindex_archive_author'] ),
					'archive_tag'       => ! empty( $_POST['noindex_archive_tag'] ),
					'archive_category'  => ! empty( $_POST['noindex_archive_category'] ),
					'archive_post_type' => ! empty( $_POST['noindex_archive_post_type'] ),
					'search'            => ! empty( $_POST['noindex_search'] ),
				),
				'noindex_paginated' => ! empty( $_POST['noindex_paginated'] ),
				'attachments'       => in_array( $attachments_in, array( 'noindex', 'redirect' ), true ) ? $attachments_in : 'redirect',
				'max_snippet'       => max( -1, (int) wp_unslash( $_POST['max_snippet'] ?? -1 ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int on this line.
				'max_image_preview' => in_array( $preview_in, array( 'none', 'standard', 'large' ), true ) ? $preview_in : 'large',
				'max_video_preview' => max( -1, (int) wp_unslash( $_POST['max_video_preview'] ?? -1 ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int on this line.
				'post_types'        => $this->read_groups( 'pt', array_keys( $this->post_types() ), array( 'noindex' ) ),
				'taxonomies'        => $this->read_groups( 'tax', array_keys( $this->taxonomies() ), array( 'noindex' ) ),
			)
		);
		$this->redirect( 'perdita-seo-robots', __( 'Robots settings saved.', 'perdita-core' ) );
	}

	/**
	 * Save the linked profiles, the app id, the card style, and the default image.
	 */
	public function save_social() {
		$this->guard( 'perdita_seo_social' );
		$twitter_card_in = wp_unslash( $_POST['twitter_card'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against an allow list on this line.
		$this->store->save(
			array(
				'twitter_card'     => in_array( $twitter_card_in, array( 'summary', 'summary_large_image' ), true ) ? $twitter_card_in : 'summary_large_image',
				'facebook_app_id'  => preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['facebook_app_id'] ?? '' ) ) ),
				'default_image_id' => absint( $_POST['default_image_id'] ?? 0 ),
				'social'           => array(
					'twitter'   => sanitize_text_field( wp_unslash( $_POST['social_twitter'] ?? '' ) ),
					'facebook'  => esc_url_raw( wp_unslash( $_POST['social_facebook'] ?? '' ) ),
					'instagram' => esc_url_raw( wp_unslash( $_POST['social_instagram'] ?? '' ) ),
					'linkedin'  => esc_url_raw( wp_unslash( $_POST['social_linkedin'] ?? '' ) ),
					'youtube'   => esc_url_raw( wp_unslash( $_POST['social_youtube'] ?? '' ) ),
				),
			)
		);
		$this->redirect( 'perdita-seo-social', __( 'Social settings saved.', 'perdita-core' ) );
	}

	/**
	 * Save the verification tokens.
	 */
	public function save_webmaster() {
		$this->guard( 'perdita_seo_webmaster' );
		$patch = array();
		foreach ( array( 'verify_google', 'verify_bing', 'verify_pinterest', 'verify_yandex', 'verify_baidu' ) as $key ) {
			// The store's sanitizer pulls the token out of a pasted tag, so
			// the raw value goes through unslashed but otherwise untouched.
			$patch[ $key ] = Perdita_SEO_Store::sanitize_verification( wp_unslash( $_POST[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_verification() is the sanitizer.
		}
		$this->store->save( $patch );
		$this->redirect( 'perdita-seo-webmaster', __( 'Verification tokens saved.', 'perdita-core' ) );
	}

	/**
	 * Save the feed footers and the feed off switches.
	 */
	public function save_feeds() {
		$this->guard( 'perdita_seo_feeds' );
		$this->store->save(
			array(
				'rss_before'                => wp_kses_post( wp_unslash( $_POST['rss_before'] ?? '' ) ),
				'rss_after'                 => wp_kses_post( wp_unslash( $_POST['rss_after'] ?? '' ) ),
				'rss_disable_comments_feed' => ! empty( $_POST['rss_disable_comments_feed'] ),
				'rss_disable_all'           => ! empty( $_POST['rss_disable_all'] ),
			)
		);
		$this->redirect( 'perdita-seo-feeds', __( 'Feed settings saved.', 'perdita-core' ) );
	}

	/**
	 * Save the robots.txt override and the llms.txt toggles, then drop the llms.txt cache.
	 */
	public function save_tools() {
		$this->guard( 'perdita_seo_tools' );
		$robots = sanitize_textarea_field( wp_unslash( $_POST['robots_txt'] ?? '' ) );
		// Saving the generated default back unchanged means "no override".
		if ( trim( str_replace( "\r\n", "\n", $robots ) ) === trim( Perdita_SEO::default_robots_txt() ) ) {
			$robots = '';
		}
		$this->store->save(
			array(
				'robots_txt'    => $robots,
				'llms_txt'      => ! empty( $_POST['llms_txt'] ),
				'llms_full_txt' => ! empty( $_POST['llms_full_txt'] ),
			)
		);
		$llms = Perdita_SEO::instance() ? Perdita_SEO::instance()->llms : null;
		if ( $llms instanceof Perdita_SEO_Llms ) {
			$llms->invalidate();
		}
		$this->redirect( 'perdita-seo-tools', __( 'Tools saved.', 'perdita-core' ) );
	}

	/**
	 * Run the requested importer and report how many fields landed.
	 */
	public function do_import() {
		$this->guard( 'perdita_seo_import' );
		$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$labels = array(
			'yoast'   => 'Yoast',
			'aioseo'  => 'AIOSEO',
			'genesis' => 'Genesis',
		);
		switch ( $source ) {
			case 'yoast':
				$n = $this->store->import_yoast();
				break;
			case 'aioseo':
				$n = $this->store->import_aioseo();
				break;
			case 'genesis':
				$n = $this->store->import_genesis();
				break;
			default:
				$n = 0;
		}
		$msg = $n
			? sprintf( /* translators: 1: count, 2: source */ __( 'Imported %1$d settings from %2$s.', 'perdita-core' ), $n, $labels[ $source ] )
			: __( 'Nothing to import (no settings found).', 'perdita-core' );
		$this->redirect( 'perdita-seo-import', $msg );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
