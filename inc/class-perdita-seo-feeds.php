<?php
/**
 * Feed controls: text before and after every feed item, an off switch for
 * the comments feed, and an off switch for every feed.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feed item footers and the feed off switches.
 */
class Perdita_SEO_Feeds {

	/**
	 * SEO store.
	 *
	 * @var Perdita_SEO_Store
	 */
	private $store;

	/**
	 * Hook the feed filters. Every callback checks its setting itself, so a
	 * setting changed later in the request (the tests do this) still applies.
	 *
	 * @param Perdita_SEO_Store $store SEO store.
	 */
	public function __construct( Perdita_SEO_Store $store ) {
		$this->store = $store;
		add_filter( 'the_content_feed', array( $this, 'wrap' ) );
		add_filter( 'the_excerpt_rss', array( $this, 'wrap' ) );
		add_filter( 'feed_links_show_comments_feed', array( $this, 'show_comments_feed_links' ) );
		add_filter( 'feed_links_show_posts_feed', array( $this, 'show_posts_feed_links' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Add the before and after text around a feed item.
	 *
	 * @param string $content Item content.
	 * @return string
	 */
	public function wrap( $content ) {
		$before = trim( (string) $this->store->get( 'rss_before' ) );
		$after  = trim( (string) $this->store->get( 'rss_after' ) );
		if ( '' === $before && '' === $after ) {
			return $content;
		}
		$post = get_post();
		if ( '' !== $before ) {
			$content = wpautop( $this->fill( $before, $post ) ) . $content;
		}
		if ( '' !== $after ) {
			$content .= wpautop( $this->fill( $after, $post ) );
		}
		return $content;
	}

	/**
	 * Replace the %%TOKEN%% placeholders in a feed footer template.
	 *
	 * @param string       $template Template.
	 * @param WP_Post|null $post     Current item.
	 * @return string
	 */
	public function fill( $template, $post = null ) {
		$post      = $post instanceof WP_Post ? $post : get_post();
		$blog_name = get_bloginfo( 'name' );
		$blog_desc = get_bloginfo( 'description' );
		$blog_url  = home_url( '/' );
		$map       = array(
			'%%BLOGLINK%%'   => '<a href="' . esc_url( $blog_url ) . '">' . esc_html( $blog_name ) . '</a>',
			'%%BLOGTITLE%%'  => esc_html( $blog_name ),
			'%%BLOGDESC%%'   => esc_html( $blog_desc ),
			'%%POSTLINK%%'   => '',
			'%%POSTTITLE%%'  => '',
			'%%AUTHORLINK%%' => '',
		);
		if ( $post instanceof WP_Post ) {
			$title                 = get_the_title( $post );
			$map['%%POSTLINK%%']   = '<a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a>';
			$map['%%POSTTITLE%%']  = esc_html( $title );
			$author                = get_the_author_meta( 'display_name', (int) $post->post_author );
			$map['%%AUTHORLINK%%'] = '<a href="' . esc_url( get_author_posts_url( (int) $post->post_author ) ) . '">' . esc_html( $author ) . '</a>';
		}
		return strtr( (string) $template, $map );
	}

	/**
	 * Drop the comments feed link from the head when that feed is off.
	 *
	 * @param bool $show Whether core would print it.
	 * @return bool
	 */
	public function show_comments_feed_links( $show ) {
		if ( $this->store->get( 'rss_disable_all' ) || $this->store->get( 'rss_disable_comments_feed' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Drop the posts feed link from the head when every feed is off.
	 *
	 * @param bool $show Whether core would print it.
	 * @return bool
	 */
	public function show_posts_feed_links( $show ) {
		return $this->store->get( 'rss_disable_all' ) ? false : $show;
	}

	/**
	 * Send a disabled feed request to the home page (or, for a post's own
	 * comments feed, to the post) with a 301.
	 */
	public function maybe_redirect() {
		if ( ! is_feed() ) {
			return;
		}
		$target = $this->redirect_target();
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Where a feed request should go, or empty when it may be served.
	 * Public so the tests can check the decision without a redirect.
	 *
	 * @return string
	 */
	public function redirect_target() {
		if ( ! is_feed() ) {
			return '';
		}
		$disable_all      = (bool) $this->store->get( 'rss_disable_all' );
		$disable_comments = (bool) $this->store->get( 'rss_disable_comments_feed' );

		if ( is_comment_feed() && ( $disable_all || $disable_comments ) ) {
			return is_singular() ? (string) get_permalink() : home_url( '/' );
		}
		if ( ! $disable_all ) {
			return '';
		}
		// Sitemaps never answer is_feed(), but a rewritten /sitemap.xml
		// aliased onto a feed by another plugin would, so keep them out.
		if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return '';
		}
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;

		/**
		 * Post types whose feeds keep working when every feed is switched
		 * off. A podcast feed is the usual reason.
		 *
		 * @param string[] $allowed Post type slugs.
		 */
		$allowed = (array) apply_filters( 'perdita_seo_allowed_feeds', array() );
		if ( '' !== $post_type && in_array( $post_type, $allowed, true ) ) {
			return '';
		}
		return home_url( '/' );
	}
}
