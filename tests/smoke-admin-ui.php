<?php
/**
 * Perdita Core smoke tests: the shared admin stylesheet.
 *
 * - Every --perdita-ui-* fallback in assets/css/perdita-admin.css matches the
 *   theme's Perdita_Settings::theme_preset() (through FALLBACK when the theme
 *   isn't active), and the theme's copy of the sheet is byte-identical.
 * - Every rule sits under the Perdita wrapper, so nothing restyles the rest
 *   of wp-admin.
 * - The sheet loads only on Perdita screens and screens with a Perdita meta
 *   box, and the body class only lands on Perdita screens.
 * - No Perdita admin CSS file in Core carries a WordPress admin blue.
 *
 * Loaded by tests/smoke.php.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- admin look (tests/smoke-admin-ui.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/class-perdita-core-admin-ui.php';

$aui_css_file = PERDITA_CORE_DIR . 'assets/css/perdita-admin.css';
$aui_css      = (string) file_get_contents( $aui_css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( '' !== $aui_css, 'admin ui: assets/css/perdita-admin.css exists' );

// 1. Token drift. The preset when the theme is loaded, else Core's copy.
$aui_preset = Perdita_Core_Admin_UI::FALLBACK;
if ( class_exists( 'Perdita_Settings' ) && method_exists( 'Perdita_Settings', 'theme_preset' ) ) {
	$aui_theme = Perdita_Settings::theme_preset();
	$aui_drift = array();
	foreach ( Perdita_Core_Admin_UI::FALLBACK as $k => $v ) {
		if ( 'radius' === $k ) {
			$aui_d = ( new Perdita_Settings() )->defaults();
			if ( (int) $aui_d['layout']['radius'] !== (int) $v ) {
				$aui_drift[] = $k;
			}
		} elseif ( ! isset( $aui_theme['colors'][ $k ] ) || strtolower( $aui_theme['colors'][ $k ] ) !== strtolower( $v ) ) {
			$aui_drift[] = $k;
		}
	}
	$ok( ! $aui_drift, 'admin ui: Perdita_Core_Admin_UI::FALLBACK matches theme_preset() (drift: ' . implode( ', ', $aui_drift ) . ')' );
}
$aui_rgb  = function ( $h ) {
	$h = ltrim( $h, '#' );
	return hexdec( substr( $h, 0, 2 ) ) . ', ' . hexdec( substr( $h, 2, 2 ) ) . ', ' . hexdec( substr( $h, 4, 2 ) );
};
$aui_want = array(
	'primary'           => $aui_preset['primary'],
	'primary-rgb'       => $aui_rgb( $aui_preset['primary'] ),
	'primary-hover'     => $aui_preset['link_hover'],
	'primary-hover-rgb' => $aui_rgb( $aui_preset['link_hover'] ),
	'accent'            => $aui_preset['accent'],
	'heading'           => $aui_preset['heading'],
	'text'              => $aui_preset['text'],
	'muted'             => $aui_preset['muted'],
	'bg'                => $aui_preset['background'],
	'surface'           => $aui_preset['surface'],
	'border'            => $aui_preset['border'],
	'radius'            => (int) $aui_preset['radius'] . 'px',
	// Not brand colors: WordPress's destructive red, kept from the builder.
	'danger'            => '#b32d2e',
	'danger-hover'      => '#8a2424',
);
preg_match_all( '/var\(\s*--perdita-ui-([a-z-]+)\s*,\s*([^)]+?)\s*\)/', $aui_css, $aui_m, PREG_SET_ORDER );
$aui_bad = array();
foreach ( $aui_m as $aui_one ) {
	if ( ! isset( $aui_want[ $aui_one[1] ] ) ) {
		$aui_bad[] = 'unknown --perdita-ui-' . $aui_one[1];
	} elseif ( strtolower( trim( $aui_one[2] ) ) !== strtolower( (string) $aui_want[ $aui_one[1] ] ) ) {
		$aui_bad[] = '--perdita-ui-' . $aui_one[1] . ' falls back to ' . $aui_one[2];
	}
}
$ok( count( $aui_m ) > 40 && ! $aui_bad, 'admin ui: every var(--perdita-ui-*) fallback in the sheet equals the preset (' . count( $aui_m ) . ' checked' . ( $aui_bad ? ': ' . implode( '; ', array_slice( $aui_bad, 0, 5 ) ) : '' ) . ')' );

// The inline tokens printed at enqueue time carry the same values.
$aui_tok = Perdita_Core_Admin_UI::tokens_css();
$aui_mis = array();
foreach ( array( 'primary', 'primary-rgb', 'primary-hover', 'accent', 'heading', 'text', 'muted', 'bg', 'surface', 'border', 'radius' ) as $k ) {
	if ( false === strpos( $aui_tok, '--perdita-ui-' . $k . ':' . $aui_want[ $k ] . ';' ) ) {
		$aui_mis[] = $k;
	}
}
$ok( ! $aui_mis, 'admin ui: tokens_css() prints every token from the preset (' . implode( ', ', $aui_mis ) . ')' );
$ok( 0 === strpos( $aui_tok, '.perdita-admin-screen #wpbody-content,.perdita-admin{' ), 'admin ui: the tokens sit on the content area and the wrapper, never on body or :root' );
$ok( false === strpos( Perdita_Core_Admin_UI::tokens_css( array_merge( Perdita_Core_Admin_UI::FALLBACK, array( 'primary' => 'red;}body{color:red' ) ) ), 'body{' ), 'admin ui: a bad color can not break out of the token rule' );

// The theme's copy is byte-identical when it sits beside this plugin.
$aui_theme_css = trailingslashit( get_theme_root() ) . 'perdita/assets/css/perdita-admin.css';
if ( file_exists( $aui_theme_css ) ) {
	$ok( md5_file( $aui_theme_css ) === md5_file( $aui_css_file ), 'admin ui: the theme\'s assets/css/perdita-admin.css is byte-identical to Core\'s' );
}

// 2. Scope: every selector of every rule starts with the wrapper.
$aui_wrapper = '.wp-core-ui :where(.perdita-admin-screen #wpbody-content, .perdita-admin)';
$aui_strip   = preg_replace( '#/\*.*?\*/#s', '', $aui_css );
$aui_strip   = preg_replace( '/@media[^{]*\{/', '', $aui_strip );
preg_match_all( '/([^{}]+)\{[^{}]*\}/', $aui_strip, $aui_rules );
$aui_out  = array();
$aui_sels = 0;
foreach ( $aui_rules[1] as $aui_sel ) {
	$aui_depth = 0;
	$aui_cur   = '';
	$aui_parts = array();
	foreach ( str_split( trim( $aui_sel ) ) as $aui_ch ) {
		if ( '(' === $aui_ch ) {
			++$aui_depth;
		} elseif ( ')' === $aui_ch ) {
			--$aui_depth;
		}
		if ( ',' === $aui_ch && 0 === $aui_depth ) {
			$aui_parts[] = trim( $aui_cur );
			$aui_cur     = '';
			continue;
		}
		$aui_cur .= $aui_ch;
	}
	$aui_parts[] = trim( $aui_cur );
	foreach ( $aui_parts as $aui_p ) {
		++$aui_sels;
		if ( 0 !== strpos( $aui_p, $aui_wrapper ) ) {
			$aui_out[] = $aui_p;
		}
	}
}
$ok( $aui_sels > 50 && ! $aui_out, 'admin ui: all ' . $aui_sels . ' selectors start with the Perdita wrapper' . ( $aui_out ? ' (outside: ' . implode( ' | ', array_slice( $aui_out, 0, 3 ) ) . ')' : '' ) );

