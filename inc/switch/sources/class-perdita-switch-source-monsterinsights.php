<?php
/**
 * MonsterInsights: the GA4 measurement ID, from its connected profile or
 * the one entered by hand.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_MonsterInsights extends Perdita_Switch_Analytics_Source {

	public function id() {
		return 'monsterinsights';
	}

	public function label() {
		return 'MonsterInsights';
	}

	public function plugin_files() {
		return array( 'google-analytics-for-wordpress/googleanalytics.php', 'google-analytics-premium/googleanalytics-premium.php' );
	}

	/**
	 * The ID, from the connected profile first.
	 *
	 * @return string
	 */
	private static function id_found() {
		$profile  = self::array_option( 'monsterinsights_site_profile' );
		$settings = self::array_option( 'monsterinsights_settings' );
		foreach ( array( self::val( $profile, 'v4' ), self::val( $profile, 'manual_v4' ), self::val( $settings, 'manual_v4' ) ) as $id ) {
			if ( '' !== trim( (string) $id ) ) {
				return (string) $id;
			}
		}
		return '';
	}

	public function has_settings() {
		return '' !== self::id_found();
	}

	public function plan() {
		$this->begin();
		$this->offer_id( __( 'Measurement ID', 'perdita-core' ), self::id_found() );
		$this->note( __( 'MonsterInsights\' reports and event tracking for links, forms, and downloads have no match in Perdita. You can track outbound links and downloads with GA4\'s enhanced measurement, which you turn on in Google Analytics.', 'perdita-core' ) );
		$this->note_anonymize();
		return $this->finish();
	}
}
