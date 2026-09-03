<?php
/**
 * SEO settings store (option `perdita_seo`).
 *
 * Kept separate from the design token document so the SEO subsystem can grow
 * without bloating the design schema the AI edits. Holds site-wide title and
 * meta defaults, linked social profiles, default social images, schema, and
 * noindex rules. Includes best-effort import of global settings from Yoast and
 * All in One SEO.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

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
			'enabled'          => true,
			'separator'        => '-',
			'title_template'   => '%title% %sep% %sitename%',
			'home_title'       => '',
			'home_description' => '',
			'default_description' => '',
			'default_image_id' => 0,
			'twitter_card'     => 'summary_large_image',
			'schema_type'      => 'Organization',
			'org_name'         => '',
			'org_logo_id'      => 0,
			'social'           => array(
				'twitter'   => '',
				'facebook'  => '',
				'instagram' => '',
				'linkedin'  => '',
				'youtube'   => '',
			),
			'noindex'          => array(
				'archive_date'     => true,
				'archive_author'   => false,
				'archive_tag'      => false,
				'archive_category' => false,
				'archive_post_type' => false,
				'search'           => true,
			),
		);
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
	 * Used only by cached_attachment_url() for its internal { id, url } cache,
	 * whose shape is already fully controlled at the call site (id from an
	 * (int) cast, url from wp_get_attachment_image_url()) -- never for
	 * anything derived from user, import, or migration input.
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
					'id'  => absint( $value['id'] ?? 0 ),
					'url' => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
				);
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue; // Reject anything else structural outright.
			}
			switch ( $key ) {
				case 'enabled':
					$out[ $key ] = (bool) $value;
					break;
				case 'home_description':
				case 'default_description':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'schema_type':
					$out[ $key ] = in_array( $value, array( 'Organization', 'Person' ), true ) ? (string) $value : 'Organization';
					break;
				case 'twitter_card':
					$out[ $key ] = in_array( $value, array( 'summary', 'summary_large_image' ), true ) ? (string) $value : 'summary_large_image';
					break;
				case 'org_logo_id':
				case 'default_image_id':
					$out[ $key ] = absint( $value );
					break;
				default:
					// title_template, separator, home_title, org_name, and any
					// future plain-text field default to the same safe rule.
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
		return $this->cached_attachment_url( 'default_image_id', 'default_image_cache', 'large' );
	}

	/**
	 * Resolved schema logo URL, cached the same way as image_url().
	 *
	 * @return string
	 */
	public function logo_url() {
		return $this->cached_attachment_url( 'org_logo_id', 'org_logo_cache', 'full' );
	}

	/**
	 * Resolve an attachment id to a URL once and remember it in the option.
	 * Later reads skip the DB lookup until the id changes.
	 *
	 * The empty result is cached too. A dangling id (the default social image
	 * or the org logo was deleted from the media library, but the id stayed in
	 * the option) resolves to '', and refusing to store '' meant every single
	 * page view re-ran the attachment lookup forever. The hit test is
	 * therefore "have we recorded a url for this exact id", not "is the
	 * recorded url non-empty".
	 *
	 * @param string $id_key    Option key holding the attachment id.
	 * @param string $cache_key Option key holding the { id, url } cache.
	 * @param string $size      Image size.
	 * @return string
	 */
	private function cached_attachment_url( $id_key, $cache_key, $size ) {
		$doc = $this->all();
		$id  = (int) ( isset( $doc[ $id_key ] ) ? $doc[ $id_key ] : 0 );
		if ( ! $id ) {
			return '';
		}
		$cache = isset( $doc[ $cache_key ] ) && is_array( $doc[ $cache_key ] ) ? $doc[ $cache_key ] : array();
		if ( array_key_exists( 'url', $cache ) && (int) ( isset( $cache['id'] ) ? $cache['id'] : 0 ) === $id ) {
			return (string) $cache['url'];
		}
		$url = (string) wp_get_attachment_image_url( $id, $size );
		$this->save_raw(
			array(
				$cache_key => array(
					'id'  => $id,
					'url' => $url,
				),
			)
		);
		return $url;
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
			$n++;
		}
		if ( ! empty( $titles['metadesc-home-wpseo'] ) ) {
			$patch['home_description'] = $titles['metadesc-home-wpseo'];
			$n++;
		}
		if ( ! empty( $titles['title-home-wpseo'] ) ) {
			$patch['home_title'] = $titles['title-home-wpseo'];
			$n++;
		}
		if ( ! empty( $titles['company_name'] ) ) {
			$patch['org_name'] = $titles['company_name'];
			$n++;
		}
		if ( ! empty( $titles['company_logo_id'] ) ) {
			$patch['org_logo_id'] = (int) $titles['company_logo_id'];
			$n++;
		}
		if ( ! empty( $social['og_default_image_id'] ) ) {
			$patch['default_image_id'] = (int) $social['og_default_image_id'];
			$n++;
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
				$n++;
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
			$n++;
		}
		if ( ! empty( $global['metaDescription'] ) && false === strpos( $global['metaDescription'], '#' ) ) {
			$patch['default_description'] = (string) $global['metaDescription'];
			$n++;
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
				$n++;
			}
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
