<?php
/**
 * Title and description template variables. resolve() turns a template such
 * as "%title% %sep% %sitename%" into text for the current view, and the
 * perdita_seo_variables filter lets another plugin add tokens of its own.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves %token% placeholders in title and description templates.
 */
class Perdita_SEO_Variables {

	/**
	 * Longest a %excerpt% or %term_description% gets, in characters.
	 */
	const EXCERPT_LENGTH = 155;

	/**
	 * Resolve every %token% in a template for the given view.
	 *
	 * $ctx is the array Perdita_SEO::context() builds: type (singular, front,
	 * home, archive, search, 404), post, term, post_type. The title path adds
	 * an optional 'title' key carrying the title WordPress itself computed for
	 * the view, so %title% matches core exactly for 404s, search, and dates.
	 * Unknown tokens are left as typed. A token that resolves empty (a
	 * %page% on page one, a %category% on a post with none) is dropped along
	 * with the separator it would have doubled.
	 *
	 * @param string $template Template with %token% placeholders.
	 * @param array  $ctx      View context.
	 * @return string
	 */
	public static function resolve( string $template, array $ctx ): string {
		$sep = self::separator();
		if ( '' === $template || false === strpos( $template, '%' ) ) {
			return self::tidy( $template, $sep );
		}
		$vars = self::variables( $ctx );
		$out  = preg_replace_callback(
			'/%([a-z0-9_]+)%/i',
			function ( $m ) use ( $vars, $ctx ) {
				$key = strtolower( $m[1] );
				if ( ! array_key_exists( $key, $vars ) ) {
					return $m[0];
				}
				$value = $vars[ $key ];
				if ( is_callable( $value ) && ! is_string( $value ) ) {
					$value = call_user_func( $value, $ctx );
				}
				return (string) $value;
			},
			$template
		);
		return self::tidy( (string) $out, $sep );
	}

	/**
	 * The variable table for a view: token => string or callable( $ctx ).
	 * Callables are only invoked for tokens the template actually uses, so a
	 * template with no %excerpt% never strips a post body.
	 *
	 * @param array $ctx View context.
	 * @return array
	 */
	public static function variables( array $ctx ) {
		$vars = array(
			'title'              => array( __CLASS__, 'title' ),
			'sitename'           => get_bloginfo( 'name' ),
			'sep'                => self::separator(),
			'tagline'            => get_bloginfo( 'description' ),
			'excerpt'            => array( __CLASS__, 'excerpt' ),
			'category'           => array( __CLASS__, 'category' ),
			'tag'                => array( __CLASS__, 'tag' ),
			'author'             => array( __CLASS__, 'author' ),
			'currentyear'        => wp_date( 'Y' ),
			'currentmonth'       => wp_date( 'F' ),
			'currentdate'        => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
			'archive_title'      => array( __CLASS__, 'archive_title' ),
			'term_title'         => array( __CLASS__, 'term_title' ),
			'term_description'   => array( __CLASS__, 'term_description' ),
			'post_type_singular' => array( __CLASS__, 'post_type_singular' ),
			'post_type_plural'   => array( __CLASS__, 'post_type_plural' ),
			'page'               => array( __CLASS__, 'page' ),
			'search_term'        => get_search_query(),
		);

		/**
		 * Add or replace template variables. Keys are the token names without
		 * the percent signs. A value may be a string or a callable that takes
		 * the view context and returns a string.
		 *
		 * @param array $vars Token => string|callable.
		 * @param array $ctx  View context from Perdita_SEO::context().
		 */
		return (array) apply_filters( 'perdita_seo_variables', $vars, $ctx );
	}

	/**
	 * Every token name this class resolves, for the admin help text.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array( 'title', 'sitename', 'sep', 'tagline', 'excerpt', 'category', 'tag', 'author', 'currentyear', 'currentmonth', 'currentdate', 'archive_title', 'term_title', 'term_description', 'post_type_singular', 'post_type_plural', 'page', 'search_term' );
	}

	/* ---------- token resolvers ---------- */

