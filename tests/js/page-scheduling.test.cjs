const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

const params = ( page ) => ( { action: 'parse', page, prop: 'text', formatversion: 2, usearticle: 1, redirects: 1 } );
async function fixture( t, fragments = false ) {
	const env = await environment( '', { wgWikidebatesReadingFragments: fragments, skin: 'minerva' } );
	t.after( env.close );
	const calls = [];
	env.window.mw.Api = function () {
		this.get = ( requestParams ) => {
			const result = env.$.Deferred();
			calls.push( { params: requestParams, result } );
			return result.promise();
		};
	};
	env.run( 'modules/common/parse.js' );
	const WK = env.window.Wikidebates;
	return { ...env, WK, calls,
		request: ( page, prefetch = false ) => WK.wkParsePage( params( page ), { prefetch } ),
		pages: () => calls.map( ( call ) => call.params.page ),
		reply: ( index ) => calls[ index ].result.resolve( fragments ?
			{ wikidebatesreading: { html: '<p>Content</p>' } } : { parse: { text: '<p>Content</p>' } } ) };
}
async function until( predicate ) {
	for ( let i = 0; i < 100 && !predicate(); i++ ) await tick();
	assert.ok( predicate(), 'observable scheduler state reached' );
}

test( 'one prefetch leaves a free slot for a click; queued foreground is FIFO and ahead of background', async ( t ) => {
	const { request, pages, reply } = await fixture( t );
	request( 'Neighbor A', true );
	request( 'Neighbor B', true );
	request( 'Neighbor C', true );
	assert.deepEqual( pages(), [ 'Neighbor A' ] );
	request( 'Clicked A' );
	request( 'Clicked B' );
	request( 'Clicked C' );
	assert.deepEqual( pages(), [ 'Neighbor A', 'Clicked A' ] );
	reply( 1 );
	assert.deepEqual( pages(), [ 'Neighbor A', 'Clicked A', 'Clicked B' ] );
	reply( 0 );
	assert.equal( pages().at( -1 ), 'Clicked C', 'foreground wins over older speculative jobs' );
	reply( 2 );
	assert.equal( pages().at( -1 ), 'Neighbor B' );
	reply( 3 );
	assert.equal( pages().length, 5, 'a second speculative job does not occupy the free slot' );
	reply( 4 );
	assert.equal( pages().at( -1 ), 'Neighbor C' );
} );

test( 'click promotes a queued prefetch and shares exactly its original promise and request', async ( t ) => {
	const { WK, request, pages, reply } = await fixture( t );
	request( 'Active background', true );
	request( 'Older background', true );
	const prefetched = request( 'Target', true );
	assert.equal( WK.wkPageParseCache.state( WK.wkPageParseCache.key( params( 'Target' ) ) ), 'pending' );
	assert.equal( request( 'Target' ), prefetched );
	assert.deepEqual( pages(), [ 'Active background', 'Target' ] );
	assert.equal( request( 'Target', true ), prefetched );
	reply( 1 );
	await prefetched;
	await request( 'Target' );
	assert.equal( pages().filter( ( page ) => page === 'Target' ).length, 1 );
} );

test( 'cancellation removes only queued speculative work, keeping promoted and active consumers', async ( t ) => {
	const { WK, request, pages, reply } = await fixture( t );
	const active = request( 'Active', true );
	request( 'Click 1' );
	const promoted = request( 'Click 2', true );
	const cancelled = request( 'Obsolete', true );
	let reason;
	cancelled.fail( ( code ) => { reason = code; } );
	assert.equal( request( 'Click 2' ), promoted );
	WK.wkCancelPagePrefetch();
	assert.equal( reason, 'wikidebates-prefetch-cancelled' );
	assert.equal( request( 'Active' ), active );
	assert.equal( WK.wkPageParseCache.state( WK.wkPageParseCache.key( params( 'Obsolete' ) ) ), 'network' );
	reply( 1 );
	assert.equal( pages().at( -1 ), 'Click 2' );
	reply( 2 );
	request( 'Obsolete' );
	assert.equal( pages().at( -1 ), 'Obsolete', 'a cancelled speculative request can be retried by the reader' );
} );

test( 'keep-list preserves the destination until it is promoted', async ( t ) => {
	const { WK, request, pages } = await fixture( t );
	request( 'Active', true );
	const target = request( 'Target', true );
	const obsolete = request( 'Old', true );
	WK.wkCancelPagePrefetch( [ WK.wkPageParseCache.key( params( 'Target' ) ) ] );
	assert.equal( obsolete.state(), 'rejected' );
	assert.equal( request( 'Target' ), target );
	assert.deepEqual( pages(), [ 'Active', 'Target' ] );
} );

