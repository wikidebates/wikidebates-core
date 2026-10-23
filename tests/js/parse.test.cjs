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

test( 'prepared fragments open directly and reuse one request for foreground and prefetch', async ( t ) => {
	const { WK, gets, run, window } = await parseEnvironment( t, { wgWikidebatesReadingFragments: true } );
	window.document.body.classList.remove( 'action-view' );
	run( 'modules/reading/core.js' );
	const content = window.document.createElement( 'div' );
	WK.wkReadingSetAdapter( { ensureUi: () => ( { content } ) } );
	const parseParams = WK.wkReadingBuildParseRequestParams( 'Prepared argument' );
	const pending = WK.wkParsePage( parseParams );
	WK.wkOpenReadingModeFromPageTitle( 'Prepared argument', [], { history: false } );
	assert.equal( gets.length, 1 );
	assert.equal( gets[ 0 ].params.action, 'wikidebatesreading' );
	const html = '<h2 id="Summary">Résumé</h2><blockquote>Citation</blockquote><p>Références et objections.</p>';
	gets[ 0 ].request.resolve( { wikidebatesreading: { html, title: 'Prepared argument', revid: 42 } } );
	await pending;
	await tick(); await tick();
	assert.equal( content.innerHTML, html, 'a ready fragment needs no Parent_debates boundary or second extraction' );
	assert.equal( WK.wkReadingFragmentFromData( await WK.wkParsePage( parseParams ) ), html );
	assert.equal( gets.length, 1 );
	assert.equal( WK.wkPageParseCache.stats().htmlCodeUnits, html.length );
} );

test( 'mobile siblings and previews consume the same prepared-fragment response', async ( t ) => {
	const { WK, gets, run, window } = await parseEnvironment( t, { skin: 'minerva', wgWikidebatesReadingFragments: true } );
	run( 'modules/reading/core.js' ); run( 'modules/reading/ui.minerva.js' );
	const chain = ( page ) => [ { page: '', title: 'Debate' }, { page, title: page, type: 'pour' } ];
	WK.WK_READING.state.siblingsByDepth[ 1 ] = [ { page: 'A', title: 'A' }, { page: 'B', title: 'B' } ];
	WK.wkOpenReadingModeFromPageTitle( 'A', chain( 'A' ), { history: false } );
	gets[ 0 ].request.resolve( { wikidebatesreading: { html: '<h2 id="Summary">Résumé</h2><p>A</p>' } } );
	await tick(); await tick(); await tick();
	assert.equal( gets.length, 2 );
	assert.equal( gets[ 1 ].params.action, 'wikidebatesreading' );
	const next = '<h2 id="Summary">Résumé</h2><p>B et sa référence</p>';
	gets[ 1 ].request.resolve( { wikidebatesreading: { html: next } } );
	await tick(); await tick();
	const data = WK.wkPageParseCache.get( WK.wkPageParseCache.key( WK.wkReadingBuildParseRequestParams( 'B' ) ) );
	assert.equal( WK.wkReadingFragmentFromData( data ), next, 'preview decoder accepts the prefetched compact response' );
	WK.wkOpenReadingModeFromPageTitle( 'B', chain( 'B' ), { history: false } );
	await tick(); await tick();
	assert.match( window.document.getElementById( 'wk-reading-content' ).innerHTML, /B et sa référence/ );
	assert.equal( gets.length, 2, 'navigation does not download B again' );
} );

test( 'a failed fragment request is retryable and custom parse options keep their original route', async ( t ) => {
	const { WK, gets } = await parseEnvironment( t, { wgWikidebatesReadingFragments: true } );
	const readingParams = params( 'Argument', { formatversion: 2, usearticle: 1, redirects: 1 } );
	WK.wkParsePage( readingParams ).fail( () => {} );
	assert.equal( gets[ 0 ].params.action, 'wikidebatesreading' );
	gets[ 0 ].request.reject( 'network' );
	await tick();
	WK.wkParsePage( readingParams ).fail( () => {} );
	assert.equal( gets.length, 2 );
	WK.wkParsePage( params( 'Argument', { section: '1' } ) ).fail( () => {} );
	assert.equal( gets[ 2 ].params.action, 'parse' );
} );

