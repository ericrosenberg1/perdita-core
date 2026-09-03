/* global PerditaForms */
/**
 * Perdita Forms front-end submit. Posts the form over REST and shows the result
 * inline. Sends the wp_rest nonce so a logged-in submitter stays authenticated
 * (without it, WordPress drops cookie auth to user 0 and the form's own nonce
 * then fails to verify), while logged-out visitors still work.
 */
( function () {
	'use strict';

	function init( form ) {
		var id = form.getAttribute( 'data-form' );
		var msg = form.querySelector( '.perdita-form__message' );
		var submit = form.querySelector( '.perdita-form__submit' );
		var spinner = form.querySelector( '.perdita-form__spinner' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}
			msg.textContent = '';
			msg.className = 'perdita-form__message';
			submit.disabled = true;
			if ( spinner ) {
				spinner.hidden = false;
			}

			fetch( PerditaForms.rest + id, {
				method: 'POST',
				body: new FormData( form ),
				headers: { Accept: 'application/json', 'X-WP-Nonce': PerditaForms.nonce }
			} ).then( function ( r ) {
				return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } );
			} ).then( function ( res ) {
				msg.textContent = res.j && res.j.message ? res.j.message : '';
				msg.classList.add( ( res.j && res.j.ok ) ? 'is-success' : 'is-error' );
				if ( res.j && res.j.ok ) {
					form.reset();
				}
			} ).catch( function () {
				msg.textContent = PerditaForms.strings.networkError;
				msg.classList.add( 'is-error' );
			} ).finally( function () {
				submit.disabled = false;
				if ( spinner ) {
					spinner.hidden = true;
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.perdita-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			init( forms[ i ] );
		}
	} );
} )();
