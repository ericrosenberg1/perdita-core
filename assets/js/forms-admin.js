/* global jQuery */
/**
 * Perdita Forms field builder: add/remove rows and auto-fill the field name
 * from the label.
 */
( function ( $ ) {
	'use strict';
	$( function () {
		var $rows = $( '.perdita-fields__rows' );
		if ( ! $rows.length ) {
			return;
		}
		var i = $rows.find( '.perdita-fields__row' ).length;
		var tpl = $( '#perdita-field-row-tpl' ).html();

		$( '.perdita-fields__add' ).on( 'click', function () {
			$rows.append( tpl.replace( /__i__/g, 'n' + i ) );
			i++;
		} );

		$rows.on( 'click', '.perdita-fields__remove', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		function slug( s ) {
			return s.toString().toLowerCase().trim().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
		}

		// Auto-fill the name from the label when the name is still empty.
		$rows.on( 'blur', '.perdita-field-label', function () {
			var $row = $( this ).closest( 'tr' );
			var $name = $row.find( '.perdita-field-name' );
			if ( ! $name.val() ) {
				$name.val( slug( $( this ).val() ) );
			}
		} );
	} );
} )( jQuery );
