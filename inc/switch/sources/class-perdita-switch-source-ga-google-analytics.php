<?php
/**
 * GA Google Analytics (Jeff Starr's plugin): the tracking ID in gap_options.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_GA_Google extends Perdita_Switch_Analytics_Source {

	public function id() {
		return 'ga-google-analytics';
	}

	public function label() {
		return 'GA Google Analytics';
	}

	public function plugin_files() {
		return array( 'ga-google-analytics/ga-google-analytics.php' );
	}

	public function has_settings() {
		$o = get_option( 'gap_options' );
		return is_array( $o ) && ! empty( $o['gap_id'] );
	}

	public function plan() {
		$this->begin();
		$o = self::array_option( 'gap_options' );
		$this->offer_id( __( 'GA Tracking ID', 'perdita-core' ), self::val( $o, 'gap_id' ) );
		foreach ( array(
			'gap_custom'      => __( 'Custom Code', 'perdita-core' ),
			'gap_custom_code' => __( 'Custom Tracker Code', 'perdita-core' ),
		) as $key => $name ) {
			if ( '' !== trim( (string) self::val( $o, $key ) ) ) {
				$this->unsupported( $name, self::val( $o, $key ), __( 'You can\'t add custom script code in Perdita. Add the code to your theme if you still need the script.', 'perdita-core' ) );
			}
		}
		$this->note_anonymize();
		return $this->finish();
	}
}
