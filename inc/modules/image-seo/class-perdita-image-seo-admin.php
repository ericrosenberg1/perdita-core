<?php
/**
 * Image SEO settings screen: the alt and title templates, the punctuation and
 * case options, a batch that backfills missing alt text 100 images at a time,
 * and the image sitemap URL.
 *
 * The batch is a plain form post, no JavaScript: each submission does one
 * round, then redirects with a count and how many are left, so the browser's
 * own progress bar is the progress bar. A person clicking through 4,000 images
 * is 40 clicks, and nothing breaks if they close the tab halfway.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Image_SEO_Admin {

	/**
	 * Menu slug under the Perdita menu.
	 */
	const PAGE = 'perdita-image-seo';

	/**
	 * The main module instance.
	 *
	 * @var Perdita_Image_SEO
	 */
	private $main;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Image_SEO $main Main module.
	 * @param Perdita_Core      $core Core.
	 */
	public function __construct( $main, $core ) {
		unset( $core );
		$this->main = $main;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_image_seo_save', array( $this, 'save' ) );
		add_action( 'admin_post_perdita_image_seo_fill', array( $this, 'fill' ) );
	}

	/**
	 * Submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page( 'perdita', __( 'Image SEO', 'perdita-core' ), __( 'Image SEO', 'perdita-core' ), 'manage_options', self::PAGE, array( $this, 'page' ) );
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
		$s       = Perdita_Image_SEO::settings();
		$missing = Perdita_Image_SEO::count_missing_alt();

		echo '<div class="wrap"><h1>' . esc_html__( 'Image SEO', 'perdita-core' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a flash message set by our own redirect().
		if ( isset( $_GET['perdita_msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same flash message.
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Generated alt text is a floor, not a ceiling. It beats an empty alt attribute for a screen reader and for image search, and a written description beats both.', 'perdita-core' ) . '</p>';

		// Settings.
		$this->form_open( 'perdita_image_seo_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Alt text', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="auto_alt" value="1" ' . checked( ! empty( $s['auto_alt'] ), true, false ) . ' /> ' . esc_html__( 'Fill in alt text when an image is uploaded without any', 'perdita-core' ) . '</label>';
		echo '<p><input type="text" name="alt_format" class="regular-text code" value="' . esc_attr( (string) $s['alt_format'] ) . '" /></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Media title', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="auto_title" value="1" ' . checked( ! empty( $s['auto_title'] ), true, false ) . ' /> ' . esc_html__( 'Rewrite the media title on upload, but only while it is still the raw filename', 'perdita-core' ) . '</label>';
		echo '<p><input type="text" name="title_format" class="regular-text code" value="' . esc_attr( (string) $s['title_format'] ) . '" /></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Variables', 'perdita-core' ) . '</th><td><p class="description">';
		echo esc_html__( '%filename% is the file name with the dashes and underscores turned back into spaces, the extension dropped, and digit-only segments removed. %post_title% is the post the image is attached to. %sitename% is the site title.', 'perdita-core' );
		echo '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cleanup', 'perdita-core' ) . '</th><td>';
		echo '<label><input type="checkbox" name="strip_punctuation" value="1" ' . checked( ! empty( $s['strip_punctuation'] ), true, false ) . ' /> ' . esc_html__( 'Strip punctuation', 'perdita-core' ) . '</label><br />';
		echo '<label><input type="checkbox" name="lowercase" value="1" ' . checked( ! empty( $s['lowercase'] ), true, false ) . ' /> ' . esc_html__( 'Force lower case', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save', 'perdita-core' ) );
		echo '</form>';

		// Batch.
		echo '<h2>' . esc_html__( 'Fill missing alt text', 'perdita-core' ) . '</h2>';
		if ( $missing > 0 ) {
			echo '<p>' . esc_html( sprintf(
				/* translators: 1: number of images with no alt text, 2: batch size. */
				_n( '%1$d image in the library has no alt text. Each round fills up to %2$d.', '%1$d images in the library have no alt text. Each round fills up to %2$d.', $missing, 'perdita-core' ),
				$missing,
				Perdita_Image_SEO::BATCH_SIZE
			) ) . '</p>';
			$this->form_open( 'perdita_image_seo_fill' );
			submit_button( __( 'Fill the next batch', 'perdita-core' ), 'primary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p>' . esc_html__( 'Every image in the library has alt text.', 'perdita-core' ) . '</p>';
		}

		// Sitemap.
		echo '<h2>' . esc_html__( 'Image sitemap', 'perdita-core' ) . '</h2>';
		echo '<p><a href="' . esc_url( Perdita_Image_SEO::sitemap_url() ) . '" target="_blank" rel="noopener">' . esc_html( Perdita_Image_SEO::sitemap_url() ) . '</a></p>';
		echo '<p class="description">' . esc_html( sprintf(
			/* translators: %d: maximum number of pages listed. */
			__( 'Lists the featured image and every in-content image for the %d most recently updated published items, and is announced in robots.txt. Cached for 12 hours and rebuilt whenever a post is saved.', 'perdita-core' ),
			Perdita_Image_SEO::SITEMAP_MAX_URLS
		) ) . '</p>';

		echo '</div>';
	}

	/**
	 * Save the settings.
	 */
	public function save() {
		$this->guard( 'perdita_image_seo_save' );
		update_option(
			Perdita_Image_SEO::OPTION,
			Perdita_Image_SEO::sanitize(
				array(
					'auto_alt'          => ! empty( $_POST['auto_alt'] ),
					'alt_format'        => isset( $_POST['alt_format'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_format'] ) ) : '',
					'auto_title'        => ! empty( $_POST['auto_title'] ),
					'title_format'      => isset( $_POST['title_format'] ) ? sanitize_text_field( wp_unslash( $_POST['title_format'] ) ) : '',
					'strip_punctuation' => ! empty( $_POST['strip_punctuation'] ),
					'lowercase'         => ! empty( $_POST['lowercase'] ),
				)
			)
		);
		$this->redirect( __( 'Settings saved.', 'perdita-core' ) );
	}

	/**
	 * Run one batch round.
	 */
	public function fill() {
		$this->guard( 'perdita_image_seo_fill' );
		$result = Perdita_Image_SEO::fill_missing_alt( Perdita_Image_SEO::BATCH_SIZE );

		if ( 0 === $result['filled'] && $result['skipped'] > 0 ) {
			$this->redirect( sprintf(
				/* translators: %d: number of images. */
				__( 'Nothing was filled. %d image(s) produced no text from the template, so they need alt text written by hand.', 'perdita-core' ),
				$result['skipped']
			) );
		}
		if ( 0 === $result['filled'] ) {
			$this->redirect( __( 'Every image in the library has alt text.', 'perdita-core' ) );
		}
		$this->redirect( sprintf(
			/* translators: 1: number filled this round, 2: number still missing. */
			__( 'Filled %1$d. %2$d still to go, run the batch again to continue.', 'perdita-core' ),
			$result['filled'],
			$result['remaining']
		) );
	}
}
