// Synthetic two-slot server, no HTTP or visitor data. Run with a local baseline:
// node tests/pageSchedulingStudy.cjs /tmp/baseline-parse.js
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { performance } = require( 'node:perf_hooks' );
const { environment } = require( './js/fixture.cjs' );
const crypto = require( 'node:crypto' );

async function sample( source ) {
	const env = await environment();
	const queue = [];
	const pending = [];
	const timers = new Set();
	let active = 0;
	const sent = [];
	const delay = 60;
	function pump() {
		while ( active < 2 && queue.length ) {
			const job = queue.shift();
			active++;
			const timer = setTimeout( () => {
				timers.delete( timer );
				active--;
				job.reply.resolve( { parse: { text: '<p>Argument</p>' } } );
				pump();
			}, delay );
			timers.add( timer );
		}
	}
	env.window.mw.Api = function () {
		this.get = ( params ) => {
			const reply = env.$.Deferred();
			sent.push( params.page );
			pending.push( reply.promise() );
			queue.push( { reply } );
			pump();
			return reply.promise();
		};
	};
	try {
		env.window.eval( source );
		const WK = env.window.Wikidebates;
		const params = ( page ) => ( { action: 'parse', page, prop: 'text' } );
		WK.wkParsePage( params( 'Next neighbor' ), { prefetch: true } );
		WK.wkParsePage( params( 'Previous neighbor' ), { prefetch: true } );
		await new Promise( ( resolve ) => setTimeout( resolve, 5 ) );
		const start = performance.now();
		// The real reader now removes obsolete queued speculation before opening.
		if ( WK.wkCancelPagePrefetch ) WK.wkCancelPagePrefetch( [ WK.wkPageParseCache.key( params( 'Clicked argument' ) ) ] );
		await WK.wkParsePage( params( 'Clicked argument' ) );
		const clickMs = performance.now() - start;
		await Promise.all( pending );
		return { click_ms: clickMs, sent, server_slots: 2, service_ms: delay };
	} finally {
		timers.forEach( clearTimeout );
		env.close();
	}
}
async function main() {
	if ( !process.argv[ 2 ] ) throw new Error( 'Pass a local baseline parse.js file' );
	const before = fs.readFileSync( process.argv[ 2 ], 'utf8' );
	const after = fs.readFileSync( path.join( __dirname, '../modules/common/parse.js' ), 'utf8' );
	const report = { node: process.version, source_sha256: {}, samples: { before: [], after: [] } };
	for ( const [ key, source ] of Object.entries( { before, after } ) ) {
		report.source_sha256[ key ] = crypto.createHash( 'sha256' ).update( source ).digest( 'hex' );
	}
	for ( let round = 0; round < 8; round++ ) {
		for ( const name of round % 2 ? [ 'after', 'before' ] : [ 'before', 'after' ] ) {
			report.samples[ name ].push( await sample( name === 'before' ? before : after ) );
		}
	}
	console.log( JSON.stringify( report, null, '\t' ) );
}
main().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
