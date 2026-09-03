<?php
/**
 * PageSpeed Insights module: settings, the PSI API call, and result caching.
 *
 * Admin-only. There is no front-end footprint, no PII, and no visitor data
 * involved: this reads Google's public PageSpeed Insights API about the
 * site's own public pages and shows the result to the site admin.
 *
 * DESIGN NUANCE (secrets): the PSI API key is a Google Cloud credential. A
 * leaked, unrestricted key can run up someone else's API quota or billing,
 * so it is encrypted at rest with Perdita_Crypto rather than stored
 * plaintext, the same pattern the SMTP module uses for its password.
 *
 * This class holds the settings contract (defaults, read, sanitize) and the
 * actual PSI request. The admin screen and its admin-post handlers live in
 * the admin class.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Pagespeed {

	/**
	 * Option name for stored settings.
	 */
	const OPTION = 'perdita_pagespeed_settings';

	/**
	 * How long a PSI result stays cached, in seconds. Google's own quota is
	 * generous, but there is no reason to re-hit the API just because an
	 * admin reloaded the settings screen. Only "Run test now" forces a fresh
	 * call.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * PSI API endpoint.
	 */
	const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * Admin-only module: nothing is hooked here for the front end. The
	 * descriptor's contexts => array( 'admin' ) already keeps this class from
	 * loading on front-end/rest/cron requests, so the constructor has no
	 * front-end branch to guard.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key' => '', // Encrypted blob at rest, see sanitize().
		);
	}

	/**
	 * Read stored settings merged over defaults. The API key stays encrypted
	 * here; callers that need the plaintext call api_key() instead.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Whether an API key is configured at all.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== (string) self::get_settings()['api_key'];
	}

	/**
	 * The decrypted API key, or '' if none is set. Decryption happens only
	 * here, at call time, never for display.
	 *
	 * @return string
	 */
	public static function api_key() {
		$stored = self::get_settings()['api_key'];
		if ( '' === (string) $stored ) {
			return '';
		}
		return Perdita_Crypto::decrypt( $stored );
	}

	/**
	 * Sanitize a settings array on write. Static so the admin class can reuse
	 * it without a front-end instance.
	 *
	 * The API key is treated as an opaque secret: it is never run through text
	 * sanitizers (which can corrupt a token), only trimmed and encrypted.
	 *
	 * @param array $input   Raw input.
	 * @param string $current_encrypted The currently stored encrypted blob, kept when the field is left blank.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input, $current_encrypted ) {
		$input = is_array( $input ) ? $input : array();

		$typed = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';

		if ( ! empty( $input['remove_api_key'] ) ) {
			$api_key = '';
		} elseif ( '' !== $typed ) {
			$api_key = Perdita_Crypto::encrypt( $typed );
		} else {
			// Blank means "keep what's already saved".
			$api_key = (string) $current_encrypted;
		}

		return array(
			'api_key' => $api_key,
		);
	}

	/**
	 * The URL under test. v1 always tests the homepage; a custom-URL field is
	 * left for later.
	 *
	 * @return string
	 */
	public static function url_under_test() {
		return home_url( '/' );
	}

	/**
	 * The transient key for a cached result, keyed by URL and strategy so
	 * mobile and desktop results never collide and a future custom URL will
	 * not collide with the homepage either.
	 *
	 * @param string $url      URL under test.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return string
	 */
	private static function cache_key( $url, $strategy ) {
		return 'perdita_psi_' . md5( $url . '|' . $strategy );
	}

	/**
	 * Run (or reuse a cached) PSI test for one strategy.
	 *
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @param bool   $force    Bypass the cache and hit the API even if a cached result exists.
	 * @return array|WP_Error {
	 *     @type int    $score  Performance score 0-100.
	 *     @type array  $lcp    array( 'value' => float seconds|null, 'source' => 'field'|'lab' ).
	 *     @type array  $cls    array( 'value' => float|null, 'source' => 'field'|'lab' ).
	 *     @type array  $tbt_inp array( 'metric' => 'inp'|'tbt', 'value' => float ms|null, 'source' => 'field'|'lab' ).
	 *     @type bool   $cached Whether this came from the transient cache.
	 * }
	 */
	public static function run_test( $strategy, $force = false ) {
		$strategy = in_array( $strategy, array( 'mobile', 'desktop' ), true ) ? $strategy : 'mobile';
		$url      = self::url_under_test();
		$key      = self::cache_key( $url, $strategy );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$api_key = self::api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'perdita_psi_no_key', __( 'No PageSpeed Insights API key is set yet.', 'perdita-core' ) );
		}

		// add_query_arg() already URL-encodes every value it's given; passing
		// pre-encoded strings here double-encodes them (a URL like
		// "https://example.com/" becomes "https%3A%2F%2F..." instead of the
		// real address), so PSI receives a garbled url/key and the test fails.
		$request_url = add_query_arg(
			array(
				'url'      => $url,
				'key'      => $api_key,
				'strategy' => $strategy,
				'category' => 'performance',
			),
			self::API_URL
		);

		// PSI can take a while to run Lighthouse server-side, so this uses a
		// longer timeout than a typical API call.
		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'perdita_psi_unreachable',
				sprintf(
					/* translators: %s: underlying HTTP error message */
					__( 'Could not reach PageSpeed Insights: %s', 'perdita-core' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			// Google returns a structured error body on 400 (bad URL) and 403
			// (bad/restricted key). Surface its exact message when present
			// rather than a generic failure, so the admin knows what to fix.
			$message = '';
			if ( is_array( $data ) && isset( $data['error']['message'] ) ) {
				$message = (string) $data['error']['message'];
			}
			if ( '' === $message ) {
				/* translators: %d: HTTP response code */
				$message = sprintf( __( 'PageSpeed Insights returned an unexpected response (HTTP %d).', 'perdita-core' ), $code );
			}
			return new WP_Error( 'perdita_psi_api_error', $message );
		}

		if ( ! is_array( $data ) || empty( $data['lighthouseResult'] ) ) {
			return new WP_Error( 'perdita_psi_malformed', __( 'PageSpeed Insights returned a response we could not read.', 'perdita-core' ) );
		}

		$result = self::extract_metrics( $data );

		set_transient( $key, $result, self::CACHE_TTL );

		$result['cached'] = false;
		return $result;
	}

	/**
	 * Pull the performance score and Core Web Vitals out of a raw PSI
	 * response. Field data (real visitor data from the Chrome UX Report, in
	 * loadingExperience.metrics) is preferred over lab data (a single
	 * simulated Lighthouse run, in lighthouseResult.audits) whenever Google
	 * has enough real-world traffic to report it.
	 *
	 * @param array $data Decoded JSON response.
	 * @return array
	 */
	private static function extract_metrics( array $data ) {
		$lighthouse = isset( $data['lighthouseResult'] ) && is_array( $data['lighthouseResult'] ) ? $data['lighthouseResult'] : array();
		$audits     = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$field      = isset( $data['loadingExperience']['metrics'] ) && is_array( $data['loadingExperience']['metrics'] ) ? $data['loadingExperience']['metrics'] : array();

		$score = null;
		if ( isset( $lighthouse['categories']['performance']['score'] ) && is_numeric( $lighthouse['categories']['performance']['score'] ) ) {
			$score = (int) round( (float) $lighthouse['categories']['performance']['score'] * 100 );
		}

		return array(
			'score' => $score,
			'lcp'   => self::metric_lcp( $field, $audits ),
			'cls'   => self::metric_cls( $field, $audits ),
			'tbt_inp' => self::metric_tbt_inp( $field, $audits ),
		);
	}

	/**
	 * Largest Contentful Paint, in seconds. Field data key is
	 * LARGEST_CONTENTFUL_PAINT_MS, lab audit is 'largest-contentful-paint'
	 * (numericValue in milliseconds).
	 *
	 * @param array $field  loadingExperience.metrics.
	 * @param array $audits lighthouseResult.audits.
	 * @return array{value: float|null, source: string}
	 */
	private static function metric_lcp( array $field, array $audits ) {
		if ( isset( $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) && is_numeric( $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) ) {
			return array(
				'value'  => round( (float) $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] / 1000, 2 ),
				'source' => 'field',
			);
		}
		if ( isset( $audits['largest-contentful-paint']['numericValue'] ) && is_numeric( $audits['largest-contentful-paint']['numericValue'] ) ) {
			return array(
				'value'  => round( (float) $audits['largest-contentful-paint']['numericValue'] / 1000, 2 ),
				'source' => 'lab',
			);
		}
		return array(
			'value'  => null,
			'source' => 'lab',
		);
	}

	/**
	 * Cumulative Layout Shift, unitless. Field data key is
	 * CUMULATIVE_LAYOUT_SHIFT_SCORE (a decimal string), lab audit is
	 * 'cumulative-layout-shift' (numericValue).
	 *
	 * @param array $field  loadingExperience.metrics.
	 * @param array $audits lighthouseResult.audits.
	 * @return array{value: float|null, source: string}
	 */
	private static function metric_cls( array $field, array $audits ) {
		if ( isset( $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) && is_numeric( $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) ) {
			// Field CLS is reported *100 (e.g. 12 means 0.12).
			return array(
				'value'  => round( (float) $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100, 3 ),
				'source' => 'field',
			);
		}
		if ( isset( $audits['cumulative-layout-shift']['numericValue'] ) && is_numeric( $audits['cumulative-layout-shift']['numericValue'] ) ) {
			return array(
				'value'  => round( (float) $audits['cumulative-layout-shift']['numericValue'], 3 ),
				'source' => 'lab',
			);
		}
		return array(
			'value'  => null,
			'source' => 'lab',
		);
	}

	/**
	 * Interaction to Next Paint when field data has it (Google's current
	 * Core Web Vital, replacing FID), else Total Blocking Time from the lab
	 * audit as the closest responsiveness proxy PSI's Lighthouse run offers.
	 * Both are reported in milliseconds.
	 *
	 * @param array $field  loadingExperience.metrics.
	 * @param array $audits lighthouseResult.audits.
	 * @return array{metric: string, value: float|null, source: string}
	 */
	private static function metric_tbt_inp( array $field, array $audits ) {
		if ( isset( $field['INTERACTION_TO_NEXT_PAINT']['percentile'] ) && is_numeric( $field['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ) {
			return array(
				'metric' => 'inp',
				'value'  => (float) $field['INTERACTION_TO_NEXT_PAINT']['percentile'],
				'source' => 'field',
			);
		}
		if ( isset( $audits['total-blocking-time']['numericValue'] ) && is_numeric( $audits['total-blocking-time']['numericValue'] ) ) {
			return array(
				'metric' => 'tbt',
				'value'  => (float) $audits['total-blocking-time']['numericValue'],
				'source' => 'lab',
			);
		}
		return array(
			'metric' => 'tbt',
			'value'  => null,
			'source' => 'lab',
		);
	}

	/**
	 * Clear cached results for both strategies for the current URL under
	 * test. Used before a forced "Run test now" so the fresh call always
	 * lands in the transient even if something upstream errors out.
	 */
	public static function clear_cache() {
		$url = self::url_under_test();
		delete_transient( self::cache_key( $url, 'mobile' ) );
		delete_transient( self::cache_key( $url, 'desktop' ) );
	}
}
