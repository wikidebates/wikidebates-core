const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

async function waitFor( predicate ) {
	for ( let i = 0; i < 100; i++ ) {
		if ( predicate() ) return;
		await tick();
	}
	assert.ok( predicate(), 'Expected reading state was reached' );
}

const parentPage = 'Parent A';
const childPage = 'Hausse de 10 %';
const siblingPage = 'Sibling C';
const parentEdit = '/wiki/Special:FormEdit/Debate/Root?section=pro';
const childEdit = '/wiki/Special:FormEdit/Argument/Parent_A?section=objections';
const parentHref = '/w/index.php?title=Parent_A';
const childHref = '/w/index.php?title=' + encodeURIComponent( childPage );
const heading = ( edit ) => '<h2><span class="bouton-modifier"><a href="' + edit + '">Edit list</a></span></h2>';
const children = heading( childEdit ) + '<ul class="argument-list contre">' +
	'<li class="argument"><div class="argument-title" id="child-title"><a href="' + childHref + '">Child label</a></div></li>' +
	'<li class="argument"><div class="argument-title"><a href="/wiki/Sibling_C">Sibling label</a></div></li></ul>';
const source = '<h1 id="firstHeading">Debate</h1><div id="bodyContent">' + heading( parentEdit ) +
	'<ul class="argument-list pour"><li class="argument level-1">' +
	'<div class="argument-title" id="parent-title"><a href="' + parentHref + '">Parent label</a></div>' +
	'<div class="argument-wrapper">' + children + '</div></li></ul></div>';

async function reader( t, skin ) {
	const env = await environment( source, { skin, wgWikidebatesReadingFragments: true } );
	t.after( env.close );
	const { window, $, run } = env;
	const requests = [];
	window.scrollTo = () => {};
	window.mw.Api = function () {
		this.get = ( params ) => {
			const deferred = $.Deferred();
			requests.push( { params, deferred } );
			return deferred.promise();
		};
	};
	run( 'modules/common/parse.js' );
	run( 'modules/reading/core.js' );
	run( 'modules/reading/ui.' + skin + '.js' );
	const WK = window.Wikidebates, D = window.document;
	const copied = [];
	Object.defineProperty( window.navigator, 'clipboard', { configurable: true,
		value: { writeText: ( value ) => { copied.push( value ); return Promise.resolve(); } } } );
	const reply = ( request = requests.at( -1 ), html ) => request.deferred.resolve( {
		wikidebatesreading: { html: html || '<p>Content</p>' + children }
	} );
	const ready = () => waitFor( () => D.querySelector( '.wk-reading-h1' ) &&
		!D.querySelector( '#wk-reading-content' ).hasAttribute( 'aria-busy' ) );
	function expectLinks( page, detail, edit ) {
		assert.equal( WK.WK_READING.state._wkCurrentPage, page );
		assert.equal( WK.WK_READING.state.h1Links.detailHref, detail );
		assert.equal( WK.WK_READING.state.h1Links.editHref, edit );
		assert.equal( D.querySelector( '#wk-reading-panel' ).dataset.wkDetailHref, detail );
		assert.equal( D.querySelector( '#wk-reading-panel' ).dataset.wkEditTitleHref, edit );
		assert.equal( D.querySelector( '.wk-h1-menu__detail' ).getAttribute( 'href' ), detail );
		assert.equal( D.querySelector( skin === 'minerva' ? '.wk-h1-menu__edit' : '.bouton-modifier.wk-h1-btn a' ).getAttribute( 'href' ), edit );
	}
	return { ...env, WK, D, requests, reply, ready, copied, expectLinks };
}

