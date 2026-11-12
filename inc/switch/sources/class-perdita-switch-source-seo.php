<?php
/**
 * Shared parts of the SEO sources: offering a nested SEO store patch one
 * field at a time, turning another plugin's template variables into
 * Perdita's tokens, and the per-term title and description batch.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_SEO_Source extends Perdita_Switch_Source {

	public function module() {
		return 'seo';
	}

	/**
	 * Term meta holding the plugin's title and description, or ''.
	 *
	 * @return array title, description.
	 */
	protected function term_meta_keys() {
		return array(
			'title'       => '',
			'description' => '',
		);
	}

	/**
	 * Turn one of the plugin's templates into a Perdita template, or null
	 * when it carries a variable Perdita has no twin for.
	 *
	 * @param string $text Template.
	 * @return string|null
	 */
	public function translate( $text ) {
		return (string) $text;
	}

	public function batch_keys() {
		$keys = array_values( array_filter( $this->term_meta_keys() ) );
		return $keys ? array(
			'type' => 'term',
			'keys' => $keys,
		) : array();
	}

	public function batch_step( $object_id ) {
		$keys  = $this->term_meta_keys();
		$out   = array();
		$fails = 0;
		foreach ( array( 'title', 'description' ) as $field ) {
			$out[ $field ] = '';
			if ( '' === $keys[ $field ] ) {
				continue;
			}
			$raw = get_term_meta( (int) $object_id, $keys[ $field ], true );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				continue;
			}
			$text = $this->translate( html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( null === $text || strlen( $text ) > 1000 ) {
				++$fails;
				continue;
			}
			$out[ $field ] = $text;
		}
		if ( '' === $out['title'] && '' === $out['description'] ) {
			return $fails ? 'skipped' : 'none';
		}
		return Perdita_Switch::write_term( (int) $object_id, $this->id(), $out['title'], $out['description'] );
	}

	/**
	 * Replace variables through a map. Unknown variables make the whole
	 * template null, so Perdita never prints a stray variable.
	 *
	 * @param string $text    Template.
	 * @param string $pattern Regex with the variable name in group 1.
	 * @param array  $map     Lowercase name => Perdita token.
	 * @return string|null
	 */
	protected static function map_vars( $text, $pattern, array $map ) {
		$unknown = false;
		$out     = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $map, &$unknown ) {
				$key = strtolower( $m[1] );
				if ( isset( $map[ $key ] ) ) {
					return $map[ $key ];
				}
				$unknown = true;
				return $m[0];
			},
			(string) $text
		);
		return $unknown || ! is_string( $out ) ? null : trim( $out );
	}

	/**
	 * A Perdita SEO field's name on the screen.
	 *
	 * @param string $key Dot path.
	 * @return string
	 */
	protected static function target_label( $key ) {
		$parts = explode( '.', $key );
		$names = array(
			'separator'           => __( 'Title separator', 'perdita-core' ),
			'home_title'          => __( 'Home page title', 'perdita-core' ),
			'home_description'    => __( 'Home page description', 'perdita-core' ),
			'org_name'            => __( 'Organization or person name', 'perdita-core' ),
			'org_logo_id'         => __( 'Logo', 'perdita-core' ),
			'schema_type'         => __( 'The site represents', 'perdita-core' ),
			'default_image_id'    => __( 'Default share image', 'perdita-core' ),
			'twitter_card'        => __( 'X card', 'perdita-core' ),
			'facebook_app_id'     => __( 'Facebook app ID', 'perdita-core' ),
			'noindex_paginated'   => __( 'Noindex page 2 and later', 'perdita-core' ),
			'attachments'         => __( 'Attachment pages', 'perdita-core' ),
			'search_title'        => __( 'Search results title', 'perdita-core' ),
			'404_title'           => __( '404 page title', 'perdita-core' ),
			'robots_txt'          => __( 'robots.txt', 'perdita-core' ),
			'rss_before'          => __( 'Before each feed item', 'perdita-core' ),
			'rss_after'           => __( 'After each feed item', 'perdita-core' ),
			'max_snippet'         => __( 'Longest snippet', 'perdita-core' ),
			'max_image_preview'   => __( 'Largest image preview', 'perdita-core' ),
			'max_video_preview'   => __( 'Longest video preview', 'perdita-core' ),
			'verify_google'       => __( 'Google verification', 'perdita-core' ),
			'verify_bing'         => __( 'Bing verification', 'perdita-core' ),
			'verify_pinterest'    => __( 'Pinterest verification', 'perdita-core' ),
			'verify_yandex'       => __( 'Yandex verification', 'perdita-core' ),
			'verify_baidu'        => __( 'Baidu verification', 'perdita-core' ),
		);
		if ( isset( $names[ $key ] ) ) {
			return $names[ $key ];
		}
		if ( 'social' === $parts[0] && isset( $parts[1] ) ) {
			/* translators: %s: network name. */
			return sprintf( __( 'Social profile: %s', 'perdita-core' ), ucfirst( $parts[1] ) );
		}
		if ( 'noindex' === $parts[0] && isset( $parts[1] ) ) {
			$kinds = array(
				'archive_author' => __( 'author archives', 'perdita-core' ),
				'archive_date'   => __( 'date archives', 'perdita-core' ),
				'search'         => __( 'search results', 'perdita-core' ),
			);
			/* translators: %s: archive kind. */
			return sprintf( __( 'Noindex: %s', 'perdita-core' ), isset( $kinds[ $parts[1] ] ) ? $kinds[ $parts[1] ] : str_replace( '_', ' ', $parts[1] ) );
		}
		if ( in_array( $parts[0], array( 'post_types', 'taxonomies' ), true ) && isset( $parts[1], $parts[2] ) ) {
			$fields = array(
				'title'       => __( 'title', 'perdita-core' ),
				'description' => __( 'description', 'perdita-core' ),
				'noindex'     => __( 'noindex', 'perdita-core' ),
			);
			return self::object_label( $parts[0], $parts[1] ) . ': ' . ( isset( $fields[ $parts[2] ] ) ? $fields[ $parts[2] ] : $parts[2] );
		}
		return $key;
	}

	/**
	 * A post type's or taxonomy's plural name, as the owner sees it in the
	 * admin menu, or the slug when it isn't registered.
	 *
	 * @param string $kind post_types or taxonomies.
	 * @param string $slug Slug.
	 * @return string
	 */
	protected static function object_label( $kind, $slug ) {
		$obj = 'post_types' === $kind ? get_post_type_object( $slug ) : get_taxonomy( $slug );
		return ( $obj && ! empty( $obj->labels->name ) ) ? (string) $obj->labels->name : (string) $slug;
	}

	/**
	 * The setting name for a per-type row: the type's name, then the field
	 * in the other plugin's words.
	 *
	 * @param string $kind  post_types or taxonomies.
	 * @param string $slug  Slug.
	 * @param string $field Field, already translated.
	 * @return string
	 */
	protected static function type_setting( $kind, $slug, $field ) {
		return self::object_label( $kind, $slug ) . ': ' . $field;
	}

	/**
	 * Offer every leaf of a nested SEO store patch.
	 *
	 * @param array $patch Patch in the store's shape.
	 * @param array $names Dot path => the setting's name in the other plugin.
	 * @param array $found Dot path => the other plugin's raw value, when it
	 *                     differs from what Perdita saves.
	 */
	protected function offer_tree( array $patch, array $names = array(), array $found = array() ) {
		foreach ( self::leaves( $patch ) as $key => $value ) {
			$this->offer(
				isset( $names[ $key ] ) ? $names[ $key ] : self::target_label( $key ),
				array_key_exists( $key, $found ) ? $found[ $key ] : $value,
				$key,
				$value,
				self::target_label( $key )
			);
		}
	}

	/**
	 * A nested array as dot path => scalar.
	 *
	 * @param array  $arr    Array.
	 * @param string $prefix Path so far.
	 * @return array
	 */
	protected static function leaves( array $arr, $prefix = '' ) {
		$out = array();
		foreach ( $arr as $k => $v ) {
			$path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) ) {
				$out = array_merge( $out, self::leaves( $v, $path ) );
			} else {
				$out[ $path ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Offer a template after translating it, or skip it with the reason.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $raw     The plugin's template.
	 * @param string $key     Perdita dot path.
	 */
	protected function offer_template( $setting, $raw, $key ) {
		if ( ! is_scalar( $raw ) ) {
			return;
		}
		$raw = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $raw ) {
			return;
		}
		$text = $this->translate( $raw );
		if ( null === $text ) {
			$this->skip( $setting, $raw, __( 'You used a variable with no match in Perdita, so you keep Perdita\'s own default for this setting.', 'perdita-core' ), self::target_label( $key ) );
			return;
		}
		$this->offer( $setting, $raw, $key, $text, self::target_label( $key ) );
	}

	/**
	 * Offer an image attachment id, when the attachment still exists.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $id      Attachment id.
	 * @param string $key     Perdita key.
	 */
	protected function offer_image( $setting, $id, $key ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			$this->skip( $setting, $id, __( 'That image is no longer in the media library.', 'perdita-core' ), self::target_label( $key ) );
			return;
		}
		$this->offer( $setting, wp_basename( (string) wp_get_attachment_url( $id ) ), $key, $id, self::target_label( $key ) );
	}

	/**
	 * A line for the term batch, so the preview says how many terms carry
	 * a title or description.
	 */
	protected function note_terms() {
		$total = Perdita_Switch::batch_total( $this );
		if ( $total > 0 ) {
			/* translators: %d: number of terms. */
			$this->note( sprintf( _n( '%d category, tag, or other term has its own SEO title or description. You get it in Perdita unless you already set one for that term in Perdita.', '%d categories, tags, and other terms have their own SEO title or description. You get each one in Perdita unless you already set one for that term in Perdita.', $total, 'perdita-core' ), $total ) );
		}
	}

	/**
	 * The note for a plugin whose per-post fields Perdita Pro imports, in
	 * the words Core's SEO import screen already uses.
	 *
	 * @return string
	 */
	protected static function per_post_note() {
		/** This filter is documented in inc/class-perdita-seo-admin.php */
		if ( '' !== (string) apply_filters( 'perdita_seo_per_post_import_url', '' ) ) {
			return __( 'Each page\'s own title and description are a separate import.', 'perdita-core' );
		}
		return __( 'Each page\'s own title and description aren\'t part of this import. You can import those with Perdita Pro.', 'perdita-core' );
	}

	/**
	 * The note for a plugin whose per-post fields nothing imports.
	 *
	 * @param string $label Plugin name.
	 * @return string
	 */
	protected static function no_per_post_note( $label ) {
		/* translators: %1$s: plugin name. */
		return sprintf( __( 'Each post\'s own SEO title and description stay in %1$s and aren\'t part of this import. Once you deactivate %1$s, you get Perdita\'s templates on every post.', 'perdita-core' ), $label );
	}
}
