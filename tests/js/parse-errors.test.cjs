const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function setup( t ) {
	const env = await environment();
	t.after( env.close );
	const requests = [];
	env.window.mw.Api = function () {
		this.post = () => {
			const request = env.$.Deferred();
			requests.push( request );
			return request.promise();
		};
	};
	env.run( 'modules/common/parse.js' );
	return { WK: env.window.Wikidebates, requests };
}

test( 'a handled API failure leaves no unhandled cleanup rejection', async ( t ) => {
	const { WK, requests } = await setup( t );
	const first = WK.wkParseWikitext( 'Failed text', 'hover' );
	assert.equal( WK.wkParseWikitext( 'Failed text', 'hover' ), first, 'consumers share the cleanup promise' );
	const error = new Error( 'API failure' );
	const handled = assert.rejects( first, ( reason ) => reason === error );
	requests[ 0 ].reject( error );
	await handled;
	// Node's test runner also fails on any unhandled rejection after the test.
	await tick();
} );

test( 'cancelling queued parsing rejects its shared promise and permits a new request', async ( t ) => {
	const { WK, requests } = await setup( t );
	const active = [ WK.wkParseWikitext( 'Active 1' ), WK.wkParseWikitext( 'Active 2' ) ];
	const queued = WK.wkParseWikitext( 'Queued', 'hover' );
	assert.equal( WK.wkParseWikitext( 'Queued', 'hover' ), queued );
	const handled = assert.rejects( queued, /wkParse:cancelled/ );
	WK.wkParseCancel( 'hover', 'Queued' );
	await handled;
	await tick();
	const retry = WK.wkParseWikitext( 'Queued', 'hover' );
	assert.notEqual( retry, queued );
	assert.equal( requests.length, 2, 'the cancelled job never occupied a connection' );
	requests[ 0 ].resolve( { parse: { text: '<p>First</p>' } } );
	assert.equal( requests.length, 3, 'the replacement is scheduled when a slot is released' );
	requests[ 1 ].resolve( { parse: { text: '<p>Second</p>' } } );
	requests[ 2 ].resolve( { parse: { text: '<p>Replacement</p>' } } );
	await Promise.all( active );
	assert.equal( await retry, '<p>Replacement</p>' );
} );
