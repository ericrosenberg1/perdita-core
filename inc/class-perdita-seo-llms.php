<?php
/**
 * /llms.txt and /llms-full.txt. The index lists the site's public content
 * with a one-line summary per item so an AI crawler can find the right page.
 * The full variant inlines post bodies and is off unless switched on.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt and /llms-full.txt for AI crawlers.
 */
class Perdita_SEO_Llms {

	const QUERY_VAR      = 'perdita_llms';
	const TRANSIENT      = 'perdita_seo_llms_txt';
	const TRANSIENT_FULL = 'perdita_seo_llms_full_txt';
	const RULES_OPTION   = 'perdita_seo_llms_rules';
	const RULES_VERSION  = '1';
	const TTL            = 12 * HOUR_IN_SECONDS;
	const INDEX_PER_TYPE = 200;
	const FULL_POSTS     = 100;
	const SUMMARY_LENGTH = 160;

	/**
	 * SEO store.
	 *
	 * @var Perdita_SEO_Store
	 */
	private $store;

	/**
	 * Register the rewrite, the query var, the responder, and the cache
	 * invalidation.
	 *
	 * @param Perdita_SEO_Store $store SEO store.
	 */
	public function __construct( Perdita_SEO_Store $store ) {
		$this->store = $store;
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::QUERY_VAR . '=full', 'top' );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rules' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
		add_action( 'save_post', array( $this, 'invalidate' ) );
		add_action( 'deleted_post', array( $this, 'invalidate' ) );
		add_action( 'update_option_blogname', array( $this, 'invalidate' ) );
		add_action( 'update_option_blogdescription', array( $this, 'invalidate' ) );
	}

	/**
	 * Register the query var the rewrite rule sets.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Flush the rewrite rules once, after every post type has registered
	 * (wp_loaded runs after init), so the new rule is in the stored set.
	 */
	public function maybe_flush_rules() {
		if ( self::RULES_VERSION === (string) get_option( self::RULES_OPTION ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::RULES_OPTION, self::RULES_VERSION );
	}

	/**
	 * Answer /llms.txt and /llms-full.txt. Works from the query var under
	 * pretty permalinks and from the request path otherwise.
	 */
	public function serve() {
		$which = $this->requested();
		if ( '' === $which ) {
			return;
		}
		$enabled = 'full' === $which ? (bool) $this->store->get( 'llms_full_txt' ) : (bool) $this->store->get( 'llms_txt', true );
		if ( ! $enabled ) {
			return;
		}
		$body = $this->content( 'full' === $which );
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain body built from plain-text fields, tags already stripped.
		exit;
	}

	/**
	 * Which file the request is for: index, full, or empty.
	 *
	 * @return string
	 */
	public function requested() {
		$var = (string) get_query_var( self::QUERY_VAR );
		if ( in_array( $var, array( 'index', 'full' ), true ) ) {
			return $var;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against two fixed paths below, never echoed.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$rel  = trim( $path, '/' );
		if ( '' !== $home && 0 === strpos( $rel, $home . '/' ) ) {
			$rel = substr( $rel, strlen( $home ) + 1 );
		}
		if ( 'llms.txt' === $rel ) {
			return 'index';
		}
		if ( 'llms-full.txt' === $rel ) {
			return 'full';
		}
		return '';
	}

	/**
	 * Cached body, building it when the transient is cold.
	 *
	 * @param bool $full Whether to inline post bodies.
	 * @return string
	 */
	public function content( $full = false ) {
		$key  = $full ? self::TRANSIENT_FULL : self::TRANSIENT;
		$body = get_transient( $key );
		if ( is_string( $body ) && '' !== $body ) {
			return $body;
		}
		$body = $full ? $this->build_full() : $this->build_index();
		set_transient( $key, $body, self::TTL );
		return $body;
	}

	/**
	 * Drop both caches. Hooked to save_post and deleted_post.
	 */
	public function invalidate() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT_FULL );
	}

	/**
	 * The index: site name, tagline, description, a section per public post
	 * type with up to INDEX_PER_TYPE recent items, and the sitemap.
	 *
	 * @return string
	 */
	public function build_index() {
		$lines   = array();
		$lines[] = '# ' . $this->plain( get_bloginfo( 'name' ) );
		$tagline = $this->plain( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		$about = $this->plain( (string) $this->store->get( 'home_description' ) );
		if ( '' !== $about ) {
			$lines[] = '';
			$lines[] = $about;
		}
		foreach ( $this->post_types() as $type ) {
			$posts = $this->recent( $type->name, self::INDEX_PER_TYPE );
			if ( ! $posts ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $this->plain( (string) $type->labels->name );
			$lines[] = '';
			foreach ( $posts as $post ) {
				$summary = Perdita_SEO_Variables::trim_chars( Perdita_SEO_Variables::plain( '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content ), self::SUMMARY_LENGTH );
				$line    = '- [' . $this->plain( get_the_title( $post ) ) . '](' . esc_url_raw( (string) get_permalink( $post ) ) . ')';
				if ( '' !== $summary ) {
					$line .= ': ' . $summary;
				}
				$lines[] = $line;
			}
		}
		$lines[] = '';
		$lines[] = '## Sitemaps';
		$lines[] = '';
		$lines[] = '- [Sitemap](' . esc_url_raw( $this->sitemap_url() ) . ')';
		$lines[] = '';

		/**
		 * Adjust the finished llms.txt body.
		 *
		 * @param string $body Body text.
		 */
		return (string) apply_filters( 'perdita_seo_llms_txt', implode( "\n", $lines ) );
	}

	/**
	 * The full variant: the index header, then up to FULL_POSTS posts with
	 * their plain-text body.
	 *
	 * @return string
	 */
	public function build_full() {
		$lines   = array();
		$lines[] = '# ' . $this->plain( get_bloginfo( 'name' ) );
		$tagline = $this->plain( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		foreach ( $this->recent( 'post', self::FULL_POSTS ) as $post ) {
			$lines[] = '';
			$lines[] = '## ' . $this->plain( get_the_title( $post ) );
			$lines[] = '';
			$lines[] = 'URL: ' . esc_url_raw( (string) get_permalink( $post ) );
			$lines[] = 'Published: ' . get_the_date( 'Y-m-d', $post );
			$lines[] = '';
			$lines[] = Perdita_SEO_Variables::plain( (string) $post->post_content );
		}
		$lines[] = '';

		/**
		 * Adjust the finished llms-full.txt body.
		 *
		 * @param string $body Body text.
		 */
		return (string) apply_filters( 'perdita_seo_llms_full_txt', implode( "\n", $lines ) );
	}

	/**
	 * Public post types that get a section, attachments excluded.
	 *
	 * @return WP_Post_Type[]
	 */
	private function post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		/**
		 * Post types listed in llms.txt.
		 *
		 * @param WP_Post_Type[] $types Post type objects keyed by slug.
		 */
		return (array) apply_filters( 'perdita_seo_llms_post_types', $types );
	}

	/**
	 * Recent published items of one type, newest first, without touching
	 * the post cache for the whole set of meta and terms.
	 *
	 * @param string $type  Post type.
	 * @param int    $limit Maximum.
	 * @return WP_Post[]
	 */
	private function recent( $type, $limit ) {
		$q = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => (int) $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'has_password'           => false,
			)
		);
		return $q->posts;
	}

	/**
	 * The sitemap index URL: core's unless the sitemap module or a plugin
	 * changed it through the filter.
	 *
	 * @return string
	 */
	private function sitemap_url() {
		$url = function_exists( 'get_sitemap_url' ) ? (string) get_sitemap_url( 'index' ) : home_url( '/wp-sitemap.xml' );
		if ( '' === $url ) {
			$url = home_url( '/wp-sitemap.xml' );
		}
		/**
		 * The sitemap URL named in llms.txt.
		 *
		 * @param string $url Sitemap URL.
		 */
		return (string) apply_filters( 'perdita_seo_sitemap_url', $url );
	}

	/**
	 * One-line plain text, safe inside a Markdown line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function plain( $text ) {
		$text = Perdita_SEO_Variables::plain( (string) $text );
		return str_replace( array( '[', ']' ), array( '(', ')' ), $text );
	}
}
