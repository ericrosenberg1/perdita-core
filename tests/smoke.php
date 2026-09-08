<?php
/**
 * Perdita Core smoke tests.
 *
 * Integration checks that run the real plugin code against a live WordPress
 * with the Perdita theme active and this plugin activated. The harness is a
 * plain PHP script that requires wp-load.php, so it runs outside is_admin()
 * and outside WP-CLI: the shape of an ordinary front-end request, which is
 * what makes the "not built on the front end" assertions mean anything.
 *
 *   php tests/smoke.php            (from a directory inside the WordPress install)
 *   bash tests/run.sh /path/to/wordpress
 *
 * Exits non-zero if anything fails. Self-cleaning: every temporary post,
 * option, user and table row it creates is removed at the end, and the
 * options it mutates are captured and restored.
 *
 * What is NOT here, because it belongs to the theme and is covered by the
 * theme's own suite: the settings schema and patch guard, CSS generation, the
 * Customizer, fonts, block patterns, the AI provider router, the child theme
 * generator, onboarding, the migration tools, comments.php, and Perdita_Crypto.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

// This is a CLI harness, so REMOTE_ADDR is never populated the way a real HTTP
// request populates it. Perdita_MCP::client_ip() treats an empty IP as "always
// allow" (it would rather not rate-limit than lock out a request it cannot
// attribute to anyone), so without this the rate-limit tests below always
// no-op no matter what they assert.
if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $name ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		$pass++;
		echo "PASS  $name\n";
	} else {
		$fail++;
		echo "FAIL  $name\n";
	}
};

/**
 * Whether any callback on $hook is a method on an instance of $class. Used to
 * assert that a screen-bound subsystem was NOT built on this request.
 */
$hook_has_instance = function ( $hook, $class ) {
	if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$fn = isset( $registered['function'] ) ? $registered['function'] : null;
			if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] instanceof $class ) {
				return true;
			}
		}
	}
	return false;
};

// Capture options tests mutate, so they can be restored at the end.
$__orig_settings = get_option( 'perdita_settings' );
$__orig_seo      = get_option( 'perdita_seo' );
$__orig_core     = get_option( 'perdita_core' );

// --- plugin bootstrap and its dependency on the theme ---
// Real deployments commonly run a generated CHILD theme (Perdita as the
// parent/template), not Perdita itself active by name, so this checks the
// template slug rather than hardcoding the active theme's display Name.
$ok( 'perdita' === wp_get_theme()->get_template(), 'theme active (Perdita is the active theme or its parent)' );
$ok(
	defined( 'PERDITA_CORE_VERSION' ) && defined( 'PERDITA_CORE_FILE' ) && defined( 'PERDITA_CORE_DIR' ) && defined( 'PERDITA_CORE_URL' ),
	'plugin constants defined'
);
$ok( function_exists( 'perdita_core' ) && perdita_core() instanceof Perdita_Core, 'perdita_core() returns the bootstrap' );
$ok( perdita_core() === perdita_core(), 'perdita_core() is a singleton, not a new object per call' );

// The dependency gate. All three halves matter: get_template() catches a child
// theme of Perdita, and the other two catch a theme directory named 'perdita'
// that is not this theme.
$ok( Perdita_Core::theme_is_ready(), 'the theme gate passes with Perdita active' );
$ok( function_exists( 'perdita' ) && class_exists( 'Perdita_Crypto' ), 'the theme engine the plugin actually reads is loaded' );
$ok( 0 === has_action( 'after_setup_theme', 'perdita_core_boot' ), 'the plugin boots on after_setup_theme priority 0 (plugins load before functions.php, so plugins_loaded would be too early)' );
$ok( ! has_action( 'admin_notices', 'perdita_core_theme_notice' ), 'the missing-theme notice is not registered when the theme IS active' );

// --- lock step: the theme, this plugin, and Pro share one version number ---
$ok( array() === Perdita_Core::version_drift(), 'lock step: every installed Perdita piece is on ' . PERDITA_CORE_VERSION . ' (bump all three with bin/bump-version.sh)' );
$ok( defined( 'PERDITA_VERSION' ) && PERDITA_VERSION === PERDITA_CORE_VERSION, 'lock step: the theme version matches PERDITA_CORE_VERSION exactly' );
$__vd = array( 'theme' => '0.18.0-alpha', 'core' => '0.17.1-alpha', 'pro' => '0.18.0-alpha' );
$ok( $__vd === Perdita_Core::version_drift( $__vd ), 'lock step: version_drift() returns every piece when any one differs' );
$ok( array() === Perdita_Core::version_drift( array( 'theme' => '1.0.0', 'core' => '1.0.0', 'pro' => '1.0.0' ) ), 'lock step: matching versions are no drift' );
$ok( array() === Perdita_Core::version_drift( array( 'core' => '0.18.0-alpha' ) ), 'lock step: a lone piece can never be out of step' );
$ok( array( 'theme' => '0.18.0', 'core' => '0.18.0-alpha' ) === Perdita_Core::version_drift( array( 'theme' => '0.18.0', 'core' => '0.18.0-alpha' ) ), 'lock step: an -alpha suffix is a different build, not the same version' );
ob_start();
perdita_core_render_version_notice( array( 'theme' => '0.18.0-alpha', 'core' => '0.17.1-alpha' ) );
$__vd_html = ob_get_clean();
$ok( false !== strpos( $__vd_html, 'notice-warning' ) && false !== strpos( $__vd_html, 'Perdita Core 0.17.1-alpha' ) && false !== strpos( $__vd_html, 'update-core.php' ) && false === strpos( $__vd_html, 'is-dismissible' ), 'lock step: the warning names each version, links to Updates, and cannot be dismissed while it is true' );
$ok( function_exists( 'perdita_core_version_notice' ), 'lock step: the admin_notices callback exists' );

$core = perdita_core();
$ok( $core->modules instanceof Perdita_Modules, 'perdita_core()->modules is the registry' );
$ok( $core->seo instanceof Perdita_SEO_Store, 'perdita_core()->seo is the SEO store (what the theme perdita()->seo used to be)' );
$ok( $core->forms instanceof Perdita_Forms, 'perdita_core()->forms is built once the forms module boots' );
$ok( $core->shortcodes instanceof Perdita_Shortcodes, 'perdita_core()->shortcodes is always on, module state or not' );
$ok( $core->settings instanceof Perdita_Settings && $core->settings === perdita()->settings, 'the plugin reads the theme token document rather than keeping its own copy' );
$ok( $core->ai === perdita()->ai, 'the plugin reads the theme AI provider router rather than building a second one' );

// The theme keeps a __get() shim for third-party code that still writes
// perdita()->seo. It has to resolve to the SAME objects, or two halves of a
// site read different state.
$ok( perdita()->modules === $core->modules, 'the theme perdita()->modules shim resolves to this plugin registry' );
$ok( perdita()->seo === $core->seo, 'the theme perdita()->seo shim resolves to this plugin SEO store' );

// The self-hosted updater only ever runs an update check from wp-admin, cron,
// or WP-CLI. This harness is none of those, so nothing should be constructed.
$ok( ! $hook_has_instance( 'upgrader_pre_download', 'Perdita_Core_Updater' ), 'the updater is not constructed on a front-end request' );
$ok( ! $hook_has_instance( 'admin_menu', 'Perdita_Core_Admin' ), 'the admin screen is not constructed on a front-end request' );

// --- admin menu slug ---
// Every module admin screen here, and all of Perdita Pro's, attach with
// add_submenu_page( 'perdita', ... ). Renaming the parent slug orphans them
// all, so it is asserted rather than assumed.
require_once PERDITA_CORE_DIR . 'inc/class-perdita-core-admin.php';
$ok( 'perdita' === Perdita_Core_Admin::PAGE, 'the top-level menu slug is perdita' );
$__menu_orphans = array();
foreach ( (array) glob( PERDITA_CORE_DIR . 'inc/modules/*/class-*-admin.php' ) as $__admin_file ) {
	$__admin_src = (string) file_get_contents( $__admin_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === strpos( $__admin_src, 'add_submenu_page' ) ) {
		continue;
	}
	// A module that owns a post type (Sales: products) parents its screens
	// under that type's edit.php menu instead; that is not an orphan.
	if ( false === strpos( $__admin_src, "'" . Perdita_Core_Admin::PAGE . "'" ) && false === strpos( $__admin_src, 'edit.php?post_type=' ) ) {
		$__menu_orphans[] = basename( $__admin_file );
	}
}
$ok( empty( $__menu_orphans ), 'every module admin screen attaches to the perdita menu (orphans: ' . ( $__menu_orphans ? implode( ', ', $__menu_orphans ) : 'none' ) . ')' );

