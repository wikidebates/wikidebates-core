/* Wikidebates — Minerva reading shared helpers */
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
		closeId: 'wk-reading-close',
		navBtnId: 'wk-reading-navbtn',
		overlayId: 'wk-reading-overlay',
		leftHandleId: 'wk-reading-left-handle',
		leftHeaderId: 'wk-reading-left-header'
	};

	function wkMsgD( key, fallback ) {
		try {
			if ( typeof WK.wkMsgD === 'function' ) return WK.wkMsgD( key, fallback );
			if ( typeof WK.wkMsg === 'function' ) return WK.wkMsg( key ) || fallback || key;
		} catch ( e ) {}
		return fallback || key;
	}

	function wkReadingGetBodyContent() {
		return D.querySelector( '#bodyContent' )
			|| D.querySelector( '#content' )
			|| D.querySelector( '#mw-content-text' )
			|| D.body;
	}

	WK.minervaReading = { ids: UI_IDS, msg: wkMsgD, getBodyContent: wkReadingGetBodyContent };

}() );
