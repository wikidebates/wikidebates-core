const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

const root = path.resolve( __dirname, '../..' );
const read = ( file ) => JSON.parse( fs.readFileSync( path.join( root, file ), 'utf8' ) );
const modules = read( 'extension.json' ).ResourceModules;

for ( const userLanguage of [ 'en', 'fr', 'fr-ca', 'fr-fr', 'de' ] ) {
	test( 'lazy form messages produce a translated summary before submit: ' + userLanguage, async ( t ) => {
		const language = userLanguage.startsWith( 'fr' ) ? 'fr' : 'en';
		const catalog = { ...read( 'i18n/' + language + '.json' ), ...read( 'i18n/compat/' + language + '.json' ) };
		const env = await environment( '<div class="mw-special-FormEdit"><form id="pfForm">' +
			'<textarea name="wpSummary">Manual note</textarea><input class="zone-sujet-debat" value="Old subject">' +
			'</form></div>', { wgUserLanguage: userLanguage, wgWikidebatesWikiNames: read( 'resources/wiki-names.json' ).site.en } );
		t.after( env.close );
		const messages = {};
		const install = ( module ) => modules[ module ].messages.forEach( ( key ) => { messages[ key ] = catalog[ key ]; } );
		env.window.mw.message = ( key ) => ( { exists: () => Object.hasOwn( messages, key ) } );
		env.window.mw.msg = ( key, ...params ) => messages[ key ].replace( /\$([1-9][0-9]*)/g,
			( _, position ) => params[ position - 1 ] ?? '$' + position );
		install( 'ext.wikidebates.common.i18n' );
		env.run( 'modules/common/i18n.js' );
		assert.equal( Object.keys( messages ).some( key => key.startsWith( 'wk-summary-' ) ), false );
		// ResourceLoader installs a module's messages before executing its scripts.
		install( 'ext.wikidebates.features.editForm' );
		env.run( 'modules/features/editForm.js' );
		await tick();
		const value = 'New $2 & subject';
		env.$( '.zone-sujet-debat' ).val( value ).trigger( 'input' );
		const form = env.window.document.getElementById( 'pfForm' );
		let summary;
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			summary = env.$( '[name=wpSummary]' ).val();
		} );
		form.dispatchEvent( new env.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
		assert.equal( summary, 'Manual note + /* ' + catalog[ 'wk-summary-section-debate-subject' ] + ' */ ' +
			catalog[ 'wk-summary-subject-mod' ].replace( '$1', () => value ) );
		assert.doesNotMatch( summary, /wk-summary-/ );
	} );
}