// --- module state migration out of the theme option ---
// Module toggles used to live in the theme's perdita_settings document, as
// modules.<id> with an older features.seo / features.forms fallback. They now
// live in this plugin's own perdita_core option, and the copy happens once.
$__mig_settings             = is_array( $__orig_settings ) ? $__orig_settings : array();
$__mig_settings['features'] = array(
	'seo'   => false,
	'forms' => true,
);
$__mig_settings['modules']  = array(
	'seo'     => true,
	'caching' => true,
	'sales'   => false,
);
update_option( 'perdita_settings', $__mig_settings );
delete_option( 'perdita_core' );

$__mig_ran   = Perdita_Core::migrate_module_state();
$__mig_state = Perdita_Core::state();
$ok( true === $__mig_ran, 'migration: runs when the plugin option does not exist yet' );
$ok( isset( $__mig_state['modules'] ) && true === $__mig_state['modules']['caching'], 'migration: copies an enabled modules.<id> across' );
$ok( isset( $__mig_state['modules']['sales'] ) && false === $__mig_state['modules']['sales'], 'migration: copies a disabled module as disabled, not as missing' );
$ok( isset( $__mig_state['modules']['seo'] ) && true === $__mig_state['modules']['seo'], 'migration: modules.seo wins over the older features.seo flag, matching the old lookup order' );
$ok( isset( $__mig_state['modules']['forms'] ) && true === $__mig_state['modules']['forms'], 'migration: features.forms is carried across as modules.forms' );
$ok( ! empty( $__mig_state['migrated'] ), 'migration: records when it ran' );
$ok( false === Perdita_Core::migrate_module_state(), 'migration: never runs a second time' );
$__mig_theme_after = get_option( 'perdita_settings' );
$ok( is_array( $__mig_theme_after ) && isset( $__mig_theme_after['modules']['caching'] ), 'migration: reads the theme option and leaves it untouched' );

if ( false === $__orig_core ) {
	delete_option( 'perdita_core' );
} else {
	update_option( 'perdita_core', $__orig_core );
}
if ( false === $__orig_settings ) {
	delete_option( 'perdita_settings' );
} else {
	update_option( 'perdita_settings', $__orig_settings );
}
perdita()->settings->flush();

// --- REST routes ---
$routes = rest_get_server()->get_routes();
$ok( isset( $routes['/perdita/v1/section'] ), 'the describe-a-section route is registered from rest_api_init' );
$ok( perdita_core()->section instanceof Perdita_Section, 'perdita_core()->section is built once rest_api_init has fired' );

