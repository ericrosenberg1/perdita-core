<?php
/**
 * Module registry and loader.
 *
 * The point of this class is the "lightweight even with everything on"
 * promise: a module's implementation code is only required, and only
 * instantiated, when the module is enabled AND the current request is one of
 * the contexts it runs in. A disabled module costs one option read and
 * nothing else. An enabled front-end-only module never loads on a REST call.
 *
 * Two ways to register:
 *   1. Folder modules. Drop inc/modules/<id>/module.php that returns a
 *      descriptor array. discover() scans and reads them (the descriptor is
 *      tiny metadata, the heavy class file is named in it and loaded lazily).
 *   2. Code modules. Core calls register() with a descriptor whose 'boot' is
 *      a closure. Used for the built-ins (SEO, Forms) whose wiring is bespoke.
 *
 * Descriptor shape:
 *   id          string   unique slug, becomes option key perdita_core.modules.<id>
 *   label       string   admin label
 *   description string   one line for the Modules screen
 *   group       string   design|content|performance|commerce|infrastructure|security
 *   default     bool     enabled out of the box?
 *   contexts    array    any of: front, admin, rest, cron, always
 *   -- folder modules provide:
 *   file        string   main class file, relative to the module dir
 *   class       string   main class name (constructed with the Perdita core)
 *   admin_file  string   optional admin class file
 *   admin_class string   optional admin class name (admin context only)
 *   -- code modules provide:
 *   boot        callable receives the Perdita core, wires the module itself
 *   -- optional lifecycle:
 *   activate    callable install tables/options
 *   uninstall   callable drop tables/options
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Modules {

	/**
	 * The Perdita Core bootstrap, passed to every module so it can reach the
	 * seo store, the module registry, and (through the plugin's references to
	 * the theme) settings, ai, css and fonts.
	 *
	 * @var Perdita_Core
	 */
	private $core;

	/**
	 * Registered descriptors, keyed by id.
	 *
	 * @var array[]
	 */
	private $registry = array();

	/**
	 * Ids of modules already booted, so boot() can safely run more than once
	 * per request without double-instantiating a module. This exists because
	 * of a WordPress timing gap: REST_REQUEST is not defined until the
	 * parse_request hook (inside rest_api_loaded()), which fires AFTER init,
	 * so a module booted only on init can never see context() return 'rest'
	 * on its very first boot() pass, even on a genuine REST request. Modules
	 * that also declare 'front' never noticed this (they boot on init via
	 * 'front' regardless), but a module that declares 'rest' without 'front'
	 * (its only reason to run is answering REST calls) would otherwise never
	 * boot at all. See Perdita_Core::init()'s two boot_modules() hookups (init and
	 * rest_api_init) for the other half of this fix.
	 *
	 * @var string[]
	 */
	private $booted = array();

	/**
	 * Constructor.
	 *
	 * @param Perdita_Core $core Bootstrap.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Register a descriptor (code module, or a discovered folder module).
	 *
	 * @param array $d Descriptor.
	 */
	public function register( array $d ) {
		if ( empty( $d['id'] ) ) {
			return;
		}
		$d += array(
			'label'       => $d['id'],
			'description' => '',
			'group'       => 'general',
			'default'     => false,
			'contexts'    => array( 'always' ),
		);
		$this->registry[ $d['id'] ] = $d;
	}

	/**
	 * Scan inc/modules/*\/module.php for folder modules.
	 */
	public function discover() {
		foreach ( (array) glob( PERDITA_CORE_DIR . 'inc/modules/*/module.php' ) as $file ) {
			$d = include $file;
			if ( is_array( $d ) && ! empty( $d['id'] ) ) {
				$d['path'] = dirname( $file );
				$this->register( $d );
			}
		}
	}

	/**
	 * All registered descriptors (for the admin Modules screen).
	 *
	 * @return array[]
	 */
	public function all() {
		return $this->registry;
	}

	/**
	 * Whether a module is enabled.
	 *
	 * Reads this PLUGIN's own option (perdita_core -> modules -> <id>), not
	 * the theme's design token document, which is where the flag used to live
	 * as modules.<id> with an older features.<id> fallback. Both of those are
	 * copied across once by Perdita_Core::migrate_module_state(), so an
	 * upgrading site keeps every choice it had made; from then on the theme
	 * option is never consulted, and a theme reset can no longer switch a
	 * module off. A module with no stored preference falls back to its
	 * descriptor default.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function is_enabled( $id ) {
		if ( ! isset( $this->registry[ $id ] ) ) {
			return false;
		}
		$stored = Perdita_Core::module_state( $id );
		if ( null !== $stored ) {
			return $stored;
		}
		return (bool) $this->registry[ $id ]['default'];
	}

	/**
	 * Write a module's enable state to the plugin option.
	 *
	 * The counterpart to is_enabled(), so both halves of the toggle read and
	 * write the same store. The Modules screen calls this; so can a companion
	 * plugin that wants to switch one of its own modules on.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled Whether it is on.
	 * @return bool
	 */
	public function set_module_state( $id, $enabled ) {
		return Perdita_Core::set_module_state( $id, $enabled );
	}

	/**
	 * The current request context.
	 *
	 * @return string front|admin|rest|cron
	 */
	private function context() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		return 'front';
	}

	/**
	 * Whether a descriptor should run in the current context.
	 *
	 * @param array $d Descriptor.
	 * @return bool
	 */
	private function in_context( array $d ) {
		$contexts = (array) $d['contexts'];
		if ( in_array( 'always', $contexts, true ) ) {
			return true;
		}
		return in_array( $this->context(), $contexts, true );
	}

	/**
	 * Boot every enabled module whose context matches and that hasn't
	 * already booted this request. Folder modules load their class file
	 * here (lazily); code modules run their boot closure.
	 *
	 * Safe to call more than once per request (see the $booted property
	 * docblock for why Perdita_Core::init() does exactly that, on both init and
	 * rest_api_init): a module already booted on an earlier pass is skipped,
	 * so nothing is ever instantiated twice.
	 */
	public function boot() {
		foreach ( $this->registry as $id => $d ) {
			if ( isset( $this->booted[ $id ] ) || ! $this->is_enabled( $id ) || ! $this->in_context( $d ) ) {
				continue;
			}
			$this->booted[ $id ] = true;

			if ( isset( $d['boot'] ) && is_callable( $d['boot'] ) ) {
				call_user_func( $d['boot'], $this->core );
				continue;
			}

			if ( empty( $d['class'] ) || empty( $d['file'] ) || empty( $d['path'] ) ) {
				continue;
			}
			require_once $d['path'] . '/' . $d['file'];
			if ( ! class_exists( $d['class'] ) ) {
				continue;
			}
			$instance = new $d['class']( $this->core );

			if ( is_admin() && ! empty( $d['admin_class'] ) && ! empty( $d['admin_file'] ) ) {
				require_once $d['path'] . '/' . $d['admin_file'];
				if ( class_exists( $d['admin_class'] ) ) {
					new $d['admin_class']( $instance, $this->core );
				}
			}
		}
	}

	/**
	 * Run a module's activate callback (create tables/options). Called when a
	 * module is switched on, and once per module on plugin activation.
	 *
	 * @param string $id Module id.
	 */
	public function activate( $id ) {
		$d = isset( $this->registry[ $id ] ) ? $this->registry[ $id ] : null;
		if ( $d && ! empty( $d['file'] ) && ! empty( $d['path'] ) ) {
			require_once $d['path'] . '/' . $d['file'];
		}
		if ( $d && isset( $d['activate'] ) && is_callable( $d['activate'] ) ) {
			call_user_func( $d['activate'], $this->core );
		}
	}

	/**
	 * Run every enabled module's activate callback. Called from plugin
	 * activation (and from the db-version catch-up on admin_init) so a fresh
	 * install has its tables.
	 */
	public function activate_enabled() {
		foreach ( array_keys( $this->registry ) as $id ) {
			if ( $this->is_enabled( $id ) ) {
				$this->activate( $id );
			}
		}
	}
}
