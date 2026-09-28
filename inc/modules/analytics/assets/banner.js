/**
 * Perdita Analytics consent banner.
 *
 * Reveals the banner only when there is no stored choice and no privacy signal
 * (Global Privacy Control, or a respected Do Not Track) is blocking. The head
 * bootstrap has already read the cookie (including a Simple Consent Manager
 * choice) and marked the result on <html data-perdita-consent>.
 *
 * Accept stores the choice for a year and grants analytics (plus the ad
 * signals when the site turned them on). If this page's page_view has not
 * gone out granted yet, which is every opt-in first visit, it also resends
 * it, because GA4 does not report one sent while denied. A page-level flag
 * (perditaAnalytics.pvGranted, set by the bootstrap and here) makes sure
 * that happens at most once per page, however often the visitor switches.
 * Decline (or Opt out) stores a denied choice and turns every signal off.
 *
 * Once a choice is stored, a small Cookie Preferences button reopens the
 * banner, and so does any link to #perdita-cookie-preferences or any element
 * with the perdita-cookie-preferences class. Escape closes the banner when
 * focus is inside it, and saves no choice, so an Escape meant for a menu or a
 * dialog never records a year-long decline. The banner does not trap focus. When it was reopened from a link or
 * button, focus moves into it, and goes back to that control on close.
 */
( function () {
	'use strict';

	var COOKIE = 'perdita_consent';
	var YEAR_SECONDS = 60 * 60 * 24 * 365;
	var cfg = window.perditaAnalytics || {};
	var ads = true === cfg.adSignals;
	var root = document.documentElement;
	var banner = null;
	var prefs = null;
	var opener = null;

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

	function writeCookie( name, value ) {
		var secure = 'https:' === window.location.protocol ? '; Secure' : '';
		document.cookie =
			name +
			'=' +
			encodeURIComponent( value ) +
			'; Max-Age=' +
			YEAR_SECONDS +
			'; Path=/; SameSite=Lax' +
			secure;
	}

	function gtagSafe() {
		if ( typeof window.gtag === 'function' ) {
			return window.gtag;
		}
		// Fall back to the dataLayer shim the bootstrap defined. If neither is
		// present, tracking was not configured, so consent updates are a no-op.
		return function () {
			if ( window.dataLayer && window.dataLayer.push ) {
				window.dataLayer.push( arguments );
			}
		};
	}

	function signals( s ) {
		var a = ads ? s : 'denied';
		return { analytics_storage: s, ad_storage: a, ad_user_data: a, ad_personalization: a };
	}

	function blocked() {
		return 'dnt' === root.getAttribute( 'data-perdita-consent' );
	}

	function storedChoice() {
		var c = root.getAttribute( 'data-perdita-consent' );
		if ( 'granted' === c || 'denied' === c ) {
			return c;
		}
		c = readCookie( COOKIE );
		return 'granted' === c || 'denied' === c ? c : '';
	}

	function isShown( el ) {
		return !! el && ! el.hasAttribute( 'hidden' );
	}

	function showPrefs() {
		if ( prefs ) {
			prefs.removeAttribute( 'hidden' );
		}
	}

	function hidePrefs() {
		if ( prefs ) {
			prefs.setAttribute( 'hidden', 'hidden' );
		}
	}

	function close() {
		var returnTo = opener;
		opener = null;
		banner.setAttribute( 'hidden', 'hidden' );
		// Shown before focus returns, since the preferences button may be the
		// control that opened the banner.
		showPrefs();
		if ( returnTo && typeof returnTo.focus === 'function' ) {
			returnTo.focus();
		}
	}

	function open( from ) {
		opener = from || null;
		hidePrefs();
		banner.removeAttribute( 'hidden' );
		if ( opener ) {
			var first = banner.querySelector( 'button' );
			if ( first && typeof first.focus === 'function' ) {
				first.focus();
			}
		}
	}

	function decide( choice ) {
		var gtag = gtagSafe();
		writeCookie( COOKIE, choice );
		gtag( 'consent', 'update', signals( choice ) );
		if ( 'granted' === choice && cfg.id && true !== cfg.pvGranted ) {
			gtag( 'event', 'page_view' );
			cfg.pvGranted = true;
		}
		root.setAttribute( 'data-perdita-consent', choice );
		close();
	}

	function isPreferencesTrigger( el ) {
		if ( ! el || 1 !== el.nodeType ) {
			return false;
		}
		if ( el === prefs ) {
			return true;
		}
		if ( el.classList && el.classList.contains( 'perdita-cookie-preferences' ) ) {
			return true;
		}
		var href = el.getAttribute && el.getAttribute( 'href' );
		return !! href && '#perdita-cookie-preferences' === href.slice( href.indexOf( '#' ) );
	}

	function onClick( event ) {
		var el = event.target;
		while ( el && el !== document && ! isPreferencesTrigger( el ) ) {
			el = el.parentNode;
		}
		if ( ! el || el === document ) {
			return;
		}
		event.preventDefault();
		if ( blocked() ) {
			return;
		}
		open( el );
	}

	function onKeydown( event ) {
		if ( ( 'Escape' !== event.key && 'Esc' !== event.key ) || ! isShown( banner ) || event.defaultPrevented ) {
			return;
		}
		// Only an Escape pressed inside the banner is meant for it. It closes
		// the banner and saves nothing, so with no stored choice the banner
		// comes back on the next page.
		if ( ! banner.contains( document.activeElement ) ) {
			return;
		}
		close();
	}

	function init() {
		banner = document.getElementById( 'perdita-consent-banner' );
		prefs = document.getElementById( 'perdita-consent-prefs' );
		if ( ! banner ) {
			return;
		}

		var accept = banner.querySelector( '[data-perdita-consent="granted"]' );
		var decline = banner.querySelector( '[data-perdita-consent="denied"]' );
		if ( accept ) {
			accept.addEventListener( 'click', function () {
				decide( 'granted' );
			} );
		}
		if ( decline ) {
			decline.addEventListener( 'click', function () {
				decide( 'denied' );
			} );
		}
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'keydown', onKeydown );

		// No banner and no preferences button under a privacy signal: no
		// choice made here would change anything.
		if ( blocked() ) {
			banner.setAttribute( 'hidden', 'hidden' );
			hidePrefs();
			return;
		}
		if ( storedChoice() ) {
			banner.setAttribute( 'hidden', 'hidden' );
			showPrefs();
			return;
		}
		hidePrefs();
		banner.removeAttribute( 'hidden' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