	/**
	 * %title%: the core-computed title when the caller supplied it, else the
	 * natural title of whatever the view is about.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function title( array $ctx ) {
		if ( isset( $ctx['title'] ) && '' !== (string) $ctx['title'] ) {
			return (string) $ctx['title'];
		}
		$type = isset( $ctx['type'] ) ? $ctx['type'] : '';
		if ( ! empty( $ctx['post'] ) && $ctx['post'] instanceof WP_Post && in_array( $type, array( 'singular', 'home' ), true ) ) {
			return (string) get_the_title( $ctx['post'] );
		}
		if ( ! empty( $ctx['term'] ) && $ctx['term'] instanceof WP_Term ) {
			return (string) $ctx['term']->name;
		}
		if ( '404' === $type ) {
			return __( 'Page not found', 'perdita-core' );
		}
		if ( 'search' === $type ) {
			/* translators: %s: search query. */
			return sprintf( __( 'Search results for "%s"', 'perdita-core' ), get_search_query() );
		}
		if ( 'archive' === $type ) {
			return self::archive_title( $ctx );
		}
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * %excerpt%: the manual excerpt, else the first sentence-ish of the body,
	 * no HTML, capped at EXCERPT_LENGTH characters on a word boundary.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function excerpt( array $ctx ) {
		$post = self::post( $ctx );
		if ( ! $post ) {
			return '';
		}
		$text = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		return self::trim_chars( self::plain( (string) $text ), self::EXCERPT_LENGTH );
	}

	/**
	 * %category%: the primary term (perdita_seo_primary_term filter) or the
	 * first one. Uses the category taxonomy when the post has it, else the
	 * first hierarchical public taxonomy attached to the post type.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function category( array $ctx ) {
		$term = self::primary_term( self::post( $ctx ) );
		return $term ? (string) $term->name : '';
	}

	/**
	 * %tag%: the post's tags, comma separated.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function tag( array $ctx ) {
		$post = self::post( $ctx );
		if ( ! $post ) {
			return '';
		}
		$names = self::term_names( $post, 'post_tag' );
		return implode( ', ', $names );
	}

	/**
	 * %author%: the post author, or the author being viewed on an author
	 * archive.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function author( array $ctx ) {
		$post = self::post( $ctx );
		if ( $post ) {
			return (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		}
		if ( is_author() ) {
			$user = get_queried_object();
			return $user instanceof WP_User ? (string) $user->display_name : '';
		}
		return '';
	}

	/**
	 * %archive_title%: a plain archive name with no "Category:" prefix and no
	 * markup. Dates, authors, terms, and post type archives are all covered.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function archive_title( array $ctx ) {
		if ( ! empty( $ctx['term'] ) && $ctx['term'] instanceof WP_Term ) {
			return (string) $ctx['term']->name;
		}
		if ( is_author() ) {
			$user = get_queried_object();
			return $user instanceof WP_User ? (string) $user->display_name : '';
		}
		if ( is_year() ) {
			return (string) get_the_date( _x( 'Y', 'yearly archives date format', 'perdita-core' ) );
		}
		if ( is_month() ) {
			return (string) get_the_date( _x( 'F Y', 'monthly archives date format', 'perdita-core' ) );
		}
		if ( is_day() ) {
			return (string) get_the_date( _x( 'F j, Y', 'daily archives date format', 'perdita-core' ) );
		}
		if ( is_post_type_archive() ) {
			return (string) post_type_archive_title( '', false );
		}
		if ( ! empty( $ctx['post_type'] ) ) {
			$obj = get_post_type_object( (string) $ctx['post_type'] );
			return $obj ? (string) $obj->labels->name : '';
		}
		return '';
	}

	/**
	 * %term_title%: the term name on a term archive.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function term_title( array $ctx ) {
		$term = self::term( $ctx );
		return $term ? (string) $term->name : '';
	}

	/**
	 * %term_description%: the term description, plain and capped.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function term_description( array $ctx ) {
		$term = self::term( $ctx );
		return $term ? self::trim_chars( self::plain( (string) $term->description ), self::EXCERPT_LENGTH ) : '';
	}

	/**
	 * %post_type_singular%: the post type's singular label.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function post_type_singular( array $ctx ) {
		$obj = self::post_type_object( $ctx );
		return $obj ? (string) $obj->labels->singular_name : '';
	}

	/**
	 * %post_type_plural%: the post type's plural label.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function post_type_plural( array $ctx ) {
		$obj = self::post_type_object( $ctx );
		return $obj ? (string) $obj->labels->name : '';
	}

	/**
	 * %page%: "Page N" past page one, else nothing. Covers both list
	 * pagination (paged) and a post split with nextpage (page).
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	public static function page( array $ctx ) {
		unset( $ctx );
		$n = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( $n < 2 ) {
			return '';
		}
		/* translators: %s: page number. */
		return sprintf( __( 'Page %s', 'perdita-core' ), number_format_i18n( $n ) );
	}

	/* ---------- shared helpers ---------- */

	/**
	 * The primary term for a post. Pro (or anything else) can pick one via
	 * the perdita_seo_primary_term filter, else it is the first term of the
	 * category taxonomy, else the first term of the first hierarchical public
	 * taxonomy the post type has.
	 *
	 * @param WP_Post|null $post Post.
	 * @return WP_Term|null
	 */
	public static function primary_term( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$taxonomy = 'category';
		if ( ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$taxonomy = '';
			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
				if ( $tax->public && $tax->hierarchical ) {
					$taxonomy = $tax->name;
					break;
				}
			}
		}
		$first = null;
		if ( '' !== $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				$first = reset( $terms );
			}
		}

		/**
		 * Pick the primary term for a post. Return a WP_Term to use it, or
		 * null to fall back to the first assigned term.
		 *
		 * @param WP_Term|null $first    First assigned term, or null.
		 * @param WP_Post      $post     Post.
		 * @param string       $taxonomy Taxonomy consulted.
		 */
		$term = apply_filters( 'perdita_seo_primary_term', $first, $post, $taxonomy );
		return $term instanceof WP_Term ? $term : $first;
	}

	/**
	 * Names of a post's terms in one taxonomy.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy.
	 * @return string[]
	 */
	public static function term_names( WP_Post $post, $taxonomy ) {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'strval', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * Strip tags, shortcodes, and block comments, then collapse whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = strip_shortcodes( (string) $text );
		$text = preg_replace( '/<!--(.*?)-->/s', '', $text );
		$text = wp_strip_all_tags( (string) $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Cut text to a character budget on a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	public static function trim_chars( $text, $max ) {
		$text = trim( (string) $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > (int) ( $max * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, ' ,.;:-' );
	}

	/**
	 * Collapse doubled separators left by empty tokens, and trim a separator
	 * off either end.
	 *
	 * @param string $text Resolved text.
	 * @param string $sep  Separator.
	 * @return string
	 */
	private static function tidy( $text, $sep ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		$sep  = trim( (string) $sep );
		if ( '' === $sep || '' === $text ) {
			return $text;
		}
		$q    = preg_quote( $sep, '/' );
		$text = (string) preg_replace( '/(?:\s*' . $q . '\s*){2,}/u', ' ' . $sep . ' ', $text );
		$text = (string) preg_replace( '/^\s*' . $q . '\s*/u', '', $text );
		$text = (string) preg_replace( '/\s*' . $q . '\s*$/u', '', $text );
		return trim( $text );
	}

	/**
	 * The separator from the SEO store, with a plain dash when the store is
	 * not reachable (a resolve() call before Perdita Core has booted).
	 *
	 * @return string
	 */
	private static function separator() {
		if ( function_exists( 'perdita_core' ) && perdita_core() && isset( perdita_core()->seo ) && perdita_core()->seo instanceof Perdita_SEO_Store ) {
			return (string) perdita_core()->seo->get( 'separator', '-' );
		}
		return '-';
	}

	/**
	 * The post a view is about: the context's post, else the queried one on
	 * a singular view.
	 *
	 * @param array $ctx View context.
	 * @return WP_Post|null
	 */
	private static function post( array $ctx ) {
		if ( ! empty( $ctx['post'] ) && $ctx['post'] instanceof WP_Post ) {
			return $ctx['post'];
		}
		if ( is_singular() ) {
			$obj = get_queried_object();
			return $obj instanceof WP_Post ? $obj : null;
		}
		return null;
	}

	/**
	 * The term a view is about.
	 *
	 * @param array $ctx View context.
	 * @return WP_Term|null
	 */
	private static function term( array $ctx ) {
		if ( ! empty( $ctx['term'] ) && $ctx['term'] instanceof WP_Term ) {
			return $ctx['term'];
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			return $obj instanceof WP_Term ? $obj : null;
		}
		return null;
	}

	/**
	 * The post type object a view is about.
	 *
	 * @param array $ctx View context.
	 * @return WP_Post_Type|null
	 */
	private static function post_type_object( array $ctx ) {
		$slug = ! empty( $ctx['post_type'] ) ? (string) $ctx['post_type'] : '';
		if ( '' === $slug ) {
			$post = self::post( $ctx );
			if ( $post ) {
				$slug = $post->post_type;
			} else {
				$queried = get_query_var( 'post_type' );
				$slug    = is_array( $queried ) ? (string) reset( $queried ) : (string) $queried;
			}
		}
		$obj = '' !== $slug ? get_post_type_object( $slug ) : null;
		return $obj instanceof WP_Post_Type ? $obj : null;
	}
}
