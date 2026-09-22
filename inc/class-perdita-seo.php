<?php
/**
 * SEO output. Renders titles, meta description, canonical, robots, Open Graph,
 * Twitter cards, webmaster verification, and a linked JSON-LD graph from the
 * Perdita SEO store. Steps aside if a dedicated SEO plugin is active or if
 * the user turned Perdita SEO off.
 *
 * Extension contract (Perdita Pro and anything else hook these, so the names
 * and shapes are stable):
 *
 *   perdita_seo_description( string $description, array $ctx )
 *   perdita_seo_canonical( string $url, array $ctx )
 *   perdita_seo_og_tags( array $tags, array $ctx )   key => value|value[]
 *   perdita_seo_json_ld( array $graph, array $ctx )  list of node arrays
 *   perdita_seo_article_type( string $type, WP_Post $post, array $ctx )
 *
 * $ctx comes from context(): type (singular, front, home, archive, search,
 * 404), post (WP_Post|null), term (WP_Term|null), post_type (string|null).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the SEO head tags and the JSON-LD graph for the current view.
 */
class Perdita_SEO {

	/**
	 * SEO store.
	 *
	 * @var Perdita_SEO_Store
	 */
	private $store;

	/**
	 * The live instance, so a test or a companion plugin can reach the
	 * output methods and the sub-objects without a second construction.
	 *
	 * @var Perdita_SEO|null
	 */
	private static $instance = null;

	/**
	 * Author profile fields and Person schema.
	 *
	 * @var Perdita_SEO_Author|null
	 */
	public $author = null;

	/**
	 * Per-term title and description meta.
	 *
	 * @var Perdita_SEO_Terms|null
	 */
	public $terms = null;

	/**
	 * Feed controls.
	 *
	 * @var Perdita_SEO_Feeds|null
	 */
	public $feeds = null;

	/**
	 * Responder for /llms.txt and /llms-full.txt.
	 *
	 * @var Perdita_SEO_Llms|null
	 */
	public $llms = null;

	/**
	 * Constructor.
	 *
	 * @param Perdita_SEO_Store $store SEO store.
	 */
	public function __construct( Perdita_SEO_Store $store ) {
		$this->store    = $store;
		self::$instance = $this;

		if ( ! $store->get( 'enabled', true ) || self::other_seo_active() ) {
			return;
		}

		// The seo module's boot closure requires these. The guard covers a
		// direct construction (the tests, a WP-CLI script) that skipped it.
		foreach ( array( 'variables', 'author', 'terms', 'feeds', 'llms' ) as $part ) {
			if ( ! class_exists( 'Perdita_SEO_' . ucfirst( $part ) ) ) {
				require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-' . $part . '.php';
			}
		}
		$this->author = new Perdita_SEO_Author();
		$this->terms  = new Perdita_SEO_Terms();
		$this->feeds  = new Perdita_SEO_Feeds( $store );
		$this->llms   = new Perdita_SEO_Llms( $store );

		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_filter( 'document_title_separator', array( $this, 'separator' ) );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 5, 2 );
		add_action( 'template_redirect', array( $this, 'attachment_redirect' ), 2 );

		// Core prints its own canonical on singular views at wp_head 10, so a
		// post used to carry two. This layer owns the canonical now.
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * The live instance, or null before the seo module booted.
	 *
	 * @return Perdita_SEO|null
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Detect common SEO plugins so we never double up tags.
	 *
	 * @return bool
	 */
	public static function other_seo_active() {
		return defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' );
	}

	/* ---------- context ---------- */

	/**
	 * What the current view is about. Every filter in this class receives
	 * this same array, so a hook can branch on it without re-running the
	 * conditional tags.
	 *
	 * @return array type, post, term, post_type.
	 */
	public function context() {
		$ctx = array(
			'type'      => 'archive',
			'post'      => null,
			'term'      => null,
			'post_type' => null,
		);
		if ( is_404() ) {
			$ctx['type'] = '404';
			return $ctx;
		}
		if ( is_search() ) {
			$ctx['type'] = 'search';
			return $ctx;
		}
		$obj = get_queried_object();
		if ( is_front_page() ) {
			$ctx['type'] = 'front';
			if ( $obj instanceof WP_Post ) {
				$ctx['post']      = $obj;
				$ctx['post_type'] = $obj->post_type;
			}
			return $ctx;
		}
		if ( is_home() ) {
			$ctx['type']      = 'home';
			$ctx['post_type'] = 'post';
			if ( $obj instanceof WP_Post ) {
				$ctx['post'] = $obj;
			}
			return $ctx;
		}
		if ( is_singular() && $obj instanceof WP_Post ) {
			$ctx['type']      = 'singular';
			$ctx['post']      = $obj;
			$ctx['post_type'] = $obj->post_type;
			return $ctx;
		}
		if ( $obj instanceof WP_Term ) {
			$ctx['term'] = $obj;
			return $ctx;
		}
		if ( $obj instanceof WP_Post_Type ) {
			$ctx['post_type'] = $obj->name;
		}
		return $ctx;
	}

