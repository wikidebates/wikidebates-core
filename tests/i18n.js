'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const root = path.resolve( __dirname, '..' );
const read = file => JSON.parse( fs.readFileSync( path.join( root, file ), 'utf8' ) );
const names = read( 'resources/wiki-names.json' ).site;
const source = fs.readFileSync( path.join( root, 'modules/common/i18n.js' ), 'utf8' );
const aliases = read( 'resources/i18n-map.json' ).browser_aliases;
const modules = read( 'extension.json' ).ResourceModules;

async function test( userLanguage, contentLanguage ) {
	const language = userLanguage.toLowerCase().startsWith( 'fr' ) ? 'fr' : 'en';
	const catalog = Object.assign( {}, read( 'i18n/' + language + '.json' ), read( 'i18n/compat/' + language + '.json' ) );
	const messages = {};
	function installMessages( name ) {
		for ( const key of modules[ name ].messages ) {
			assert.ok( Object.hasOwn( catalog, key ), key );
			messages[ key ] = catalog[ key ];
		}
	}
	installMessages( 'ext.wikidebates.common.i18n' );
	const config = { wgUserLanguage: userLanguage, wgContentLanguage: contentLanguage, wgWikidebatesWikiNames: names[ contentLanguage ] };
	const context = {
		window: {}, Promise,
		mw: {
			config: { get: key => config[ key ] },
			message: key => ( { exists: () => Object.hasOwn( messages, key ) } ),
			msg: ( key, ...params ) => messages[ key ].replace( /\$([1-9][0-9]*)/g, ( _, position ) => params[ position - 1 ] ?? '$' + position ),
			loader: { using: () => { throw new Error( 'Unexpected language-module request' ); } }
		}
	};
	vm.runInNewContext( source, context );
	const api = context.window.Wikidebates;
	await api.wkLoadI18n();
	assert.equal( api.wkMsg( 'wk-edit' ), language === 'fr' ? 'Modifier' : 'Edit' );
	assert.equal( api.wkMsg( 'wk-summary-add-generic' ), 'wk-summary-add-generic', 'view pages do not install form summaries' );
	assert.equal( Object.keys( messages ).some( key => key.startsWith( 'wk-summary-' ) ), false );
	installMessages( 'ext.wikidebates.features.editForm' );
	for ( const [ alias, canonical ] of Object.entries( aliases ) ) assert.equal( api.wkMsg( alias, 'Title' ), api.wkMsg( canonical, 'Title' ) );
	for ( const [ key, value ] of Object.entries( names[ contentLanguage ] ) ) {
		if ( key.startsWith( 'wk-' ) ) assert.equal( api.wkMsg( key ), value, key );
	}
	assert.equal( api.wkForm( 'wk-reading-form-name' ), contentLanguage === 'fr' ? 'Nouvel_argument' : 'New_argument' );
	assert.equal( api.wkParamFast( 'wk-param-display-title' ), names[ contentLanguage ][ 'wk-param-display-title' ] );
	api.wkWarmI18nCaches();
	assert.equal( api.WK_T.more, names[ contentLanguage ][ 'wk-tpl-more-content' ] );
	assert.equal( api.wkMsgD( 'missing-key', 'fallback' ), 'fallback' );
	assert.ok( api.wkMsg( 'wk-summary-add-generic', '$2 & title' ).includes( '$2 & title' ) );
	assert.equal( context.window.wkMsg, api.wkMsg );
	assert.equal( api.wkMsg( 'wk-reading-siblings-aria-label' ), language === 'fr' ? 'Arguments connexes' : 'Related arguments' );
	delete config.wgWikidebatesWikiNames;
	assert.equal( api.wkFormFast( 'late-name', 'fallback' ), 'fallback' );
	config.wgWikidebatesWikiNames = { 'late-name': 'arrived', zero: '0' };
	assert.equal( api.wkFormFast( 'late-name', 'fallback' ), 'arrived' );
	assert.equal( api.wkForm( 'zero' ), '0' );
}

( async () => {
	for ( const user of [ 'en', 'fr', 'fr-ca', 'fr-fr', 'de', 'xx' ] ) {
		for ( const content of [ 'en', 'fr' ] ) await test( user, content );
	}
	console.log( 'OK: 12 browser/content language combinations, view/form message loading, technical names, aliases, parameters and late configuration' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
