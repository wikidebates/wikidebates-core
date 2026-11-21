const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function waitFor( predicate ) {
	for ( let attempt = 0; attempt < 80; attempt++ ) {
		if ( predicate() ) return;
		await tick();
	}
	assert.ok( predicate(), 'expected reading state was reached' );
}

async function reader( t, fragments = true ) {
	const env = await environment( '', { wgWikidebatesReadingFragments: fragments } );
	t.after( env.close );
	const { window, $ } = env;
	const requests = [];
	window.mw.Api = function () {
		this.get = ( params ) => {
			const result = $.Deferred();
			requests.push( { params, result } );
			return result.promise();
		};
	};
	env.run( 'modules/common/parse.js' );
	env.run( 'modules/reading/core.js' );
	const WK = window.Wikidebates;
	const content = window.document.createElement( 'div' );
	const panel = window.document.createElement( 'div' );
	panel.appendChild( content );
	window.document.body.appendChild( panel );
	WK.wkReadingSetAdapter( {
		ensureUi: () => ( { content, panel } ),
		prependH1: ( ui, chain ) => {
			const h1 = window.document.createElement( 'h1' );
			h1.textContent = chain.at( -1 ).title;
			ui.content.prepend( h1 );
		}
	} );
	const open = ( page ) => WK.wkOpenReadingModeFromPageTitle( page,
		[ { page: '', title: 'Debate' }, { page, title: page, type: 'pour' } ] );
	const reply = ( text ) => requests.at( -1 ).result.resolve( fragments ?
		{ wikidebatesreading: { html: text, title: requests.at( -1 ).params.page } } :
		{ parse: { text: text ? '<h2 id="Summary">Summary</h2>' + text + '<h2 id="Parent_debates">Parents</h2>' : '<p>No extract</p>' } } );
	return { ...env, WK, content, panel, requests, open, reply };
}

for ( const fragments of [ false, true ] ) {
	test( 'failed navigation replaces stale content and retries without another history entry: fragments=' + fragments, async ( t ) => {
		const { open, reply, requests, content, panel, window, WK } = await reader( t, fragments );
		open( 'A' );
		reply( '<p>Original A content</p>' );
		await waitFor( () => content.textContent.includes( 'Original A content' ) );
		open( 'B' );
		const historyLength = window.history.length;
		const historyStep = WK.WK_READING.state._wkHistoryStep;
		assert.equal( content.getAttribute( 'aria-busy' ), 'true' );
		requests.at( -1 ).result.reject( 'http', { error: { info: 'Private diagnostic' } } );
		await waitFor( () => content.querySelector( '.wk-reading-error' ) );
		assert.doesNotMatch( content.textContent, /Original A content|Private diagnostic/ );
		assert.equal( content.querySelector( 'h1' ).textContent, 'B' );
		assert.equal( content.querySelector( '.wk-reading-error' ).getAttribute( 'role' ), 'alert' );
		assert.equal( content.querySelector( '.wk-reading-error a' ).getAttribute( 'href' ), '/wiki/B' );
		assert.equal( content.hasAttribute( 'aria-busy' ), false );
		assert.equal( panel.classList.contains( 'is-loading' ), false );
		const button = content.querySelector( '.wk-reading-retry' );
		button.click();
		button.click();
		assert.equal( requests.length, 3, 'one retry, even on a double click' );
		assert.equal( requests.at( -1 ).params.page, 'B' );
		assert.equal( window.history.length, historyLength );
		assert.equal( WK.WK_READING.state._wkHistoryStep, historyStep );
		reply( '<p>Recovered B content</p>' );
		await waitFor( () => content.textContent.includes( 'Recovered B content' ) );
		assert.equal( content.querySelector( '.wk-reading-error' ), null );
		assert.equal( content.hasAttribute( 'aria-busy' ), false );
	} );

	test( 'empty extract is not a missing page and retry bypasses only its response cache: fragments=' + fragments, async ( t ) => {
		const { open, reply, requests, content, WK } = await reader( t, fragments );
		open( 'Empty' );
		reply( '' );
		await waitFor( () => content.querySelector( '.wk-reading-error' ) );
		assert.equal( content.querySelector( '.wk-reading-missing' ), null );
		assert.match( content.textContent, /wk-reading-empty-fragment/ );
		const key = WK.wkPageParseCache.key( WK.wkReadingBuildParseRequestParams( 'Empty' ) );
		assert.equal( WK.wkPageParseCache.state( key ), 'response' );
		content.querySelector( '.wk-reading-retry' ).click();
		assert.equal( requests.length, 2, 'empty cached response was not reused' );
		reply( '<p>Extract now available</p>' );
		await waitFor( () => content.textContent.includes( 'Extract now available' ) );
	} );
}