	/* ---------- title ---------- */

	/**
	 * Apply the title template for the view: the homepage title, the post
	 * type template, the term's own title or its taxonomy template, or the
	 * archive, search, and 404 templates.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		$parts    = is_array( $parts ) ? $parts : array();
		$ctx      = $this->context();
		$template = $this->title_template( $ctx );
		if ( '' === $template ) {
			return $parts;
		}
		$built = Perdita_SEO_Variables::resolve( $template, $ctx + array( 'title' => isset( $parts['title'] ) ? (string) $parts['title'] : '' ) );
		if ( '' === $built ) {
			return $parts;
		}
		$out = array( 'title' => $built );
		// Keep core's "Page N" when the template did not place %page% itself.
		if ( isset( $parts['page'] ) && false === stripos( $template, '%page%' ) ) {
			$out['page'] = $parts['page'];
		}
		return $out;
	}

	/**
	 * The title template for a view.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	private function title_template( array $ctx ) {
		switch ( $ctx['type'] ) {
			case 'front':
			case 'home':
				$home = trim( (string) $this->store->get( 'home_title' ) );
				if ( '' !== $home ) {
					return $home;
				}
				if ( 'front' === $ctx['type'] ) {
					return '%sitename% %sep% %tagline%';
				}
				return $this->store->post_type_settings( $ctx['post'] instanceof WP_Post ? $ctx['post']->post_type : 'post' )['title'];
			case 'singular':
				return $this->store->post_type_settings( (string) $ctx['post_type'] )['title'];
			case 'search':
				return (string) $this->store->get( 'search_title', '%title% %sep% %sitename%' );
			case '404':
				return (string) $this->store->get( '404_title', '%title% %sep% %sitename%' );
			case 'archive':
				if ( $ctx['term'] instanceof WP_Term ) {
					$own = Perdita_SEO_Terms::title( $ctx['term']->term_id );
					return '' !== $own ? $own : $this->store->taxonomy_settings( $ctx['term']->taxonomy )['title'];
				}
				return (string) $this->store->get( 'archive_title', '%archive_title% %sep% %sitename%' );
		}
		return (string) $this->store->get( 'title_template' );
	}

	/**
	 * Title separator.
	 *
	 * @return string
	 */
	public function separator() {
		return (string) $this->store->get( 'separator', '-' );
	}

	/* ---------- description, canonical, image ---------- */