for ( const skin of [ 'vector', 'minerva' ] ) {
	test( skin + ': direct nested opening captures ancestor actions and parent return updates pending controls and copied URL', async ( t ) => {
		const { WK, D, window, requests, reply, ready, copied, expectLinks } = await reader( t, skin );
		WK.wkOpenReadingModeFromTitleEl( D.getElementById( 'child-title' ) );
		assert.equal( requests[ 0 ].params.page, childPage, 'query-string % title opens the correct page' );
		reply(); await ready();
		expectLinks( childPage, childHref, childEdit );
		assert.equal( window.history.state.chain[ 1 ].editHref, parentEdit );
		D.querySelector( '#wk-reading-stack [data-page="Parent A"]' ).click();
		expectLinks( parentPage, parentHref, parentEdit );
		assert.equal( D.querySelector( '#wk-reading-content' ).getAttribute( 'aria-busy' ), 'true' );
		assert.equal( window.history.state.h1Links.detailHref, parentHref, 'history is saved after action synchronization' );
		reply(); await ready();
		D.querySelector( '.wk-icon-more' ).click();
		D.querySelector( '.wk-h1-menu__copy' ).click();
		await waitFor( () => copied.length === 1 );
		assert.equal( copied[ 0 ], new URL( parentHref, window.location.href ).href );
	} );

	test( skin + ': nested clicks, siblings and real history back/forward retain the right editable list', async ( t ) => {
		const { WK, D, window, reply, ready, expectLinks } = await reader( t, skin );
		WK.wkOpenReadingModeFromTitleEl( D.getElementById( 'parent-title' ) );
		reply(); await ready();
		WK.wkOpenReadingModeFromTitleEl( D.querySelector( '#wk-reading-content #child-title' ) );
		reply(); await ready();
		expectLinks( childPage, childHref, childEdit );
		D.querySelector( '.wk-reading-siblings__link[data-page="Sibling C"]' ).click();
		reply( undefined, '' ); await ready();
		expectLinks( siblingPage, '/wiki/Sibling%20C', childEdit );
		for ( const [ direction, page, detail, edit ] of [
			[ 'back', childPage, childHref, childEdit ],
			[ 'back', parentPage, parentHref, parentEdit ],
			[ 'forward', childPage, childHref, childEdit ],
			[ 'forward', siblingPage, '/wiki/Sibling%20C', childEdit ]
		] ) {
			window.history[ direction ]();
			await waitFor( () => WK.WK_READING.state._wkCurrentPage === page );
			await ready();
			expectLinks( page, detail, edit );
		}
	} );
}

test( 'same argument occurrences preserve independent edit context; direct unknown context never inherits an old edit', async ( t ) => {
	const { WK, window, D, reply, ready, expectLinks } = await reader( t, 'vector' );
	const first = [ { page: parentPage, title: 'First list', type: 'pour', editHref: '/edit/first', detailHref: parentHref } ];
	WK.wkOpenReadingModeFromPageTitle( parentPage, first );
	first[ 0 ].editHref = '/mutated';
	reply(); await ready();
	WK.wkOpenReadingModeFromPageTitle( parentPage, [ { ...first[ 0 ], editHref: '/edit/second' } ] );
	await ready(); expectLinks( parentPage, parentHref, '/edit/second' );
	window.history.back();
	await waitFor( () => WK.WK_READING.state.h1Links.editHref === '/edit/first' );
	await ready(); expectLinks( parentPage, parentHref, '/edit/first' );
	WK.wkOpenReadingModeFromPageTitle( 'Unknown', [ { page: 'Unknown', title: 'Unknown' } ], { history: false } );
	assert.equal( D.querySelector( '.wk-h1-menu__detail' ).getAttribute( 'href' ), '/wiki/Unknown' );
	assert.equal( WK.WK_READING.state.h1Links.editHref, '#' );
} );

test( 'older history restores matching current context but never guesses an unknown ancestor or uses stale child links', async ( t ) => {
	const { WK, window, D, reply, ready, expectLinks } = await reader( t, 'vector' );
	const restore = ( page, chain, h1Links ) => window.dispatchEvent( new window.PopStateEvent( 'popstate', {
		state: { wkReading: 1, wkReadingVersion: 2, page, chain, h1Links }
	} ) );
	const chain = [ { page: parentPage, title: 'Parent' }, { page: childPage, title: 'Child' } ];
	restore( childPage, chain, { detailHref: childHref, editHref: childEdit } );
	reply(); await ready(); expectLinks( childPage, childHref, childEdit );
	D.querySelector( '#wk-reading-stack button[data-page="Parent A"]' ).click();
	reply(); await ready(); expectLinks( parentPage, '/wiki/Parent%20A', '#' );
	restore( parentPage, [ chain[ 0 ] ], { detailHref: childHref, editHref: childEdit } );
	await ready(); expectLinks( parentPage, '/wiki/Parent%20A', '#' );
} );

test( 'late child response and retry cannot restore an obsolete action context', async ( t ) => {
	const { WK, D, requests, reply, ready, expectLinks, window } = await reader( t, 'vector' );
	WK.wkOpenReadingModeFromTitleEl( D.getElementById( 'child-title' ) );
	const childRequest = requests[ 0 ];
	D.querySelector( '#wk-reading-stack button[data-page="Parent A"]' ).click();
	requests.at( -1 ).deferred.reject( 'http' );
	await ready(); expectLinks( parentPage, parentHref, parentEdit );
	const historyLength = window.history.length;
	D.querySelector( '.wk-reading-retry' ).click();
	reply(); await ready();
	reply( childRequest, '<p>Late child</p>' );
	await tick(); await tick();
	expectLinks( parentPage, parentHref, parentEdit );
	assert.equal( window.history.length, historyLength );
	assert.doesNotMatch( D.querySelector( '#wk-reading-content' ).textContent, /Late child/ );
} );
