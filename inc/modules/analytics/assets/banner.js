/**
 * Perdita Analytics consent banner.
 *
 * Reveals the banner only when there is no stored choice and Do Not Track is
 * not blocking. On Accept, stores the choice for a year and grants Consent Mode
 * v2 storage. On Decline, stores a denied choice and leaves storage denied.
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
		gtagSafe()( 'consent', 'update', {
			analytics_storage: 'granted',
			ad_storage: 'granted',
			ad_user_data: 'granted',
			ad_personalization: 'granted'
		} );
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
		}
		hide( banner );
	}

	function init() {
		var banner = document.getElementById( 'perdita-consent-banner' );
		if ( ! banner ) {
			return;
		}

		// Do not show if the browser is under a respected Do Not Track signal, or
		// if a choice is already stored.
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
