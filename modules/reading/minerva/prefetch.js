/* Wikidebates — Minerva reading sibling lookup and prefetch */
( function () {
	'use strict';

	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;

	var WKReadingParseCache = WK.wkPageParseCache;

	M.createNavigation = function ( ui ) {
		function getListEls() {
			var list = ui.siblings.querySelector( '.wk-reading-siblings__list' );
			if ( !list ) return null;

			var items = list.querySelectorAll( '.wk-reading-siblings__item' );
			if ( !items || !items.length ) return null;

			return Array.prototype.slice.call( items );
		}

		function getCurrentIndex( items ) {
			for ( var i = 0; i < items.length; i++ ) {
				var t = items[ i ].querySelector( '.wk-reading-siblings__title' );
				if ( t && t.classList.contains( 'is-current' ) ) return i;
			}
			return -1;
		}

		function openByIndex( items, idx ) {
			if ( idx < 0 || idx >= items.length ) return false;

			var a = items[ idx ].querySelector( 'a.wk-reading-siblings__link[data-page]' );
			if ( !a ) return false;

			a.click();
			return true;
		}

		function getSiblingTargets() {
			var items = getListEls();
			if ( !items || !items.length ) return { prev: '', next: '' };

			var cur = getCurrentIndex( items );
			if ( cur === -1 ) return { prev: '', next: '' };

			function getPageAt( idx ) {
				if ( idx < 0 || idx >= items.length ) return '';
				var a = items[ idx ].querySelector( 'a.wk-reading-siblings__link[data-page]' );
				return a ? ( a.getAttribute( 'data-page' ) || '' ) : '';
			}

			return {
				prev: getPageAt( cur - 1 ),
				next: getPageAt( cur + 1 )
			};
		}

		function buildParams( page ) {
			if ( !page ) return null;
			try {
				if ( typeof WK.wkReadingBuildParseRequestParams === 'function' ) return WK.wkReadingBuildParseRequestParams( page );
			} catch ( e ) {}
			return null;
		}

		function prefetchPage( page ) {
			if ( !page ) return;

			var params = buildParams( page );
			if ( !params ) return;

			try {
				var key = WKReadingParseCache.key( params );

				if ( WKReadingParseCache.get( key ) ) return;
				WK.wkParsePage( params );
			} catch ( e ) {}
		}

		function prefetchSiblings() {
			var t = getSiblingTargets();
			if ( t.next ) prefetchPage( t.next );
			if ( t.prev ) prefetchPage( t.prev );
		}

		function getSiblingTitleByPage( page ) {
			if ( !page || !ui.siblings ) return '';

			var a = ui.siblings.querySelector( 'a.wk-reading-siblings__link[data-page="' + page.replace( /"/g, '\\"' ) + '"]' );
			if ( a ) return ( a.textContent || '' ).replace( /\s+/g, ' ' ).trim();

			var cur = ui.siblings.querySelector( '.wk-reading-siblings__title.is-current .wk-reading-siblings__current' );
			if ( cur ) return ( cur.textContent || '' ).replace( /\s+/g, ' ' ).trim();

			return '';
		}

		return {
			getListEls: getListEls, getCurrentIndex: getCurrentIndex, openByIndex: openByIndex,
			getSiblingTargets: getSiblingTargets, prefetchPage: prefetchPage,
			prefetchSiblings: prefetchSiblings, getSiblingTitleByPage: getSiblingTitleByPage
		};
	};

}() );
