( function () {
	var data        = window.wpUserTeamsUsersList || {};
	var teamNames   = data.teamNames || {};
	var memberTeams = data.memberTeams || {};

	// Per-site Users list gives each row `id="user-{id}"`. The
	// network list's `<tr>` has no id — find it by the bulk
	// checkbox `#blog_{id}`. Returns `null` if neither is on page.
	function findUserRow( id ) {
		var row = document.getElementById( 'user-' + id );
		if ( row ) { return row; }
		var cb = document.getElementById( 'blog_' + id );
		return cb ? cb.closest( 'tr' ) : null;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Object.keys( teamNames ).forEach( function ( id ) {
			var row = findUserRow( id );
			if ( ! row ) { return; }
			row.classList.add( 'wput-team-row' );
			var displayName = teamNames[ id ];

			// Walk text nodes in the username cell. The `_team_*`
			// login appears either inside the `<a>` (network Users
			// list) or as a sibling text node after `<br />`
			// (per-site Users list). Replace the in-link text with
			// the team's display name; drop the sibling copy.
			var username = row.querySelector( '.column-username' );
			if ( ! username ) { return; }
			var walker = document.createTreeWalker( username, NodeFilter.SHOW_TEXT );
			var inLink = [];
			var outside = [];
			while ( walker.nextNode() ) {
				var node = walker.currentNode;
				if ( ! /^\s*_team_/.test( node.nodeValue ) ) { continue; }
				if ( node.parentNode && node.parentNode.tagName === 'A' ) {
					inLink.push( node );
				} else {
					outside.push( node );
				}
			}
			inLink.forEach( function ( n ) { n.nodeValue = displayName; } );
			outside.forEach( function ( n ) { n.parentNode.removeChild( n ); } );
		} );

		// Append " — Team A, Team B" to member usernames, matching
		// WP's own "— Super Admin" marker. Skip team-user rows.
		Object.keys( memberTeams ).forEach( function ( uid ) {
			if ( teamNames[ uid ] ) { return; }
			var row = findUserRow( uid );
			if ( ! row ) { return; }
			var strong = row.querySelector( '.column-username strong' );
			if ( ! strong ) { return; }
			strong.appendChild( document.createTextNode( ' — ' + memberTeams[ uid ].join( ', ' ) ) );
		} );
	} );
} )();
