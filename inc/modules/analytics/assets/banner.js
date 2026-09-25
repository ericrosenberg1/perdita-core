/**
 * Perdita Analytics consent banner.
 *
 * Reveals the banner only when there is no stored choice and no privacy signal
 * (Global Privacy Control, or a respected Do Not Track) is blocking. On Accept,
 * stores the choice for a year, grants analytics storage and resends this
 * page's page_view, which went out before consent and which GA4 does not
 * report. That keeps one-page visits and their referrer. On Decline, stores a
 * denied choice and leaves analytics off. Ad storage is never granted.
 *
 * The banner does not trap focus. Keyboard users can Tab through the buttons
 * and continue into the page, and Escape dismisses the banner as a decline.
 */
( function () {
	'use strict';

	var COOKIE = 'perdita_consent';
	var YEAR_SECONDS = 60 * 60 * 24 * 365;

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]+)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
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

	function grant() {
		var gtag = gtagSafe();
		gtag( 'consent', 'update', { analytics_storage: 'granted' } );
		gtag( 'event', 'page_view' );
	}

	function deny() {
		gtagSafe()( 'consent', 'update', { analytics_storage: 'denied' } );
	}

	function dntBlocking() {
		var root = document.documentElement;
		return 'dnt' === root.getAttribute( 'data-perdita-consent' );
	}

	function hide( banner ) {
		banner.setAttribute( 'hidden', 'hidden' );
	}

	function decide( banner, choice ) {
		writeCookie( COOKIE, choice );
		document.documentElement.setAttribute( 'data-perdita-consent', choice );
		if ( 'granted' === choice ) {
			grant();
		} else {
			deny();
		}
		hide( banner );
	}

	function init() {
		var banner = document.getElementById( 'perdita-consent-banner' );
		if ( ! banner ) {
			return;
		}

		// Do not show under a privacy signal (the bootstrap marks it 'dnt'), or
		// when a choice is already stored.
		if ( dntBlocking() ) {
			hide( banner );
			return;
		}
		var stored = readCookie( COOKIE );
		if ( 'granted' === stored || 'denied' === stored ) {
			hide( banner );
			return;
		}

		var accept = banner.querySelector( '[data-perdita-consent="granted"]' );
		var decline = banner.querySelector( '[data-perdita-consent="denied"]' );

		if ( accept ) {
			accept.addEventListener( 'click', function () {
				decide( banner, 'granted' );
			} );
		}
		if ( decline ) {
			decline.addEventListener( 'click', function () {
				decide( banner, 'denied' );
			} );
		}

		// Escape counts as a decline, so the banner can always be dismissed from
		// the keyboard without forcing a choice to accept.
		banner.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				decide( banner, 'denied' );
			}
		} );

		banner.removeAttribute( 'hidden' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
