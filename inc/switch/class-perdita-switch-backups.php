<?php
/**
 * Backup copies of the files Switch to Perdita edits or removes.
 *
 * A copy of wp-config.php or .htaccess left beside the original is a risk on
 * any server that would hand it to a browser. So every copy goes into one
 * protected folder, wp-content/uploads/perdita-switch-backups/ (the site's
 * uploads base), and never beside the file:
 *
 *   - The folder carries an index.php, an .htaccess that refuses every
 *     request (Apache 2.2 and 2.4), and a web.config that does the same on
 *     IIS.
 *   - Every name ends in .bak and never contains ".php", so no server hands
 *     a copy to PHP, even one that maps handlers by any extension in a name.
 *   - Every name carries 20 hex characters from an HMAC keyed on the site's
 *     auth salt and 16 random bytes, so nobody can guess one on a server that
 *     ignores .htaccess (nginx).
 *   - Every copy is 0600, and a folder copy is 0700, so on a server where
 *     the web server runs as another user than PHP, it can't read them.
 *   - wp-config.php is never copied here. Adding WP_CACHE leaves a note
 *     naming the one line to delete instead, so the database password and
 *     the salts never sit under a public folder (Perdita_Switch_Leftovers).
 *
 * Perdita Pro and any other source call save_file() and save_dir() for the
 * same protection, and list() and delete_all() back the "Backup copies" list
 * on the Switch to Perdita screen.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Backups {

	/**
	 * The folder's name under the uploads base.
	 */
	const DIR = 'perdita-switch-backups';

	/**
	 * A backup's name: kind, UTC date and time, token, and .bak for a file.
	 */
	const NAME_PATTERN = '/^[a-z0-9-]{1,40}-\d{8}-\d{6}-[a-f0-9]{20}(\.bak)?$/';

	/**
	 * The folder, with a trailing slash.
	 *
	 * @return string
	 */
	public static function dir() {
		$up = wp_get_upload_dir();
		return trailingslashit( $up['basedir'] ) . self::DIR . '/';
	}

	/**
	 * The folder as the owner would type it, relative to the WordPress root
	 * when it sits under it.
	 *
	 * @return string
	 */
	public static function display_dir() {
		$dir  = wp_normalize_path( self::dir() );
		$root = wp_normalize_path( trailingslashit( ABSPATH ) );
		return 0 === strpos( $dir, $root ) ? substr( $dir, strlen( $root ) ) : $dir;
	}

	/**
	 * Create the folder and its guard files.
	 *
	 * @return bool Whether the folder exists and is writable.
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			// For a server set to list folders that looks for index.html.
			'index.html' => '',
			'.htaccess'  => implode(
				"\n",
				array(
					'# Perdita: backup copies of edited files. Never served.',
					'<IfModule mod_authz_core.c>',
					'Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'Order allow,deny',
					'Deny from all',
					'</IfModule>',
					'',
				)
			),
			'web.config' => implode(
				"\n",
				array(
					'<?xml version="1.0" encoding="UTF-8"?>',
					'<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
					'',
				)
			),
		);
		foreach ( $guards as $name => $body ) {
			if ( ! is_file( $dir . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a static guard file in our own folder.
				file_put_contents( $dir . $name, $body );
			}
		}
		return is_file( $dir . '.htaccess' ) && is_file( $dir . 'index.php' ) && wp_is_writable( $dir );
	}

	/**
	 * A fresh name for one copy.
	 *
	 * @param string $kind Short slug, such as htaccess or wp-config.
	 * @param bool   $file Whether the copy is a file (.bak) or a folder.
	 * @return string
	 */
	public static function name( $kind, $file = true ) {
		$kind  = substr( trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $kind ) ), '-' ), 0, 40 );
		$kind  = '' === $kind ? 'file' : $kind;
		$token = substr( hash_hmac( 'sha256', $kind . '|' . microtime( true ) . '|' . bin2hex( random_bytes( 16 ) ), wp_salt( 'auth' ) ), 0, 20 );
		return $kind . '-' . gmdate( 'Ymd-His' ) . '-' . $token . ( $file ? '.bak' : '' );
	}

	/**
	 * Copy one file into the folder and check the copy.
	 *
	 * @param string $path The file to copy.
	 * @param string $kind Short slug for the name.
	 * @return string The copy's name, or '' when nothing was saved.
	 */
	public static function save_file( $path, $kind ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) || ! self::ensure_dir() ) {
			return '';
		}
		$name = self::name( $kind );
		$dest = self::dir() . $name;
		if ( ! self::copy_checked( $path, $dest ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * Copy the plain files at the top of one folder into a new subfolder.
	 * Each copy keeps its name with every dot turned into a dash, plus .bak,
	 * so a config.php copy never ends in .php.
	 *
	 * @param string $source Folder to copy.
	 * @param string $kind   Short slug for the name.
	 * @return string The subfolder's name, or '' when nothing was saved.
	 */
	public static function save_dir( $source, $kind ) {
		$source = untrailingslashit( (string) $source );
		if ( ! is_dir( $source ) || ! is_readable( $source ) || ! self::ensure_dir() ) {
			return '';
		}
		$name = self::name( $kind, false );
		$dest = self::dir() . $name;
		if ( ! wp_mkdir_p( $dest ) ) {
			return '';
		}
		chmod( $dest, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the copies can hold settings or credentials.
		$used = array();
		foreach ( (array) scandir( $source ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || is_link( $source . '/' . $entry ) || ! is_file( $source . '/' . $entry ) ) {
				continue;
			}
			$safe = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $entry ), '-' );
			$safe = ( '' === $safe ? 'file' : $safe ) . '.bak';
			// a.b.php and a-b.php both slug to a-b-php: number the second, so
			// no copy writes over another.
			$base = substr( $safe, 0, -4 );
			for ( $n = 2; isset( $used[ $safe ] ); $n++ ) {
				$safe = $base . '-' . $n . '.bak';
			}
			$used[ $safe ] = true;
			if ( file_exists( $dest . '/' . $safe ) || ! self::copy_checked( $source . '/' . $entry, $dest . '/' . $safe ) ) {
				self::delete_path( $dest );
				return '';
			}
		}
		return $name;
	}

	/**
	 * Copy, make it 0600, and compare the bytes.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @return bool
	 */
	private static function copy_checked( $from, $to ) {
		if ( file_exists( $to ) || is_link( $to ) || ! copy( $from, $to ) ) {
			return false;
		}
		chmod( $to, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a copy of wp-config.php holds database credentials.
		clearstatcache( true, $to );
		if ( filesize( $from ) !== filesize( $to ) || hash_file( 'sha256', $from ) !== hash_file( 'sha256', $to ) ) {
			wp_delete_file( $to );
			return false;
		}
		return true;
	}

	/**
	 * The copies in the folder, newest first.
	 *
	 * @return array[] name, size (bytes), time (Unix), dir (bool).
	 */
	public static function all() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( ! is_string( $entry ) || ! preg_match( self::NAME_PATTERN, $entry ) ) {
				continue;
			}
			$path = $dir . $entry;
			$size = 0;
			if ( is_dir( $path ) ) {
				foreach ( (array) glob( $path . '/*' ) as $inner ) {
					$size += is_file( $inner ) ? (int) filesize( $inner ) : 0;
				}
			} else {
				$size = (int) filesize( $path );
			}
			$out[] = array(
				'name' => $entry,
				'size' => $size,
				'time' => (int) filemtime( $path ),
				'dir'  => is_dir( $path ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		return $out;
	}

	/**
	 * Delete every copy. The guard files stay, so the folder stays closed.
	 *
	 * @return int How many copies went.
	 */
	public static function delete_all() {
		$n = 0;
		foreach ( self::all() as $item ) {
			if ( self::delete_path( self::dir() . $item['name'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Delete one copy, a file or a folder of plain files.
	 *
	 * @param string $path Path inside the folder.
	 * @return bool
	 */
	private static function delete_path( $path ) {
		if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( self::dir() ) ) ) {
			return false;
		}
		if ( is_link( $path ) ) {
			// Remove the link itself, never what it points at.
			wp_delete_file( $path );
			return ! is_link( $path );
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry && ( is_link( $path . '/' . $entry ) || is_file( $path . '/' . $entry ) ) ) {
					wp_delete_file( $path . '/' . $entry );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- an empty folder of our own.
			return @rmdir( $path );
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}
}
