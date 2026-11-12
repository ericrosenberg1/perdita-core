<?php
/**
 * Read a PHP settings file another plugin writes (WP Super Cache's
 * wp-content/wp-cache-config.php) without running it.
 *
 * The file is PHP, so including it would run whatever is in it. This reads
 * it with PHP's tokenizer instead and keeps only plain assignments whose
 * right-hand side is a literal:
 *
 *   $name = 'text';            $name = 3600;          $name = true;
 *   $name = array( 'a', 'b' ); $name = [ 'k' => 1 ];  $name[ "feed" ] = 0;
 *
 * Anything else is skipped whole: function calls, constants, concatenation,
 * backticks, include and require, define(), interpolated strings, heredocs,
 * and control structures. Nothing in the file is ever evaluated.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_PHP_Config {

	/**
	 * Largest file read, in bytes.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Deepest array nesting kept.
	 */
	const MAX_DEPTH = 6;

	/**
	 * Tokens after whitespace and comments are dropped.
	 *
	 * @var array
	 */
	private $t = array();

	/**
	 * Read position in $t.
	 *
	 * @var int
	 */
	private $i = 0;

	/**
	 * Parse a file.
	 *
	 * @param string $path File path.
	 * @return array Variable name (no $) => value. Empty when unreadable.
	 */
	public static function parse_file( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			return array();
		}
		$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local settings file, read as text and never run.
		return is_string( $src ) ? self::parse( $src ) : array();
	}

	/**
	 * Parse PHP source.
	 *
	 * @param string $src Source.
	 * @return array
	 */
	public static function parse( $src ) {
		if ( ! is_string( $src ) || strlen( $src ) > self::MAX_BYTES || ! function_exists( 'token_get_all' ) ) {
			return array();
		}
		$p = new self();
		// Alternative syntax (if: ... endif;) and goto hide which statements
		// run, so a file that uses them is left unread.
		$opaque = array( T_ENDIF, T_ENDFOREACH, T_ENDFOR, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE, T_GOTO );
		foreach ( token_get_all( $src ) as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], $opaque, true ) ) {
				return array();
			}
			if ( is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ), true ) ) {
				continue;
			}
			$p->t[] = $tok;
		}
		return $p->statements();
	}

	/**
	 * Walk the statements.
	 *
	 * @return array
	 */
	private function statements() {
		$out    = array();
		$n      = count( $this->t );
		$braces = 0;
		while ( $this->i < $n ) {
			// Only top-level statements count: an assignment inside an if
			// block may never run.
			$tok = $this->peek();
			if ( '{' === $tok || '}' === $tok ) {
				$braces = max( 0, $braces + ( '{' === $tok ? 1 : -1 ) );
				++$this->i;
				continue;
			}
			$start = $this->i;
			$hit   = 0 === $braces ? $this->assignment() : null;
			if ( null !== $hit ) {
				list( $name, $key, $value ) = $hit;
				if ( null === $key ) {
					$out[ $name ] = $value;
				} else {
					if ( ! isset( $out[ $name ] ) || ! is_array( $out[ $name ] ) ) {
						$out[ $name ] = array();
					}
					$out[ $name ][ $key ] = $value;
				}
				continue;
			}
			$this->i = $start;
			$this->skip_statement();
		}
		return $out;
	}

	/**
	 * Try `$name = literal;` or `$name[ literal ] = literal;` at the cursor.
	 *
	 * @return array|null name, key or null, value. Null when it isn't one.
	 */
	private function assignment() {
		$tok = $this->peek();
		if ( ! is_array( $tok ) || T_VARIABLE !== $tok[0] ) {
			return null;
		}
		$name = substr( $tok[1], 1 );
		++$this->i;
		$key = null;
		if ( '[' === $this->peek() ) {
			++$this->i;
			$k = $this->literal( 0 );
			if ( ! $k['ok'] || ! ( is_string( $k['v'] ) || is_int( $k['v'] ) ) || ']' !== $this->peek() ) {
				return null;
			}
			$key = $k['v'];
			++$this->i;
		}
		if ( '=' !== $this->peek() ) {
			return null;
		}
		++$this->i;
		$v = $this->literal( 0 );
		if ( ! $v['ok'] || ';' !== $this->peek() ) {
			return null;
		}
		++$this->i;
		return array( $name, $key, $v['v'] );
	}

	/**
	 * Read one literal at the cursor.
	 *
	 * @param int $depth Array depth.
	 * @return array ok, v.
	 */
	private function literal( $depth ) {
		$no  = array(
			'ok' => false,
			'v'  => null,
		);
		$tok = $this->peek();
		if ( null === $tok ) {
			return $no;
		}
		$neg = false;
		if ( '-' === $tok || '+' === $tok ) {
			$neg = '-' === $tok;
			++$this->i;
			$tok = $this->peek();
			if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_LNUMBER, T_DNUMBER ), true ) ) {
				return $no;
			}
		}
		if ( is_array( $tok ) ) {
			switch ( $tok[0] ) {
				case T_LNUMBER:
					++$this->i;
					$num = intval( str_replace( '_', '', $tok[1] ), 0 );
					return array(
						'ok' => true,
						'v'  => $neg ? -$num : $num,
					);
				case T_DNUMBER:
					++$this->i;
					return array(
						'ok' => true,
						'v'  => $neg ? -(float) str_replace( '_', '', $tok[1] ) : (float) str_replace( '_', '', $tok[1] ),
					);
				case T_CONSTANT_ENCAPSED_STRING:
					++$this->i;
					return array(
						'ok' => true,
						'v'  => self::unquote( $tok[1] ),
					);
				case T_STRING:
					$word = strtolower( $tok[1] );
					if ( in_array( $word, array( 'true', 'false', 'null' ), true ) ) {
						++$this->i;
						return array(
							'ok' => true,
							'v'  => 'null' === $word ? null : 'true' === $word,
						);
					}
					return $no;
				case T_ARRAY:
					++$this->i;
					if ( '(' !== $this->peek() ) {
						return $no;
					}
					++$this->i;
					return $this->elements( ')', $depth );
			}
			return $no;
		}
		if ( '[' === $tok ) {
			++$this->i;
			return $this->elements( ']', $depth );
		}
		return $no;
	}

	/**
	 * Read array elements up to the closing token.
	 *
	 * @param string $close ) or ].
	 * @param int    $depth Depth.
	 * @return array ok, v.
	 */
	private function elements( $close, $depth ) {
		$no = array(
			'ok' => false,
			'v'  => null,
		);
		if ( $depth >= self::MAX_DEPTH ) {
			return $no;
		}
		$arr = array();
		while ( true ) {
			if ( $close === $this->peek() ) {
				++$this->i;
				return array(
					'ok' => true,
					'v'  => $arr,
				);
			}
			$first = $this->literal( $depth + 1 );
			if ( ! $first['ok'] ) {
				return $no;
			}
			$tok = $this->peek();
			if ( is_array( $tok ) && T_DOUBLE_ARROW === $tok[0] ) {
				++$this->i;
				if ( ! ( is_string( $first['v'] ) || is_int( $first['v'] ) ) ) {
					return $no;
				}
				$second = $this->literal( $depth + 1 );
				if ( ! $second['ok'] ) {
					return $no;
				}
				$arr[ $first['v'] ] = $second['v'];
			} else {
				$arr[] = $first['v'];
			}
			if ( ',' === $this->peek() ) {
				++$this->i;
				continue;
			}
			if ( $close !== $this->peek() ) {
				return $no;
			}
		}
	}

	/**
	 * Skip to the end of the statement at the cursor.
	 */
	private function skip_statement() {
		$n     = count( $this->t );
		$depth = 0;
		while ( $this->i < $n ) {
			$tok = $this->t[ $this->i ];
			++$this->i;
			if ( is_array( $tok ) ) {
				continue;
			}
			if ( '(' === $tok || '[' === $tok ) {
				++$depth;
			} elseif ( ')' === $tok || ']' === $tok ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( 0 === $depth && ';' === $tok ) {
				return;
			} elseif ( 0 === $depth && ( '{' === $tok || '}' === $tok ) ) {
				// Leave the brace for statements() to count.
				--$this->i;
				return;
			}
		}
	}

	/**
	 * The token at the cursor, or null.
	 *
	 * @return mixed
	 */
	private function peek() {
		return isset( $this->t[ $this->i ] ) ? $this->t[ $this->i ] : null;
	}

	/**
	 * A quoted string literal's value.
	 *
	 * @param string $raw The literal with its quotes.
	 * @return string
	 */
	private static function unquote( $raw ) {
		$q    = substr( $raw, 0, 1 );
		$body = substr( $raw, 1, -1 );
		if ( "'" === $q ) {
			return (string) preg_replace( "/\\\\([\\\\'])/", '$1', $body );
		}
		return stripcslashes( $body );
	}
}
