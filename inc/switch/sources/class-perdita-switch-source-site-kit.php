<?php
/**
 * Site Kit by Google: the GA4 measurement ID from its Analytics module.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Site_Kit extends Perdita_Switch_Analytics_Source {

	public function id() {
		return 'site-kit';
	}

	public function label() {
		return 'Site Kit by Google';
	}

	public function plugin_files() {
		return array( 'google-site-kit/google-site-kit.php' );
	}

	public function has_settings() {
		$o = get_option( 'googlesitekit_analytics-4_settings' );
		return is_array( $o ) && ! empty( $o['measurementID'] );
	}

	public function plan() {
		$this->begin();
		$o = self::array_option( 'googlesitekit_analytics-4_settings' );
		if ( ! $o ) {
			return $this->finish();
		}
		$this->offer_id( __( 'Measurement ID', 'perdita-core' ), self::val( $o, 'measurementID' ) );
		if ( isset( $o['useSnippet'] ) && ! self::on( $o['useSnippet'] ) ) {
			$this->note( __( 'You had Site Kit\'s Google tag switched off, so you may already add the tag through your theme or another plugin. Check before you turn on Perdita, Analytics, or you\'ll count every visit twice.', 'perdita-core' ) );
		}
		$this->note( __( 'Site Kit\'s dashboards for Search Console, AdSense, and PageSpeed have no match in Perdita. Use those tools on Google\'s own sites.', 'perdita-core' ) );
		$this->note_anonymize();
		return $this->finish();
	}
}
