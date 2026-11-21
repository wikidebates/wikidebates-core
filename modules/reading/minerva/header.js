/* Wikidebates — Minerva reading heading and actions */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;
	var UI_IDS = M.ids;
	var wkMsgD = M.msg;

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
				'<button type="button" class="wk-h1-btn wk-icon-more" aria-expanded="false">' +
					'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
						'<circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" />' +
					'</svg>' +
				'</button>' +
				'<div class="wk-h1-menu" hidden>' +
					'<a class="wk-h1-menu__detail wk-icon-before wk-icon-page" href="#"></a>' +
					'<a class="wk-h1-menu__edit wk-icon-before wk-icon-edit" href="#"></a>' +
					'<button type="button" class="wk-h1-menu__copy wk-icon-before wk-icon-link"></button>' +
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

		if ( moreBtn ) {
			moreBtn.setAttribute( 'title', moreTxt );
			moreBtn.setAttribute( 'data-tooltip', moreTxt );
			moreBtn.setAttribute( 'aria-label', moreTxt );
		}

		WK.readingActions.bindMenu( h1, wkMsgD );

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
		if ( aCopy ) aCopy.textContent = copyTxt;
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
		WK.readingActions.closeMenu( bar, false );

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
