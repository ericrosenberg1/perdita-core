<?php
/**
 * Perdita Firewall, the WordPress side: compiles the settings into
 * wp-content/perdita-firewall/config.php, runs the engine in plugin mode,
 * signs the trust cookie, and cleans up on deactivate and uninstall.
 *
 * perdita-core.php calls register() on every request. It costs two autoloaded
 * option reads and a handful of add_action() calls.
 *
 * Extension points for Perdita Pro:
 *   - filter perdita_firewall_config( array $config, array $settings ):
 *     add 'ip_blocklist', 'ip_allowlist' (strings, merged with Core's list),
 *     'custom_rules', 'country', and 'rate_limits'. The shapes are in the
 *     docblock of class-perdita-firewall.php. The compiler parses the two IP
 *     lists into ranges after the filter runs.
 *   - Perdita_Firewall_Config::compile() rebuilds the file. Pro calls it after
 *     saving its own settings (the update_option_perdita_security_pro_settings
 *     hook already does).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Firewall_Config {

	/**
	 * Option holding the trust cookie HMAC key.
	 */
	const KEY_OPTION = 'perdita_firewall_key';

	/**
	 * Option holding the last loader result, for the settings screen.
	 */
	const STATE_OPTION = 'perdita_firewall_loader_state';

	/**
	 * First line marker of every file this class writes.
	 */
	const MARKER = 'Perdita Firewall';

	/**
	 * Every rule category, all on once the firewall is on.
	 */
	const CATEGORIES = array( 'sqli', 'xss', 'traversal', 'rfi', 'php_upload', 'bad_bots', 'probes', 'xmlrpc' );

	/**
	 * Whether the next compile may add a prepend block (an owner save).
	 *
	 * @var bool
	 */
	private static $allow_server_config = true;

	/* ---------- wiring ---------- */

	/**
	 * Hook everything. Runs from perdita-core.php on every request.
	 */
	public static function register() {
		add_action( 'add_option_perdita_security_settings', array( __CLASS__, 'on_add' ), 10, 2 );
		add_action( 'update_option_perdita_security_settings', array( __CLASS__, 'on_update' ), 10, 2 );
		add_action( 'add_option_perdita_security_pro_settings', array( __CLASS__, 'on_add' ), 10, 2 );
		add_action( 'update_option_perdita_security_pro_settings', array( __CLASS__, 'on_update' ), 10, 2 );
		add_action( 'update_option_perdita_core', array( __CLASS__, 'on_module_state' ), 10, 2 );
		add_action( 'add_option_perdita_core', array( __CLASS__, 'on_add' ), 10, 2 );
		add_action( 'admin_post_perdita_firewall_allow', array( __CLASS__, 'handle_allow' ) );

		if ( ! self::active() ) {
			return;
		}
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'on_set_logged_in_cookie' ), 10, 6 );
		add_action( 'wp_login', array( __CLASS__, 'on_wp_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'clear_trust_cookie' ) );
		add_action( 'init', array( __CLASS__, 'refresh_trust_cookie' ), 1 );

		// Plugin mode, and the fallback for mu and prepend when that loader
		// isn't running yet: the engine runs here unless an earlier loader ran.
		if ( ! defined( 'PERDITA_FIREWALL_LOADED' ) && is_file( self::config_path() ) ) {
			require_once __DIR__ . '/class-perdita-firewall.php';
			Perdita_Firewall::boot( self::config_path(), 'plugin' );
		}
	}

	/**
	 * Whether the security module and the firewall are both on, from the
	 * autoloaded options.
	 *
	 * @return bool
	 */
	public static function active() {
		$core = get_option( 'perdita_core', array() );
		if ( ! is_array( $core ) || empty( $core['modules']['security'] ) ) {
			return false;
		}
		$settings = get_option( 'perdita_security_settings', array() );
		return is_array( $settings ) && ! empty( $settings['firewall'] );
	}

	/**
	 * The security settings merged over the firewall defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( class_exists( 'Perdita_Security' ) && method_exists( 'Perdita_Security', 'get_settings' ) ) {
			$settings = Perdita_Security::get_settings();
		} else {
			$settings = get_option( 'perdita_security_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
		}
		return array_merge( self::defaults(), $settings );
	}

	/**
	 * Defaults for the firewall keys. Perdita_Security::defaults() carries
	 * the same values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'firewall'       => false,
			'firewall_mode'  => 'monitor',
			'firewall_load'  => 'plugin',
			'fw_rules'       => array_fill_keys( self::CATEGORIES, true ),
			'fw_trust_roles' => 'edit_posts',
			'fw_exclusions'  => array(),
			'ip_allowlist'   => array(),
			'disable_xmlrpc' => false,
		);
	}

	/**
	 * add_option_* callback.
	 */
	public static function on_add() {
		self::compile();
	}

	/**
	 * update_option_* callback.
	 */
	public static function on_update() {
		self::compile();
	}

	/**
	 * update_option_perdita_core callback: recompile, and tear the security
	 * module's server config down when the module went off.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public static function on_module_state( $old, $new ) {
		$was = is_array( $old ) && ! empty( $old['modules']['security'] );
		$now = is_array( $new ) && ! empty( $new['modules']['security'] );
		if ( $was && ! $now ) {
			self::teardown_security();
		}
		self::compile();
	}

	/**
	 * Call the security module's own teardown (uploads .htaccess block, core
	 * check cron) when that method exists.
	 */
	public static function teardown_security() {
		$file = dirname( __DIR__ ) . '/class-perdita-security.php';
		if ( ! class_exists( 'Perdita_Security' ) && is_readable( $file ) ) {
			require_once $file;
		}
		if ( class_exists( 'Perdita_Security' ) && method_exists( 'Perdita_Security', 'teardown' ) ) {
			Perdita_Security::teardown();
		}
	}

	/* ---------- paths ---------- */

	/**
	 * @return string wp-content/perdita-firewall/ with a trailing slash.
	 */
	public static function dir() {
		/**
		 * Folder for the compiled config and the log. Tests point it elsewhere.
		 *
		 * @param string $dir Absolute path with a trailing slash.
		 */
		return trailingslashit( (string) apply_filters( 'perdita_firewall_dir', trailingslashit( WP_CONTENT_DIR ) . 'perdita-firewall/' ) );
	}

	/**
	 * @return string The compiled config.
	 */
	public static function config_path() {
		return self::dir() . 'config.php';
	}

	/**
	 * @return string The log folder.
	 */
	public static function log_dir() {
		return self::dir() . 'log/';
	}

	/**
	 * The bootstrap the mu-plugin and auto_prepend_file include. It sits
	 * beside the firewall folder, not in it, because PHP stops every request
	 * with a fatal error when auto_prepend_file names a missing file, and the
	 * emergency off switch is deleting that folder.
	 *
	 * @return string
	 */
	public static function bootstrap_path() {
		/**
		 * Path of the firewall bootstrap. Tests point it elsewhere.
		 *
		 * @param string $path Absolute path.
		 */
		return (string) apply_filters( 'perdita_firewall_bootstrap_path', trailingslashit( WP_CONTENT_DIR ) . 'perdita-firewall-bootstrap.php' );
	}

	/**
	 * @return string The engine file.
	 */
	public static function engine_path() {
		return __DIR__ . '/class-perdita-firewall.php';
	}

	/* ---------- key ---------- */

	/**
	 * The trust cookie HMAC key, generated once.
	 *
	 * @return string 64 hex characters.
	 */
	public static function key() {
		$key = get_option( self::KEY_OPTION, '' );
		if ( is_string( $key ) && preg_match( '/^[0-9a-f]{64}$/', $key ) ) {
			return $key;
		}
		$key = bin2hex( random_bytes( 32 ) );
		update_option( self::KEY_OPTION, $key, true );
		return $key;
	}

	/* ---------- compile ---------- */

	/**
	 * The engine config for these settings, before it's written.
	 *
	 * @param array $settings Security settings (merged over defaults).
	 * @param bool  $module   Whether the security module is on.
	 * @return array
	 */
	public static function build( array $settings, $module ) {
		require_once self::engine_path();
		$rules = is_array( $settings['fw_rules'] ) ? $settings['fw_rules'] : array();
		$cats  = array();
		foreach ( self::CATEGORIES as $cat ) {
			$cats[ $cat ] = ! array_key_exists( $cat, $rules ) || ! empty( $rules[ $cat ] );
		}
		$exclusions = array();
		foreach ( (array) $settings['fw_exclusions'] as $ex ) {
			if ( is_array( $ex ) && ! empty( $ex['rule'] ) && is_string( $ex['rule'] ) ) {
				$exclusions[] = array(
					'rule'  => (string) $ex['rule'],
					'path'  => isset( $ex['path'] ) ? (string) $ex['path'] : '',
					'param' => isset( $ex['param'] ) ? (string) $ex['param'] : '',
				);
			}
		}
		$enabled = $module && ! empty( $settings['firewall'] );
		$config  = array(
			'version'        => 1,
			'engine'         => Perdita_Firewall::VERSION,
			'compiled'       => time(),
			'enabled'        => $enabled,
			'mode'           => 'block' === $settings['firewall_mode'] ? 'block' : 'monitor',
			'rules'          => $cats,
			'exclusions'     => $exclusions,
			'ip_allowlist'   => array_values( array_filter( (array) $settings['ip_allowlist'], 'is_string' ) ),
			'ip_blocklist'   => array(),
			'key'            => $enabled ? self::key() : '',
			'cf_ranges'      => class_exists( 'Perdita_Client_IP' ) ? Perdita_Client_IP::cloudflare_ranges() : array(),
			'host'           => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'log_dir'        => self::log_dir(),
			'disable_xmlrpc' => ! empty( $settings['disable_xmlrpc'] ),
		);
		/**
		 * Add sections to the compiled firewall config. Perdita Pro adds
		 * ip_blocklist, ip_allowlist entries, custom_rules, country, and
		 * rate_limits here. See class-perdita-firewall.php for the shapes.
		 *
		 * @param array $config   The config so far.
		 * @param array $settings Core's security settings.
		 */
		$config = apply_filters( 'perdita_firewall_config', $config, $settings );
		if ( ! is_array( $config ) ) {
			$config = array( 'enabled' => false );
		}
		$config['allowlist'] = Perdita_Firewall::parse_ranges( isset( $config['ip_allowlist'] ) ? (array) $config['ip_allowlist'] : array() );
		$config['blocklist'] = Perdita_Firewall::parse_ranges( isset( $config['ip_blocklist'] ) ? (array) $config['ip_blocklist'] : array() );
		$config['cf_ranges'] = Perdita_Firewall::parse_ranges( isset( $config['cf_ranges'] ) ? (array) $config['cf_ranges'] : array() );
		foreach ( array( 'allowlist', 'blocklist' ) as $list ) {
			if ( ! $config[ $list ]['v4'] && ! $config[ $list ]['v6'] ) {
				$config[ $list ] = array();
			}
		}
		return $config;
	}

	/**
	 * Write the config, the bootstrap, and the folder guards, then bring the
	 * chosen loader in line. With the firewall or the module off, the config
	 * file goes away and so do the mu-plugin and the prepend block.
	 *
	 * @return array Loader state, see Perdita_Firewall_Loader::sync().
	 */
	public static function compile() {
		$settings = self::settings();
		$core     = get_option( 'perdita_core', array() );
		$module   = is_array( $core ) && ! empty( $core['modules']['security'] );
		$config   = self::build( $settings, $module );
		require_once __DIR__ . '/class-perdita-firewall-loader.php';

		if ( empty( $config['enabled'] ) ) {
			// Off writes nothing new: no key, no state row, no files.
			self::delete_file( self::config_path() );
			$state = Perdita_Firewall_Loader::sync( 'off', false );
			delete_option( self::STATE_OPTION );
			return $state;
		}
		if ( ! self::ensure_dir() ) {
			$state = array(
				'mode'   => (string) $settings['firewall_load'],
				'result' => 'unwritable',
				'file'   => self::dir(),
			);
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		}
		$source = '<?php' . "\n" . '// ' . self::MARKER . ' config. Perdita Core writes this file on every security settings save. Edits here are lost.' . "\n"
			. "defined( 'ABSPATH' ) || defined( 'PERDITA_FIREWALL_EARLY' ) || exit;\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP array file.
		$ok = self::write_atomic( self::config_path(), $source );
		self::write_atomic( self::bootstrap_path(), self::bootstrap_source() );
		$mode  = in_array( $settings['firewall_load'], array( 'plugin', 'mu', 'prepend' ), true ) ? $settings['firewall_load'] : 'plugin';
		$state = Perdita_Firewall_Loader::sync( $mode, self::$allow_server_config );
		if ( ! $ok ) {
			$state['config'] = 'unwritable';
		}
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Compile without adding server config. For activation, which isn't an
	 * owner's click on the prepend option.
	 *
	 * @return array
	 */
	public static function compile_quiet() {
		self::$allow_server_config = false;
		$state                     = self::compile();
		self::$allow_server_config = true;
		return $state;
	}

	/**
	 * The bootstrap PHP. Every path is a plain value, and it does nothing when
	 * the engine or the config is missing, so a deleted plugin or a deleted
	 * firewall folder never stops a site.
	 *
	 * Before WordPress loads (auto_prepend_file), wp-config.php hasn't run,
	 * so the bootstrap looks for PERDITA_FIREWALL_DISABLE in that file itself,
	 * but only when the file changed since the compile.
	 *
	 * @return string
	 */
	public static function bootstrap_source() {
		$wp_config = '';
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			$wp_config = ABSPATH . 'wp-config.php';
		} elseif ( is_file( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			$wp_config = dirname( ABSPATH ) . '/wp-config.php';
		}
		$mtime = '' !== $wp_config ? (int) @filemtime( $wp_config ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file reads as 0.
		if ( '' !== $wp_config && false !== stripos( (string) file_get_contents( $wp_config ), 'PERDITA_FIREWALL_DISABLE' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$mtime = -1;
		}
		$lines = array(
			'<?php',
			'/**',
			' * ' . self::MARKER . ' bootstrap.',
			' *',
			' * Perdita Core wrote this file and rewrites or removes it. It runs the',
			' * firewall from auto_prepend_file or from the must-use plugin, and does',
			' * nothing when Perdita Core or wp-content/perdita-firewall/config.php is gone.',
			' */',
			'',
			'if ( defined( \'PERDITA_FIREWALL_LOADED\' ) ) {',
			'	return;',
			'}',
			'if ( ! defined( \'ABSPATH\' ) && ! defined( \'PERDITA_FIREWALL_EARLY\' ) ) {',
			'	define( \'PERDITA_FIREWALL_EARLY\', true );',
			'}',
			'if ( defined( \'PERDITA_FIREWALL_DISABLE\' ) && PERDITA_FIREWALL_DISABLE ) {',
			'	return;',
			'}',
			'( function ( $engine, $config, $wp_config, $mtime ) {',
			'	if ( ! is_file( $engine ) || ! is_file( $config ) ) {',
			'		return;',
			'	}',
			'	if ( ! defined( \'ABSPATH\' ) && \'\' !== $wp_config ) {',
			'		$now = @filemtime( $wp_config );',
			'		if ( false !== $now && $now !== $mtime ) {',
			'			$src = (string) @file_get_contents( $wp_config );',
			'			if ( false !== stripos( $src, \'PERDITA_FIREWALL_DISABLE\' ) && preg_match( \'/define\s*\(\s*[\\\'"]PERDITA_FIREWALL_DISABLE[\\\'"]\s*,\s*true\s*\)/i\', $src ) ) {',
			'				return;',
			'			}',
			'		}',
			'	}',
			'	require_once $engine;',
			'	Perdita_Firewall::boot( $config, defined( \'ABSPATH\' ) ? \'mu\' : \'prepend\' );',
			'} )( ' . var_export( self::engine_path(), true ) . ', ' . var_export( self::config_path(), true ) . ', ' . var_export( $wp_config, true ) . ', ' . $mtime . ' );', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP string literals.
			'',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Create the firewall folder and its log folder with deny guards.
	 *
	 * @return bool
	 */
	private static function ensure_dir() {
		foreach ( array( self::dir(), self::log_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			$guards = array(
				'index.php'  => "<?php\n// Silence is golden.\n",
				'.htaccess'  => "# " . self::MARKER . "\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
				'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			);
			foreach ( $guards as $name => $body ) {
				if ( ! is_file( $dir . $name ) ) {
					self::write_atomic( $dir . $name, $body );
				}
			}
		}
		return true;
	}

	/**
	 * Write a file through a temp file and a rename, so no request ever
	 * includes half of one.
	 *
	 * @param string $path    Target.
	 * @param string $content Content.
	 * @return bool
	 */
	public static function write_atomic( $path, $content ) {
		if ( is_file( $path ) && (string) file_get_contents( $path ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			return true;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}
		$tmp = $dir . '/.' . basename( $path ) . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a real file PHP includes before WordPress loads.
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return false;
		}
		if ( is_file( $path ) ) {
			@chmod( $tmp, fileperms( $path ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- keep the old permissions.
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap.
			wp_delete_file( $tmp );
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache may be restricted.
		}
		return true;
	}

	/**
	 * Delete a file when it exists.
	 *
	 * @param string $path Path.
	 */
	public static function delete_file( $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
			if ( function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
				@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache may be restricted.
			}
		}
	}

	/* ---------- trust cookie ---------- */

	/**
	 * The capability that earns the trust cookie.
	 *
	 * @return string
	 */
	public static function trust_cap() {
		$settings = get_option( 'perdita_security_settings', array() );
		$cap      = is_array( $settings ) && ! empty( $settings['fw_trust_roles'] ) && is_string( $settings['fw_trust_roles'] ) ? $settings['fw_trust_roles'] : 'edit_posts';
		return sanitize_key( $cap );
	}

	/**
	 * set_logged_in_cookie callback.
	 *
	 * @param string $cookie     Logged-in cookie.
	 * @param int    $expire     Cookie expiry for the browser (0 for a session cookie).
	 * @param int    $expiration Token expiration.
	 * @param int    $user_id    User id.
	 */
	public static function on_set_logged_in_cookie( $cookie, $expire, $expiration, $user_id ) {
		self::maybe_set_trust_cookie( (int) $user_id, (int) $expiration, (int) $expire );
	}

	/**
	 * wp_login callback.
	 *
	 * @param string  $login User login.
	 * @param WP_User $user  User.
	 */
	public static function on_wp_login( $login, $user ) {
		if ( $user instanceof WP_User && ! isset( $_COOKIE[ Perdita_Firewall_Config::cookie_name() ] ) ) {
			self::maybe_set_trust_cookie( (int) $user->ID, time() + 2 * DAY_IN_SECONDS, 0 );
		}
	}

	/**
	 * On init, give a signed-in user with the capability a trust cookie when
	 * theirs is missing or no longer verifies (the firewall went on after they
	 * signed in, or the key changed), and drop it when they lost the capability.
	 */
	public static function refresh_trust_cookie() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		require_once self::engine_path();
		$user_id = get_current_user_id();
		$have    = isset( $_COOKIE[ self::cookie_name() ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie_name() ] ) ) : '';
		$valid   = '' !== $have && Perdita_Firewall::verify_trust( $have, self::key() ) === $user_id;
		if ( ! user_can( $user_id, self::trust_cap() ) ) {
			if ( '' !== $have ) {
				self::clear_trust_cookie();
			}
			return;
		}
		if ( $valid ) {
			return;
		}
		$parsed = wp_parse_auth_cookie( '', 'logged_in' );
		$until  = is_array( $parsed ) && ! empty( $parsed['expiration'] ) ? (int) $parsed['expiration'] : time() + 2 * DAY_IN_SECONDS;
		self::maybe_set_trust_cookie( $user_id, $until, 0 );
	}

	/**
	 * @return string The trust cookie name.
	 */
	public static function cookie_name() {
		return 'perdita_fw_trust';
	}

	/**
	 * Set the signed trust cookie when the user has the capability.
	 *
	 * @param int $user_id    User id.
	 * @param int $expiration When the signature stops verifying.
	 * @param int $expire     Browser expiry, 0 for a session cookie.
	 * @return string The value set, or ''.
	 */
	public static function maybe_set_trust_cookie( $user_id, $expiration, $expire ) {
		if ( $user_id < 1 || ! user_can( $user_id, self::trust_cap() ) ) {
			return '';
		}
		require_once self::engine_path();
		$value = Perdita_Firewall::trust_value( $user_id, $expiration, self::key() );
		$_COOKIE[ self::cookie_name() ] = $value;
		if ( ! headers_sent() ) {
			setcookie(
				self::cookie_name(),
				$value,
				array(
					'expires'  => $expire,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		return $value;
	}

	/**
	 * Remove the trust cookie (logout, lost capability).
	 */
	public static function clear_trust_cookie() {
		unset( $_COOKIE[ self::cookie_name() ] );
		if ( ! headers_sent() ) {
			setcookie(
				self::cookie_name(),
				' ',
				array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/* ---------- log ---------- */

	/**
	 * The newest log entries, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent( $limit = 50 ) {
		$files = glob( self::log_dir() . '*.log.php' );
		if ( ! $files ) {
			return array();
		}
		rsort( $files );
		$out = array();
		foreach ( $files as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! $lines ) {
				continue;
			}
			for ( $i = count( $lines ) - 1; $i >= 0 && count( $out ) < $limit; $i-- ) {
				if ( '{' !== substr( $lines[ $i ], 0, 1 ) ) {
					continue;
				}
				$entry = json_decode( $lines[ $i ], true );
				if ( is_array( $entry ) && ! empty( $entry['ref'] ) ) {
					$out[] = $entry;
				}
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The exclusion an Allow click writes for one log entry: the rule id,
	 * the request path as the prefix, and the parameter name.
	 *
	 * @param array $entry Log entry.
	 * @return array|null
	 */
	public static function exclusion_for_entry( array $entry ) {
		$rule = isset( $entry['rule'] ) ? (string) $entry['rule'] : '';
		if ( '' === $rule || ! preg_match( '/^[a-z]+-[a-z0-9_-]+$/', $rule ) || in_array( $rule, array( 'ip-001', 'country-001' ), true ) || 0 === strpos( $rule, 'rate-' ) ) {
			return null;
		}
		$uri   = isset( $entry['uri'] ) ? (string) $entry['uri'] : '/';
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$field = isset( $entry['field'] ) ? (string) $entry['field'] : '';
		$param = '';
		if ( preg_match( '/^(?:query|post|json|cookie|file):(.+)$/', $field, $m ) ) {
			$param = $m[1];
		}
		return array(
			'rule'  => $rule,
			'path'  => '' === $path ? '/' : $path,
			'param' => $param,
		);
	}

	/**
	 * admin-post handler for the Allow button on a log entry.
	 */
	public static function handle_allow() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ), 403 );
		}
		$ref = isset( $_GET['ref'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ) : '';
		check_admin_referer( 'perdita_firewall_allow_' . $ref );
		$msg = __( 'We couldn\'t find that log entry.', 'perdita-core' );
		foreach ( self::recent( 500 ) as $entry ) {
			if ( $entry['ref'] !== $ref ) {
				continue;
			}
			$ex = self::exclusion_for_entry( $entry );
			if ( ! $ex ) {
				$msg = __( 'You can\'t allow an address or rate block from here.', 'perdita-core' );
				break;
			}
			$settings = get_option( 'perdita_security_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
			$list     = isset( $settings['fw_exclusions'] ) && is_array( $settings['fw_exclusions'] ) ? $settings['fw_exclusions'] : array();
			$list[] = $ex;
			require_once dirname( __DIR__ ) . '/class-perdita-security-firewall-admin.php';
			$settings['fw_exclusions'] = Perdita_Security_Firewall_Admin::sanitize_exclusions( $list );
			update_option( 'perdita_security_settings', $settings );
			/* translators: 1: rule id, 2: path. */
			$msg = sprintf( __( 'You allowed %1$s on %2$s.', 'perdita-core' ), $ex['rule'], $ex['path'] . ( '' !== $ex['param'] ? ' (' . $ex['param'] . ')' : '' ) );
			break;
		}
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( $msg ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=perdita-security' ) ) );
		exit;
	}

	/* ---------- lifecycle ---------- */

	/**
	 * Activation: rebuild the config and the mu-plugin. A prepend block waits
	 * for the owner's next save.
	 */
	public static function on_activate() {
		self::compile_quiet();
	}

	/**
	 * Core deactivation: take out the mu-plugin, the prepend block, and the
	 * config, so nothing runs the engine while the plugin is off. Options and
	 * the log stay, so reactivating is lossless.
	 */
	public static function on_deactivate() {
		require_once __DIR__ . '/class-perdita-firewall-loader.php';
		Perdita_Firewall_Loader::sync( 'off', false );
		self::delete_file( self::config_path() );
		self::teardown_security();
	}

	/**
	 * Uninstall: everything on_deactivate() does, plus the firewall folder
	 * (only the files this class writes), the bootstrap, and the options.
	 */
	public static function uninstall() {
		self::on_deactivate();
		$dir = self::dir();
		foreach ( (array) glob( self::log_dir() . '*.log.php' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		foreach ( array( self::log_dir(), $dir ) as $folder ) {
			foreach ( array( 'index.php', '.htaccess', 'web.config', 'config.php' ) as $name ) {
				self::delete_file( $folder . $name );
			}
			if ( is_dir( $folder ) ) {
				@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only empties out; a folder with other files stays.
			}
		}
		require_once __DIR__ . '/class-perdita-firewall-loader.php';
		if ( Perdita_Firewall_Loader::bootstrap_still_needed() ) {
			// PHP may hold the old .user.ini for up to user_ini.cache_ttl, and
			// a missing prepend file is a fatal error. The bootstrap does
			// nothing without its config, so it stays until then.
			self::write_atomic( self::bootstrap_path(), self::bootstrap_source() );
		} else {
			self::delete_file( self::bootstrap_path() );
		}
		delete_option( self::KEY_OPTION );
		delete_option( self::STATE_OPTION );
	}
}
