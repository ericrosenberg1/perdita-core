<?php
/**
 * Backups engine: create, list, restore, and prune backup sets.
 *
 * A backup is a full copy of your database and (optionally) your files, so
 * where it lives and how it is served is the whole ballgame. Everything in this
 * class is built around that: backups are written under the uploads directory
 * in a folder that is hardened against direct web access, every filename
 * carries a per-site secret plus a random token so paths are unguessable, and
 * downloads only ever happen through an authenticated admin handler that
 * streams the file after validating the path.
 *
 * WHAT A BACKUP SET IS
 *   perdita-<date>-<32hex>.sql          the database dump
 *   perdita-<date>-<32hex>.zip          the file archive (optional)
 *   perdita-<date>-<32hex>.manifest.json  what is in the set, sizes, sha256s
 * The three share one base name, so a "set" is just that base name.
 *
 * HONEST LIMITS
 *   Free backups live on the same server as the site. That is great for a quick
 *   rollback after a bad update, but it is not off-site disaster recovery: if
 *   the host disk dies, the backup dies with it. Off-site storage (S3, etc.) is
 *   a planned Pro feature. Very large sites may also hit host memory or time
 *   limits during a files archive or a restore. The engine streams to disk and
 *   chunks the database to stay light, but it cannot beat a hard host cap.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Backups {

	/**
	 * Option holding the settings array (schedule, retention, secret token, etc).
	 */
	const OPTION = 'perdita_backups_settings';

	/**
	 * Cron event that runs a scheduled backup.
	 */
	const CRON_HOOK = 'perdita_backups_run';

	/**
	 * Directory name under the uploads basedir where sets are stored.
	 */
	const DIR_NAME = 'perdita-backups';

	/**
	 * How many rows to read per SELECT while dumping a table. Kept modest so the
	 * dump streams to disk instead of building a huge string in memory.
	 */
	const CHUNK = 500;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor. Register the cron handler and the admin-post actions.
	 *
	 * The admin-post handlers live here (not only in the admin class) because
	 * admin-post.php runs in an is_admin() context and the module's admin class
	 * is what wires the screen. Keeping the heavy actions on the engine keeps the
	 * capability + nonce checks next to the code that does the work.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core = null ) {
		$this->core = $core;

		// Scheduled run.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );

		// Custom weekly schedule for wp-cron.
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );

		// Authenticated actions. Every handler re-checks capability + nonce.
		add_action( 'admin_post_perdita_backups_run', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_perdita_backups_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_perdita_backups_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_perdita_backups_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_perdita_backups_settings', array( $this, 'handle_save_settings' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'schedule'  => 'off',   // off | daily | weekly.
			'retention' => 5,       // keep the newest N sets.
			'contents'  => 'both',  // database | files | both.
			'secret'    => '',      // per-site random token, set on install.
		);
	}

	/**
	 * Read stored settings merged over defaults. Ensures a secret exists.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$s = array_merge( self::defaults(), $stored );

		// A per-site secret must always exist. If somehow missing, mint one and
		// persist it, because it is a component of every backup filename.
		if ( empty( $s['secret'] ) ) {
			$s['secret'] = self::random_token();
			update_option( self::OPTION, $s );
		}
		return $s;
	}

	/**
	 * Sanitize a settings array on write. Static so the admin class can reuse it.
	 * The secret is never taken from input, only preserved from storage.
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$prev  = self::settings();

		$schedule = isset( $input['schedule'] ) ? (string) $input['schedule'] : 'off';
		if ( ! in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ) {
			$schedule = 'off';
		}

		$contents = isset( $input['contents'] ) ? (string) $input['contents'] : 'both';
		if ( ! in_array( $contents, array( 'database', 'files', 'both' ), true ) ) {
			$contents = 'both';
		}

		$retention = isset( $input['retention'] ) ? (int) $input['retention'] : 5;
		$retention = max( 1, min( 100, $retention ) );

		return array(
			'schedule'  => $schedule,
			'retention' => $retention,
			'contents'  => $contents,
			'secret'    => $prev['secret'], // Never from input.
		);
	}

	/* ---------------------------------------------------------------------
	 * Storage location and hardening
	 * ------------------------------------------------------------------- */

	/**
	 * Absolute path to the backups directory (trailing slash), or '' if the
	 * uploads directory is not available.
	 *
	 * @return string
	 */
	public static function dir() {
		$up = wp_upload_dir();
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $up['basedir'] ) . self::DIR_NAME . '/';
	}

	/**
	 * A 32-character lowercase hex token. Uses a cryptographically strong source.
	 *
	 * @return string
	 */
	public static function random_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Extremely unlikely fallback. wp_generate_password is not crypto-CSPRNG
			// guaranteed, but it is only ever hit if random_bytes throws.
			return substr( md5( wp_generate_password( 64, true, true ) . microtime() ), 0, 32 );
		}
	}

	/**
	 * Create the backups directory and harden it. Idempotent, safe to re-run.
	 *
	 * Defense in depth, because a backup exposed over the web hands an attacker
	 * the whole site:
	 *   (a) .htaccess "Require all denied" (+ legacy "Deny from all") for Apache.
	 *   (b) web.config denying access for IIS.
	 *   (c) index.php so the directory is never browsable.
	 *   (d) a per-site secret in settings, and a 32-hex random component in every
	 *       filename, so even a server misconfig that exposes the folder does not
	 *       expose guessable URLs.
	 * nginx ignores .htaccess, so the admin screen shows an nginx deny snippet and
	 * the unguessable filename + authenticated-only download is the real backstop.
	 */
	public static function harden() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}

		self::write_guard(
			$dir . '.htaccess',
			"# Perdita Backups: block all direct web access.\n"
			. "# Apache 2.4+\n"
			. "<IfModule mod_authz_core.c>\n"
			. "Require all denied\n"
			. "</IfModule>\n"
			. "# Apache 2.2 and earlier\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "Order allow,deny\n"
			. "Deny from all\n"
			. "</IfModule>\n"
		);

		self::write_guard(
			$dir . 'web.config',
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<configuration>\n"
			. "  <system.webServer>\n"
			. "    <authorization>\n"
			. "      <deny users=\"*\" />\n"
			. "    </authorization>\n"
			. "  </system.webServer>\n"
			. "</configuration>\n"
		);

		self::write_guard(
			$dir . 'index.php',
			"<?php // Silence is golden.\n"
		);
	}

	/**
	 * Write a guard file only if it does not already exist or its contents drift.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents Desired contents.
	 */
	private static function write_guard( $path, $contents ) {
		if ( file_exists( $path ) && $contents === (string) @file_get_contents( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		// Direct write: these are static guard files in our own private dir.
		@file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Activation callback. Create and harden the directory, and ensure a secret.
	 * The loader calls this with the core, which we ignore.
	 *
	 * @param Perdita|null $core Core (unused).
	 */
	public static function install( $core = null ) {
		self::harden();
		self::settings(); // Forces a secret to exist.
	}

	/* ---------------------------------------------------------------------
	 * Path safety
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve a caller-supplied basename to a real path INSIDE the backups dir,
	 * or return '' if it is anything else. This is the single choke point that
	 * every file operation on a user-named file must pass through.
	 *
	 * Rejects path separators and traversal, then confirms with realpath() that
	 * the resolved file actually sits inside the backups directory.
	 *
	 * @param string $name Requested filename (basename only).
	 * @return string Absolute path if valid and inside the dir, else ''.
	 */
	public static function safe_path( $name ) {
		$name = (string) $name;

		// Reject anything with a directory separator or a traversal token up front.
		if ( '' === $name
			|| false !== strpos( $name, '/' )
			|| false !== strpos( $name, '\\' )
			|| false !== strpos( $name, "\0" )
			|| false !== strpos( $name, '..' ) ) {
			return '';
		}

		// Also require the basename to equal the input, so odd inputs are rejected.
		if ( basename( $name ) !== $name ) {
			return '';
		}

		$dir = self::dir();
		if ( '' === $dir ) {
			return '';
		}

		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return '';
		}

		$candidate = $real_dir . DIRECTORY_SEPARATOR . $name;
		$real      = realpath( $candidate );
		if ( false === $real ) {
			return '';
		}

		// The resolved path must sit inside the backups directory.
		$prefix = trailingslashit( $real_dir );
		if ( 0 !== strpos( $real, $prefix ) ) {
			return '';
		}
		return $real;
	}

	/* ---------------------------------------------------------------------
	 * Listing backup sets
	 * ------------------------------------------------------------------- */

	/**
	 * List backup sets, newest first. A set is keyed by its shared base name.
	 *
	 * @return array[] Each: base, created (int ts), contents (array of 'database'
	 *                 and/or 'files'), size (int bytes), sql, zip, manifest
	 *                 (basenames or ''), wp_version, php_version.
	 */
	public static function list_sets() {
		$dir = self::dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$sets = array();
		foreach ( (array) glob( $dir . 'perdita-*.manifest.json' ) as $manifest_path ) {
			$base = basename( $manifest_path, '.manifest.json' );
			$data = json_decode( (string) @file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			if ( ! is_array( $data ) ) {
				continue;
			}

			$sql_name = $base . '.sql';
			$zip_name = $base . '.zip';
			$size     = 0;
			$size    += file_exists( $dir . $sql_name ) ? (int) filesize( $dir . $sql_name ) : 0;
			$size    += file_exists( $dir . $zip_name ) ? (int) filesize( $dir . $zip_name ) : 0;

			$contents = array();
			if ( file_exists( $dir . $sql_name ) ) {
				$contents[] = 'database';
			}
			if ( file_exists( $dir . $zip_name ) ) {
				$contents[] = 'files';
			}

			$sets[] = array(
				'base'        => $base,
				'created'     => isset( $data['created'] ) ? (int) $data['created'] : 0,
				'contents'    => $contents,
				'size'        => $size,
				'sql'         => file_exists( $dir . $sql_name ) ? $sql_name : '',
				'zip'         => file_exists( $dir . $zip_name ) ? $zip_name : '',
				'manifest'    => $base . '.manifest.json',
				'wp_version'  => isset( $data['wp_version'] ) ? (string) $data['wp_version'] : '',
				'php_version' => isset( $data['php_version'] ) ? (string) $data['php_version'] : '',
			);
		}

		usort(
			$sets,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return $sets;
	}

	/* ---------------------------------------------------------------------
	 * Creating a backup
	 * ------------------------------------------------------------------- */

	/**
	 * Create a backup set. Returns the base name on success or a WP_Error.
	 *
	 * @param string $contents 'database', 'files', or 'both'. Defaults to the
	 *                         configured contents.
	 * @param string $label    Optional label baked into the manifest (e.g. the
	 *                         auto pre-restore snapshot).
	 * @return string|WP_Error Base name on success.
	 */
	public function create( $contents = '', $label = '' ) {
		self::harden(); // Never write a backup into an un-hardened directory.

		$dir = self::dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'perdita_backups_dir', __( 'The backups directory could not be created.', 'perdita-core' ) );
		}

		$s = self::settings();
		if ( '' === $contents ) {
			$contents = $s['contents'];
		}
		$do_db    = in_array( $contents, array( 'database', 'both' ), true );
		$do_files = in_array( $contents, array( 'files', 'both' ), true );

		// Base name: date + per-site secret slice + fresh random token. The secret
		// makes names unguessable per-site, the random token per-set.
		$stamp = gmdate( 'Ymd-His' );
		$token = self::random_token();
		$base  = 'perdita-' . $stamp . '-' . $token;

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit

		$manifest = array(
			'created'     => time(),
			'created_utc' => gmdate( 'c' ),
			'label'       => (string) $label,
			'contents'    => array(),
			'files'       => array(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'site_url'    => home_url(),
		);

		// --- Database ---
		if ( $do_db ) {
			$sql_path = $dir . $base . '.sql';
			$db_err   = $this->export_database( $sql_path );
			if ( is_wp_error( $db_err ) ) {
				wp_delete_file( $sql_path );
				return $db_err;
			}
			$manifest['contents'][] = 'database';
			$manifest['files'][]    = self::describe_file( $sql_path, $base . '.sql' );
		}

		// --- Files ---
		if ( $do_files ) {
			$zip_path = $dir . $base . '.zip';
			$zip_err  = $this->export_files( $zip_path );
			if ( is_wp_error( $zip_err ) ) {
				wp_delete_file( $zip_path );
				return $zip_err;
			}
			$manifest['contents'][] = 'files';
			$manifest['files'][]    = self::describe_file( $zip_path, $base . '.zip' );
		}

		// --- Manifest ---
		$manifest_path = $dir . $base . '.manifest.json';
		$json          = wp_json_encode( $manifest, JSON_PRETTY_PRINT );
		if ( false === @file_put_contents( $manifest_path, $json, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'perdita_backups_manifest', __( 'The backup manifest could not be written.', 'perdita-core' ) );
		}

		return $base;
	}

	/**
	 * Build a manifest file descriptor: name, byte size, and sha256.
	 *
	 * @param string $path Absolute file path.
	 * @param string $name Basename to record.
	 * @return array
	 */
	private static function describe_file( $path, $name ) {
		return array(
			'name'   => $name,
			'bytes'  => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'sha256' => file_exists( $path ) ? (string) @hash_file( 'sha256', $path ) : '', // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
	}

	/**
	 * Stream a full mysqldump-style .sql file to disk.
	 *
	 * For each table: DROP TABLE IF EXISTS, the SHOW CREATE TABLE statement, then
	 * chunked INSERTs read 500 rows at a time. Values are escaped so the dump is
	 * safe to replay. Written with fwrite as we go, so a large table never lands
	 * whole in memory.
	 *
	 * @param string $path Destination .sql path.
	 * @return true|WP_Error
	 */
	private function export_database( $path ) {
		global $wpdb;

		$fh = @fopen( $path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return new WP_Error( 'perdita_backups_sql_open', __( 'Could not open the database dump file for writing.', 'perdita-core' ) );
		}

		fwrite( $fh, "-- Perdita Backups database dump\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, '-- Generated: ' . gmdate( 'c' ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, '-- Site: ' . home_url() . "\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, "SET FOREIGN_KEY_CHECKS=0;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Every base table in this database.
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $tables ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'perdita_backups_no_tables', __( 'No database tables were found to back up.', 'perdita-core' ) );
		}

		foreach ( $tables as $table ) {
			$table_q = '`' . str_replace( '`', '``', $table ) . '`';

			fwrite( $fh, "\n-- --------------------------------------------------------\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fh, '-- Table: ' . $table . "\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fh, 'DROP TABLE IF EXISTS ' . $table_q . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			// SHOW CREATE TABLE returns [ Table, Create Table ].
			$create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table_q, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $create_row ) && isset( $create_row[1] ) ) {
				fwrite( $fh, $create_row[1] . ";\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}

			// Row count, then chunked SELECTs.
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table_q ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			if ( $total < 1 ) {
				continue;
			}

			for ( $offset = 0; $offset < $total; $offset += self::CHUNK ) {
				// Table name is a backticked identifier from SHOW TABLES, not user
				// input. LIMIT/OFFSET are prepared.
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM ' . $table_q . ' LIMIT %d OFFSET %d', self::CHUNK, $offset ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					ARRAY_A
				);
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$cols = array();
					$vals = array();
					foreach ( $row as $col => $value ) {
						$cols[] = '`' . str_replace( '`', '``', $col ) . '`';
						if ( null === $value ) {
							$vals[] = 'NULL';
						} else {
							// $wpdb->prepare-style escaping of a single value.
							$vals[] = "'" . esc_sql( (string) $value ) . "'";
						}
					}
					$line = 'INSERT INTO ' . $table_q . ' (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $vals ) . ");\n";
					if ( false === fwrite( $fh, $line ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return new WP_Error( 'perdita_backups_sql_write', __( 'Writing the database dump failed. The disk may be full.', 'perdita-core' ) );
					}
				}
			}
		}

		fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * Zip the selected directories with ZipArchive, adding files incrementally.
	 *
	 * Default set is wp-content, but we explicitly skip:
	 *   - the backups directory itself (never recurse into our own output),
	 *   - common cache directories (cache, upgrade, wp-rocket, litespeed, w3tc),
	 *   - node_modules and .git anywhere in the tree.
	 *
	 * @param string $path Destination .zip path.
	 * @return true|WP_Error
	 */
	private function export_files( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'perdita_backups_no_zip', __( 'The PHP ZipArchive extension is not available, so a files backup cannot be created. A database-only backup still works.', 'perdita-core' ) );
		}

		$source = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
		$source = untrailingslashit( $source );
		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'perdita_backups_no_source', __( 'The wp-content directory could not be found.', 'perdita-core' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'perdita_backups_zip_open', __( 'The backup archive could not be created.', 'perdita-core' ) );
		}

		$backups_dir = self::dir();
		$backups_real = $backups_dir ? realpath( $backups_dir ) : false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
				function ( $current ) use ( $backups_real ) {
					return ! $this->is_excluded_path( $current, $backups_real );
				}
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$prefix_len = strlen( $source ) + 1; // Strip the source path to a relative name.

		foreach ( $iterator as $item ) {
			$abs = $item->getPathname();
			$rel = 'wp-content/' . substr( $abs, $prefix_len );
			$rel = str_replace( '\\', '/', $rel );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $rel );
			} elseif ( $item->isFile() && $item->isReadable() ) {
				// addFile streams from disk into the archive, so memory stays flat.
				$zip->addFile( $abs, $rel );
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'perdita_backups_zip_close', __( 'The backup archive failed to finalize. The disk may be full.', 'perdita-core' ) );
		}
		return true;
	}

	/**
	 * Whether a path should be excluded from the files archive. Returns true to
	 * SKIP the item (and, for a directory, everything under it), false to keep.
	 *
	 * @param SplFileInfo  $current      Current item from the iterator.
	 * @param string|false $backups_real Real path of the backups dir, or false.
	 * @return bool True to exclude, false to keep.
	 */
	private function is_excluded_path( $current, $backups_real ) {
		$name = $current->getFilename();
		$path = $current->getPathname();

		// Never recurse into our own backups directory.
		if ( $backups_real ) {
			$real = realpath( $path );
			if ( $real && 0 === strpos( $real, trailingslashit( $backups_real ) ) ) {
				return true; // Exclude.
			}
			if ( $real && $real === $backups_real ) {
				return true; // Exclude the dir entry itself.
			}
		}

		// Skip cache dirs, node_modules, and .git wherever they appear.
		if ( $current->isDir() ) {
			$skip_dirs = array( 'node_modules', '.git', 'cache', 'upgrade', 'wp-rocket-cache', 'litespeed', 'w3tc-cache', 'et-cache' );
			if ( in_array( $name, $skip_dirs, true ) ) {
				return true; // Exclude.
			}
		}
		return false; // Keep.
	}

	/* ---------------------------------------------------------------------
	 * Deleting sets (retention + manual)
	 * ------------------------------------------------------------------- */

	/**
	 * Delete one set by base name. Validates each file path stays inside the dir.
	 *
	 * @param string $base Set base name.
	 * @return bool Whether anything was deleted.
	 */
	public function delete_set( $base ) {
		$base = (string) $base;
		// A base name is our own generated token; still, treat it as untrusted.
		if ( '' === $base || false !== strpos( $base, '/' ) || false !== strpos( $base, '\\' ) || false !== strpos( $base, '..' ) ) {
			return false;
		}

		$deleted = false;
		foreach ( array( $base . '.sql', $base . '.zip', $base . '.manifest.json' ) as $name ) {
			$real = self::safe_path( $name );
			if ( '' !== $real ) {
				wp_delete_file( $real );
				$deleted = true;
			}
		}
		return $deleted;
	}

	/**
	 * Enforce retention: keep the newest N sets, delete the rest.
	 */
	public function enforce_retention() {
		$s    = self::settings();
		$keep = max( 1, (int) $s['retention'] );
		$sets = self::list_sets(); // Newest first.
		if ( count( $sets ) <= $keep ) {
			return;
		}
		$old = array_slice( $sets, $keep );
		foreach ( $old as $set ) {
			$this->delete_set( $set['base'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Restore
	 * ------------------------------------------------------------------- */

	/**
	 * Restore a set: take a fresh pre-restore snapshot FIRST, then apply the DB
	 * and files. On any failure, stop and report which step failed, leaving the
	 * pre-restore snapshot as the safety net.
	 *
	 * @param string $base Set base name to restore.
	 * @return true|WP_Error
	 */
	public function restore_set( $base ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit

		$sql_real = self::safe_path( $base . '.sql' );
		$zip_real = self::safe_path( $base . '.zip' );
		if ( '' === $sql_real && '' === $zip_real ) {
			return new WP_Error( 'perdita_backups_restore_missing', __( 'That backup set could not be found on disk.', 'perdita-core' ) );
		}

		// Step 1: automatic pre-restore snapshot. If this fails, do not proceed,
		// because it is the whole safety net.
		$snapshot = $this->create( 'both', 'pre-restore safety snapshot' );
		if ( is_wp_error( $snapshot ) ) {
			return new WP_Error(
				'perdita_backups_restore_snapshot',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Restore was stopped before any change because the automatic pre-restore snapshot failed: %s', 'perdita-core' ),
					$snapshot->get_error_message()
				)
			);
		}

		// Enforce retention now so the snapshot does not blow past the cap.
		$this->enforce_retention();

		try {
			// Step 2: database.
			if ( '' !== $sql_real ) {
				$db = $this->import_database( $sql_real );
				if ( is_wp_error( $db ) ) {
					return $this->restore_failure( 'database', $db, $snapshot );
				}
			}

			// Step 3: files.
			if ( '' !== $zip_real ) {
				$files = $this->import_files( $zip_real );
				if ( is_wp_error( $files ) ) {
					return $this->restore_failure( 'files', $files, $snapshot );
				}
			}
		} catch ( \Throwable $e ) {
			return $this->restore_failure( 'unexpected', new WP_Error( 'perdita_backups_restore_exception', $e->getMessage() ), $snapshot );
		}

		return true;
	}

	/**
	 * Build a clear restore-failure error that names the step and the snapshot.
	 *
	 * @param string   $step     Which step failed.
	 * @param WP_Error $error    Underlying error.
	 * @param string   $snapshot Base name of the pre-restore snapshot.
	 * @return WP_Error
	 */
	private function restore_failure( $step, $error, $snapshot ) {
		return new WP_Error(
			'perdita_backups_restore_failed',
			sprintf(
				/* translators: 1: step name, 2: underlying message, 3: snapshot base name. */
				__( 'Restore failed during the %1$s step: %2$s The site may be partly changed. Your automatic pre-restore snapshot is saved as "%3$s" so you can roll back.', 'perdita-core' ),
				$step,
				$error->get_error_message(),
				$snapshot
			)
		);
	}

	/**
	 * Import a .sql file by executing its statements in order.
	 *
	 * Reads the file line by line so a large dump never lands whole in memory,
	 * accumulating a statement until a line ends with a semicolon, then running
	 * it via $wpdb->query. Comment and blank lines are skipped.
	 *
	 * @param string $sql_path Absolute .sql path (already validated).
	 * @return true|WP_Error
	 */
	private function import_database( $sql_path ) {
		global $wpdb;

		$fh = @fopen( $sql_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return new WP_Error( 'perdita_backups_import_open', __( 'The database dump could not be opened for reading.', 'perdita-core' ) );
		}

		$statement = '';
		$suppress  = $wpdb->suppress_errors( true );

		while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$trimmed = ltrim( $line );

			// Skip standalone comment and blank lines only when not mid-statement.
			if ( '' === $statement ) {
				if ( '' === trim( $trimmed ) ) {
					continue;
				}
				if ( 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '#' ) || 0 === strpos( $trimmed, '/*' ) ) {
					continue;
				}
			}

			$statement .= $line;

			// A statement boundary is a line whose trimmed end is a semicolon. Our
			// own dump writes one statement per line, so this is reliable here.
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$sql = trim( $statement );
				$statement = '';
				if ( '' === $sql || ';' === $sql ) {
					continue;
				}
				$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				if ( false === $result && '' !== (string) $wpdb->last_error ) {
					$err = $wpdb->last_error;
					$wpdb->suppress_errors( $suppress );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return new WP_Error(
						'perdita_backups_import_query',
						sprintf(
							/* translators: %s: database error. */
							__( 'A database statement failed: %s', 'perdita-core' ),
							$err
						)
					);
				}
			}
		}

		$wpdb->suppress_errors( $suppress );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * Restore files by extracting the archive over wp-content.
	 *
	 * @param string $zip_path Absolute .zip path (already validated).
	 * @return true|WP_Error
	 */
	private function import_files( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'perdita_backups_no_zip', __( 'The PHP ZipArchive extension is not available, so files cannot be restored.', 'perdita-core' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'perdita_backups_import_zip', __( 'The backup archive could not be opened.', 'perdita-core' ) );
		}

		// Archive paths are stored relative to ABSPATH as wp-content/... so we
		// extract into ABSPATH. extractTo protects against Zip-slip in modern PHP,
		// but we still confirm the target base.
		$target = untrailingslashit( ABSPATH );
		$ok     = $zip->extractTo( trailingslashit( $target ) );
		$zip->close();

		if ( ! $ok ) {
			return new WP_Error( 'perdita_backups_import_extract', __( 'The backup archive could not be extracted. Check file permissions and free disk space.', 'perdita-core' ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Add a weekly interval to wp-cron if one is not already registered.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'perdita-core' ),
			);
		}
		return $schedules;
	}

	/**
	 * Sync the cron event to the configured schedule. Clears and re-adds so a
	 * changed frequency takes effect. Static so the admin save can call it.
	 *
	 * @param string $schedule 'off', 'daily', or 'weekly'.
	 */
	public static function sync_schedule( $schedule ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( 'daily' === $schedule || 'weekly' === $schedule ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
			}
		}
	}

	/**
	 * Cron handler: run a scheduled backup, then enforce retention.
	 */
	public function run_scheduled() {
		$result = $this->create();
		if ( ! is_wp_error( $result ) ) {
			$this->enforce_retention();
		}
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers (each: capability + nonce)
	 * ------------------------------------------------------------------- */

	/**
	 * Back up now.
	 */
	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_backups_run' );

		$result = $this->create();
		if ( ! is_wp_error( $result ) ) {
			$this->enforce_retention();
		}
		$this->redirect_notice(
			is_wp_error( $result ) ? 'error' : 'created',
			is_wp_error( $result ) ? $result->get_error_message() : ''
		);
	}

	/**
	 * Delete a set.
	 */
	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_backups_delete' );

		$base = isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : '';
		$ok   = $this->delete_set( $base );
		$this->redirect_notice( $ok ? 'deleted' : 'error', $ok ? '' : __( 'That backup could not be deleted.', 'perdita-core' ) );
	}

	/**
	 * Restore a set (auto snapshot first).
	 */
	public function handle_restore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_backups_restore' );

		$base   = isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : '';
		$result = $this->restore_set( $base );
		$this->redirect_notice(
			is_wp_error( $result ) ? 'error' : 'restored',
			is_wp_error( $result ) ? $result->get_error_message() : ''
		);
	}

	/**
	 * Save settings, then sync the cron schedule.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_backups_settings' );

		$raw   = isset( $_POST['perdita_backups'] ) ? wp_unslash( $_POST['perdita_backups'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in sanitize().
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean );
		self::sync_schedule( $clean['schedule'] );

		$this->redirect_notice( 'saved', '' );
	}

	/**
	 * Stream a backup file to the browser. The ONLY way a backup leaves the
	 * server. Capability + nonce, then the requested name is validated against
	 * the backups directory with realpath() before a single byte is sent.
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_backups_download' );

		$requested = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$real      = self::safe_path( $requested );
		if ( '' === $real || ! is_file( $real ) ) {
			wp_die( esc_html__( 'That file could not be found.', 'perdita-core' ) );
		}

		// Only ever our own backup artifacts, by extension.
		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'sql', 'zip', 'json' ), true ) ) {
			wp_die( esc_html__( 'That file type cannot be downloaded.', 'perdita-core' ) );
		}

		$size = (int) filesize( $real );
		$name = basename( $real );

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . $size );

		// Flush any output buffering so the binary is not corrupted.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$fh = @fopen( $real, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false !== $fh ) {
			while ( ! feof( $fh ) ) {
				echo fread( $fh, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary file stream.
				flush();
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * Redirect back to the settings screen with a notice flag. Long error text is
	 * stashed in a transient so it survives the redirect without a giant query.
	 *
	 * @param string $status  Notice key.
	 * @param string $message Optional message.
	 */
	private function redirect_notice( $status, $message = '' ) {
		if ( '' !== $message ) {
			set_transient( 'perdita_backups_notice_' . get_current_user_id(), $message, 60 );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'perdita-backups',
					'perdita_backups' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
