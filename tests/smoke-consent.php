<?php
/**
 * Smoke fragment: the Analytics module's consent layer as the replacement for
 * the standalone Simple Consent Manager plugin (0.19.4-alpha).
 *
 * Covers the PHP half: opt-in and opt-out settings and what reaches the
 * bootstrap, consent-only operation with no measurement ID, accent color
 * validation, the privacy link, the message filters, the Cookie Preferences
 * shortcode, cache safety (the markup never depends on the visitor's cookie),
 * and the SCM hand-over (settings adoption, hook removal, admin notice). The
 * browser half (defaults, GPC in opt-out, the scm_consent migration, the
 * banner's controls) is tests/analytics-consent.test.mjs, which smoke.php
 * runs through node.
 *
 * Runs inside smoke.php's scope, so $ok is available. Every option and global
 * it touches is restored at the end.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once PERDITA_CORE_DIR . 'inc/modules/analytics/class-perdita-analytics.php';

$__cs_orig   = get_option( Perdita_Analytics::OPTION );
$__cs_cookie = $_COOKIE;
$__cs_user   = get_current_user_id();
$__cs        = ( new ReflectionClass( 'Perdita_Analytics' ) )->newInstanceWithoutConstructor();
$__cs_set    = static function ( $value ) {
	update_option( Perdita_Analytics::OPTION, $value );
};
$__cs_head   = static function () use ( $__cs ) {
	ob_start();
	$__cs->print_consent_bootstrap();
	return (string) ob_get_clean();
};
$__cs_foot   = static function () use ( $__cs ) {
	ob_start();
	$__cs->print_banner();
	return (string) ob_get_clean();
};
$__cs_cfg    = static function ( $out ) {
	return preg_match( '/window\.perditaAnalytics = (\{[^;]*\});/', $out, $m ) ? json_decode( $m[1], true ) : null;
};
$__cs_scm_on = static function () {
	return true;
};

// --- consent model: opt-in stays the default for new installs --------------
delete_option( Perdita_Analytics::OPTION );
$__cs_s = $__cs->get_settings();
$ok( Perdita_Analytics::MODEL_OPT_IN === $__cs_s['consent_model'], 'consent: a new install is opt-in' );
$ok( false === $__cs_s['manage_without_id'] && false === $__cs_s['ad_signals'], 'consent: consent-only operation and ad signals are off by default' );
$ok( true === $__cs_s['show_preferences_link'] && '' === $__cs_s['accent_color'], 'consent: the preferences button is on and the accent color blank by default' );
$ok( 'Decline' === $__cs_s['decline_label'], 'consent: opt-in declines with "Decline"' );

$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'consent_model' => 'opt_out' ) );
$__cs_c = $__cs_cfg( $__cs_head() );
$ok( is_array( $__cs_c ) && 'opt_out' === $__cs_c['model'] && 'G-SMOKE1' === $__cs_c['id'] && false === $__cs_c['adSignals'], 'consent: the opt-out model reaches the bootstrap config' );
$__cs_s = $__cs->get_settings();
$ok( Perdita_Analytics::default_message( 'opt_out' ) === $__cs_s['banner_message'] && 'Opt out' === $__cs_s['decline_label'], 'consent: opt-out gets its own default message and an "Opt out" button' );

$__cs_set(
	array(
		'consent_model'  => 'opt_out',
		'banner_message' => Perdita_Analytics::default_message( 'opt_in' ),
		'decline_label'  => 'Decline',
	)
);
$__cs_s = $__cs->get_settings();
$ok( Perdita_Analytics::default_message( 'opt_out' ) === $__cs_s['banner_message'] && 'Opt out' === $__cs_s['decline_label'], 'consent: a saved, unedited opt-in default follows a switch to opt-out' );
$__cs_set( array( 'consent_model' => 'opt_out', 'decline_label' => 'No thanks' ) );
$ok( 'No thanks' === $__cs->get_settings()['decline_label'], 'consent: a customized decline label survives a model switch' );
$__cs_set( array( 'consent_model' => '<script>' ) );
$ok( 'opt_in' === $__cs->get_settings()['consent_model'], 'consent: an unknown stored model reads as opt-in' );
$ok( 'opt_in' === Perdita_Analytics::sanitize( array( 'consent_model' => 'evil' ) )['consent_model'], 'consent: sanitize() stores only a known model' );
$ok( 'opt_out' === Perdita_Analytics::sanitize( array( 'consent_model' => 'opt_out' ) )['consent_model'], 'consent: sanitize() keeps opt-out' );
$__cs_clean = Perdita_Analytics::sanitize( array( 'consent_model' => 'opt_out', 'ad_signals' => '1', 'manage_without_id' => '1', 'preferences_label' => '<b>Prefs</b>' ) );
$ok( true === $__cs_clean['ad_signals'] && true === $__cs_clean['manage_without_id'] && 'Prefs' === $__cs_clean['preferences_label'], 'consent: sanitize() keeps the new toggles and strips tags from the label' );
$ok( 'Opt out' === $__cs_clean['decline_label'], 'consent: sanitize() fills a blank decline label with the model default' );

// --- consent-only operation (no measurement ID) ----------------------------
$__cs_set( array( 'measurement_id' => '', 'manage_without_id' => true, 'consent_model' => 'opt_out' ) );
$__cs_h = $__cs_head();
$__cs_c = $__cs_cfg( $__cs_h );
$ok( $__cs->consent_active(), 'consent-only: the consent layer is active with no measurement ID' );
$ok( is_array( $__cs_c ) && '' === $__cs_c['id'] && 'opt_out' === $__cs_c['model'], 'consent-only: the bootstrap prints with an empty id' );
$ok( false !== strpos( $__cs_h, 'data-cfasync="false"' ), 'consent: the bootstrap opts out of Rocket Loader deferral' );
wp_dequeue_script( 'perdita-gtag' );
wp_deregister_script( 'perdita-gtag' );
$__cs->enqueue_gtag();
$ok( ! wp_script_is( 'perdita-gtag', 'registered' ), 'consent-only: gtag.js is not loaded without a measurement ID' );
$__cs_f = $__cs_foot();
$ok( false !== strpos( $__cs_f, 'id="perdita-consent-banner"' ) && false !== strpos( $__cs_f, 'id="perdita-consent-prefs"' ), 'consent-only: the banner and the preferences button print' );
$__cs->enqueue_assets();
$ok( wp_script_is( 'perdita-analytics-banner', 'enqueued' ) && wp_style_is( 'perdita-analytics-banner', 'enqueued' ), 'consent-only: the banner assets load' );
wp_dequeue_script( 'perdita-analytics-banner' );
wp_dequeue_style( 'perdita-analytics-banner' );
wp_deregister_style( 'perdita-analytics-banner' );

$__cs_set( array( 'measurement_id' => '' ) );
$ok( '' === $__cs_head() && '' === $__cs_foot(), 'consent: no ID and consent-only off prints nothing (sites rely on a blank ID to switch the module off)' );
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'show_banner' => false ) );
$ok( '' !== $__cs_head() && '' === $__cs_foot(), 'consent: banner off keeps the consent defaults but prints no banner' );
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'show_preferences_link' => false ) );
$ok( false === strpos( $__cs_foot(), 'perdita-consent-prefs' ), 'consent: the preferences button can be switched off' );

// --- accent color -----------------------------------------------------------
$ok( '#2e6b29' === Perdita_Analytics::sanitize_accent_color( '#2E6B29' ), 'accent: a six-digit hex is kept, lower-cased' );
$ok( '#abc' === Perdita_Analytics::sanitize_accent_color( ' #ABC ' ), 'accent: a three-digit hex is kept' );
$__cs_bad = array( '', 'red', '#12', '#1234', '#12345g', '2e6b29', '#2e6b29;}</style><script>x</script>', '#fff;background:url(x)', 'var(--x)', array( '#fff' ), null, 123 );
$__cs_all_bad = true;
foreach ( $__cs_bad as $__cs_b ) {
	if ( '' !== Perdita_Analytics::sanitize_accent_color( $__cs_b ) ) {
		$__cs_all_bad = false;
	}
}
$ok( $__cs_all_bad, 'accent: names, short or long hex, injection and non-strings are refused' );
$ok( '' === Perdita_Analytics::sanitize( array( 'accent_color' => '#fff;}body{display:none' ) )['accent_color'], 'accent: sanitize() stores nothing for an invalid color' );
$ok( '#ffffff' === Perdita_Analytics::accent_text_color( '#2e6b29' ) && '#111111' === Perdita_Analytics::accent_text_color( '#ff0' ), 'accent: button text picks white on dark and near-black on light' );

$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'accent_color' => '#2e6b29' ) );
$__cs->enqueue_assets();
$__cs_inline = implode( '', (array) wp_styles()->get_data( 'perdita-analytics-banner', 'after' ) );
$ok( false !== strpos( $__cs_inline, '--perdita-consent-accent:#2e6b29;--perdita-consent-accent-text:#ffffff;' ), 'accent: the color reaches the banner stylesheet' );
wp_dequeue_script( 'perdita-analytics-banner' );
wp_dequeue_style( 'perdita-analytics-banner' );
wp_deregister_style( 'perdita-analytics-banner' );

// Stored straight into the option, past sanitize(), the way a bad import
// would: it still never reaches the page.
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'accent_color' => 'red;}</style><script>x</script>' ) );
$__cs_scm_color = defined( 'SCM_ACCENT_COLOR' ) ? Perdita_Analytics::sanitize_accent_color( SCM_ACCENT_COLOR ) : '';
$ok( $__cs_scm_color === $__cs->accent_color(), 'accent: an invalid stored color is ignored' );

if ( ! defined( 'SCM_ACCENT_COLOR' ) ) {
	define( 'SCM_ACCENT_COLOR', '#1D4ED8' );
}
$__cs_scm_color = Perdita_Analytics::sanitize_accent_color( SCM_ACCENT_COLOR );
$__cs_set( array( 'measurement_id' => 'G-SMOKE1' ) );
$ok( '' !== $__cs_scm_color && $__cs_scm_color === $__cs->accent_color(), 'accent: a blank setting falls back to SCM_ACCENT_COLOR' );
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'accent_color' => '#123456' ) );
$ok( '#123456' === $__cs->accent_color(), 'accent: the setting outranks SCM_ACCENT_COLOR' );

// --- cache safety: the markup never depends on the visitor's cookie ---------
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'consent_model' => 'opt_out' ) );
unset( $_COOKIE['perdita_consent'], $_COOKIE['scm_consent'] );
$__cs_none = $__cs_head() . $__cs_foot();
$_COOKIE['perdita_consent'] = 'granted';
$__cs_yes = $__cs_head() . $__cs_foot();
$_COOKIE['perdita_consent'] = 'denied';
$_COOKIE['scm_consent']     = 'declined';
$__cs_no = $__cs_head() . $__cs_foot();
$ok( $__cs_none === $__cs_yes && $__cs_yes === $__cs_no, 'cache: head and footer markup are identical whatever consent cookie the request carries' );
$ok( 1 === preg_match( '/<div id="perdita-consent-banner"[^>]* hidden>/', $__cs_none ) && 1 === preg_match( '/<button[^>]*id="perdita-consent-prefs"[^>]* hidden>/', $__cs_none ), 'cache: the banner and the preferences button start hidden, the browser decides' );
$_COOKIE = $__cs_cookie;

// --- privacy link -----------------------------------------------------------
$__cs_privacy = static function () {
	return 'https://example.test/privacy/?a=1&b="2"';
};
add_filter( 'perdita_consent_privacy_url', $__cs_privacy );
$__cs_f = $__cs_foot();
$ok( 1 === preg_match( '#<a class="perdita-consent-banner__link" href="https://example\.test/privacy/\?a=1&(amp;|\#038;)b=[^"]*">Privacy Policy</a>#', $__cs_f ), 'privacy: the banner links to the privacy policy, escaped' );
remove_filter( 'perdita_consent_privacy_url', $__cs_privacy );
$__cs_js_url = static function () {
	return 'javascript:alert(1)';
};
add_filter( 'perdita_consent_privacy_url', $__cs_js_url );
$ok( false === strpos( $__cs_foot(), 'perdita-consent-banner__link' ), 'privacy: a javascript: URL drops the link' );
remove_filter( 'perdita_consent_privacy_url', $__cs_js_url );
add_filter( 'perdita_consent_privacy_url', '__return_empty_string' );
$ok( false === strpos( $__cs_foot(), 'perdita-consent-banner__link' ), 'privacy: no privacy page means no link' );
remove_filter( 'perdita_consent_privacy_url', '__return_empty_string' );

// --- message filters, Perdita's and SCM's -----------------------------------
$__cs_seen_model = '';
$__cs_msg_p      = static function ( $message, $model ) use ( &$__cs_seen_model ) {
	$__cs_seen_model = $model;
	return $message . ' [perdita]';
};
$__cs_msg_scm    = static function ( $message ) {
	return $message . ' [scm] <img src=x onerror=alert(1)>';
};
add_filter( 'perdita_consent_message', $__cs_msg_p, 10, 2 );
add_filter( 'scm_consent_message', $__cs_msg_scm );
$__cs_f = $__cs_foot();
$ok( false !== strpos( $__cs_f, '[perdita] [scm] &lt;img' ) && 'opt_out' === $__cs_seen_model, 'message: perdita_consent_message runs, then the scm_consent_message alias, with the model passed' );
$ok( false === strpos( $__cs_f, '<img' ), 'message: filtered text is escaped' );
remove_filter( 'perdita_consent_message', $__cs_msg_p, 10 );
remove_filter( 'scm_consent_message', $__cs_msg_scm );

// --- [perdita_cookie_preferences] -------------------------------------------
$__cs_sc = $__cs->shortcode_preferences( array( 'label' => '<i>Change cookies</i>' ) );
$ok( '<a href="#perdita-cookie-preferences" class="perdita-cookie-preferences">&lt;i&gt;Change cookies&lt;/i&gt;</a>' === $__cs_sc, 'shortcode: a reopen link with an escaped label' );
$ok( false !== strpos( $__cs->shortcode_preferences( '' ), '>Cookie Preferences</a>' ), 'shortcode: the default label comes from the settings' );
$__cs_set( array( 'measurement_id' => '' ) );
$ok( '' === $__cs->shortcode_preferences( array() ), 'shortcode: nothing when the consent layer is off' );

// --- the Simple Consent Manager hand-over -----------------------------------
// SCM is simulated through the detection filter and its two hook names, so
// the rest of the run never sees SCM's functions defined.
$__cs_had_head = has_action( 'wp_head', 'scm_consent_mode_script' );
$__cs_had_foot = has_action( 'wp_footer', 'scm_consent_banner' );
$__cs_scm_hooks = static function () {
	add_action( 'wp_head', 'scm_consent_mode_script', 0 );
	add_action( 'wp_footer', 'scm_consent_banner', 5 );
};
$__cs_scm_gone = static function () {
	return false === has_action( 'wp_head', 'scm_consent_mode_script' ) && false === has_action( 'wp_footer', 'scm_consent_banner' );
};
// A real instance, constructed on this front-end request, then unhooked.
$__cs_build = static function () {
	$inst = new Perdita_Analytics( null );
	remove_action( 'wp_head', array( $inst, 'print_consent_bootstrap' ), Perdita_Analytics::HEAD_PRIORITY );
	remove_action( 'wp_enqueue_scripts', array( $inst, 'enqueue_assets' ) );
	remove_action( 'wp_enqueue_scripts', array( $inst, 'enqueue_gtag' ) );
	remove_action( 'wp_footer', array( $inst, 'print_banner' ) );
	return $inst;
};
$__cs_had_sc = shortcode_exists( 'perdita_cookie_preferences' );

$__cs_scm_hooks();
$ok( ! Perdita_Analytics::scm_active() && false === Perdita_Analytics::take_over_scm() && ! $__cs_scm_gone(), 'scm: nothing is removed while SCM is not active' );

add_filter( 'perdita_analytics_scm_active', $__cs_scm_on );

// Adoption: a site that never chose a model here takes SCM's, once, stored.
$__cs_set( array() );
$ok( true === Perdita_Analytics::maybe_adopt_scm(), 'scm: adoption stores settings on a site that never chose' );
$__cs_s = get_option( Perdita_Analytics::OPTION );
$ok( 'opt_out' === $__cs_s['consent_model'] && true === $__cs_s['manage_without_id'], 'scm: adoption keeps SCM behavior, opt-out for whatever Google tags are on the page' );
$ok( true === $__cs_s['ad_signals'], 'scm: adoption keeps SCM granting ad signals by default, so ad tags behave as before' );
$ok( Perdita_Analytics::sanitize_accent_color( SCM_ACCENT_COLOR ) === $__cs_s['accent_color'], 'scm: adoption stores SCM\'s accent color, so the banner keeps it after SCM is gone' );
$ok( false === Perdita_Analytics::maybe_adopt_scm(), 'scm: adoption runs once' );
$__cs_set( array( 'measurement_id' => 'G-OWN1' ) );
Perdita_Analytics::maybe_adopt_scm();
$__cs_s = get_option( Perdita_Analytics::OPTION );
$ok( 'opt_in' === $__cs_s['consent_model'], 'scm: a site already running its own GA4 here stays opt-in' );
$ok( false === $__cs_s['manage_without_id'] && ! array_key_exists( 'ad_signals', $__cs_s ), 'scm: a site with its own ID gets no consent-only mode and keeps its ad signal setting' );

// A filter that blanks the ID on some requests (nonprofitmanager.app does it
// on the front end) must never be saved back by adoption.
$__cs_blank = static function ( $v ) {
	if ( is_array( $v ) ) {
		$v['measurement_id'] = '';
	}
	return $v;
};
add_filter( 'option_' . Perdita_Analytics::OPTION, $__cs_blank );
$__cs_set( array( 'measurement_id' => 'G-OWN2' ) );
Perdita_Analytics::maybe_adopt_scm();
remove_filter( 'option_' . Perdita_Analytics::OPTION, $__cs_blank );
$__cs_s = get_option( Perdita_Analytics::OPTION );
$ok( 'G-OWN2' === $__cs_s['measurement_id'] && 'opt_in' === $__cs_s['consent_model'], 'scm: adoption reads the stored option, not a filtered one, so a filtered-out ID is never saved blank' );
add_filter( 'option_' . Perdita_Analytics::OPTION, $__cs_blank );
$ok( '' === get_option( Perdita_Analytics::OPTION )['measurement_id'], 'scm: the site\'s own option filter is back in place after adoption reads around it' );
remove_filter( 'option_' . Perdita_Analytics::OPTION, $__cs_blank );

// A page view never writes: adoption is hooked to admin_init only.
$__cs_set( array() );
$__cs_build();
$ok( array() === get_option( Perdita_Analytics::OPTION ), 'scm: constructing the module on a front-end request adopts nothing' );
$__cs_set( array( 'consent_model' => 'opt_in', 'manage_without_id' => false ) );
$ok( false === Perdita_Analytics::maybe_adopt_scm() && array( 'consent_model' => 'opt_in', 'manage_without_id' => false ) === get_option( Perdita_Analytics::OPTION ), 'scm: an explicit choice is never overwritten' );

// Consent layer off (explicitly): SCM keeps working, untouched.
$__cs_build();
$ok( ! $__cs_scm_gone(), 'scm: with Perdita\'s consent layer off, SCM keeps its banner and defaults' );

// Consent layer on: exactly one consent default and one banner, Perdita's.
$__cs_set( array( 'manage_without_id' => true, 'consent_model' => 'opt_out' ) );
$__cs_build();
$ok( $__cs_scm_gone(), 'scm: with Perdita\'s consent layer on, SCM\'s head script and banner are unhooked' );
$ok( false === $__cs_cfg( $__cs_head() )['scmBanner'], 'scm: with Perdita\'s banner on, the bootstrap owns the choice' );

// Perdita's banner off: SCM's banner stays, or the site would have none.
$__cs_scm_hooks();
$__cs_set( array( 'manage_without_id' => true, 'consent_model' => 'opt_out', 'show_banner' => false ) );
$__cs_build();
$ok( false === has_action( 'wp_head', 'scm_consent_mode_script' ) && 5 === has_action( 'wp_footer', 'scm_consent_banner' ), 'scm: with Perdita\'s banner off, only SCM\'s head script is unhooked and its banner stays' );
$ok( true === $__cs_cfg( $__cs_head() )['scmBanner'], 'scm: with SCM\'s banner kept, the bootstrap reads scm_consent as the live choice' );
$__cs_scm_hooks();
$__cs_set( array( 'measurement_id' => 'G-SMOKE1', 'manage_without_id' => false, 'consent_model' => 'opt_in' ) );
$__cs_build();
$ok( $__cs_scm_gone(), 'scm: a measurement ID alone also takes over' );
$ok( shortcode_exists( 'perdita_cookie_preferences' ), 'shortcode: registered on the front end' );
$__cs_inst = new Perdita_Analytics( null );
$ok( 0 === has_action( 'wp_head', array( $__cs_inst, 'print_consent_bootstrap' ) ), 'consent: the bootstrap prints at wp_head 0, as early as SCM did and before any enqueued head script' );
remove_action( 'wp_head', array( $__cs_inst, 'print_consent_bootstrap' ), Perdita_Analytics::HEAD_PRIORITY );
remove_action( 'wp_enqueue_scripts', array( $__cs_inst, 'enqueue_assets' ) );
remove_action( 'wp_enqueue_scripts', array( $__cs_inst, 'enqueue_gtag' ) );
remove_action( 'wp_footer', array( $__cs_inst, 'print_banner' ) );

// The admin notice says SCM can go, on the Plugins screen.
require_once PERDITA_CORE_DIR . 'inc/modules/analytics/class-perdita-analytics-admin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
// get_current_screen() only returns a WP_Screen, and its constructor is private.
$__cs_screen = static function ( $id ) {
	$screen     = ( new ReflectionClass( 'WP_Screen' ) )->newInstanceWithoutConstructor();
	$screen->id = $id;
	return $screen;
};
$__cs_admin_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( ! empty( $__cs_admin_ids ) ) {
	wp_set_current_user( (int) $__cs_admin_ids[0] );
	$__cs_admin = ( new ReflectionClass( 'Perdita_Analytics_Admin' ) )->newInstanceWithoutConstructor();
	$__cs_main  = new ReflectionProperty( 'Perdita_Analytics_Admin', 'main' );
	if ( PHP_VERSION_ID < 80100 ) {
		$__cs_main->setAccessible( true );
	}
	$__cs_main->setValue( $__cs_admin, $__cs );
	$__cs_screen_before       = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
	$GLOBALS['current_screen'] = $__cs_screen( 'plugins' );
	ob_start();
	$__cs_admin->scm_notice();
	$__cs_notice = (string) ob_get_clean();
	$ok( false !== strpos( $__cs_notice, 'You can deactivate Simple Consent Manager' ), 'scm: the Plugins screen says SCM can be deactivated' );
	$__cs_keep = get_option( Perdita_Analytics::OPTION );
	$__cs_set( array( 'manage_without_id' => true, 'show_banner' => false ) );
	ob_start();
	$__cs_admin->scm_notice();
	$__cs_notice = (string) ob_get_clean();
	$ok( false !== strpos( $__cs_notice, 'its banner is off' ) && false === strpos( $__cs_notice, 'You can deactivate' ), 'scm: with Perdita\'s banner off, the notice says turn it on before deactivating SCM' );
	$__cs_set( $__cs_keep );
	$__cs_set( array( 'measurement_id' => '', 'manage_without_id' => false ) );
	ob_start();
	$__cs_admin->scm_notice();
	$ok( '' === (string) ob_get_clean(), 'scm: no takeover, no Plugins-screen notice' );
	$GLOBALS['current_screen'] = $__cs_screen( 'perdita_page_perdita-analytics' );
	ob_start();
	$__cs_admin->scm_notice();
	$ok( false !== strpos( (string) ob_get_clean(), 'still handling cookie consent' ), 'scm: the settings screen says SCM is still in charge and how to switch' );
	remove_filter( 'perdita_analytics_scm_active', $__cs_scm_on );
	ob_start();
	$__cs_admin->scm_notice();
	$ok( '' === (string) ob_get_clean(), 'scm: no notice when SCM is not active' );
	$GLOBALS['current_screen'] = $__cs_screen_before;
	wp_set_current_user( $__cs_user );
} else {
	echo "SKIP  scm admin notice (no administrator on this site)\n";
}

// --- restore ------------------------------------------------------------------
remove_filter( 'perdita_analytics_scm_active', $__cs_scm_on );
remove_action( 'wp_head', 'scm_consent_mode_script', 0 );
remove_action( 'wp_footer', 'scm_consent_banner', 5 );
if ( false !== $__cs_had_head ) {
	add_action( 'wp_head', 'scm_consent_mode_script', $__cs_had_head );
}
if ( false !== $__cs_had_foot ) {
	add_action( 'wp_footer', 'scm_consent_banner', $__cs_had_foot );
}
if ( ! $__cs_had_sc ) {
	remove_shortcode( 'perdita_cookie_preferences' );
}
$_COOKIE = $__cs_cookie;
if ( false === $__cs_orig ) {
	delete_option( Perdita_Analytics::OPTION );
} else {
	update_option( Perdita_Analytics::OPTION, $__cs_orig );
}
