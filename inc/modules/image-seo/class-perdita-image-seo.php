<?php
/**
 * Image SEO: alt text that writes itself, and an image sitemap.
 *
 * Two jobs, one module. The first is alt text. An image uploaded with no alt
 * gets one built from a template, which defaults to the filename with the
 * dashes and underscores turned back into spaces, the extension dropped, and
 * any digit-only segment (a camera's DSC_0421, a date stamp) thrown away. The
 * same template runs as a batch over the images already in the library, 100 at
 * a time, so a site with 4,000 photos is a few clicks rather than a plugin
 * that hammers the database in one request.
 *
 * The second is /image-sitemap.xml: one url entry per published post that has
 * images, listing the featured image and every img src in the content, in the
 * namespace Google reads. It is cached for 12 hours and dropped whenever a
 * post is saved. No rewrite rule, so nothing has to be flushed.
 *
 * Generated alt text is a floor, not a ceiling. It beats an empty alt
 * attribute for a screen reader and for image search, and a human writing a
 * real description beats both.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Image_SEO {

	/**
	 * Settings option.
	 */
	const OPTION = 'perdita_image_seo';

	/**
	 * The alt text meta key WordPress itself reads.
	 */
	const ALT_META = '_wp_attachment_image_alt';

	/**
	 * Transient holding the rendered image sitemap.
	 */
	const SITEMAP_TRANSIENT = 'perdita_image_sitemap';

	/**
	 * How long the sitemap is cached.
	 */
	const SITEMAP_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Most url entries (posts) in the sitemap.
	 */
	const SITEMAP_MAX_URLS = 1000;

	/**
	 * Most images listed under one url entry. Google's own ceiling.
	 */
	const SITEMAP_MAX_IMAGES = 1000;

	/**
	 * Attachments processed per batch round.
	 */
	const BATCH_SIZE = 100;

	/**
	 * The path the sitemap is served at, relative to the home url.
	 */
	const SITEMAP_PATH = '/image-sitemap.xml';

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

		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_sitemap' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );
		add_action( 'save_post', array( $this, 'flush_sitemap' ) );
		add_action( 'deleted_post', array( $this, 'flush_sitemap' ) );
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
			'auto_alt'          => true,
			'alt_format'        => '%filename%',
			'auto_title'        => false,
			'title_format'      => '%filename%',
			'strip_punctuation' => true,
			'lowercase'         => false,
		);
	}

	/**
	 * Stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
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
			'auto_alt'          => ! empty( $input['auto_alt'] ),
			'alt_format'        => isset( $input['alt_format'] ) && '' !== trim( (string) $input['alt_format'] ) ? sanitize_text_field( (string) $input['alt_format'] ) : $d['alt_format'],
			'auto_title'        => ! empty( $input['auto_title'] ),
			'title_format'      => isset( $input['title_format'] ) && '' !== trim( (string) $input['title_format'] ) ? sanitize_text_field( (string) $input['title_format'] ) : $d['title_format'],
			'strip_punctuation' => ! empty( $input['strip_punctuation'] ),
			'lowercase'         => ! empty( $input['lowercase'] ),
		);
	}

	/* ==========================================================
	 * Alt text
	 * ========================================================== */

	/**
	 * A filename turned back into words: no directory, no extension, dashes
	 * and underscores as spaces, digit-only segments dropped. Falls back to
	 * the bare basename when dropping the numbers would leave nothing, which
	 * is what a file called 20240711-1200.jpg does.
	 *
	 * @param string $filename File name or path.
	 * @return string
	 */
	public static function words_from_filename( $filename ) {
		$base = wp_basename( (string) $filename );
		$base = (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $base );
		$base = str_replace( array( '-', '_', '+', '.' ), ' ', $base );
		$base = trim( (string) preg_replace( '/\s+/', ' ', $base ) );
		if ( '' === $base ) {
			return '';
		}

		$kept = array();
		foreach ( explode( ' ', $base ) as $segment ) {
			if ( '' !== $segment && ! preg_match( '/^\d+$/', $segment ) ) {
				$kept[] = $segment;
			}
		}

		return $kept ? implode( ' ', $kept ) : $base;
	}

	/**
	 * Fill a template's variables for one attachment.
	 *
	 * Recognized: %filename%, %post_title% (the parent post, empty when the
	 * image is unattached), %sitename%.
	 *
	 * @param string      $format     Template.
	 * @param int|WP_Post $attachment Attachment.
	 * @return string
	 */
	public static function apply_format( $format, $attachment ) {
		$post = get_post( $attachment );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$s = self::settings();

		$file        = get_post_meta( $post->ID, '_wp_attached_file', true );
		$file        = $file ? (string) $file : (string) $post->post_name;
		$parent      = $post->post_parent ? get_post( $post->post_parent ) : null;
		$replacement = array(
			'%filename%'   => self::words_from_filename( $file ),
			'%post_title%' => $parent instanceof WP_Post ? wp_strip_all_tags( (string) get_the_title( $parent ) ) : '',
			'%sitename%'   => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
		);

		$out = strtr( (string) $format, $replacement );

		if ( ! empty( $s['strip_punctuation'] ) ) {
			$out = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $out );
		}
		if ( ! empty( $s['lowercase'] ) ) {
			$out = function_exists( 'mb_strtolower' ) ? mb_strtolower( $out, 'UTF-8' ) : strtolower( $out );
		}

		$out = trim( (string) preg_replace( '/\s+/', ' ', $out ) );

		/**
		 * The generated alt text or title for one attachment, after the
		 * template and the punctuation and case options have run.
		 *
		 * @param string  $out    Generated text.
		 * @param string  $format The template it came from.
		 * @param WP_Post $post   The attachment.
		 */
		return (string) apply_filters( 'perdita_image_seo_text', $out, $format, $post );
	}

	/**
	 * The alt text a template would produce for an attachment.
	 *
	 * @param int|WP_Post $attachment Attachment.
	 * @return string
	 */
	public static function generate_alt( $attachment ) {
		return self::apply_format( self::settings()['alt_format'], $attachment );
	}

	/**
	 * Whether an attachment is an image this module handles.
	 *
	 * @param int|WP_Post $attachment Attachment.
	 * @return bool
	 */
	public static function is_image( $attachment ) {
		$post = get_post( $attachment );
		return $post instanceof WP_Post && 'attachment' === $post->post_type && 0 === strpos( (string) $post->post_mime_type, 'image/' );
	}

	/**
	 * Whether an attachment's alt text is missing.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function alt_is_missing( $attachment_id ) {
		return '' === trim( (string) get_post_meta( (int) $attachment_id, self::ALT_META, true ) );
	}

	/**
	 * Write generated alt text to an attachment that has none.
	 *
	 * @param int  $attachment_id Attachment id.
	 * @param bool $force         Overwrite alt text that is already there.
	 * @return string The alt text now stored, empty when nothing was written.
	 */
	public static function fill_alt( $attachment_id, $force = false ) {
		$attachment_id = (int) $attachment_id;
		if ( ! self::is_image( $attachment_id ) ) {
			return '';
		}
		if ( ! $force && ! self::alt_is_missing( $attachment_id ) ) {
			return '';
		}
		$alt = self::generate_alt( $attachment_id );
		if ( '' === $alt ) {
			return '';
		}
		update_post_meta( $attachment_id, self::ALT_META, $alt );
		return $alt;
	}

	/**
	 * A freshly uploaded image: fill the alt text, and the title too when the
	 * title is still the bare filename WordPress derived from the upload.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function on_add_attachment( $attachment_id ) {
		$s = self::settings();
		if ( ! self::is_image( $attachment_id ) ) {
			return;
		}

		if ( ! empty( $s['auto_alt'] ) ) {
			self::fill_alt( $attachment_id );
		}

		if ( empty( $s['auto_title'] ) ) {
			return;
		}
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || ! self::title_is_raw_filename( $post ) ) {
			return;
		}
		$title = self::apply_format( $s['title_format'], $post );
		if ( '' === $title || $title === $post->post_title ) {
			return;
		}
		wp_update_post(
			array(
				'ID'         => (int) $attachment_id,
				'post_title' => $title,
			)
		);
	}

	/**
	 * Whether an attachment's title is still the one WordPress derives from
	 * the uploaded file, rather than something a person typed. WordPress
	 * sanitizes the filename into the title, so both the raw basename and
	 * that sanitized form count.
	 *
	 * @param WP_Post $post Attachment.
	 * @return bool
	 */
	public static function title_is_raw_filename( WP_Post $post ) {
		$file = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		if ( '' === $file ) {
			return true;
		}
		$base  = wp_basename( $file );
		$noext = (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $base );
		$title = (string) $post->post_title;

		return '' === trim( $title )
			|| $title === $base
			|| $title === $noext
			|| $title === sanitize_title( $noext )
			|| $title === str_replace( array( '-', '_' ), ' ', $noext );
	}

	/* ==========================================================
	 * Batch fill
	 * ========================================================== */

	/**
	 * Ids of image attachments with no alt text.
	 *
	 * @param int $limit How many to return.
	 * @return int[]
	 */
	public static function ids_missing_alt( $limit = self::BATCH_SIZE ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => max( 1, (int) $limit ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_meta_query -- an admin-only maintenance query, run on demand, capped at BATCH_SIZE rows.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => self::ALT_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::ALT_META,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * How many images are still missing alt text. Asks the database for the
	 * count rather than the rows, so a library with thousands of photos costs
	 * one COUNT query and not thousands of ids in memory.
	 *
	 * @return int
	 */
	public static function count_missing_alt() {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_meta_query -- an admin-only maintenance count, run on demand from one screen.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => self::ALT_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::ALT_META,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Fill alt text for up to $limit images that have none.
	 *
	 * "skipped" counts images whose template produced nothing, which stay
	 * missing however many rounds are run. The admin screen stops offering
	 * another round once a round fills nothing, so those cannot loop.
	 *
	 * @param int $limit How many to do this round.
	 * @return array array( 'filled' => int, 'skipped' => int, 'remaining' => int )
	 */
	public static function fill_missing_alt( $limit = self::BATCH_SIZE ) {
		$ids     = self::ids_missing_alt( $limit );
		$filled  = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			if ( '' !== self::fill_alt( $id ) ) {
				++$filled;
			} else {
				++$skipped;
			}
		}
		return array(
			'filled'    => $filled,
			'skipped'   => $skipped,
			'remaining' => self::count_missing_alt(),
		);
	}

	/* ==========================================================
	 * Image sitemap
	 * ========================================================== */

	/**
	 * The public URL of the image sitemap.
	 *
	 * @return string
	 */
	public static function sitemap_url() {
		return home_url( self::SITEMAP_PATH );
	}

	/**
	 * Whether a request URI asks for the image sitemap. Pure, so it can be
	 * tested with a made-up URI. Subdirectory installs are handled by
	 * comparing against the home url's own path.
	 *
	 * @param string $uri Request URI.
	 * @return bool
	 */
	public static function sitemap_requested( $uri ) {
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return ( $base . self::SITEMAP_PATH ) === $path;
	}

	/**
	 * Serve the sitemap when the request asks for it. Hooked to
	 * template_redirect at priority 0 so it needs no rewrite rule and nothing
	 * has to be flushed.
	 */
	public function maybe_serve_sitemap() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || ! self::sitemap_requested( $uri ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sitemap_xml() escapes every url and title with esc_url() and esc_xml() as it builds the document.
		echo self::sitemap_xml();
		exit;
	}

	/**
	 * The image sitemap, from the transient when it is warm.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return string
	 */
	public static function sitemap_xml( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::SITEMAP_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$xml = self::build_sitemap_xml();
		set_transient( self::SITEMAP_TRANSIENT, $xml, self::SITEMAP_TTL );
		return $xml;
	}

	/**
	 * Build the sitemap document.
	 *
	 * @return string
	 */
	public static function build_sitemap_xml() {
		$types = array_values( array_diff( array_keys( (array) get_post_types( array( 'public' => true ), 'names' ) ), array( 'attachment' ) ) );

		$query = new WP_Query(
			array(
				'post_type'              => $types ? $types : array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::SITEMAP_MAX_URLS,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
			)
		);

		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ( $query->posts as $post ) {
			if ( 'attachment' === $post->post_type ) {
				continue;
			}
			$images = self::images_for_post( $post );
			if ( empty( $images ) ) {
				continue;
			}
			$out .= "\t<url>\n\t\t<loc>" . esc_url( (string) get_permalink( $post ) ) . "</loc>\n";
			foreach ( $images as $image ) {
				$out .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( $image['url'] ) . "</image:loc>\n";
				if ( '' !== $image['title'] ) {
					$out .= "\t\t\t<image:title>" . self::xml( $image['title'] ) . "</image:title>\n";
				}
				if ( '' !== $image['caption'] ) {
					$out .= "\t\t\t<image:caption>" . self::xml( $image['caption'] ) . "</image:caption>\n";
				}
				$out .= "\t\t</image:image>\n";
			}
			$out .= "\t</url>\n";
		}

		$out .= '</urlset>';
		return $out;
	}

	/**
	 * Escape a string for an XML text node. esc_xml() landed in WP 5.5 and
	 * the floor here is 6.4, so it is always there. The wrapper exists so
	 * every text node in the document goes through one place.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function xml( $text ) {
		return esc_xml( (string) $text );
	}

	/**
	 * The images one post contributes to the sitemap: its featured image,
	 * then every img src in the content, deduplicated and capped.
	 *
	 * @param WP_Post $post Post.
	 * @return array[] Each array( 'url' => string, 'title' => string, 'caption' => string ).
	 */
	public static function images_for_post( WP_Post $post ) {
		$images = array();

		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$url = wp_get_attachment_url( $thumb_id );
			if ( $url ) {
				$images[ $url ] = array(
					'url'     => (string) $url,
					'title'   => wp_strip_all_tags( (string) get_the_title( $thumb_id ) ),
					'caption' => wp_strip_all_tags( (string) wp_get_attachment_caption( $thumb_id ) ),
				);
			}
		}

		foreach ( self::img_srcs( (string) $post->post_content ) as $url ) {
			if ( ! isset( $images[ $url ] ) ) {
				$images[ $url ] = array(
					'url'     => $url,
					'title'   => '',
					'caption' => '',
				);
			}
			if ( count( $images ) >= self::SITEMAP_MAX_IMAGES ) {
				break;
			}
		}

		return array_values( $images );
	}

	/**
	 * Absolute http(s) src values from the img tags in a blob of HTML, in
	 * document order, deduplicated. A root-relative src is resolved against
	 * the site's home url. Anything else (a data: URI, a protocol the browser
	 * would not fetch as an image) is dropped.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function img_srcs( $html ) {
		if ( '' === trim( $html ) || false === stripos( $html, '<img' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return array();
		}

		$home = home_url( '/' );
		$out  = array();
		foreach ( $matches[1] as $src ) {
			$src = trim( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				continue;
			}
			if ( 0 === strpos( $src, '//' ) ) {
				$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
			} elseif ( 0 === strpos( $src, '/' ) ) {
				$src = rtrim( $home, '/' ) . $src;
			}
			$src = esc_url_raw( $src );
			if ( '' === $src ) {
				continue;
			}
			$scheme = (string) wp_parse_url( $src, PHP_URL_SCHEME );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}
			$out[ $src ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Drop the cached sitemap.
	 */
	public function flush_sitemap() {
		delete_transient( self::SITEMAP_TRANSIENT );
	}

	/**
	 * Add the image sitemap to robots.txt.
	 *
	 * @param string $output The robots.txt body so far.
	 * @param bool   $public Whether the site is set to be indexed.
	 * @return string
	 */
	public function robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$line = 'Sitemap: ' . self::sitemap_url();
		if ( false !== strpos( (string) $output, $line ) ) {
			return $output;
		}
		return rtrim( (string) $output, "\n" ) . "\n" . $line . "\n";
	}
}
