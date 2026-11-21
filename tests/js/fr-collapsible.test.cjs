const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

function box( id, collapsed = true ) {
	return '<section id="' + id + '" class="fr-collapsible' + ( collapsed ? ' fr-collapsed' : '' ) + '">' +
		'<div class="fr-collapsible-toggle" id="' + id + '-toggle">Toggle</div>' +
		'<div class="fr-collapsible-content" id="' + id + '-content"><a href="#inside">Inside</a></div></section>';
}

test( 'a new collapsible section is initialized when it is the content root itself', async ( t ) => {
	const { window, $, run, close } = await environment( box( 'original' ) );
	t.after( close );
	run( 'modules/features/frCollapsible.js' );
	const root = $( box( 'new' ) ).appendTo( window.document.body );
	window.mw.hook( 'wikipage.content' ).fire( root );
	await tick();
	assert.equal( root.find( '.fr-collapsible-toggle' ).attr( 'aria-expanded' ), 'false' );
	assert.equal( root.find( '.fr-collapsible-content' ).is( '[inert]' ), true );
	root.find( '.fr-collapsible-toggle' ).trigger( 'click' );
	assert.equal( root.find( '.fr-collapsible-content' ).is( '[inert]' ), false );
	assert.equal( root.find( '.fr-collapsible-toggle' ).attr( 'aria-expanded' ), 'true' );
	// Another hook on the same root must not bind a duplicate toggle handler.
	window.mw.hook( 'wikipage.content' ).fire( root );
	root.find( '.fr-collapsible-toggle' ).trigger( 'click' );
	assert.equal( root.find( '.fr-collapsible-toggle' ).attr( 'aria-expanded' ), 'false' );
} );

test( 'initial and subsequent closures make descendants inert and restore focus to toggle', async ( t ) => {
	const { window, $, run, close } = await environment( box( 'section' ) );
	t.after( close );
	run( 'modules/features/frCollapsible.js' );
	const content = $( '#section-content' );
	const toggle = $( '#section-toggle' );
	assert.equal( content.is( '[inert]' ), true );
	assert.equal( content.attr( 'aria-hidden' ), 'true' );
	toggle.trigger( 'click' );
	assert.equal( content.is( '[inert]' ), false );
	assert.equal( content.attr( 'aria-hidden' ), 'false' );
	const innerLink = content.find( 'a' )[ 0 ];
	innerLink.focus();
	assert.equal( window.document.activeElement, innerLink );
	toggle.trigger( 'click' );
	assert.equal( content.is( '[inert]' ), true );
	assert.equal( content.attr( 'aria-hidden' ), 'true' );
	assert.equal( window.document.activeElement, toggle[ 0 ] );
} );

test( 'sections missing markup are not marked bound and can initialize when completed', async ( t ) => {
	const { window, $, run, close } = await environment( '<section class="fr-collapsible" id="late"></section>' );
	t.after( close );
	run( 'modules/features/frCollapsible.js' );
	const root = $( '#late' );
	assert.equal( !!root.data( 'fr-collapsible-bound' ), false );
	root.append( '<div class="fr-collapsible-toggle"></div><div class="fr-collapsible-content"></div>' );
	window.mw.hook( 'wikipage.content' ).fire( root );
	assert.equal( !!root.data( 'fr-collapsible-bound' ), true );
} );

test( 'keyboard activation on a native button does not trigger a synthetic second toggle', async ( t ) => {
	const { window, $, run, close } = await environment(
		'<section class="fr-collapsible fr-collapsed"><button class="fr-collapsible-toggle" type="button">Toggle</button>' +
		'<div class="fr-collapsible-content"><a href="#nested">Nested</a></div></section>' );
	t.after( close );
	run( 'modules/features/frCollapsible.js' );
	const toggle = $( '.fr-collapsible-toggle' );
	toggle[ 0 ].dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );
	assert.equal( toggle.attr( 'aria-expanded' ), 'false', 'native button keydown is handled by the browser' );
	toggle[ 0 ].click();
	assert.equal( toggle.attr( 'aria-expanded' ), 'true' );
} );
