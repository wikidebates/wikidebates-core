const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { environment, tick } = require( './fixture.cjs' );

const root = path.resolve( __dirname, '../..' );
const catalogs = {
	fr: JSON.parse( fs.readFileSync( path.join( root, 'i18n/fr.json' ), 'utf8' ) ),
	en: JSON.parse( fs.readFileSync( path.join( root, 'i18n/en.json' ), 'utf8' ) )
};

const html = '<section id="first"><div class="more-content-button" data-page="Alpha">More A</div>' +
	'<div class="more-content-wrapper"></div></section>' +
	'<section id="second"><div class="more-content-button" data-page="Beta">More B</div>' +
	'<div class="more-content-wrapper"></div></section>' +
	'<section id="latest-a"><h2 class="latest-changes-button">Recent contributions</h2>' +
	'<div class="latest-changes-button mw-ui-button" data-page="Debate A">Show A</div>' +
	'<div class="latest-changes-wrapper"></div></section>' +
	'<section id="latest-b"><h2 class="latest-changes-button">Recent contributions</h2>' +
	'<div class="latest-changes-button mw-ui-button" data-page="Debate B">Show B</div>' +
	'<div class="latest-changes-wrapper"></div></section>';

async function fixture( t, language = 'fr' ) {
	const env = await environment( html, {
		wgUserLanguage: language,
		wgWikidebatesWikiNames: { 'wk-param-page': 'page', 'wk-tpl-more-content': 'More_content' }
	} );
	t.after( env.close );
	const { window, $, run } = env;
	const catalog = catalogs[ language ];
	window.mw.message = ( key ) => ( { exists: () => Object.hasOwn( catalog, key ) } );
	window.mw.msg = ( key ) => catalog[ key ];
	const calls = [];
	window.mw.Api = function () {
		this.post = ( params ) => {
			const deferred = $.Deferred();
			calls.push( { params, deferred } );
			return deferred.promise();
		};
	};
	run( 'modules/common/i18n.js' );
	run( 'modules/common/parse.js' );
	run( 'modules/features/moreContent.js' );
	run( 'modules/features/view.ns0.idle.js' );
	return { ...env, calls, catalog,
		click: ( selector ) => $( selector ).trigger( 'click' ),
		resolve: ( n, html ) => calls[ n ].deferred.resolve( { parse: { text: html } } ),
		reject: ( n ) => calls[ n ].deferred.reject( 'http' ) };
}

test( 'more content keeps the button visible during loading and restores it on error', async ( t ) => {
	const { $, calls, catalog, click, reject, resolve } = await fixture( t );
	click( '#first .more-content-button' );
	click( '#first .more-content-button' );
	await tick();
	assert.equal( calls.length, 1, 'simultaneous clicks never send duplicate requests' );
	assert.equal( calls[ 0 ].params.text, '{{More_content|page = Alpha}}' );
	assert.equal( $( '#first .more-content-button' ).attr( 'aria-busy' ), 'true' );
	assert.notEqual( $( '#first .more-content-button' ).css( 'display' ), 'none' );
	assert.equal( $( '#first .wk-expand-status' ).text(), catalog[ 'wk-expand-loading' ] );
	reject( 0 );
	await tick();
	assert.equal( $( '#first .more-content-button' ).attr( 'aria-busy' ), undefined );
	assert.notEqual( $( '#first .more-content-button' ).css( 'display' ), 'none' );
	assert.equal( $( '#first .wk-expand-status' ).attr( 'role' ), 'alert' );
	assert.equal( $( '#first .wk-expand-status' ).text(), catalog[ 'wk-expand-load-error' ] );
	click( '#first .more-content-button' );
	await tick();
	assert.equal( calls.length, 2, 'an explicit retry bypasses the fifteen-second failure throttle' );
	assert.equal( calls[ 1 ].params.text, calls[ 0 ].params.text );
	resolve( 1, '<p id="loaded-more">Available</p>' );
	await tick();
	assert.equal( $( '#loaded-more' ).text(), 'Available' );
	assert.equal( $( '#first .more-content-button' ).css( 'display' ), 'none' );
	assert.equal( $( '#first .wk-expand-status' ).length, 0 );
} );

