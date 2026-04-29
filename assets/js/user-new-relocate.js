( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var el = document.querySelector( '.wput-add-team-to-site' );
		if ( ! el ) { return; }
		var selectors = ( el.getAttribute( 'data-wput-relocate-below' ) || '' ).split( ',' );
		var anchor = null;
		for ( var i = 0; i < selectors.length; i++ ) {
			var candidates = document.querySelectorAll( selectors[ i ].trim() );
			if ( candidates.length ) {
				anchor = candidates[ candidates.length - 1 ];
				break;
			}
		}
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( el, anchor.nextSibling );
		}
	} );
} )();
