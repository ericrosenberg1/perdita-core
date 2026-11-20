<?php
/**
 * Page cache housekeeping: the sweep of expired files, the cached pages
 * list, and the debug log.
 *
 * Loaded on first use from Perdita_Caching::load( 'tools' ), so a page view
 * with the debug log off never parses this file.
 *
 * Every path here is built from a directory listing of the cache directory
 * or from an id matched against a strict pattern, never from a URL or any
 * other free text, so nothing can reach outside that directory.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_Tools {

	/**
	 * The most cached pages the admin list reads in one request. Reading
	 * one page's details costs two file reads, so a 50,000-page cache would
	 * otherwise hold the screen for seconds.
	 */
	const LIST_CAP = 2000;

	/**
	 * Size at which the debug log rotates, in bytes.
	 */
	const LOG_MAX_BYTES = 1048576;

	/**
	 * What a cached page's file name looks like: the URL's sha1, then an
	 * optional variant suffix.
	 */
	const PAGE_ID = '[a-f0-9]{40}(?:-[a-f0-9]{12})?';

	/* ---------- garbage collection ---------- */

	/**
	 * Delete cache files nobody will be served again: pages past the cache
	 * lifetime (plus the stale window, when "serve stale" is on), stale
	 * marks past the hard cap, leftovers whose page is gone, dead rebuild
	 * locks, purge marks older than any render, and temp files from a write
	 * that never finished. Then, when the pages left take more than the size
	 * cap, the oldest go until they fit in 90% of it, and the running total
	 * the stores check is reset to what is on disk.
	 *
	 * @param int $budget Seconds this run may take.
	 * @return int Number of pages removed.
	 */
	public static function gc( $budget = 20 ) {
		$real_dir = realpath( Perdita_Caching::cache_dir() );
		if ( false === $real_dir || ! is_dir( $real_dir ) ) {
			return 0;
		}
		$dir      = trailingslashit( $real_dir );
		$settings = Perdita_Caching::settings();
		$ttl      = (int) $settings['ttl_hours'] * HOUR_IN_SECONDS;
		$cap      = Perdita_Caching::stale_max_age();
		$grace    = ! empty( $settings['serve_stale'] ) ? $cap : 0;
		$now      = time();
		$deadline = microtime( true ) + max( 1, (int) $budget );
		$removed  = 0;
		$kept     = array();
		$total    = 0;
		$complete = true;

		$handle = opendir( $dir );
		if ( ! $handle ) {
			return 0;
		}
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard readdir() loop.
		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( microtime( true ) > $deadline ) {
				$complete = false;
				break;
			}
			$file = $dir . $name;
			if ( preg_match( '/^' . self::PAGE_ID . '\.html$/', $name ) ) {
				$mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish mid-sweep.
				if ( $mtime <= 0 ) {
					continue;
				}
				$marked = Perdita_Caching::stale_since( $file );
				if ( $now - $mtime > $ttl + $grace || ( $marked > 0 && $now - $marked > $cap ) ) {
					$removed += Perdita_Caching::delete_entry( $file );
				} else {
					$bytes          = Perdita_Caching::entry_bytes( $file );
					$kept[ $file ]  = array( $mtime, $bytes );
					$total         += $bytes;
				}
			} elseif ( preg_match( '/^(' . self::PAGE_ID . '\.html)\.(json|gz|stale|lock)$/', $name, $m ) ) {
				$age = $now - (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish mid-sweep.
				// A leftover whose page is gone. The page is always written
				// first, so a minute's grace covers a write in progress.
				if ( ( ! is_file( $dir . $m[1] ) && $age > MINUTE_IN_SECONDS ) || ( 'lock' === $m[2] && $age > Perdita_Caching::LOCK_TTL ) ) {
					wp_delete_file( $file );
				}
			} elseif ( preg_match( '/^[a-f0-9]{40}\.purged$/', $name ) ) {
				if ( $now - (int) @filemtime( $file ) > Perdita_Caching::PURGE_MARK_TTL ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish mid-sweep.
					wp_delete_file( $file );
				}
			} elseif ( '.tmp' === substr( $name, -4 ) && is_file( $file ) && $now - (int) @filemtime( $file ) > 5 * MINUTE_IN_SECONDS ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish mid-sweep.
				wp_delete_file( $file );
			}
		}
		closedir( $handle );

		$trimmed = 0;
		if ( $complete ) {
			$max = Perdita_Caching::max_bytes();
			if ( $max > 0 && $total > $max ) {
				uasort(
					$kept,
					static function ( $a, $b ) {
						return $a[0] <=> $b[0];
					}
				);
				$target = (int) floor( $max * 0.9 );
				foreach ( $kept as $file => $info ) {
					if ( $total <= $target ) {
						break;
					}
					if ( 1 === Perdita_Caching::delete_entry( $file ) ) {
						++$trimmed;
					}
					$total -= $info[1];
				}
			}
			Perdita_Caching::write_usage( $total );
		}

		Perdita_Caching::log( 'GC', $removed . ' expired' . ( $trimmed ? ', ' . $trimmed . ' over the size cap' : '' ) );
		return $removed + $trimmed;
	}

	/* ---------- cached pages list ---------- */

	/**
	 * What a cached page's sidecar says about it.
	 *
	 * @param string $file The page's .html file.
	 * @return array{url:string,variant:string,type:string}
	 */
	public static function read_meta( $file ) {
		$meta = array(
			'url'     => '',
			'variant' => '',
			'type'    => 'page',
		);
		$raw  = is_file( $file . '.json' ) ? @file_get_contents( $file . '.json' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local sidecar.
		$data = false !== $raw ? json_decode( (string) $raw, true ) : null;
		$last = is_array( $data ) ? end( $data ) : null;
		if ( is_array( $last ) ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( isset( $last[ $key ] ) && is_string( $last[ $key ] ) ) {
					$meta[ $key ] = $last[ $key ];
				}
			}
		}
		return $meta;
	}

	/**
	 * One row for the list.
	 *
	 * @param string $dir  Cache dir, trailing slash.
	 * @param string $name File name (already matched against PAGE_ID).
	 * @return array|null
	 */
	private static function row( $dir, $name ) {
		$file  = $dir . $name;
		$mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish between the listing and here.
		if ( $mtime <= 0 ) {
			return null;
		}
		$meta = self::read_meta( $file );
		return array(
			'id'      => substr( $name, 0, -5 ),
			'url'     => $meta['url'],
			'variant' => $meta['variant'],
			'type'    => $meta['type'],
			'mtime'   => $mtime,
			'age'     => max( 0, time() - $mtime ),
			'bytes'   => (int) @filesize( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			'stale'   => is_file( $file . '.stale' ),
		);
	}

	/**
	 * A page of the cached pages list, newest first.
	 *
	 * At most LIST_CAP pages are read per request. A search for a full URL
	 * or a path also looks that page up directly by its hash, so it is found
	 * in a cache of any size.
	 *
	 * @param string $search   Text to find in the URL, or ''.
	 * @param int    $paged    1-based page number.
	 * @param int    $per_page Rows per page.
	 * @return array{rows:array,total:int,pages:int,paged:int,capped:bool,cap:int}
	 */
	public static function list_pages( $search = '', $paged = 1, $per_page = 20 ) {
		$out      = array(
			'rows'   => array(),
			'total'  => 0,
			'pages'  => 1,
			'paged'  => 1,
			'capped' => false,
			'cap'    => self::LIST_CAP,
		);
		$real_dir = realpath( Perdita_Caching::cache_dir() );
		if ( false === $real_dir || ! is_dir( $real_dir ) ) {
			return $out;
		}
		$dir    = trailingslashit( $real_dir );
		$search = trim( (string) $search );
		$rows   = array();
		$read   = 0;

		$handle = opendir( $dir );
		if ( $handle ) {
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard readdir() loop.
			while ( false !== ( $name = readdir( $handle ) ) ) {
				if ( ! preg_match( '/^' . self::PAGE_ID . '\.html$/', $name ) ) {
					continue;
				}
				if ( $read >= self::LIST_CAP ) {
					$out['capped'] = true;
					break;
				}
				++$read;
				$row = self::row( $dir, $name );
				if ( null !== $row && ( '' === $search || false !== stripos( $row['url'], $search ) ) ) {
					$rows[ $row['id'] ] = $row;
				}
			}
			closedir( $handle );
		}

		// A full URL or a path names one page exactly, wherever it sits in
		// the directory.
		if ( '' !== $search && $out['capped'] && preg_match( '#^(?:https?://|/)#i', $search ) ) {
			$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$path = (string) wp_parse_url( $search, PHP_URL_PATH );
			$path = '' === $path ? '/' : $path;
			foreach ( array( 'https', 'http' ) as $scheme ) {
				foreach ( (array) glob( $dir . sha1( $scheme . '://' . $host . $path ) . '*.html' ) as $hit ) {
					$name = basename( (string) $hit );
					if ( preg_match( '/^' . self::PAGE_ID . '\.html$/', $name ) && ! isset( $rows[ substr( $name, 0, -5 ) ] ) ) {
						$row = self::row( $dir, $name );
						if ( null !== $row ) {
							$rows[ $row['id'] ] = $row;
						}
					}
				}
			}
		}

		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);

		$per_page     = max( 1, (int) $per_page );
		$out['total'] = count( $rows );
		$out['pages'] = max( 1, (int) ceil( $out['total'] / $per_page ) );
		$out['paged'] = min( $out['pages'], max( 1, (int) $paged ) );
		$out['rows']  = array_slice( $rows, ( $out['paged'] - 1 ) * $per_page, $per_page );
		return $out;
	}

	/**
	 * Delete one cached page by its list id.
	 *
	 * @param string $id The id from list_pages(): 40 hex characters, with an
	 *                   optional dash and 12 more. Anything else is refused,
	 *                   so no path can be smuggled in.
	 * @return bool Whether a page was removed.
	 */
	public static function delete_page( $id ) {
		$id = (string) $id;
		if ( ! preg_match( '/^' . self::PAGE_ID . '$/', $id ) ) {
			return false;
		}
		$file = Perdita_Caching::cache_dir() . $id . '.html';
		if ( ! is_file( $file ) ) {
			return false;
		}
		$meta = self::read_meta( $file );
		if ( 1 !== Perdita_Caching::delete_entry( $file ) ) {
			return false;
		}
		Perdita_Caching::log( 'PURGE', '' !== $meta['url'] ? $meta['url'] : $id );
		if ( '' !== $meta['url'] ) {
			/** This action is documented in class-perdita-caching.php. */
			do_action( 'perdita_cache_purged_url', $meta['url'], 1 );
		}
		return true;
	}

	/* ---------- debug log ---------- */

	/**
	 * The first line of the log file. The file ends in .php, so a direct
	 * request runs this line and shows nothing.
	 */
	const LOG_GUARD = "<?php exit; ?>\n";

	/**
	 * Append one line to the debug log, rotating it when it has grown past
	 * LOG_MAX_BYTES. One older generation is kept.
	 *
	 * @param string $event  Event name.
	 * @param string $detail Detail.
	 */
	public static function log( $event, $detail = '' ) {
		$path = Perdita_Caching::log_path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			Perdita_Caching::install();
			if ( ! is_dir( $dir ) ) {
				return;
			}
		}
		if ( is_file( $path ) && (int) filesize( $path ) > self::LOG_MAX_BYTES ) {
			@rename( $path, self::old_log_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- rotating our own log inside the cache dir.
			clearstatcache( true, $path );
		}
		// Printable ASCII only, so nothing a visitor sends can break a line or
		// open a PHP tag in a file that ends in .php.
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $event . ' ' . $detail;
		$line = str_replace( array( '<?', '?>' ), '', (string) preg_replace( '/[^\x20-\x7E]/', '', $line ) );
		$line = substr( $line, 0, 500 ) . "\n";
		if ( ! is_file( $path ) ) {
			$line = self::LOG_GUARD . $line;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- appending to our own log inside the cache dir.
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Where the previous generation of the log lives.
	 *
	 * @return string
	 */
	private static function old_log_path() {
		return substr( Perdita_Caching::log_path(), 0, -8 ) . '.old.log.php';
	}

	/**
	 * The last lines of the debug log, oldest first.
	 *
	 * @param int $lines How many lines.
	 * @return string
	 */
	public static function log_tail( $lines = 200 ) {
		$path = Perdita_Caching::log_path();
		if ( ! is_file( $path ) ) {
			return '';
		}
		$size = (int) filesize( $path );
		$read = min( $size, 65536 );
		$text = (string) file_get_contents( $path, false, null, max( 0, $size - $read ), $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the tail of our own local log.
		$rows = array_values(
			array_filter(
				explode( "\n", $text ),
				static function ( $row ) {
					return '' !== $row && 0 === strpos( $row, '[' );
				}
			)
		);
		return implode( "\n", array_slice( $rows, -1 * max( 1, (int) $lines ) ) );
	}

	/**
	 * Delete the debug log and its older generation.
	 */
	public static function log_clear() {
		foreach ( array( Perdita_Caching::log_path(), self::old_log_path() ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/* ---------- WP_CACHE ---------- */

	/** Who added the line, in Switch's record. */
	const WP_CACHE_OWNER = 'caching-screen';

	/**
	 * Add `define( 'WP_CACHE', true );` to wp-config.php, the way Switch to
	 * Perdita does: never a copy of the file, an undo note in the Switch
	 * backup folder, an atomic write, and the exact line to paste when the
	 * file can't be written safely.
	 *
	 * @return array array{ok: bool, message: string, line: string}
	 */
	public static function wp_cache_add() {
		if ( ! class_exists( 'Perdita_Switch_Leftovers' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Add this line to wp-config.php, above the "That\'s all, stop editing" line.', 'perdita-core' ),
				'line'    => "define( 'WP_CACHE', true );",
			);
		}
		$out         = Perdita_Switch_Leftovers::add_wp_cache_line( self::WP_CACHE_OWNER );
		$out['line'] = $out['ok'] ? '' : Perdita_Switch_Leftovers::WP_CACHE_LINE;
		return $out;
	}

	/**
	 * Take out the line this button added. It stays while early serving is
	 * on, and when another tool or a person added it.
	 *
	 * @return array array{ok: bool, message: string}
	 */
	public static function wp_cache_remove() {
		if ( ! class_exists( 'Perdita_Switch_Leftovers' ) ) {
			return array(
				'ok'      => false,
				'message' => '',
			);
		}
		$message = Perdita_Switch_Leftovers::undo_wp_cache( self::WP_CACHE_OWNER );
		$path    = Perdita_Switch_Leftovers::config_path();
		$body    = '' !== $path && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return array(
			'ok'      => '' !== $message && '' !== $body && false === strpos( $body, Perdita_Switch_Leftovers::WP_CACHE_LINE ),
			'message' => '' !== $message ? $message : __( 'Perdita didn\'t add the WP_CACHE line from this screen, so it stays.', 'perdita-core' ),
		);
	}

	/* ---------- cache folder exposure ---------- */

	/** Runtime state: the folder probe's last result. */
	const PROBE_OPTION = 'perdita_cache_folder_probe';

	/**
	 * Whether a visitor can download files from the cache folder. The
	 * .htaccess guards cover Apache and LiteSpeed. nginx ignores them, and
	 * a signed-in visitor's page (Perdita Pro's logged-in cache) sits in
	 * that folder. This writes a small file there, asks for it over HTTP,
	 * and deletes it.
	 *
	 * @return array array{state: string, time: int} state is safe, exposed or unknown.
	 */
	public static function probe_folder() {
		$dir   = Perdita_Caching::cache_dir();
		$token = wp_generate_password( 24, false );
		$name  = 'perdita-probe-' . strtolower( wp_generate_password( 12, false ) ) . '.html';
		$state = 'unknown';
		if ( wp_mkdir_p( $dir ) && false !== file_put_contents( $dir . $name, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a probe file in our own cache dir, deleted below.
			$uploads = wp_upload_dir( null, false );
			$url     = trailingslashit( (string) $uploads['baseurl'] ) . 'perdita-cache/' . $name;
			$res     = wp_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);
			wp_delete_file( $dir . $name );
			if ( ! is_wp_error( $res ) ) {
				$code  = (int) wp_remote_retrieve_response_code( $res );
				$state = ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $res ), $token ) ) ? 'exposed' : 'safe';
			}
		}
		$out = array(
			'state' => $state,
			'time'  => time(),
		);
		update_option( self::PROBE_OPTION, $out, false );
		return $out;
	}

	/**
	 * The last probe result.
	 *
	 * @return array array{state: string, time: int}
	 */
	public static function last_probe() {
		$probe = get_option( self::PROBE_OPTION, array() );
		return is_array( $probe ) && isset( $probe['state'] ) ? $probe : array(
			'state' => 'unknown',
			'time'  => 0,
		);
	}
}
