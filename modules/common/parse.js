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
	var wkParseFailUntil = new Map();

	var WK_PARSE_PRIORITY = {
		hover: 0,
		bc: 1,
		latest: 2,
		more: 2,
		arg: 3,
		def: 4
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

	function wkFailIsHot( bucket, keyLocal ) {
		var k = bucket + '|' + keyLocal;
		var until = wkParseFailUntil.get( k ) || 0;

		if ( until > Date.now() ) return true;
		if ( until ) wkParseFailUntil.delete( k );

		return false;
	}

	function wkFailTouch( bucket, keyLocal ) {
		var k = bucket + '|' + keyLocal;
		wkParseFailUntil.set( k, Date.now() + WK_PARSE_FAIL_TTL_MS );
	}

	function wkParseCancel( bucket, wikitext ) {
		var keyLocal = String( wikitext || '' );
		var inFlightKey = String( bucket || 'def' ) + '|' + keyLocal;

		for ( var i = wkParseQueue.length - 1; i >= 0; i-- ) {
			if ( wkParseQueue[ i ] && wkParseQueue[ i ].inFlightKey === inFlightKey ) {
				var job = wkParseQueue.splice( i, 1 )[ 0 ];

				if ( wkParseInFlight.has( inFlightKey ) ) wkParseInFlight.delete( inFlightKey );

				try { job.reject( new Error( 'wkParse:cancelled' ) ); } catch ( e ) {}
				break;
			}
		}
	}

	function wkParseWikitext( wikitext, bucket ) {
		bucket = bucket || 'def';

		// Recent changes have a server-side 60-second cache. Do not add
		// another browser cache on top of it and extend their stale lifetime.
		var cache = bucket === 'latest' ? null : wkGetBucketCache( bucket );
		var keyLocal = String( wikitext || '' );
		var inFlightKey = bucket + '|' + keyLocal;

		var cached = wkCacheGetLRU( cache, keyLocal );
		if ( cached !== null ) return Promise.resolve( cached );

		if ( wkFailIsHot( bucket, keyLocal ) ) return Promise.reject( new Error( 'wkParse:fail-cached' ) );

		if ( wkParseInFlight.has( inFlightKey ) ) return wkParseInFlight.get( inFlightKey );

		var p = new Promise( function ( resolve, reject ) {
			wkParseQueue.push( {
				inFlightKey: inFlightKey,
				keyLocal: keyLocal,
				bucket: bucket,
				wikitext: wikitext,
				resolve: resolve,
				reject: reject
			} );
			wkParsePump();
		} );

		// Return and share the cleanup promise too, so a handled API failure
		// cannot leave a second, unobserved rejected promise behind.
		p = p.finally( function () {
			wkParseInFlight.delete( inFlightKey );
		} );
		wkParseInFlight.set( inFlightKey, p );

		return p;
	}

	function wkParseQueuePickNext() {
		if ( !wkParseQueue.length ) return null;

		var bestIdx = -1;
		var bestPri = 999;

		for ( var i = 0; i < wkParseQueue.length; i++ ) {
			var job = wkParseQueue[ i ];
			if ( !job ) continue;

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

	function wkParsePump() {
		while ( wkParseActive < WK_PARSE_CONCURRENCY && wkParseQueue.length ) {
			var job = wkParseQueuePickNext();
			if ( !job ) return;

			wkParseActive++;

			( function ( job2 ) {
				wkGetApi().post( {
					action: 'parse',
					contentmodel: 'wikitext',
					text: job2.wikitext,
					formatversion: 2,
					usearticle: 1,
					redirects: 1,
					useskin: 'vector',
					uselang: mw.config.get( 'wgUserLanguage' )
				} ).done( function ( data ) {

					var html = data && data.parse && data.parse.text
						? ( typeof data.parse.text === 'string' ? data.parse.text : ( data.parse.text[ '*' ] || '' ) )
						: '';

					if ( html ) html = html.replace( /<!--[\S\s]*?-->/gm, '' );

					var cache2 = job2.bucket === 'latest' ? null : wkGetBucketCache( job2.bucket );
					var max = WK_PARSE_CACHE_MAX[ job2.bucket ];
					if ( typeof max !== 'number' ) max = WK_PARSE_CACHE_MAX.def;

					wkCacheSetLRU( cache2, max, job2.keyLocal, html );

					job2.resolve( html );

				} ).fail( function ( err ) {

					wkFailTouch( job2.bucket, job2.keyLocal );
					job2.reject( err );

				} ).always( function () {

					wkParseActive--;
					wkParsePump();

				} );
			} )( job );
		}
	}

	WK.wkGetApi = wkGetApi;
	WK.wkParseWikitext = wkParseWikitext;
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
