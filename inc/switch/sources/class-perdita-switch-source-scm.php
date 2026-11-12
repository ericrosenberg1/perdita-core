<?php
/**
 * Simple Consent Manager: its consent behavior and banner color. Perdita
 * Analytics adopts these once by itself (Perdita_Analytics::maybe_adopt_scm())
 * on a site that never chose a consent model, and this card shows the same
 * adoption and lets the owner run it, undo it, and deactivate SCM.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_SCM extends Perdita_Switch_Analytics_Source {

	public function id() {
		return 'scm';
	}

	public function label() {
		return 'Simple Consent Manager';
	}

	public function plugin_files() {
		return array( 'simple-consent-manager/simple-consent-manager.php', 'simple-consent-manager.php' );
	}

	public function has_settings() {
		return Perdita_Switch_Target::load( 'analytics' ) && Perdita_Analytics::scm_active();
	}

	public function conflict_note() {
		return __( 'While Simple Consent Manager is active, its consent script is switched off, so you have one consent default on each page. Its banner is switched off too whenever you use Perdita\'s banner.', 'perdita-core' );
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() ) {
			return $this->finish();
		}
		$adopt = Perdita_Analytics::scm_adoption( Perdita_Analytics::raw_settings() );
		if ( null === $adopt ) {
			$this->note( __( 'You already have a consent model in Perdita Analytics, so you have no settings to bring over from Simple Consent Manager.', 'perdita-core' ) );
			return $this->finish();
		}
		$models = array(
			Perdita_Analytics::MODEL_OPT_IN  => __( 'opt in', 'perdita-core' ),
			Perdita_Analytics::MODEL_OPT_OUT => __( 'opt out', 'perdita-core' ),
		);
		$model  = (string) $adopt['consent_model'];
		$this->offer( __( 'Consent behavior', 'perdita-core' ), isset( $models[ $model ] ) ? $models[ $model ] : $model, 'consent_model', $model, __( 'Consent model', 'perdita-core' ) );
		$this->offer( __( 'Consent without a measurement ID', 'perdita-core' ), (bool) $adopt['manage_without_id'], 'manage_without_id', (bool) $adopt['manage_without_id'], __( 'Manage consent for other Google tags', 'perdita-core' ) );
		if ( array_key_exists( 'ad_signals', $adopt ) ) {
			$this->offer( __( 'Ad signals', 'perdita-core' ), (bool) $adopt['ad_signals'], 'ad_signals', (bool) $adopt['ad_signals'], __( 'Ad signals', 'perdita-core' ) );
		}
		if ( ! empty( $adopt['accent_color'] ) ) {
			$this->offer( 'SCM_ACCENT_COLOR', $adopt['accent_color'], 'accent_color', (string) $adopt['accent_color'], __( 'Banner accent color', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
