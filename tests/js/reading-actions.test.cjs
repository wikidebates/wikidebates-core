const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function helper( t ) {
	const env = await environment( '<input id="selection" value="original text"><p id="text">Original selection</p>' );
	t.after( env.close );
	env.run( 'modules/reading/actions.js' );
	env.actions = env.window.Wikidebates.readingActions;
	return env;
}

function clipboard( window, writeText ) {
	Object.defineProperty( window.navigator, 'clipboard', { configurable: true, value: { writeText } } );
}

async function waitFor( predicate ) {
	for ( let i = 0; i < 100; i++ ) {
		if ( predicate() ) return;
		await tick();
	}
	assert.fail( 'Expected DOM state did not arrive' );
}

async function reader( t, skin ) {
	const env = await environment( '<button id="opener">Open</button><div id="bodyContent"></div>', { skin } );
	t.after( env.close );
	const { window, $, run } = env;
	window.scrollTo = () => {};
	const notifications = [];
	window.mw.notify = ( value ) => notifications.push( value );
	window.mw.Api = function () {
		this.get = () => $.Deferred().resolve( { parse: { text: '<h2 id="Summary">Summary</h2><p>Argument</p><h2 id="Parent_debates">Parents</h2>' } } ).promise();
	};
	run( 'modules/common/parse.js' );
	run( 'modules/reading/core.js' );
	run( 'modules/reading/ui.' + skin + '.js' );
	const WK = window.Wikidebates;
	let count = 0;
	async function open() {
		const name = 'Page ' + ++count;
		WK.wkOpenReadingModeFromPageTitle( name, [
			{ page: '', title: 'Debate', type: '' },
			{ page: 'Parent', title: 'Parent', type: 'pour' },
			{ page: name, title: name, type: 'pour' }
		], { history: false } );
		WK.WK_READING.state.h1Links.detailHref = '/wiki/' + encodeURIComponent( name );
		await waitFor( () => window.document.querySelector( '.wk-reading-h1' ) && !window.document.querySelector( '#wk-reading-content' ).hasAttribute( 'aria-busy' ) );
	}
	return { ...env, WK, open, notifications };
}

test( 'clipboard success is asynchronous and does not use the legacy fallback', async ( t ) => {
	const { window, actions } = await helper( t );
	let resolve, notified = false, calls = 0;
	clipboard( window, ( text ) => { assert.equal( text, 'url' ); calls++; return new Promise( ( done ) => { resolve = done; } ); } );
	window.document.execCommand = () => assert.fail( 'Unexpected fallback' );
	const result = actions.copyText( 'url' ).then( ( copied ) => { notified = copied; } );
	await tick();
	assert.equal( notified, false ); assert.equal( calls, 1 );
	resolve(); await result;
	assert.equal( notified, true );
} );

test( 'rejected clipboard falls back, checks its boolean and preserves input selection/focus', async ( t ) => {
	const { window, actions } = await helper( t );
	const input = window.document.getElementById( 'selection' );
	input.focus(); input.setSelectionRange( 2, 7, 'backward' );
	clipboard( window, () => Promise.reject( new Error( 'Permission denied' ) ) );
	let seen;
	window.document.execCommand = ( command ) => { seen = window.document.activeElement.value; assert.equal( command, 'copy' ); return true; };
	assert.equal( await actions.copyText( 'Exact URL' ), true );
	assert.equal( seen, 'Exact URL' );
	assert.equal( window.document.activeElement, input );
	assert.deepEqual( [ input.selectionStart, input.selectionEnd, input.selectionDirection ], [ 2, 7, 'backward' ] );
	assert.equal( window.document.querySelectorAll( 'textarea' ).length, 0 );
} );

for ( const behavior of [ 'false', 'throw', 'missing' ] ) {
	test( 'legacy failure is not success and always removes its textarea: ' + behavior, async ( t ) => {
		const { window, actions } = await helper( t );
		if ( behavior !== 'missing' ) window.document.execCommand = () => { if ( behavior === 'throw' ) throw new Error( 'Denied' ); return false; };
		assert.equal( await actions.copyText( 'url' ), false );
		assert.equal( window.document.querySelectorAll( 'textarea' ).length, 0 );
	} );
}

