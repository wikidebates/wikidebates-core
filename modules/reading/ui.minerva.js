/* Wikidebates — Minerva reading adapter and DOM lifecycle */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;
	var UI_IDS = M.ids;
	var wkMsgD = M.msg;

	var wkReadingGetBodyContent = M.getBodyContent;
	var ensureH1Dom = M.header.ensure;
	var updateH1Actions = M.header.updateActions;
	var prependH1 = M.header.prepend;
	var wkReadingBindTitleFade = M.header.bindTitleFade;
	var wkReadingBindLeftSheet = M.bindLeftSheet;
	var wkReadingBindSiblingSwipeMinerva = M.bindGestures;

	function ensureUi() {
		var body = wkReadingGetBodyContent();
		if ( !body ) return null;

		var root = D.getElementById( UI_IDS.rootId );
		if ( root ) {
			var ui = {
				root: root,
				panel: D.getElementById( UI_IDS.panelId ),
				left: D.getElementById( UI_IDS.leftId ),
				stack: D.getElementById( UI_IDS.stackId ),
				siblings: D.getElementById( UI_IDS.siblingsId ),
				content: D.getElementById( UI_IDS.contentId )
			};

			wkReadingBindLeftSheet( ui );
			wkReadingBindSiblingSwipeMinerva( ui );
			wkReadingBindTitleFade( ui );

			return ui;
		}

		root = D.createElement( 'div' );
		root.id = UI_IDS.rootId;

		var panel = D.createElement( 'div' );
		panel.className = 'wk-reading-panel';
		panel.id = UI_IDS.panelId;

		var stack = D.createElement( 'div' );
		stack.id = UI_IDS.stackId;

		var siblings = D.createElement( 'div' );
		siblings.id = UI_IDS.siblingsId;
		siblings.className = 'wk-reading-siblings';
		siblings.setAttribute( 'aria-label', wkMsgD( 'wk-reading-siblings-aria-label' ) );

		var content = D.createElement( 'div' );
		content.id = UI_IDS.contentId;
		content.className = 'wk-reading-output';

		var left = D.createElement( 'div' );
		left.className = 'wk-reading-left';
		left.id = UI_IDS.leftId;

		left.appendChild( stack );
		left.appendChild( siblings );

		panel.appendChild( content );
		root.appendChild( panel );
		root.appendChild( left );

		body.insertBefore( root, body.firstChild );

		var ui2 = { root: root, panel: panel, left: left, stack: stack, siblings: siblings, content: content };

		ensureH1Dom( ui2 );
		updateH1Actions( ui2 );
		wkReadingBindLeftSheet( ui2 );
		wkReadingBindSiblingSwipeMinerva( ui2 );
		wkReadingBindTitleFade( ui2 );

		return ui2;
	}

	function isClickInsideReading( target ) {
		if ( !target ) return false;
		var root = D.getElementById( UI_IDS.rootId );
		return !!( root && root.contains( target ) );
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
		return { useskin: 'minerva' };
	}

	function closeUi() {
		var content = D.getElementById( UI_IDS.contentId );
		if ( content && content._wkTitleFade ) content._wkTitleFade.disconnect();
		var root = D.getElementById( UI_IDS.rootId );
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
