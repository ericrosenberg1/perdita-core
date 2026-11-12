<?php
/**
 * What a page cache plugin can leave behind once it's deactivated, and the
 * careful removal of each piece on the owner's say-so.
 *
 * Three things, each only when it provably belongs to that plugin:
 *   - wp-content/advanced-cache.php carrying that plugin's own signature
 *     (never Perdita's drop-in, which carries Perdita_Caching::DROPIN_MARKER).
 *   - a block in .htaccess between that plugin's own BEGIN and END markers.
 *   - WP_CACHE: Perdita's early serving needs it on. When it's off, the
 *     owner gets the one line to add, and Perdita adds it only when
 *     wp-config.php is safely writable (see config_blocker()), at a
 *     top-level spot the tokenizer found, through a temp file renamed over
 *     the original, and only when the result still parses. wp-config.php
 *     is never copied: the backup folder gets a note naming the one line
 *     to delete, so no copy of the database password sits under uploads.
 *
 * Every backup copy goes through Perdita_Switch_Backups into the protected
 * uploads folder, never beside the file it copies.
 *
 * An item may carry 'confirm', the label beside its confirmation box. The
 * screen falls back to a plain line when an item has none.
 *
 * Nothing here runs while the plugin is active.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Leftovers {

	/**
	 * The line Perdita adds to wp-config.php.
	 */
	const WP_CACHE_LINE = "define( 'WP_CACHE', true ); // Added by Perdita, Switch to Perdita.";

	/**
	 * Leftovers for a caching source.
	 *
	 * @param Perdita_Switch_Source $source     Source.
	 * @param string[]              $signatures Text that marks its advanced-cache.php.
	 * @param string[]              $markers    Its .htaccess marker names.
	 * @return array[] id, label, detail, removable (bool), line (manual fix).
	 */
	public static function find( Perdita_Switch_Source $source, array $signatures, array $markers ) {
		$det = $source->detect();
		if ( $det['active'] ) {
			return array();
		}
		$out = array();
		if ( self::dropin_is( $signatures ) ) {
			$out[] = array(
				'id'        => 'dropin',
				'label'     => __( 'wp-content/advanced-cache.php', 'perdita-core' ),
				'detail'    => self::dropin_detail( $source->label(), defined( 'WP_CACHE' ) && WP_CACHE ),
				'removable' => wp_is_writable( dirname( self::dropin_path() ) ),
				'line'      => '',
				/* translators: %s: backup folder, such as wp-content/uploads/perdita-switch-backups/. */
				'confirm'   => sprintf( __( 'Delete the file. You\'ll get a backup copy in %s first.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ),
			);
		}
		$htaccess = self::htaccess_path();
		$body     = '' !== $htaccess && is_readable( $htaccess ) ? (string) file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		foreach ( $markers as $marker ) {
			if ( '' !== $body && preg_match( '/^[ \t]*# BEGIN ' . preg_quote( (string) $marker, '/' ) . '[ \t]*$/m', $body ) ) {
				$out[] = array(
					'id'        => 'htaccess:' . $marker,
					/* translators: %s: marker name. */
					'label'     => sprintf( __( '.htaccess block "%s"', 'perdita-core' ), $marker ),
					/* translators: %s: plugin name. */
					'detail'    => sprintf( __( 'Rules from %s. While they stay in .htaccess, visitors can keep getting that plugin\'s old cached files.', 'perdita-core' ), $source->label() ),
					'removable' => wp_is_writable( $htaccess ),
					'line'      => '',
					/* translators: %s: backup folder, such as wp-content/uploads/perdita-switch-backups/. */
					'confirm'   => sprintf( __( 'Remove the block. You\'ll get a backup copy of .htaccess in %s first.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ),
				);
			}
		}
		$wp_cache = self::wp_cache_item();
		if ( $wp_cache ) {
			$out[] = $wp_cache;
		}
		return $out;
	}

	/**
	 * What the card says about another plugin's advanced-cache.php. WordPress
	 * includes the drop-in only while WP_CACHE is true, so the text says
	 * what's true in each state.
	 *
	 * @param string $label    Plugin name.
	 * @param bool   $wp_cache Whether WP_CACHE is on.
	 * @return string
	 */
	public static function dropin_detail( $label, $wp_cache ) {
		if ( $wp_cache ) {
			/* translators: %s: plugin name. */
			return sprintf( __( 'This file belongs to %s. WP_CACHE is on, so WordPress loads the file on every request. Once you remove the file, you can serve cached pages with Perdita\'s drop-in before WordPress loads.', 'perdita-core' ), $label );
		}
		/* translators: %s: plugin name. */
		return sprintf( __( 'This file belongs to %s. WP_CACHE is off, so WordPress skips the file for now, but WordPress loads the file on every request again once you set WP_CACHE to true. Remove the file before you set WP_CACHE to true.', 'perdita-core' ), $label );
	}

	/**
	 * The WP_CACHE item, when Perdita's early serving wants it and it's off.
	 *
	 * @return array|null
	 */
	public static function wp_cache_item() {
		if ( ! Perdita_Switch_Target::load( 'caching' ) || Perdita_Caching::wp_cache_on() ) {
			return null;
		}
		$s = Perdita_Caching::settings();
		if ( empty( $s['enabled'] ) || empty( $s['early_serve'] ) ) {
			return null;
		}
		$why    = self::config_blocker( self::config_path() );
		$detail = __( 'WP_CACHE is off, so you get no early serving. Add this line to wp-config.php above the "That\'s all, stop editing" line.', 'perdita-core' );
		return array(
			'id'        => 'wp_cache',
			'label'     => __( 'WP_CACHE in wp-config.php', 'perdita-core' ),
			'detail'    => '' === $why ? $detail : $detail . ' ' . $why,
			'removable' => '' === $why,
			'line'      => "define( 'WP_CACHE', true );",
			/* translators: %s: backup folder, such as wp-content/uploads/perdita-switch-backups/. */
			'confirm'   => sprintf( __( 'Add the line for me. In %s, you\'ll get a note with the one line to delete if you want to undo the change.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ),
		);
	}

	/**
	 * Remove (or, for WP_CACHE, add) one item, after checking again that it
	 * is what find() said it was.
	 *
	 * @param Perdita_Switch_Source $source     Source.
	 * @param string                $item       Item id.
	 * @param string[]              $signatures Drop-in signatures.
	 * @param string[]              $markers    .htaccess markers.
	 * @return array ok, message.
	 */
	public static function fix( Perdita_Switch_Source $source, $item, array $signatures, array $markers ) {
		$no  = function ( $msg ) {
			return array(
				'ok'      => false,
				'message' => $msg,
			);
		};
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return $no( __( 'You\'ll need to make this change yourself, since file changes from plugins are turned off on this site.', 'perdita-core' ) );
		}
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return $no( __( 'These files apply to every site on this network, so a network admin needs to make this change.', 'perdita-core' ) );
		}
		$det = $source->detect();
		if ( $det['active'] ) {
			/* translators: %s: plugin name. */
			return $no( sprintf( __( '%s is active again, so its files are unchanged.', 'perdita-core' ), $source->label() ) );
		}
		if ( 'dropin' === $item ) {
			if ( ! self::dropin_is( $signatures ) ) {
				return $no( __( 'The current advanced-cache.php isn\'t that plugin\'s file anymore, so the file is unchanged.', 'perdita-core' ) );
			}
			$other = Perdita_Switch_Target::load( 'caching' ) ? Perdita_Caching::other_page_cache() : '';
			if ( '' !== $other ) {
				/* translators: %s: plugin name. */
				return $no( sprintf( __( 'You have %1$s active, so we left advanced-cache.php in place in case %1$s needs the file.', 'perdita-core' ), $other ) );
			}
			$backup = Perdita_Switch_Backups::save_file( self::dropin_path(), 'advanced-cache' );
			if ( '' === $backup ) {
				/* translators: %s: backup folder. */
				return $no( sprintf( __( 'The backup copy couldn\'t be saved in %s, so advanced-cache.php is unchanged. Check that the uploads folder is writable.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ) );
			}
			wp_delete_file( self::dropin_path() );
			if ( is_file( self::dropin_path() ) ) {
				return $no( __( 'wp-content/advanced-cache.php is still in place. Check that wp-content is writable, then try again.', 'perdita-core' ) );
			}
			if ( Perdita_Switch_Target::load( 'caching' ) ) {
				Perdita_Caching::sync_dropin();
			}
			return array(
				'ok'      => true,
				/* translators: %s: backup file name. */
				'message' => sprintf( __( 'Removed the old advanced-cache.php. You\'ll find a copy under Backup copies as %s.', 'perdita-core' ), $backup ),
			);
		}
		if ( 0 === strpos( (string) $item, 'htaccess:' ) ) {
			$marker = substr( (string) $item, 9 );
			if ( ! in_array( $marker, $markers, true ) ) {
				return $no( __( 'That block doesn\'t belong to this plugin.', 'perdita-core' ) );
			}
			return self::remove_htaccess_block( $marker );
		}
		if ( 'wp_cache' === $item ) {
			if ( Perdita_Switch_Target::load( 'caching' ) && Perdita_Caching::wp_cache_on() ) {
				Perdita_Caching::sync_dropin();
				return array(
					'ok'      => true,
					'message' => __( 'WP_CACHE is already on in wp-config.php, so the file is unchanged.', 'perdita-core' ),
				);
			}
			return self::add_wp_cache();
		}
		return $no( __( 'That item isn\'t on this card.', 'perdita-core' ) );
	}

	/**
	 * Whether advanced-cache.php carries one of these signatures and not
	 * Perdita's marker.
	 *
	 * @param string[] $signatures Signatures.
	 * @return bool
	 */
	public static function dropin_is( array $signatures ) {
		$path = self::dropin_path();
		if ( ! $signatures || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( Perdita_Switch_Target::load( 'caching' ) && false !== strpos( $head, Perdita_Caching::DROPIN_MARKER ) ) {
			return false;
		}
		foreach ( $signatures as $sig ) {
			if ( '' !== $sig && false !== stripos( $head, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string wp-content/advanced-cache.php.
	 */
	public static function dropin_path() {
		return trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
	}

	/**
	 * The site's .htaccess path, or ''.
	 *
	 * @return string
	 */
	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		/**
		 * Filters the .htaccess file Switch to Perdita reads and edits.
		 *
		 * @param string $path Path.
		 */
		$path = (string) apply_filters( 'perdita_switch_htaccess_path', trailingslashit( get_home_path() ) . '.htaccess' );
		return '' !== $path && is_file( $path ) ? $path : '';
	}

	/**
	 * The wp-config.php WordPress loaded, or ''.
	 *
	 * @return string
	 */
	public static function config_path() {
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			$path = ABSPATH . 'wp-config.php';
		} else {
			$up   = dirname( ABSPATH ) . '/wp-config.php';
			$path = is_file( $up ) && ! is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ? $up : '';
		}
		/**
		 * Filters the wp-config.php Switch to Perdita reads and edits. The
		 * tests point it at a copy.
		 *
		 * @param string $path Path, or '' when WordPress's lookup finds none.
		 */
		return (string) apply_filters( 'perdita_switch_wp_config_path', $path );
	}


	/* ---------- wp-config.php ---------- */

	/**
	 * Why Perdita can't add the WP_CACHE line itself, or '' when it can.
	 * Each answer is a sentence for the card, and the card always shows the
	 * line to add by hand.
	 *
	 * @param string $path wp-config.php.
	 * @return string
	 */
	public static function config_blocker( $path ) {
		if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return __( 'This site has file changes from plugins turned off, so add the line yourself.', 'perdita-core' );
		}
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return __( 'wp-config.php applies to every site on this network, so a network admin needs to add the line.', 'perdita-core' );
		}
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return __( 'We couldn\'t find wp-config.php, so add the line yourself.', 'perdita-core' );
		}
		if ( is_link( $path ) || ! wp_is_writable( $path ) || ! wp_is_writable( dirname( $path ) ) ) {
			return __( 'We can\'t safely write wp-config.php on this server, so add the line yourself.', 'perdita-core' );
		}
		if ( ! self::same_owner_as_php( $path ) ) {
			return __( 'wp-config.php belongs to another user on this server, so add the line yourself.', 'perdita-core' );
		}
		foreach ( self::config_files( $path ) as $file ) {
			if ( self::php_defines_wp_cache( (string) file_get_contents( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read as text.
				return __( 'You already set WP_CACHE in your configuration, so change that line to true yourself.', 'perdita-core' );
			}
		}
		if ( null === self::config_with_wp_cache( (string) file_get_contents( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read as text.
			return __( 'We couldn\'t find a safe spot for the line in wp-config.php, so add the line yourself.', 'perdita-core' );
		}
		return '';
	}

	/**
	 * wp-config.php and any file loaded before WordPress from its folder or
	 * the folder above it (a wp-config-local.php, say), so a WP_CACHE set
	 * in either one counts.
	 *
	 * @param string $path wp-config.php.
	 * @return string[]
	 */
	private static function config_files( $path ) {
		$out  = array( $path );
		$dirs = array( wp_normalize_path( dirname( $path ) ), wp_normalize_path( dirname( dirname( $path ) ) ), untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		$core = array( 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'index.php', 'wp-config.php', 'wp-cron.php', 'wp-login.php', 'xmlrpc.php', 'wp-activate.php', 'wp-signup.php', 'wp-trackback.php', 'wp-comments-post.php', 'wp-mail.php', 'wp-links-opml.php' );
		foreach ( get_included_files() as $file ) {
			$norm = wp_normalize_path( $file );
			if ( in_array( wp_normalize_path( dirname( $norm ) ), $dirs, true ) && ! in_array( basename( $norm ), $core, true ) && is_readable( $file ) && filesize( $file ) < 1048576 ) {
				$out[] = $file;
			}
		}
		return array_unique( $out );
	}

	/**
	 * Whether PHP code defines WP_CACHE: define( 'WP_CACHE', ... ) or
	 * const WP_CACHE, in code (a commented-out line doesn't count). A file
	 * that won't tokenize counts as yes, so Perdita leaves it alone.
	 *
	 * @param string $src PHP source.
	 * @return bool
	 */
	public static function php_defines_wp_cache( $src ) {
		try {
			$tokens = token_get_all( (string) $src );
		} catch ( \Throwable $e ) {
			return true;
		}
		$code = array();
		foreach ( $tokens as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code[] = $tok;
		}
		foreach ( $code as $i => $tok ) {
			if ( ! is_array( $tok ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $tok[0] && 'WP_CACHE' === trim( $tok[1], '\'"' ) ) {
				return true;
			}
			if ( T_STRING === $tok[0] && 'WP_CACHE' === $tok[1] && isset( $code[ $i - 1 ] ) && is_array( $code[ $i - 1 ] ) && T_CONST === $code[ $i - 1 ][0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * wp-config.php's source with the WP_CACHE line added, or null when
	 * there's no safe place for it.
	 *
	 * The line goes right above the first top-level statement among the
	 * "That's all, stop editing" comment, the "Add any custom values"
	 * comment, and the require of wp-settings.php. Top level means outside
	 * any braces or alternative-syntax block, at the start of a statement.
	 * Text that only looks like one of those (inside a string or another
	 * comment) never counts. The original must tokenize, and so must the
	 * result, and the result must differ from the original by exactly the
	 * line. Line endings follow the file's own, and a byte order mark or
	 * anything else before the opening tag stays as it is.
	 *
	 * @param string $body Original source.
	 * @return string|null
	 */
	public static function config_with_wp_cache( $body ) {
		$body = (string) $body;
		if ( '' === $body || ! function_exists( 'token_get_all' ) || self::php_defines_wp_cache( $body ) ) {
			return null;
		}
		try {
			$tokens = token_get_all( $body, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return null;
		}
		$alt   = array( T_ENDIF, T_ENDFOREACH, T_ENDFOR, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE );
		$opens = array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES );
		foreach ( $tokens as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], $alt, true ) ) {
				return null;
			}
		}
		$offset  = 0;
		$depth   = 0;
		$prev    = null; // Last token that isn't whitespace or a comment.
		$at      = null;
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$tok  = $tokens[ $i ];
			$text = is_array( $tok ) ? $tok[1] : $tok;
			$id   = is_array( $tok ) ? $tok[0] : null;
			$start_ok = 0 === $depth && ( null === $prev || ';' === $prev || '}' === $prev || ( is_array( $prev ) && in_array( $prev[0], array( T_OPEN_TAG ), true ) ) );
			if ( $start_ok && in_array( $id, array( T_COMMENT, T_DOC_COMMENT ), true ) && ( false !== strpos( $text, "That's all, stop editing" ) || false !== strpos( $text, 'Add any custom values between this line' ) ) ) {
				$at = $offset;
				break;
			}
			if ( $start_ok && in_array( $id, array( T_REQUIRE_ONCE, T_REQUIRE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
				for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] && false !== strpos( $tokens[ $j ][1], 'wp-settings.php' ) ) {
						$at = $offset;
						break 2;
					}
				}
			}
			if ( '{' === $tok || in_array( $id, $opens, true ) ) {
				++$depth;
			} elseif ( '}' === $tok ) {
				--$depth;
			}
			if ( ! in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$prev = $tok;
			}
			$offset += strlen( $text );
		}
		if ( null === $at ) {
			return null;
		}
		$eol        = false !== strpos( $body, "\r\n" ) ? "\r\n" : "\n";
		$line_start = $at;
		while ( $line_start > 0 && ( ' ' === $body[ $line_start - 1 ] || "\t" === $body[ $line_start - 1 ] ) ) {
			--$line_start;
		}
		$fresh_line = 0 === $line_start || "\n" === $body[ $line_start - 1 ] || "\r" === $body[ $line_start - 1 ];
		$insert     = $fresh_line ? self::WP_CACHE_LINE . $eol : $eol . self::WP_CACHE_LINE . $eol;
		$pos        = $fresh_line ? $line_start : $at;
		$new        = substr( $body, 0, $pos ) . $insert . substr( $body, $pos );
		try {
			token_get_all( $new, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( strlen( $new ) !== strlen( $body ) + strlen( $insert ) || substr( $new, 0, $pos ) . substr( $new, $pos + strlen( $insert ) ) !== $body ) {
			return null;
		}
		return $new;
	}

	/**
	 * Whether the file belongs to the user PHP runs as, so a rename keeps
	 * its owner. True where PHP can't tell.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	private static function same_owner_as_php( $path ) {
		if ( ! function_exists( 'posix_geteuid' ) || ! function_exists( 'fileowner' ) ) {
			return true;
		}
		$owner = fileowner( $path );
		return false === $owner || (int) $owner === (int) posix_geteuid();
	}

	/**
	 * Add the WP_CACHE line. wp-config.php is never copied: the note in the
	 * backup folder names the one line to delete, so no copy of the
	 * database password or the salts ever sits under uploads.
	 *
	 * @return array ok, message.
	 */
	private static function add_wp_cache() {
		$path = self::config_path();
		$why  = self::config_blocker( $path );
		$no   = function ( $why ) {
			return array(
				'ok'      => false,
				/* translators: %s: the reason. */
				'message' => sprintf( __( 'wp-config.php is unchanged. %s Put the WP_CACHE line from this card above the "That\'s all, stop editing" line.', 'perdita-core' ), $why ),
			);
		};
		if ( '' !== $why ) {
			return $no( $why );
		}
		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$new  = self::config_with_wp_cache( $body );
		if ( null === $new ) {
			return $no( __( 'We couldn\'t find a safe spot for the line.', 'perdita-core' ) );
		}
		$note = self::save_note(
			'wp-config-line',
			array(
				'Perdita added this line to wp-config.php on ' . gmdate( 'Y-m-d H:i' ) . ' UTC.',
				'To undo the change, delete that one line:',
				'',
				self::WP_CACHE_LINE,
				'',
			)
		);
		if ( '' === $note ) {
			/* translators: %s: backup folder. */
			return $no( sprintf( __( 'We couldn\'t save the undo note in %s.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ) );
		}
		if ( ! self::write_atomic( $path, $new, $body ) ) {
			return $no( __( 'We couldn\'t save the file in one piece.', 'perdita-core' ) );
		}
		return array(
			'ok'      => true,
			/* translators: %s: note file name. */
			'message' => sprintf( __( 'Added WP_CACHE to wp-config.php. To undo it, delete the line that ends "Added by Perdita". You\'ll find the same steps under Backup copies as %s.', 'perdita-core' ), $note ),
		);
	}

	/**
	 * A short text note in the backup folder.
	 *
	 * @param string   $kind  Slug.
	 * @param string[] $lines Lines.
	 * @return string Name, or ''.
	 */
	private static function save_note( $kind, array $lines ) {
		$tmp = wp_tempnam( 'perdita-note' );
		if ( ! $tmp ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file, copied then removed.
		file_put_contents( $tmp, implode( "\n", $lines ) );
		$name = Perdita_Switch_Backups::save_file( $tmp, $kind );
		wp_delete_file( $tmp );
		return $name;
	}

	/* ---------- .htaccess ---------- */

	/**
	 * $body with one "# BEGIN marker" ... "# END marker" block taken out,
	 * byte for byte otherwise (line endings included), or null when the
	 * block isn't there or isn't safe to cut: a missing END, a BEGIN of the
	 * same marker inside the block, or another block's BEGIN or END line
	 * between the two markers, which would mean cutting into rules that
	 * aren't this plugin's. Every complete copy of the block goes.
	 *
	 * Pro's file helpers can call this too.
	 *
	 * @param string $body   File contents.
	 * @param string $marker Marker name.
	 * @return string|null
	 */
	public static function strip_block( $body, $marker ) {
		$marker = (string) $marker;
		if ( '' === $marker ) {
			return null;
		}
		$parts = preg_split( '/(\r\n|\n|\r)/', (string) $body, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$in    = false;
		$found = false;
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$line = $parts[ $i ];
			$eol  = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';
			$t    = trim( $line );
			if ( '# BEGIN ' . $marker === $t ) {
				if ( $in ) {
					return null;
				}
				$in    = true;
				$found = true;
				continue;
			}
			if ( $in && '# END ' . $marker === $t ) {
				$in = false;
				continue;
			}
			if ( $in && preg_match( '/^#\s*(BEGIN|END)\s+\S/', $t ) ) {
				return null;
			}
			if ( ! $in ) {
				$out .= $line . $eol;
			}
		}
		return ( $found && ! $in ) ? $out : null;
	}

	/**
	 * Take one marker block out of .htaccess, after a backup copy.
	 *
	 * @param string $marker Marker name.
	 * @return array ok, message.
	 */
	private static function remove_htaccess_block( $marker ) {
		$path = self::htaccess_path();
		if ( '' === $path || is_link( $path ) || ! wp_is_writable( $path ) || ! wp_is_writable( dirname( $path ) ) || ! self::same_owner_as_php( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'You\'ll need to remove the block by hand, since we can\'t safely write .htaccess on this server.', 'perdita-core' ),
			);
		}
		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$new  = self::strip_block( $body, $marker );
		if ( null === $new ) {
			return array(
				'ok'      => false,
				'message' => __( 'The block is missing a marker or overlaps another block, so .htaccess is unchanged. Remove the block by hand.', 'perdita-core' ),
			);
		}
		// Only the removed block is kept. A whole .htaccess can hold paths to
		// password files or tokens in rewrite rules, and the backup folder sits
		// under uploads.
		$backup = self::save_note( 'htaccess-block', self::block_lines( $body, $marker ) );
		if ( '' === $backup ) {
			return array(
				'ok'      => false,
				/* translators: %s: backup folder. */
				'message' => sprintf( __( 'The backup copy couldn\'t be saved in %s, so .htaccess is unchanged. Check that the uploads folder is writable.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ),
			);
		}
		if ( ! self::write_atomic( $path, $new, $body ) ) {
			return array(
				'ok'      => false,
				'message' => __( '.htaccess couldn\'t be saved, so the file is unchanged.', 'perdita-core' ),
			);
		}
		return array(
			'ok'      => true,
			/* translators: 1: marker name, 2: backup file name. */
			'message' => sprintf( __( 'Removed the "%1$s" block from .htaccess. You\'ll find the removed block under Backup copies as %2$s.', 'perdita-core' ), $marker, $backup ),
		);
	}

	/**
	 * The lines of every "# BEGIN marker" ... "# END marker" block in $body,
	 * markers included. Call it only after strip_block() accepted the body.
	 *
	 * @param string $body   File contents.
	 * @param string $marker Marker name.
	 * @return string[]
	 */
	private static function block_lines( $body, $marker ) {
		$out    = array();
		$inside = false;
		foreach ( preg_split( '/\\r\\n|\\n|\\r/', (string) $body ) as $line ) {
			$trim = trim( $line );
			if ( '# BEGIN ' . $marker === $trim ) {
				$inside = true;
			}
			if ( $inside ) {
				$out[] = $line;
			}
			if ( '# END ' . $marker === $trim ) {
				$inside = false;
			}
		}
		return $out;
	}

	/* ---------- writing ---------- */

	/**
	 * Replace a file's contents in one step: write a temp file in the same
	 * folder, check its bytes, give it the original's permissions, and
	 * rename it over the original. A crash, a full disk, or a failed write
	 * leaves the original as it was, never a cut-off file. Then read the
	 * file back, and put the original back if it isn't what was written.
	 *
	 * For a PHP file, the new contents must tokenize first.
	 *
	 * @param string $path     File.
	 * @param string $new      New contents.
	 * @param string $expected What the file holds now. When it changed in
	 *                         the meantime, nothing is written.
	 * @return bool
	 */
	public static function write_atomic( $path, $new, $expected ) {
		$path = (string) $path;
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		if ( '.php' === substr( $path, -4 ) ) {
			try {
				token_get_all( (string) $new, TOKEN_PARSE );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		clearstatcache( true, $path );
		if ( (string) file_get_contents( $path ) !== (string) $expected ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			return false;
		}
		$perms = fileperms( $path ) & 0777;
		$tmp   = dirname( $path ) . '/.' . basename( $path ) . '.perdita-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		// A folder that refuses the temp file is an answer, not an error.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a temp file beside the original, renamed over it.
		$written = @file_put_contents( $tmp, (string) $new, LOCK_EX );
		clearstatcache( true, $tmp );
		if ( strlen( (string) $new ) !== $written || ! is_file( $tmp ) || (string) file_get_contents( $tmp ) !== (string) $new ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our temp file.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return false;
		}
		chmod( $tmp, $perms ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- keep the original's permissions.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- an atomic swap in the same folder.
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		clearstatcache( true, $path );
		if ( (string) file_get_contents( $path ) !== (string) $new ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- put the original back.
			file_put_contents( $path, (string) $expected, LOCK_EX );
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
			opcache_invalidate( $path, true );
		}
		return true;
	}
}
