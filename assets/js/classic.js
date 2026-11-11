/* global jQuery, wp, lbSpClassic */
( function ( $ ) {
	'use strict';

	var frame;
	var $id = $( '#lb-sp-audio-id' );
	var $summary = $( '#lb-sp-audio-summary' );

	$( '#lb-sp-audio-select' ).on( 'click', function ( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: lbSpClassic.frameTitle,
				button: { text: lbSpClassic.frameButton },
				library: { type: lbSpClassic.mime },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( attachment.mime !== lbSpClassic.mime ) {
					window.alert( lbSpClassic.mimeError );
					return;
				}
				$id.val( attachment.id );
				$summary.text( attachment.filename || attachment.title );
			} );
		}

		frame.open();
	} );

	$( '#lb-sp-audio-remove' ).on( 'click', function ( event ) {
		event.preventDefault();
		$id.val( '' );
		$summary.text( '' );
	} );
} )( jQuery );
