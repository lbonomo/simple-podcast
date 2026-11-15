( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.lb-sp-log-toggle' ) : null;
		if ( ! button ) {
			return;
		}
		var row = document.getElementById( button.getAttribute( 'aria-controls' ) );
		if ( ! row ) {
			return;
		}
		var open = button.getAttribute( 'aria-expanded' ) === 'true';
		row.hidden = open;
		button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		button.textContent = open ? '+' : '\u2212';
	} );
}() );
