/* global PerditaSubscribe */
/**
 * Perdita Subscriptions front-end submit. Posts the signup form over REST and
 * shows the result inline. This endpoint must also work for logged-out
 * visitors, so it sends a dedicated per-shortcode nonce as a form field
 * rather than the cookie-bound X-WP-Nonce header Perdita Forms uses.
 */
( function () {
	'use strict';

	function init( form ) {
		var msg = form.querySelector( '.perdita-subscribe__message' );
		var submit = form.querySelector( '.perdita-subscribe__submit' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}
			msg.textContent = '';
			msg.className = 'perdita-subscribe__message';
			submit.disabled = true;

			fetch( PerditaSubscribe.rest, {
				method: 'POST',
				body: new FormData( form ),
				headers: { Accept: 'application/json' }
			} ).then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } );
			} ).then( function ( res ) {
				msg.textContent = res.j && res.j.message ? res.j.message : '';
				msg.classList.add( ( res.j && res.j.ok ) ? 'is-success' : 'is-error' );
				if ( res.j && res.j.ok ) {
					form.reset();
				}
			} ).catch( function () {
				msg.textContent = PerditaSubscribe.strings.networkError;
				msg.classList.add( 'is-error' );
			} ).finally( function () {
				submit.disabled = false;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.perdita-subscribe' );
		for ( var i = 0; i < forms.length; i++ ) {
			init( forms[ i ] );
		}
	} );
} )();
