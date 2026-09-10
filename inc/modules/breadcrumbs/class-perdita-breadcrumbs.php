<?php
/**
 * Breadcrumbs: a trail from the home page down to whatever is being viewed.
 *
 * The whole module hangs off one pure function, Perdita_Breadcrumbs::trail(),
 * which returns an ordered array of array( 'title' => string, 'url' =>
 * string|null ) with the last item's url always null. Nothing in it echoes,
 * enqueues, or reads a rendering option, so Perdita Pro's schema class can
 * call it to build a BreadcrumbList from exactly the trail the visitor sees.
 * Everything visual (the separator, the prefix, whether the current item is
 * shown at all) lives in the renderer instead.
 *
 * Four ways to output it: the perdita_breadcrumbs() template tag, the
 * [perdita_breadcrumbs] shortcode, the perdita/breadcrumbs block, and the
 * auto_insert setting, which prepends the trail to the content on singular
 * views.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Breadcrumbs {

	/**
	 * Settings option.
	 */
	const OPTION = 'perdita_breadcrumbs';

	/**
	 * Block name.
	 */
	const BLOCK = 'perdita/breadcrumbs';

	/**
	 * Handle of the inline editor script the block registers.
	 */
	const EDITOR_HANDLE = 'perdita-breadcrumbs-editor';

	/**
	 * Whether the inline CSS has been printed this request.
	 *
	 * @var bool
	 */
	private static $css_printed = false;

	/**
	 * Whether auto_insert has already fired on this request, so a theme that
	 * runs the_content more than once (an excerpt fallback, a related-posts
	 * loop) gets one trail, not one per pass.
	 *
	 * @var bool
	 */
	private static $auto_inserted = false;

	/**
	 * The Perdita core.
	 *
	 * @var Perdita_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Core $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_shortcode( 'perdita_breadcrumbs', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ), 20 );

		$s = self::settings();
		if ( 'before_content' === $s['auto_insert'] ) {
			add_filter( 'the_content', array( $this, 'auto_insert' ), 8 );
		}
	}

	/* ==========================================================
	 * Settings
	 * ========================================================== */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'separator'     => '›',
			'home_label'    => __( 'Home', 'perdita-core' ),
			'show_current'  => true,
			'show_on_front' => false,
			'prefix'        => '',
			'auto_insert'   => 'none',
		);
	}

	/**
	 * Stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( ! in_array( $s['auto_insert'], array( 'none', 'before_content' ), true ) ) {
			$s['auto_insert'] = 'none';
		}
		if ( '' === trim( (string) $s['home_label'] ) ) {
			$s['home_label'] = self::defaults()['home_label'];
		}
		return $s;
	}

	/**
	 * Sanitize a settings array. Every write goes through here.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d = self::defaults();
		return array(
			'separator'     => isset( $input['separator'] ) ? sanitize_text_field( (string) $input['separator'] ) : $d['separator'],
			'home_label'    => isset( $input['home_label'] ) && '' !== trim( (string) $input['home_label'] ) ? sanitize_text_field( (string) $input['home_label'] ) : $d['home_label'],
			'show_current'  => ! empty( $input['show_current'] ),
			'show_on_front' => ! empty( $input['show_on_front'] ),
			'prefix'        => isset( $input['prefix'] ) ? sanitize_text_field( (string) $input['prefix'] ) : $d['prefix'],
			'auto_insert'   => isset( $input['auto_insert'] ) && 'before_content' === $input['auto_insert'] ? 'before_content' : 'none',
		);
	}

	/* ==========================================================
	 * The trail
	 * ========================================================== */

	/**
	 * The breadcrumb trail for the current request, or for one post.
	 *
	 * Pure: no output, no enqueue, no rendering option read. The last item's
	 * url is always null, because the last item is the thing being looked at.
	 *
	 * @param int|WP_Post|null $ctx Optional post (id or object) to build the
	 *                              trail for, so it works outside the loop
	 *                              and outside a matching query.
	 * @return array[] Ordered list of array( 'title' => string, 'url' => string|null ).
	 */
	public static function trail( $ctx = null ) {
		$home  = array(
			'title' => self::settings()['home_label'],
			'url'   => home_url( '/' ),
		);
		$post  = ( null === $ctx || '' === $ctx ) ? null : get_post( $ctx );
		$items = array( $home );
		$paged = false;

		if ( $post instanceof WP_Post ) {
			$items = array_merge( $items, self::post_ancestry( $post ), array( self::post_item( $post ) ) );
		} elseif ( is_front_page() ) {
			$paged = true;
		} elseif ( is_home() ) {
			$items = array_merge( $items, self::blog_items() );
			$paged = true;
		} elseif ( is_singular() ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post ) {
				$items = array_merge( $items, self::post_ancestry( $queried ), array( self::post_item( $queried ) ) );
			}
			$paged = true;
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$items = array_merge( $items, self::taxonomy_context( $term->taxonomy ), self::term_chain( $term ) );
			}
			$paged = true;
		} elseif ( is_post_type_archive() ) {
			$items = array_merge( $items, self::post_type_archive_items() );
			$paged = true;
		} elseif ( is_date() ) {
			$items = array_merge( $items, self::date_items() );
			$paged = true;
		} elseif ( is_author() ) {
			$author = get_queried_object();
			if ( $author instanceof WP_User ) {
				$items[] = array(
					'title' => (string) $author->display_name,
					'url'   => (string) get_author_posts_url( $author->ID ),
				);
			}
			$paged = true;
		} elseif ( is_search() ) {
			$items[] = array(
				'title' => sprintf(
					/* translators: %s: the search term. */
					__( 'Search results for "%s"', 'perdita-core' ),
					get_search_query( false )
				),
				'url'   => (string) get_search_link(),
			);
			$paged = true;
		} elseif ( is_404() ) {
			$items[] = array(
				'title' => __( 'Page not found', 'perdita-core' ),
				'url'   => null,
			);
		} elseif ( is_archive() ) {
			$items[] = array(
				'title' => wp_strip_all_tags( (string) get_the_archive_title() ),
				'url'   => null,
			);
			$paged = true;
		}

		if ( $paged ) {
			$page = self::current_page_number();
			if ( $page > 1 ) {
				$items[] = array(
					'title' => sprintf(
						/* translators: %d: page number. */
						__( 'Page %d', 'perdita-core' ),
						$page
					),
					'url'   => null,
				);
			}
		}

		$items = array_values( array_filter( $items ) );
		if ( $items ) {
			$items[ count( $items ) - 1 ]['url'] = null;
		}

		/**
		 * The finished breadcrumb trail.
		 *
		 * @param array[]          $items Trail items.
		 * @param int|WP_Post|null $ctx   The context passed to trail().
		 */
		return (array) apply_filters( 'perdita_breadcrumbs_trail', $items, $ctx );
	}

	/**
	 * The items between Home and a single post: page ancestors, a post type
	 * archive, or a category chain, depending on the post type.
	 *
	 * @param WP_Post $post Post.
	 * @return array[]
	 */
	private static function post_ancestry( WP_Post $post ) {
		$items = array();

		// An attachment hangs off its parent, not off a taxonomy.
		if ( 'attachment' === $post->post_type && $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return array_merge( self::post_ancestry( $parent ), array( self::post_item( $parent ) ) );
			}
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( (array) get_post_ancestors( $post ) ) as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );
				if ( $ancestor instanceof WP_Post ) {
					$items[] = self::post_item( $ancestor, get_permalink( $ancestor ) );
				}
			}
			return $items;
		}

		if ( 'post' !== $post->post_type ) {
			$items = array_merge( $items, self::archive_item_for_type( $post->post_type ) );
		}

		$term = self::primary_term( $post );
		if ( $term instanceof WP_Term ) {
			$items = array_merge( $items, self::term_chain( $term ) );
		}

		return $items;
	}

	/**
	 * One trail item for a post.
	 *
	 * @param WP_Post     $post Post.
	 * @param string|null $url  Optional url, null for the current item.
	 * @return array
	 */
	private static function post_item( WP_Post $post, $url = null ) {
		$title = wp_strip_all_tags( (string) get_the_title( $post ) );
		if ( '' === trim( $title ) ) {
			$title = __( '(no title)', 'perdita-core' );
		}
		return array(
			'title' => $title,
			'url'   => null === $url ? null : (string) $url,
		);
	}

	/**
	 * The blog item, for the posts page when a static front page is set.
	 *
	 * @return array[]
	 */
	private static function blog_items() {
		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( ! $page_for_posts ) {
			return array();
		}
		return array( self::post_item( get_post( $page_for_posts ), get_permalink( $page_for_posts ) ) );
	}

	/**
	 * The post type archive item for a type, when it has one.
	 *
	 * @param string $post_type Post type.
	 * @return array[] Zero or one item.
	 */
	private static function archive_item_for_type( $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->has_archive ) ) {
			return array();
		}
		$link = get_post_type_archive_link( $post_type );
		if ( ! $link ) {
			return array();
		}
		return array(
			array(
				'title' => self::post_type_label( $obj ),
				'url'   => (string) $link,
			),
		);
	}

	/**
	 * The archive label for a post type object.
	 *
	 * @param WP_Post_Type $obj Post type object.
	 * @return string
	 */
	private static function post_type_label( $obj ) {
		if ( ! empty( $obj->labels->archives ) ) {
			return (string) $obj->labels->archives;
		}
		if ( ! empty( $obj->labels->name ) ) {
			return (string) $obj->labels->name;
		}
		return (string) $obj->name;
	}

	/**
	 * The items in front of a term archive: the post type archive of the
	 * taxonomy's first object type, when that type is not the built-in post.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array[]
	 */
	private static function taxonomy_context( $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->object_type ) ) {
			return array();
		}
		$type = (string) reset( $tax->object_type );
		if ( '' === $type || 'post' === $type ) {
			return array();
		}
		return self::archive_item_for_type( $type );
	}

	/**
	 * A term and every ancestor above it, root first, each linked.
	 *
	 * @param WP_Term $term Term.
	 * @return array[]
	 */
	private static function term_chain( WP_Term $term ) {
		$items = array();
		foreach ( array_reverse( (array) get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$items[] = self::term_item( $ancestor );
			}
		}
		$items[] = self::term_item( $term );
		return $items;
	}

	/**
	 * One trail item for a term.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	private static function term_item( WP_Term $term ) {
		$link = get_term_link( $term );
		return array(
			'title' => wp_strip_all_tags( (string) $term->name ),
			'url'   => is_wp_error( $link ) ? null : (string) $link,
		);
	}

	/**
	 * The term a post's trail runs through: its first category for the
	 * built-in post type, otherwise the first term in the first public
	 * hierarchical taxonomy the type has.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Term|null
	 */
	private static function primary_term( WP_Post $post ) {
		$taxonomies = 'post' === $post->post_type
			? array( 'category' )
			: (array) get_object_taxonomies( $post->post_type, 'names' );

		foreach ( $taxonomies as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || empty( $tax->hierarchical ) || empty( $tax->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				$first = reset( $terms );
				if ( $first instanceof WP_Term ) {
					return $first;
				}
			}
		}
		return null;
	}

	/**
	 * The items for a post type archive view.
	 *
	 * @return array[]
	 */
	private static function post_type_archive_items() {
		$type = get_query_var( 'post_type' );
		if ( is_array( $type ) ) {
			$type = (string) reset( $type );
		}
		$obj = get_post_type_object( (string) $type );
		if ( ! $obj ) {
			return array();
		}
		return array(
			array(
				'title' => self::post_type_label( $obj ),
				'url'   => (string) get_post_type_archive_link( $obj->name ),
			),
		);
	}

	/**
	 * Year, month, and day items for a date archive, each linked.
	 *
	 * @return array[]
	 */
	private static function date_items() {
		$year  = (int) get_query_var( 'year' );
		$month = (int) get_query_var( 'monthnum' );
		$day   = (int) get_query_var( 'day' );
		$items = array();

		if ( $year ) {
			$items[] = array(
				'title' => (string) $year,
				'url'   => (string) get_year_link( $year ),
			);
		}
		if ( $year && $month ) {
			$items[] = array(
				'title' => wp_strip_all_tags( (string) wp_date( 'F', mktime( 0, 0, 0, $month, 1, $year ) ) ),
				'url'   => (string) get_month_link( $year, $month ),
			);
		}
		if ( $year && $month && $day ) {
			$items[] = array(
				'title' => (string) $day,
				'url'   => (string) get_day_link( $year, $month, $day ),
			);
		}
		return $items;
	}

	/**
	 * The page number of a paged view, 1 when it is the first page. Covers
	 * both a paged archive and a multi-page single post.
	 *
	 * @return int
	 */
	private static function current_page_number() {
		return max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	}

	/* ==========================================================
	 * Rendering
	 * ========================================================== */

	/**
	 * The trail as HTML.
	 *
	 * @param array $args Overrides for separator, home_label, show_current,
	 *                    prefix, class, and post (a post id for the trail).
	 * @return string Empty when there is nothing worth showing.
	 */
	public static function render( array $args = array() ) {
		$s    = array_merge( self::settings(), array_intersect_key( $args, self::defaults() ) );
		$post = isset( $args['post'] ) ? $args['post'] : null;

		if ( null === $post && is_front_page() && empty( $s['show_on_front'] ) ) {
			return '';
		}

		$items = self::trail( $post );
		if ( empty( $s['show_current'] ) && count( $items ) > 1 ) {
			array_pop( $items );
			$items[ count( $items ) - 1 ]['url'] = null;
		}
		if ( count( $items ) < 2 ) {
			return '';
		}

		$class = 'perdita-breadcrumbs';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		$sep  = '<span class="perdita-breadcrumbs__sep" aria-hidden="true">' . esc_html( (string) $s['separator'] ) . '</span> ';
		$html = self::css();
		$html .= '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'perdita-core' ) . '">';
		if ( '' !== trim( (string) $s['prefix'] ) ) {
			$html .= '<span class="perdita-breadcrumbs__prefix">' . esc_html( (string) $s['prefix'] ) . '</span> ';
		}
		$html .= '<ol>';
		$last  = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			$html .= '<li>';
			if ( $i > 0 ) {
				$html .= $sep;
			}
			if ( ! empty( $item['url'] ) && $i !== $last ) {
				$html .= '<a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['title'] ) . '</a>';
			} else {
				$html .= '<span' . ( $i === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $item['title'] ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol></nav>';

		return $html;
	}

	/**
	 * The module's inline CSS, once per request. Small enough that a separate
	 * stylesheet request would cost more than the bytes it saves.
	 *
	 * @return string
	 */
	private static function css() {
		if ( self::$css_printed ) {
			return '';
		}
		self::$css_printed = true;
		return '<style id="perdita-breadcrumbs-css">.perdita-breadcrumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;align-items:center;gap:.35em}.perdita-breadcrumbs li{display:flex;align-items:center;gap:.35em}.perdita-breadcrumbs__sep{opacity:.6}.perdita-breadcrumbs__prefix{opacity:.7;margin-right:.35em}</style>';
	}

	/**
	 * [perdita_breadcrumbs] handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'separator'    => null,
				'home_label'   => null,
				'prefix'       => null,
				'show_current' => null,
				'class'        => '',
			),
			(array) $atts,
			'perdita_breadcrumbs'
		);

		$args = array();
		foreach ( array( 'separator', 'home_label', 'prefix', 'class' ) as $key ) {
			if ( null !== $atts[ $key ] && '' !== $atts[ $key ] ) {
				$args[ $key ] = (string) $atts[ $key ];
			}
		}
		if ( null !== $atts['show_current'] ) {
			$args['show_current'] = in_array( strtolower( (string) $atts['show_current'] ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return self::render( $args );
	}

	/**
	 * Prepend the trail to the content on singular main-query views, once.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function auto_insert( $content ) {
		if ( self::$auto_inserted || ! is_singular() || ! in_the_loop() || ! is_main_query() || is_front_page() ) {
			return $content;
		}
		self::$auto_inserted = true;
		return self::render() . $content;
	}

	/* ==========================================================
	 * Block
	 * ========================================================== */

	/**
	 * Register the perdita/breadcrumbs block from its block.json, with a
	 * server-side render callback so the markup can never drift between the
	 * editor and the front end.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) || WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_HANDLE,
			false,
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ),
			PERDITA_CORE_VERSION,
			true
		);
		wp_add_inline_script( self::EDITOR_HANDLE, self::editor_script() );

		register_block_type(
			__DIR__,
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * The block's editor script: a plain registerBlockType call that renders
	 * through wp.serverSideRender, so there is no build step and no JSX.
	 *
	 * @return string
	 */
	private static function editor_script() {
		return "( function( wp ) {\n"
			. "\tif ( ! wp || ! wp.blocks || ! wp.blocks.registerBlockType ) { return; }\n"
			. "\tvar el = wp.element.createElement;\n"
			. "\tvar SSR = wp.serverSideRender;\n"
			. "\twp.blocks.registerBlockType( '" . self::BLOCK . "', {\n"
			. "\t\tedit: function( props ) {\n"
			. "\t\t\tvar blockProps = wp.blockEditor.useBlockProps();\n"
			. "\t\t\treturn el( 'div', blockProps, el( SSR, { block: '" . self::BLOCK . "', attributes: props.attributes } ) );\n"
			. "\t\t},\n"
			. "\t\tsave: function() { return null; }\n"
			. "\t} );\n"
			. "} )( window.wp );\n";
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = (array) $attributes;
		$args       = array();
		if ( ! empty( $attributes['separator'] ) ) {
			$args['separator'] = (string) $attributes['separator'];
		}
		if ( isset( $attributes['showCurrent'] ) ) {
			$args['show_current'] = (bool) $attributes['showCurrent'];
		}

		$html = self::render( $args );
		if ( '' === $html && is_admin() ) {
			return '<p>' . esc_html__( 'The breadcrumb trail appears here on the front end.', 'perdita-core' ) . '</p>';
		}
		return $html;
	}
}

if ( ! function_exists( 'perdita_breadcrumbs' ) ) {
	/**
	 * Template tag: print (or return) the breadcrumb trail.
	 *
	 * @param array $args Overrides: separator, home_label, show_current,
	 *                    prefix, class, post (a post id).
	 * @param bool  $echo Whether to print it. Pass false to get the string.
	 * @return string The markup, always returned as well as printed.
	 */
	function perdita_breadcrumbs( $args = array(), $echo = true ) {
		$html = Perdita_Breadcrumbs::render( (array) $args );
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every title, url and separator with esc_html/esc_url/esc_attr as it builds this markup.
			echo $html;
		}
		return $html;
	}
}
