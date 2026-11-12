<?php
/**
 * The Perdita modules a switch writes into, and how. Every write goes
 * through the module's own sanitize() and the same follow-up its settings
 * screen runs, never a raw update_option() of unvalidated data.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Target {

	/**
	 * Load a module's main class whether or not the module is on, so its
	 * sanitize() is available.
	 *
	 * @param string $module Module id.
	 * @return bool Whether the module is known.
	 */
	public static function load( $module ) {
		if ( 'seo' === $module ) {
			return function_exists( 'perdita_core' ) && isset( perdita_core()->seo );
		}
		$classes = array(
			'caching'   => 'Perdita_Caching',
			'smtp'      => 'Perdita_SMTP',
			'analytics' => 'Perdita_Analytics',
			'security'  => 'Perdita_Security',
		);
		if ( ! isset( $classes[ $module ] ) ) {
			return false;
		}
		if ( class_exists( $classes[ $module ] ) ) {
			return true;
		}
		$file = PERDITA_CORE_DIR . 'inc/modules/' . $module . '/class-perdita-' . $module . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		return class_exists( $classes[ $module ] );
	}

	/**
	 * The options a module's import writes.
	 *
	 * @param string $module Module id.
	 * @return string[]
	 */
	public static function options( $module ) {
		$map = array(
			'caching'   => array( 'perdita_caching_settings' ),
			'seo'       => array( 'perdita_seo' ),
			'smtp'      => array( 'perdita_smtp_settings' ),
			'analytics' => array( 'perdita_analytics_settings' ),
			'security'  => array( 'perdita_security_settings' ),
		);
		/**
		 * Filters the options a module's switch import writes, so a Pro
		 * module can add its own. Undo restores exactly these.
		 *
		 * @param string[] $options Option names.
		 * @param string   $module  Module id.
		 */
		return (array) apply_filters( 'perdita_switch_target_options', isset( $map[ $module ] ) ? $map[ $module ] : array(), $module );
	}

	/**
	 * Check one value the way the module's sanitize() would store it.
	 *
	 * @param string $module Module id.
	 * @param string $key    Setting key, dot path for SEO.
	 * @param mixed  $value  Value.
	 * @return array ok (bool), clean (stored value), dropped (list entries refused).
	 */
	public static function check( $module, $key, $value ) {
		$out = array(
			'ok'      => false,
			'clean'   => null,
			'dropped' => array(),
		);
		if ( ! self::load( $module ) ) {
			return $out;
		}
		$one = function ( $v ) use ( $module, $key ) {
			return Perdita_Switch_Target::clean_one( $module, $key, $v );
		};
		if ( is_array( $value ) ) {
			$clean = $one( $value );
			$clean = is_array( $clean ) ? array_map( 'strval', $clean ) : array();
			$kept  = array();
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) ) {
					$out['dropped'][] = '…';
					continue;
				}
				$single = $one( array( $item ) );
				$single = is_array( $single ) ? array_map( 'strval', $single ) : array();
				// The check keeps an entry only when the module stores it as
				// it is. One the module would change (tags stripped, cut
				// short) is refused, not imported altered.
				$expect = 'cdn_dirs' === $key ? trim( (string) $item, '/' ) : trim( (string) $item );
				if ( 1 !== count( $single ) || ! in_array( $single[0], $clean, true ) || $expect !== $single[0] ) {
					$out['dropped'][] = (string) $item;
				} else {
					$kept[] = $item;
				}
			}
			$out['clean'] = $kept ? $one( $kept ) : array();
			$out['ok']    = ! empty( $kept );
			return $out;
		}
		$out['clean'] = $one( $value );
		$out['ok'] = self::same( $value, $out['clean'], $key );
		return $out;
	}

	/**
	 * What the module stores for one key given one value.
	 *
	 * @param string $module Module id.
	 * @param string $key    Key.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	public static function clean_one( $module, $key, $value ) {
		switch ( $module ) {
			case 'caching':
				$full = Perdita_Caching::sanitize( array_merge( Perdita_Caching::settings(), array( $key => $value ) ) );
				return array_key_exists( $key, $full ) ? $full[ $key ] : null;
			case 'seo':
				$patch = array();
				Perdita_Switch_Source::set_path( $patch, $key, $value );
				$clean = perdita_core()->seo->sanitize( $patch );
				foreach ( explode( '.', $key ) as $part ) {
					if ( ! is_array( $clean ) || ! array_key_exists( $part, $clean ) ) {
						return null;
					}
					$clean = $clean[ $part ];
				}
				return $clean;
			case 'smtp':
				if ( 'password_plain' === $key ) {
					return is_string( $value ) && '' !== $value && strlen( $value ) <= 1024 ? $value : null;
				}
				$full = Perdita_SMTP::sanitize( array_merge( Perdita_SMTP::settings(), array( $key => $value ) ), Perdita_SMTP::settings() );
				return array_key_exists( $key, $full ) ? $full[ $key ] : null;
			case 'analytics':
				$full = Perdita_Analytics::sanitize( array_merge( Perdita_Analytics::defaults(), Perdita_Analytics::raw_settings(), array( $key => $value ) ) );
				return array_key_exists( $key, $full ) ? $full[ $key ] : null;
			case 'security':
				$full = Perdita_Security::sanitize_login( array_merge( Perdita_Security::get_settings(), array( $key => $value ) ) );
				return array_key_exists( $key, $full ) ? $full[ $key ] : null;
		}
		return null;
	}

	/**
	 * Whether a stored value is the value offered.
	 *
	 * @param mixed  $value Offered.
	 * @param mixed  $clean Stored.
	 * @param string $key   Key.
	 * @return bool
	 */
	private static function same( $value, $clean, $key ) {
		if ( null === $clean ) {
			return false;
		}
		if ( is_bool( $clean ) ) {
			return (bool) $value === $clean;
		}
		if ( is_int( $clean ) ) {
			return is_numeric( $value ) && (int) $value === $clean && (float) $value === (float) $clean;
		}
		if ( 'measurement_id' === $key ) {
			return strtoupper( trim( (string) $value ) ) === (string) $clean;
		}
		if ( 'cdn_host' === $key ) {
			return strtolower( trim( (string) $value ) ) === (string) $clean;
		}
		return is_scalar( $value ) && trim( (string) $value ) === trim( (string) $clean );
	}

	/**
	 * Write a patch through the module's own sanitize() and save path.
	 *
	 * @param string $module Module id.
	 * @param array  $patch  Patch.
	 * @return bool Whether the module took it.
	 */
	public static function write( $module, array $patch ) {
		if ( empty( $patch ) || ! self::load( $module ) ) {
			return false;
		}
		switch ( $module ) {
			case 'caching':
				update_option( Perdita_Caching::OPTION, Perdita_Caching::sanitize( array_merge( Perdita_Caching::settings(), $patch ) ) );
				self::after_restore( 'caching' );
				return true;
			case 'seo':
				perdita_core()->seo->save( $patch );
				return true;
			case 'smtp':
				$current = Perdita_SMTP::settings();
				update_option( Perdita_SMTP::OPTION, Perdita_SMTP::sanitize( array_merge( $current, $patch ), $current ) );
				return true;
			case 'analytics':
				update_option( Perdita_Analytics::OPTION, Perdita_Analytics::sanitize( array_merge( Perdita_Analytics::defaults(), Perdita_Analytics::raw_settings(), $patch ) ) );
				return true;
			case 'security':
				$current = Perdita_Security::get_settings();
				update_option( Perdita_Security::OPTION, array_merge( $current, Perdita_Security::sanitize_login( array_merge( $current, $patch ) ) ) );
				return true;
		}
		return false;
	}

	/**
	 * What the module's settings screen runs after a save, run again after
	 * an import or an undo changed the stored settings.
	 *
	 * @param string $module Module id.
	 */
	public static function after_restore( $module ) {
		if ( 'caching' === $module && self::load( 'caching' ) ) {
			Perdita_Caching::sync_dropin();
			Perdita_Caching::sync_schedules();
		}
		if ( 'seo' === $module && function_exists( 'perdita_core' ) && isset( perdita_core()->seo ) ) {
			// The store keeps its document for the request.
			perdita_core()->seo->refresh();
		}
	}

	/**
	 * One sentence after an import, for a module where the owner should
	 * check something right away, or ''.
	 *
	 * @param string $module Module id.
	 * @return string
	 */
	public static function after_import_note( $module ) {
		if ( 'smtp' === $module ) {
			return __( 'Send a test email from Perdita, Email now, since your password reset mail depends on these settings once you deactivate the other plugin.', 'perdita-core' );
		}
		if ( 'security' === $module ) {
			return __( 'Check the allowlist on Perdita, Security before you deactivate the other plugin, so you can\'t lock yourself out.', 'perdita-core' );
		}
		return '';
	}

	/**
	 * What happens while the old plugin and Perdita both run.
	 *
	 * @param string $module Module id.
	 * @param string $label  Plugin name.
	 * @return string
	 */
	public static function conflict_note( $module, $label ) {
		switch ( $module ) {
			case 'caching':
				/* translators: %1$s: plugin name. */
				return sprintf( __( 'Perdita\'s page cache is off while %1$s is active, so you never have two caches on the same pages. You get Perdita\'s page cache once you deactivate %1$s.', 'perdita-core' ), $label );
			case 'seo':
				/* translators: %1$s: plugin name. */
				return sprintf( __( 'Perdita SEO is off while %1$s is active, so you never get two sets of titles and tags on a page. You get Perdita\'s tags once you deactivate %1$s.', 'perdita-core' ), $label );
			case 'smtp':
				/* translators: %1$s: plugin name. */
				return sprintf( __( 'While %1$s is active, you have two sets of mailer settings on one site, and only one of them takes effect. Send a test email from Perdita, Email, then deactivate %1$s.', 'perdita-core' ), $label );
			case 'analytics':
				/* translators: %1$s: plugin name. */
				return sprintf( __( 'While %1$s is active and you have a measurement ID in Perdita, you get the Google tag twice on each page and count every visit twice. Deactivate %1$s right after the import.', 'perdita-core' ), $label );
			case 'security':
				/* translators: %1$s: plugin name. */
				return sprintf( __( 'While %1$s is active, you have two login limits, and a visitor can get locked out by either one. Deactivate %1$s once you\'ve checked Perdita, Security.', 'perdita-core' ), $label );
		}
		return '';
	}

	/**
	 * A module's name for the screen.
	 *
	 * @param string $module Module id.
	 * @return string
	 */
	public static function module_label( $module ) {
		if ( function_exists( 'perdita_core' ) && isset( perdita_core()->modules ) ) {
			$all = perdita_core()->modules->all();
			if ( isset( $all[ $module ]['label'] ) ) {
				return (string) $all[ $module ]['label'];
			}
		}
		return (string) $module;
	}
}
