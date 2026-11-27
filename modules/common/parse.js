/*	Wikidébats — parse pool commun (MW 1.43) */
( function () {
	'use strict';

	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );

	var wkApi = null;
	var wkReadingEndpointUnavailable = false;

	var WK_PARSE_CONCURRENCY = 2;

	var WK_PARSE_CACHE_MAX = {
		bc: 80,
		arg: 160,
		hover: 120,
		latest: 10,
		more: 10,
		def: 80
	};

	var wkParseCacheBC = new Map();
	var wkParseCacheArg = new Map();
	var wkParseCacheHover = new Map();
	var wkParseCacheLatest = new Map();
	var wkParseCacheMore = new Map();
	var wkParseCacheDef = new Map();

	var wkParseInFlight = new Map();
	var wkParseQueue = [];
	var wkParseActive = 0;
	var wkParseActiveMap = 0;
	var WK_MAP_CACHE_TTL_MS = 60000;
	var wkParsePumping = false;
	var wkPageParseCache = new Map();
	var wkPageParseInFlight = new Map();
	var wkPageParseQueue = [];
	var wkPageParseActive = 0;
	var wkPagePrefetchActive = 0;
	var wkPageParsePumping = false;
	var WK_PAGE_PARSE_CONCURRENCY = 2;
	var WK_PAGE_PREFETCH_CONCURRENCY = 1;
	var WK_PAGE_PARSE_CACHE_MAX = 50;

	var WK_PARSE_FAIL_TTL_MS = 15000;
	var WK_PARSE_FAIL_MAX = 128;
	var wkParseFailUntil = new Map();

	// Explicit clicks outrank speculative hover work only while queued.
	// Running requests are never aborted; FIFO is retained within a priority.
	var WK_PARSE_PRIORITY = {
		latest: 0,
		more: 0,
		bc: 1,
		arg: 2,
		def: 3,
		hover: 4
	};

	function wkGetApi() {
		if ( wkApi ) return wkApi;
		wkApi = new mw.Api();
		return wkApi;
	}

	function wkGetBucketCache( bucket ) {
		if ( bucket === 'bc' ) return wkParseCacheBC;
		if ( bucket === 'arg' ) return wkParseCacheArg;
		if ( bucket === 'hover' ) return wkParseCacheHover;
		if ( bucket === 'latest' ) return wkParseCacheLatest;
		if ( bucket === 'more' ) return wkParseCacheMore;
		return wkParseCacheDef;
	}

	function wkCacheGetLRU( cache, key ) {
		if ( !cache || !cache.has || !cache.size ) return null;
		if ( !cache.has( key ) ) return null;

		var v = cache.get( key );
		cache.delete( key );
		cache.set( key, v );

		return v;
	}

	function wkCacheSetLRU( cache, max, key, value ) {
		if ( !cache || typeof max !== 'number' || max <= 0 ) return;

		if ( cache.has( key ) ) cache.delete( key );
		cache.set( key, value );

		while ( cache.size > max ) {
			var firstKey = cache.keys().next().value;
			if ( typeof firstKey === 'undefined' ) break;
			cache.delete( firstKey );
		}
	}

	function wkIsMapQuery( bucket, text ) {
		return bucket === 'hover' && text.indexOf( '{{#argumentmap:render|' ) === 0;
	}

	function wkCachedParse( bucket, text, language ) {
		if ( bucket === 'latest' ) return null;
		var cache = wkGetBucketCache( bucket );
		var key = JSON.stringify( [ language, text ] );
		var cached = wkCacheGetLRU( cache, key );
		if ( cached === null || !wkIsMapQuery( bucket, text ) ) return cached;
		if ( cached.expires <= Date.now() ) {
			cache.delete( key );
			return null;
		}
		return cached.html;
	}

	function wkGetCachedMap( text ) {
		return wkCachedParse( 'hover', text, mw.config.get( 'wgUserLanguage' ) );
	}

	function wkPageParseKey( params ) {
		return JSON.stringify( Object.keys( params ).sort().map( function ( key ) {
			return [ key, params[ key ] ];
		} ) );
	}

	/* Foreground, sibling prefetch and swipe preview share the same promise. */
	function wkParsePage( params, options ) {
		var key = wkPageParseKey( params );
		var cached = wkCacheGetLRU( wkPageParseCache, key );
		if ( cached !== null ) return jQuery.Deferred().resolve( cached ).promise();
		var prefetch = !!( options && options.prefetch );
		var job = wkPageParseInFlight.get( key );
		if ( job ) {
			// A click promotes the queued request instead of downloading it twice.
			if ( !prefetch ) job.prefetch = false;
			wkPageParsePump();
			return job.promise;
		}
		job = {
			key: key, params: Object.assign( {}, params ), prefetch: prefetch,
			deferred: jQuery.Deferred(), startedAsPrefetch: false, finished: false
		};
		job.promise = job.deferred.promise();
		// Register before dispatch, including for synchronously settled API doubles.
		wkPageParseInFlight.set( key, job );
		wkPageParseQueue.push( job );
		wkPageParsePump();
		return job.promise;
	}

	function wkPageParseFinish( job, success, context, args ) {
		if ( job.finished ) return;
		job.finished = true;
		wkPageParseInFlight.delete( job.key );
		wkPageParseActive--;
		if ( job.startedAsPrefetch ) wkPagePrefetchActive--;
		try {
			var data = args[ 0 ];
			if ( success ) {
				if ( data && ( data.parse || data.reading ) ) {
					wkCacheSetLRU( wkPageParseCache, WK_PAGE_PARSE_CACHE_MAX, job.key, data );
				}
				job.deferred.resolveWith( context, args );
				if ( data && ( data.parse || data.reading ) ) mw.hook( 'wikidebates.pageParsed' ).fire( job.key );
			} else {
				job.deferred.rejectWith( context, args );
			}
		} finally {
			wkPageParsePump();
		}
	}

	function wkPageParseStart( job ) {
		wkPageParseActive++;
		job.startedAsPrefetch = job.prefetch;
		if ( job.startedAsPrefetch ) wkPagePrefetchActive++;
		var request;
		try {
			request = wkPageParseRequest( job.params );
		} catch ( error ) {
			wkPageParseFinish( job, false, null, [ 'http', { exception: error } ] );
			return;
		}
		request.done( function () {
			wkPageParseFinish( job, true, this, arguments );
		} ).fail( function () {
			wkPageParseFinish( job, false, this, arguments );
		} );
	}

	function wkPageParsePump() {
		if ( wkPageParsePumping ) return;
		wkPageParsePumping = true;
		try {
			while ( wkPageParseActive < WK_PAGE_PARSE_CONCURRENCY && wkPageParseQueue.length ) {
				var index = wkPageParseQueue.findIndex( function ( job ) { return !job.prefetch; } );
				if ( index === -1 ) {
					// Leave one slot available to a reader even during background work.
					if ( wkPagePrefetchActive >= WK_PAGE_PREFETCH_CONCURRENCY ) break;
					index = 0;
				}
				wkPageParseStart( wkPageParseQueue.splice( index, 1 )[ 0 ] );
			}
		} finally {
			wkPageParsePumping = false;
		}
	}

	function wkCancelPagePrefetch( keepKeys ) {
		var keep = new Set( keepKeys || [] );
		var cancelled = [];
		wkPageParseQueue = wkPageParseQueue.filter( function ( job ) {
			if ( !job.prefetch || keep.has( job.key ) ) return true;
			wkPageParseInFlight.delete( job.key );
			cancelled.push( job );
			return false;
		} );
		// Never abort an active request or a request promoted by another consumer.
		cancelled.forEach( function ( job ) {
			job.deferred.reject( 'wikidebates-prefetch-cancelled' );
		} );
	}

	function wkPageParseRequest( params ) {
		var useFragment = mw.config.get( 'wgWikidebatesReadingFragments' ) !== false && !wkReadingEndpointUnavailable &&
			params.action === 'parse' && params.page && params.prop === 'text' &&
			params.formatversion === 2 && params.usearticle === 1 && params.redirects === 1 &&
			( !params.useskin || params.useskin === 'vector' || params.useskin === 'minerva' ) &&
			Object.keys( params ).every( function ( name ) {
				return [ 'action', 'page', 'prop', 'useskin', 'uselang', 'formatversion', 'usearticle', 'redirects' ].indexOf( name ) !== -1;
			} );
		var requestParams = useFragment ? {
			action: 'wikidebatesreading', page: params.page, useskin: params.useskin || 'vector',
			uselang: params.uselang, formatversion: 2
		} : params;
		var request = wkGetApi().get( requestParams );
		if ( useFragment ) {
			request = request.then( function ( data ) {
				if ( !data || !data.wikidebatesreading || typeof data.wikidebatesreading.html !== 'string' ) {
					return jQuery.Deferred().reject( 'invalid-reading-response' ).promise();
				}
				return { reading: data.wikidebatesreading };
			}, function ( code, details ) {
				var error = details && details.error;
				if ( code === 'unknown_action' || ( code === 'badvalue' && error &&
					( error.parameter === 'action' || /action.*wikidebatesreading/.test( error.info || '' ) ) ) ) {
					// A rolling deployment can briefly route to an older backend.
					wkReadingEndpointUnavailable = true;
					return wkGetApi().get( params );
				}
				return jQuery.Deferred().reject( code, details ).promise();
			} );
		}
		return request;
	}

	function wkPruneParseFailures() {
		var now = Date.now();
		wkParseFailUntil.forEach( function ( until, key ) {
			if ( until <= now ) wkParseFailUntil.delete( key );
		} );
	}

	function wkFailIsHot( key ) {
		wkPruneParseFailures();
		return wkParseFailUntil.has( key );
	}

	function wkFailTouch( key ) {
		wkPruneParseFailures();
		// Bound failed requests only, never the reader's successful fragments.
		wkCacheSetLRU( wkParseFailUntil, WK_PARSE_FAIL_MAX, key, Date.now() + WK_PARSE_FAIL_TTL_MS );
	}

	function wkParseCancel( bucket, wikitext ) {
		var keyLocal = String( wikitext || '' );
		bucket = bucket || 'def';

		// Leaving a hover cancels every queued language variant, never active work.
		for ( var i = wkParseQueue.length - 1; i >= 0; i-- ) {
			var job = wkParseQueue[ i ];
			if ( job.bucket !== bucket || job.keyLocal !== keyLocal ) continue;
			wkParseQueue.splice( i, 1 );
			job.finished = true;
			if ( wkParseInFlight.get( job.inFlightKey ) === job ) wkParseInFlight.delete( job.inFlightKey );
			job.reject( new Error( 'wkParse:cancelled' ) );
		}
	}

	function wkParseWikitext( wikitext, bucket, options ) {
		bucket = bucket || 'def';

		// Recent changes must not reuse the browser's tooltip data cache.
		var keyLocal = String( wikitext || '' );
		var language = mw.config.get( 'wgUserLanguage' );
		var cacheKey = JSON.stringify( [ language, keyLocal ] );
		var inFlightKey = JSON.stringify( [ bucket, cacheKey ] );

		var cached = wkCachedParse( bucket, keyLocal, language );
		if ( cached !== null ) return Promise.resolve( cached );
		// Explicit user retries must not be blocked by the 15-second failure throttle.
		// Background and speculative requests still respect the throttle.
		if ( options && options.retry === true ) wkParseFailUntil.delete( inFlightKey );
		if ( wkFailIsHot( inFlightKey ) ) return Promise.reject( new Error( 'wkParse:fail-cached' ) );
		if ( wkParseInFlight.has( inFlightKey ) ) return wkParseInFlight.get( inFlightKey ).promise;

		var job = {
			inFlightKey: inFlightKey, keyLocal: keyLocal, cacheKey: cacheKey,
			bucket: bucket, language: language, finished: false,
			isMap: wkIsMapQuery( bucket, keyLocal ), map: options && options.map
		};
		job.promise = new Promise( function ( resolve, reject ) {
			job.resolve = resolve;
			job.reject = reject;
		} );
		// Register before dispatch. Cleanup is synchronous, not a later finally
		// that could delete a replacement submitted immediately after cancellation.
		wkParseInFlight.set( inFlightKey, job );
		wkParseQueue.push( job );
		wkParsePump();
		return job.promise;
	}

	function wkParseQueuePickNext() {
		if ( !wkParseQueue.length ) return null;

		var bestIdx = -1;
		var bestPri = 999;

		for ( var i = 0; i < wkParseQueue.length; i++ ) {
			var job = wkParseQueue[ i ];
			if ( !job || ( job.isMap && wkParseActiveMap >= 1 ) ) continue;

			var b = job.bucket || 'def';
			var pri = ( typeof WK_PARSE_PRIORITY[ b ] === 'number' ) ? WK_PARSE_PRIORITY[ b ] : WK_PARSE_PRIORITY.def;

			if ( pri < bestPri ) {
				bestPri = pri;
				bestIdx = i;
				if ( bestPri === 0 ) break;
			}
		}

		if ( bestIdx < 0 ) return null;

		return wkParseQueue.splice( bestIdx, 1 )[ 0 ];
	}

	function wkParseFinish( job, success, value ) {
		if ( job.finished ) return;
		job.finished = true;
		if ( wkParseInFlight.get( job.inFlightKey ) === job ) wkParseInFlight.delete( job.inFlightKey );
		wkParseActive--;
		if ( job.isMap ) wkParseActiveMap--;
		try {
			if ( success ) {
				job.resolve( value );
			} else {
				wkFailTouch( job.inFlightKey );
				job.reject( value );
			}
		} finally {
			wkParsePump();
		}
	}

	function wkParseStart( job ) {
		wkParseActive++;
		if ( job.isMap ) wkParseActiveMap++;
		try {
			var params = {
				action: 'parse',
				contentmodel: 'wikitext',
				text: job.keyLocal,
				formatversion: 2,
				usearticle: 1,
				redirects: 1,
				useskin: 'vector',
				uselang: job.language
			};
			// The map consumes only HTML. Skip MediaWiki's default link, section,
			// category, template and other metadata, and the discarded debug report.
			// Other parse clients (including Wikipedia hovers) keep their contract.
			if ( job.isMap ) {
				params.prop = 'text';
				params.disablelimitreport = 1;
			}
			var request;
			if ( job.isMap && job.map && mw.config.get( 'wgWikidebatesArgumentMapApi' ) === true ) {
				request = wkGetApi().get( {
					action: 'wikidebatesargumentmap', page: job.map.page, source: job.map.source,
					mode: job.map.mode, uselang: job.language, formatversion: 2
				} ).then( function ( data ) {
					return { parse: { text: data && data.wikidebatesargumentmap && data.wikidebatesargumentmap.html } };
				} );
			} else {
				request = wkGetApi().post( params );
			}
			request.done( function ( data ) {
				if ( job.finished ) return;
				var html;
				try {
					html = data && data.parse && data.parse.text;
					if ( html && typeof html === 'object' ) html = html[ '*' ];
					if ( typeof html !== 'string' ) throw new Error( 'wkParse:invalid-response' );
					html = html.replace( /<!--[\S\s]*?-->/gm, '' );
					var cache = job.bucket === 'latest' ? null : wkGetBucketCache( job.bucket );
					var max = WK_PARSE_CACHE_MAX[ job.bucket ];
					if ( typeof max !== 'number' ) max = WK_PARSE_CACHE_MAX.def;
					wkCacheSetLRU( cache, max, job.cacheKey, job.isMap ?
						{ html: html, expires: Date.now() + WK_MAP_CACHE_TTL_MS } : html );
				} catch ( error ) {
					wkParseFinish( job, false, error );
					return;
				}
				wkParseFinish( job, true, html );
			} ).fail( function ( error ) {
				wkParseFinish( job, false, error );
			} );
		} catch ( error ) {
			// Construction/dispatch failures must not permanently consume a slot.
			wkParseFinish( job, false, error );
		}
	}

	function wkParsePump() {
		if ( wkParsePumping ) return;
		wkParsePumping = true;
		try {
			while ( wkParseActive < WK_PARSE_CONCURRENCY && wkParseQueue.length ) {
				var job = wkParseQueuePickNext();
				if ( !job ) break;
				wkParseStart( job );
			}
		} finally {
			wkParsePumping = false;
		}
	}

	/**
	 * Shared UI for two on-demand wikitext expansions. Keep the trigger available
	 * on errors, and allow a real retry instead of replaying a throttled failure.
	 */
	function wkExpandWikitext( button, wrapperClass, dropClass, wikitext, bucket ) {
		var $button = jQuery( button );
		if ( !$button.length || $button.data( 'wk-expand-loading' ) ) return;

		var $wrapper = $button.parent().find( '.' + wrapperClass ).first();
		if ( !$wrapper.length || !wikitext ) return;

		var $status = $button.next( '.wk-expand-status' );
		if ( !$status.length ) {
			$status = jQuery( '<span class="wk-expand-status" role="status" aria-live="polite"></span>' );
			$status.insertAfter( $button );
		}
		var msg = function ( key, fallback ) {
			return typeof WK.wkMsgD === 'function' ? WK.wkMsgD( key, fallback ) : fallback;
		};
		$button.data( 'wk-expand-loading', true ).attr( {
			'aria-busy': 'true', 'aria-disabled': 'true'
		} ).prop( 'disabled', true );
		$status.attr( 'role', 'status' ).text( msg( 'wk-expand-loading', 'Loading...' ) );

		return Promise.resolve().then( function () {
			return wkParseWikitext( wikitext, bucket, { retry: true } );
		} ).then( function ( html ) {
			if ( !$button[ 0 ].isConnected ) return;
			if ( !$wrapper[ 0 ].isConnected ) throw new Error( 'wkExpand:missing-target' );
			WK.wkReplaceHtml( $wrapper, '<div class="' + wrapperClass + '"><div class="' +
				dropClass + ' show"></div>' + html + '</div>' );
			$button.hide();
			$status.remove();
		} ).catch( function () {
			if ( $button[ 0 ].isConnected ) {
				$status.attr( 'role', 'alert' ).text( msg( 'wk-expand-load-error',
					'Unable to load this content. Select the button again to retry.' ) );
			}
		} ).then( function () {
			$button.removeData( 'wk-expand-loading' ).removeAttr( 'aria-busy aria-disabled' )
				.prop( 'disabled', false );
		} );
	}

	WK.wkGetApi = wkGetApi;
	WK.wkParseWikitext = wkParseWikitext;
	WK.wkGetCachedMap = wkGetCachedMap;
	WK.wkExpandWikitext = wkExpandWikitext;
	WK.wkParseCancel = wkParseCancel;
	WK.wkParsePage = wkParsePage;
	WK.wkCancelPagePrefetch = wkCancelPagePrefetch;
	WK.wkPageParseCache = {
		key: wkPageParseKey,
		get: function ( key ) { return wkCacheGetLRU( wkPageParseCache, key ); },
		// Explicit retry may discard an unusable response without cancelling shared work.
		remove: function ( key ) { wkPageParseCache.delete( key ); },
		// Diagnostic reads must not change which response is evicted next.
		state: function ( key ) {
			return wkPageParseCache.has( key ) ? 'response' :
				( wkPageParseInFlight.has( key ) ? 'pending' : 'network' );
		},
		stats: function () {
			var codeUnits = 0;
			wkPageParseCache.forEach( function ( data ) {
				var text = data.reading ? data.reading.html : ( data.parse.text || '' );
				codeUnits += ( typeof text === 'string' ? text : ( text[ '*' ] || '' ) ).length;
			} );
			return { entries: wkPageParseCache.size, htmlCodeUnits: codeUnits };
		}
	};

	/*	Alias legacy */
	W.wkGetApi = wkGetApi;
	W.wkParseWikitext = wkParseWikitext;
	W.wkParseCancel = wkParseCancel;

}() );
