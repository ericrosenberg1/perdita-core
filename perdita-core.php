<?php
/**
 * Plugin Name:       Perdita Core
 * Plugin URI:        https://perdita.ericrosenberg.com
 * Description:       The free companion plugin for the Perdita theme. Adds SEO, forms, caching, security, analytics, email, and more as modules you turn on one at a time.
 * Version:           0.19.0-alpha
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Eric Rosenberg
 * Author URI:        https://ericrosenberg.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perdita-core
 * Domain Path:       /languages
 *
 * Why this is a plugin and not part of the theme: everything in here is
 * plugin territory by the wordpress.org theme review rules (SEO output,
 * forms, page caching, analytics, shortcodes, custom post types, custom
 * capabilities). The theme keeps design: the token document, the CSS
 * generator, the Customizer, fonts, patterns, the AI provider router, and
 * Perdita_Crypto. This plugin keeps behavior, and reads the theme's shared
 * pieces through perdita().
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'PERDITA_CORE_VERSION', '0.19.0-alpha' );
define( 'PERDITA_CORE_FILE', __FILE__ );
define( 'PERDITA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERDITA_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once PERDITA_CORE_DIR . 'inc/class-perdita-core.php';

/**
 * Main accessor. The single source of truth for the plugin instance, and the
 * name every moved class now calls instead of the theme's perdita().
 *
 * Only ever call this from after_setup_theme priority 0 onward, and only when
 * Perdita_Core::theme_is_ready() is true: the instance reaches into
 * perdita()->settings and perdita()->ai while it builds.
 *
 * @return Perdita_Core
 */
function perdita_core() {
	return Perdita_Core::instance();
}

/**
 * Boot on after_setup_theme priority 0.
 *
 * Plugins load before the active theme's functions.php, so plugins_loaded is
 * too early: perdita(), Perdita_Crypto and the token document do not exist
 * yet. functions.php is included between setup_theme and after_setup_theme,
 * so priority 0 here is the first moment the theme's own engine is available
 * and still early enough to hook init, rest_api_init and admin_menu.
 */
function perdita_core_boot() {
	if ( ! Perdita_Core::theme_is_ready() ) {
		add_action( 'admin_notices', 'perdita_core_theme_notice' );
		return;
	}
	perdita_core();
	if ( is_admin() ) {
		add_action( 'admin_notices', 'perdita_core_version_notice' );
	}
	/**
	 * Fires once the plugin has booted against a compatible theme. Perdita
	 * Pro keys its own dependency check on this (Perdita_Core::is_booted()),
	 * not on the plugin file merely being loaded.
	 *
	 * @param Perdita_Core $core The plugin instance.
	 */
	do_action( 'perdita_core_booted', perdita_core() );
}
add_action( 'after_setup_theme', 'perdita_core_boot', 0 );

/**
 * Warn when the theme, this plugin, and Perdita Pro are not on the same
 * version. They release together, so a difference means one piece has been
 * updated and the others have not yet: normal for the hours between a
 * wordpress.org auto-update and the next plugin update check, wrong for any
 * longer. Not dismissible, because it stops being true only when the
 * versions match again.
 *
 * This plugin owns the notice whenever it has booted. The theme shows its
 * own copy only when this plugin predates version_drift(), and Pro only
 * when both do, so a site never sees it twice.
 */
function perdita_core_version_notice() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$drift = Perdita_Core::version_drift();
	if ( $drift ) {
		perdita_core_render_version_notice( $drift );
	}
}

/**
 * The out-of-step warning markup for a version_drift() result.
 *
 * @param array $drift slug => version, from Perdita_Core::version_drift().
 */
function perdita_core_render_version_notice( array $drift ) {
	$labels = array(
		'theme' => __( 'Perdita theme', 'perdita-core' ),
		'core'  => __( 'Perdita Core', 'perdita-core' ),
		'pro'   => __( 'Perdita Pro', 'perdita-core' ),
	);
	$parts = array();
	foreach ( $drift as $slug => $version ) {
		$parts[] = ( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug ) . ' ' . $version;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html(
		sprintf(
			/* translators: %s: list like "Perdita theme 0.18.0-alpha, Perdita Core 0.17.1-alpha" */
			__( 'Perdita versions are out of step: %s. The theme, Perdita Core, and Perdita Pro are released together and should all be on the same version, so update whichever is behind.', 'perdita-core' ),
			implode( ', ', $parts )
		)
	);
	printf(
		' <a href="%s">%s</a>',
		esc_url( self_admin_url( 'update-core.php' ) ),
		esc_html__( 'Check for updates', 'perdita-core' )
	);
	echo '</p></div>';
}

/**
 * Tell an administrator why nothing this plugin does is happening.
 *
 * Registering nothing else is deliberate. Half of this code type-hints the
 * theme's classes (Perdita_Settings, Perdita_AI) or reads perdita()->settings
 * directly, so booting without the theme would not degrade, it would fatal.
 */
function perdita_core_theme_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( class_exists( 'Perdita_Core' ) && 'old' === Perdita_Core::theme_status() ) {
		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			/* translators: %s: minimum Perdita theme version. */
			esc_html__( 'Perdita Core is waiting for a theme update: it needs Perdita %s or newer. Update the Perdita theme and the modules switch on by themselves.', 'perdita-core' ),
			esc_html( Perdita_Core::MIN_THEME )
		);
		echo '</p></div>';
		return;
	}
	echo '<div class="notice notice-warning is-dismissible"><p>';
	echo esc_html__( 'Perdita Core needs the Perdita theme. Activate Perdita (or a child theme of it) and this plugin\'s modules will switch back on with their settings intact.', 'perdita-core' );
	echo '</p></div>';
}

/**
 * Load translations. On init, not earlier: WordPress 6.7+ warns when a
 * translation function runs before init, and every module descriptor label
 * goes through __().
 */
function perdita_core_i18n() {
	load_plugin_textdomain( 'perdita-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'perdita_core_i18n' );

register_activation_hook( __FILE__, array( 'Perdita_Core', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Perdita_Core', 'on_deactivate' ) );