// --- forms ---
$ok( post_type_exists( 'perdita_form' ), 'forms CPT registered' );
global $wpdb;
$table = $wpdb->prefix . 'perdita_entries';
$ok( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table, 'entries table exists' );
$idx = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'form_created'" );
$ok( count( $idx ) === 2 && ! $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'form_id'" ), 'entries composite index (form_id, created), old index dropped' );

$form_id = wp_insert_post( array( 'post_type' => 'perdita_form', 'post_title' => 'Smoke Form', 'post_status' => 'publish' ) );
update_post_meta( $form_id, Perdita_Forms::META, Perdita_Forms::default_config() );
$forms = new Perdita_Forms();
$nonce = wp_create_nonce( 'perdita_form_' . $form_id );
$req   = new WP_REST_Request( 'POST', "/perdita/v1/form/$form_id" );
$req->set_param( 'id', $form_id );
$req->set_param( 'perdita_nonce', $nonce );
$req->set_param( 'perdita_hp', '' );
$req->set_param( 'fields', array( 'name' => 'QA', 'email' => 'qa@example.com', 'message' => 'hi' ) );
$ok( 200 === $forms->submit( $req )->get_status(), 'form valid submit -> 200' );
$ok( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE form_id=%d", $form_id ) ) === 1, 'entry stored' );
$bad = new WP_REST_Request( 'POST', "/perdita/v1/form/$form_id" );
$bad->set_param( 'id', $form_id );
$bad->set_param( 'perdita_nonce', 'nope' );
$ok( 403 === $forms->submit( $bad )->get_status(), 'form bad nonce -> 403' );

// --- SEO: Genesis site-wide importer ---
$seo_g        = perdita_core()->seo;
$__gen_before = $seo_g->all();
$__gen_opt    = get_option( 'genesis-seo-settings', false );
update_option( 'genesis-seo-settings', array(
	'home_doctitle'          => 'Smoke Genesis Home',
	'home_description'       => 'Genesis home description.',
	'doctitle_sep'           => '|',
	'doctitle_seplocation'   => 'right',
	'append_site_title'      => 1,
	'noindex_cat_archive'    => 0,
	'noindex_tag_archive'    => 1,
	'noindex_author_archive' => 1,
	'noindex_date_archive'   => 1,
	'noindex_search_archive' => 1,
) );
$__gen_n = $seo_g->import_genesis();
$ok( 9 === $__gen_n, 'SEO Genesis import: counts every field it mapped (title, description, separator, title shape, five noindex rules)' );
$ok( 'Smoke Genesis Home' === $seo_g->get( 'home_title' ) && 'Genesis home description.' === $seo_g->get( 'home_description' ) && '|' === $seo_g->get( 'separator' ), 'SEO Genesis import: homepage title, description, and separator land in the store' );
$ok( '%title% %sep% %sitename%' === $seo_g->get( 'title_template' ), 'SEO Genesis import: append_site_title=1 with the separator on the right keeps the site name after the title' );
$ok( false === $seo_g->get( 'noindex.archive_category' ) && true === $seo_g->get( 'noindex.archive_tag' ), 'SEO Genesis import: per-archive noindex rules carry over as booleans' );
update_option( 'genesis-seo-settings', array( 'append_site_title' => 0 ) );
$seo_g->import_genesis();
$ok( '%title%' === $seo_g->get( 'title_template' ), 'SEO Genesis import: append_site_title=0 (the Genesis default) drops the site name from the title template' );
delete_option( 'genesis-seo-settings' );
$ok( 0 === $seo_g->import_genesis(), 'SEO Genesis import: nothing to import returns 0' );
if ( false !== $__gen_opt ) {
	update_option( 'genesis-seo-settings', $__gen_opt );
}
$seo_g->save( array(
	'home_title'       => $__gen_before['home_title'],
	'home_description' => $__gen_before['home_description'],
	'separator'        => $__gen_before['separator'],
	'title_template'   => $__gen_before['title_template'],
	'noindex'          => $__gen_before['noindex'],
) );

// --- SEO attachment URL cache ---
$seo = perdita_core()->seo;
$seo->save( array( 'default_image_id' => 0, 'default_image_cache' => array() ) );
$ok( '' === $seo->image_url(), 'SEO image_url empty when no image set (no fatal)' );

// --- module registry ---
$reg      = perdita_core()->modules;
$all_mods = $reg->all();
// >= not ===: a companion plugin (Perdita Pro) can register additional
// modules into this SAME registry via the perdita_register_modules action,
// so an install with one active also has more than this plugin's own 13.
// A live smoke run against a site with Perdita Pro active is exactly what
// caught this assertion being too strict.
$ok( count( $all_mods ) >= 13, 'modules: at least the free plugin\'s own 13 are discovered' );
foreach ( array( 'seo', 'forms', 'analytics', 'backups', 'caching', 'related_posts', 'sales', 'security', 'smtp', 'subscriptions', 'pagespeed', 'search-console', 'mcp' ) as $mid ) {
	$ok( isset( $all_mods[ $mid ] ), "modules: $mid is registered" );
}
$__defaults = $reg->all();
$ok( empty( $__defaults['sales']['default'] ) && empty( $__defaults['backups']['default'] ), 'modules: new modules default to disabled (descriptor default, independent of this site\'s toggles)' );
$ok( $reg->is_enabled( 'seo' ) && $reg->is_enabled( 'forms' ), 'modules: seo and forms default to enabled' );

// --- smtp module (static only; not instantiated, so no phpmailer_init hook registers) ---
require_once PERDITA_CORE_DIR . 'inc/modules/smtp/class-perdita-smtp.php';
Perdita_SMTP::install();
$smtp_table = $wpdb->prefix . 'perdita_smtp_log';
$ok( $wpdb->get_var( "SHOW TABLES LIKE '$smtp_table'" ) === $smtp_table, 'smtp: log table created' );

// --- smtp: log_success()/log_failure() must skip logging when Perdita Pro's
// SMTP module is mid-failover, or every internal per-route attempt there
// would ALSO show up in this module's own separate log (see the matching
// guard added to log_success()/log_failure()) ---
if ( ! class_exists( 'Perdita_SMTP_Pro' ) && defined( 'PERDITA_PRO_DIR' ) && file_exists( PERDITA_PRO_DIR . 'inc/modules/smtp-pro/class-perdita-smtp-pro.php' ) ) {
	require_once PERDITA_PRO_DIR . 'inc/modules/smtp-pro/class-perdita-smtp-pro.php';
}
if ( class_exists( 'Perdita_SMTP_Pro' ) ) {
	$smtp_instance   = new Perdita_SMTP( perdita_core() );
	$smtp_sending_prop = new ReflectionProperty( 'Perdita_SMTP_Pro', 'sending' );
	if ( PHP_VERSION_ID < 80100 ) { $smtp_sending_prop->setAccessible( true ); }

	$smtp_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$smtp_table}" );
	$smtp_sending_prop->setValue( null, true );
	$smtp_instance->log_success( array( 'to' => 'smoke@example.com', 'subject' => 'smoke' ) );
	$ok( $smtp_count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$smtp_table}" ), 'smtp: log_success() is skipped while SMTP Pro is brokering a send (no double-logging)' );

	$smtp_sending_prop->setValue( null, false );
	$smtp_instance->log_success( array( 'to' => 'smoke@example.com', 'subject' => 'smoke' ) );
	$ok( $smtp_count_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$smtp_table}" ), 'smtp: log_success() logs normally once SMTP Pro is no longer brokering (the guard only skips during an actual handoff)' );
	$wpdb->query( "DELETE FROM {$smtp_table} WHERE recipient = 'smoke@example.com'" );
} else {
	echo "SKIP  smtp double-logging guard (Perdita Pro not present in this checkout)\n";
}

// --- analytics module (static only) ---
require_once PERDITA_CORE_DIR . 'inc/modules/analytics/class-perdita-analytics.php';
$ok( Perdita_Analytics::is_valid_measurement_id( 'G-ABC123XYZ' ), 'analytics: accepts a validly-shaped GA4 id' );
$ok( ! Perdita_Analytics::is_valid_measurement_id( '<script>x</script>' ), 'analytics: rejects a malformed id' );
$an_clean = Perdita_Analytics::sanitize( array( 'measurement_id' => '<script>x</script>' ) );
$ok( '' === $an_clean['measurement_id'], 'analytics: sanitize() drops an invalid id rather than storing it (never printed into the page)' );

// --- pagespeed module (static only) ---
require_once PERDITA_CORE_DIR . 'inc/modules/pagespeed/class-perdita-pagespeed.php';
$__orig_psi_settings = get_option( Perdita_Pagespeed::OPTION );
$psi_clean = Perdita_Pagespeed::sanitize( array( 'api_key' => 'psi-smoke-key-1234' ), '' );
$ok( '' !== $psi_clean['api_key'] && 'psi-smoke-key-1234' !== $psi_clean['api_key'], 'pagespeed: sanitize() encrypts the API key, never stores it plaintext' );
update_option( Perdita_Pagespeed::OPTION, $psi_clean );
$ok( 'psi-smoke-key-1234' === Perdita_Pagespeed::api_key(), 'pagespeed: api_key() decrypts what sanitize() stored' );
$psi_kept = Perdita_Pagespeed::sanitize( array( 'api_key' => '' ), $psi_clean['api_key'] );
$ok( $psi_clean['api_key'] === $psi_kept['api_key'], 'pagespeed: blank input on save keeps the existing encrypted key rather than clearing it' );
$psi_removed = Perdita_Pagespeed::sanitize( array( 'remove_api_key' => true ), $psi_clean['api_key'] );
$ok( '' === $psi_removed['api_key'], 'pagespeed: remove_api_key clears the stored key' );
if ( false === $__orig_psi_settings ) {
	delete_option( Perdita_Pagespeed::OPTION );
} else {
	update_option( Perdita_Pagespeed::OPTION, $__orig_psi_settings );
}

// --- search console module (static only; OAuth/API calls need a live Google
// round-trip and are out of scope for this offline suite) ---
require_once PERDITA_CORE_DIR . 'inc/modules/search-console/class-perdita-search-console.php';
$__orig_sc_settings = get_option( Perdita_Search_Console::OPTION );
$sc_clean = Perdita_Search_Console::sanitize_client( array( 'client_id' => 'smoke-client-id', 'client_secret' => 'smoke-client-secret' ) );
$ok( 'smoke-client-id' === $sc_clean['client_id'], 'search console: sanitize_client() keeps the client id as plain text' );
$ok( '' !== $sc_clean['client_secret'] && 'smoke-client-secret' !== $sc_clean['client_secret'], 'search console: sanitize_client() encrypts the client secret, never stores it plaintext' );
update_option( Perdita_Search_Console::OPTION, array_merge( Perdita_Search_Console::defaults(), $sc_clean ) );
$ok( Perdita_Search_Console::has_client(), 'search console: has_client() true once id + secret are both saved' );
$ok( ! Perdita_Search_Console::is_connected(), 'search console: is_connected() false with no refresh token yet' );
$sc_removed = Perdita_Search_Console::sanitize_client( array( 'client_id' => 'smoke-client-id', 'remove_secret' => true ) );
$ok( '' === $sc_removed['client_secret'], 'search console: remove_secret clears the stored client secret' );
$sc_sites = array(
	array( 'siteUrl' => trailingslashit( home_url( '/' ) ), 'permissionLevel' => 'siteOwner' ),
	array( 'siteUrl' => 'sc-domain:example.org', 'permissionLevel' => 'siteOwner' ),
);
$ok( trailingslashit( home_url( '/' ) ) === Perdita_Search_Console::auto_match_site( $sc_sites ), 'search console: auto_match_site() matches an exact home_url() urlprefix property' );
$ok( '' === Perdita_Search_Console::auto_match_site( array( array( 'siteUrl' => 'sc-domain:example.org' ) ) ), 'search console: auto_match_site() returns empty when nothing matches this site' );
if ( false === $__orig_sc_settings ) {
	delete_option( Perdita_Search_Console::OPTION );
} else {
	update_option( Perdita_Search_Console::OPTION, $__orig_sc_settings );
}

// --- security module (static only; not instantiated, so no authenticate/send_headers hook registers) ---
require_once PERDITA_CORE_DIR . 'inc/modules/security/class-perdita-security.php';
$sec_defaults = Perdita_Security::defaults();
$ok( 'report-only' === $sec_defaults['csp_mode'], 'security: CSP defaults to report-only, never silently enforcing' );
$ok( false === $sec_defaults['login_limit'] && false === $sec_defaults['headers'], 'security: every protection defaults off' );

// --- caching module (static only) ---
require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
$cache_file = Perdita_Caching::file_for_key( 'https://example.com/../../etc/passwd' );
$ok( false === strpos( $cache_file, '..' ) && false === strpos( $cache_file, '/etc/' ) && '.html' === substr( $cache_file, -5 ), 'caching: cache filename is a fixed-length hash, never a raw path' );

// --- caching: perdita_cache_output_html falls back to the original HTML when
// a filter (e.g. Perdita Pro's minifier) returns a malformed value ---
$cache_filter_method = new ReflectionMethod( 'Perdita_Caching', 'filter_output_html' );
if ( PHP_VERSION_ID < 80100 ) { $cache_filter_method->setAccessible( true ); }

$cache_bad_filter = function () {
	return array( 'not', 'a', 'string' );
};
add_filter( 'perdita_cache_output_html', $cache_bad_filter );
$cache_filtered = $cache_filter_method->invoke( null, '<html><body>smoke original</body></html>' );
remove_filter( 'perdita_cache_output_html', $cache_bad_filter );
$ok( false !== strpos( $cache_filtered, 'smoke original' ), 'caching: malformed perdita_cache_output_html filter result falls back to the original HTML' );

$cache_good_filter = function ( $html ) {
	return $html . '<!-- minified -->';
};
add_filter( 'perdita_cache_output_html', $cache_good_filter );
$cache_filtered2 = $cache_filter_method->invoke( null, '<html><body>smoke original</body></html>' );
remove_filter( 'perdita_cache_output_html', $cache_good_filter );
$ok( false !== strpos( $cache_filtered2, '<!-- minified -->' ), 'caching: a well-behaved perdita_cache_output_html filter result is still honored' );

// --- backups module (static only) ---
require_once PERDITA_CORE_DIR . 'inc/modules/backups/class-perdita-backups.php';
$__orig_backups_settings = get_option( 'perdita_backups_settings' );
Perdita_Backups::install( perdita_core() );
$ok( '' === Perdita_Backups::safe_path( '../../../etc/passwd' ), 'backups: safe_path() rejects traversal' );
$ok( '' === Perdita_Backups::safe_path( 'a/b.zip' ), 'backups: safe_path() rejects a nested path' );
$ok( '' === Perdita_Backups::safe_path( '' ), 'backups: safe_path() rejects an empty name' );
if ( false === $__orig_backups_settings ) {
	delete_option( 'perdita_backups_settings' );
} else {
	update_option( 'perdita_backups_settings', $__orig_backups_settings );
}

// --- related posts module (creates its own test posts here, BEFORE
// subscriptions is instantiated below, so its transition_post_status hook
// isn't yet registered to catch them) ---
require_once PERDITA_CORE_DIR . 'inc/modules/related-posts/class-perdita-related-posts.php';
$rp        = new Perdita_Related_Posts( perdita_core() );
$rp_ref    = new ReflectionClass( $rp );
$rp_find   = $rp_ref->getMethod( 'find_related' );
if ( PHP_VERSION_ID < 80100 ) { $rp_find->setAccessible( true ); }
$rp_render = $rp_ref->getMethod( 'render' );
if ( PHP_VERSION_ID < 80100 ) { $rp_render->setAccessible( true ); }

$rp_cat = wp_create_category( 'Perdita Smoke RP' );
$rp_p1  = wp_insert_post( array( 'post_title' => 'Smoke RP One', 'post_status' => 'publish', 'post_content' => 'a', 'post_category' => array( $rp_cat ) ) );
$rp_p2  = wp_insert_post( array( 'post_title' => 'Smoke RP Two', 'post_status' => 'publish', 'post_content' => 'b', 'post_category' => array( $rp_cat ) ) );
$rp_xss = wp_insert_post( array( 'post_title' => 'Smoke RP <script>x</script>', 'post_status' => 'publish', 'post_content' => 'c' ) );

$rp_related = $rp_find->invoke( $rp, $rp_p1, 3, 'both' );
$ok( in_array( 'Smoke RP Two', wp_list_pluck( $rp_related, 'post_title' ), true ), 'related posts: matches shared-category posts' );
$ok( ! in_array( $rp_p1, wp_list_pluck( $rp_related, 'ID' ), true ), 'related posts: excludes the current post' );

$rp_html = $rp_render->invoke( $rp, $rp_xss );
$ok( '' !== $rp_html, 'related posts: backfills when there is no taxonomy match' );
$ok( false === strpos( $rp_html, '<script>' ), 'related posts: escapes a post title containing markup' );

wp_delete_post( $rp_p1, true );
wp_delete_post( $rp_p2, true );
wp_delete_post( $rp_xss, true );
wp_delete_category( $rp_cat );

// --- subscriptions module ---
require_once PERDITA_CORE_DIR . 'inc/modules/subscriptions/class-perdita-subscriptions.php';
$subs = new Perdita_Subscriptions( perdita_core() );
Perdita_Subscriptions::install();
$subs_table = $wpdb->prefix . 'perdita_subscribers';
$ok( $wpdb->get_var( "SHOW TABLES LIKE '$subs_table'" ) === $subs_table, 'subscriptions: table created' );

$subs_ref     = new ReflectionClass( $subs );
$subs_process = $subs_ref->getMethod( 'process_signup' );
if ( PHP_VERSION_ID < 80100 ) { $subs_process->setAccessible( true ); }
$subs_send    = $subs_ref->getMethod( 'send_post_notification' );
if ( PHP_VERSION_ID < 80100 ) { $subs_send->setAccessible( true ); }

$subs_email = 'perdita-smoke-subscriber@example.com';
$wpdb->delete( $subs_table, array( 'email' => $subs_email ) );

$subs_calls  = array();
$subs_filter = function ( $a ) use ( &$subs_calls ) {
	$subs_calls[] = $a;
	return $a;
};
add_filter( 'wp_mail', $subs_filter );

$subs_process->invoke( $subs, $subs_email );
$subs_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $subs_table WHERE email = %s", $subs_email ) );
$ok( $subs_row && 'pending' === $subs_row->status, 'subscriptions: first signup inserts as pending' );
$ok( 1 === count( $subs_calls ), 'subscriptions: first signup sends exactly one confirmation email' );

$subs_process->invoke( $subs, $subs_email ); // Repeat within the cooldown window.
$ok( 1 === count( $subs_calls ), 'subscriptions: repeat signup inside the cooldown sends no second email (the core abuse case)' );

// Move straight to confirmed via the store, the same state the real
// wp_die()-based confirm handler would leave (that handler isn't called
// directly here: wp_die() halts the whole CLI process in this eval-file
// context rather than throwing a catchable exception, which would truncate
// the rest of this test run).
$subs_unsub_token = bin2hex( random_bytes( 16 ) );
$wpdb->update( $subs_table, array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ), 'unsubscribe_token' => $subs_unsub_token ), array( 'id' => $subs_row->id ) );