test( 'only confirmed missing pages offer creation', async ( t ) => {
	const { open, requests, content } = await reader( t );
	open( 'Missing' );
	requests.at( -1 ).result.reject( 'missingtitle' );
	await waitFor( () => content.querySelector( '.wk-reading-missing' ) );
	assert.equal( content.querySelector( '.wk-reading-error' ), null );
	assert.equal( content.hasAttribute( 'aria-busy' ), false );
} );

test( 'late failures and detached retry buttons cannot replace a newer argument', async ( t ) => {
	const { open, reply, requests, content, WK } = await reader( t );
	open( 'A' );
	const first = requests.at( -1 );
	open( 'B' );
	reply( '<p>Current B</p>' );
	await waitFor( () => content.textContent.includes( 'Current B' ) );
	first.result.reject( 'http' );
	await tick(); await tick(); await tick();
	assert.equal( content.querySelector( '.wk-reading-error' ), null );
	assert.match( content.textContent, /Current B/ );
	open( 'Failed' );
	requests.at( -1 ).result.reject( 'http' );
	await waitFor( () => content.querySelector( '.wk-reading-retry' ) );
	const retry = content.querySelector( '.wk-reading-retry' );
	open( 'C' );
	reply( '<p>Current C</p>' );
	await waitFor( () => content.textContent.includes( 'Current C' ) );
	const count = requests.length;
	retry.click();
	assert.equal( requests.length, count );
	assert.equal( WK.WK_READING.state._wkCurrentPage, 'C' );
} );

test( 'closing a pending read prevents error UI from reopening the panel', async ( t ) => {
	const { open, requests, content, WK, window } = await reader( t );
	open( 'Pending' );
	WK.wkCloseReadingMode( { fromPop: true } );
	requests.at( -1 ).result.reject( 'http' );
	await tick(); await tick(); await tick();
	assert.equal( content.querySelector( '.wk-reading-error' ), null );
	assert.equal( window.document.documentElement.classList.contains( 'wk-reading-active' ), false );
} );

test( 'targeted response removal neither clears another page nor cancels shared pending work', async ( t ) => {
	const { WK, requests } = await reader( t );
	const a = WK.wkReadingBuildParseRequestParams( 'A' );
	const b = WK.wkReadingBuildParseRequestParams( 'B' );
	await Promise.resolve().then( () => {
		WK.wkParsePage( a );
		requests.at( -1 ).result.resolve( { wikidebatesreading: { html: 'A' } } );
	} );
	await waitFor( () => WK.wkPageParseCache.state( WK.wkPageParseCache.key( a ) ) === 'response' );
	const pending = WK.wkParsePage( b );
	WK.wkPageParseCache.remove( WK.wkPageParseCache.key( b ) );
	assert.equal( WK.wkParsePage( b ), pending );
	assert.equal( WK.wkPageParseCache.state( WK.wkPageParseCache.key( a ) ), 'response' );
	assert.equal( requests.length, 2 );
	requests.at( -1 ).result.resolve( { wikidebatesreading: { html: 'B' } } );
	await pending;
} );
