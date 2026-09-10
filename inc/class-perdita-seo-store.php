<?php
/**
 * SEO settings store (option `perdita_seo`).
 *
 * Kept separate from the design token document so the SEO subsystem can grow
 * without bloating the design schema the AI edits. Holds site-wide title and
 * meta templates (global, per post type, per taxonomy), robots directives,
 * linked social profiles, default social images, schema identity, webmaster
 * verification tokens, the robots.txt override, feed controls, and the
 * llms.txt toggles. Includes best-effort import of global settings from
 * Yoast, All in One SEO, and Genesis.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes, and writes the perdita_seo settings option.
 */
class Perdita_SEO_Store {

	const OPTION = 'perdita_seo';

	/**
	 * Cached document.
	 *
	 * @var array|null
	 */
	private $doc = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enabled'                   => true,
			'separator'                 => '-',
			'title_template'            => '%title% %sep% %sitename%',
			'home_title'                => '',
			'home_description'          => '',
			'default_description'       => '',
			'default_image_id'          => 0,
			'twitter_card'              => 'summary_large_image',
			'schema_type'               => 'Organization',
			'org_name'                  => '',
			'org_logo_id'               => 0,
			'facebook_app_id'           => '',
			'social'                    => array(
				'twitter'   => '',
				'facebook'  => '',
				'instagram' => '',
				'linkedin'  => '',
				'youtube'   => '',
			),
			'noindex'                   => array(
				'archive_date'      => true,
				'archive_author'    => false,
				'archive_tag'       => false,
				'archive_category'  => false,
				'archive_post_type' => false,
				'search'            => true,
			),
			// Robots directives (Perdita_SEO::robots() feeds these into core's
			// wp_robots filter). -1 on the two counters means no limit.
			'noindex_paginated'         => false,
			'attachments'               => 'redirect',
			'max_snippet'               => -1,
			'max_image_preview'         => 'large',
			'max_video_preview'         => -1,
			// Per post type and per taxonomy overrides, keyed by slug, each a
			// partial array of title, description, noindex. Missing keys fall
			// back through post_type_settings() / taxonomy_settings().
			'post_types'                => array(),
			'taxonomies'                => array(),
			'archive_title'             => '%archive_title% %sep% %sitename%',
			'search_title'              => '%title% %sep% %sitename%',
			'404_title'                 => '%title% %sep% %sitename%',
			// Webmaster verification tokens, printed on the front page only.
			'verify_google'             => '',
			'verify_bing'               => '',
			'verify_pinterest'          => '',
			'verify_yandex'             => '',
			'verify_baidu'              => '',
			// robots.txt override. Empty means core's virtual file.
			'robots_txt'                => '',
			// Feed controls.
			'rss_before'                => '',
			'rss_after'                 => '',
			'rss_disable_comments_feed' => false,
			'rss_disable_all'           => false,
			// llms.txt index (on) and llms-full.txt body dump (off).
			'llms_txt'                  => true,
			'llms_full_txt'             => false,
		);
	}

	/**
	 * Per post type title/description/noindex, with the global title template
	 * and the excerpt as the fallbacks so an older site that only ever set
	 * title_template keeps exactly the titles it had.
	 *
	 * @param string $slug Post type slug.
	 * @return array title, description, noindex.
	 */
	public function post_type_settings( $slug ) {
		$row = $this->get( 'post_types.' . $slug, array() );
		$row = is_array( $row ) ? $row : array();
		return array(
			'title'       => isset( $row['title'] ) && '' !== trim( (string) $row['title'] ) ? (string) $row['title'] : (string) $this->get( 'title_template', '%title% %sep% %sitename%' ),
			'description' => isset( $row['description'] ) && '' !== trim( (string) $row['description'] ) ? (string) $row['description'] : '%excerpt%',
			'noindex'     => ! empty( $row['noindex'] ),
		);
	}

	/**
	 * Per taxonomy title/description/noindex with the term name and term
	 * description as the fallbacks.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return array title, description, noindex.
	 */
	public function taxonomy_settings( $slug ) {
		$row = $this->get( 'taxonomies.' . $slug, array() );
		$row = is_array( $row ) ? $row : array();
		return array(
			'title'       => isset( $row['title'] ) && '' !== trim( (string) $row['title'] ) ? (string) $row['title'] : '%term_title% %sep% %sitename%',
			'description' => isset( $row['description'] ) && '' !== trim( (string) $row['description'] ) ? (string) $row['description'] : '%term_description%',
			'noindex'     => ! empty( $row['noindex'] ),
		);
	}

	/**
	 * Sanitize a title or description template without losing its tokens.
	 * sanitize_text_field() strips anything that looks like a percent-encoded
	 * octet, and %category% starts with one (%ca), so it came back as
	 * "tegory%". This keeps the tag stripping and the UTF-8 check, collapses
	 * whitespace, and leaves percent signs alone.
	 *
	 * @param mixed $value Raw template.
	 * @return string
	 */
	public static function sanitize_template( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Reduce a pasted verification value to its token. Accepts the bare token
	 * or the full meta tag the console hands out, in which case the content
	 * attribute is what gets kept.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_verification( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $value, $m ) ) {
			$value = $m[1];
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Full document merged over defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->doc ) {
			$saved     = get_option( self::OPTION, array() );
			$this->doc = $this->merge_deep( $this->defaults(), is_array( $saved ) ? $saved : array() );
		}
		return $this->doc;
	}

	/**
	 * Get by dot path.
	 *
	 * @param string $path    Dot path.
	 * @param mixed  $default  Fallback.
	 * @return mixed
	 */
	public function get( $path, $default = null ) {
		$node = $this->all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( is_array( $node ) && array_key_exists( $key, $node ) ) {
				$node = $node[ $key ];
			} else {
				return $default;
			}
		}
		return $node;
	}

	/**
	 * Save a full (partial) document, merged over current. This is the single
	 * write boundary for the whole store: every field is sanitized by role
	 * here, so a caller (an admin form, the Yoast/AIOSEO importers, the
	 * Astra/Genesis migrator, or anything added later) can't land an
	 * unsanitized value just because it forgot to sanitize its own input.
	 * The admin-form callers already sanitize before calling this, so this is
	 * defense-in-depth for those and the actual fix for the importers/migrator,
	 * which didn't.
	 *
	 * @param array $patch Partial doc.
	 * @return bool
	 */
	public function save( array $patch ) {
		$this->doc = $this->merge_deep( $this->all(), $this->sanitize_patch( $patch ) );
		return update_option( self::OPTION, $this->doc );
	}

	/**
	 * Write to the option without the role-based sanitization in save().
	 * Used only by cached_attachment() for its internal attachment cache,
	 * whose shape is already fully controlled at the call site (id from an
	 * (int) cast, url and size from wp_get_attachment_image_src(), alt
	 * through sanitize_text_field()), never for anything derived from user,
	 * import, or migration input.
	 *
	 * @param array $patch Partial doc.
	 * @return bool
	 */
	private function save_raw( array $patch ) {
		$this->doc = $this->merge_deep( $this->all(), $patch );
		return update_option( self::OPTION, $this->doc );
	}

	/**
	 * Sanitize a (possibly partial, possibly nested) patch by field role
	 * before it's merged into the document. Mirrors
	 * Perdita_Settings::sanitize_leaf()'s role-based approach.
	 *
	 * @param array $patch Partial doc.
	 * @return array
	 */
	private function sanitize_patch( array $patch ) {
		$out = array();
		foreach ( $patch as $key => $value ) {
			if ( 'social' === $key && is_array( $value ) ) {
				$out['social'] = array();
				foreach ( $value as $sk => $sv ) {
					// Twitter/X accepts a bare @handle (see same_as()), so it
					// isn't forced through esc_url_raw() like the others.
					$out['social'][ $sk ] = 'twitter' === $sk ? sanitize_text_field( (string) $sv ) : esc_url_raw( (string) $sv );
				}
				continue;
			}
			if ( 'noindex' === $key && is_array( $value ) ) {
				$out['noindex'] = array();
				foreach ( $value as $nk => $nv ) {
					$out['noindex'][ $nk ] = (bool) $nv;
				}
				continue;
			}
			if ( in_array( $key, array( 'default_image_cache', 'org_logo_cache' ), true ) && is_array( $value ) ) {
				$out[ $key ] = array(
					'id'     => absint( $value['id'] ?? 0 ),
					'url'    => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
					'width'  => absint( $value['width'] ?? 0 ),
					'height' => absint( $value['height'] ?? 0 ),
					'alt'    => sanitize_text_field( (string) ( $value['alt'] ?? '' ) ),
				);
				continue;
			}
			if ( in_array( $key, array( 'post_types', 'taxonomies' ), true ) && is_array( $value ) ) {
				$out[ $key ] = array();
				foreach ( $value as $slug => $row ) {
					$slug = sanitize_key( (string) $slug );
					if ( '' === $slug || ! is_array( $row ) ) {
						continue;
					}
					$clean = array();
					if ( array_key_exists( 'title', $row ) ) {
						$clean['title'] = self::sanitize_template( $row['title'] );
					}
					if ( array_key_exists( 'description', $row ) ) {
						$clean['description'] = self::sanitize_template( $row['description'] );
					}
					if ( array_key_exists( 'noindex', $row ) ) {
						$clean['noindex'] = (bool) $row['noindex'];
					}
					$out[ $key ][ $slug ] = $clean;
				}
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue; // Reject anything else structural outright.
			}
			switch ( $key ) {
				case 'enabled':
				case 'noindex_paginated':
				case 'rss_disable_comments_feed':
				case 'rss_disable_all':
				case 'llms_txt':
				case 'llms_full_txt':
					$out[ $key ] = (bool) $value;
					break;
				case 'home_description':
				case 'default_description':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'title_template':
				case 'home_title':
				case 'archive_title':
				case 'search_title':
				case '404_title':
					$out[ $key ] = self::sanitize_template( $value );
					break;
				case 'schema_type':
					$out[ $key ] = in_array( $value, array( 'Organization', 'Person' ), true ) ? (string) $value : 'Organization';
					break;
				case 'twitter_card':
					$out[ $key ] = in_array( $value, array( 'summary', 'summary_large_image' ), true ) ? (string) $value : 'summary_large_image';
					break;
				case 'attachments':
					$out[ $key ] = in_array( $value, array( 'noindex', 'redirect' ), true ) ? (string) $value : 'redirect';
					break;
				case 'max_image_preview':
					$out[ $key ] = in_array( $value, array( 'none', 'standard', 'large' ), true ) ? (string) $value : 'large';
					break;
				case 'max_snippet':
				case 'max_video_preview':
					$out[ $key ] = max( -1, (int) $value );
					break;
				case 'org_logo_id':
				case 'default_image_id':
					$out[ $key ] = absint( $value );
					break;
				case 'facebook_app_id':
					$out[ $key ] = preg_replace( '/\D+/', '', (string) $value );
					break;
				case 'verify_google':
				case 'verify_bing':
				case 'verify_pinterest':
				case 'verify_yandex':
				case 'verify_baidu':
					$out[ $key ] = self::sanitize_verification( $value );
					break;
				case 'robots_txt':
					$out[ $key ] = trim( sanitize_textarea_field( str_replace( "\r\n", "\n", (string) $value ) ) );
					break;
				case 'rss_before':
				case 'rss_after':
					// Feed footers carry links, so they keep post-safe HTML.
					$out[ $key ] = trim( wp_kses_post( (string) $value ) );
					break;
				default:
					// separator, org_name, and any future plain-text field
					// default to the same safe rule.
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * Reset to defaults.
	 */
	public function reset() {
		delete_option( self::OPTION );
		$this->doc = null;
	}

	/**
	 * Resolved default social image URL, cached so the head doesn't run an
	 * attachment lookup (2 queries) on every page view. Busts when the id changes.
	 *
	 * @return string
	 */
	public function image_url() {
		return (string) $this->cached_attachment( 'default_image_id', 'default_image_cache', 'large' )['url'];
	}

	/**
	 * Resolved default social image as id, url, width, height, alt, from the
	 * same cache as image_url(), so the Open Graph size and alt tags cost no
	 * extra lookup either.
	 *
	 * @return array
	 */
	public function image_data() {
		return $this->cached_attachment( 'default_image_id', 'default_image_cache', 'large' );
	}

	/**
	 * Resolved schema logo URL, cached the same way as image_url().
	 *
	 * @return string
	 */
	public function logo_url() {
		return (string) $this->cached_attachment( 'org_logo_id', 'org_logo_cache', 'full' )['url'];
	}

	/**
	 * Resolve an attachment id to a URL (plus size and alt) once and remember
	 * it in the option. Later reads skip the DB lookup until the id changes.
	 *
	 * The empty result is cached too. A dangling id (the default social image
	 * or the org logo was deleted from the media library, but the id stayed in
	 * the option) resolves to '', and refusing to store '' meant every single
	 * page view re-ran the attachment lookup forever. The hit test is
	 * therefore "have we recorded a url for this exact id", not "is the
	 * recorded url non-empty".
	 *
	 * @param string $id_key    Option key holding the attachment id.
	 * @param string $cache_key Option key holding the { id, url, width, height, alt } cache.
	 * @param string $size      Image size.
	 * @return array id, url, width, height, alt.
	 */
	private function cached_attachment( $id_key, $cache_key, $size ) {
		$empty = array(
			'id'     => 0,
			'url'    => '',
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
		);
		$doc   = $this->all();
		$id    = (int) ( isset( $doc[ $id_key ] ) ? $doc[ $id_key ] : 0 );
		if ( ! $id ) {
			return $empty;
		}
		$cache = isset( $doc[ $cache_key ] ) && is_array( $doc[ $cache_key ] ) ? $doc[ $cache_key ] : array();
		if ( array_key_exists( 'url', $cache ) && (int) ( isset( $cache['id'] ) ? $cache['id'] : 0 ) === $id ) {
			return array_merge( $empty, array_intersect_key( $cache, $empty ) );
		}
		$src   = wp_get_attachment_image_src( $id, $size );
		$entry = array(
			'id'     => $id,
			'url'    => is_array( $src ) ? (string) $src[0] : '',
			'width'  => is_array( $src ) ? (int) $src[1] : 0,
			'height' => is_array( $src ) ? (int) $src[2] : 0,
			'alt'    => is_array( $src ) ? sanitize_text_field( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '',
		);
		$this->save_raw( array( $cache_key => $entry ) );
		return $entry;
	}

	/**
	 * Social profile URLs that are set, for schema sameAs.
	 *
	 * @return array
	 */
	public function same_as() {
		$out = array();
		foreach ( $this->get( 'social', array() ) as $key => $val ) {
			$val = trim( (string) $val );
			if ( '' === $val ) {
				continue;
			}
			if ( 'twitter' === $key && '#' !== $val[0] && 0 !== strpos( $val, 'http' ) ) {
				$val = 'https://x.com/' . ltrim( $val, '@' );
			}
			if ( 0 === strpos( $val, 'http' ) ) {
				$out[] = $val;
			}
		}
		return $out;
	}

	/**
	 * Import global settings from Yoast SEO, if present.
	 *
	 * @return int Number of fields imported.
	 */
	public function import_yoast() {
		$titles = get_option( 'wpseo_titles' );
		$social = get_option( 'wpseo_social' );
		if ( ! is_array( $titles ) && ! is_array( $social ) ) {
			return 0;
		}
		$titles = is_array( $titles ) ? $titles : array();
		$social = is_array( $social ) ? $social : array();
		$patch  = array( 'social' => array() );
		$n      = 0;

		$sep_map = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '·',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
		);
		if ( ! empty( $titles['separator'] ) && isset( $sep_map[ $titles['separator'] ] ) ) {
			$patch['separator'] = $sep_map[ $titles['separator'] ];
			++$n;
		}
		if ( ! empty( $titles['metadesc-home-wpseo'] ) ) {
			$patch['home_description'] = $titles['metadesc-home-wpseo'];
			++$n;
		}
		if ( ! empty( $titles['title-home-wpseo'] ) ) {
			$patch['home_title'] = $titles['title-home-wpseo'];
			++$n;
		}
		if ( ! empty( $titles['company_name'] ) ) {
			$patch['org_name'] = $titles['company_name'];
			++$n;
		}
		if ( ! empty( $titles['company_logo_id'] ) ) {
			$patch['org_logo_id'] = (int) $titles['company_logo_id'];
			++$n;
		}
		if ( ! empty( $social['og_default_image_id'] ) ) {
			$patch['default_image_id'] = (int) $social['og_default_image_id'];
			++$n;
		}
		foreach ( array(
			'facebook'  => 'facebook_site',
			'twitter'   => 'twitter_site',
			'instagram' => 'instagram_url',
			'linkedin'  => 'linkedin_url',
			'youtube'   => 'youtube_url',
		) as $ours => $theirs ) {
			if ( ! empty( $social[ $theirs ] ) ) {
				$patch['social'][ $ours ] = $social[ $theirs ];
				++$n;
			}
		}
		if ( $n ) {
			$this->save( $patch );
		}
		return $n;
	}

	/**
	 * Import global settings from All in One SEO, if present.
	 *
	 * @return int Number of fields imported.
	 */
	public function import_aioseo() {
		$raw = get_option( 'aioseo_options' );
		if ( empty( $raw ) ) {
			return 0;
		}
		$o = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $o ) ) {
			return 0;
		}
		$patch = array( 'social' => array() );
		$n     = 0;

		$global = isset( $o['searchAppearance']['global'] ) ? $o['searchAppearance']['global'] : array();
		if ( ! empty( $global['separator'] ) ) {
			$patch['separator'] = (string) $global['separator'];
			++$n;
		}
		if ( ! empty( $global['metaDescription'] ) && false === strpos( $global['metaDescription'], '#' ) ) {
			$patch['default_description'] = (string) $global['metaDescription'];
			++$n;
		}

		$urls = isset( $o['social']['profiles']['urls'] ) ? $o['social']['profiles']['urls'] : array();
		foreach ( array(
			'facebook'  => 'facebookPageUrl',
			'twitter'   => 'twitterUrl',
			'instagram' => 'instagramUrl',
			'linkedin'  => 'linkedinUrl',
			'youtube'   => 'youtubeUrl',
		) as $ours => $theirs ) {
			if ( ! empty( $urls[ $theirs ] ) ) {
				$patch['social'][ $ours ] = (string) $urls[ $theirs ];
				++$n;
			}
		}
		if ( $n ) {
			$this->save( $patch );
		}
		return $n;
	}

	/**
	 * Import global settings from the Genesis Framework, if present.
	 *
	 * Genesis wrote its SEO Settings screen to the `genesis-seo-settings`
	 * option, and that option survives a theme switch, which is what makes
	 * this importable after the site has moved to Perdita. Per-post Genesis
	 * titles and descriptions (`_genesis_title`, `_genesis_description`) are
	 * post meta and belong to the Perdita Pro importer, the same split as the
	 * Yoast and AIOSEO importers above.
	 *
	 * @return int Number of fields imported.
	 */
	public function import_genesis() {
		$g = get_option( 'genesis-seo-settings' );
		if ( ! is_array( $g ) || ! $g ) {
			return 0;
		}
		$patch = array();
		$n     = 0;

		if ( ! empty( $g['home_doctitle'] ) ) {
			$patch['home_title'] = (string) $g['home_doctitle'];
			++$n;
		}
		if ( ! empty( $g['home_description'] ) ) {
			$patch['home_description'] = (string) $g['home_description'];
			++$n;
		}
		if ( ! empty( $g['doctitle_sep'] ) ) {
			$patch['separator'] = (string) $g['doctitle_sep'];
			++$n;
		}
		// Genesis only appends the site name when append_site_title is on
		// (off by default), and puts the separator on whichever side
		// doctitle_seplocation says. Carry the shape the site actually had.
		if ( array_key_exists( 'append_site_title', $g ) ) {
			if ( empty( $g['append_site_title'] ) ) {
				$patch['title_template'] = '%title%';
			} else {
				$patch['title_template'] = ( 'left' === ( $g['doctitle_seplocation'] ?? 'right' ) )
					? '%sitename% %sep% %title%'
					: '%title% %sep% %sitename%';
			}
			++$n;
		}

		$noindex = array();
		foreach ( array(
			'noindex_cat_archive'    => 'archive_category',
			'noindex_tag_archive'    => 'archive_tag',
			'noindex_author_archive' => 'archive_author',
			'noindex_date_archive'   => 'archive_date',
			'noindex_search_archive' => 'search',
		) as $theirs => $ours ) {
			if ( array_key_exists( $theirs, $g ) ) {
				$noindex[ $ours ] = (bool) $g[ $theirs ];
				++$n;
			}
		}
		if ( $noindex ) {
			$patch['noindex'] = $noindex;
		}

		if ( $n ) {
			$this->save( $patch );
		}
		return $n;
	}

	/**
	 * Deep merge.
	 *
	 * @param array $base     Base.
	 * @param array $override Override.
	 * @return array
	 */
	private function merge_deep( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = $this->merge_deep( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
