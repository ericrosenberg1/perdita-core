<?php
/**
 * Plugin bootstrap. Loads modules and wires the framework together.
 *
 * This is the plugin-side twin of the theme's Perdita class, and it keeps the
 * same boot order on purpose: modules register and boot on `init` priority 0,
 * again on `rest_api_init` priority 0 for REST-only modules, and the
 * REST-bound subsystems build from rest_api_init (or straight away on an
 * admin request). Anything that reads differently from the theme's version is
 * commented with why.
 *
 * What still lives in the theme and is read through perdita():
 *   perdita()->settings  the design token document (option perdita_settings)
 *   perdita()->ai        the AI provider router, used by Perdita_Section
 *   perdita()->fonts     Google Fonts helper
 *   perdita()->css       the CSS generator
 *   Perdita_Crypto       static, encrypts every module secret at rest
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

final class Perdita_Core {

	/**
	 * This plugin's own option. A single autoloaded array:
	 *   modules    array  id => bool, the enable state for every module
	 *   migrated   int    unix time the theme's state was copied in
	 *   db_version string schema version, drives maybe_upgrade()
	 *
	 * Module state used to live in the theme's perdita_settings document
	 * (modules.<id>, with the older features.seo / features.forms flags
	 * before that). It moved here because the modules themselves moved: a
	 * site that switches away from Perdita keeps this plugin, its tables and
	 * its toggles, and none of that should be stored in a theme option that
	 * a theme reset would clear.
	 */
	const OPTION = 'perdita_core';

	/**
	 * Schema version for this plugin's own installed state (module tables and
	 * directories). Bumping it re-runs every enabled module's activate
	 * callback on the next admin request.
	 */
	const DB_VERSION = '1';

	/**
	 * Cron events this plugin's modules can schedule. Cleared on deactivation
	 * and again on uninstall, so a site that removes the plugin is not left
	 * firing events with no handler.
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array(
		'perdita_backups_run',            // Perdita_Backups::CRON_HOOK.
		'perdita_subscriptions_send_batch', // Perdita_Subscriptions::CRON_HOOK.
	);

	/**
	 * Singleton instance.
	 *
	 * @var Perdita_Core|null
	 */
	private static $instance = null;

	/**
	 * Module registry and loader.
	 *
	 * @var Perdita_Modules
	 */
	public $modules;

	/**
	 * SEO settings store (option perdita_seo).
	 *
	 * This is the STORE, not the front-end renderer, matching what the
	 * theme's perdita()->seo was: the seo module's boot closure builds
	 * Perdita_SEO from it, and both this plugin's modules and Perdita Pro
	 * call $core->seo->get() / ->image_url(), which are store methods.
	 *
	 * @var Perdita_SEO_Store
	 */
	public $seo;

	/**
	 * Forms engine. Built by the forms module's boot closure, so it is null
	 * until `init` and stays null when the module is off.
	 *
	 * @var Perdita_Forms|null
	 */
	public $forms;

	/**
	 * Footer and copyright shortcodes. Always on.
	 *
	 * @var Perdita_Shortcodes
	 */
	public $shortcodes;

	/**
	 * The block-editor "describe a section" sidebar. Built from rest_api_init
	 * or an admin request, so it is null on an ordinary front-end page view.
	 *
	 * @var Perdita_Section|null
	 */
	public $section;

	/**
	 * The theme's token document. A reference to perdita()->settings, kept
	 * here so a module that receives this object as $core can reach settings
	 * exactly the way it did when the theme owned it.
	 *
	 * @var Perdita_Settings
	 */
	public $settings;

	/**
	 * The theme's AI provider router (perdita()->ai). Read by the MCP module
	 * for its status tool and by Perdita_Section.
	 *
	 * @var Perdita_AI
	 */
	public $ai;

	/**
	 * The theme's CSS generator (perdita()->css).
	 *
	 * @var Perdita_CSS
	 */
	public $css;

	/**
	 * The theme's Google Fonts helper (perdita()->fonts).
	 *
	 * @var Perdita_Fonts
	 */
	public $fonts;

	/**
	 * Whether register_builtin_modules()/discover() have already run this
	 * request. boot_modules() fires on both `init` and `rest_api_init`, but
	 * only Perdita_Modules::boot() needs the second pass; re-registering and
	 * re-globbing descriptors on every REST request (every MCP JSON-RPC call
	 * included) is wasted work.
	 *
	 * @var bool
	 */
	private $modules_registered = false;

	/**
	 * Whether Perdita_Section has already been built this request. It is
	 * constructed from rest_api_init AND from an admin request (it carries
	 * the block-editor enqueue as well as a REST route), and an admin request
	 * can dispatch an internal REST call, so this keeps a single instance
	 * either way.
	 *
	 * @var bool
	 */
	private $rest_subsystems_booted = false;

	/**
	 * Get the singleton.
	 *
	 * @return Perdita_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether the Perdita theme is active and its engine is loaded.
	 *
	 * Three separate checks because each fails differently. get_template()
	 * catches a site running a child theme of Perdita (the common real
	 * deployment) as well as Perdita itself. function_exists('perdita') and
	 * class_exists('Perdita_Crypto') catch a theme directory named 'perdita'
	 * that is not this theme, and a Perdita install whose functions.php
	 * bailed before wiring the engine up.
	 *
	 * @return bool
	 */
	public static function theme_is_ready() {
		return 'ok' === self::theme_status();
	}

	/**
	 * Whether the plugin actually booted on this request (theme present and
	 * new enough). False while it is installed but inert.
	 *
	 * @return bool
	 */
	public static function is_booted() {
		return (bool) did_action( 'perdita_core_booted' );
	}

	/**
	 * The theme has to be 0.17.0-alpha or newer, the first version that no
	 * longer declares Perdita_Modules, Perdita_SEO_Store, Perdita_Shortcodes
	 * and Perdita_Section itself. Booting this plugin against an older theme
	 * would redeclare those classes and fatal on every request, so a site
	 * that activates the plugin first (the safe upgrade order) stays inert
	 * until the theme is updated, and then boots on the next request.
	 */
	const MIN_THEME = '0.17.0-alpha';

	/**
	 * Why the plugin cannot boot right now, or 'ok'.
	 *
	 * @return string ok|missing|old
	 */
	public static function theme_status() {
		if ( ! function_exists( 'perdita' ) || ! class_exists( 'Perdita_Crypto' ) || 'perdita' !== wp_get_theme()->get_template() ) {
			return 'missing';
		}
		if ( ! defined( 'PERDITA_VERSION' ) || version_compare( PERDITA_VERSION, self::MIN_THEME, '<' ) ) {
			return 'old';
		}
		return 'ok';
	}

	/**
	 * Constructor. Load everything.
	 */
	private function __construct() {
		$this->includes();
		$this->init();
	}

	/* ---------------------------------------------------------------------
	 * Plugin option: module enable state
	 * ------------------------------------------------------------------- */

	/**
	 * This plugin's stored state, always an array.
	 *
	 * Named state(), not settings(), so it can never be confused with the
	 * $settings property above, which is the THEME's design token document.
	 *
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist this plugin's state. Autoloaded: it is read on every request to
	 * decide which modules boot, so making WordPress fetch it separately would
	 * cost a query on every page view.
	 *
	 * @param array $state Full state array.
	 * @return bool
	 */
	public static function save_state( array $state ) {
		return update_option( self::OPTION, $state, true );
	}

	/**
	 * The stored enable state for one module, or null when the site has
	 * never expressed a preference (so the descriptor default wins).
	 *
	 * @param string $id Module id.
	 * @return bool|null
	 */
	public static function module_state( $id ) {
		$state = self::state();
		if ( ! isset( $state['modules'] ) || ! is_array( $state['modules'] ) ) {
			return null;
		}
		return array_key_exists( $id, $state['modules'] ) ? (bool) $state['modules'][ $id ] : null;
	}

	/**
	 * Write one module's enable state.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled Whether it is on.
	 * @return bool
	 */
	public static function set_module_state( $id, $enabled ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return false;
		}
		$state = self::state();
		if ( ! isset( $state['modules'] ) || ! is_array( $state['modules'] ) ) {
			$state['modules'] = array();
		}
		$state['modules'][ $id ] = (bool) $enabled;
		return self::save_state( $state );
	}

	/**
	 * Copy the theme's module state into this plugin's option, once.
	 *
	 * Runs on the first boot after this plugin is installed on a site that
	 * had the modules inside the theme, and again on activation so a fresh
	 * activation never depends on a front-end page view happening first.
	 *
	 * Precedence matches what Perdita_Modules::is_enabled() used to do:
	 * perdita_settings['modules'][id] wins, and the older
	 * perdita_settings['features']['seo'|'forms'] flags fill in for the two
	 * built-ins on sites old enough to predate the module registry. The
	 * theme's option is only read, never written or removed: another agent's
	 * theme still owns it, and a site that rolls this plugin back has to find
	 * its toggles where it left them.
	 *
	 * @return bool True when a migration was written this call.
	 */
	public static function migrate_module_state() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return false;
		}

		$legacy  = get_option( 'perdita_settings', array() );
		$legacy  = is_array( $legacy ) ? $legacy : array();
		$modules = array();

		// Legacy features.* first, so a modules.* entry for the same id
		// overwrites it below, exactly as the old lookup order resolved.
		if ( isset( $legacy['features'] ) && is_array( $legacy['features'] ) ) {
			foreach ( array( 'seo', 'forms' ) as $builtin ) {
				if ( array_key_exists( $builtin, $legacy['features'] ) ) {
					$modules[ $builtin ] = (bool) $legacy['features'][ $builtin ];
				}
			}
		}
		if ( isset( $legacy['modules'] ) && is_array( $legacy['modules'] ) ) {
			foreach ( $legacy['modules'] as $id => $on ) {
				$id = sanitize_key( $id );
				if ( '' !== $id ) {
					$modules[ $id ] = (bool) $on;
				}
			}
		}

		return self::save_state(
			array(
				'modules'    => $modules,
				'migrated'   => time(),
				'db_version' => '',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Activation, deactivation, upgrade
	 * ------------------------------------------------------------------- */

	/**
	 * Activation hook. Bring the module state across, then create the tables
	 * and directories every enabled module needs.
	 *
	 * The theme did this half on `after_switch_theme`
	 * (Perdita_Modules::activate_enabled). Plugin activation is the right
	 * moment for it now, and maybe_upgrade() below covers the case where the
	 * files were updated in place without a deactivate/reactivate cycle.
	 */
	public static function on_activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::on_activate( false );
				restore_current_blog();
			}
			return;
		}
		self::migrate_module_state();
		self::install_modules();
	}

	/**
	 * Deactivation hook. Clear scheduled events and the update manifest.
	 *
	 * Nothing else: a deactivate/reactivate cycle has to be lossless, so no
	 * option, table, or stored entry is touched here. Removal is uninstall.php.
	 */
	public static function on_deactivate() {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		delete_transient( 'perdita_core_update_manifest' );
	}

	/**
	 * Run every enabled module's activate callback (tables, directories,
	 * generated secrets) and record the schema version.
	 *
	 * Safe to call more than once: every module's install routine is written
	 * to be idempotent (dbDelta, wp_mkdir_p, "generate a secret if absent").
	 */
	public static function install_modules() {
		if ( ! self::theme_is_ready() ) {
			// Activated before the theme. maybe_upgrade() picks this up on
			// the first admin request after the theme is switched on.
			return;
		}
		$core = self::instance();
		$core->register_modules();
		$core->modules->activate_enabled();

		$state               = self::state();
		$state['db_version'] = self::DB_VERSION;
		self::save_state( $state );
	}

	/**
	 * Catch up a site whose plugin files were replaced without a
	 * deactivate/reactivate cycle (an in-place update, a git pull, a
	 * filesystem deploy). Hooked to admin_init, so it costs one already
	 * autoloaded option read on an admin request and nothing at all on the
	 * front end.
	 */
	public function maybe_upgrade() {
		$state  = self::state();
		$stored = isset( $state['db_version'] ) ? (string) $state['db_version'] : '';
		if ( self::DB_VERSION === $stored ) {
			return;
		}
		self::install_modules();
	}

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	/**
	 * Pull in the always-loaded files: the module registry, the SEO store,
	 * the shortcodes, and the section editor. Everything past this is a
	 * module that loads on demand.
	 */
	private function includes() {
		$files = array(
			'inc/class-perdita-modules.php',
			'inc/class-perdita-seo-store.php',
			'inc/class-perdita-shortcodes.php',
			'inc/class-perdita-section.php',
		);
		foreach ( $files as $file ) {
			require_once PERDITA_CORE_DIR . $file;
		}
	}

	/**
	 * Instantiate subsystems and register hooks.
	 */
	private function init() {
		$theme = perdita();

		// References to the theme's shared engine, so a module handed this
		// object as $core reaches settings/ai/css/fonts by the same property
		// names it used when the theme owned the modules.
		$this->settings = $theme->settings;
		$this->ai       = $theme->ai;
		$this->css      = $theme->css;
		$this->fonts    = $theme->fonts;

		$this->seo     = new Perdita_SEO_Store();
		$this->modules = new Perdita_Modules( $this );

		// Always-on: the footer and copyright shortcodes. They are ordinary
		// shortcodes, so they have to be registered whether or not any module
		// is enabled, and on every context (a REST-rendered excerpt runs
		// do_shortcode too).
		$this->shortcodes = new Perdita_Shortcodes( $this->settings );

		// Self-hosted updates. Only built where an update can actually be
		// checked or installed, and only when the file is present: a
		// wordpress.org build omits it entirely (see bin/build-release.sh
		// --wporg), which is why this is file_exists-guarded rather than
		// unconditionally required.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$updater_file = PERDITA_CORE_DIR . 'inc/class-perdita-core-updater.php';
			if ( file_exists( $updater_file ) ) {
				require_once $updater_file;
				new Perdita_Core_Updater();
			}
		}

		// Perdita_Section registers a REST route AND the block editor's
		// script enqueue. REST_REQUEST is not defined until parse_request,
		// long after a plugin loads, so rest_api_init is the only sound test
		// for "this is a REST request" at this point. Priority 0 mirrors
		// boot_modules() below: a callback added at the default priority from
		// inside a priority-0 callback still runs in the same do_action pass,
		// so the route registers normally.
		add_action( 'rest_api_init', array( $this, 'boot_rest_subsystems' ), 0 );
		if ( is_admin() ) {
			$this->boot_rest_subsystems();
		}

		if ( is_admin() ) {
			require_once PERDITA_CORE_DIR . 'inc/class-perdita-core-admin.php';
			new Perdita_Core_Admin( $this );
			add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		}

		// Copy the theme's module toggles across on the first request after
		// this plugin is installed. get_option() on an autoloaded option that
		// already exists is free, so this costs nothing from then on.
		self::migrate_module_state();

		// Register and boot modules on `init`. Their descriptors carry
		// translated labels (__), and WP 6.7+ warns when a translation
		// function runs before init. Priority 0 boots modules ahead of most
		// init work.
		add_action( 'init', array( $this, 'boot_modules' ), 0 );

		// Boot again on `rest_api_init`, at the earliest priority, for
		// modules whose contexts include 'rest' but not 'front' (a module
		// whose only job is answering REST calls, e.g. the MCP server).
		// WordPress does not define REST_REQUEST until parse_request, which
		// runs AFTER init, so on the very first (init) boot pass
		// Perdita_Modules::context() can never see 'rest' yet even on a
		// genuine REST request. rest_api_init fires from inside
		// rest_api_loaded(), after REST_REQUEST is defined and before the
		// request is dispatched, which is the correct, and only, moment such
		// a module can register its routes. Perdita_Modules::boot() is
		// idempotent, so this never double-instantiates a module that already
		// booted on init because it also declares 'front' or 'admin'.
		add_action( 'rest_api_init', array( $this, 'boot_modules' ), 0 );
	}

	/**
	 * Instantiate the REST-bound subsystems. Called from rest_api_init (the
	 * earliest point at which a REST request can be identified) and directly
	 * on an admin request, since Perdita_Section also registers the block
	 * editor's script enqueue.
	 */
	public function boot_rest_subsystems() {
		if ( $this->rest_subsystems_booted ) {
			return;
		}
		$this->rest_subsystems_booted = true;
		$this->section                = new Perdita_Section( $this->ai );
	}

	/**
	 * Register and boot modules. Deferred to `init` so their translated
	 * labels do not trigger WP 6.7's "translation loaded too early" notice.
	 */
	public function boot_modules() {
		$this->register_modules();
		$this->modules->boot();
	}

	/**
	 * Fill the registry: the two built-in code modules, the folder modules
	 * under inc/modules/, and whatever a companion plugin adds.
	 *
	 * Split out of boot_modules() so activation can build the same registry
	 * without booting anything (install_modules() needs descriptors and their
	 * activate callbacks, not running module instances).
	 */
	public function register_modules() {
		if ( $this->modules_registered ) {
			return;
		}
		$this->modules_registered = true;
		$this->register_builtin_modules();
		$this->modules->discover();

		/**
		 * Fires once per request, after this plugin's own free modules are
		 * registered and discovered but before anything boots. A companion
		 * plugin (Perdita Pro) hooks this to register its own additional
		 * modules into this SAME registry via
		 * $modules->register( $descriptor ), with 'path' pointing into the
		 * plugin's own directory rather than inc/modules/. From here on a Pro
		 * module behaves exactly like a free folder module (Modules screen
		 * listing, enable/disable toggle, activate() lifecycle, lazy
		 * require/instantiate), with no changes needed to Perdita_Modules
		 * itself: register()/boot()/activate() only ever use $d['path'] to
		 * build a require path, they never assume it lives here.
		 *
		 * @param Perdita_Modules $modules The module registry.
		 */
		do_action( 'perdita_register_modules', $this->modules );
	}

	/**
	 * Register the built-in SEO and Forms modules. Their wiring is bespoke,
	 * so they are code modules (boot closures) rather than folder modules,
	 * but they appear in the Modules screen and honor the same enable flag as
	 * the rest. Both default ON: they are what most sites install this plugin
	 * for, and neither writes anything until it is used.
	 */
	private function register_builtin_modules() {
		$this->modules->register(
			array(
				'id'          => 'seo',
				'label'       => __( 'Perdita SEO', 'perdita-core' ),
				'description' => __( 'Titles, meta, social cards, schema, and Yoast/AIOSEO import.', 'perdita-core' ),
				'group'       => 'content',
				'default'     => true,
				'contexts'    => array( 'front', 'admin', 'rest' ),
				'boot'        => function ( $core ) {
					require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo.php';
					new Perdita_SEO( $core->seo );
					if ( is_admin() ) {
						require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-admin.php';
						new Perdita_SEO_Admin( $core->seo );
					}
				},
			)
		);

		$this->modules->register(
			array(
				'id'          => 'forms',
				'label'       => __( 'Perdita Forms', 'perdita-core' ),
				'description' => __( 'Build contact forms with stored entries and spam protection.', 'perdita-core' ),
				'group'       => 'content',
				'default'     => true,
				'contexts'    => array( 'front', 'admin', 'rest' ),
				'boot'        => function ( $core ) {
					require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms.php';
					$core->forms = new Perdita_Forms();
					if ( is_admin() ) {
						require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms-admin.php';
						new Perdita_Forms_Admin( $core->forms );
					}
				},
				// Forms is a code module, so it has no descriptor 'file' for
				// Perdita_Modules::activate() to require first. The closure
				// loads the class itself, then creates the entries table the
				// same way the module's own init hook would.
				'activate'    => function ( $core ) {
					unset( $core );
					require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms.php';
					Perdita_Forms::install();
				},
			)
		);
	}
}
