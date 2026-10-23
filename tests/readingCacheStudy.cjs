// Controlled Chromium study. All page content and API responses are synthetic;
// no request reaches a wiki. Hypothetical limits never modify the real cache.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { chromium } = require( 'playwright-core' );
const { resourceModuleFiles } = require( './resourceModuleFiles.cjs' );

const root = path.resolve( __dirname, '..' );
const args = process.argv.slice( 2 );
function option( name ) {
	const index = args.indexOf( name );
	return index === -1 ? null : args[ index + 1 ];
}
const baselineInput = option( '--baseline' );
const baseline = baselineInput ? execFileSync( 'git', [ 'rev-parse', '--verify', baselineInput + '^{commit}' ],
	{ cwd: root, encoding: 'utf8' } ).trim() : null;
const output = option( '--output' );
const range = ( start, count ) => Array.from( { length: count }, ( _, i ) => start + i );
const scenarios = {
	short: [ ...range( 0, 12 ), ...range( 0, 12 ) ],
	longRecent: [ ...range( 0, 80 ), ...range( 60, 20 ), ...range( 60, 20 ) ],
	longReturn: [ ...range( 0, 80 ), ...range( 0, 10 ), ...range( 0, 10 ) ]
};

function projectLimit( trace, capacity ) {
	const fragments = new Map(), responses = new Map();
	let network = 0, responseHits = 0, fragmentHits = 0;
	function touch( cache, key, limit ) {
		cache.delete( key );
		cache.set( key, true );
		if ( cache.size > limit ) cache.delete( cache.keys().next().value );
	}
	for ( const key of trace ) {
		if ( fragments.has( key ) ) fragmentHits++;
		else {
			if ( responses.has( key ) ) responseHits++;
			else network++;
			touch( responses, key, 50 );
		}
		touch( fragments, key, capacity );
	}
	return { fragmentCapacity: capacity, networkRequests: network, responseHits, fragmentHits,
		extraNetworkRequests: network - new Set( trace ).size };
}

function distribution( values ) {
	if ( !values.length ) return { count: 0, medianMs: null, p95Ms: null };
	values.sort( ( a, b ) => a - b );
	return { count: values.length, medianMs: values[ Math.floor( values.length / 2 ) ],
		p95Ms: values[ Math.ceil( values.length * 0.95 ) - 1 ] };
}

