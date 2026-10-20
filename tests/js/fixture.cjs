const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const jquery = require( 'jquery' );

async function environment( html = '', config = {} ) {
	const dom = new JSDOM( '<!doctype html><body class="action-view">' + html + '</body>', {
		url: 'http://wiki.test/', runScripts: 'outside-only'
	} );
	const { window } = dom;
	await new Promise( ( resolve ) => window.addEventListener( 'load', resolve, { once: true } ) );
	const $ = jquery( window );
	window.requestAnimationFrame = ( fn ) => window.setTimeout( fn, 0 );
	window.$ = window.jQuery = $;
	const hooks = new Map();
	const loads = [];
	window.mw = {
		util: { getUrl: ( title ) => '/wiki/' + encodeURIComponent( title ) },
		config: { get: ( key ) => ( { skin: 'vector-2022', wgUserLanguage: 'fr', wgNamespaceNumber: 0, ...config } )[ key ] },
		loader: {
			load: ( module ) => loads.push( module ),
			using: () => $.Deferred().resolve().promise()
		},
		hook: ( name ) => {
			if ( !hooks.has( name ) ) {
				const callbacks = [];
				let memory;
				const hook = {
					add: ( fn ) => { callbacks.push( fn ); if ( memory ) fn( ...memory ); return hook; },
					fire: ( ...args ) => { memory = args; callbacks.slice().forEach( ( fn ) => fn( ...args ) ); }
				};
				hooks.set( name, hook );
			}
			return hooks.get( name );
		}
	};
	const run = ( file ) => window.eval( fs.readFileSync( path.join( __dirname, '../..', file ), 'utf8' ) );
	run( 'modules/common/core.js' );
	window.Wikidebates.wkIdle = ( fn ) => fn();
	return { window, $, run, loads, close: () => window.close() };
}

const tick = () => new Promise( ( resolve ) => setTimeout( resolve, 5 ) );
module.exports = { environment, tick };