test( 'failure details survive and release a slot before retry', async ( t ) => {
	const { request, pages, calls } = await fixture( t );
	request( 'A' );
	const failed = request( 'B' );
	request( 'C' );
	let error;
	failed.fail( ( ...args ) => { error = args; } );
	const details = { error: { info: 'Diagnostic' } };
	calls[ 1 ].result.reject( 'http', details );
	assert.deepEqual( error, [ 'http', details ] );
	assert.equal( pages().at( -1 ), 'C' );
	request( 'B' );
	calls[ 0 ].result.resolve( { parse: { text: 'A' } } );
	assert.equal( pages().at( -1 ), 'B' );
} );

test( 'legacy endpoint fallback retains its slot and queued jobs observe the disabled endpoint', async ( t ) => {
	const { WK, request, calls, reply } = await fixture( t, true );
	const first = request( 'A' );
	request( 'B' );
	request( 'C' );
	calls[ 0 ].result.reject( 'unknown_action' );
	await until( () => calls.length === 3 );
	assert.equal( calls[ 2 ].params.action, 'parse' );
	assert.equal( calls[ 2 ].params.page, 'A' );
	calls[ 2 ].result.resolve( { parse: { text: 'Legacy' } } );
	await first;
	await until( () => calls.length === 4 );
	assert.equal( calls[ 3 ].params.action, 'parse' );
	assert.equal( calls[ 3 ].params.page, 'C' );
	reply( 1 );
	assert.equal( WK.wkPageParseCache.state( WK.wkPageParseCache.key( params( 'A' ) ) ), 'response' );
} );

test( 'synchronous API exceptions and successes leave no phantom in-flight jobs', async ( t ) => {
	const { WK, window, $ } = await fixture( t );
	const api = WK.wkGetApi();
	api.get = () => { throw new Error( 'Sync failure' ); };
	const failed = WK.wkParsePage( params( 'A' ) );
	assert.equal( failed.state(), 'rejected' );
	assert.equal( WK.wkPageParseCache.state( WK.wkPageParseCache.key( params( 'A' ) ) ), 'network' );
	api.get = () => $.Deferred().resolve( { parse: { text: 'OK' } } ).promise();
	for ( let i = 0; i < 100; i++ ) {
		assert.equal( ( await WK.wkParsePage( params( String( i ) ) ) ).parse.text, 'OK' );
	}
	assert.equal( window.Wikidebates.wkPageParseCache.stats().entries, 50 );
} );

test( 'queued parameter snapshots keep variant keys consistent even if caller mutates its object', async ( t ) => {
	const { WK, request, calls, reply } = await fixture( t );
	request( 'A' ); request( 'B' );
	const original = { ...params( 'Variant' ), uselang: 'fr', useskin: 'minerva' };
	const key = WK.wkPageParseCache.key( original );
	const pending = WK.wkParsePage( original );
	original.uselang = 'en'; original.page = 'Other';
	reply( 0 );
	assert.equal( calls[ 2 ].params.uselang, 'fr' );
	assert.equal( calls[ 2 ].params.page, 'Variant' );
	reply( 2 );
	await pending;
	assert.equal( WK.wkPageParseCache.state( key ), 'response' );
} );

test( 'real reader promotes its destination, cancels obsolete neighbors and cancels again on close', async ( t ) => {
	const { WK, request, pages, run } = await fixture( t );
	run( 'modules/reading/core.js' ); run( 'modules/reading/ui.minerva.js' );
	request( 'Active', true );
	const targetParams = WK.wkReadingBuildParseRequestParams( 'Destination' );
	const target = WK.wkParsePage( targetParams, { prefetch: true } );
	const old = request( 'Old', true );
	WK.wkOpenReadingModeFromPageTitle( 'Destination', [ { page: 'Destination', title: 'Destination' } ], { history: false } );
	assert.equal( old.state(), 'rejected' );
	assert.equal( WK.wkParsePage( targetParams ), target );
	assert.deepEqual( pages(), [ 'Active', 'Destination' ] );
	const queued = request( 'Next neighbor', true );
	WK.wkCloseReadingMode( { fromPop: true } );
	assert.equal( queued.state(), 'rejected' );
	await tick(); await tick();
	assert.deepEqual( pages(), [ 'Active', 'Destination' ], 'detached prefetch timers do not restart work' );
} );