	/**
	 * Best description for the view, run through perdita_seo_description.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	private function description( array $ctx ) {
		$desc = '';
		switch ( $ctx['type'] ) {
			case 'front':
			case 'home':
				if ( ! is_paged() ) {
					$home = trim( (string) $this->store->get( 'home_description' ) );
					if ( '' !== $home ) {
						$desc = Perdita_SEO_Variables::resolve( $home, $ctx );
					}
				}
				if ( '' === $desc && $ctx['post'] instanceof WP_Post ) {
					$desc = Perdita_SEO_Variables::resolve( $this->store->post_type_settings( $ctx['post']->post_type )['description'], $ctx );
				}
				break;
			case 'singular':
				$desc = Perdita_SEO_Variables::resolve( $this->store->post_type_settings( (string) $ctx['post_type'] )['description'], $ctx );
				break;
			case 'archive':
				if ( $ctx['term'] instanceof WP_Term ) {
					$own  = Perdita_SEO_Terms::description( $ctx['term']->term_id );
					$desc = Perdita_SEO_Variables::resolve( '' !== $own ? $own : $this->store->taxonomy_settings( $ctx['term']->taxonomy )['description'], $ctx );
				}
				break;
		}
		if ( '' === $desc ) {
			$desc = Perdita_SEO_Variables::resolve( (string) $this->store->get( 'default_description' ), $ctx );
		}

		/**
		 * The meta description before it prints. Also feeds og:description,
		 * twitter:description, and the schema description.
		 *
		 * @param string $desc Description.
		 * @param array  $ctx  View context.
		 */
		return (string) apply_filters( 'perdita_seo_description', $desc, $ctx );
	}

	/**
	 * Best canonical/og URL for the current view, for every archive type and
	 * for either permalink structure.
	 *
	 * @return string
	 */
	private function current_url() {
		if ( is_singular() ) {
			$url = get_permalink();
			// A <!--nextpage--> split post/page uses the 'page' query var (not
			// 'paged', which is post-list pagination) for which of its own
			// pages this is.
			$page = (int) get_query_var( 'page' );
			if ( $page > 1 ) {
				$url = trailingslashit( $url ) . user_trailingslashit( (string) $page, 'single_paged' );
			}
			return (string) $url;
		}

		// get_pagenum_link() reconstructs the CURRENT view's own URL from the
		// actual request rather than from $wp->request, which core only
		// populates under pretty permalinks. That is why every archive type
		// (category, tag, author, date, CPT) used to get no canonical at all
		// on a Plain-permalinks site, and why a paginated home/front page
		// still canonicalized to page 1. This is the same machinery
		// paginate_links()/the_posts_pagination() rely on, so it is correct
		// for any archive type under either permalink structure.
		$paged = (int) get_query_var( 'paged' );
		return (string) get_pagenum_link( max( 1, $paged ), false );
	}

	/**
	 * The social image for the view: the featured image with its size and
	 * alt, else the store's default image (cached with the same fields).
	 *
	 * @param array $ctx View context.
	 * @return array id, url, width, height, alt.
	 */
	private function image( array $ctx ) {
		$empty = array(
			'id'     => 0,
			'url'    => '',
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
		);
		$post  = $ctx['post'];
		if ( $post instanceof WP_Post && has_post_thumbnail( $post ) ) {
			$id  = (int) get_post_thumbnail_id( $post );
			$src = wp_get_attachment_image_src( $id, 'large' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return array(
					'id'     => $id,
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		return array_merge( $empty, array_intersect_key( $this->store->image_data(), $empty ) );
	}

	/* ---------- robots ---------- */

	/**
	 * Should the current view be noindexed? Site-wide archive flags, the
	 * per post type and per taxonomy flags, the author's own flag, paginated
	 * pages, and attachment pages when they are set to noindex.
	 *
	 * @param array $ctx View context.
	 * @return bool
	 */
	private function is_noindex( array $ctx ) {
		$n = $this->store->get( 'noindex', array() );
		if ( is_search() && ! empty( $n['search'] ) ) {
			return true;
		}
		if ( is_date() && ! empty( $n['archive_date'] ) ) {
			return true;
		}
		if ( is_author() ) {
			if ( ! empty( $n['archive_author'] ) ) {
				return true;
			}
			$user = get_queried_object();
			if ( $user instanceof WP_User && Perdita_SEO_Author::noindex_archive( $user->ID ) ) {
				return true;
			}
		}
		if ( is_tag() && ! empty( $n['archive_tag'] ) ) {
			return true;
		}
		if ( is_category() && ! empty( $n['archive_category'] ) ) {
			return true;
		}
		if ( is_post_type_archive() && ! empty( $n['archive_post_type'] ) ) {
			return true;
		}
		if ( $ctx['term'] instanceof WP_Term && $this->store->taxonomy_settings( $ctx['term']->taxonomy )['noindex'] ) {
			return true;
		}
		if ( 'singular' === $ctx['type'] && $ctx['post'] instanceof WP_Post ) {
			if ( 'attachment' === $ctx['post']->post_type && 'noindex' === $this->store->get( 'attachments', 'redirect' ) ) {
				return true;
			}
			if ( $this->store->post_type_settings( $ctx['post']->post_type )['noindex'] ) {
				return true;
			}
		}
		if ( is_paged() && $this->store->get( 'noindex_paginated' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Core's wp_robots filter: noindex where the settings say so, else the
	 * site-wide snippet, image, and video preview limits.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public function robots( $robots ) {
		$robots = is_array( $robots ) ? $robots : array();
		$ctx    = $this->context();
		if ( $this->is_noindex( $ctx ) ) {
			$robots['noindex'] = true;
			if ( empty( $robots['nofollow'] ) ) {
				$robots['follow'] = true;
			}
		}
		if ( ! empty( $robots['noindex'] ) || ! get_option( 'blog_public' ) ) {
			// Preview limits mean nothing on a page that is not indexed.
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
			return $robots;
		}
		$robots['max-snippet']       = (string) max( -1, (int) $this->store->get( 'max_snippet', -1 ) );
		$robots['max-image-preview'] = (string) $this->store->get( 'max_image_preview', 'large' );
		$robots['max-video-preview'] = (string) max( -1, (int) $this->store->get( 'max_video_preview', -1 ) );
		return $robots;
	}

	/**
	 * Send a single attachment page to its parent (or home) with a 301 when
	 * attachments are set to redirect.
	 */
	public function attachment_redirect() {
		if ( ! is_attachment() || 'redirect' !== $this->store->get( 'attachments', 'redirect' ) ) {
			return;
		}
		$target = $this->attachment_target( get_queried_object() );
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Where an attachment page should send visitors: the published parent,
	 * else home. Public so the decision is testable without a redirect.
	 *
	 * @param WP_Post|null $attachment Attachment post.
	 * @return string
	 */
	public function attachment_target( $attachment ) {
		if ( ! $attachment instanceof WP_Post ) {
			return home_url( '/' );
		}
		$parent = $attachment->post_parent ? get_post( (int) $attachment->post_parent ) : null;
		if ( $parent instanceof WP_Post && 'publish' === $parent->post_status && is_post_type_viewable( $parent->post_type ) ) {
			return (string) get_permalink( $parent );
		}
		return home_url( '/' );
	}

	/**
	 * The robots_txt filter: the stored text verbatim when there is one,
	 * plus core's Sitemap line unless the stored text already names one.
	 *
	 * @param string $output Core's virtual robots.txt (sitemap line included).
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function robots_txt( $output, $public ) {
		unset( $public );
		$custom = trim( (string) $this->store->get( 'robots_txt' ) );
		if ( '' === $custom ) {
			return $output;
		}
		$custom = str_replace( "\r\n", "\n", $custom );
		// This runs at priority 5, so the only Sitemap line in $output is the
		// one WordPress core added. Carry it over unless the stored text names
		// a sitemap of its own. Modules that add their own Sitemap lines (the
		// image sitemap, for one) hook later and append to the override.
		if ( false === stripos( $custom, 'sitemap:' ) && preg_match_all( '/^sitemap:\s*\S+\s*$/mi', (string) $output, $m ) ) {
			$custom .= "\n\n" . implode( "\n", array_map( 'trim', $m[0] ) );
		}
		return $custom . "\n";
	}

	/**
	 * The robots.txt WordPress serves with no override: core's rules, the
	 * sitemap line, and whatever other plugins add. The admin shows it as
	 * the starting point for the editor.
	 *
	 * @return string
	 */
	public static function default_robots_txt() {
		$public   = (bool) get_option( 'blog_public' );
		$site_url = wp_parse_url( site_url() );
		$path     = ( ! empty( $site_url['path'] ) ) ? $site_url['path'] : '';
		$output   = "User-agent: *\n";
		$output  .= "Disallow: $path/wp-admin/\n";
		$output  .= "Allow: $path/wp-admin/admin-ajax.php\n";
		$self     = self::instance();
		if ( $self ) {
			remove_filter( 'robots_txt', array( $self, 'robots_txt' ), 5 );
		}
		$output = (string) apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, applied to reproduce core's file.
		if ( $self ) {
			add_filter( 'robots_txt', array( $self, 'robots_txt' ), 5, 2 );
		}
		return $output;
	}

	/* ---------- head ---------- */

	/**
	 * Output head tags.
	 */
	public function head() {
		$ctx   = $this->context();
		$desc  = $this->description( $ctx );
		$title = wp_get_document_title();
		$image = $this->image( $ctx );

		/**
		 * The canonical URL before it prints. Also feeds og:url and the
		 * schema @id values.
		 *
		 * @param string $url Canonical URL.
		 * @param array  $ctx View context.
		 */
		$url = (string) apply_filters( 'perdita_seo_canonical', $this->current_url(), $ctx );

		echo "\n<!-- Perdita SEO -->\n";
		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( '' !== $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
		$this->verification();

		/**
		 * Open Graph, article, Facebook, and Twitter card tags as key =>
		 * value (or a list of values for a repeated key such as
		 * article:tag). Keys starting with og:, article:, or fb: print as
		 * property attributes, everything else as name attributes. Empty
		 * values are skipped.
		 *
		 * @param array $tags Tags.
		 * @param array $ctx  View context.
		 */
		$tags = (array) apply_filters( 'perdita_seo_og_tags', $this->og_tags( $ctx, $title, $desc, $url, $image ), $ctx );
		$this->print_meta( $tags );

		$this->json_ld( $ctx, $title, $desc, $url, $image );
	}

	/**
	 * Webmaster verification tags, front page only.
	 */
	private function verification() {
		if ( ! is_front_page() || is_paged() ) {
			return;
		}
		$map = array(
			'verify_google'    => 'google-site-verification',
			'verify_bing'      => 'msvalidate.01',
			'verify_pinterest' => 'p:domain_verify',
			'verify_yandex'    => 'yandex-verification',
			'verify_baidu'     => 'baidu-site-verification',
		);
		foreach ( $map as $key => $name ) {
			$token = Perdita_SEO_Store::sanitize_verification( $this->store->get( $key ) );
			if ( '' === $token ) {
				continue;
			}
			printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $token ) );
		}
	}

	/**
	 * The Open Graph, article, Facebook, and Twitter tags for the view.
	 *
	 * @param array  $ctx   View context.
	 * @param string $title Document title.
	 * @param string $desc  Description.
	 * @param string $url   Canonical URL.
	 * @param array  $image Image data from image().
	 * @return array
	 */
	private function og_tags( array $ctx, $title, $desc, $url, array $image ) {
		$post = $ctx['post'] instanceof WP_Post ? $ctx['post'] : null;
		$tags = array(
			'og:locale'      => get_locale(),
			'og:type'        => 'singular' === $ctx['type'] ? 'article' : 'website',
			'og:title'       => $title,
			'og:description' => $desc,
			'og:url'         => $url,
			'og:site_name'   => get_bloginfo( 'name' ),
		);
		if ( '' !== $image['url'] ) {
			$tags['og:image'] = $image['url'];
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$tags['og:image:width']  = (string) $image['width'];
				$tags['og:image:height'] = (string) $image['height'];
			}
			if ( '' !== $image['alt'] ) {
				$tags['og:image:alt'] = $image['alt'];
			}
		}
		if ( 'singular' === $ctx['type'] && $post && 'attachment' !== $post->post_type ) {
			$tags['article:published_time'] = (string) get_the_date( 'c', $post );
			$tags['article:modified_time']  = (string) get_the_modified_date( 'c', $post );
			// A post with no user (post_author 0) has no archive to point at,
			// and get_author_posts_url( 0 ) is a bare /author/.
			if ( get_userdata( (int) $post->post_author ) ) {
				$tags['article:author'] = get_author_posts_url( (int) $post->post_author );
			}
			$section = Perdita_SEO_Variables::primary_term( $post );
			if ( $section ) {
				$tags['article:section'] = (string) $section->name;
			}
			$post_tags = Perdita_SEO_Variables::term_names( $post, 'post_tag' );
			if ( $post_tags ) {
				$tags['article:tag'] = $post_tags;
			}
		}
		$app_id = trim( (string) $this->store->get( 'facebook_app_id' ) );
		if ( '' !== $app_id ) {
			$tags['fb:app_id'] = $app_id;
		}

		$tags['twitter:card'] = '' !== $image['url'] ? (string) $this->store->get( 'twitter_card', 'summary_large_image' ) : 'summary';
		$handle               = $this->twitter_handle();
		if ( '' !== $handle ) {
			$tags['twitter:site'] = '@' . $handle;
		}
		$tags['twitter:title']       = $title;
		$tags['twitter:description'] = $desc;
		if ( '' !== $image['url'] ) {
			$tags['twitter:image'] = $image['url'];
		}
		if ( $post && 'singular' === $ctx['type'] ) {
			$creator = Perdita_SEO_Author::twitter_handle( (int) $post->post_author );
			if ( '' !== $creator ) {
				$tags['twitter:creator'] = '@' . $creator;
			}
		}
		return $tags;
	}

	/**
	 * Print a tag array. Repeated keys carry a list of values and print one
	 * meta each. URL-bearing keys go through esc_url, the rest esc_attr.
	 *
	 * @param array $tags Tags.
	 */
	private function print_meta( array $tags ) {
		foreach ( $tags as $key => $values ) {
			$key = trim( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$attr   = preg_match( '/^(og|article|fb):/', $key ) ? 'property' : 'name';
			$is_url = (bool) preg_match( '/:(url|image|secure_url|video|audio|player|author|publisher|logo)$/', $key );
			foreach ( (array) $values as $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					continue;
				}
				$value = trim( (string) $value );
				if ( '' === $value ) {
					continue;
				}
				printf(
					'<meta %s="%s" content="%s" />' . "\n",
					$attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of two literals chosen above.
					esc_attr( $key ),
					$is_url ? esc_url( $value ) : esc_attr( $value )
				);
			}
		}
	}

	/**
	 * Twitter @handle from the stored profile (handle or URL).
	 *
	 * @return string
	 */
	private function twitter_handle() {
		$t = trim( (string) $this->store->get( 'social.twitter' ) );
		if ( '' === $t ) {
			return '';
		}
		if ( 0 === strpos( $t, 'http' ) ) {
			$t = (string) wp_parse_url( $t, PHP_URL_PATH );
		}
		return ltrim( trim( $t, '/@ ' ), '@' );
	}

	/* ---------- JSON-LD ---------- */

	/**
	 * Emit one linked JSON-LD graph: identity, WebSite, WebPage (typed for
	 * the view), the primary image, the Article for any public singular
	 * type other than page and attachment, and the author Person.
	 *
	 * @param array  $ctx   View context.
	 * @param string $title Document title.
	 * @param string $desc  Description.
	 * @param string $url   Canonical URL.
	 * @param array  $image Image data from image().
	 */
	private function json_ld( array $ctx, $title, $desc, $url, array $image ) {
		$home        = home_url( '/' );
		$identity_id = $home . '#identity';
		$website_id  = $home . '#website';
		$lang        = (string) get_bloginfo( 'language' );
		$site_name   = (string) get_bloginfo( 'name' );
		$url         = '' !== $url ? $url : $home;
		$page_id     = $url . '#webpage';
		$image_id    = $url . '#primaryimage';
		$post        = $ctx['post'] instanceof WP_Post ? $ctx['post'] : null;

		$identity = array(
			'@type' => (string) $this->store->get( 'schema_type', 'Organization' ),
			'@id'   => $identity_id,
			'name'  => $this->store->get( 'org_name' ) ? (string) $this->store->get( 'org_name' ) : $site_name,
			'url'   => $home,
		);
		$logo     = $this->store->logo_url();
		if ( $logo ) {
			$identity['logo']  = array(
				'@type'      => 'ImageObject',
				'@id'        => $home . '#logo',
				'url'        => $logo,
				'contentUrl' => $logo,
				'caption'    => $identity['name'],
			);
			$identity['image'] = array( '@id' => $home . '#logo' );
		}
		$same = $this->store->same_as();
		if ( $same ) {
			$identity['sameAs'] = $same;
		}

		$website = array(
			'@type'      => 'WebSite',
			'@id'        => $website_id,
			'url'        => $home,
			'name'       => $site_name,
			'publisher'  => array( '@id' => $identity_id ),
			'inLanguage' => $lang,
		);
		$tagline = (string) get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$website['description'] = $tagline;
		}
		$website['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $home . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		);

		$graph = array( $identity, $website );

		$webpage = array(
			'@type'      => $this->webpage_type( $ctx ),
			'@id'        => $page_id,
			'url'        => $url,
			'name'       => $title,
			'isPartOf'   => array( '@id' => $website_id ),
			'inLanguage' => $lang,
		);
		if ( '' !== $desc ) {
			$webpage['description'] = $desc;
		}
		if ( $post ) {
			$webpage['datePublished'] = (string) get_the_date( 'c', $post );
			$webpage['dateModified']  = (string) get_the_modified_date( 'c', $post );
		}
		if ( '' !== $image['url'] ) {
			$img = array(
				'@type'      => 'ImageObject',
				'@id'        => $image_id,
				'url'        => $image['url'],
				'contentUrl' => $image['url'],
			);
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$img['width']  = $image['width'];
				$img['height'] = $image['height'];
			}
			if ( '' !== $image['alt'] ) {
				$img['caption'] = $image['alt'];
			}
			$graph[]                       = $img;
			$webpage['primaryImageOfPage'] = array( '@id' => $image_id );
			$webpage['image']              = array( '@id' => $image_id );
			$webpage['thumbnailUrl']       = $image['url'];
		}

		$people = array();
		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof WP_User ) {
				$person = Perdita_SEO_Author::person_node( $user->ID, $identity_id );
				if ( $person ) {
					$webpage['@type']      = 'ProfilePage';
					$webpage['mainEntity'] = array( '@id' => $person['@id'] );
					$people[ $user->ID ]   = $person;
				}
			}
		}
		$graph[] = $webpage;

		if ( 'singular' === $ctx['type'] && $post && ! in_array( $post->post_type, array( 'page', 'attachment' ), true ) && is_post_type_viewable( $post->post_type ) ) {
			/**
			 * The schema type of the article node: Article, BlogPosting,
			 * NewsArticle, or empty to drop the node.
			 *
			 * @param string  $type Schema type.
			 * @param WP_Post $post Post.
			 * @param array   $ctx  View context.
			 */
			$type = (string) apply_filters( 'perdita_seo_article_type', 'Article', $post, $ctx );
			if ( '' !== $type ) {
				$article = array(
					'@type'            => $type,
					'@id'              => $url . '#article',
					'headline'         => (string) get_the_title( $post ),
					'url'              => $url,
					'datePublished'    => (string) get_the_date( 'c', $post ),
					'dateModified'     => (string) get_the_modified_date( 'c', $post ),
					'mainEntityOfPage' => array( '@id' => $page_id ),
					'isPartOf'         => array( '@id' => $page_id ),
					'publisher'        => array( '@id' => $identity_id ),
					'inLanguage'       => $lang,
				);
				if ( '' !== $desc ) {
					$article['description'] = $desc;
				}
				$person = Perdita_SEO_Author::person_node( (int) $post->post_author, $identity_id );
				if ( $person ) {
					$article['author']                  = array( '@id' => $person['@id'] );
					$people[ (int) $post->post_author ] = $person;
				}
				if ( '' !== $image['url'] ) {
					$article['image'] = array( '@id' => $image_id );
				}
				$words = Perdita_SEO_Variables::plain( (string) $post->post_content );
				if ( '' !== $words ) {
					$article['wordCount'] = count( preg_split( '/\s+/u', $words, -1, PREG_SPLIT_NO_EMPTY ) );
				}
				$section = Perdita_SEO_Variables::primary_term( $post );
				if ( $section ) {
					$article['articleSection'] = (string) $section->name;
				}
				$keywords = Perdita_SEO_Variables::term_names( $post, 'post_tag' );
				if ( $keywords ) {
					$article['keywords'] = implode( ', ', $keywords );
				}
				$graph[] = $article;
			}
		}
		foreach ( $people as $person ) {
			$graph[] = $person;
		}

		/**
		 * The JSON-LD graph before it prints: a list of node arrays. Add a
		 * node, change one, or drop one. Nodes reference each other by @id.
		 *
		 * @param array $graph Nodes.
		 * @param array $ctx   View context.
		 */
		$graph = (array) apply_filters( 'perdita_seo_json_ld', $graph, $ctx );
		$graph = array_values( array_filter( $graph, 'is_array' ) );
		if ( ! $graph ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG neutralizes </script>.
	}

	/**
	 * The WebPage subtype for a view.
	 *
	 * @param array $ctx View context.
	 * @return string
	 */
	private function webpage_type( array $ctx ) {
		switch ( $ctx['type'] ) {
			case 'search':
				return 'SearchResultsPage';
			case 'archive':
			case 'home':
				return 'CollectionPage';
			case 'singular':
			case 'front':
				$post = $ctx['post'];
				if ( $post instanceof WP_Post && 'page' === $post->post_type ) {
					if ( in_array( $post->post_name, array( 'about', 'about-us', 'about-me' ), true ) ) {
						return 'AboutPage';
					}
					if ( in_array( $post->post_name, array( 'contact', 'contact-us' ), true ) ) {
						return 'ContactPage';
					}
				}
				return 'WebPage';
		}
		return 'WebPage';
	}
}