$subs_calls = array();
$subs_process->invoke( $subs, $subs_email );
$ok( 0 === count( $subs_calls ), 'subscriptions: signing up an already-confirmed address sends no email (no enumeration signal)' );

$subs_post = wp_insert_post( array( 'post_title' => 'Smoke Subs Post', 'post_status' => 'publish', 'post_content' => 'x' ) );
$subs_row2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $subs_table WHERE email = %s", $subs_email ) );
$subs_calls = array();
$subs_send->invoke( $subs, $subs_row2, get_post( $subs_post ) );
$ok( 1 === count( $subs_calls ) && false !== strpos( $subs_calls[0]['message'], 'perdita_unsubscribe=' . $subs_row2->unsubscribe_token ), 'subscriptions: notification email contains a real, matching unsubscribe link' );

remove_filter( 'wp_mail', $subs_filter );
$wpdb->delete( $subs_table, array( 'email' => $subs_email ) );
wp_delete_post( $subs_post, true );
// wp_insert_post() above fired the module's own transition_post_status hook,
// queuing that post and scheduling a real cron event. Undo both so the suite
// stays self-cleaning.
delete_option( 'perdita_subscriptions_queue' );
wp_clear_scheduled_hook( Perdita_Subscriptions::CRON_HOOK );

// --- sales module (static only; not instantiated, so no CPT/REST-route
// registration or cart/checkout hooks fire) ---
require_once PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php';
$ok( false === Perdita_Sales::verify_stripe_signature( '{}', 't=' . time() . ',v1=deadbeef', '' ), 'sales: webhook verification rejects an empty secret rather than failing open' );

$sales_secret  = 'smoke_test_secret';
$sales_payload = '{"type":"checkout.session.completed"}';
$sales_t       = time();
$sales_mac     = hash_hmac( 'sha256', $sales_t . '.' . $sales_payload, $sales_secret );
$ok( true === Perdita_Sales::verify_stripe_signature( $sales_payload, "t=$sales_t,v1=$sales_mac", $sales_secret ), 'sales: correctly-signed webhook payload verifies' );
$ok( false === Perdita_Sales::verify_stripe_signature( $sales_payload . 'X', "t=$sales_t,v1=$sales_mac", $sales_secret ), 'sales: tampered webhook payload is rejected' );

