/* global jQuery, wp */
/**
 * Generic media picker for admin pages. Wire a button with
 * data-input="<hidden input id>" and data-preview="<img id>".
 */
( function ( $ ) {
	'use strict';
	$( function () {
		var frames = {};

		$( '.perdita-media-pick' ).on( 'click', function ( e ) {
			e.preventDefault();
			var input = $( this ).data( 'input' );
			var preview = $( this ).data( 'preview' );
			if ( ! frames[ input ] ) {
				frames[ input ] = wp.media( { title: 'Select image', multiple: false, library: { type: 'image' } } );
				frames[ input ].on( 'select', function () {
					var att = frames[ input ].state().get( 'selection' ).first().toJSON();
					var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
					$( '#' + input ).val( att.id );
					$( '#' + preview ).attr( 'src', url ).prop( 'hidden', false );
					$( '.perdita-media-clear[data-input="' + input + '"]' ).prop( 'hidden', false );
				} );
			}
			frames[ input ].open();
		} );

		$( '.perdita-media-clear' ).on( 'click', function () {
			var input = $( this ).data( 'input' );
			$( '#' + input ).val( '0' );
			$( '#' + $( this ).data( 'preview' ) ).attr( 'src', '' ).prop( 'hidden', true );
			$( this ).prop( 'hidden', true );
		} );
	} );
} )( jQuery );
