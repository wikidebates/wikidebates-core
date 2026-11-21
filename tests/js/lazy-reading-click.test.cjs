const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

const html = '<h1 id="firstHeading">Debate</h1><div id="bodyContent">' +
	'<div class="argument-title" id="argument-a"><a href="/wiki/Argument_A">Argument A</a></div>' +
	'<div class="argument-title" id="argument-b"><a href="/wiki/Argument_B">Argument B</a></div>' +
	'<div class="argument-title" id="external"><a href="https://example.org/wiki/External">External</a></div>' +
	'<div id="Argument_map"><div id="argument-a_map" class="argument-title--map">Map A</div></div>' +
	'</div>';

async function fixture( t, skin = 'vector-2022' ) {
	const env = await environment( html, { skin } );
	t.after( env.close );
	const { window, $, run } = env;
	const opens = [];
	const deferred = $.Deferred();
	const calls = [];
	window.mw.loader.using = ( module ) => {
		calls.push( module );
		return deferred.promise();
	};
	run( 'modules/features/view.ns0.js' );
	const fire = ( id ) => {
		const event = $.Event( 'click' );
		$( '#' + id ).trigger( event );
		return event;
	};
	return { ...env, deferred, calls, opens, fire,
		ready: () => { window.Wikidebates.wkOpenReadingModeFromTitleEl = ( el ) => opens.push( el.id ); deferred.resolve(); } };
}

for ( const skin of [ 'vector-2022', 'minerva' ] ) {
	test( skin + ': early clicks join one lazy load, and only the latest title opens', async ( t ) => {
		const { window, calls, opens, fire, ready } = await fixture( t, skin );
		assert.equal( calls.length, 0, 'initial page rendering does not request the reader' );
		assert.equal( fire( 'argument-a' ).isDefaultPrevented(), true );
		assert.equal( fire( 'argument-b' ).isDefaultPrevented(), true );
		await tick();
		assert.deepEqual( calls, [ 'ext.wikidebates.reading.ui.' + ( skin === 'minerva' ? 'minerva' : 'vector' ) ] );
		assert.equal( window.document.getElementById( 'argument-b' ).getAttribute( 'aria-busy' ), 'true' );
		assert.equal( window.document.getElementById( 'argument-a' ).hasAttribute( 'aria-busy' ), false );
		ready();
		await tick();
		assert.deepEqual( opens, [ 'argument-b' ] );
		assert.equal( window.document.getElementById( 'argument-b' ).hasAttribute( 'aria-busy' ), false );
		assert.equal( window.document.getElementById( 'argument-b' ).classList.contains( 'wk-reading-open-pending' ), false );
	} );
}

test( 'already loaded reading UI opens immediately without invoking the lazy loader', async ( t ) => {
	const { window, calls, opens, fire } = await fixture( t );
	window.mw.loader.getState = () => 'ready';
	window.Wikidebates.wkOpenReadingModeFromTitleEl = ( el ) => opens.push( el.id );
	assert.equal( fire( 'argument-a' ).isDefaultPrevented(), true );
	assert.deepEqual( opens, [ 'argument-a' ] );
	assert.deepEqual( calls, [] );
} );

test( 'early map clicks share the same lazy UI and open the matching title element', async ( t ) => {
	const { window, calls, opens, fire, ready } = await fixture( t );
	assert.equal( fire( 'argument-a_map' ).isDefaultPrevented(), true );
	await tick();
	assert.equal( calls.length, 1 );
	ready();
	await tick();
	assert.deepEqual( opens, [ 'argument-a' ] );
	assert.equal( window.document.getElementById( 'argument-a_map' ).classList.contains( 'visited' ), true );
} );

test( 'external links are not intercepted or mistaken for a local reading page', async ( t ) => {
	const { calls, fire } = await fixture( t );
	assert.equal( fire( 'external' ).isDefaultPrevented(), false );
	await tick();
	assert.deepEqual( calls, [] );
} );

test( 'an unmounted title is ignored after the lazy UI finishes loading', async ( t ) => {
	const { window, opens, fire, ready } = await fixture( t );
	fire( 'argument-a' );
	window.document.getElementById( 'argument-a' ).remove();
	ready();
	await tick();
	assert.deepEqual( opens, [] );
} );

test( 'a failed loader navigates to the original title instead of losing the click', async ( t ) => {
	const { window, deferred, fire } = await fixture( t );
	window.history.replaceState( null, '', '/wiki/Argument_A' );
	window.document.querySelector( '#argument-a a' ).setAttribute( 'href', '/wiki/Argument_A#fallback' );
	assert.equal( fire( 'argument-a' ).isDefaultPrevented(), true );
	await tick();
	deferred.reject( 'offline' );
	await tick();
	assert.equal( window.location.hash, '#fallback' );
} );

test( 'loader resolution without a reading handler also navigates to the original link', async ( t ) => {
	const { window, deferred, fire } = await fixture( t );
	window.history.replaceState( null, '', '/wiki/Argument_A' );
	window.document.querySelector( '#argument-a a' ).setAttribute( 'href', '/wiki/Argument_A#missing-ui' );
	fire( 'argument-a' );
	await tick();
	deferred.resolve();
	await tick();
	assert.equal( window.location.hash, '#missing-ui' );
} );

test( 'a navigation before lazy loading completes does not open a stale reader or leave busy state', async ( t ) => {
	const { window, opens, fire, ready } = await fixture( t );
	fire( 'argument-a' );
	window.history.pushState( null, '', '/wiki/Unrelated' );
	ready();
	await tick();
	assert.deepEqual( opens, [] );
	assert.equal( window.document.getElementById( 'argument-a' ).hasAttribute( 'aria-busy' ), false );
} );

test( 'a failed lazy load can be retried by another click with a fresh promise', async ( t ) => {
	const { window, $, deferred, opens, fire } = await fixture( t );
	window.history.replaceState( null, '', '/wiki/Argument_A' );
	window.document.querySelector( '#argument-a a' ).setAttribute( 'href', '/wiki/Argument_A#fallback' );
	fire( 'argument-a' );
	await tick();
	deferred.reject( 'offline' );
	await tick();
	assert.equal( window.location.hash, '#fallback' );
	window.history.replaceState( null, '', '/wiki/Argument_A' );
	const second = $.Deferred();
	let retried = 0;
	window.mw.loader.using = () => { retried++; return second.promise(); };
	fire( 'argument-a' );
	await tick();
	assert.equal( retried, 1 );
	window.Wikidebates.wkOpenReadingModeFromTitleEl = ( el ) => opens.push( el.id );
	second.resolve();
	await tick();
	assert.deepEqual( opens, [ 'argument-a' ] );
} );