$sales_tok_a = Perdita_Sales::download_token( 1, 5, 0, $sales_t + 3600 );
$sales_tok_b = Perdita_Sales::download_token( 1, 6, 0, $sales_t + 3600 );
$sales_tok_c = Perdita_Sales::download_token( 2, 5, 0, $sales_t + 3600 );
$ok( $sales_tok_a !== $sales_tok_b, 'sales: download token differs per product id (no IDOR by construction)' );
$ok( $sales_tok_a !== $sales_tok_c, 'sales: download token differs per order id' );

// --- sales: reserve_stock()/release_stock() close the oversell race a
// plain read-then-write stock check leaves open (see handle_checkout_post()) ---
$sales_product_id = wp_insert_post( array( 'post_type' => Perdita_Sales::CPT_PRODUCT, 'post_title' => 'Smoke Stock Product', 'post_status' => 'publish' ) );
update_post_meta( $sales_product_id, '_perdita_sales_stock', 2 );
$sales_reserve_ref = new ReflectionMethod( 'Perdita_Sales', 'reserve_stock' );
if ( PHP_VERSION_ID < 80100 ) { $sales_reserve_ref->setAccessible( true ); }
$sales_release_ref = new ReflectionMethod( 'Perdita_Sales', 'release_stock' );
if ( PHP_VERSION_ID < 80100 ) { $sales_release_ref->setAccessible( true ); }
$sales_dummy = new Perdita_Sales( perdita_core() );

$ok( true === $sales_reserve_ref->invoke( $sales_dummy, $sales_product_id, 2 ), 'sales: reserve_stock() succeeds for exactly the available quantity' );
$ok( false === $sales_reserve_ref->invoke( $sales_dummy, $sales_product_id, 1 ), 'sales: reserve_stock() fails once stock is fully claimed (the oversell race this closes)' );
$ok( 0 === (int) get_post_meta( $sales_product_id, '_perdita_sales_stock', true ), 'sales: reserve_stock() actually decremented the stored stock' );
$sales_release_ref->invoke( $sales_dummy, $sales_product_id, 2 );
$ok( 2 === (int) get_post_meta( $sales_product_id, '_perdita_sales_stock', true ), 'sales: release_stock() gives back a reservation released after a later checkout failure' );
wp_delete_post( $sales_product_id, true );

// --- sales: a page embedding [perdita_form] must never be cached (a cache
// HIT would keep serving that visitor's nonce long after it expires) ---
require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms.php';
$forms_cache_test_post = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Smoke Form Cache Page', 'post_status' => 'publish', 'post_content' => '[perdita_form id="1"]' ) );
global $wp_query;
$__orig_wp_query_is_singular = $wp_query->is_singular;
$__orig_post_global          = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$wp_query->is_singular = true;
$GLOBALS['post']       = get_post( $forms_cache_test_post );
$forms_dummy           = new Perdita_Forms();
$ok( true === $forms_dummy->exclude_from_cache_if_has_form( false ), 'forms: a page containing [perdita_form] is excluded from the page cache' );

$forms_no_form_post    = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Smoke No-Form Page', 'post_status' => 'publish', 'post_content' => 'just some ordinary content' ) );
$GLOBALS['post']       = get_post( $forms_no_form_post );
$ok( false === $forms_dummy->exclude_from_cache_if_has_form( false ), 'forms: a page with no [perdita_form] is left cacheable' );
wp_delete_post( $forms_no_form_post, true );

$wp_query->is_singular = $__orig_wp_query_is_singular;
$GLOBALS['post']       = $__orig_post_global;
wp_delete_post( $forms_cache_test_post, true );

// --- forms entries screen: the per-value render filter an add-on hooks to
// display a value plain text can't express (Forms Pro turns a stored file
// upload into a download link there). An ordinary value must come back
// untouched, so the free screen renders exactly as it always has. ---
$forms_entry_row = (object) array( 'id' => 0, 'form_id' => 0, 'data' => '{}', 'ip' => '', 'created' => '2026-01-01 00:00:00' );
$ok( 'Jane &amp; Co' === apply_filters( 'perdita_form_entry_value_html', 'Jane &amp; Co', 'Jane & Co', 'name', $forms_entry_row ), 'forms: perdita_form_entry_value_html returns the default escaped HTML untouched for an ordinary value' );

// --- footer/copyright shortcodes (Perdita_Shortcodes is always on, and
// already registered by Perdita_Core::init() by the time this suite runs) ---
$__orig_footer = perdita()->settings->get( 'footer' );

$ok( shortcode_exists( 'perdita_year' ), 'shortcodes: perdita_year is registered' );
$ok( shortcode_exists( 'perdita_copyright' ), 'shortcodes: perdita_copyright is registered' );
$ok( shortcode_exists( 'perdita_trademark' ), 'shortcodes: perdita_trademark is registered' );
$ok( shortcode_exists( 'perdita_site_name' ), 'shortcodes: perdita_site_name is registered' );
$ok( shortcode_exists( 'perdita_credit' ), 'shortcodes: perdita_credit is registered' );

perdita()->settings->apply_patch( array( 'footer.established_year' => '' ) );
$sc_year_now = do_shortcode( '[perdita_year]' );
$ok( wp_date( 'Y' ) === $sc_year_now, 'shortcodes: [perdita_year] with no established year shows only the current year' );

perdita()->settings->apply_patch( array( 'footer.established_year' => '2020' ) );
$sc_year_range = do_shortcode( '[perdita_year]' );
$ok( false !== strpos( $sc_year_range, '2020' ) && false !== strpos( $sc_year_range, wp_date( 'Y' ) ) && '2020' !== $sc_year_range, 'shortcodes: [perdita_year] shows a start-end range when an established year is set and differs from the current year' );

perdita()->settings->apply_patch( array( 'footer.established_year' => wp_date( 'Y' ) ) );
$sc_year_same = do_shortcode( '[perdita_year]' );
$ok( wp_date( 'Y' ) === $sc_year_same, 'shortcodes: [perdita_year] collapses to a single year when the established year equals the current year (no redundant "2026-2026")' );

$ok( '©' === do_shortcode( '[perdita_copyright]' ), 'shortcodes: [perdita_copyright] outputs the (c) symbol' );
$ok( '™' === do_shortcode( '[perdita_trademark]' ), 'shortcodes: [perdita_trademark] outputs the (tm) symbol' );

$__orig_blogname = get_option( 'blogname' );
update_option( 'blogname', 'Smith & Sons "Best" <Co>' );
$sc_site_name = do_shortcode( '[perdita_site_name]' );
$ok( false === strpos( $sc_site_name, '<' ) && false === strpos( $sc_site_name, '"' ), '[perdita_site_name]: a site name with HTML-special characters renders with no raw < or " (escaped once, not double-escaped into visibly mangled entities)' );
update_option( 'blogname', $__orig_blogname );

perdita()->settings->apply_patch( array( 'footer.established_year' => '' ) );
$sc_credit = do_shortcode( '[perdita_credit]' );
$ok( false !== strpos( $sc_credit, '©' ) && false !== strpos( $sc_credit, wp_date( 'Y' ) ) && false !== strpos( $sc_credit, get_bloginfo( 'name' ) ), 'shortcodes: [perdita_credit] composes copyright symbol + year + site name' );

perdita()->settings->apply_patch( array( 'footer.established_year' => 'not-a-year' ) );
$ok( '' === perdita()->settings->get( 'footer.established_year' ), 'shortcodes: established_year sanitizer drops non-4-digit garbage rather than storing it' );

perdita()->settings->apply_patch( array( 'footer' => $__orig_footer ) );

// --- MCP server module (static logic only: the live JSON-RPC endpoint over
// real HTTP, and its rate limiter, are exercised in a separate live check,
// not this offline suite; see ROADMAP.md) ---
require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp.php';
$__orig_mcp_settings = get_option( Perdita_MCP::OPTION );
$__orig_mcp_user     = get_current_user_id();
wp_set_current_user( 1 );

Perdita_MCP::revoke_api_key();
$ok( ! Perdita_MCP::has_key(), 'mcp: no key active after revoke_api_key()' );

