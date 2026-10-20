const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const { environment, tick } = require( './fixture.cjs' );

function row( name = 'Old page' ) {
	return '<div class="multipleTemplateInstance"><select class="language"><option value="en">en</option><option value="fr">fr</option></select>' +
		'<select class="pfTokens"><option>' + name + '</option><option>New page</option></select>' +
		'<span class="instanceRemove"><a href="#">remove</a></span></div>';
}
async function formEnvironment( t ) {
	const env = await environment( '<form id="pfForm"><textarea name="wpSummary">Manual note</textarea>' +
		'<input class="zone-sujet-debat" value="Original subject">' +
		'<div class="zone-nom-consacre"><input value=""></div>' +
		'<div class="zone-interlangue">' + row() + row( 'Second page' ) + '</div>' +
		'<div class="zone-bandeaux"><select><option>draft</option><option>complete</option></select></div></form>' );
	t.after( env.close );
	const WK = env.window.Wikidebates;
	WK.wkIsFormEdit = () => true;
	WK.wkMsg = ( key, value ) => key + ( value ? ': ' + value : '' );
	env.run( 'modules/features/editForm.js' );
	await tick();
	return { ...env, summary: env.$( '[name=wpSummary]' ), subject: env.$( '.zone-sujet-debat' ) };
}

test( 'typing batches duplicate events without scanning unrelated interlang rows', async ( t ) => {
	const { $, window, summary, subject } = await formEnvironment( t );
	let scans = 0, writes = 0;
	for ( const root of window.document.querySelectorAll( '.multipleTemplateInstance' ) ) {
		for ( const method of [ 'querySelector', 'querySelectorAll' ] ) {
			const original = root[ method ].bind( root );
			root[ method ] = ( selector ) => { scans++; return original( selector ); };
		}
	}
	summary.on( 'input', () => writes++ );
	subject.val( 'New subject' ).trigger( 'input' ).trigger( 'change' ).trigger( 'blur' );
	await tick();
	assert.equal( scans, 0 );
	assert.equal( writes, 1 );
	assert.match( summary.val(), /Manual note/ );
	assert.match( summary.val(), /New subject/ );
	subject.trigger( 'input' ).trigger( 'change' ).trigger( 'blur' );
	await tick();
	assert.equal( writes, 1, 'unchanged summaries must not retrigger autogrow' );
	subject.val( 'Original subject' ).trigger( 'input' );
	await tick();
	assert.equal( summary.val(), 'Manual note', 'reverted changes remove their automatic summary' );
} );

test( 'submit flushes the last edit before serialization', async ( t ) => {
	const { window, summary, subject } = await formEnvironment( t );
	let submittedSummary;
	const form = window.document.getElementById( 'pfForm' );
	form.addEventListener( 'submit', ( e ) => { e.preventDefault(); submittedSummary = summary.val(); } );
	subject.val( 'Last keystroke' ).trigger( 'input' );
	form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	assert.match( submittedSummary, /Last keystroke/ );
} );

test( 'interlang updates retain other summaries, support additions, and remove cancelled additions', async ( t ) => {
	const { $, window, summary, subject } = await formEnvironment( t );
	subject.val( 'New subject' ).trigger( 'input' );
	const first = $( '.zone-interlangue .pfTokens' ).first();
	first.val( 'New page' ).trigger( 'change' );
	await tick();
	assert.match( summary.val(), /New subject/ );
	assert.match( summary.val(), /interlanguage-page-modify/ );
	first.val( 'Old page' ).trigger( 'change' );
	await tick();
	assert.doesNotMatch( summary.val(), /interlanguage-page-modify/ );
	$( '.zone-interlangue' ).append( row( 'Added page' ) );
	$( '.zone-interlangue .pfTokens' ).last().trigger( 'change' );
	await tick();
	assert.match( summary.val(), /interlanguage-page-add/ );
	$( '.zone-interlangue .instanceRemove a' ).last()[ 0 ].dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
	await tick();
	assert.doesNotMatch( summary.val(), /interlanguage-page-add/ );
	assert.match( summary.val(), /New subject/ );
} );
