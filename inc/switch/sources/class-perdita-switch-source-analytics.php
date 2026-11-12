<?php
/**
 * Shared parts of the analytics sources: the GA4 measurement ID.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_Analytics_Source extends Perdita_Switch_Source {

	public function module() {
		return 'analytics';
	}

	/**
	 * Offer a measurement ID. A Universal Analytics ID (UA-) is skipped:
	 * Google stopped processing those in 2024.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $id      ID.
	 */
	protected function offer_id( $setting, $id ) {
		$id = is_scalar( $id ) ? strtoupper( trim( (string) $id ) ) : '';
		if ( '' === $id ) {
			return;
		}
		if ( 0 === strpos( $id, 'UA-' ) ) {
			$this->skip( $setting, $id, __( 'This is a Universal Analytics ID, and Google stopped collecting data for those in 2024. Create a GA4 property and enter its G- ID on Perdita, Analytics.', 'perdita-core' ), __( 'Measurement ID', 'perdita-core' ) );
			return;
		}
		$this->offer(
			$setting,
			$id,
			'measurement_id',
			$id,
			__( 'Measurement ID', 'perdita-core' ),
			array( 'refused' => __( 'You need a GA4 measurement ID that starts with G-.', 'perdita-core' ) )
		);
	}

	/**
	 * The note every analytics card carries.
	 */
	protected function note_anonymize() {
		$this->note( __( 'Google never stores full IP addresses in GA4, so you don\'t need an IP anonymizing setting in Perdita.', 'perdita-core' ) );
	}
}