$mcp_key = Perdita_MCP::generate_api_key( 1 );
$ok( 0 === strpos( $mcp_key, 'perdita_mcp_' ), 'mcp: generated key carries the identifiable perdita_mcp_ prefix' );
$ok( Perdita_MCP::has_key(), 'mcp: has_key() true after generation' );
$ok( 1 === Perdita_MCP::authenticate( $mcp_key ), 'mcp: authenticate() resolves a correct key to its bound user id' );
$ok( false === Perdita_MCP::authenticate( 'not-the-real-key' ), 'mcp: authenticate() rejects a wrong key' );
$ok( false === Perdita_MCP::authenticate( '' ), 'mcp: authenticate() rejects an empty token rather than matching a blank stored hash' );

$mcp_stored = get_option( Perdita_MCP::OPTION );
$ok( is_array( $mcp_stored ) && $mcp_key !== $mcp_stored['key_hash'] && '' !== $mcp_stored['key_hash'], 'mcp: the stored value is a hash, never the plaintext key' );

// Phase 2 (OAuth) extension point: nothing hooks this yet, so it must default
// to false and never authenticate a token the free-tier key doesn't already
// cover.
$ok( false === apply_filters( 'perdita_mcp_validate_oauth_token', false, 'some-oauth-token' ), 'mcp: perdita_mcp_validate_oauth_token defaults to false with nothing hooked' );