test( 'recent changes targets only its clicked button, not the heading or other blocks', async ( t ) => {
	const { $, calls, click, reject, resolve } = await fixture( t );
	click( '#latest-b h2.latest-changes-button' );
	await tick();
	assert.equal( calls.length, 0 );
	click( '#latest-b .mw-ui-button' );
	await tick();
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].params.text, '{{#recentdebatechanges: page=Debate B|limit=100}}' );
	assert.notEqual( $( '#latest-a .mw-ui-button' ).css( 'display' ), 'none' );
	assert.equal( $( '#latest-a .wk-expand-status' ).length, 0 );
	reject( 0 );
	await tick();
	assert.notEqual( $( '#latest-b .mw-ui-button' ).css( 'display' ), 'none' );
	click( '#latest-b .mw-ui-button' );
	await tick();
	assert.equal( calls.length, 2 );
	resolve( 1, '<ul id="recent-list"><li>Changed</li></ul>' );
	await tick();
	assert.equal( $( '#latest-b #recent-list li' ).text(), 'Changed' );
	assert.equal( $( '#latest-b .mw-ui-button' ).css( 'display' ), 'none' );
	assert.notEqual( $( '#latest-a .mw-ui-button' ).css( 'display' ), 'none' );
} );

test( 'two independent more-content buttons never share their busy state', async ( t ) => {
	const { $, calls, click, resolve } = await fixture( t );
	click( '#first .more-content-button' );
	click( '#second .more-content-button' );
	await tick();
	assert.equal( calls.length, 2 );
	assert.equal( $( '#first .more-content-button' ).attr( 'aria-busy' ), 'true' );
	assert.equal( $( '#second .more-content-button' ).attr( 'aria-busy' ), 'true' );
	resolve( 1, 'Second' );
	await tick();
	assert.equal( $( '#second .more-content-button' ).css( 'display' ), 'none' );
	assert.equal( $( '#first .more-content-button' ).attr( 'aria-busy' ), 'true' );
	resolve( 0, 'First' );
	await tick();
	assert.equal( $( '#first .more-content-button' ).css( 'display' ), 'none' );
} );

test( 'the status and error use the selected UI language, without new network requests', async ( t ) => {
	const { $, calls, click, reject, catalog } = await fixture( t, 'en' );
	click( '#latest-a .mw-ui-button' );
	await tick();
	assert.equal( $( '#latest-a .wk-expand-status' ).text(), catalog[ 'wk-expand-loading' ] );
	reject( 0 );
	await tick();
	assert.equal( $( '#latest-a .wk-expand-status' ).text(), catalog[ 'wk-expand-load-error' ] );
	assert.equal( calls.length, 1 );
} );

test( 'removed content does not receive a delayed response', async ( t ) => {
	const { $, click, resolve } = await fixture( t );
	click( '#first .more-content-button' );
	await tick();
	$( '#first' ).remove();
	resolve( 0, '<p id="should-not-insert">Late</p>' );
	await tick();
	assert.equal( $( '#should-not-insert' ).length, 0 );
} );

test( 'unexpected successful markup insertion errors leave the original button retryable', async ( t ) => {
	const { $, window, click, resolve } = await fixture( t );
	window.Wikidebates.wkReplaceHtml = () => { throw new Error( 'insert failed' ); };
	click( '#first .more-content-button' );
	await tick();
	resolve( 0, '<p>Content</p>' );
	await tick();
	assert.equal( $( '#first .wk-expand-status' ).attr( 'role' ), 'alert' );
	assert.equal( $( '#first .more-content-button' ).attr( 'aria-disabled' ), undefined );
} );