// 3. No WordPress admin blue in any Perdita admin stylesheet Core ships.
$aui_blues = '/#(2271b1|135e96|3858e9|183ad6|007cba|0073aa|2145e6|72aee6|2563eb)\b|rgba?\(\s*(34,\s*113,\s*177|19,\s*94,\s*150|56,\s*88,\s*233|24,\s*58,\s*214|0,\s*124,\s*186|0,\s*115,\s*170)\b/i';
$aui_hits  = array();
foreach ( array_merge( (array) glob( PERDITA_CORE_DIR . 'assets/css/*.css' ), (array) glob( PERDITA_CORE_DIR . 'inc/modules/*/assets/*.css' ), (array) glob( PERDITA_CORE_DIR . 'inc/modules/*/assets/css/*.css' ) ) as $aui_f ) {
	if ( preg_match( $aui_blues, (string) file_get_contents( $aui_f ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$aui_hits[] = str_replace( PERDITA_CORE_DIR, '', $aui_f );
	}
}
$ok( ! $aui_hits, 'admin ui: no Core CSS file carries a WordPress admin blue (' . implode( ', ', $aui_hits ) . ')' );

// 3b. The Caching, Security, and Switch to Perdita screens load enqueued
// sheets and print no <style> block of their own.
$aui_screens = array(
	'inc/modules/caching/class-perdita-caching-admin.php'   => 'assets/css/perdita-caching-admin.css',
	'inc/modules/security/class-perdita-security-admin.php' => 'assets/css/perdita-security-admin.css',
	'inc/switch/class-perdita-switch-admin.php'             => 'assets/css/perdita-switch.css',
);
foreach ( $aui_screens as $aui_php => $aui_sheet ) {
	$aui_src = (string) file_get_contents( PERDITA_CORE_DIR . $aui_php ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( is_file( PERDITA_CORE_DIR . $aui_sheet ) && false !== strpos( $aui_src, "'" . $aui_sheet . "'" ), 'admin ui: ' . basename( $aui_php ) . ' enqueues ' . $aui_sheet );
	$ok( false === stripos( $aui_src, '<style' ) && false === strpos( $aui_src, 'wp_add_inline_style' ) && ! preg_match( '/\sstyle=\\\\?["\']/', $aui_src ), 'admin ui: ' . basename( $aui_php ) . ' prints no <style> block, inline style, or style attribute' );
	$ok( ! preg_match( $aui_blues, $aui_src ), 'admin ui: ' . basename( $aui_php ) . ' carries no WordPress admin blue' );
	$aui_sheet_css = (string) file_get_contents( PERDITA_CORE_DIR . $aui_sheet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$aui_bad       = array();
	$aui_flat      = (string) preg_replace( array( '#/\*.*?\*/#s', '/@media[^{]*\{/' ), '', $aui_sheet_css );
	foreach ( explode( '}', $aui_flat ) as $aui_rule ) {
		if ( false === strpos( $aui_rule, '{' ) ) {
			continue;
		}
		$aui_sel = trim( substr( $aui_rule, 0, (int) strpos( $aui_rule, '{' ) ) );
		foreach ( explode( ',', $aui_sel ) as $aui_one ) {
			if ( ! preg_match( '/^\.(perdita-caching|perdita-security|perdita-switch)\b/', trim( $aui_one ) ) ) {
				$aui_bad[] = trim( $aui_one );
			}
		}
	}
	$ok( ! $aui_bad, 'admin ui: every rule in ' . $aui_sheet . ' sits under its screen wrapper (' . implode( ' | ', array_slice( $aui_bad, 0, 3 ) ) . ')' );
}

// 4. Enqueued only on Perdita screens.
global $plugin_page;
$aui_orig_page   = $plugin_page;
$aui_orig_styles = isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null;
$aui_loaded      = function ( $page ) {
	global $plugin_page;
	$GLOBALS['wp_styles'] = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$plugin_page          = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	Perdita_Core_Admin_UI::maybe_enqueue();
	return wp_style_is( Perdita_Core_Admin_UI::HANDLE, 'enqueued' );
};
$ok( $aui_loaded( 'perdita' ), 'admin ui: the sheet loads on Perdita, Dashboard' );
$ok( $aui_loaded( 'perdita-seo-titles' ), 'admin ui: the sheet loads on a Perdita SEO screen' );
$ok( $aui_loaded( 'perdita-hf-builder' ), 'admin ui: the sheet loads on a Pro screen (the header and footer builder)' );
$ok( ! $aui_loaded( '' ), 'admin ui: the sheet does not load on a screen with no Perdita page' );
$ok( ! $aui_loaded( 'akismet-key-config' ), 'admin ui: the sheet does not load on another plugin\'s page' );
$aui_loaded( 'perdita' );
Perdita_Core_Admin_UI::enqueue_assets();
$ok( 1 === substr_count( implode( '', (array) wp_styles()->get_data( Perdita_Core_Admin_UI::HANDLE, 'after' ) ), '--perdita-ui-primary:' ), 'admin ui: enqueueing twice prints the tokens once' );
$plugin_page = 'perdita'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$ok( false !== strpos( Perdita_Core_Admin_UI::body_class( 'wp-admin' ), 'perdita-admin-screen' ) && 1 === substr_count( Perdita_Core_Admin_UI::body_class( 'wp-admin perdita-admin-screen' ), 'perdita-admin-screen' ), 'admin ui: the body class lands once on a Perdita screen' );
$plugin_page = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$ok( 'wp-admin' === Perdita_Core_Admin_UI::body_class( 'wp-admin' ), 'admin ui: no body class on another screen' );
$ok( array( 'closed', 'perdita-admin' ) === Perdita_Core_Admin_UI::postbox_class( array( 'closed' ) ), 'admin ui: a Perdita meta box gets the wrapper class' );
$plugin_page = $aui_orig_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$GLOBALS['wp_styles'] = $aui_orig_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

// 5. Accessibility and phone-width fixes that axe-core and a 375px run found.
$aui_src = function ( $rel ) {
	return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
};
$aui_forms = $aui_src( 'inc/class-perdita-forms-admin.php' );
$aui_row   = substr( $aui_forms, strpos( $aui_forms, 'private function field_row(' ), 4000 );
$aui_row   = substr( $aui_row, 0, strpos( $aui_row, '</tr>' ) );
$ok( 6 === substr_count( $aui_row, 'aria-label=' ) && 6 === preg_match_all( '/<(?:input|select|button)\b/', $aui_row ), 'admin ui: every control in a form builder row has an accessible name' );
$ok( 5 === substr_count( $aui_row, 'data-label=' ) && false !== strpos( $aui_forms, 'perdita-fields perdita-stack' ), 'admin ui: the form builder stacks into labeled rows on a phone' );
$ok( false !== strpos( $aui_forms, '<label for="perdita-entries-form">' ) && false !== strpos( $aui_forms, '<select id="perdita-entries-form"' ), 'admin ui: the form picker on Form entries has a label' );
$aui_img = $aui_src( 'inc/modules/image-seo/class-perdita-image-seo-admin.php' );
$ok( false !== strpos( $aui_img, '<label for="perdita-image-seo-alt-format">' ) && false !== strpos( $aui_img, 'id="perdita-image-seo-alt-format" name="alt_format"' ) && false !== strpos( $aui_img, '<label for="perdita-image-seo-title-format">' ) && false !== strpos( $aui_img, 'id="perdita-image-seo-title-format" name="title_format"' ), 'admin ui: the Image SEO pattern fields have labels' );
$ok( (bool) preg_match( '/<pre class="perdita-code" tabindex="0" role="region" aria-label=/', $aui_src( 'inc/modules/backups/class-perdita-backups-admin.php' ) ), 'admin ui: the nginx sample on Backups scrolls with the keyboard and has a name' );
foreach ( array( 'inc/modules/indexnow/class-perdita-indexnow-admin.php', 'inc/modules/smtp/class-perdita-smtp-admin.php', 'inc/modules/sales/class-perdita-sales-admin.php' ) as $aui_file ) {
	$ok( false !== strpos( $aui_src( $aui_file ), 'perdita-stack' ) && false !== strpos( $aui_src( $aui_file ), 'data-label=' ), 'admin ui: the log or list table in ' . basename( $aui_file ) . ' stacks into labeled rows on a phone' );
}
$aui_sales = $aui_src( 'inc/modules/sales/class-perdita-sales-admin.php' );
$ok( false !== strpos( $aui_sales, 'perdita-stats' ) && false === strpos( $aui_sales, '#ccd0d4' ), 'admin ui: the sales report cards wrap and use the brand border' );
$aui_css = $aui_src( 'assets/css/perdita-admin.css' );
foreach ( array( 'table.perdita-stack td[data-label]::before', '.perdita-break', '.perdita-code', '.perdita-stats', '.perdita-stat-value' ) as $aui_sel ) {
	$ok( false !== strpos( $aui_css, $aui_sel ), 'admin ui: the shared sheet styles ' . $aui_sel );
}
$aui_cache = $aui_src( 'inc/modules/caching/class-perdita-caching-admin.php' );
$ok( substr_count( $aui_cache, '$this->guard();' ) === substr_count( $aui_cache, "check_admin_referer( '" ) && 6 === substr_count( $aui_cache, "check_admin_referer( '" ), 'admin ui: every Caching handler checks its own nonce' );
