const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function parseEnvironment( t, config = {} ) {
	const env = await environment( '', config );
	t.after( env.close );
	const gets = [], posts = [];
	env.window.mw.Api = function () {
		this.get = ( params ) => { const request = env.$.Deferred(); gets.push( { params, request } ); return request.promise(); };
		this.post = ( params ) => { const request = env.$.Deferred(); posts.push( { params, request } ); return request.promise(); };
	};
	env.run( 'modules/common/parse.js' );
	return { ...env, gets, posts, WK: env.window.Wikidebates };
}
const params = ( page, extra = {} ) => ( { action: 'parse', page, prop: 'text', useskin: 'vector', uselang: 'fr', ...extra } );

test( 'prefetch and foreground share one request and the cached response', async ( t ) => {
	const { WK, gets } = await parseEnvironment( t );
	const prefetch = WK.wkParsePage( params( 'Argument' ) );
	const foreground = WK.wkParsePage( { uselang: 'fr', useskin: 'vector', prop: 'text', page: 'Argument', action: 'parse' } );
	assert.equal( prefetch, foreground );
	assert.equal( gets.length, 1 );
	gets[ 0 ].request.resolve( { parse: { text: '<p>Argument</p>' } } );
	assert.equal( ( await WK.wkParsePage( params( 'Argument' ) ) ).parse.text, '<p>Argument</p>' );
	assert.equal( gets.length, 1 );
} );

test( 'failed requests retry and skin/language variants do not share results', async ( t ) => {
	const { WK, gets } = await parseEnvironment( t );
	WK.wkParsePage( params( 'Argument' ) ).fail( () => {} );
	gets[ 0 ].request.reject( 'network' );
	WK.wkParsePage( params( 'Argument' ) );
	WK.wkParsePage( params( 'Argument', { uselang: 'en' } ) );
	WK.wkParsePage( params( 'Argument', { useskin: 'minerva' } ) );
	assert.equal( gets.length, 4 );
} );

test( 'page response cache is bounded and evicts least recently used entries', async ( t ) => {
	const { WK, gets } = await parseEnvironment( t );
	for ( let i = 0; i < 50; i++ ) {
		WK.wkParsePage( params( String( i ) ) );
		gets.at( -1 ).request.resolve( { parse: { text: String( i ) } } );
	}
	await WK.wkParsePage( params( '0' ) );
	WK.wkParsePage( params( '50' ) );
	gets.at( -1 ).request.resolve( { parse: { text: '50' } } );
	assert.equal( WK.wkPageParseCache.get( WK.wkPageParseCache.key( params( '1' ) ) ), null );
	assert.ok( WK.wkPageParseCache.get( WK.wkPageParseCache.key( params( '0' ) ) ) );
} );

test( 'recent changes reuse only in-flight work; wikitext parse parameters stay unchanged', async ( t ) => {
	const { WK, posts } = await parseEnvironment( t );
	const first = WK.wkParseWikitext( '{{#recentdebatechanges:page=Debate}}', 'latest' );
	assert.equal( WK.wkParseWikitext( '{{#recentdebatechanges:page=Debate}}', 'latest' ), first );
	assert.equal( Object.hasOwn( posts[ 0 ].params, 'prop' ), false );
	posts[ 0 ].request.resolve( { parse: { text: '<ul>old</ul>' } } );
	await first;
	const next = WK.wkParseWikitext( '{{#recentdebatechanges:page=Debate}}', 'latest' );
	assert.equal( posts.length, 2 );
	posts[ 1 ].request.resolve( { parse: { text: '<ul>new</ul>' } } );
	assert.equal( await next, '<ul>new</ul>' );
} );

test( 'real reading loader uses a prefetched full page and preserves fragment boundaries', async ( t ) => {
	const { WK, gets, run, window } = await parseEnvironment( t );
	window.document.body.classList.remove( 'action-view' );
	run( 'modules/reading/core.js' );
	const html = '<p>before</p><h2 id="Summary">Summary</h2><p>argument</p><h2 id="Parent_debates">Parents</h2><p>after</p>';
	const request = WK.wkParsePage( WK.wkReadingBuildParseRequestParams( 'Argument' ) );
	const container = window.document.createElement( 'div' );
	WK.wkReadingSetAdapter( { ensureUi: () => ( { content: container } ) } );
	WK.wkOpenReadingModeFromPageTitle( 'Argument', [], { history: false } );
	assert.equal( gets.length, 1 );
	gets[ 0 ].request.resolve( { parse: { text: html } } );
	await request;
	await tick();
	await tick();
	assert.equal( container.innerHTML, '<h2 id="Summary">Summary</h2><p>argument</p>' );
	assert.equal( WK.wkReadingExtractFragment( html ), '<h2 id="Summary">Summary</h2><p>argument</p>' );
	assert.equal( gets.length, 1 );
	assert.equal( gets[ 0 ].params.prop, 'text' );
	assert.equal( Object.hasOwn( gets[ 0 ].params, 'section' ), false );
} );


test( 'mobile navigation joins an actual sibling prefetch without patching the API', async ( t ) => {
	const { WK, gets, run, window } = await parseEnvironment( t, { skin: 'minerva' } );
	run( 'modules/reading/core.js' );
	run( 'modules/reading/ui.minerva.js' );
	const apiGet = WK.wkGetApi().get;
	const chain = ( page ) => [ { page: '', title: 'Debate' }, { page, title: page, type: 'pour' } ];
	WK.WK_READING.state.siblingsByDepth[ 1 ] = [ { page: 'A', title: 'A' }, { page: 'B', title: 'B' } ];
	WK.wkOpenReadingModeFromPageTitle( 'A', chain( 'A' ), { history: false } );
	gets[ 0 ].request.resolve( { parse: { text: '<h2 id="Summary">Summary</h2><p>A</p><h2 id="Parent_debates">Parents</h2>' } } );
	await tick();
	await tick();
	assert.equal( gets.length, 2, 'the mobile UI prefetched its next sibling' );
	assert.equal( gets[ 1 ].params.page, 'B' );
	WK.wkOpenReadingModeFromPageTitle( 'B', chain( 'B' ), { history: false } );
	assert.equal( gets.length, 2, 'opening B joins the pending prefetch' );
	gets[ 1 ].request.resolve( { parse: { text: '<h2 id="Summary">Summary</h2><p>B</p><h2 id="Parent_debates">Parents</h2>' } } );
	await tick();
	await tick();
	assert.match( window.document.getElementById( 'wk-reading-content' ).innerHTML, /<p>B<\/p>/ );
	assert.equal( WK.wkGetApi().get, apiGet );
} );
