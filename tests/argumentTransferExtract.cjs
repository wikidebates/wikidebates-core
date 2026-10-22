// Study helper: execute the actual reading extractor, without browser layout.
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const readline = require( 'node:readline' );
const { performance } = require( 'node:perf_hooks' );

const window = { addEventListener() {} };
vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../modules/reading/core.js' ), 'utf8' ),
	{ window, document: { addEventListener() {} } } );
const extract = window.Wikidebates.wkReadingExtractFragment;
readline.createInterface( { input: process.stdin } ).on( 'line', ( line ) => {
	const html = JSON.parse( line ).html;
	const fragment = extract( html );
	const samples = [];
	for ( let i = 0; i < 21; i++ ) {
		const start = performance.now();
		extract( html );
		samples.push( performance.now() - start );
	}
	samples.sort( ( a, b ) => a - b );
	process.stdout.write( JSON.stringify( { fragment, extractionMedianMs: samples[ 10 ], extractionP95Ms: samples[ 19 ] } ) + '\n' );
} );
