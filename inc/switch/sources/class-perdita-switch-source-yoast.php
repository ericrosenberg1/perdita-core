<?php
/**
 * Yoast SEO, through the importer Perdita SEO already has
 * (Perdita_SEO_Store::yoast_patch()).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Yoast extends Perdita_Switch_SEO_Source {

	public function id() {
		return 'yoast';
	}

	public function label() {
		return 'Yoast SEO';
	}

	public function plugin_files() {
		return array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'wpseo_titles' ) ) || is_array( get_option( 'wpseo_social' ) );
	}

	public function pro_note() {
		return self::per_post_note();
	}

	public function plan() {
		$this->begin();
		list( $patch ) = perdita_core()->seo->yoast_patch();
		$titles        = self::array_option( 'wpseo_titles' );
		$this->offer_tree(
			$patch,
			array(
				'separator'        => __( 'Title separator', 'perdita-core' ),
				'home_title'       => __( 'Homepage SEO title', 'perdita-core' ),
				'home_description' => __( 'Homepage meta description', 'perdita-core' ),
				'org_name'         => __( 'Organization name', 'perdita-core' ),
				'org_logo_id'      => __( 'Organization logo', 'perdita-core' ),
				'default_image_id' => __( 'Default image', 'perdita-core' ),
				'social.facebook'  => __( 'Facebook', 'perdita-core' ),
				'social.twitter'   => 'X',
				'social.instagram' => __( 'Instagram', 'perdita-core' ),
				'social.linkedin'  => __( 'LinkedIn', 'perdita-core' ),
				'social.youtube'   => __( 'YouTube', 'perdita-core' ),
			),
			array( 'separator' => self::val( $titles, 'separator' ) )
		);
		return $this->finish();
	}
}
