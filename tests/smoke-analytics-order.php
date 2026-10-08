<?php
/**
 * Smoke fragment: the rendered page puts Consent Mode's default ahead of every
 * gtag('config') on every path (0.19.6-beta).
 *
 * config sends the page_view, so a config that runs before the consent
 * default, or under a default that does not yet carry the visitor's stored
 * choice, sends a page_view GA4 will not report. Nonprofit Manager's site had
 * exactly that bug (nonprofit-manager-site PR #4). This renders the real
 * wp_head and wp_footer for each settings path, with a Site Kit style tag of
 * another plugin on the page, checks the emitted order, then runs the page's
 * inline scripts in node with no choice, a stored Accept and a stored Decline,
 * and reads the dataLayer in the order GA4 would.
 *
 * Runs inside tests/smoke.php (shares $ok, $pass, $fail).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Perdita_Analytics' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/modules/analytics/class-perdita-analytics.php';
}

global $wp_filter, $wp_scripts, $wp_styles;
// wp_print_styles is reset too: core hooks its deprecated print_emoji_styles
// there, and only wp_enqueue_emoji_styles on wp_enqueue_scripts (reset here)
// unhooks it, so leaving it would log a core deprecation on every render.
$__ao_hooks   = array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_styles', 'wp_print_footer_scripts' );
$__ao_saved   = array();
foreach ( $__ao_hooks as $__ao_hook ) {
	$__ao_saved[ $__ao_hook ] = isset( $wp_filter[ $__ao_hook ] ) ? $wp_filter[ $__ao_hook ] : null;
}
$__ao_scripts = $wp_scripts;
$__ao_styles  = $wp_styles;
$__ao_option  = get_option( Perdita_Analytics::OPTION );
$__ao_node    = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
$__ao_tmp     = wp_tempnam( 'perdita-analytics-order' );

// Render one page: only WordPress's own script printing, this module, and an
// optional tag from another plugin, registered the way Site Kit registers its
// gtag (the loader in the head plus an inline config after it).
$__ao_render = static function ( array $settings, $third_party ) use ( $__ao_hooks ) {
	global $wp_filter, $wp_scripts, $wp_styles;
	foreach ( $__ao_hooks as $hook ) {
		unset( $wp_filter[ $hook ] );
	}
	$wp_scripts = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$wp_styles  = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	update_option( Perdita_Analytics::OPTION, $settings );
	add_action( 'wp_head', 'wp_enqueue_scripts', 1 );
	add_action( 'wp_head', 'wp_print_styles', 8 );
	add_action( 'wp_head', 'wp_print_head_scripts', 9 );
	add_action( 'wp_footer', 'wp_print_footer_scripts', 20 );
	add_action( 'wp_print_footer_scripts', '_wp_footer_scripts' );
	new Perdita_Analytics( perdita() );
	if ( $third_party ) {
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_script( 'google_gtagjs', 'https://www.googletagmanager.com/gtag/js?id=GT-SMOKE1', array(), null, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
				wp_add_inline_script( 'google_gtagjs', 'window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}' . "\n" . 'gtag("js", new Date());' . "\n" . 'gtag("config", "GT-SMOKE1");' );
			}
		);
	}
	ob_start();
	do_action( 'wp_head' );
	$head = (string) ob_get_clean();
	ob_start();
	do_action( 'wp_footer' );
	$foot = (string) ob_get_clean();
	return array( $head, $foot );
};

$__ao_paths = array(
	'opt-in, own ID, banner'                     => array( array( 'measurement_id' => 'G-SMOKE123' ), false ),
	'opt-in, own ID, no banner'                  => array( array( 'measurement_id' => 'G-SMOKE123', 'show_banner' => false ), false ),
	'opt-out, own ID, banner'                    => array( array( 'measurement_id' => 'G-SMOKE123', 'consent_model' => 'opt_out' ), false ),
	'opt-in, own ID and another plugin\'s tag'   => array( array( 'measurement_id' => 'G-SMOKE123' ), true ),
	'opt-in, consent only, another plugin\'s tag' => array( array( 'manage_without_id' => true ), true ),
	'opt-out, consent only, another plugin\'s tag, no banner (hsatracker.org)' => array( array( 'manage_without_id' => true, 'consent_model' => 'opt_out', 'show_banner' => false ), true ),
);

foreach ( $__ao_paths as $__ao_label => $__ao_path ) {
	list( $__ao_settings, $__ao_third ) = $__ao_path;
	list( $__ao_head, $__ao_foot )      = $__ao_render( $__ao_settings, $__ao_third );
	// Positions are read from the code only: a comment that names
	// gtag('config') is not a call.
	$__ao_page    = (string) preg_replace( array( '#/\*.*?\*/#s', '#^[ \t]*//[^\n]*#m' ), '', $__ao_head . $__ao_foot );
	$__ao_head_n  = strlen( (string) preg_replace( array( '#/\*.*?\*/#s', '#^[ \t]*//[^\n]*#m' ), '', $__ao_head ) );
	$__ao_boot    = strpos( $__ao_page, '<!-- Perdita Analytics: Google Consent Mode v2 -->' );
	$__ao_default = strpos( $__ao_page, "gtag( 'consent', 'default'" );
	$__ao_own_id  = ! empty( $__ao_settings['measurement_id'] );
	$__ao_banner  = ! isset( $__ao_settings['show_banner'] ) || $__ao_settings['show_banner'];

	$ok( false !== $__ao_boot && 1 === substr_count( $__ao_page, '<!-- Perdita Analytics: Google Consent Mode v2 -->' ) && 1 === substr_count( $__ao_page, "gtag( 'consent', 'default'" ) && $__ao_default < $__ao_head_n, "analytics order, $__ao_label: one consent bootstrap, printed in the head" );
	$ok( false === strpos( $__ao_head, '* Perdita Analytics: Google Consent Mode v2.' ), "analytics order, $__ao_label: the bootstrap's developer docblock stays out of the page" );
	$__ao_first_config = false;
	if ( preg_match_all( '/gtag\(\s*[\'"]config[\'"]/', $__ao_page, $__ao_m, PREG_OFFSET_CAPTURE ) ) {
		$__ao_first_config = $__ao_m[0][0][1];
	}
	$__ao_loader = strpos( $__ao_page, 'googletagmanager.com/gtag/js' );
	$ok(
		( false === $__ao_first_config || $__ao_default < $__ao_first_config ) && ( false === $__ao_loader || $__ao_default < $__ao_loader ),
		"analytics order, $__ao_label: the consent default comes before every gtag(config) and every gtag.js loader in the page"
	);
	$ok( false === strpos( $__ao_head, "'consent', 'update'" ), "analytics order, $__ao_label: the head sends no consent update on load, the default carries the stored choice" );
	$ok( $__ao_banner === ( false !== strpos( $__ao_foot, 'id="perdita-consent-banner"' ) ), "analytics order, $__ao_label: the banner prints only when it is switched on" );
	$ok( $__ao_own_id === ( false !== strpos( $__ao_page, 'gtag/js?id=G-SMOKE123' ) ), "analytics order, $__ao_label: Perdita loads gtag.js only for its own ID" );

	if ( '' === $__ao_node ) {
		continue;
	}
	file_put_contents( $__ao_tmp, $__ao_head . $__ao_foot ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$__ao_opt_out = isset( $__ao_settings['consent_model'] ) && 'opt_out' === $__ao_settings['consent_model'];
	foreach ( array( '' => $__ao_opt_out, 'perdita_consent=granted' => true, 'perdita_consent=denied' => false ) as $__ao_cookie => $__ao_granted ) {
		$__ao_json  = (string) shell_exec( escapeshellarg( $__ao_node ) . ' ' . escapeshellarg( PERDITA_CORE_DIR . 'tests/analytics-page-order.mjs' ) . ' ' . escapeshellarg( $__ao_tmp ) . ' ' . escapeshellarg( $__ao_cookie ) . ' 2>&1' );
		$__ao_run   = json_decode( $__ao_json, true );
		$__ao_calls = is_array( $__ao_run ) && isset( $__ao_run['calls'] ) ? $__ao_run['calls'] : array();
		$__ao_def_i = null;
		$__ao_cfg   = array();
		$__ao_upd   = 0;
		foreach ( $__ao_calls as $__ao_i => $__ao_c ) {
			if ( 'consent' === $__ao_c[0] && 'default' === $__ao_c[1] && null === $__ao_def_i ) {
				$__ao_def_i = $__ao_i;
			}
			if ( 'consent' === $__ao_c[0] && 'update' === $__ao_c[1] ) {
				$__ao_upd++;
			}
			if ( 'config' === $__ao_c[0] ) {
				$__ao_cfg[ $__ao_i ] = $__ao_c[1];
			}
		}
		$__ao_expect_cfg = array_values( array_filter( array( $__ao_own_id ? 'G-SMOKE123' : '', $__ao_third ? 'GT-SMOKE1' : '' ) ) );
		$__ao_state      = null === $__ao_def_i ? '' : $__ao_calls[ $__ao_def_i ][2]['analytics_storage'];
		$__ao_cookie_lbl = '' === $__ao_cookie ? 'no stored choice' : $__ao_cookie;
		$ok(
			0 === $__ao_def_i
			&& array() !== $__ao_cfg && min( array_keys( $__ao_cfg ) ) > $__ao_def_i
			&& $__ao_expect_cfg === array_values( $__ao_cfg )
			&& 0 === $__ao_upd
			&& ( $__ao_granted ? 'granted' : 'denied' ) === $__ao_state,
			"analytics order, $__ao_label, $__ao_cookie_lbl: executed in page order, the consent default runs first, carries analytics_storage " . ( $__ao_granted ? 'granted' : 'denied' ) . ', and every config runs after it' . ( 0 === $__ao_def_i && 0 === $__ao_upd ? '' : "\n" . $__ao_json )
		);
	}
}
if ( '' === $__ao_node ) {
	echo "SKIP  analytics order: executing the rendered page (node not on PATH)\n";
}

// Put everything back exactly as it was.
foreach ( $__ao_saved as $__ao_hook => $__ao_value ) {
	if ( null === $__ao_value ) {
		unset( $wp_filter[ $__ao_hook ] );
	} else {
		$wp_filter[ $__ao_hook ] = $__ao_value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
$wp_scripts = $__ao_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_styles  = $__ao_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
if ( false === $__ao_option ) {
	delete_option( Perdita_Analytics::OPTION );
} else {
	update_option( Perdita_Analytics::OPTION, $__ao_option );
}
wp_delete_file( $__ao_tmp );
