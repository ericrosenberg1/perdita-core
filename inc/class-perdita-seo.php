<?php
/**
 * SEO output. Renders titles, meta description, canonical, robots, Open Graph,
 * Twitter cards, and JSON-LD from the Perdita SEO store. Steps aside if a
 * dedicated SEO plugin is active or if the user turned Perdita SEO off.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_SEO {

	/**
	 * SEO store.
	 *
	 * @var Perdita_SEO_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Perdita_SEO_Store $store SEO store.
	 */
	public function __construct( Perdita_SEO_Store $store ) {
		$this->store = $store;

		if ( ! $store->get( 'enabled', true ) || self::other_seo_active() ) {
			return;
		}

		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_filter( 'document_title_separator', array( $this, 'separator' ) );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
	}

	/**
	 * Detect common SEO plugins so we never double up tags.
	 *
	 * @return bool
	 */
	public static function other_seo_active() {
		return defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' );
	}

	/**
	 * Apply the title template (and homepage title).
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		if ( ( is_front_page() || is_home() ) && '' !== trim( (string) $this->store->get( 'home_title' ) ) ) {
			return array( 'title' => trim( (string) $this->store->get( 'home_title' ) ) );
		}
		$template = (string) $this->store->get( 'title_template' );
		if ( '' === $template || ! isset( $parts['title'] ) ) {
			return $parts;
		}
		$built = strtr(
			$template,
			array(
				'%title%'    => $parts['title'],
				'%sitename%' => get_bloginfo( 'name' ),
				'%sep%'      => (string) $this->store->get( 'separator', '-' ),
			)
		);
		return array( 'title' => trim( $built ) );
	}

	/**
	 * Title separator.
	 *
	 * @return string
	 */
	public function separator() {
		return (string) $this->store->get( 'separator', '-' );
	}

	/**
	 * Best description for the current view.
	 *
	 * @return string
	 */
	private function description() {
		if ( ( is_front_page() || is_home() ) && ! is_paged() ) {
			$home = trim( (string) $this->store->get( 'home_description' ) );
			if ( '' !== $home ) {
				return $home;
			}
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post && '' !== $post->post_excerpt ) {
				return wp_strip_all_tags( $post->post_excerpt );
			}
			if ( $post ) {
				return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );
			}
		}
		return (string) $this->store->get( 'default_description' );
	}

	/**
	 * Should the current view be noindexed?
	 *
	 * @return bool
	 */
	private function is_noindex() {
		$n = $this->store->get( 'noindex', array() );
		if ( is_search() ) {
			return ! empty( $n['search'] );
		}
		if ( is_date() ) {
			return ! empty( $n['archive_date'] );
		}
		if ( is_author() ) {
			return ! empty( $n['archive_author'] );
		}
		if ( is_tag() ) {
			return ! empty( $n['archive_tag'] );
		}
		if ( is_category() ) {
			return ! empty( $n['archive_category'] );
		}
		if ( is_post_type_archive() ) {
			return ! empty( $n['archive_post_type'] );
		}
		return false;
	}

	/**
	 * Best canonical/og URL for the current view, for every archive type and
	 * for either permalink structure.
	 *
	 * @return string
	 */
	private function current_url() {
		if ( is_singular() ) {
			$url  = get_permalink();
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
		// populates under pretty permalinks -- the reason every archive type
		// (category, tag, author, date, CPT) used to get no canonical at all
		// on a Plain-permalinks site, and the reason a paginated home/front
		// page still canonicalized to page 1. This is the same machinery
		// paginate_links()/the_posts_pagination() rely on, so it's correct
		// for any archive type under either permalink structure.
		$paged = (int) get_query_var( 'paged' );
		return (string) get_pagenum_link( max( 1, $paged ), false );
	}

	/**
	 * Resolve the og:image for the current view.
	 *
	 * @return string
	 */
	private function image() {
		if ( is_singular() && has_post_thumbnail() ) {
			return (string) get_the_post_thumbnail_url( null, 'large' );
		}
		return $this->store->image_url();
	}

	/**
	 * Output head tags.
	 */
	public function head() {
		$desc  = $this->description();
		$title = wp_get_document_title();
		$url   = $this->current_url();
		$img   = $this->image();

		echo "\n<!-- Perdita SEO -->\n";
		if ( $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
		if ( $this->is_noindex() ) {
			echo '<meta name="robots" content="noindex,follow" />' . "\n";
		}

		// Open Graph.
		printf( '<meta property="og:type" content="%s" />' . "\n", is_singular() ? 'article' : 'website' );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( $desc ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( $url ) {
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		}
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		if ( $img ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $img ) );
		}

		// Twitter.
		printf( '<meta name="twitter:card" content="%s" />' . "\n", $img ? esc_attr( (string) $this->store->get( 'twitter_card', 'summary_large_image' ) ) : 'summary' );
		$handle = $this->twitter_handle();
		if ( $handle ) {
			printf( '<meta name="twitter:site" content="@%s" />' . "\n", esc_attr( $handle ) );
		}

		$this->json_ld( $title, $desc, $url, $img );
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

	/**
	 * Emit JSON-LD for the site identity plus the current article.
	 *
	 * @param string $title Title.
	 * @param string $desc  Description.
	 * @param string $url   URL.
	 * @param string $img   Image URL.
	 */
	private function json_ld( $title, $desc, $url, $img ) {
		$identity = array(
			'@type' => $this->store->get( 'schema_type', 'Organization' ),
			'@id'   => home_url( '/#identity' ),
			'name'  => $this->store->get( 'org_name' ) ? $this->store->get( 'org_name' ) : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		$logo = $this->store->logo_url();
		if ( $logo ) {
			$identity['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}
		$same = $this->store->same_as();
		if ( $same ) {
			$identity['sameAs'] = $same;
		}

		$graph = array(
			$identity,
			array(
				'@type' => 'WebSite',
				'@id'   => home_url( '/#website' ),
				'url'   => home_url( '/' ),
				'name'  => get_bloginfo( 'name' ),
			),
		);

		if ( is_singular( 'post' ) ) {
			$article = array(
				'@type'         => 'Article',
				'headline'      => $title,
				'description'   => $desc,
				'url'           => $url,
				'datePublished' => get_the_date( 'c' ),
				'dateModified'  => get_the_modified_date( 'c' ),
				'author'        => array(
					'@type' => 'Person',
					'name'  => get_the_author(),
				),
			);
			if ( $img ) {
				$article['image'] = $img;
			}
			$graph[] = $article;
		}

		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
		) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG neutralizes </script>.
	}
}
