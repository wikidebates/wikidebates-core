/* Shared reading actions: clipboard results and keyboard-operable disclosures. */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var menuId = 0;
	var documentBound = false;
	var legacyCopyActive = false;

	function focus( element ) {
		if ( !element || !element.isConnected || typeof element.focus !== 'function' || element.disabled ) return;
		try { element.focus( { preventScroll: true } ); } catch ( e ) { try { element.focus(); } catch ( ignored ) {} }
	}

	function legacyCopy( text ) {
		var previous = D.activeElement;
		var selection = W.getSelection ? W.getSelection() : null;
		var ranges = [];
		var inputSelection = null;
		var textarea = D.createElement( 'textarea' );
		var copied = false;
		try {
			if ( selection ) {
				for ( var i = 0; i < selection.rangeCount; i++ ) ranges.push( selection.getRangeAt( i ).cloneRange() );
			}
			if ( previous && typeof previous.selectionStart === 'number' ) {
				inputSelection = [ previous.selectionStart, previous.selectionEnd, previous.selectionDirection ];
			}
			textarea.value = text;
			textarea.readOnly = true;
			textarea.tabIndex = -1;
			textarea.style.cssText = 'position:fixed;left:-9999px;top:0';
			legacyCopyActive = true;
			D.body.appendChild( textarea );
			focus( textarea );
			textarea.select();
			copied = typeof D.execCommand === 'function' && D.execCommand( 'copy' ) === true;
		} catch ( e ) {
			copied = false;
		} finally {
			textarea.remove();
			focus( previous );
			try {
				if ( inputSelection && previous.isConnected ) previous.setSelectionRange.apply( previous, inputSelection );
				else if ( selection ) {
					selection.removeAllRanges();
					ranges.forEach( function ( range ) { selection.addRange( range ); } );
				}
			} catch ( e2 ) {}
			legacyCopyActive = false;
		}
		return copied;
	}

	// Resolve a boolean on every path. Never report success before writeText settles.
	// A detached/navigated-away caller must not steal focus for a late fallback.
	function copyText( text, isCurrent ) {
		if ( !text ) return Promise.resolve( false );
		function fallback() { return ( !isCurrent || isCurrent() ) ? legacyCopy( text ) : false; }
		try {
			if ( W.navigator.clipboard && typeof W.navigator.clipboard.writeText === 'function' ) {
				return Promise.resolve( W.navigator.clipboard.writeText( text ) ).then(
					function () { return true; }, fallback
				);
			}
		} catch ( e ) {}
		return Promise.resolve( fallback() );
	}

	function closeMenu( h1, restoreFocus ) {
		if ( !h1 ) return false;
		var menu = h1.querySelector( '.wk-h1-menu' );
		var button = h1.querySelector( '.wk-h1-more > .wk-icon-more' );
		if ( !menu || !button ) return false;
		var wasOpen = !menu.hidden;
		var hadFocus = menu.contains( D.activeElement );
		menu.hidden = true;
		button.setAttribute( 'aria-expanded', 'false' );
		var manual = menu.querySelector( '.wk-copy-manual' );
		if ( manual ) manual.remove();
		if ( restoreFocus && ( hadFocus || wasOpen ) ) focus( button );
		return wasOpen;
	}

	function closeOpenMenus( restoreFocus ) {
		var closed = false;
		D.querySelectorAll( '.wk-reading-h1' ).forEach( function ( h1 ) {
			if ( closeMenu( h1, restoreFocus ) ) closed = true;
		} );
		return closed;
	}

	function bindDocument() {
		if ( documentBound ) return;
		documentBound = true;
		// One pair of delegated handlers for both skins, not one closure per header.
		function closeOutside( e ) {
			if ( legacyCopyActive ) return;
			D.querySelectorAll( '.wk-reading-h1' ).forEach( function ( h1 ) {
				var wrap = h1.querySelector( '.wk-h1-more' );
				if ( wrap && !wrap.contains( e.target ) ) closeMenu( h1, false );
			} );
		}
		D.addEventListener( 'click', closeOutside );
		D.addEventListener( 'focusin', closeOutside );
	}

	function showManualCopy( menu, text, msg ) {
		var previous = menu.querySelector( '.wk-copy-manual' );
		if ( previous ) previous.remove();
		var box = D.createElement( 'div' );
		box.className = 'wk-copy-manual';
		var label = D.createElement( 'label' );
		label.textContent = msg( 'wk-link-copy-manual' );
		var input = D.createElement( 'input' );
		input.type = 'text';
		input.readOnly = true;
		input.value = text;
		label.appendChild( input );
		box.appendChild( label );
		menu.appendChild( box );
		focus( input );
		input.select();
	}

	function bindMenu( h1, msg ) {
		var button = h1.querySelector( '.wk-h1-more > .wk-icon-more' );
		var menu = h1.querySelector( '.wk-h1-menu' );
		if ( !button || !menu || button.dataset.wkBound === '1' ) return;
		button.dataset.wkBound = '1';
		menu.id = 'wk-reading-actions-' + ( ++menuId );
		button.setAttribute( 'aria-controls', menu.id );
		// A disclosure with ordinary links/buttons, not an ARIA menu widget.
		button.removeAttribute( 'aria-haspopup' );
		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( !menu.hidden ) closeMenu( h1, true );
			else {
				closeOpenMenus( false );
				menu.hidden = false;
				button.setAttribute( 'aria-expanded', 'true' );
			}
		} );
		bindDocument();

		var copy = menu.querySelector( '.wk-h1-menu__copy' );
		if ( !copy ) return;
		var pending = null;
		copy.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var state = WK.WK_READING && WK.WK_READING.state;
			if ( pending && state && pending.sequence === state._wkOpenSeq ) return;
			var href = state && state.h1Links && state.h1Links.detailHref;
			if ( !href || href === '#' ) return;
			var text;
			try { text = new URL( href, W.location.href ).href; } catch ( error ) { return; }
			var sequence = state._wkOpenSeq;
			function current() {
				return h1.isConnected && D.documentElement.classList.contains( 'wk-reading-active' ) &&
					WK.WK_READING.state._wkOpenSeq === sequence;
			}
			var request = { sequence: sequence };
			pending = request;
			copy.setAttribute( 'aria-disabled', 'true' );
			copyText( text, function () { return current() && !menu.hidden; } ).then( function ( copied ) {
				if ( pending !== request ) return;
				pending = null;
				copy.removeAttribute( 'aria-disabled' );
				if ( !current() ) return;
				if ( copied ) {
					closeMenu( h1, menu.contains( D.activeElement ) );
					try { if ( W.mw && typeof W.mw.notify === 'function' ) W.mw.notify( msg( 'wk-link-copied' ) ); } catch ( e2 ) {}
				} else if ( !menu.hidden ) {
					showManualCopy( menu, text, msg );
				}
			} );
		} );
	}

	WK.readingActions = { bindMenu: bindMenu, closeMenu: closeMenu, closeOpenMenus: closeOpenMenus, copyText: copyText, focus: focus };
}() );
