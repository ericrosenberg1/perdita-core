<?php
/**
 * Perdita Firewall loaders: the must-use plugin and the auto_prepend_file
 * block. Plugin mode needs no file, perdita-core.php runs the engine.
 *
 *   mu       wp-content/mu-plugins/0-perdita-firewall.php, which includes the
 *            bootstrap. Never written over another plugin's file of that name.
 *   prepend  a marked block in .user.ini (CGI and FPM) or a marked php_value
 *            block in .htaccess (mod_php and LiteSpeed), chosen by
 *            php_sapi_name(). Refused when another auto_prepend_file is set
 *            (Wordfence's wordfence-waf.php, for one). Removing the mode cuts
 *            exactly the marked block and leaves every other byte alone.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Firewall_Loader {

	/**
	 * Block markers.
	 */
	const BEGIN = 'BEGIN Perdita Firewall';
	const END   = 'END Perdita Firewall';

	/**
	 * Unix time this request removed a .user.ini block, or 0.
	 *
	 * @var int
	 */
	private static $user_ini_removed = 0;

	/* ---------- paths ---------- */

	/**
	 * @return string The must-use plugin path.
	 */
	public static function mu_path() {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		/**
		 * Folder for the firewall's must-use plugin. Tests point it elsewhere.
		 *
		 * @param string $dir Absolute path.
		 */
		return trailingslashit( (string) apply_filters( 'perdita_firewall_mu_dir', $dir ) ) . '0-perdita-firewall.php';
	}

	/**
	 * Which file the prepend block goes in for this SAPI.
	 *
	 * @return string user_ini, htaccess, or '' when this server can't take one.
	 */
	public static function prepend_kind() {
		/**
		 * The PHP SAPI the prepend loader plans for. Tests change it.
		 *
		 * @param string $sapi php_sapi_name().
		 */
		$sapi = (string) apply_filters( 'perdita_firewall_sapi', php_sapi_name() );
		if ( in_array( $sapi, array( 'cgi-fcgi', 'fpm-fcgi', 'cgi' ), true ) ) {
			return 'user_ini';
		}
		if ( in_array( $sapi, array( 'apache2handler', 'litespeed' ), true ) ) {
			return 'htaccess';
		}
		return '';
	}

	/**
	 * The file the prepend block goes in.
	 *
	 * @param string $kind user_ini or htaccess.
	 * @return string
	 */
	public static function prepend_file( $kind ) {
		if ( 'user_ini' === $kind ) {
			$name = (string) ini_get( 'user_ini.filename' );
			$path = ABSPATH . ( '' !== $name ? basename( $name ) : '.user.ini' );
			/**
			 * The .user.ini file the prepend block goes in.
			 *
			 * @param string $path Absolute path.
			 */
			return (string) apply_filters( 'perdita_firewall_user_ini_path', $path );
		}
		/**
		 * The .htaccess file the prepend block goes in.
		 *
		 * @param string $path Absolute path.
		 */
		return (string) apply_filters( 'perdita_firewall_htaccess_path', ABSPATH . '.htaccess' );
	}

	/* ---------- sync ---------- */

	/**
	 * Bring the loader files in line with a mode.
	 *
	 * @param string $mode      plugin, mu, prepend, or off.
	 * @param bool   $allow_add Whether a prepend block may be added now (an owner save).
	 * @return array array( 'mode', 'result', 'file', 'detail', 'time' ). result is one of
	 *               off, plugin, mu_installed, mu_current, mu_foreign, mu_unwritable,
	 *               prepend_installed, prepend_current, prepend_foreign, prepend_unsupported,
	 *               prepend_user_ini_off, prepend_unwritable, prepend_needs_save.
	 */
	public static function sync( $mode, $allow_add ) {
		$state = array(
			'mode'   => $mode,
			'result' => 'off' === $mode ? 'off' : 'plugin',
			'file'   => '',
			'detail' => '',
			'time'   => time(),
		);
		if ( 'mu' === $mode ) {
			$state = array_merge( $state, self::write_mu() );
		} else {
			self::remove_mu();
		}
		if ( 'prepend' === $mode ) {
			$state = array_merge( $state, self::add_prepend( $allow_add ) );
		} else {
			self::remove_prepend();
		}
		return $state;
	}

	/* ---------- mu-plugin ---------- */

	/**
	 * The must-use plugin source.
	 *
	 * @return string
	 */
	public static function mu_source() {
		$lines = array(
			'<?php',
			'/**',
			' * Plugin Name: ' . Perdita_Firewall_Config::MARKER,
			' * Description: Runs the Perdita firewall before other plugins load. Perdita Core wrote this file and removes it when you switch the firewall loader back or deactivate Perdita Core.',
			' */',
			'',
			'defined( \'ABSPATH\' ) || exit;',
			'',
			'if ( is_file( ' . var_export( Perdita_Firewall_Config::bootstrap_path(), true ) . ' ) ) {', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a PHP string literal.
			'	include_once ' . var_export( Perdita_Firewall_Config::bootstrap_path(), true ) . ';', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a PHP string literal.
			'}',
			'',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Whether a file carries this plugin's marker in its first lines.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_ours( $path ) {
		$head = (string) file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return false !== strpos( $head, Perdita_Firewall_Config::MARKER );
	}

	/**
	 * Write the must-use plugin.
	 *
	 * @return array Partial state.
	 */
	private static function write_mu() {
		$path = self::mu_path();
		if ( is_file( $path ) && ! self::is_ours( $path ) ) {
			return array(
				'result' => 'mu_foreign',
				'file'   => $path,
			);
		}
		$current = is_file( $path ) && (string) file_get_contents( $path ) === self::mu_source(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! $current ) {
			if ( ! is_dir( dirname( $path ) ) ) {
				wp_mkdir_p( dirname( $path ) );
			}
			if ( ! Perdita_Firewall_Config::write_atomic( $path, self::mu_source() ) ) {
				return array(
					'result' => 'mu_unwritable',
					'file'   => $path,
				);
			}
		}
		return array(
			'result' => $current ? 'mu_current' : 'mu_installed',
			'file'   => $path,
		);
	}

	/**
	 * Remove the must-use plugin when it's ours.
	 */
	public static function remove_mu() {
		$path = self::mu_path();
		if ( is_file( $path ) && self::is_ours( $path ) ) {
			Perdita_Firewall_Config::delete_file( $path );
		}
	}

	/* ---------- prepend ---------- */

	/**
	 * The marked block for a file kind.
	 *
	 * @param string $kind user_ini or htaccess.
	 * @return string
	 */
	public static function block( $kind ) {
		$boot = Perdita_Firewall_Config::bootstrap_path();
		if ( 'user_ini' === $kind ) {
			return '; ' . self::BEGIN . "\n" . "auto_prepend_file = '" . $boot . "'\n" . '; ' . self::END . "\n";
		}
		$out = '# ' . self::BEGIN . "\n";
		foreach ( array( 'mod_php.c', 'mod_php7.c', 'mod_php5.c', 'lsapi_module', 'LiteSpeed' ) as $module ) {
			$out .= '<IfModule ' . $module . ">\n\tphp_value auto_prepend_file '" . $boot . "'\n</IfModule>\n";
		}
		return $out . '# ' . self::END . "\n";
	}

	/**
	 * Cut the marked block out of some text. Returns the text unchanged when
	 * there is no complete block.
	 *
	 * @param string $text File content.
	 * @param string $kind user_ini or htaccess.
	 * @return string
	 */
	public static function strip_block( $text, $kind ) {
		$c     = 'user_ini' === $kind ? ';' : '#';
		$begin = $c . ' ' . self::BEGIN;
		$end   = $c . ' ' . self::END;
		$start = strpos( $text, $begin );
		if ( false === $start || ( $start > 0 && "\n" !== $text[ $start - 1 ] ) ) {
			return $text;
		}
		$stop = strpos( $text, $end, $start );
		if ( false === $stop ) {
			return $text;
		}
		$stop += strlen( $end );
		if ( isset( $text[ $stop ] ) && "\r" === $text[ $stop ] ) {
			++$stop;
		}
		if ( isset( $text[ $stop ] ) && "\n" === $text[ $stop ] ) {
			++$stop;
		}
		return substr( $text, 0, $start ) . substr( $text, $stop );
	}

	/**
	 * The auto_prepend_file someone else set, by name, or ''.
	 *
	 * @param string $outside File content outside Perdita's block.
	 * @return string
	 */
	public static function foreign_prepend( $outside ) {
		$boot    = Perdita_Firewall_Config::bootstrap_path();
		$current = (string) ini_get( 'auto_prepend_file' );
		if ( '' !== $current && $current !== $boot && realpath( $current ) !== realpath( $boot ) ) {
			return basename( $current );
		}
		if ( preg_match( '/^[ \t]*(?:php_value[ \t]+)?auto_prepend_file\b[ \t=]*["\']?([^"\'\r\n]*)/mi', $outside, $m ) ) {
			return '' !== trim( $m[1] ) ? basename( trim( $m[1] ) ) : 'auto_prepend_file';
		}
		return '';
	}

	/**
	 * Add the prepend block.
	 *
	 * @param bool $allow_add Whether adding is allowed in this request.
	 * @return array Partial state.
	 */
	private static function add_prepend( $allow_add ) {
		$kind = self::prepend_kind();
		if ( '' === $kind ) {
			return array(
				'result' => 'prepend_unsupported',
				'detail' => (string) apply_filters( 'perdita_firewall_sapi', php_sapi_name() ),
			);
		}
		if ( 'user_ini' === $kind && '' === (string) ini_get( 'user_ini.filename' ) && ! has_filter( 'perdita_firewall_user_ini_path' ) ) {
			return array( 'result' => 'prepend_user_ini_off' );
		}
		$file  = self::prepend_file( $kind );
		$text  = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$plain = self::strip_block( $text, $kind );
		$other = self::foreign_prepend( $plain );
		if ( '' !== $other ) {
			return array(
				'result' => 'prepend_foreign',
				'file'   => $file,
				'detail' => $other,
			);
		}
		$want = $plain . ( '' !== $plain && "\n" !== substr( $plain, -1 ) ? "\n" : '' ) . self::block( $kind );
		if ( false !== strpos( $text, self::block( $kind ) ) ) {
			return array(
				'result' => 'prepend_current',
				'file'   => $file,
				'detail' => $kind,
			);
		}
		if ( ! $allow_add ) {
			return array(
				'result' => 'prepend_needs_save',
				'file'   => $file,
				'detail' => $kind,
			);
		}
		if ( false !== strpos( Perdita_Firewall_Config::bootstrap_path(), "'" ) || ! Perdita_Firewall_Config::write_atomic( $file, $want ) ) {
			return array(
				'result' => 'prepend_unwritable',
				'file'   => $file,
				'detail' => $kind,
			);
		}
		return array(
			'result' => 'prepend_installed',
			'file'   => $file,
			'detail' => $kind,
		);
	}

	/**
	 * Remove Perdita's block from .user.ini and .htaccess. A file Perdita
	 * created and left empty goes away.
	 *
	 * @return string[] The files changed.
	 */
	public static function remove_prepend() {
		$changed = array();
		foreach ( array( 'user_ini', 'htaccess' ) as $kind ) {
			$file = self::prepend_file( $kind );
			if ( ! is_file( $file ) ) {
				continue;
			}
			$text  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$plain = self::strip_block( $text, $kind );
			if ( $plain === $text ) {
				continue;
			}
			if ( '' === $plain ) {
				Perdita_Firewall_Config::delete_file( $file );
			} else {
				Perdita_Firewall_Config::write_atomic( $file, $plain );
			}
			if ( 'user_ini' === $kind ) {
				self::$user_ini_removed = time();
			}
			$changed[] = $file;
		}
		return $changed;
	}

	/**
	 * Whether PHP may still prepend the bootstrap: this request took a
	 * .user.ini block out (PHP rereads that file only every
	 * user_ini.cache_ttl seconds), or this request ran through the prepend.
	 *
	 * @return bool
	 */
	public static function bootstrap_still_needed() {
		$boot = Perdita_Firewall_Config::bootstrap_path();
		return self::$user_ini_removed > 0 || defined( 'PERDITA_FIREWALL_EARLY' ) || realpath( (string) ini_get( 'auto_prepend_file' ) ) === realpath( $boot );
	}

	/* ---------- status ---------- */

	/**
	 * The loader status for the settings screen.
	 *
	 * @return array array( 'mode', 'result', 'working' => bool, 'message' => string ).
	 */
	public static function status() {
		$state = get_option( Perdita_Firewall_Config::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$state = array_merge(
			array(
				'mode'   => 'off',
				'result' => 'off',
				'file'   => '',
				'detail' => '',
			),
			$state
		);
		$ran     = defined( 'PERDITA_FIREWALL_LOADED' ) ? (string) PERDITA_FIREWALL_LOADED : '';
		$working = false;
		switch ( $state['result'] ) {
			case 'off':
				$msg = __( 'The firewall is off.', 'perdita-core' );
				break;
			case 'plugin':
				$working = 'plugin' === $ran;
				$msg     = __( 'Perdita Core runs the firewall as it loads, before its other code.', 'perdita-core' );
				break;
			case 'mu_installed':
			case 'mu_current':
				$working = 'mu' === $ran;
				$msg     = $working ? __( 'The must-use plugin runs the firewall before other plugins load.', 'perdita-core' ) : __( 'You have the must-use plugin in place, and WordPress hasn\'t loaded it yet on this page. Reload this page to check again.', 'perdita-core' );
				break;
			case 'mu_foreign':
				/* translators: %s: file path. */
				$msg = sprintf( __( 'Another plugin owns %s, so Perdita left it alone. Perdita Core runs the firewall instead.', 'perdita-core' ), $state['file'] );
				break;
			case 'mu_unwritable':
				/* translators: %s: file path. */
				$msg = sprintf( __( 'WordPress can\'t write %s. Perdita Core runs the firewall instead.', 'perdita-core' ), $state['file'] );
				break;
			case 'prepend_installed':
			case 'prepend_current':
				$working = defined( 'PERDITA_FIREWALL_EARLY' ) && 'prepend' === $ran;
				if ( $working ) {
					$msg = __( 'PHP runs the firewall before WordPress loads, on every request.', 'perdita-core' );
				} elseif ( 'user_ini' === $state['detail'] ) {
					$msg = __( 'You have the .user.ini block in place. PHP rereads that file every 5 minutes on most hosts, so check back then. Until then Perdita Core runs the firewall.', 'perdita-core' );
				} else {
					$msg = __( 'You have the .htaccess block in place, and PHP didn\'t run it on this page. Your host may not allow php_value. Perdita Core runs the firewall instead.', 'perdita-core' );
				}
				break;
			case 'prepend_foreign':
				/* translators: %s: file name such as wordfence-waf.php. */
				$msg = sprintf( __( 'Another security plugin already loads first through auto_prepend_file (%s), so Perdita left your server settings alone. Remove that one first, or keep the plugin loader.', 'perdita-core' ), $state['detail'] );
				break;
			case 'prepend_unsupported':
				/* translators: %s: PHP SAPI name. */
				$msg = sprintf( __( 'Your host runs PHP as %s, and PHP reads neither .user.ini nor .htaccess settings there. Perdita Core runs the firewall instead.', 'perdita-core' ), $state['detail'] );
				break;
			case 'prepend_user_ini_off':
				$msg = __( 'Your host turned off .user.ini files. Perdita Core runs the firewall instead.', 'perdita-core' );
				break;
			case 'prepend_needs_save':
				$msg = __( 'Save these settings to add the early loader again. Until then Perdita Core runs the firewall.', 'perdita-core' );
				break;
			default:
				/* translators: %s: file path. */
				$msg = sprintf( __( 'WordPress can\'t write %s. Perdita Core runs the firewall instead.', 'perdita-core' ), $state['file'] );
		}
		$state['working'] = $working;
		$state['ran']     = $ran;
		$state['message'] = $msg;
		return $state;
	}
}
