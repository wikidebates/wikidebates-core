/*	Wikidébats — scan/dispatch (commun) */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var $ = jQuery;

	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var contentFeatures = [
		{ selector: '.fr-collapsible', module: 'ext.wikidebates.features.frCollapsible' },
		{ selector: '.more-content-button', module: 'ext.wikidebates.features.moreContent' },
		{ selector: '.smw-highlighter', module: 'ext.wikidebates.features.smwTooltips' }
	];

	function bindOngletExterne() {
		if ( typeof WK.wkOnce === 'function' ) {
			if ( !WK.wkOnce( 'wk:ongletExterne' ) ) return;
		}

		$( D ).on( 'click', '.onglet-externe a', function () {
			var a = this;

			if ( a.getAttribute( 'target' ) !== '_blank' ) {
				a.setAttribute( 'target', '_blank' );
			}

			if ( a.relList && a.relList.add ) {
				a.relList.add( 'noopener' );
			} else {
				var rel = ( a.getAttribute( 'rel' ) || '' );
				if ( !/\bnoopener\b/.test( rel ) ) {
					a.setAttribute( 'rel', ( rel ? rel + ' ' : '' ) + 'noopener' );
				}
			}
		} );
	}

	function wkLoadOnce( onceKey, moduleName ) {
		if ( typeof WK.wkOnce === 'function' ) {
			if ( !WK.wkOnce( onceKey ) ) return;
		}
		try { mw.loader.load( moduleName ); } catch ( e ) {}
	}

	function wkLoadIdleOnce( onceKey, moduleName ) {
		if ( typeof WK.wkOnce === 'function' ) {
			if ( !WK.wkOnce( onceKey ) ) return;
		}
		( WK.wkIdle || function ( fn ) { setTimeout( fn, 0 ); } )( function () {
			try { mw.loader.load( moduleName ); } catch ( e ) {}
		} );
	}

	function hasIn( root, selector ) {
		var el = ( WK.wkRootNode ? WK.wkRootNode( root ) : ( root || D ) );
		try { return !!( el && ( ( el.matches && el.matches( selector ) ) || ( el.querySelector && el.querySelector( selector ) ) ) ); } catch ( e ) {}
		return false;
	}

	function scanAndDispatch( root ) {
		var el = ( WK.wkRootNode ? WK.wkRootNode( root ) : ( root || D ) );

		contentFeatures.forEach( function ( feature ) {
			if ( !feature.loaded && hasIn( el, feature.selector ) ) {
				feature.loaded = true;
				wkLoadIdleOnce( 'wk:load:' + feature.module, feature.module );
			}
		} );
	}

	function initPageFeatures() {
		/*	FormEdit (module séparé) */
		try {
			if ( typeof WK.wkIsFormEdit === 'function' && WK.wkIsFormEdit() ) {
				wkLoadOnce( 'wk:load:editForm', 'ext.wikidebates.features.editForm' );
			}
		} catch ( e2 ) {}

		/*	NS0 view : features communes (contenu) */
		try {
			if ( typeof WK.wkIsNs === 'function' && typeof WK.wkIsView === 'function' && WK.wkIsNs( 0 ) && WK.wkIsView() ) {
				wkLoadOnce( 'wk:load:view:ns0', 'ext.wikidebates.view.ns0' );
				wkLoadIdleOnce( 'wk:load:view:ns0:idle', 'ext.wikidebates.view.ns0.idle' );
			}
		} catch ( e3 ) {}
	}

	function boot() {
		if ( typeof WK.wkOnce === 'function' ) {
			if ( !WK.wkOnce( 'wk:scan:booted' ) ) return;
		}

		bindOngletExterne();
		initPageFeatures();
		WK.wkBindContentScan( scanAndDispatch );
	}

	if ( D.readyState === 'loading' ) D.addEventListener( 'DOMContentLoaded', boot, { once: true } );
	else boot();
	
}() );
