const fs = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '..' );

// Execute the manifest's ordered scripts in fixtures just as ResourceLoader
// does, including when a study reads the manifest from an older Git revision.
function resourceModuleFiles( name, read = ( file ) => fs.readFileSync( path.join( root, file ), 'utf8' ) ) {
	const manifest = JSON.parse( read( 'extension.json' ) );
	const module = manifest.ResourceModules[ name ];
	if ( !module || !Array.isArray( module.scripts ) ) throw new Error( 'Missing script module: ' + name );
	return module.scripts.map( ( file ) => path.posix.join( manifest.ResourceFileModulePaths.localBasePath, file ) );
}

module.exports = { resourceModuleFiles };