async function study( browser, skin, trace, revision ) {
	const context = await browser.newContext( {
		viewport: { width: skin === 'minerva' ? 390 : 1280, height: 800 },
		isMobile: skin === 'minerva', hasTouch: skin === 'minerva'
	} );
	try {
		const page = await context.newPage();
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.route( '**/*', ( route ) => route.abort() );
		await page.setContent( '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="action-view"><div id="bodyContent"></div></body></html>' );
		await page.addStyleTag( { content: 'html,body { margin:0; font:16px Arial,sans-serif; } :root { --wd-arg-icon-size:20px; --background-color-base:#fff; --background-color-neutral-subtle:#fff; }' } );
		for ( const file of [ 'base.css', skin === 'minerva' ? 'minerva.css' : 'vector-2022.css' ] ) {
			await page.addStyleTag( { path: path.join( root, 'modules/reading', file ) } );
		}
		await page.addScriptTag( { path: require.resolve( 'jquery' ) } );
		await page.evaluate( ( skin ) => {
			window.studyRequests = 0;
			const hooks = new Map();
			window.mw = {
				config: { get: ( key ) => ( { skin, wgUserLanguage: 'fr', wgNamespaceNumber: 0, wgWikidebatesReadingFragments: false } )[ key ] },
				util: { getUrl: ( title ) => '/wiki/' + encodeURIComponent( title ) },
				hook: ( name ) => {
					if ( !hooks.has( name ) ) {
						const callbacks = [];
						hooks.set( name, { add: ( fn ) => callbacks.push( fn ), fire: ( ...args ) => callbacks.forEach( ( fn ) => fn( ...args ) ) } );
					}
					return hooks.get( name );
				},
				Api: function () {
					this.get = ( params ) => {
						window.studyRequests++;
						const reply = jQuery.Deferred();
						setTimeout( () => reply.resolve( { parse: { text:
							'<div>' + 'Unused page content. '.repeat( 5000 ) + '</div>' +
							'<h2 id="Summary">Résumé</h2><div id="study-probe" data-page="' + params.page + '">' +
							'Argument content. '.repeat( 200 ) + '</div><h2 id="Parent_debates">Débats parents</h2>' +
							'<div>' + 'Unused parent section. '.repeat( 2000 ) + '</div>'
						} } ), 20 );
						return reply.promise();
					};
				}
			};
		}, skin );
		const read = ( file ) => revision ? execFileSync( 'git', [ 'show', revision + ':' + file ], { cwd: root, encoding: 'utf8' } ) :
				fs.readFileSync( path.join( root, file ), 'utf8' );
		for ( const file of [ 'modules/common/core.js', 'modules/common/parse.js', 'modules/reading/core.js',
			...resourceModuleFiles( 'ext.wikidebates.reading.ui.' + ( skin === 'minerva' ? 'minerva' : 'vector' ), read ) ] ) {
			await page.addScriptTag( { content: read( file ) } );
		}
		const cdp = await context.newCDPSession( page );
		await cdp.send( 'HeapProfiler.collectGarbage' );
		const heapBefore = ( await cdp.send( 'Runtime.getHeapUsage' ) ).usedSize;
		const samples = [];
		for ( const id of trace ) {
			samples.push( await page.evaluate( ( id ) => new Promise( ( resolve, reject ) => {
				const title = 'Argument ' + id;
				const before = window.studyRequests;
				const start = performance.now();
				const timeout = setTimeout( () => { observer.disconnect(); reject( new Error( 'Read did not complete' ) ); }, 5000 );
				const observer = new MutationObserver( () => {
					const probe = document.querySelector( '#wk-reading-content #study-probe' );
					if ( !probe || probe.dataset.page !== title ) return;
					observer.disconnect();
					clearTimeout( timeout );
					const fragmentReadyMs = performance.now() - start;
					requestAnimationFrame( () => resolve( { fragmentReadyMs,
						nextFrameMs: performance.now() - start, newRequests: window.studyRequests - before } ) );
				} );
				observer.observe( document.body, { childList: true, subtree: true } );
				Wikidebates.wkOpenReadingModeFromPageTitle( title,
					[ { page: '', title: 'Debate' }, { page: title, title, type: 'pour' } ], { history: false } );
			} ), id ) );
		}
		await cdp.send( 'HeapProfiler.collectGarbage' );
		const heapAfter = ( await cdp.send( 'Runtime.getHeapUsage' ) ).usedSize;
		const state = await page.evaluate( () => ( {
			networkRequests: window.studyRequests,
			cache: Wikidebates.wkReadingCacheDiagnostics ? Wikidebates.wkReadingCacheDiagnostics.snapshot() : null
		} ) );
		assert.deepEqual( errors, [] );
		assert.equal( state.networkRequests, new Set( trace ).size, 'all rereads reuse fragments without network' );
		assert.equal( samples.reduce( ( n, sample ) => n + sample.newRequests, 0 ), state.networkRequests );
		if ( state.cache ) {
			assert.equal( state.cache.measurement, null, 'timing probes stay disabled during before/after measurements' );
			assert.equal( state.cache.fragments.entries, new Set( trace ).size );
			assert.equal( state.cache.responses.entries, Math.min( 50, new Set( trace ).size ) );
		}
		return { revision: revision || 'working-tree', reads: trace.length, reopens: trace.length - new Set( trace ).size,
			reopenRatio: ( trace.length - new Set( trace ).size ) / trace.length,
			...state, heapAfterGcBytes: { before: heapBefore, after: heapAfter, difference: heapAfter - heapBefore },
			timings: Object.fromEntries( [ [ 'newPage', true ], [ 'reread', false ] ].map( ( [ name, cold ] ) => {
				const selected = samples.filter( ( sample ) => !!sample.newRequests === cold );
				return [ name, { fragmentReady: distribution( selected.map( ( sample ) => sample.fragmentReadyMs ) ),
					nextFrame: distribution( selected.map( ( sample ) => sample.nextFrameMs ) ) } ];
			} ) ) };
	} finally {
		await context.close();
	}
}

( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
		headless: true, args: [ '--no-sandbox' ] } );
	try {
		const result = { generatedAt: new Date().toISOString(), chromium: browser.version(), node: process.version,
			baseline, methodology: {
				content: 'Synthetic HTML only; no wiki access; 20ms simulated API delay; no siblings prefetched.',
				heap: 'Total page JavaScript heap after explicit GC outside timing; not attributable solely to caches.',
				cacheSizes: 'HTML UTF-16 code units, not actual heap bytes; strings may share backing storage.',
				timings: 'Open to fragment DOM mutation and next animation frame; one controlled run, no HTTP/FPM measurement.',
				projections: 'Offline LRU replay only. Real fragments remain unlimited. Response LRU stays at 50.',
				frequency: 'Reopen ratios are prescribed by synthetic traces, not observed visitor behavior.'
			}, scenarios: {} };
		let index = 0;
		for ( const [ name, trace ] of Object.entries( scenarios ) ) {
			const entry = { projections: [ 25, 50, 100, 200 ].map( ( limit ) => projectLimit( trace, limit ) ), skins: {} };
			for ( const skin of [ 'vector', 'minerva' ] ) {
				const revisions = baseline ? ( index++ % 2 ? [ null, baseline ] : [ baseline, null ] ) : [ null ];
				entry.skins[ skin ] = [];
				for ( const revision of revisions ) entry.skins[ skin ].push( await study( browser, skin, trace, revision ) );
			}
			result.scenarios[ name ] = entry;
		}
		const json = JSON.stringify( result, null, 2 ) + '\n';
		if ( output ) fs.writeFileSync( path.resolve( output ), json );
		else process.stdout.write( json );
		console.error( 'Reading cache study passed: original fragments remain available in all scenarios.' );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
