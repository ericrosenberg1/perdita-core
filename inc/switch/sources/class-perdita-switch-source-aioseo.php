<?php
/**
 * All in One SEO, through the importer Perdita SEO already has
 * (Perdita_SEO_Store::aioseo_patch()).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_AIOSEO extends Perdita_Switch_SEO_Source {

	public function id() {
		return 'aioseo';
	}

	public function label() {
		return 'All in One SEO';
	}

	public function plugin_files() {
		return array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
	}

	public function has_settings() {
		return ! empty( get_option( 'aioseo_options' ) );
	}

	public function pro_note() {
		return self::per_post_note();
	}

	public function plan() {
		$this->begin();
		list( $patch ) = perdita_core()->seo->aioseo_patch();
		$this->offer_tree( $patch );
		return $this->finish();
	}
}
