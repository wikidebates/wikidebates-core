/* Wikidebates — MediaWiki messages and content-language wiki identifiers. */
( function () {
	'use strict';

	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );

	function wkLoadI18n() {
		// ResourceLoader installs this module's messages before executing its script.
		// Its normal language fallback also handles fr-ca, fr-fr and unsupported languages.
		return Promise.resolve();
	}

	function isWikiName( key ) {
		return /^wk-(tpl|param|form)-/.test( key ) || key === 'wk-reading-form-name' ||
			/^wk-menu-.*-(url|page|category)$/.test( key );
	}

	function wkWikiName( key, fallback ) {
		var names = mw.config.get( 'wgWikidebatesWikiNames' ) || {};
		return Object.prototype.hasOwnProperty.call( names, key ) ? names[ key ] :
			( fallback === undefined ? key : fallback );
	}

	function wkMsg( key /*, ...params */ ) {
		// Historical consumers also request template/form names through wkMsg.
		if ( isWikiName( key ) ) return wkWikiName( key );
		var params = Array.prototype.slice.call( arguments, 1 );
		if ( mw.message( key ).exists() ) return mw.msg.apply( mw, [ key ].concat( params ) );
		return key;
	}

	function wkMsgD( key, fallback /*, ...params */ ) {
		var params = Array.prototype.slice.call( arguments, 2 );
		var value = wkMsg.apply( null, [ key ].concat( params ) );
		return value === key ? fallback : value;
	}

	function wkTpl( key, fallback ) { return wkWikiName( key, fallback ); }
	function wkParam( key, fallback ) { return wkWikiName( key, fallback ); }
	function wkForm( key, fallback ) { return wkWikiName( key, fallback ); }

	var cache = Object.create( null );
	function cachedName( key, fallback ) {
		var cacheKey = JSON.stringify( [ key, fallback ] );
		if ( Object.prototype.hasOwnProperty.call( cache, cacheKey ) ) return cache[ cacheKey ];
		var value = wkWikiName( key, fallback );
		// A call before configuration arrives must not cache a missing name or a fallback.
		var names = mw.config.get( 'wgWikidebatesWikiNames' ) || {};
		if ( Object.prototype.hasOwnProperty.call( names, key ) ) cache[ cacheKey ] = value;
		return value;
	}

	var WK_P = { page: null };
	var WK_T = { more: null, latest: null, hoverWP: null };
	function wkWarmI18nCaches() {
		if ( WK_P.page !== null || wkWikiName( 'wk-param-page' ) === 'wk-param-page' ) return;
		WK_P.page = cachedName( 'wk-param-page' );
		WK_T.more = cachedName( 'wk-tpl-more-content' );
		WK_T.hoverWP = cachedName( 'wk-tpl-hover-wikipedia' );
		WK_T.latest = cachedName( 'wk-tpl-latest-changes' );
	}

	var api = {
		wkLoadI18n: wkLoadI18n, wkMsg: wkMsg, wkMsgD: wkMsgD, wkWikiName: wkWikiName,
		wkTpl: wkTpl, wkParam: wkParam, wkForm: wkForm,
		wkParamFast: cachedName, wkTplFast: cachedName, wkFormFast: cachedName,
		wkWarmI18nCaches: wkWarmI18nCaches
	};
	Object.keys( api ).forEach( function ( name ) { WK[ name ] = W[ name ] = api[ name ]; } );
	WK.WK_P = WK_P;
	WK.WK_T = WK_T;
}() );