test( 'fallback restores a document selection and survives a throwing clipboard getter', async ( t ) => {
	const { window, actions } = await helper( t );
	const range = window.document.createRange();
	range.selectNodeContents( window.document.getElementById( 'text' ) );
	window.getSelection().addRange( range );
	Object.defineProperty( window.navigator, 'clipboard', { configurable: true, get() { throw new Error( 'Blocked' ); } } );
	window.document.execCommand = () => true;
	assert.equal( await actions.copyText( 'url' ), true );
	assert.equal( window.getSelection().toString(), 'Original selection' );
} );

test( 'a late rejected clipboard cannot invoke fallback for a stale caller', async ( t ) => {
	const { window, actions } = await helper( t );
	let reject, current = true;
	clipboard( window, () => new Promise( ( resolve, fail ) => { reject = fail; } ) );
	window.document.execCommand = () => assert.fail( 'Stale fallback stole focus' );
	const result = actions.copyText( 'url', () => current );
	current = false; reject( new Error( 'Delayed rejection' ) );
	assert.equal( await result, false );
} );

for ( const skin of [ 'vector', 'minerva' ] ) {
	test( skin + ': copy deduplicates pending clicks and notifies only after success', async ( t ) => {
		const { window, open, notifications } = await reader( t, skin );
		let resolve, calls = 0;
		clipboard( window, () => { calls++; return new Promise( ( done ) => { resolve = done; } ); } );
		await open();
		const more = window.document.querySelector( '.wk-icon-more' );
		const copy = window.document.querySelector( '.wk-h1-menu__copy' );
		more.click(); copy.focus(); copy.click(); copy.click();
		assert.equal( calls, 1 ); assert.equal( copy.getAttribute( 'aria-disabled' ), 'true' );
		assert.deepEqual( notifications, [] );
		resolve(); await waitFor( () => notifications.length );
		assert.deepEqual( notifications, [ 'wk-link-copied' ] );
		assert.equal( more.getAttribute( 'aria-expanded' ), 'false' );
		assert.equal( window.document.activeElement, more );
	} );

	test( skin + ': failed copying exposes a literal, selectable URL instead of a success notice', async ( t ) => {
		const { window, WK, open, notifications } = await reader( t, skin );
		clipboard( window, () => Promise.reject( new Error( 'Denied' ) ) );
		window.document.execCommand = () => false;
		await open();
		WK.WK_READING.state.h1Links.detailHref = '/wiki/A?x="<test>"&y=1';
		window.document.querySelector( '.wk-icon-more' ).click();
		const copy = window.document.querySelector( '.wk-h1-menu__copy' );
		copy.focus(); copy.click();
		await waitFor( () => window.document.querySelector( '.wk-copy-manual input' ) );
		const input = window.document.querySelector( '.wk-copy-manual input' );
		assert.equal( input.value, new URL( WK.WK_READING.state.h1Links.detailHref, 'http://wiki.test/' ).href );
		assert.equal( input.readOnly, true ); assert.equal( input.selectionEnd, input.value.length );
		assert.equal( input.closest( 'label' ).textContent, 'wk-link-copy-manual' );
		assert.equal( window.document.querySelector( 'test' ), null );
		assert.deepEqual( notifications, [] );
		assert.equal( window.document.activeElement, input );
	} );

	test( skin + ': closing or navigating suppresses late copy UI without losing the new focus', async ( t ) => {
		const { window, WK, open, notifications } = await reader( t, skin );
		const rejects = [];
		clipboard( window, () => new Promise( ( resolve, reject ) => rejects.push( reject ) ) );
		window.document.execCommand = () => assert.fail( 'Obsolete fallback' );
		for ( const navigate of [ true, false ] ) {
			await open();
			window.document.querySelector( '.wk-icon-more' ).click();
			window.document.querySelector( '.wk-h1-menu__copy' ).click();
			if ( navigate ) await open(); else WK.wkCloseReadingMode( { fromPop: true } );
			const expectedFocus = window.document.activeElement;
			rejects.shift()( new Error( 'Late denial' ) ); await tick();
			assert.equal( window.document.activeElement, expectedFocus );
			assert.equal( window.document.querySelector( '.wk-copy-manual' ), null );
		}
		assert.deepEqual( notifications, [] );
	} );

	test( skin + ': parent buttons, disclosure toggling, Escape and opener focus restoration', async ( t ) => {
		const { window, WK, open } = await reader( t, skin );
		const D = window.document;
		D.getElementById( 'opener' ).focus();
		await open();
		assert.equal( D.activeElement.id, 'wk-reading-content' );
		assert.equal( D.querySelectorAll( '#wk-reading-stack button[type="button"]' ).length, 2 );
		assert.equal( D.querySelector( '[aria-current="location"]' ).tagName, 'DIV' );
		const more = D.querySelector( '.wk-icon-more' );
		assert.equal( more.tagName, 'BUTTON' );
		more.click(); assert.equal( more.getAttribute( 'aria-expanded' ), 'true' );
		assert.equal( D.getElementById( more.getAttribute( 'aria-controls' ) ).hidden, false );
		more.click(); assert.equal( more.getAttribute( 'aria-expanded' ), 'false' );
		more.click(); D.querySelector( '.wk-h1-menu__detail' ).focus();
		D.activeElement.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
		assert.equal( D.documentElement.classList.contains( 'wk-reading-active' ), true );
		assert.equal( D.activeElement, more );
		D.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
		assert.equal( D.documentElement.classList.contains( 'wk-reading-active' ), false );
		assert.equal( D.activeElement.id, 'opener' );
	} );
}

