<?php
/**
 * Footer/copyright shortcodes.
 *
 * Registered as real WordPress shortcodes (not a closed one-off token parser)
 * so they also work if pasted into ordinary post/page content or a widget,
 * not just the footer credit field. [perdita_credit] composes the full
 * default line, and is the single source of truth for what "default" means:
 * templates/footer.php runs the (possibly blank, falling back to
 * [perdita_credit]) credit_text setting through do_shortcode() rather than
 * re-deriving the default line itself.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Shortcodes {

	/**
	 * Settings.
	 *
	 * @var Perdita_Settings
	 */
	private $settings;

	/**
	 * Constructor. Register the shortcodes.
	 *
	 * @param Perdita_Settings $settings Settings.
	 */
	public function __construct( Perdita_Settings $settings ) {
		$this->settings = $settings;

		add_shortcode( 'perdita_year', array( $this, 'shortcode_year' ) );
		add_shortcode( 'perdita_copyright', array( $this, 'shortcode_copyright' ) );
		add_shortcode( 'perdita_trademark', array( $this, 'shortcode_trademark' ) );
		add_shortcode( 'perdita_site_name', array( $this, 'shortcode_site_name' ) );
		add_shortcode( 'perdita_credit', array( $this, 'shortcode_credit' ) );
	}

	/**
	 * [perdita_year]: the current year, or a "start–end" range when an
	 * established year is configured and differs from the current year.
	 * Same year on both ends would read as a redundant "2026–2026", so that
	 * case collapses to a single year instead.
	 *
	 * @return string
	 */
	public function shortcode_year() {
		$current      = wp_date( 'Y' );
		$established  = trim( (string) $this->settings->get( 'footer.established_year', '' ) );
		if ( '' === $established || $established === $current ) {
			return esc_html( $current );
		}
		return esc_html( $established ) . '&#8211;' . esc_html( $current );
	}

	/**
	 * [perdita_copyright]: the © symbol.
	 *
	 * @return string
	 */
	public function shortcode_copyright() {
		return esc_html( '©' );
	}

	/**
	 * [perdita_trademark]: the ™ symbol.
	 *
	 * @return string
	 */
	public function shortcode_trademark() {
		return esc_html( '™' );
	}

	/**
	 * [perdita_site_name]: the site title.
	 *
	 * @return string
	 */
	public function shortcode_site_name() {
		return esc_html( get_bloginfo( 'name', 'display' ) );
	}

	/**
	 * [perdita_credit]: the full default line (© + year + site name),
	 * composed from the other shortcodes so there is one source of truth for
	 * what "default" means. This is what templates/footer.php falls back to
	 * when the credit_text setting is left blank, and it can also be dropped
	 * into a custom credit_text value (or ordinary post content) alongside
	 * other text if a site owner wants to reorder or add to it.
	 *
	 * @return string
	 */
	public function shortcode_credit() {
		// The theme owns the footer template, so when it publishes its own
		// perdita_footer_credit() that function is the source of truth and
		// this shortcode defers to it: one credit line, whether the footer
		// renders it directly or a site owner pastes [perdita_credit] into a
		// custom credit_text value. The buffer is there because a theme
		// template helper can reasonably either echo or return, and a
		// shortcode callback must return; whichever convention it uses, the
		// output lands in the right place instead of printing at the top of
		// the page.
		if ( function_exists( 'perdita_footer_credit' ) ) {
			ob_start();
			$returned = perdita_footer_credit();
			$printed  = trim( (string) ob_get_clean() );
			if ( is_string( $returned ) && '' !== trim( $returned ) ) {
				return $returned;
			}
			if ( '' !== $printed ) {
				return $printed;
			}
		}

		return sprintf(
			/* translators: 1: copyright symbol, 2: year (or year range), 3: site name */
			esc_html__( '%1$s %2$s %3$s', 'perdita-core' ),
			$this->shortcode_copyright(),
			$this->shortcode_year(),
			$this->shortcode_site_name()
		);
	}
}
