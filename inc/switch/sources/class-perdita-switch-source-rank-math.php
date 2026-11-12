<?php
/**
 * Rank Math SEO: the site-wide settings in rank-math-options-titles and
 * rank-math-options-general, and each term's own title and description.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Rank_Math extends Perdita_Switch_SEO_Source {

	public function id() {
		return 'rank-math';
	}

	public function label() {
		return 'Rank Math SEO';
	}

	public function plugin_files() {
		return array( 'seo-by-rank-math/rank-math.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'rank-math-options-titles' ) ) || is_array( get_option( 'rank-math-options-general' ) );
	}

	protected function term_meta_keys() {
		return array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		);
	}

	public function translate( $text ) {
		return self::map_vars(
			$text,
			'/%([a-z_]+(?:\([^)%]*\))?)%/i',
			array(
				'title'            => '%title%',
				'sep'              => '%sep%',
				'sitename'         => '%sitename%',
				'sitedesc'         => '%tagline%',
				'excerpt'          => '%excerpt%',
				'excerpt_only'     => '%excerpt%',
				'seo_description'  => '%excerpt%',
				'category'         => '%category%',
				'primary_category' => '%category%',
				'tag'              => '%tag%',
				'name'             => '%author%',
				'term'             => '%term_title%',
				'term_description' => '%term_description%',
				'search_query'     => '%search_term%',
				'pt_single'        => '%post_type_singular%',
				'pt_plural'        => '%post_type_plural%',
				'page'             => '%page%',
				'currentyear'      => '%currentyear%',
				'currentmonth'     => '%currentmonth%',
				'currentdate'      => '%currentdate%',
			)
		);
	}

	/**
	 * Whether a robots list (applied only with its custom switch on) says
	 * noindex.
	 *
	 * @param array  $t      Titles option.
	 * @param string $robots Robots key.
	 * @param string $custom Custom switch key, '' when always applied.
	 * @return bool|null Null when the setting isn't there.
	 */
	private static function noindex( array $t, $robots, $custom ) {
		if ( '' !== $custom && ( ! isset( $t[ $custom ] ) || ! self::on( $t[ $custom ] ) ) ) {
			return null;
		}
		if ( ! isset( $t[ $robots ] ) ) {
			return null;
		}
		return is_array( $t[ $robots ] ) && in_array( 'noindex', $t[ $robots ], true );
	}

	public function plan() {
		$this->begin();
		$t = self::array_option( 'rank-math-options-titles' );
		$g = self::array_option( 'rank-math-options-general' );
		if ( ! $t && ! $g ) {
			return $this->finish();
		}

		if ( isset( $t['title_separator'] ) && is_scalar( $t['title_separator'] ) && '' !== trim( (string) $t['title_separator'] ) ) {
			$sep = html_entity_decode( (string) $t['title_separator'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$this->offer( __( 'Separator character', 'perdita-core' ), $sep, 'separator', $sep, self::target_label( 'separator' ) );
		}
		// Rank Math's homepage fields only reach a site that lists posts on
		// the front page. A static front page uses that page's own fields.
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$this->offer_template( __( 'Homepage title', 'perdita-core' ), self::val( $t, 'homepage_title' ), 'home_title' );
			$this->offer_template( __( 'Homepage meta description', 'perdita-core' ), self::val( $t, 'homepage_description' ), 'home_description' );
		}

		foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
			$this->offer_template( self::type_setting( 'post_types', $pt, __( 'Single title', 'perdita-core' ) ), self::val( $t, "pt_{$pt}_title" ), "post_types.{$pt}.title" );
			$this->offer_template( self::type_setting( 'post_types', $pt, __( 'Single description', 'perdita-core' ) ), self::val( $t, "pt_{$pt}_description" ), "post_types.{$pt}.description" );
			$no = self::noindex( $t, "pt_{$pt}_robots", "pt_{$pt}_custom_robots" );
			if ( null !== $no && 'attachment' !== $pt ) {
				$this->offer( self::type_setting( 'post_types', $pt, __( 'Robots meta', 'perdita-core' ) ), $no ? 'noindex' : 'index', "post_types.{$pt}.noindex", $no, self::target_label( "post_types.{$pt}.noindex" ) );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$this->offer_template( self::type_setting( 'taxonomies', $tax, __( 'Archive titles', 'perdita-core' ) ), self::val( $t, "tax_{$tax}_title" ), "taxonomies.{$tax}.title" );
			$this->offer_template( self::type_setting( 'taxonomies', $tax, __( 'Archive descriptions', 'perdita-core' ) ), self::val( $t, "tax_{$tax}_description" ), "taxonomies.{$tax}.description" );
			$no = self::noindex( $t, "tax_{$tax}_robots", "tax_{$tax}_custom_robots" );
			if ( null !== $no ) {
				$this->offer( self::type_setting( 'taxonomies', $tax, __( 'Robots meta', 'perdita-core' ) ), $no ? 'noindex' : 'index', "taxonomies.{$tax}.noindex", $no, self::target_label( "taxonomies.{$tax}.noindex" ) );
			}
		}

		$author = self::noindex( $t, 'author_robots', 'author_custom_robots' );
		if ( null !== $author ) {
			$this->offer( __( 'Author robots meta', 'perdita-core' ), $author ? 'noindex' : 'index', 'noindex.archive_author', $author, self::target_label( 'noindex.archive_author' ) );
		}
		$date = self::noindex( $t, 'date_archive_robots', '' );
		if ( null !== $date ) {
			$this->offer( __( 'Date archive robots meta', 'perdita-core' ), $date ? 'noindex' : 'index', 'noindex.archive_date', $date, self::target_label( 'noindex.archive_date' ) );
		}
		foreach ( array(
			'disable_author_archives' => __( 'Author archives', 'perdita-core' ),
			'disable_date_archives'   => __( 'Date archives', 'perdita-core' ),
		) as $key => $name ) {
			if ( isset( $t[ $key ] ) && self::on( $t[ $key ] ) ) {
				$this->unsupported( $name, __( 'off', 'perdita-core' ), __( 'In Rank Math, you had these archives redirected away. In Perdita, you keep the archives and can mark them noindex instead.', 'perdita-core' ) );
			}
		}
		if ( isset( $t['noindex_search'] ) ) {
			$this->offer( __( 'Noindex search results', 'perdita-core' ), self::on( $t['noindex_search'] ), 'noindex.search', self::on( $t['noindex_search'] ), self::target_label( 'noindex.search' ) );
		}
		if ( isset( $t['noindex_archive_subpages'] ) ) {
			$this->offer( __( 'Noindex paginated pages', 'perdita-core' ), self::on( $t['noindex_archive_subpages'] ), 'noindex_paginated', self::on( $t['noindex_archive_subpages'] ), self::target_label( 'noindex_paginated' ) );
		}
		if ( isset( $t['advanced_robots_global'] ) && is_array( $t['advanced_robots_global'] ) ) {
			$adv = $t['advanced_robots_global'];
			if ( isset( $adv['max-snippet'] ) ) {
				$this->offer( 'max-snippet', $adv['max-snippet'], 'max_snippet', (int) $adv['max-snippet'], self::target_label( 'max_snippet' ) );
			}
			if ( isset( $adv['max-video-preview'] ) ) {
				$this->offer( 'max-video-preview', $adv['max-video-preview'], 'max_video_preview', (int) $adv['max-video-preview'], self::target_label( 'max_video_preview' ) );
			}
			if ( isset( $adv['max-image-preview'] ) ) {
				$this->offer( 'max-image-preview', $adv['max-image-preview'], 'max_image_preview', (string) $adv['max-image-preview'], self::target_label( 'max_image_preview' ) );
			}
		}
		$this->offer_template( __( 'Search results title', 'perdita-core' ), self::val( $t, 'search_title' ), 'search_title' );
		$this->offer_template( __( '404 title', 'perdita-core' ), self::val( $t, '404_title' ), '404_title' );

		// Knowledge graph and social.
		$kg = (string) self::val( $t, 'knowledgegraph_type' );
		if ( in_array( $kg, array( 'person', 'company' ), true ) ) {
			$type = 'person' === $kg ? 'Person' : 'Organization';
			$this->offer( __( 'Person or company', 'perdita-core' ), $kg, 'schema_type', $type, self::target_label( 'schema_type' ) );
		}
		if ( '' !== trim( (string) self::val( $t, 'knowledgegraph_name' ) ) ) {
			$this->offer( __( 'Name', 'perdita-core' ), self::val( $t, 'knowledgegraph_name' ), 'org_name', (string) self::val( $t, 'knowledgegraph_name' ), self::target_label( 'org_name' ) );
		}
		$this->offer_image( __( 'Logo', 'perdita-core' ), self::val( $t, 'knowledgegraph_logo_id', 0 ), 'org_logo_id' );
		$this->offer_image( __( 'OpenGraph thumbnail', 'perdita-core' ), self::val( $t, 'open_graph_image_id', 0 ), 'default_image_id' );
		$card = (string) self::val( $t, 'twitter_card_type' );
		if ( '' !== $card ) {
			if ( in_array( $card, array( 'summary_card', 'summary_large_image' ), true ) ) {
				$this->offer( __( 'Twitter card type', 'perdita-core' ), $card, 'twitter_card', 'summary_card' === $card ? 'summary' : 'summary_large_image', self::target_label( 'twitter_card' ) );
			} else {
				$this->unsupported( __( 'Twitter card type', 'perdita-core' ), $card, __( 'In Perdita, you can pick the summary card or the large image card.', 'perdita-core' ) );
			}
		}
		if ( '' !== trim( (string) self::val( $t, 'facebook_app_id' ) ) ) {
			$this->offer( __( 'Facebook app ID', 'perdita-core' ), self::val( $t, 'facebook_app_id' ), 'facebook_app_id', (string) self::val( $t, 'facebook_app_id' ), self::target_label( 'facebook_app_id' ) );
		}
		if ( '' !== trim( (string) self::val( $t, 'social_url_facebook' ) ) ) {
			$this->offer( __( 'Facebook page URL', 'perdita-core' ), self::val( $t, 'social_url_facebook' ), 'social.facebook', (string) self::val( $t, 'social_url_facebook' ), self::target_label( 'social.facebook' ) );
		}
		if ( '' !== trim( (string) self::val( $t, 'twitter_author_names' ) ) ) {
			$this->offer( __( 'Twitter username', 'perdita-core' ), self::val( $t, 'twitter_author_names' ), 'social.twitter', (string) self::val( $t, 'twitter_author_names' ), self::target_label( 'social.twitter' ) );
		}
		$this->plan_profiles( (string) self::val( $t, 'social_additional_profiles' ) );

		// General settings.
		foreach ( array( 'google', 'bing', 'pinterest', 'yandex', 'baidu' ) as $engine ) {
			$code = (string) self::val( $g, $engine . '_verify' );
			if ( '' !== trim( $code ) ) {
				$this->offer( self::target_label( 'verify_' . $engine ), $code, 'verify_' . $engine, $code, self::target_label( 'verify_' . $engine ) );
			}
		}
		if ( isset( $g['attachment_redirect_urls'] ) ) {
			if ( self::on( $g['attachment_redirect_urls'] ) ) {
				$this->offer( __( 'Redirect attachments', 'perdita-core' ), true, 'attachments', 'redirect', self::target_label( 'attachments' ) );
			} else {
				$this->unsupported( __( 'Redirect attachments', 'perdita-core' ), false, __( 'In Perdita, you can redirect attachment pages to their file or mark them noindex. Pick one on Perdita, SEO.', 'perdita-core' ) );
			}
		}
		$rss = array(
			'%authorlink%' => '%%AUTHORLINK%%',
			'%postlink%'   => '%%POSTLINK%%',
			'%bloglink%'   => '%%BLOGLINK%%',
		);
		foreach ( array(
			'rss_before_content' => 'rss_before',
			'rss_after_content'  => 'rss_after',
		) as $theirs => $ours ) {
			$name = 'rss_before' === $ours ? __( 'RSS before content', 'perdita-core' ) : __( 'RSS after content', 'perdita-core' );
			$raw  = trim( (string) self::val( $g, $theirs ) );
			if ( '' === $raw ) {
				continue;
			}
			$text = self::map_vars( $raw, '/(%[a-z]+%)/i', $rss );
			if ( null === $text ) {
				$this->skip( $name, $raw, __( 'You used a variable with no match in Perdita, so you keep Perdita\'s own default for this setting.', 'perdita-core' ), self::target_label( $ours ) );
				continue;
			}
			$this->offer( $name, $raw, $ours, $text, self::target_label( $ours ) );
		}
		$robots = trim( (string) self::val( $g, 'robots_txt_content' ) );
		if ( '' !== $robots ) {
			$this->offer( __( 'Edit robots.txt', 'perdita-core' ), $robots, 'robots_txt', $robots, self::target_label( 'robots_txt' ) );
		}

		$this->note_terms();
		$this->note( self::no_per_post_note( $this->label() ) );
		return $this->finish();
	}

	/**
	 * Additional profiles: one URL a line. Instagram, LinkedIn and YouTube
	 * have a field in Perdita.
	 *
	 * @param string $raw Profiles.
	 */
	private function plan_profiles( $raw ) {
		$hosts = array(
			'instagram.com' => 'instagram',
			'linkedin.com'  => 'linkedin',
			'youtube.com'   => 'youtube',
		);
		$taken = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $url ) {
			$url = trim( $url );
			if ( '' === $url ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );
			if ( isset( $hosts[ $host ] ) && ! isset( $taken[ $hosts[ $host ] ] ) ) {
				$taken[ $hosts[ $host ] ] = true;
				$this->offer( __( 'Additional profiles', 'perdita-core' ), $url, 'social.' . $hosts[ $host ], $url, self::target_label( 'social.' . $hosts[ $host ] ) );
			} else {
				$this->unsupported( __( 'Additional profiles', 'perdita-core' ), $url, __( 'In Perdita, you can add Facebook, X, Instagram, LinkedIn, and YouTube profiles, and no others.', 'perdita-core' ) );
			}
		}
	}
}
