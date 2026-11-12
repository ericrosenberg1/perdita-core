<?php
/**
 * SEOPress: the site-wide settings in seopress_titles_option_name,
 * seopress_social_option_name and seopress_advanced_option_name, and each
 * term's own title and description.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_SEOPress extends Perdita_Switch_SEO_Source {

	public function id() {
		return 'seopress';
	}

	public function label() {
		return 'SEOPress';
	}

	public function plugin_files() {
		return array( 'wp-seopress/seopress.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'seopress_titles_option_name' ) ) || is_array( get_option( 'seopress_social_option_name' ) );
	}

	protected function term_meta_keys() {
		return array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
		);
	}

	public function translate( $text ) {
		return self::map_vars(
			$text,
			'/%%([a-z0-9_]+)%%/i',
			array(
				'post_title'            => '%title%',
				'title'                 => '%title%',
				'sep'                   => '%sep%',
				'sitetitle'             => '%sitename%',
				'sitename'              => '%sitename%',
				'tagline'               => '%tagline%',
				'sitedesc'              => '%tagline%',
				'post_excerpt'          => '%excerpt%',
				'excerpt'               => '%excerpt%',
				'post_category'         => '%category%',
				'post_tag'              => '%tag%',
				'post_author'           => '%author%',
				'author_name'           => '%author%',
				'_category_title'       => '%term_title%',
				'_category_description' => '%term_description%',
				'tag_title'             => '%term_title%',
				'tag_description'       => '%term_description%',
				'term_title'            => '%term_title%',
				'term_description'      => '%term_description%',
				'search_keywords'       => '%search_term%',
				'current_pagination'    => '%page%',
				'page'                  => '%page%',
				'cpt_plural'            => '%post_type_plural%',
				'archive_title'         => '%archive_title%',
				'archive_date'          => '%archive_title%',
				'currentyear'           => '%currentyear%',
				'currentmonth'          => '%currentmonth%',
				'currentdate'           => '%currentdate%',
			)
		);
	}

	public function plan() {
		$this->begin();
		$t = self::array_option( 'seopress_titles_option_name' );
		$s = self::array_option( 'seopress_social_option_name' );
		$a = self::array_option( 'seopress_advanced_option_name' );
		if ( ! $t && ! $s && ! $a ) {
			return $this->finish();
		}

		$sep = trim( html_entity_decode( (string) self::val( $t, 'seopress_titles_sep' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $sep ) {
			$this->offer( __( 'Separator', 'perdita-core' ), $sep, 'separator', $sep, self::target_label( 'separator' ) );
		}
		$this->offer_template( __( 'Site title', 'perdita-core' ), self::val( $t, 'seopress_titles_home_site_title' ), 'home_title' );
		$this->offer_template( __( 'Meta description', 'perdita-core' ), self::val( $t, 'seopress_titles_home_site_desc' ), 'home_description' );

		$groups = array(
			'post_types' => array( 'seopress_titles_single_titles', get_post_types( array( 'public' => true ) ) ),
			'taxonomies' => array( 'seopress_titles_tax_titles', get_taxonomies( array( 'public' => true ) ) ),
		);
		foreach ( $groups as $ours => $group ) {
			list( $key, $slugs ) = $group;
			$rows                = isset( $t[ $key ] ) && is_array( $t[ $key ] ) ? $t[ $key ] : array();
			foreach ( $rows as $slug => $row ) {
				if ( ! is_array( $row ) || ! in_array( $slug, $slugs, true ) ) {
					continue;
				}
				$this->offer_template( self::type_setting( $ours, $slug, __( 'Title template', 'perdita-core' ) ), self::val( $row, 'title' ), "{$ours}.{$slug}.title" );
				$this->offer_template( self::type_setting( $ours, $slug, __( 'Meta description template', 'perdita-core' ) ), self::val( $row, 'description' ), "{$ours}.{$slug}.description" );
				if ( 'attachment' !== $slug ) {
					$no = ! empty( $row['noindex'] );
					$this->offer( self::type_setting( $ours, $slug, __( 'Do not display in search engines', 'perdita-core' ) ), $no, "{$ours}.{$slug}.noindex", $no, self::target_label( "{$ours}.{$slug}.noindex" ) );
				}
			}
		}

		foreach ( array(
			'seopress_titles_archives_author_noindex'       => array( __( 'Author archives noindex', 'perdita-core' ), 'noindex.archive_author' ),
			'seopress_titles_archives_date_noindex'         => array( __( 'Date archives noindex', 'perdita-core' ), 'noindex.archive_date' ),
			'seopress_titles_archives_search_title_noindex' => array( __( 'Search results noindex', 'perdita-core' ), 'noindex.search' ),
			'seopress_titles_paged_noindex'                 => array( __( 'Paginated pages noindex', 'perdita-core' ), 'noindex_paginated' ),
		) as $theirs => $pair ) {
			$on = ! empty( $t[ $theirs ] );
			$this->offer( $pair[0], $on, $pair[1], $on, self::target_label( $pair[1] ) );
		}
		$this->offer_template( __( 'Search results title', 'perdita-core' ), self::val( $t, 'seopress_titles_archives_search_title' ), 'search_title' );
		$this->offer_template( __( '404 title', 'perdita-core' ), self::val( $t, 'seopress_titles_archives_404_title' ), '404_title' );

		// Attachments: SEOPress redirects them (advanced) or marks them noindex.
		if ( ! empty( $a['seopress_advanced_advanced_attachments'] ) ) {
			$this->offer( __( 'Redirect attachment pages', 'perdita-core' ), true, 'attachments', 'redirect', self::target_label( 'attachments' ) );
		} elseif ( ! empty( $t['seopress_titles_attachments_noindex'] ) ) {
			$this->offer( __( 'Attachment pages noindex', 'perdita-core' ), true, 'attachments', 'noindex', self::target_label( 'attachments' ) );
		}

		// Knowledge graph and social.
		$kg = (string) self::val( $s, 'seopress_social_knowledge_type' );
		if ( in_array( $kg, array( 'Person', 'Organization' ), true ) ) {
			$this->offer( __( 'Person or organization', 'perdita-core' ), $kg, 'schema_type', $kg, self::target_label( 'schema_type' ) );
		}
		$name = trim( (string) self::val( $s, 'seopress_social_knowledge_name' ) );
		if ( '' !== $name ) {
			$this->offer( __( 'Your name or company name', 'perdita-core' ), $name, 'org_name', $name, self::target_label( 'org_name' ) );
		}
		$logo = trim( (string) self::val( $s, 'seopress_social_knowledge_img' ) );
		if ( '' !== $logo ) {
			$logo_id = Perdita_SEO_Store::attachment_id_for_url( $logo );
			if ( $logo_id ) {
				$this->offer_image( __( 'Your photo or company logo', 'perdita-core' ), $logo_id, 'org_logo_id' );
			} else {
				$this->skip( __( 'Your photo or company logo', 'perdita-core' ), $logo, __( 'That image isn\'t in this site\'s media library, and you can only pick a logo from the media library.', 'perdita-core' ), self::target_label( 'org_logo_id' ) );
			}
		}
		$this->offer_image( __( 'Default Facebook image', 'perdita-core' ), self::val( $s, 'seopress_social_facebook_img_attachment_id', 0 ), 'default_image_id' );
		if ( isset( $s['seopress_social_twitter_card_img_size'] ) ) {
			$size = (string) self::val( $s, 'seopress_social_twitter_card_img_size' );
			$this->offer( __( 'Twitter card image size', 'perdita-core' ), $size, 'twitter_card', 'large' === $size ? 'summary_large_image' : 'summary', self::target_label( 'twitter_card' ) );
		}
		$app = trim( (string) self::val( $s, 'seopress_social_facebook_app_id' ) );
		if ( '' !== $app ) {
			$this->offer( __( 'Facebook App ID', 'perdita-core' ), $app, 'facebook_app_id', $app, self::target_label( 'facebook_app_id' ) );
		}
		foreach ( array(
			'facebook'  => 'seopress_social_accounts_facebook',
			'twitter'   => 'seopress_social_accounts_twitter',
			'instagram' => 'seopress_social_accounts_instagram',
			'linkedin'  => 'seopress_social_accounts_linkedin',
			'youtube'   => 'seopress_social_accounts_youtube',
		) as $ours => $theirs ) {
			$url = trim( (string) self::val( $s, $theirs ) );
			if ( '' !== $url ) {
				$this->offer( ucfirst( $ours ), $url, 'social.' . $ours, $url, self::target_label( 'social.' . $ours ) );
			}
		}

		foreach ( array( 'google', 'bing', 'pinterest', 'yandex', 'baidu' ) as $engine ) {
			$code = trim( (string) self::val( $a, 'seopress_advanced_advanced_' . $engine ) );
			if ( '' !== $code ) {
				$this->offer( ucfirst( $engine ) . ' site verification', $code, 'verify_' . $engine, $code, self::target_label( 'verify_' . $engine ) );
			}
		}

		$this->note_terms();
		$this->note( self::no_per_post_note( $this->label() ) );
		return $this->finish();
	}
}
