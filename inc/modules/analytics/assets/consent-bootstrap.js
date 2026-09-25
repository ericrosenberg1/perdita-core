/**
 * Perdita Analytics: GA4 with Google Consent Mode v2.
 *
 * Inlined at the top of <head>, before gtag.js loads, after
 * window.perditaAnalytics = { id, respectDnt } (print_consent_bootstrap()).
 *
 * Opt-in: every storage type starts denied. A visitor's stored Accept turns
 * analytics storage on BEFORE gtag('config'), because config sends the
 * page_view and a page_view sent while denied is one GA4 never reports. Global
 * Privacy Control always keeps analytics off, and so does Do Not Track when
 * the site respects it. Ad storage is never granted.
 */
( function () {
	'use strict';

	var cfg = window.perditaAnalytics || {};
	var id = cfg.id;
	if ( ! id ) {
		return;
	}

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

	var choice = '';
	var m = document.cookie.match( /(?:^|;\s*)perdita_consent=([^;]+)/ );
	if ( m ) {
		try {
			choice = decodeURIComponent( m[ 1 ] );
		} catch ( e ) {
			choice = '';
		}
	}

	gtag( 'consent', 'default', {
		analytics_storage: 'denied',
		ad_storage: 'denied',
		ad_user_data: 'denied',
		ad_personalization: 'denied'
	} );
	gtag( 'set', 'ads_data_redaction', true );
	if ( ! signal && 'granted' === choice ) {
		gtag( 'consent', 'update', { analytics_storage: 'granted' } );
	}
	gtag( 'js', new Date() );
	gtag( 'config', id );

	// Read by the banner, which stays hidden for 'dnt' or a stored choice.
	document.documentElement.setAttribute(
		'data-perdita-consent',
		signal ? 'dnt' : ( 'granted' === choice || 'denied' === choice ) ? choice : 'unset'
	);
} )();
