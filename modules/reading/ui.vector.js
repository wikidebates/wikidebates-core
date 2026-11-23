/*	Wikidébats — reading ui (Vector) */
( function () {
	'use strict';

	var D = document;
	var W = window;

	var WK = W.Wikidebates || ( W.Wikidebates = {} );

	var UI_IDS = {
		rootId: 'wk-reading-mode',
		panelId: 'wk-reading-panel',
		leftId: 'wk-reading-left',
		stackId: 'wk-reading-stack',
		siblingsId: 'wk-reading-siblings',
		contentId: 'wk-reading-content',
		closeId: 'wk-reading-close'
	};

	function wkMsgD( key, fallback ) {
		try {
			if ( typeof WK.wkMsgD === 'function' ) return WK.wkMsgD( key, fallback );
			if ( typeof WK.wkMsg === 'function' ) return WK.wkMsg( key ) || fallback || key;
		} catch ( e ) {}
		return fallback || key;
	}

	function wkReadingGetVectorPinnedContainer() {
		// Vector 2022 uses this class in BOTH sidebars. When the table
		// of contents is unavailable, the first matching container is
		// on the RIGHT, inside .vector-column-end. Reading mode hides
		// that column, which would also hide the breadcrumb.
		// Only attach to the genuine left TOC column when its pinned
		// layout is active. Anonymous visitors may use the unpinned TOC
		// client preference, which moves that column below the article.
		// Otherwise ensureUi() keeps the breadcrumb in the reading panel.
		if ( D.documentElement.classList.contains( 'vector-feature-toc-pinned-clientpref-0' ) ) {
			return null;
		}
		return D.querySelector( '.vector-column-start .vector-sticky-pinned-container' );
	}

	function wkReadingGetVectorTopContainer() {
		return D.querySelector( '.mw-page-container' ) || D.body;
	}

	function ensureUi() {
		var body = D.querySelector( '#bodyContent' );
		if ( !body ) return null;

		var root = D.getElementById( UI_IDS.rootId );
		if ( root ) {
			return {
				root: root,
				panel: D.getElementById( UI_IDS.panelId ),
				left: D.getElementById( UI_IDS.leftId ),
				stack: D.getElementById( UI_IDS.stackId ),
				siblings: D.getElementById( UI_IDS.siblingsId ),
				content: D.getElementById( UI_IDS.contentId )
			};
		}

		root = D.createElement( 'div' );
		root.id = UI_IDS.rootId;

		var panel = D.createElement( 'div' );
		panel.className = 'wk-reading-panel';
		panel.id = UI_IDS.panelId;

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

		var stack = D.createElement( 'div' );
		stack.id = UI_IDS.stackId;

		var siblings = D.createElement( 'div' );
		siblings.id = UI_IDS.siblingsId;
		siblings.className = 'wk-reading-siblings';
		siblings.setAttribute( 'aria-label', wkMsgD( 'wk-reading-siblings-aria-label' ) );

		var content = D.createElement( 'div' );
		content.id = UI_IDS.contentId;
		content.className = 'mw-parser-output';

		var left = D.createElement( 'div' );
		left.className = 'wk-reading-left';
		left.id = UI_IDS.leftId;

		left.appendChild( stack );
		left.appendChild( siblings );

		var pinned = wkReadingGetVectorPinnedContainer();
		if ( pinned ) {
			pinned.appendChild( left );
			D.documentElement.classList.add( 'wk-reading-left-in-vector' );
		} else {
			panel.appendChild( left );
		}

		panel.appendChild( content );
		wkReadingGetVectorTopContainer().appendChild( close );
		root.appendChild( panel );

		body.insertBefore( root, body.firstChild );

		close.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( typeof WK.wkCloseReadingMode === 'function' ) WK.wkCloseReadingMode();
		} );

		wkReadingBindOutsideClickClose();

		return { root: root, panel: panel, left: left, stack: stack, siblings: siblings, content: content };
	}

	function isClickInsideReading( target ) {
		if ( !target ) return false;

		var root = D.getElementById( UI_IDS.rootId );
		if ( root && root.contains( target ) ) return true;

		var close = D.getElementById( UI_IDS.closeId );
		if ( close && close.contains( target ) ) return true;

		var left = D.getElementById( UI_IDS.leftId );
		if ( left && left.contains( target ) ) return true;

		return false;
	}

	function wkReadingBindOutsideClickClose() {
		if ( typeof WK.wkOnce !== 'function' ) return;
		if ( !WK.wkOnce( 'wk:reading:vector:outside-close' ) ) return;

		D.addEventListener( 'click', function ( e ) {
			if ( !D.documentElement.classList.contains( 'wk-reading-active' ) ) return;

			var t = e.target;

			//	Inside = root OU left (car left peut être déplacé dans le pinned container) OU close
			var root = D.getElementById( UI_IDS.rootId );
			var left = D.getElementById( UI_IDS.leftId );
			var close = D.getElementById( UI_IDS.closeId );

			var inside = false;
			if ( root && root.contains( t ) ) inside = true;
			else if ( left && left.contains( t ) ) inside = true;
			else if ( close && close.contains( t ) ) inside = true;

			if ( inside ) return;

			//	1er clic = ferme (et évite de cliquer “à travers” sur un lien dessous)
			var tag = (t && t.tagName) ? t.tagName.toLowerCase() : '';
			var interactive = !!(t && (t.closest && t.closest('a,button,input,select,textarea,label')));

			if (interactive || tag === 'a' || tag === 'button') {
			  e.preventDefault();
			  e.stopPropagation();
			} else {
			  // on évite juste de remonter, mais on laisse l'UI Vector réagir si besoin
			  e.stopPropagation();
			}

			if ( typeof WK.wkCloseReadingMode === 'function' ) WK.wkCloseReadingMode();
		}, true ); // capture = bloque avant navigation
	}

	function ensureH1Dom( ui ) {
		if ( !ui || !ui.panel ) return null;

		var h1 = ui.panel.querySelector( '.wk-reading-h1' );
		if ( !h1 ) return null;

		var actions = h1.querySelector( '.wk-reading-h1__actions' );
		if ( !actions ) {
			actions = D.createElement( 'div' );
			actions.className = 'wk-reading-h1__actions';
			h1.appendChild( actions );
		}

		if ( !actions.querySelector( '.wk-h1-btn.bouton-modifier' ) ) {
			var editWrap = D.createElement( 'div' );
			editWrap.className = 'wk-h1-btn bouton-modifier';

			var editLink = D.createElement( 'a' );
			editWrap.appendChild( editLink );

			actions.appendChild( editWrap );
		}

		if ( !actions.querySelector( '.wk-h1-more' ) ) {
			var moreWrap = D.createElement( 'div' );
			moreWrap.className = 'wk-h1-more';

			moreWrap.innerHTML =
				'<button type="button" class="wk-h1-btn wk-icon-before wk-icon-more" aria-expanded="false"></button>' +
				'<div class="wk-h1-menu" hidden>' +
					'<a class="wk-h1-menu__detail wk-icon-before wk-icon-page" href="#"></a>' +
					'<button type="button" class="wk-h1-menu__copy wk-icon-before wk-icon-link"></button>' +
				'</div>';

			actions.appendChild( moreWrap );
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

		var editA = h1.querySelector( '.wk-h1-btn.bouton-modifier a' );
		if ( editA ) {
			editA.setAttribute( 'href', links.editHref || '#' );
			editA.setAttribute( 'title', editTxt );
			editA.setAttribute( 'data-tooltip', editTxt );
			editA.setAttribute( 'aria-label', editTxt );
		}

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

		var existing = ui.panel.querySelector( '.wk-reading-h1' );
		if ( existing && existing.parentNode ) existing.parentNode.removeChild( existing );

		var wrap = D.createElement( 'div' );
		wrap.className = 'wk-reading-h1';

		var title = D.createElement( 'div' );
		title.className = 'wk-reading-h1__title wk-icon-before wk-icon-argument';
		title.textContent = last.title;

		wrap.appendChild( title );

		ui.panel.insertBefore( wrap, ui.content );

		updateH1Actions( ui );
	}

	function applyContentTypeClass( ui, chain ) {
		if ( !ui ) return;

		var panel = D.getElementById( UI_IDS.panelId );
		if ( !panel ) return;

		panel.classList.remove( 'is-pro', 'is-con' );

		if ( !chain || !chain.length ) return;

		for ( var i = chain.length - 1; i >= 0; i-- ) {
			var it = chain[ i ];
			if ( !it || !it.page ) continue;

			if ( it.type === 'contre' ) panel.classList.add( 'is-con' );
			else if ( it.type === 'pour' ) panel.classList.add( 'is-pro' );

			break;
		}
	}

	function getParseParams() {
		return { useskin: 'vector' };
	}

	function closeUi() {
		var root = D.getElementById( UI_IDS.rootId );

		var close = D.getElementById( UI_IDS.closeId );
		if ( close && close.parentNode ) close.parentNode.removeChild( close );

		var left = D.getElementById( UI_IDS.leftId );
		if ( left && left.parentNode ) left.parentNode.removeChild( left );

		D.documentElement.classList.remove( 'wk-reading-left-in-vector' );

		if ( root && root.parentNode ) root.parentNode.removeChild( root );
	}

	var adapter = {
		ensureUi: ensureUi,
		isClickInsideReading: isClickInsideReading,
		updateH1Actions: updateH1Actions,
		prependH1: prependH1,
		applyContentTypeClass: applyContentTypeClass,
		getParseParams: getParseParams,
		closeUi: closeUi
	};

	if ( typeof WK.wkReadingSetAdapter === 'function' ) {
		WK.wkReadingSetAdapter( adapter );
	} else {
		WK._wkReadingPendingAdapter = adapter;
	}

}() );
