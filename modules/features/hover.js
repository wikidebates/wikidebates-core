/*	Wikidébats — hover (commun) */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var $D = jQuery( D );

	var WK = W.Wikidebates || ( W.Wikidebates = {} );

	var WK_OPEN = '{{';
	var WK_CLOSE = '}}';
	var WK_SEP = '|';

	var wkHoverCurrent = null;
	var wkHoverTimer = 0;
	var wkHoverToken = 0;
	var wkHoverStore = ( typeof WeakMap !== 'undefined' ) ? new WeakMap() : null;

	function hoverContentCall() {
		if ( !WK.wkOnce( 'wkHoverInit' ) ) return;

		function wkGetHoverTypeAndTpl( $wrap ) {
			if ( $wrap.hasClass( 'hover-map' ) ) {
				return { type: 'map' };
			}
			if ( $wrap.hasClass( 'hover-wikipedia' ) ) {
				return { type: 'wikipedia', template: WK.WK_T.hoverWP };
			}
			return null;
		}

		function wkEnsureLocalHoverWrapper( $wrap ) {
			var $w = $wrap.find( '.hover-wrapper' );
			if ( !$w.length ) {
				$w = jQuery( '<div class="hover-wrapper" aria-hidden="true"></div>' );
				$wrap.append( $w );
			}
			return $w;
		}

		function wkGetLinkNode( $wrap ) {
			var $a = $wrap.find( 'a' ).first();
			return $a.length ? $a : null;
		}

		function wkGetParamForMap( $a ) {
			var t = ( $a.attr( 'title' ) || '' ).trim();
			if ( t ) return t;
			return WK.hrefToPageTitle( $a.attr( 'href' ) || '' );
		}

		function wkGetParamForWikipedia( $a ) {
			var href = $a.attr( 'href' ) || '';
			if ( !href ) return '';

			try {
				var m = href.match( /\/\/[^/]+\/wiki\/([^#?]+)/ );
				if ( m && m[ 1 ] ) return decodeURIComponent( m[ 1 ].replace( /_/g, ' ' ) );
			} catch ( e ) {}

			return ( $a.text() || '' ).replace( /\s+/g, ' ' ).trim();
		}

		function wkBuildHoverQuery( info, el, $a ) {
			if ( !info ) return null;

			var ds = ( el && el.dataset ) ? el.dataset : null;

			if ( info.type === 'wikipedia' ) {
				var p1 = wkGetParamForWikipedia( $a );
				if ( !p1 ) return null;

				return {
					query: WK_OPEN + info.template + WK_SEP + p1 + WK_SEP + WK_SEP + WK_CLOSE,
					key: 'wikipedia|' + p1
				};
			}

			var p1m = wkGetParamForMap( $a );
			if ( !p1m ) return null;

			var p2 = '';
			var p3 = '';

			if ( ds && ds.debate ) {
				p2 = ds.debate;
				p3 = 'debate';
			} else if ( ds && ds.argument ) {
				p2 = ds.argument;
			}

			// data-debate/data-argument are QUERY-encoded by the renderers,
			// including existing cached HTML. Decode once, then encode both
			// titles as literal URI components so wiki parameter delimiters
			// cannot change the parser function call.
			var source = p2;
			try { source = decodeURIComponent( p2.replace( /\+/g, ' ' ) ); } catch ( e ) {}

			return {
				query: WK_OPEN
					+ '#argumentmap:render'
					+ WK_SEP + encodeURIComponent( p1m )
					+ WK_SEP + encodeURIComponent( source )
					+ WK_SEP + ( p3 || '' )
					+ WK_SEP + 'encoding=uri'
					+ WK_CLOSE,
				key: 'map|' + p1m + '|' + ( p2 || '' ) + '|' + ( p3 || '' ),
				map: { page: p1m, source: source, mode: source.trim() === '' ? 'full' : ( p3 ? 'debate' : 'argument' ) }
			};
		}

		function wkHoverVisible( el, $wrap ) {
			if ( $wrap.is( ':hidden' ) || el.offsetParent === null ) return false;
			try {
				var r = el.getBoundingClientRect();
				var vh = W.innerHeight || D.documentElement.clientHeight || 0;
				if ( r.bottom < 0 || r.top > vh ) return false;
			} catch ( e ) {}
			return true;
		}

		$D.on( 'mouseenter.wkHover', '.hover-map, .hover-wikipedia', function () {
			var el = this;
			var $wrap = jQuery( el );

			wkHoverCurrent = $wrap;

			if ( wkHoverTimer ) clearTimeout( wkHoverTimer );

			var myToken = ++wkHoverToken;

			// A fresh shared map is already available: no timer or network.
			if ( $wrap.hasClass( 'hover-map' ) && wkHoverVisible( el, $wrap ) ) {
				var a = wkGetLinkNode( $wrap );
				var map = a && wkBuildHoverQuery( { type: 'map' }, el, a );
				var cached = map ? WK.wkGetCachedMap( map.query ) : null;
				if ( cached !== null ) {
					wkEnsureLocalHoverWrapper( $wrap ).attr( 'aria-hidden', 'false' ).html( cached ).show();
					return;
				}
			}

			wkHoverTimer = setTimeout( function () {
				wkHoverTimer = 0;

				if ( myToken !== wkHoverToken ) return;
				if ( !wkHoverCurrent || wkHoverCurrent[ 0 ] !== el ) return;

				if ( !wkHoverVisible( el, $wrap ) ) return;

				if ( !$wrap.hasClass( 'hover-map' ) ) WK.wkWarmI18nCaches();

				var info = wkGetHoverTypeAndTpl( $wrap );
				if ( !info ) return;

				var $a = wkGetLinkNode( $wrap );
				if ( !$a ) return;

				var built = wkBuildHoverQuery( info, el, $a );
				if ( !built || !built.query || !built.key ) return;

				var $local = wkEnsureLocalHoverWrapper( $wrap );
				$local.attr( 'aria-hidden', 'false' );

				// The shared parse cache distinguishes user languages. Keep this
				// element-local cache equally strict, including the complete query.
				var language = mw.config.get( 'wgUserLanguage' );
				var cacheKey = JSON.stringify( [ language, built.query ] );
				var stored = !built.map && wkHoverStore ? wkHoverStore.get( el ) : null;
				if ( stored && stored.key === cacheKey && stored.html ) {
					$local.html( stored.html ).show();
					return;
				}

				$local.hide().empty();

				WK.wkParseWikitext( built.query, 'hover', { map: built.map } ).then( function ( html ) {
					if ( myToken !== wkHoverToken ) return;
					if ( !wkHoverCurrent || wkHoverCurrent[ 0 ] !== el ) return;
					// An in-flight response belongs to the language and query at
					// submission time, even when the element is reused.
					if ( mw.config.get( 'wgUserLanguage' ) !== language ) return;
					var currentInfo = wkGetHoverTypeAndTpl( $wrap );
					var currentLink = wkGetLinkNode( $wrap );
					var currentQuery = currentLink && wkBuildHoverQuery( currentInfo, el, currentLink );
					if ( !currentQuery || currentQuery.query !== built.query ) return;

					if ( !built.map && wkHoverStore ) {
						wkHoverStore.set( el, { key: cacheKey, html: html } );
					}

					$local.html( html ).show();
				} ).catch( function () {} );
			}, 140 );
		} );

		$D.on( 'mouseleave.wkHover', '.hover-map, .hover-wikipedia', function () {
			var el = this;
			var $wrap = jQuery( el );

			if ( wkHoverTimer ) clearTimeout( wkHoverTimer );
			wkHoverTimer = 0;

			wkHoverToken++;

			try {
				var info = wkGetHoverTypeAndTpl( $wrap );
				var $a = wkGetLinkNode( $wrap );

				if ( info && $a ) {
					var built = wkBuildHoverQuery( info, el, $a );
					if ( built && built.query ) WK.wkParseCancel( 'hover', built.query );
				}
			} catch ( e ) {}

			var $local = $wrap.find( '.hover-wrapper' );
			if ( $local.length ) $local.attr( 'aria-hidden', 'true' ).hide().empty();
		} );
	}

	WK.hoverContentCall = hoverContentCall;

	/*
		Auto-init : le module est chargé à la demande (Vector),
		mais doit binder ses handlers immédiatement.
	*/
	try { hoverContentCall(); } catch ( e ) {}

}() );
