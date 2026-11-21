const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function fixture( t ) {
	const config = { wgUserLanguage: 'fr' };
	const env = await environment( '', config );
	t.after( env.close );
	const calls = [];
	env.window.mw.Api = function () {
		this.post = ( params ) => {
			const result = env.$.Deferred();
			calls.push( { params, result } );
			return result.promise();
		};
	};
	env.run( 'modules/common/parse.js' );
	return { ...env, config, calls, WK: env.window.Wikidebates,
		reply: ( index, html = 'OK' ) => calls[ index ].result.resolve( { parse: { text: html } } ) };
}

test( 'cancel followed immediately by retry cannot erase the replacement on the next microtask', async ( t ) => {
	const { WK, calls, reply } = await fixture( t );
	const active = [ WK.wkParseWikitext( 'A' ), WK.wkParseWikitext( 'B' ) ];
	const cancelled = WK.wkParseWikitext( 'Target', 'hover' );
	const rejected = assert.rejects( cancelled, /wkParse:cancelled/ );
	WK.wkParseCancel( 'hover', 'Target' );
	const replacement = WK.wkParseWikitext( 'Target', 'hover' );
	await rejected;
	await tick();
	assert.equal( WK.wkParseWikitext( 'Target', 'hover' ), replacement );
	reply( 0 ); reply( 1 ); reply( 2, 'Replacement' );
	await Promise.all( active );
	assert.equal( await replacement, 'Replacement' );
	assert.equal( calls.length, 3, 'the cancelled request is not downloaded, the replacement is sent once' );
} );

test( 'synchronous dispatch failures release both slots and allow later work', async ( t ) => {
	const { WK } = await fixture( t );
	let calls = 0;
	const error = new Error( 'dispatch failed' );
	WK.wkGetApi().post = () => { calls++; throw error; };
	const first = assert.rejects( WK.wkParseWikitext( 'One' ), ( e ) => e === error );
	const second = assert.rejects( WK.wkParseWikitext( 'Two' ), ( e ) => e === error );
	const third = WK.wkParseWikitext( 'Three' );
	third.catch( () => {} );
	assert.equal( calls, 3, 'exceptions do not leave the pool full forever' );
	await Promise.all( [ first, second, assert.rejects( third, ( e ) => e === error ) ] );
} );

test( 'API construction can fail without preventing the next request from succeeding', async ( t ) => {
	const { WK, window, $ } = await fixture( t );
	let constructions = 0;
	window.mw.Api = function () {
		constructions++;
		if ( constructions <= 2 ) throw new Error( 'constructor failed' );
		this.post = () => $.Deferred().resolve( { parse: { text: 'Recovered' } } ).promise();
	};
	await assert.rejects( WK.wkParseWikitext( 'One' ), /constructor failed/ );
	await assert.rejects( WK.wkParseWikitext( 'Two' ), /constructor failed/ );
	const recovered = WK.wkParseWikitext( 'Three' );
	assert.equal( constructions, 3 );
	assert.equal( await recovered, 'Recovered' );
} );

test( 'queued parses retain their language snapshot and identical text has separate language caches', async ( t ) => {
	const { WK, config, calls, reply } = await fixture( t );
	const active = [ WK.wkParseWikitext( 'A' ), WK.wkParseWikitext( 'B' ) ];
	const french = WK.wkParseWikitext( 'Same', 'hover' );
	assert.equal( WK.wkParseWikitext( 'Same', 'hover' ), french );
	config.wgUserLanguage = 'en';
	const english = WK.wkParseWikitext( 'Same', 'hover' );
	assert.notEqual( english, french );
	reply( 0 );
	assert.equal( calls[ 2 ].params.uselang, 'fr', 'language is captured at submission, not dispatch' );
	reply( 1 );
	assert.equal( calls[ 3 ].params.uselang, 'en' );
	reply( 2, 'French' ); reply( 3, 'English' );
	assert.deepEqual( await Promise.all( [ french, english ] ), [ 'French', 'English' ] );
	assert.equal( await WK.wkParseWikitext( 'Same', 'hover' ), 'English' );
	config.wgUserLanguage = 'fr';
	assert.equal( await WK.wkParseWikitext( 'Same', 'hover' ), 'French' );
	assert.equal( calls.length, 4 );
	await Promise.all( active );
} );

test( 'a language change during an active response cannot contaminate the other language', async ( t ) => {
	const { WK, config, calls, reply } = await fixture( t );
	const french = WK.wkParseWikitext( 'Same' );
	config.wgUserLanguage = 'en';
	const english = WK.wkParseWikitext( 'Same' );
	assert.equal( calls.length, 2 );
	reply( 1, 'English' ); reply( 0, 'French' );
	await Promise.all( [ french, english ] );
	assert.equal( await WK.wkParseWikitext( 'Same' ), 'English' );
	config.wgUserLanguage = 'fr';
	assert.equal( await WK.wkParseWikitext( 'Same' ), 'French' );
} );

