<?php
/**
 * Perdita's brand on its own admin screens.
 *
 * One stylesheet, assets/css/perdita-admin.css (handle 'perdita-admin-ui'),
 * styles WordPress's standard admin controls inside a Perdita wrapper:
 *
 *   - body class 'perdita-admin-screen' on a whole Perdita screen (any
 *     ?page=perdita*, and the list and classic edit screens of Perdita's own
 *     post types). The sheet scopes it to #wpbody-content, so the admin menu,
 *     toolbar and footer keep WordPress's look.
 *   - class 'perdita-admin' on one Perdita element on someone else's screen:
 *     every meta box whose id starts with "perdita" gets it here, and Pro adds
 *     it to its nav menu item fields.
 *
 * The theme ships a byte-identical copy of the sheet and registers the same
 * handle, so it works without this plugin. Whichever registers first wins,
 * and a screen never loads the rules twice. Tokens come from the theme's
 * Perdita_Settings::theme_preset() when the theme is active, else from
 * FALLBACK below, which tests/smoke-admin-ui.php holds to the preset.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Core_Admin_UI {

	const HANDLE        = 'perdita-admin-ui';
	const BODY_CLASS    = 'perdita-admin-screen';
	const WRAPPER_CLASS = 'perdita-admin';

	/**
	 * Perdita's default preset, for a site running another theme. Must match
	 * Perdita_Settings::theme_preset() and the theme's layout radius.
	 */
	const FALLBACK = array(
		'primary'    => '#3f6b5e',
		'link_hover' => '#2e5246',
		'accent'     => '#c2703d',
		'heading'    => '#1c1b18',
		'text'       => '#33312c',
		'muted'      => '#76726a',
		'background' => '#f4f2ec',
		'surface'    => '#fbfaf6',
		'border'     => '#e4e1d8',
		'radius'     => 8,
	);

	/**
	 * Hooked once.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Meta box ids already given the wrapper class on this request.
	 *
	 * @var array<string,bool>
	 */
	private static $boxes = array();

	/**
	 * Wire the hooks. Admin requests only.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 5 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'mark_meta_boxes' ), PHP_INT_MAX );
		add_action( 'do_meta_boxes', array( __CLASS__, 'mark_meta_boxes' ), PHP_INT_MAX );
	}

	/**
	 * Whether the current admin screen is one of Perdita's own.
	 *
	 * @param WP_Screen|null $screen Screen, default the current one.
	 * @return bool
	 */
	public static function is_screen( $screen = null ) {
		global $plugin_page;
		if ( null === $screen && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}
		$page = is_string( $plugin_page ) ? $plugin_page : '';
		$yes  = '' !== $page && 0 === strpos( $page, 'perdita' );
		if ( ! $yes && $screen instanceof WP_Screen && 0 === strpos( (string) $screen->post_type, 'perdita_' ) ) {
			// The block editor keeps WordPress's own look. Its Perdita meta
			// boxes still get the brand through the wrapper class.
			$yes = 'edit' === $screen->base || ( 'post' === $screen->base && ! $screen->is_block_editor() );
		}

		/**
		 * Filter whether an admin screen carries Perdita's admin look.
		 *
		 * @param bool           $yes    Whether this is a Perdita screen.
		 * @param WP_Screen|null $screen The screen.
		 */
		return (bool) apply_filters( 'perdita_admin_ui_screen', $yes, $screen );
	}

	/**
	 * admin_enqueue_scripts: load the sheet on Perdita screens and on screens
	 * that show a Perdita meta box.
	 */
	public static function maybe_enqueue() {
		if ( self::is_screen() || self::$boxes ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the shared sheet and its tokens. Safe to call more than once,
	 * and from Pro for a screen it owns part of.
	 */
	public static function enqueue_assets() {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, PERDITA_CORE_URL . 'assets/css/perdita-admin.css', array(), PERDITA_CORE_VERSION );
		}
		if ( ! wp_styles()->get_data( self::HANDLE, 'after' ) ) {
			wp_add_inline_style( self::HANDLE, self::tokens_css() );
		}
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * The brand values: the theme's preset when the theme is active, else
	 * FALLBACK.
	 *
	 * @return array<string,string|int>
	 */
	public static function tokens() {
		$t = self::FALLBACK;
		if ( class_exists( 'Perdita_Settings' ) && method_exists( 'Perdita_Settings', 'theme_preset' ) ) {
			$preset = Perdita_Settings::theme_preset();
			foreach ( array_keys( $t ) as $k ) {
				if ( isset( $preset['colors'][ $k ] ) ) {
					$t[ $k ] = $preset['colors'][ $k ];
				}
			}
			$defaults = ( new Perdita_Settings() )->defaults();
			if ( isset( $defaults['layout']['radius'] ) ) {
				$t['radius'] = (int) $defaults['layout']['radius'];
			}
		}
		return $t;
	}

	/**
	 * The custom properties the sheet reads, as one rule.
	 *
	 * @param array|null $t Tokens, default tokens().
	 * @return string
	 */
	public static function tokens_css( $t = null ) {
		$t   = null === $t ? self::tokens() : $t;
		$hex = function ( $v, $fallback ) {
			$v = sanitize_hex_color( (string) $v );
			return $v ? strtolower( $v ) : $fallback;
		};
		$rgb = function ( $h ) {
			$h = ltrim( $h, '#' );
			if ( 3 === strlen( $h ) ) {
				$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
			}
			return hexdec( substr( $h, 0, 2 ) ) . ', ' . hexdec( substr( $h, 2, 2 ) ) . ', ' . hexdec( substr( $h, 4, 2 ) );
		};
		$f       = self::FALLBACK;
		$primary = $hex( $t['primary'], $f['primary'] );
		$hover   = $hex( $t['link_hover'], $f['link_hover'] );
		$props   = array(
			'--perdita-ui-primary'           => $primary,
			'--perdita-ui-primary-rgb'       => $rgb( $primary ),
			'--perdita-ui-primary-hover'     => $hover,
			'--perdita-ui-primary-hover-rgb' => $rgb( $hover ),
			'--perdita-ui-accent'            => $hex( $t['accent'], $f['accent'] ),
			'--perdita-ui-heading'           => $hex( $t['heading'], $f['heading'] ),
			'--perdita-ui-text'              => $hex( $t['text'], $f['text'] ),
			'--perdita-ui-muted'             => $hex( $t['muted'], $f['muted'] ),
			'--perdita-ui-bg'                => $hex( $t['background'], $f['background'] ),
			'--perdita-ui-surface'           => $hex( $t['surface'], $f['surface'] ),
			'--perdita-ui-border'            => $hex( $t['border'], $f['border'] ),
			'--perdita-ui-radius'            => max( 0, (int) $t['radius'] ) . 'px',
		);
		$css = '';
		foreach ( $props as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		// On the content area, not the body, so the admin menu and toolbar
		// don't even inherit the properties.
		return '.' . self::BODY_CLASS . ' #wpbody-content,.' . self::WRAPPER_CLASS . '{' . $css . '}';
	}

	/**
	 * admin_body_class: mark a Perdita screen.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		if ( self::is_screen() && ! preg_match( '/(^|\s)' . self::BODY_CLASS . '(\s|$)/', (string) $classes ) ) {
			$classes .= ' ' . self::BODY_CLASS;
		}
		return $classes;
	}

	/**
	 * add_meta_boxes / do_meta_boxes: give every Perdita meta box on this
	 * screen the wrapper class.
	 */
	public static function mark_meta_boxes() {
		global $wp_meta_boxes;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $wp_meta_boxes[ $screen->id ] ) || ! is_array( $wp_meta_boxes[ $screen->id ] ) ) {
			return;
		}
		foreach ( $wp_meta_boxes[ $screen->id ] as $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( ! is_array( $box ) || isset( self::$boxes[ $id ] ) || 0 !== strpos( (string) $id, 'perdita' ) ) {
						continue;
					}
					self::$boxes[ $id ] = true;
					add_filter( 'postbox_classes_' . $screen->id . '_' . $id, array( __CLASS__, 'postbox_class' ) );
				}
			}
		}
	}

	/**
	 * postbox_classes_{screen}_{id}: add the wrapper class.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public static function postbox_class( $classes ) {
		$classes   = (array) $classes;
		$classes[] = self::WRAPPER_CLASS;
		return array_values( array_unique( $classes ) );
	}
}
