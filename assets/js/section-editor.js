/* global wp, PerditaSection */
/**
 * Perdita "describe a section" editor sidebar. Sends a plain-language
 * description to the connected AI provider and inserts the generated blocks.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.editPost ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = wp.editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;

	// No wp.blocks.rawHandler fallback here on purpose: that's Gutenberg's
	// paste-raw-HTML-as-blocks path, and it will happily turn arbitrary HTML
	// (including a script tag) into a Custom HTML block that renders live in
	// the editor canvas. The server already validates the markup against a
	// block-name allow-list before it reaches this function, so a response
	// that fails to parse into named blocks is treated as an error, not
	// silently upgraded to raw HTML.
	function toBlocks( markup ) {
		var blocks = wp.blocks.parse( markup );
		return ( blocks || [] ).filter( function ( b ) { return b && b.name; } );
	}

	function Panel() {
		var d = useState( '' ); var desc = d[0], setDesc = d[1];
		var b = useState( false ); var busy = b[0], setBusy = b[1];
		var e = useState( '' ); var err = e[0], setErr = e[1];

		if ( ! PerditaSection.ready ) {
			return el( 'div', { style: { padding: '16px' } },
				el( 'p', {}, __( 'Connect an AI provider to use this.', 'perdita' ) ),
				el( 'a', { href: PerditaSection.setup, target: '_blank', rel: 'noopener' }, __( 'Open Perdita settings', 'perdita' ) )
			);
		}

		function generate() {
			if ( ! desc ) { return; }
			setBusy( true ); setErr( '' );
			wp.apiFetch( { path: '/perdita/v1/section', method: 'POST', data: { description: desc } } )
				.then( function ( res ) {
					var blocks = toBlocks( ( res && res.markup ) || '' );
					if ( ! blocks.length ) {
						setErr( __( 'No blocks came back. Try a richer description.', 'perdita' ) );
						return;
					}
					wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
					setDesc( '' );
				} )
				.catch( function ( x ) { setErr( ( x && x.message ) || __( 'Request failed.', 'perdita' ) ); } )
				.finally( function () { setBusy( false ); } );
		}

		return el( 'div', { style: { padding: '16px' } },
			el( 'p', {}, __( 'Describe a section and Perdita builds it from blocks, then inserts it.', 'perdita' ) ),
			el( TextareaControl, {
				label: __( 'Describe the section', 'perdita' ),
				value: desc,
				onChange: setDesc,
				rows: 4,
				placeholder: __( 'e.g. a hero with a headline, one sentence, and two buttons', 'perdita' )
			} ),
			err ? el( Notice, { status: 'error', isDismissible: false }, err ) : null,
			el( Button, { variant: 'primary', onClick: generate, disabled: busy || ! desc },
				busy ? el( Spinner ) : __( 'Generate section', 'perdita' )
			)
		);
	}

	registerPlugin( 'perdita-section', {
		render: function () {
			return el( Fragment, {},
				el( PluginSidebarMoreMenuItem, { target: 'perdita-section', icon: 'superhero' }, __( 'Perdita: describe a section', 'perdita' ) ),
				el( PluginSidebar, { name: 'perdita-section', title: __( 'Describe a section', 'perdita' ), icon: 'superhero' }, el( Panel ) )
			);
		}
	} );
} )( window.wp );
