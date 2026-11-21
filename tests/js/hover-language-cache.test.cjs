const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

const markup = '<div id="hover" class="hover-map" data-argument="Context A">' +
	'<a href="/wiki/Argument_A">Argument A</a></div>';

async function fixture( t ) {
	const env = await environment( markup );
	t.after( env.close );
	const { window, $, run } = env;
	const el = window.document.getElementById( 'hover' );
	// jsdom does not calculate layout, but the production listener checks it.
	$.expr.pseudos.hidden = () => false;
	Object.defineProperty( el, 'offsetParent', { get: () => window.document.body } );
	el.getBoundingClientRect = () => ( { top: 10, bottom: 30 } );
	const oldSetTimeout = window.setTimeout.bind( window );
	window.setTimeout = ( fn, delay, ...args ) => oldSetTimeout( fn, delay === 140 ? 0 : delay, ...args );

	let language = 'fr';
	const get = window.mw.config.get;
	window.mw.config.get = ( key ) => key === 'wgUserLanguage' ? language : get( key );
	const calls = [];
	window.mw.Api = function () {
		this.post = ( params ) => {
			const pending = $.Deferred();
			calls.push( { params, pending } );
			return pending.promise();
		};
	};
	run( 'modules/common/i18n.js' );
	run( 'modules/common/parse.js' );
	run( 'modules/features/hover.js' );
	return {
		...env, calls,
		setLanguage: ( value ) => { language = value; },
		enter: async () => { $( el ).trigger( 'mouseenter' ); await tick(); },
		leave: () => $( el ).trigger( 'mouseleave' ),
		reply: async ( index, html ) => {
			calls[ index ].pending.resolve( { parse: { text: html } } );
			await tick();
		},
		contents: () => $( el ).find( '.hover-wrapper' ).html() || ''
	};
}

test( 'the element-local tooltip cache never crosses user languages', async ( t ) => {
	const { calls, enter, leave, reply, contents, setLanguage } = await fixture( t );
	await enter();
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].params.uselang, 'fr' );
	await reply( 0, '<strong>Francais</strong>' );
	assert.match( contents(), /Francais/ );
	leave();

	setLanguage( 'en' );
	await enter();
	assert.equal( calls.length, 2, 'a new language must not reuse the French tooltip' );
	assert.equal( calls[ 1 ].params.uselang, 'en' );
	await reply( 1, '<strong>English</strong>' );
	assert.match( contents(), /English/ );
	leave();

	await enter();
	assert.equal( calls.length, 2, 'the same language and query reuse the local cache' );
	assert.match( contents(), /English/ );
	leave();

	setLanguage( 'fr' );
	await enter();
	assert.equal( calls.length, 2, 'the shared parse cache still retains the original French response' );
	await tick();
	assert.match( contents(), /Francais/ );
} );

test( 'a response from the previous language cannot be displayed or cached', async ( t ) => {
	const { calls, enter, leave, reply, contents, setLanguage } = await fixture( t );
	await enter();
	assert.equal( calls.length, 1 );
	setLanguage( 'en' );
	await reply( 0, '<strong>Ancienne langue</strong>' );
	assert.doesNotMatch( contents(), /Ancienne langue/ );
	leave();
	await enter();
	assert.equal( calls.length, 2 );
	await reply( 1, '<strong>New language</strong>' );
	assert.match( contents(), /New language/ );
} );

test( 'a response to a changed query cannot overwrite the current tooltip', async ( t ) => {
	const { window, calls, enter, leave, reply, contents } = await fixture( t );
	await enter();
	assert.equal( calls.length, 1 );
	const wrap = window.document.getElementById( 'hover' );
	wrap.dataset.argument = 'Context B';
	await reply( 0, '<strong>Old context</strong>' );
	assert.doesNotMatch( contents(), /Old context/ );
	leave();
	await enter();
	assert.equal( calls.length, 2 );
	assert.match( calls[ 1 ].params.text, /Context B/ );
	await reply( 1, '<strong>New context</strong>' );
	assert.match( contents(), /New context/ );
} );

test( 'a hover that has ended never displays its late response', async ( t ) => {
	const { calls, enter, leave, reply, contents } = await fixture( t );
	await enter();
	assert.equal( calls.length, 1 );
	leave();
	await reply( 0, '<strong>Too late</strong>' );
	assert.doesNotMatch( contents(), /Too late/ );
} );