test( 'menu document handlers are installed once and retain no individual header', async ( t ) => {
	const { window, actions } = await helper( t );
	const D = window.document;
	const counts = {};
	const add = D.addEventListener.bind( D );
	D.addEventListener = ( name, ...args ) => { counts[ name ] = ( counts[ name ] || 0 ) + 1; return add( name, ...args ); };
	for ( let i = 0; i < 20; i++ ) {
		const h1 = D.createElement( 'div' ); h1.className = 'wk-reading-h1';
		h1.innerHTML = '<div class="wk-h1-more"><button class="wk-icon-more"></button><div class="wk-h1-menu" hidden><button class="wk-h1-menu__copy"></button></div></div>';
		D.body.appendChild( h1 ); actions.bindMenu( h1, ( key ) => key ); actions.bindMenu( h1, ( key ) => key );
		h1.remove();
	}
	assert.equal( counts.click, 1 ); assert.equal( counts.focusin, 1 );
} );

test( 'a reused Minerva header can copy the new page while an old copy is pending', async ( t ) => {
	const { window, open, notifications } = await reader( t, 'minerva' );
	const pending = [];
	clipboard( window, ( text ) => new Promise( ( resolve ) => pending.push( { text, resolve } ) ) );
	await open();
	window.document.querySelector( '.wk-icon-more' ).click();
	window.document.querySelector( '.wk-h1-menu__copy' ).click();
	await open();
	window.document.querySelector( '.wk-icon-more' ).click();
	const copy = window.document.querySelector( '.wk-h1-menu__copy' );
	copy.click();
	assert.equal( pending.length, 2 ); assert.notEqual( pending[ 0 ].text, pending[ 1 ].text );
	pending[ 0 ].resolve(); await tick();
	assert.equal( copy.getAttribute( 'aria-disabled' ), 'true' ); assert.deepEqual( notifications, [] );
	pending[ 1 ].resolve(); await waitFor( () => notifications.length );
	assert.deepEqual( notifications, [ 'wk-link-copied' ] );
} );