test( 'cached older HTML can use fragments and an older backend falls back during deployment', async ( t ) => {
	const { WK, gets } = await parseEnvironment( t, { wgWikidebatesReadingFragments: undefined } );
	const readingParams = params( 'Argument', { formatversion: 2, usearticle: 1, redirects: 1 } );
	const pending = WK.wkParsePage( readingParams );
	assert.equal( gets[ 0 ].params.action, 'wikidebatesreading' );
	gets[ 0 ].request.reject( 'badvalue', { error: { parameter: 'action' } } );
	await tick();
	assert.equal( gets[ 1 ].params.action, 'parse' );
	gets[ 1 ].request.resolve( { parse: { text: 'Legacy HTML' } } );
	assert.equal( ( await pending ).parse.text, 'Legacy HTML' );
	WK.wkParsePage( { ...readingParams, page: 'Next argument' } );
	assert.equal( gets[ 2 ].params.action, 'parse', 'a mixed deployment does not repeat the unavailable-endpoint probe' );
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

async function readingEnvironment( t ) {
	const config = { wgUserLanguage: 'fr' };
	const env = await parseEnvironment( t, config );
	env.window.document.body.classList.remove( 'action-view' );
	env.run( 'modules/reading/core.js' );
	const content = env.window.document.createElement( 'div' );
	let skin = 'vector';
	let extra = {};
	env.WK.wkReadingSetAdapter( {
		ensureUi: () => ( { content } ),
		getParseParams: () => ( { useskin: skin, ...extra } )
	} );
	const open = ( page = 'Private argument' ) => env.WK.wkOpenReadingModeFromPageTitle( page, [], { history: false } );
	const html = ( text ) => '<h2 id="Summary">Summary</h2><p>' + text + '</p><h2 id="Parent_debates">Parents</h2>';
	async function reply( text ) {
		env.gets.at( -1 ).request.resolve( { parse: { text: html( text ) } } );
		await tick();
		await tick();
	}
	return { ...env, config, content, open, reply,
		setSkin: ( value ) => { skin = value; }, setExtra: ( value ) => { extra = value; } };
}

test( 'fragment keys distinguish language, skin and all other parse parameters', async ( t ) => {
	const { config, content, gets, open, reply, setSkin, setExtra } = await readingEnvironment( t );
	open();
	await reply( 'French Vector' );
	config.wgUserLanguage = 'en';
	open();
	assert.equal( gets.length, 2, 'a French fragment cannot satisfy an English request' );
	await reply( 'English Vector' );
	setSkin( 'minerva' );
	open();
	assert.equal( gets.length, 3, 'a Vector fragment cannot satisfy a Minerva request' );
	await reply( 'English Minerva' );
	setExtra( { variant: 'test' } );
	open();
	assert.equal( gets.length, 4 );
	await reply( 'Variant' );
	setExtra( {} );
	setSkin( 'vector' );
	config.wgUserLanguage = 'FR';
	open();
	await tick();
	assert.equal( gets.length, 4, 'returning to an equivalent key reuses the original fragment' );
	assert.match( content.textContent, /French Vector/ );
} );

test( 'a pending read stores its fragment under the parameters captured at request time', async ( t ) => {
	const { config, content, gets, open, reply } = await readingEnvironment( t );
	open();
	config.wgUserLanguage = 'en';
	await reply( 'French' );
	open();
	assert.equal( gets.length, 2, 'the French reply was not stored under the new English settings' );
	await reply( 'English' );
	config.wgUserLanguage = 'fr';
	open();
	await tick();
	assert.equal( gets.length, 2 );
	assert.match( content.textContent, /French/ );
} );

test( 'old fragments remain readable without network after the response LRU has evicted their pages', async ( t ) => {
	const { WK, content, gets, open, reply } = await readingEnvironment( t );
	assert.equal( WK.wkReadingCacheDiagnostics.snapshot().measurement, null, 'timing is off by default' );
	WK.wkReadingCacheDiagnostics.start();
	for ( let i = 0; i < 60; i++ ) {
		open( 'Private argument ' + i );
		await reply( 'Content ' + i );
	}
	const key = WK.wkPageParseCache.key( WK.wkReadingBuildParseRequestParams( 'Private argument 0' ) );
	assert.equal( WK.wkPageParseCache.state( key ), 'network', 'the first full response has been evicted' );
	open( 'Private argument 0' );
	await tick();
	assert.equal( gets.length, 60, 'the first fragment needs no new API call' );
	assert.match( content.textContent, /Content 0/ );
	const snapshot = WK.wkReadingCacheDiagnostics.stop();
	assert.equal( snapshot.fragments.entries, 60, 'no fragment eviction has been introduced' );
	assert.equal( snapshot.responses.entries, 50 );
	assert.equal( snapshot.measurement.opens, 61 );
	assert.equal( snapshot.measurement.reopens, 1 );
	assert.equal( snapshot.measurement.sources.fragment.count, 1 );
	assert.equal( snapshot.measurement.sources.network.count, 60 );
	assert.ok( snapshot.fragments.htmlCodeUnits > 0 );
	assert.ok( snapshot.responses.htmlCodeUnits > 0 );
	assert.equal( JSON.stringify( snapshot ).includes( 'Private argument' ), false, 'reports contain no page titles' );
	assert.equal( JSON.stringify( snapshot ).includes( 'Content 0' ), false, 'reports contain no content' );
	snapshot.measurement.sources.fragment.count = 999;
	assert.equal( WK.wkReadingCacheDiagnostics.snapshot().measurement, null, 'stop releases the measurement state' );
} );

test( 'diagnostics distinguish response-cache and pending-prefetch reads without changing LRU order', async ( t ) => {
	const { WK, gets, open, reply } = await readingEnvironment( t );
	for ( let i = 0; i < 50; i++ ) {
		WK.wkParsePage( WK.wkReadingBuildParseRequestParams( 'Page ' + i ) );
		gets.at( -1 ).request.resolve( { parse: { text: '<p>Full ' + i + '</p>' } } );
	}
	const oldestKey = WK.wkPageParseCache.key( WK.wkReadingBuildParseRequestParams( 'Page 0' ) );
	assert.equal( WK.wkPageParseCache.state( oldestKey ), 'response' );
	WK.wkReadingCacheDiagnostics.snapshot();
	WK.wkParsePage( WK.wkReadingBuildParseRequestParams( 'Prefetched' ) );
	assert.equal( WK.wkPageParseCache.state( oldestKey ), 'response' );
	const prefetched = '<h2 id="Summary">Summary</h2><p>Prefetched</p><h2 id="Parent_debates">Parents</h2>';
	gets.at( -1 ).request.resolve( { parse: { text: prefetched } } );
	assert.equal( WK.wkPageParseCache.state( oldestKey ), 'network', 'diagnostics did not refresh the oldest entry' );
	WK.wkReadingCacheDiagnostics.start();
	open( 'Prefetched' );
	await tick();
	await tick();
	WK.wkParsePage( WK.wkReadingBuildParseRequestParams( 'Pending' ) );
	open( 'Pending' );
	assert.equal( gets.length, 52, 'opening joins the existing prefetch' );
	await reply( 'Pending' );
	const measurement = WK.wkReadingCacheDiagnostics.snapshot().measurement;
	assert.equal( measurement.sources.response.count, 1 );
	assert.equal( measurement.sources.pending.count, 1 );
	assert.equal( measurement.sources.network.count, 0 );
	assert.equal( measurement.reopens, 0 );
	measurement.sources.response.count = 999;
	assert.equal( WK.wkReadingCacheDiagnostics.snapshot().measurement.sources.response.count, 1, 'snapshots are detached' );
} );
