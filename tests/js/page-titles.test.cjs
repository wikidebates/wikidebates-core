const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment } = require( './fixture.cjs' );

test( 'query-string titles are decoded exactly once, preserving literal percent escape sequences', async ( t ) => {
	const env = await environment(); t.after( env.close );
	const get = env.window.Wikidebates.hrefToPageTitle;
	for ( const title of [ 'Hausse de 10 %', 'Literal %2F and %25', 'A+B & C=D', 'Caf\u00e9 \u00e9thique', '100%AB', '%', '0' ] ) {
		assert.equal( get( '/w/index.php?title=' + encodeURIComponent( title ) + '&action=view#section' ), title );
	}
} );

test( 'article-path titles still decode once, including percent signs, accents and plus signs', async ( t ) => {
	const env = await environment( '', { wgArticlePath: '/articles/$1' } ); t.after( env.close );
	const get = env.window.Wikidebates.hrefToPageTitle;
	for ( const title of [ 'Hausse de 10 %', 'Literal %2F and %25', 'A+B', 'Caf\u00e9 \u00e9thique' ] ) {
		assert.equal( get( '/articles/' + encodeURIComponent( title ) + '#section' ), title );
	}
	assert.equal( get( '/articles/Invalid%ZZ' ), '' );
} );

test( 'query plus-as-space, encoded plus and underscores keep their established meanings', async ( t ) => {
	const env = await environment(); t.after( env.close );
	const get = env.window.Wikidebates.hrefToPageTitle;
	assert.equal( get( '/w/index.php?title=A+B%2BC_D' ), 'A B+C D' );
	assert.equal( get( '/wiki/A+B_C' ), 'A+B C' );
	assert.equal( get( '/w/index.php?title=First&title=Second' ), 'First' );
	assert.equal( get( '/unrelated' ), '' );
	assert.equal( get( '' ), '' );
} );

test( 'matching wiki-looking paths and title parameters on another origin are not local pages', async ( t ) => {
	const env = await environment(); t.after( env.close );
	const get = env.window.Wikidebates.hrefToPageTitle;
	for ( const href of [
		'https://example.com/wiki/Foreign_page',
		'https://example.com/w/index.php?title=Foreign_page',
		'https://wiki.test/wiki/Wrong_scheme',
		'//evil.test/wiki/Foreign_page',
		'javascript:alert(1)',
		'data:text/plain,/wiki/False',
		'https://wiki.test/wiki/Wrong_port',
		'https://wiki.test/w/index.php?title=Wrong_port'
	] ) {
		assert.equal( get( href ), '', href );
	}
	assert.equal( get( 'http://wiki.test/wiki/Local_page' ), 'Local page' );
	assert.equal( get( '/w/index.php?title=Hausse%20de%2010%20%25' ), 'Hausse de 10 %' );
	assert.equal( get( 'https://wiki.test:443/wiki/Different_scheme' ), '' );
} );
