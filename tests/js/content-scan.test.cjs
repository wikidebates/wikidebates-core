const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

test( 'initial notifications coalesce; nested updates scan only their enclosing root', async ( t ) => {
	const { window, $ , close } = await environment( '<main><div id="parent"><span id="child"></span></div></main>' );
	t.after( close );
	const { document, mw, Wikidebates: WK } = window;
	mw.hook( 'wikipage.ready' ).fire();
	mw.hook( 'wikipage.content' ).fire( $( 'main' ) );
	const scanned = [];
	WK.wkBindContentScan( ( root ) => scanned.push( root ) );
	await tick();
	assert.deepEqual( scanned, [ document ] );
	mw.hook( 'wikipage.ready' ).fire();
	const parent = document.getElementById( 'parent' );
	const child = document.getElementById( 'child' );
	mw.hook( 'wikipage.content' ).fire( $( child ) );
	mw.hook( 'wikipage.content' ).fire( $( parent ) );
	await tick();
	assert.deepEqual( scanned, [ document, parent ] );
	mw.hook( 'wikipage.content' ).fire( $( parent ) );
	await tick();
	assert.deepEqual( scanned, [ document, parent, parent ], 'later mutations of the same node must still be scanned' );
} );

test( 'feature dispatch detects an added root itself and stops searching for loaded features', async ( t ) => {
	const { window, run, loads, close } = await environment();
	t.after( close );
	run( 'modules/features/scan.js' );
	await tick();
	const node = window.document.createElement( 'div' );
	node.className = 'fr-collapsible';
	window.document.body.appendChild( node );
	window.mw.hook( 'wikipage.content' ).fire( node );
	await tick();
	assert.equal( loads.filter( ( name ) => name === 'ext.wikidebates.features.frCollapsible' ).length, 1 );
	let repeatedSearches = 0;
	const query = node.querySelector.bind( node );
	node.querySelector = ( selector ) => { if ( selector === '.fr-collapsible' ) repeatedSearches++; return query( selector ); };
	window.mw.hook( 'wikipage.content' ).fire( node );
	await tick();
	assert.equal( repeatedSearches, 0 );
	assert.equal( loads.filter( ( name ) => name === 'ext.wikidebates.features.frCollapsible' ).length, 1 );
} );