// Capability enforcement: the core safety property this whole feature rests
// on. A Contributor-bound key must never be able to publish, only draft,
// even when it explicitly asks to.
$mcp_contrib_id = wp_insert_user( array( 'user_login' => 'perdita_smoke_mcp_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
Perdita_MCP::generate_api_key( $mcp_contrib_id );
wp_set_current_user( $mcp_contrib_id );

$mcp_instance = new Perdita_MCP( perdita_core() );
$mcp_ref      = new ReflectionClass( $mcp_instance );
$mcp_create   = $mcp_ref->getMethod( 'tool_create_post' );
if ( PHP_VERSION_ID < 80100 ) { $mcp_create->setAccessible( true ); }
$mcp_result   = $mcp_create->invoke( $mcp_instance, array( 'title' => 'Perdita Smoke MCP Contrib Post', 'content' => 'x', 'status' => 'publish' ) );
$mcp_post_id  = $mcp_result['structuredContent']['id'] ?? 0;
$ok( $mcp_post_id > 0 && 'draft' === get_post_status( $mcp_post_id ), 'mcp: create_post forces a Contributor-bound request to draft regardless of the requested status' );
$ok( true === ( $mcp_result['structuredContent']['forced_to_draft'] ?? false ), 'mcp: create_post response explicitly flags the forced downgrade rather than succeeding silently' );
if ( $mcp_post_id ) {
	wp_delete_post( $mcp_post_id, true );
}
wp_delete_user( $mcp_contrib_id );

// Status validation: garbled/unrecognized status values must be rejected
// outright (tool_error), never silently coerced to draft with no
// indication anything happened - the exact silent-unpublish bug this
// session's review found and fixed.
wp_set_current_user( 1 );
$mcp_create2       = $mcp_ref->getMethod( 'tool_create_post' );
$mcp_create2->setAccessible( true );
$mcp_valid_post    = $mcp_create2->invoke( $mcp_instance, array( 'title' => 'Perdita Smoke Status Post', 'content' => 'x', 'status' => 'publish' ) );
$mcp_valid_post_id = $mcp_valid_post['structuredContent']['id'] ?? 0;
$ok( $mcp_valid_post_id > 0 && 'publish' === get_post_status( $mcp_valid_post_id ), 'mcp: create_post with a valid status and publish_posts capability actually publishes' );

$mcp_update      = $mcp_ref->getMethod( 'tool_update_post' );
if ( PHP_VERSION_ID < 80100 ) { $mcp_update->setAccessible( true ); }
$mcp_bad_status  = $mcp_update->invoke( $mcp_instance, array( 'id' => $mcp_valid_post_id, 'status' => 'NOT-A-REAL-STATUS' ) );
$ok( true === ( $mcp_bad_status['isError'] ?? false ), 'mcp: update_post rejects an unrecognized status value as an error, rather than silently coercing to draft' );
$ok( 'publish' === get_post_status( $mcp_valid_post_id ), 'mcp: the post is NOT silently unpublished by the rejected status update' );

$mcp_create_bad_status = $mcp_create2->invoke( $mcp_instance, array( 'title' => 'Perdita Smoke Bad Status Create', 'content' => 'x', 'status' => 'NOT-A-REAL-STATUS' ) );
$ok( true === ( $mcp_create_bad_status['isError'] ?? false ), 'mcp: create_post also rejects an unrecognized status value as an error' );

// Post-type allowlist: get_post/update_post must never touch a post type
// outside post/page, even though every post type shares one wp_posts ID
// space (an attachment could otherwise be read/edited through a tool
// whose own schema only ever advertises posts and pages).
$mcp_attachment_id = wp_insert_attachment( array( 'post_title' => 'Perdita Smoke Attachment', 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg' ), false, $mcp_valid_post_id );
$mcp_get           = $mcp_ref->getMethod( 'tool_get_post' );
if ( PHP_VERSION_ID < 80100 ) { $mcp_get->setAccessible( true ); }
$mcp_attach_result = $mcp_get->invoke( $mcp_instance, array( 'id' => $mcp_attachment_id ) );
$ok( true === ( $mcp_attach_result['isError'] ?? false ), 'mcp: get_post refuses to return a non-post/page post type (attachment) even though it shares the same ID space' );
$mcp_attach_update = $mcp_update->invoke( $mcp_instance, array( 'id' => $mcp_attachment_id, 'title' => 'should not apply' ) );
$ok( true === ( $mcp_attach_update['isError'] ?? false ), 'mcp: update_post refuses to touch a non-post/page post type' );

wp_delete_post( $mcp_attachment_id, true );
wp_delete_post( $mcp_valid_post_id, true );

// Tool annotations (MCP best-practice hints): every tool must carry the
// four standard hint fields so a client can auto-approve safe reads and
// require confirmation before a write.
$mcp_defs = Perdita_MCP::tool_definitions( perdita_core() );
$mcp_defs_missing_annotations = array_filter( $mcp_defs, function ( $t ) {
	return ! isset( $t['annotations']['readOnlyHint'], $t['annotations']['destructiveHint'], $t['annotations']['idempotentHint'], $t['annotations']['openWorldHint'] );
} );
$ok( empty( $mcp_defs_missing_annotations ), 'mcp: every tool definition carries all four MCP annotation hints' );
$mcp_create_def = current( array_filter( $mcp_defs, function ( $t ) { return 'create_post' === $t['name']; } ) );
$ok( false === $mcp_create_def['annotations']['readOnlyHint'] && false === $mcp_create_def['annotations']['idempotentHint'], 'mcp: create_post is correctly annotated as non-read-only and non-idempotent' );

// Generalized rate limiter (Perdita_MCP::rate_limit_check()/rate_limit_record()
// are now public static + parameterized so Perdita_MCP_OAuth can reuse them
// for its own, separately-keyed counters).
$mcp_rl_prefix = 'perdita_smoke_rl_test_';
delete_transient( Perdita_MCP::rate_limit_key( $mcp_rl_prefix, Perdita_MCP::client_ip() ) );
$ok( true === Perdita_MCP::rate_limit_check( $mcp_rl_prefix, 2 ), 'mcp: rate_limit_check() allows a request under the cap' );
Perdita_MCP::rate_limit_record( $mcp_rl_prefix, HOUR_IN_SECONDS );
Perdita_MCP::rate_limit_record( $mcp_rl_prefix, HOUR_IN_SECONDS );
$ok( is_wp_error( Perdita_MCP::rate_limit_check( $mcp_rl_prefix, 2 ) ), 'mcp: rate_limit_check() rejects once the recorded count reaches the cap' );
delete_transient( Perdita_MCP::rate_limit_key( $mcp_rl_prefix, Perdita_MCP::client_ip() ) );

wp_set_current_user( $__orig_mcp_user );
if ( false === $__orig_mcp_settings ) {
	delete_option( Perdita_MCP::OPTION );
} else {
	update_option( Perdita_MCP::OPTION, $__orig_mcp_settings );
}

// --- MCP OAuth 2.1 authorization server (Pro tier): offline-testable logic
// only. The interactive /authorize consent screen and the Allow/Deny POST
// handler both end in an unconditional exit; (correct for a real HTTP
// response, nothing left to render after them) so they, and the full
// redirect-based authorize/token/revoke round trip, are exercised in a
// separate live check instead of this in-process suite; see ROADMAP.md ---
require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp-oauth.php';
$__orig_oauth_clients     = get_option( Perdita_MCP_OAuth::OPTION_CLIENTS );
$__orig_oauth_grants      = get_option( Perdita_MCP_OAuth::OPTION_GRANTS );
$__orig_oauth_connections = get_option( 'perdita_mcp_oauth_connections' );
update_option( Perdita_MCP_OAuth::OPTION_CLIENTS, array() );
update_option( Perdita_MCP_OAuth::OPTION_GRANTS, array() );
update_option( 'perdita_mcp_oauth_connections', array() );
// This section makes several /oauth/register calls against the real
// rate limiter below. A CLI run has no real visitor IP, so every wp-cli
// invocation shares one bucket (127.0.0.1); without this reset, re-running
// the suite a handful of times in the same hour trips the limiter and
// every assertion after it fails for a reason that has nothing to do with
// the code being tested.
delete_transient( Perdita_MCP::rate_limit_key( Perdita_MCP_OAuth::REGISTER_RATE_LIMIT_PREFIX, Perdita_MCP::client_ip() ) );

$oauth_smoke = new Perdita_MCP_OAuth( perdita_core() );

// Dynamic client registration (RFC7591): the REST callback itself has no
// exit; in it, so it can be invoked in-process like any other REST handler.
$reg_req = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_req->set_body( wp_json_encode( array( 'client_name' => 'Smoke Client', 'redirect_uris' => array( 'https://example.test/callback' ), 'token_endpoint_auth_method' => 'none' ) ) );
$reg_res  = $oauth_smoke->handle_register( $reg_req );
$reg_data = $reg_res->get_data();
$ok( 201 === $reg_res->get_status() && 0 === strpos( $reg_data['client_id'] ?? '', 'perdita_mcpc_' ), 'mcp-oauth: register issues a client_id with the identifiable prefix' );
$ok( ! isset( $reg_data['client_secret'] ), 'mcp-oauth: a token_endpoint_auth_method=none client gets no client_secret (PKCE is its only proof of possession)' );
$oauth_smoke_client_id = $reg_data['client_id'] ?? '';

$reg_req2 = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_req2->set_body( wp_json_encode( array( 'client_name' => 'Confidential Smoke Client', 'redirect_uris' => array( 'https://example.test/callback' ) ) ) );
$reg_data2 = $oauth_smoke->handle_register( $reg_req2 )->get_data();
$ok( ! empty( $reg_data2['client_secret'] ), 'mcp-oauth: omitting token_endpoint_auth_method defaults to a confidential client that IS issued a secret' );

$reg_bad_scheme = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_bad_scheme->set_body( wp_json_encode( array( 'redirect_uris' => array( 'http://example.com/callback' ) ) ) );
$ok( 400 === $oauth_smoke->handle_register( $reg_bad_scheme )->get_status(), 'mcp-oauth: register rejects a plain-http redirect_uri on a non-localhost host' );

$reg_localhost = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_localhost->set_body( wp_json_encode( array( 'redirect_uris' => array( 'http://localhost:3000/callback' ), 'token_endpoint_auth_method' => 'none' ) ) );
$ok( 201 === $oauth_smoke->handle_register( $reg_localhost )->get_status(), 'mcp-oauth: register allows plain-http on localhost (local-development client exception)' );

$reg_empty = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_empty->set_body( wp_json_encode( array( 'redirect_uris' => array() ) ) );
$ok( 400 === $oauth_smoke->handle_register( $reg_empty )->get_status(), 'mcp-oauth: register rejects an empty redirect_uris list' );

$oauth_stored_client = Perdita_MCP_OAuth::all_clients()[ $oauth_smoke_client_id ] ?? null;
$ok( is_array( $oauth_stored_client ) && array( 'https://example.test/callback' ) === $oauth_stored_client['redirect_uris'], 'mcp-oauth: all_clients() reflects exactly the registered redirect_uris, nothing normalized away' );

// PKCE S256 derivation (RFC7636 Section 4.2), checked against the RFC's own
// worked example rather than only round-tripping our own values.
$oauth_ref          = new ReflectionClass( $oauth_smoke );
$pkce_derive        = $oauth_ref->getMethod( 'pkce_challenge_from_verifier' );
if ( PHP_VERSION_ID < 80100 ) { $pkce_derive->setAccessible( true ); }
$ok( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' === $pkce_derive->invoke( null, 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ), 'mcp-oauth: PKCE S256 challenge derivation matches the RFC7636 worked example exactly' );

// Grant storage: one-way hashed, never the plaintext, matching Perdita_MCP's
// own free-tier key model.
$store_grant = $oauth_ref->getMethod( 'store_grant' );
if ( PHP_VERSION_ID < 80100 ) { $store_grant->setAccessible( true ); }
$get_grant = $oauth_ref->getMethod( 'get_grant' );
if ( PHP_VERSION_ID < 80100 ) { $get_grant->setAccessible( true ); }
$delete_grant = $oauth_ref->getMethod( 'delete_grant' );
if ( PHP_VERSION_ID < 80100 ) { $delete_grant->setAccessible( true ); }

// grant_types enforcement at the token endpoint: a client that never
// declared refresh_token among its grant_types must be rejected even if a
// refresh-type grant somehow exists bound to it.
$reg_no_refresh = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_no_refresh->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://example.test/callback' ), 'token_endpoint_auth_method' => 'none', 'grant_types' => array( 'authorization_code' ) ) ) );
$no_refresh_client_id = $oauth_smoke->handle_register( $reg_no_refresh )->get_data()['client_id'] ?? '';
$ok( ! in_array( 'refresh_token', Perdita_MCP_OAuth::all_clients()[ $no_refresh_client_id ]['grant_types'] ?? array(), true ), 'mcp-oauth: register honors a grant_types list that excludes refresh_token' );

delete_transient( Perdita_MCP::rate_limit_key( Perdita_MCP_OAuth::TOKEN_RATE_LIMIT_PREFIX, Perdita_MCP::client_ip() ) );
$rogue_refresh_token = Perdita_MCP_OAuth::REFRESH_TOKEN_PREFIX . 'smokerogue';
$store_grant->invoke( $oauth_smoke, $rogue_refresh_token, array( 'type' => 'refresh', 'user_id' => 1, 'client_id' => $no_refresh_client_id, 'resource' => Perdita_MCP_OAuth::resource_uri(), 'scope' => 'mcp', 'expires' => time() + HOUR_IN_SECONDS ) );
$refresh_req = new WP_REST_Request( 'POST', '/perdita/v1/oauth/token' );
$refresh_req->set_body_params( array( 'grant_type' => 'refresh_token', 'refresh_token' => $rogue_refresh_token, 'client_id' => $no_refresh_client_id ) );
$refresh_res = $oauth_smoke->handle_token( $refresh_req );
$ok( 400 === $refresh_res->get_status() && 'unauthorized_client' === ( $refresh_res->get_data()['error'] ?? '' ), 'mcp-oauth: token endpoint rejects a refresh_token grant for a client that never declared it' );
$delete_grant->invoke( $oauth_smoke, $rogue_refresh_token );

$smoke_token = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'smoketest123';
$store_grant->invoke( $oauth_smoke, $smoke_token, array( 'type' => 'access', 'user_id' => 1, 'client_id' => $oauth_smoke_client_id, 'resource' => Perdita_MCP_OAuth::resource_uri(), 'scope' => 'mcp', 'expires' => time() + HOUR_IN_SECONDS ) );
$fetched_grant = $get_grant->invoke( $oauth_smoke, $smoke_token );
$ok( is_array( $fetched_grant ) && 1 === $fetched_grant['user_id'], 'mcp-oauth: store_grant()/get_grant() round-trip by plaintext token' );
$all_grants_method = $oauth_ref->getMethod( 'all_grants' );
if ( PHP_VERSION_ID < 80100 ) { $all_grants_method->setAccessible( true ); }
$raw_grants = $all_grants_method->invoke( null );
$ok( ! in_array( $smoke_token, array_keys( $raw_grants ), true ), 'mcp-oauth: the grants option is keyed by hash, never the plaintext token itself' );

// validate_token(): the exact extension point Perdita_MCP::authenticate()
// calls through the perdita_mcp_validate_oauth_token filter.
$ok( false === $oauth_smoke->validate_token( false, 'not-shaped-like-ours' ), 'mcp-oauth: validate_token() lets a non-OAuth-shaped token fall through to $default' );
$ok( 1 === $oauth_smoke->validate_token( false, $smoke_token ), 'mcp-oauth: validate_token() resolves a valid, current, correct-audience access token to its user id' );

$expired_token = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'smokeexpired';
$store_grant->invoke( $oauth_smoke, $expired_token, array( 'type' => 'access', 'user_id' => 1, 'client_id' => $oauth_smoke_client_id, 'resource' => Perdita_MCP_OAuth::resource_uri(), 'scope' => 'mcp', 'expires' => time() - 60 ) );
$ok( false === $oauth_smoke->validate_token( false, $expired_token ), 'mcp-oauth: validate_token() rejects an expired access token' );
$ok( null === $get_grant->invoke( $oauth_smoke, $expired_token ), 'mcp-oauth: validate_token() prunes the expired grant it just rejected' );

$wrong_audience_token = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'smokewrongaud';
$store_grant->invoke( $oauth_smoke, $wrong_audience_token, array( 'type' => 'access', 'user_id' => 1, 'client_id' => $oauth_smoke_client_id, 'resource' => 'https://not-this-site.example/mcp', 'scope' => 'mcp', 'expires' => time() + HOUR_IN_SECONDS ) );
$ok( false === $oauth_smoke->validate_token( false, $wrong_audience_token ), 'mcp-oauth: validate_token() rejects a token minted for a different resource/audience (RFC8707)' );
$delete_grant->invoke( $oauth_smoke, $wrong_audience_token );

// Connections list: what the admin screen (and my profile.php self-service
// fix) render and revoke by.
$oauth_smoke_contrib = wp_insert_user( array( 'user_login' => 'perdita_smoke_mcp_oauth_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
$record_connection = $oauth_ref->getMethod( 'record_connection' );
if ( PHP_VERSION_ID < 80100 ) { $record_connection->setAccessible( true ); }
$record_connection->invoke( $oauth_smoke, $oauth_smoke_contrib, $oauth_smoke_client_id );
$ok( 1 === count( Perdita_MCP_OAuth::connections_for_user( $oauth_smoke_contrib ) ), 'mcp-oauth: record_connection() makes the connection visible via connections_for_user()' );

$ok( Perdita_MCP_OAuth::user_owns_connection( $oauth_smoke_contrib, $oauth_smoke_client_id ), 'mcp-oauth: user_owns_connection() is true for an existing connection' );
$ok( ! Perdita_MCP_OAuth::user_owns_connection( $oauth_smoke_contrib, 'not-a-real-client-id' ), 'mcp-oauth: user_owns_connection() is false for a client the user never connected' );
$ok( ! Perdita_MCP_OAuth::user_owns_connection( 1, $oauth_smoke_client_id ), 'mcp-oauth: user_owns_connection() is false for the right client but the wrong user' );

// Unbounded OPTION_CLIENTS growth: a never-connected client past the
// retention window must be pruned on the next registration, but a client
// that HAS an active connection right now (like $oauth_smoke_client_id,
// backdated here to simulate age) must survive regardless of age.
$prune_test_clients = Perdita_MCP_OAuth::all_clients();
$prune_test_clients[ $oauth_smoke_client_id ]['created'] = time() - ( 31 * DAY_IN_SECONDS );
$prune_test_clients['perdita_mcpc_smokestaleunused00000000000000'] = array(
	'client_name'   => 'Stale Unused Smoke Client',
	'redirect_uris' => array( 'https://example.test/callback' ),
	'grant_types'   => array( 'authorization_code' ),
	'auth_method'   => 'none',
	'secret_hash'   => '',
	'created'       => time() - ( 31 * DAY_IN_SECONDS ),
);
update_option( Perdita_MCP_OAuth::OPTION_CLIENTS, $prune_test_clients, false );
$reg_prune_trigger = new WP_REST_Request( 'POST', '/perdita/v1/oauth/register' );
$reg_prune_trigger->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://example.test/callback' ), 'token_endpoint_auth_method' => 'none' ) ) );
$oauth_smoke->handle_register( $reg_prune_trigger );
$post_prune_clients = Perdita_MCP_OAuth::all_clients();
$ok( ! isset( $post_prune_clients['perdita_mcpc_smokestaleunused00000000000000'] ), 'mcp-oauth: a stale, never-connected client is pruned on the next registration' );
$ok( isset( $post_prune_clients[ $oauth_smoke_client_id ] ), 'mcp-oauth: a client that DOES have an active connection survives pruning regardless of age' );

// A live grant actually bound to this user+client pair (not $smoke_token,
// which belongs to user 1 from the validate_token() checks above): revoking
// the wrong user's connection must never touch it, revoking the right one must.
$contrib_token = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'smokecontrib';
$store_grant->invoke( $oauth_smoke, $contrib_token, array( 'type' => 'access', 'user_id' => $oauth_smoke_contrib, 'client_id' => $oauth_smoke_client_id, 'resource' => Perdita_MCP_OAuth::resource_uri(), 'scope' => 'mcp', 'expires' => time() + HOUR_IN_SECONDS ) );

Perdita_MCP_OAuth::revoke_connection( 1, $oauth_smoke_client_id ); // a different user's revoke must not touch the contributor's grant or connection.
$ok( 1 === count( Perdita_MCP_OAuth::connections_for_user( $oauth_smoke_contrib ) ), 'mcp-oauth: revoke_connection() for a different user leaves this connection untouched' );
$ok( is_array( $get_grant->invoke( $oauth_smoke, $contrib_token ) ), 'mcp-oauth: revoke_connection() for a different user leaves this grant untouched' );

Perdita_MCP_OAuth::revoke_connection( $oauth_smoke_contrib, $oauth_smoke_client_id );
$ok( empty( Perdita_MCP_OAuth::connections_for_user( $oauth_smoke_contrib ) ), 'mcp-oauth: revoke_connection() removes the connection-list entry' );
$ok( null === $get_grant->invoke( $oauth_smoke, $contrib_token ), 'mcp-oauth: revoke_connection() also deletes the still-live access grant for that exact user+client pair, not just the connection-list entry' );

wp_delete_user( $oauth_smoke_contrib );
if ( false === $__orig_oauth_clients ) {
	delete_option( Perdita_MCP_OAuth::OPTION_CLIENTS );
} else {
	update_option( Perdita_MCP_OAuth::OPTION_CLIENTS, $__orig_oauth_clients );
}
if ( false === $__orig_oauth_grants ) {
	delete_option( Perdita_MCP_OAuth::OPTION_GRANTS );
} else {
	update_option( Perdita_MCP_OAuth::OPTION_GRANTS, $__orig_oauth_grants );
}
if ( false === $__orig_oauth_connections ) {
	delete_option( 'perdita_mcp_oauth_connections' );
} else {
	update_option( 'perdita_mcp_oauth_connections', $__orig_oauth_connections );
}


// --- cleanup ---
wp_delete_post( $form_id, true );
$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE form_id=%d", $form_id ) );
if ( false === $__orig_settings ) {
	delete_option( 'perdita_settings' );
} else {
	update_option( 'perdita_settings', $__orig_settings );
}
if ( false === $__orig_seo ) {
	delete_option( 'perdita_seo' );
} else {
	update_option( 'perdita_seo', $__orig_seo );
}
if ( false === $__orig_core ) {
	delete_option( 'perdita_core' );
} else {
	update_option( 'perdita_core', $__orig_core );
}
perdita()->settings->flush();


// --- optional fragments: tests/smoke-*.php run in the same harness ---------
// Each fragment shares the helpers and the $pass/$fail counters above, so a
// focused suite (a regression for one fix) can live in its own file without
// two people editing this one at once.
foreach ( (array) glob( __DIR__ . '/smoke-*.php' ) as $perdita_fragment ) {
	require $perdita_fragment;
}

echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
