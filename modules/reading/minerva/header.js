/* Wikidebates — Minerva reading heading and actions */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;
	var UI_IDS = M.ids;
	var wkMsgD = M.msg;

	function wkReadingCloseH1Menu( h1 ) {
		if ( !h1 ) return;

		var moreBtn = h1.querySelector( '.wk-h1-more > .wk-icon-more' );
		var menu = h1.querySelector( '.wk-h1-more .wk-h1-menu' );

		if ( menu ) menu.setAttribute( 'hidden', '' );
		if ( moreBtn ) moreBtn.setAttribute( 'aria-expanded', 'false' );
	}

	function wkReadingCopyText( text ) {
		if ( !text ) return false;

		try {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text );
				return true;
			}
		} catch ( e ) {}

		try {
			var ta = D.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			D.body.appendChild( ta );
			ta.select();
			D.execCommand( 'copy' );
			D.body.removeChild( ta );
			return true;
		} catch ( e2 ) {}

		return false;
	}

	function wkReadingGetAbsoluteUrl( href ) {
		if ( !href || href === '#' ) return '';
		try { return ( new URL( href, W.location.href ) ).href; } catch ( e ) {}
		return href;
	}

	function wkReadingBuildCloseButton() {
		var close = D.createElement( 'button' );
		close.id = UI_IDS.closeId;
		close.className = 'wk-reading-close';
		close.type = 'button';
		close.setAttribute( 'aria-label', wkMsgD( 'wk-reading-close' ) );
		close.setAttribute( 'title', wkMsgD( 'wk-reading-close' ) );
		close.innerHTML =
			'<svg viewBox="6 6 12 12" aria-hidden="true" focusable="false">' +
				'<path d="M6 6 L18 18 M18 6 L6 18" />' +
			'</svg>';

		close.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( typeof WK.wkCloseReadingMode === 'function' ) WK.wkCloseReadingMode();
		} );

		return close;
	}

	function wkReadingBindTitleFade( ui ) {
		if ( !ui || !ui.content || !ui.panel ) return;
		if ( ui.content._wkTitleFade ) {
			ui.content._wkTitleFade.refresh();
			return;
		}

		var frame = 0;
		var fullTitle = null;
		var lastOpacity = -1;
		var stopped = false;
		var resizeObserver = null;

		function apply() {
			frame = 0;
			if ( stopped ) return;
			if ( !ui.panel.isConnected ) {
				disconnect();
				return;
			}

			var nextTitle = ui.content.querySelector( '.wk-reading-full-title' );
			if ( fullTitle !== nextTitle ) {
				if ( resizeObserver && fullTitle ) resizeObserver.unobserve( fullTitle );
				fullTitle = nextTitle;
				lastOpacity = -1;
				if ( resizeObserver && fullTitle ) resizeObserver.observe( fullTitle );
			}

			var bar = ui.panel.querySelector( '.wk-reading-h1' );
			if ( !bar ) return;
			// Only opacity changes. The full heading belongs to the native scroll
			// flow; the overlay never resizes or moves the reading viewport.
			var hidden = fullTitle ? ui.content.getBoundingClientRect().top - fullTitle.getBoundingClientRect().bottom : 0;
			var opacity = Math.max( 0, Math.min( 1, hidden / 32 ) );
			if ( opacity !== lastOpacity ) {
				bar.style.setProperty( '--wk-reading-title-opacity', String( opacity ) );
				lastOpacity = opacity;
			}
		}

		function refresh() {
			if ( !stopped && !frame ) frame = W.requestAnimationFrame( apply );
		}

		function disconnect() {
			stopped = true;
			if ( frame ) W.cancelAnimationFrame( frame );
			if ( resizeObserver ) resizeObserver.disconnect();
			mutations.disconnect();
			ui.content.removeEventListener( 'scroll', refresh );
			W.removeEventListener( 'resize', refresh );
			if ( D.fonts ) D.fonts.removeEventListener( 'loadingdone', refresh );
			delete ui.content._wkTitleFade;
		}

		ui.content._wkTitleFade = { refresh: refresh, disconnect: disconnect };
		ui.content.addEventListener( 'scroll', refresh, { passive: true } );
		W.addEventListener( 'resize', refresh );
		if ( D.fonts ) D.fonts.addEventListener( 'loadingdone', refresh );

		var mutations = new MutationObserver( refresh );
		mutations.observe( ui.content, { childList: true, subtree: true, characterData: true } );
		if ( W.ResizeObserver ) {
			resizeObserver = new ResizeObserver( refresh );
			resizeObserver.observe( ui.content );
		}
		refresh();
	}

	function wkReadingBuildFullTitle( text ) {
		var title = D.createElement( 'h1' );
		title.className = 'wk-reading-full-title wk-icon-before wk-icon-argument';
		title.textContent = text;
		return title;
	}

	function ensureH1Dom( ui ) {
		if ( !ui || !ui.panel ) return null;

		var h1 = ui.panel.querySelector( '.wk-reading-h1' );
		if ( !h1 ) {
			h1 = D.createElement( 'div' );
			h1.className = 'wk-reading-h1';
			var compactTitle = D.createElement( 'div' );
			compactTitle.className = 'wk-reading-h1__title wk-icon-before wk-icon-argument';
			// The complete, semantic heading is in the scrollable content.
			compactTitle.setAttribute( 'aria-hidden', 'true' );
			h1.appendChild( compactTitle );
			ui.panel.insertBefore( h1, ui.content );
		}

		var close = h1.querySelector( '#' + UI_IDS.closeId );
		if ( !close ) {
			close = wkReadingBuildCloseButton();
			h1.insertBefore( close, h1.firstChild );
		}

		var actions = h1.querySelector( '.wk-reading-h1__actions' );
		if ( !actions ) {
			actions = D.createElement( 'div' );
			actions.className = 'wk-reading-h1__actions';
			h1.appendChild( actions );
		}

		var legacyEdit = actions.querySelector( '.wk-h1-btn.bouton-modifier' );
		if ( legacyEdit && legacyEdit.parentNode ) legacyEdit.parentNode.removeChild( legacyEdit );

		if ( !actions.querySelector( '.wk-h1-more' ) ) {
			var moreWrap = D.createElement( 'div' );
			moreWrap.className = 'wk-h1-more';

			moreWrap.innerHTML =
				'<button type="button" class="wk-h1-btn wk-icon-more" aria-haspopup="true" aria-expanded="false">' +
					'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
						'<circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" />' +
					'</svg>' +
				'</button>' +
				'<div class="wk-h1-menu" hidden>' +
					'<a class="wk-h1-menu__detail wk-icon-before wk-icon-page" href="#"></a>' +
					'<a class="wk-h1-menu__edit wk-icon-before wk-icon-edit" href="#"></a>' +
					'<a class="wk-h1-menu__copy wk-icon-before wk-icon-link" href="#"></a>' +
				'</div>';

			actions.appendChild( moreWrap );
		} else {
			var menu = actions.querySelector( '.wk-h1-more .wk-h1-menu' );
			if ( menu && !menu.querySelector( '.wk-h1-menu__edit' ) ) {
				var aEdit = D.createElement( 'a' );
				aEdit.className = 'wk-h1-menu__edit wk-icon-before wk-icon-edit';
				aEdit.href = '#';

				var aCopy = menu.querySelector( '.wk-h1-menu__copy' );
				if ( aCopy && aCopy.parentNode ) aCopy.parentNode.insertBefore( aEdit, aCopy );
				else menu.appendChild( aEdit );
			}
		}

		return h1;
	}

	function updateH1Actions( ui ) {
		var h1 = ensureH1Dom( ui );
		if ( !h1 ) return;

		var links = ( WK.WK_READING && WK.WK_READING.state && WK.WK_READING.state.h1Links )
			? WK.WK_READING.state.h1Links
			: { editHref: '#', detailHref: '' };

		var editTxt = wkMsgD( 'wk-edit-display-title' );
		var detailTxt = wkMsgD( 'wk-view-detailed-page' );
		var copyTxt = wkMsgD( 'wk-copy-argument-link' );
		var moreTxt = wkMsgD( 'wk-reading-more-actions' );

		var moreBtn = h1.querySelector( '.wk-h1-more > .wk-icon-more' );
		var menu = h1.querySelector( '.wk-h1-more .wk-h1-menu' );

		if ( moreBtn ) {
			moreBtn.setAttribute( 'title', moreTxt );
			moreBtn.setAttribute( 'data-tooltip', moreTxt );
			moreBtn.setAttribute( 'aria-label', moreTxt );
		}

		if ( moreBtn && menu ) {
			if ( !moreBtn.dataset.wkBound ) {
				moreBtn.dataset.wkBound = '1';

				moreBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();

					var open = !menu.hasAttribute( 'hidden' );
					if ( open ) {
						menu.setAttribute( 'hidden', '' );
						moreBtn.setAttribute( 'aria-expanded', 'false' );
					} else {
						menu.removeAttribute( 'hidden' );
						moreBtn.setAttribute( 'aria-expanded', 'true' );
					}
				} );

				D.addEventListener( 'click', function ( e2 ) {
					if ( !h1.contains( e2.target ) ) {
						menu.setAttribute( 'hidden', '' );
						moreBtn.setAttribute( 'aria-expanded', 'false' );
					}
				} );
			}
		}

		var aDetail = h1.querySelector( '.wk-h1-menu__detail' );
		if ( aDetail ) {
			aDetail.textContent = detailTxt;
			aDetail.setAttribute( 'href', links.detailHref || '#' );
		}

		var aEdit = h1.querySelector( '.wk-h1-menu__edit' );
		if ( aEdit ) {
			aEdit.textContent = editTxt;
			aEdit.setAttribute( 'href', links.editHref || '#' );
		}

		var aCopy = h1.querySelector( '.wk-h1-menu__copy' );
		if ( aCopy ) {
			aCopy.textContent = copyTxt;
			aCopy.href = '#';

			if ( aCopy.dataset.wkBound !== '1' ) {
				aCopy.dataset.wkBound = '1';

				aCopy.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();

					wkReadingCloseH1Menu( h1 );

					var href = ( WK.WK_READING && WK.WK_READING.state && WK.WK_READING.state.h1Links )
						? ( WK.WK_READING.state.h1Links.detailHref || '' )
						: '';

					var abs = wkReadingGetAbsoluteUrl( href );
					if ( !abs ) return;

					if ( wkReadingCopyText( abs ) ) {
						try {
							if ( W.mw && typeof W.mw.notify === 'function' ) W.mw.notify( wkMsgD( 'wk-link-copied' ) );
						} catch ( e2 ) {}
					}
				} );
			}
		}
	}

	function prependH1( ui, chain ) {
		if ( !ui || !chain || !chain.length ) return;
		if ( !ui.panel || !ui.content ) return;

		var last = null;
		for ( var i = chain.length - 1; i >= 0; i-- ) {
			if ( chain[ i ] && chain[ i ].page ) { last = chain[ i ]; break; }
		}
		if ( !last || !last.title ) return;

		var bar = ensureH1Dom( ui );
		bar.querySelector( '.wk-reading-h1__title' ).textContent = last.title;
		bar.style.setProperty( '--wk-reading-title-opacity', '0' );
		wkReadingCloseH1Menu( bar );

		var existing = ui.content.querySelector( '.wk-reading-full-title' );
		if ( existing ) existing.remove();

		ui.content.insertBefore( wkReadingBuildFullTitle( last.title ), ui.content.firstChild );
		if ( ui.content._wkTitleFade ) ui.content._wkTitleFade.refresh();

		updateH1Actions( ui );
	}

	M.buildFullTitle = wkReadingBuildFullTitle;
	M.header = {
		ensure: ensureH1Dom, updateActions: updateH1Actions, prepend: prependH1,
		bindTitleFade: wkReadingBindTitleFade
	};

}() );
