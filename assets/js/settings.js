/* global jQuery, wp, lbSpSettings */
( function ( $ ) {
	'use strict';

	var frame;
	var $id = $( '#lb-sp-image-id' );
	var $preview = $( '#lb-sp-image-preview' );

	$( '#lb-sp-image-select' ).on( 'click', function ( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: lbSpSettings.frameTitle,
				button: { text: lbSpSettings.frameButton },
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				$id.val( attachment.id );
				$preview.empty().append(
					$( '<img>', { src: url, alt: '' } ).css( { maxWidth: '150px', height: 'auto' } )
				);
			} );
		}

		frame.open();
	} );

	$( '#lb-sp-image-remove' ).on( 'click', function ( event ) {
		event.preventDefault();
		$id.val( '' );
		$preview.empty();
	} );
} )( jQuery );
