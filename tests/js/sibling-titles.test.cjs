const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment } = require( './fixture.cjs' );

async function fixture( t ) {
	const env = await environment( '', { skin: 'minerva' } );
	t.after( env.close );
	const WK = env.window.Wikidebates;
	WK.minervaReading = {};
	WK.WK_READING = { state: { _wkCurrentPage: 'Current' } };
	env.run( 'modules/reading/minerva/prefetch.js' );
	const siblings = env.window.document.createElement( 'div' );
	siblings.innerHTML = '<div class="wk-reading-siblings__title is-current"><div class="wk-reading-siblings__current">Current label</div></div>';
	const navigation = WK.minervaReading.createNavigation( { siblings } );
	return { ...env, navigation, siblings, WK };
}

test( 'sibling lookup compares page names literally, including quotes, backslashes and Unicode', async ( t ) => {
	const { window, navigation, siblings } = await fixture( t );
	const names = [ 'A"quoted title', 'C:\\temp', 'Trailing\\', 'Both\\"quoted', '100% certain', 'Fran\u00e7ais \u2014 \u03b1' ];
	names.forEach( ( name, i ) => {
		const a = window.document.createElement( 'a' );
		a.className = 'wk-reading-siblings__link'; a.dataset.page = name;
		a.textContent = '  Label\n' + i + '  '; siblings.appendChild( a );
	} );
	names.forEach( ( name, i ) => assert.equal( navigation.getSiblingTitleByPage( name ), 'Label ' + i ) );
} );

test( 'the current label is not substituted for a missing or removed neighbor', async ( t ) => {
	const { window, navigation, siblings, WK } = await fixture( t );
	assert.equal( navigation.getSiblingTitleByPage( 'Current' ), 'Current label' );
	assert.equal( navigation.getSiblingTitleByPage( 'Unknown' ), '' );
	const a = window.document.createElement( 'a' );
	a.className = 'wk-reading-siblings__link'; a.dataset.page = 'Neighbor'; a.textContent = 'Neighbor label';
	siblings.appendChild( a );
	assert.equal( navigation.getSiblingTitleByPage( 'Neighbor' ), 'Neighbor label' );
	a.remove();
	assert.equal( navigation.getSiblingTitleByPage( 'Neighbor' ), '' );
	WK.WK_READING.state._wkCurrentPage = 'Other';
	assert.equal( navigation.getSiblingTitleByPage( 'Current' ), '' );
} );