test( 'failure throttling is isolated by language and expires after fifteen seconds', async ( t ) => {
	const { WK, config, window, calls, reply } = await fixture( t );
	let now = 100000;
	window.Date.now = () => now;
	const failed = assert.rejects( WK.wkParseWikitext( 'Same' ), ( e ) => e === 'http' );
	calls[ 0 ].result.reject( 'http' );
	await failed;
	await assert.rejects( WK.wkParseWikitext( 'Same' ), /wkParse:fail-cached/ );
	config.wgUserLanguage = 'en';
	const english = WK.wkParseWikitext( 'Same' );
	english.catch( () => {} );
	assert.equal( calls.length, 2 );
	reply( 1, 'English' );
	assert.equal( await english, 'English' );
	config.wgUserLanguage = 'fr';
	now += 14999;
	await assert.rejects( WK.wkParseWikitext( 'Same' ), /wkParse:fail-cached/ );
	now++;
	const french = WK.wkParseWikitext( 'Same' );
	reply( 2, 'French' );
	assert.equal( await french, 'French' );
} );

test( 'failure retention is capped, without evicting successful parse results', async ( t ) => {
	const { WK, window, calls, reply } = await fixture( t );
	window.Date.now = () => 100000;
	const good = WK.wkParseWikitext( 'Good' );
	reply( 0, 'Retained' ); await good;
	for ( let i = 0; i < 129; i++ ) {
		const failed = assert.rejects( WK.wkParseWikitext( 'Failure ' + i ), ( e ) => e === 'http' );
		calls.at( -1 ).result.reject( 'http' );
		await failed;
	}
	const before = calls.length;
	await assert.rejects( WK.wkParseWikitext( 'Failure 128' ), /wkParse:fail-cached/ );
	const retry = WK.wkParseWikitext( 'Failure 0' );
	retry.catch( () => {} );
	assert.equal( calls.length, before + 1, 'the oldest failure is not retained beyond the 128-entry budget' );
	reply( calls.length - 1 ); await retry;
	assert.equal( await WK.wkParseWikitext( 'Good' ), 'Retained' );
	assert.equal( calls.length, before + 1 );
} );

test( 'malformed successful replies reject and release the queue instead of caching empty content', async ( t ) => {
	const { WK, window, calls, reply } = await fixture( t );
	let now = 100000;
	window.Date.now = () => now;
	for ( const data of [ {}, { parse: {} }, { parse: { text: 42 } }, { parse: { text: { '*': {} } } } ] ) {
		const failed = WK.wkParseWikitext( 'Invalid' );
		const outcome = failed.then( () => 'resolved', ( e ) => e.message );
		calls.at( -1 ).result.resolve( data );
		assert.equal( await outcome, 'wkParse:invalid-response' );
		now += 15000;
	}
	const recovered = WK.wkParseWikitext( 'Invalid' );
	assert.equal( calls.length, 5 );
	reply( 4, 'Recovered' );
	assert.equal( await recovered, 'Recovered' );
} );

test( 'valid empty HTML, legacy text wrappers and comment stripping remain supported', async ( t ) => {
	const { WK, calls, reply } = await fixture( t );
	const empty = WK.wkParseWikitext( 'Empty' );
	reply( 0, '' );
	assert.equal( await empty, '' );
	assert.equal( await WK.wkParseWikitext( 'Empty' ), '' );
	const wrapped = WK.wkParseWikitext( 'Wrapped' );
	calls[ 1 ].result.resolve( { parse: { text: { '*': '<!-- hidden -->Visible<!--\nmore-->' } } } );
	assert.equal( await wrapped, 'Visible' );
	assert.equal( calls.length, 2 );
} );

test( 'cancellation removes queued language variants but leaves active work and other buckets alone', async ( t ) => {
	const { WK, config, calls, reply } = await fixture( t );
	const active = [ WK.wkParseWikitext( 'A' ), WK.wkParseWikitext( 'B' ) ];
	const fr = WK.wkParseWikitext( 'Target', 'hover' );
	const frRejected = assert.rejects( fr, /wkParse:cancelled/ );
	config.wgUserLanguage = 'en';
	const en = WK.wkParseWikitext( 'Target', 'hover' );
	const enRejected = assert.rejects( en, /wkParse:cancelled/ );
	const other = WK.wkParseWikitext( 'Target', 'bc' );
	WK.wkParseCancel( 'hover', 'Target' );
	WK.wkParseCancel( 'def', 'A' );
	config.wgUserLanguage = 'fr';
	assert.equal( WK.wkParseWikitext( 'A' ), active[ 0 ] );
	reply( 0 ); reply( 1 ); reply( 2, 'Other bucket' );
	await Promise.all( [ ...active, frRejected, enRejected ] );
	assert.equal( await other, 'Other bucket' );
	assert.equal( calls.length, 3 );
} );

