const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment } = require( './fixture.cjs' );

const body = '<div class="mw-page-container"><div class="mw-page-container-inner">' +
	'<div class="vector-column-start">' +
		'<div class="vector-main-menu-container"><nav id="mw-panel"></nav></div>' +
		'<div class="vector-sticky-pinned-container"><nav id="mw-panel-toc"></nav></div>' +
	'</div>' +
	'<main class="mw-content-container"><div id="bodyContent"></div></main>' +
	'<div class="vector-column-end"><div class="vector-sticky-pinned-container">' +
		'<nav class="vector-page-tools-landmark"></nav></div></div>' +
	'</div></div>';

async function reader( t, html ) {
	const env = await environment( html );
	t.after( env.close );
	let adapter;
	env.window.Wikidebates.wkReadingSetAdapter = ( next ) => { adapter = next; };
	env.run( 'modules/reading/ui.vector.js' );
	assert.ok( adapter, 'Vector reading UI should register its adapter' );
	const ui = adapter.ensureUi();
	assert.ok( ui && ui.left && ui.stack, 'Reading UI and breadcrumb stack should be created' );
	ui.stack.textContent = 'Debate / argument';
	env.window.document.documentElement.classList.add( 'wk-reading-active' );
	return { ...env, ui, adapter };
}

test( 'Vector with a TOC pins the reading breadcrumb on the left, never near right-hand tools', async ( t ) => {
	const { window, ui, adapter } = await reader( t, body );
	const D = window.document;
	assert.equal( ui.left.parentElement, D.querySelector( '.vector-column-start .vector-sticky-pinned-container' ) );
	assert.equal( ui.left.closest( '.vector-column-end' ), null );
	assert.equal( ui.left.closest( '#mw-panel-toc' ), null, 'TOC itself is hidden in reading mode' );
	assert.equal( D.querySelectorAll( '#wk-reading-left' ).length, 1 );
	assert.equal( D.documentElement.classList.contains( 'wk-reading-left-in-vector' ), true );
	adapter.closeUi();
	assert.equal( D.getElementById( 'wk-reading-left' ), null );
	assert.equal( D.documentElement.classList.contains( 'wk-reading-left-in-vector' ), false );
} );

test( 'Anonymous Vector without a TOC keeps breadcrumbs visible in the reading panel', async ( t ) => {
	const html = body.replace( '<div class="vector-sticky-pinned-container"><nav id="mw-panel-toc"></nav></div>', '' );
	const { window, ui, adapter } = await reader( t, html );
	const D = window.document;
	assert.equal( D.querySelector( '#mw-panel-toc' ), null, 'TOC may be entirely absent in HTML' );
	assert.ok( D.querySelector( '.vector-column-end .vector-sticky-pinned-container' ) );
	assert.equal( ui.left.parentNode, ui.panel, 'must not select the only remaining right-hand pinned container' );
	assert.equal( ui.left.closest( '.vector-column-end' ), null );
	assert.equal( ui.stack.textContent, 'Debate / argument' );
	assert.equal( D.documentElement.classList.contains( 'wk-reading-left-in-vector' ), false );
	adapter.closeUi();
	const again = adapter.ensureUi();
	assert.equal( again.left.parentNode, again.panel, 'reopening must keep the same visible fallback' );
} );

test( 'Unpinned main menu without a TOC cannot swallow the reading breadcrumb', async ( t ) => {
	const html = body.replace( '<div class="vector-sticky-pinned-container"><nav id="mw-panel-toc"></nav></div>', '' )
		.replace( '<nav id="mw-panel"></nav>', '<nav id="mw-panel" style="display:none"><div class="vector-pinnable-element"></div></nav>' );
	const { ui } = await reader( t, html );
	assert.equal( ui.left.parentNode, ui.panel, 'main menu may be hidden by client preferences' );
	assert.equal( ui.left.closest( '#mw-panel' ), null );
} );
