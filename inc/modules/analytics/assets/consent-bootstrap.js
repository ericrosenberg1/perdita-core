/**
 * Perdita Analytics: Google Consent Mode v2.
 *
 * Inlined at the top of <head>, before gtag.js or any other Google tag loads,
 * after window.perditaAnalytics = { id, model, adSignals, respectDnt }
 * (print_consent_bootstrap()).
 *
 * Opt-in (model 'opt_in'): every storage type starts denied unless the
 * visitor accepted on an earlier page. Opt-out (model 'opt_out'): analytics
 * starts granted unless the visitor opted out. In both, the visitor's state
 * goes into the consent default itself, the first command in the queue and
 * ahead of gtag('config'). config sends the page_view, and a page_view sent
 * while denied is one GA4 never reports, so no later update can fix it.
 * Global Privacy Control always keeps analytics off, and so does Do Not
 * Track when the site respects it. The three ad signals follow analytics
 * only when adSignals is on, and are denied otherwise. Every consent command
 * sets all four.
 *
 * With no id, this manages consent for Google tags other plugins add and
 * loads nothing itself. A choice stored by Simple Consent Manager
 * (scm_consent=accepted|declined) counts when perdita_consent is unset, and
 * is copied into perdita_consent so the visitor is not asked again. When
 * SCM's own banner is still on the page (scmBanner, this module's banner is
 * off), scm_consent is the live choice and is read first, never copied.
 *
 * Sets cfg.pvGranted when config sent this page's page_view with analytics
 * granted, so the banner never resends a page_view that already counted.
 *
 * The cookie is read here, in the browser, because pages come from page
 * caches.
 */
( function () {
	'use strict';

	var cfg = window.perditaAnalytics || {};
	var id = cfg.id || '';
	var optOut = 'opt_out' === cfg.model;
	var ads = true === cfg.adSignals;

	window.dataLayer = window.dataLayer || [];
	window.gtag =
		window.gtag ||
		function () {
			window.dataLayer.push( arguments );
		};
	var gtag = window.gtag;

	var nav = window.navigator || {};
	var gpc = true === nav.globalPrivacyControl;
	var dnt =
		false !== cfg.respectDnt &&
		( '1' === nav.doNotTrack || '1' === window.doNotTrack || '1' === nav.msDoNotTrack );
	var signal = gpc || dnt;

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]+)' ) );
		if ( ! m ) {
			return '';
		}
		try {
			return decodeURIComponent( m[ 1 ] );
		} catch ( e ) {
			return '';
		}
	}

	var scm = readCookie( 'scm_consent' );
	var fromScm = 'accepted' === scm ? 'granted' : 'declined' === scm ? 'denied' : '';
	var choice = readCookie( 'perdita_consent' );
	if ( true === cfg.scmBanner && fromScm ) {
		choice = fromScm;
	} else if ( 'granted' !== choice && 'denied' !== choice ) {
		choice = '';
		if ( fromScm ) {
			choice = fromScm;
			document.cookie =
				'perdita_consent=' +
				choice +
				'; Max-Age=31536000; Path=/; SameSite=Lax' +
				( 'https:' === ( window.location || {} ).protocol ? '; Secure' : '' );
		}
	}

	var state = ! signal && ( 'granted' === choice || ( '' === choice && optOut ) ) ? 'granted' : 'denied';

	function signals( s ) {
		var a = ads ? s : 'denied';
		return { analytics_storage: s, ad_storage: a, ad_user_data: a, ad_personalization: a };
	}

	// The default carries the visitor's state in both models, a stored choice
	// included, so config below runs under it with no update in between.
	var initial = signals( state );
	if ( ! id ) {
		// Other plugins' tags may load before anything else runs here.
		initial.wait_for_update = 500;
	}
	gtag( 'consent', 'default', initial );
	gtag( 'set', 'ads_data_redaction', true );
	if ( id ) {
		gtag( 'js', new Date() );
		gtag( 'config', id );
		cfg.pvGranted = 'granted' === state;
	}

	// Read by the banner, which stays hidden for 'dnt' or a stored choice.
	document.documentElement.setAttribute( 'data-perdita-consent', signal ? 'dnt' : choice || 'unset' );
} )();