test( 'synchronous responses drain queued work without recursive pumping or phantom latest requests', async ( t ) => {
	const { WK, $, reply } = await fixture( t );
	const active = [ WK.wkParseWikitext( 'A' ), WK.wkParseWikitext( 'B' ) ];
	const queued = Array.from( { length: 200 }, ( _, i ) => WK.wkParseWikitext( 'Job ' + i, 'latest' ) );
	let depth = 0, maxDepth = 0, posts = 0;
	WK.wkGetApi().post = () => {
		posts++;
		const promise = $.Deferred().resolve( { parse: { text: 'Synchronous' } } ).promise();
		const done = promise.done;
		promise.done = function ( handler ) {
			depth++; maxDepth = Math.max( maxDepth, depth );
			try { return done.call( this, handler ); } finally { depth--; }
		};
		return promise;
	};
	reply( 0 ); reply( 1 );
	await Promise.all( [ ...active, ...queued ] );
	assert.equal( maxDepth, 1 );
	assert.equal( posts, 200 );
	const first = WK.wkParseWikitext( 'Latest', 'latest' );
	const second = WK.wkParseWikitext( 'Latest', 'latest' );
	assert.notEqual( first, second, 'a settled latest result is never retained as in-flight work' );
	assert.equal( posts, 202 );
	await Promise.all( [ first, second ] );
} );

test( 'queued explicit clicks outrank hover work without preempting active work or changing FIFO ties', async ( t ) => {
	const { WK, calls, reply } = await fixture( t );
	const pending = [ WK.wkParseWikitext( 'Active A' ), WK.wkParseWikitext( 'Active B' ),
		WK.wkParseWikitext( 'Hover first', 'hover' ), WK.wkParseWikitext( 'Hover second', 'hover' ),
		WK.wkParseWikitext( 'Default' ), WK.wkParseWikitext( 'Breadcrumb', 'bc' ),
		WK.wkParseWikitext( 'Click more', 'more' ), WK.wkParseWikitext( 'Click latest', 'latest' ) ];
	assert.equal( calls.length, 2, 'only two requests may run simultaneously' );
	for ( let i = 0; i < pending.length; i++ ) reply( i );
	assert.deepEqual( calls.map( ( c ) => c.params.text ), [ 'Active A', 'Active B',
		'Click more', 'Click latest', 'Breadcrumb', 'Default', 'Hover first', 'Hover second' ] );
	await Promise.all( pending );
} );

test( 'already-active hover work completes, while a queued click starts first', async ( t ) => {
	const { WK, calls, reply } = await fixture( t );
	const first = WK.wkParseWikitext( 'Running hover 1', 'hover' );
	const second = WK.wkParseWikitext( 'Running hover 2', 'hover' );
	const laterHover = WK.wkParseWikitext( 'Queued hover', 'hover' );
	const clicked = WK.wkParseWikitext( 'Clicked content', 'more' );
	assert.equal( calls.length, 2 );
	reply( 0 );
	assert.equal( calls[ 2 ].params.text, 'Clicked content' );
	reply( 1 );
	assert.equal( calls[ 3 ].params.text, 'Queued hover' );
	reply( 2 ); reply( 3 );
	await Promise.all( [ first, second, laterHover, clicked ] );
} );


test( 'explicit retry bypasses a hot failure only for its own request key', async ( t ) => {
	const { WK, calls, reply } = await fixture( t );
	const failed = assert.rejects( WK.wkParseWikitext( 'More request', 'more' ), ( error ) => error === 'http' );
	calls[ 0 ].result.reject( 'http' );
	await failed;
	await assert.rejects( WK.wkParseWikitext( 'More request', 'more' ), /wkParse:fail-cached/ );
	const retry = WK.wkParseWikitext( 'More request', 'more', { retry: true } );
	assert.equal( calls.length, 2 );
	reply( 1, 'Recovered' );
	assert.equal( await retry, 'Recovered' );
	assert.equal( await WK.wkParseWikitext( 'More request', 'more' ), 'Recovered' );
	const unrelated = assert.rejects( WK.wkParseWikitext( 'Other request', 'hover' ), ( error ) => error === 'http' );
	calls[ 2 ].result.reject( 'http' );
	await unrelated;
	await assert.rejects( WK.wkParseWikitext( 'Other request', 'hover' ), /wkParse:fail-cached/ );
	assert.equal( calls.length, 3 );
} );
