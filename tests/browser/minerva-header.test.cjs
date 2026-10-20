const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright-core' );

const root = path.resolve( __dirname, '../..' );
const longTitle = 'Un argument avec un titre particulièrement long qui doit rester lisible sur plusieurs lignes pendant la lecture et se replier progressivement lorsque la personne fait défiler son contenu sur mobile';

async function fixture( t ) {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
		headless: true, args: [ '--no-sandbox' ]
	} );
	const page = await browser.newPage( { viewport: { width: 390, height: 700 }, isMobile: true, hasTouch: true } );
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	t.after( async () => { await browser.close(); assert.deepEqual( errors, [] ); } );
	await page.setContent( '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="skin-minerva action-view"></body></html>' );
	await page.addStyleTag( { content: 'html,body { margin:0; font:16px Arial,sans-serif; } :root { --wd-arg-icon-size:20px; --background-color-base:#fff; --background-color-neutral-subtle:#fff; }' } );
	for ( const file of [ 'base.css', 'minerva.css' ] ) await page.addStyleTag( { path: path.join( root, 'modules/reading', file ) } );
	await page.addScriptTag( { path: require.resolve( 'jquery' ) } );
	await page.evaluate( () => {
		const hooks = new Map();
		window.mw = {
			config: { get: ( key ) => ( { skin: 'minerva', wgUserLanguage: 'fr', wgNamespaceNumber: 0 } )[ key ] },
			util: { getUrl: ( title ) => '/wiki/' + encodeURIComponent( title ) },
			hook: ( key ) => {
				if ( !hooks.has( key ) ) {
					const callbacks = [];
					hooks.set( key, { add: ( fn ) => callbacks.push( fn ), fire: ( ...args ) => callbacks.forEach( ( fn ) => fn( ...args ) ) } );
				}
				return hooks.get( key );
			},
			Api: function () {
				this.get = () => jQuery.Deferred().resolve( { parse: { text: window.argumentHTML } } ).promise();
			}
		};
	} );
	for ( const file of [ 'common/core.js', 'common/parse.js', 'reading/core.js', 'reading/ui.minerva.js' ] ) {
		await page.addScriptTag( { path: path.join( root, 'modules', file ) } );
	}
	let id = 0;
	async function open( title = longTitle, height = 1800 ) {
		await page.evaluate( ( { title, height, id } ) => {
			window.argumentHTML = '<h2 id="Summary">Résumé</h2><div id="body-probe" style="height:' + height + 'px">Contenu de l’argument</div><h2 id="Parent_debates">Débats parents</h2>';
			Wikidebates.wkOpenReadingModeFromPageTitle( 'Argument ' + id, [ { page: 'Argument ' + id, title, type: 'pour' } ], { history: false } );
		}, { title, height, id: ++id } );
		await page.waitForSelector( '.wk-reading-h1' );
		await settle();
	}
	async function settle() {
		await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
	}
	async function metrics() {
		return page.evaluate( () => {
			const header = document.querySelector( '.wk-reading-h1' );
			const title = header.querySelector( '.wk-reading-h1__title' );
			const content = document.getElementById( 'wk-reading-content' );
			const style = getComputedStyle( title );
			return {
				height: title.getBoundingClientRect().height,
				expanded: title.scrollHeight,
				line: parseFloat( style.lineHeight ), font: style.fontSize,
				scroll: content.scrollTop, range: content.scrollHeight - content.clientHeight,
				progress: parseFloat( header.style.getPropertyValue( '--wk-h1-collapse' ) || '0' )
			};
		} );
	}
	async function scroll( offset ) {
		await page.evaluate( ( offset ) => { document.getElementById( 'wk-reading-content' ).scrollTop = offset; }, offset );
		await settle();
		return metrics();
	}
	return { page, open, settle, metrics, scroll };
}

test( 'multiline title follows small scroll steps continuously and reverses without a threshold', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	await open();
	const initial = await metrics();
	assert.ok( initial.expanded > initial.line * 2 );
	for ( const offset of [ 8, 16, 17, 18, 19, 20, 40, 60, 80, 120, 60, 20, 0 ] ) {
		const state = await scroll( offset );
		const progress = Math.min( 1, offset / 80 );
		assert.ok( Math.abs( state.progress - progress ) < 0.01, JSON.stringify( { offset, state } ) );
		assert.ok( Math.abs( state.height - ( initial.expanded - ( initial.expanded - initial.line ) * progress ) ) < 1 );
		assert.equal( state.scroll, offset, 'header updates must not force scrollTop' );
		assert.equal( state.font, initial.font, 'text must not scale or rewrap during the gesture' );
	}
	const idleWrites = await page.evaluate( async () => {
		let writes = 0;
		const observer = new MutationObserver( ( records ) => { writes += records.length; } );
		observer.observe( document.querySelector( '.wk-reading-h1' ), { attributes: true, attributeFilter: [ 'style' ] } );
		for ( let i = 0; i < 8; i++ ) await new Promise( requestAnimationFrame );
		observer.disconnect();
		return writes;
	} );
	assert.equal( idleWrites, 0, 'no animation or style updates continue after scrolling stops' );
} );

test( 'short scrollable page settles at a partial collapse without oscillating at the bottom', async ( t ) => {
	const { page, open, metrics, scroll, settle } = await fixture( t );
	await open();
	await page.evaluate( () => {
		const content = document.getElementById( 'wk-reading-content' );
		const probe = document.getElementById( 'body-probe' );
		const gap = probe.getBoundingClientRect().top - content.getBoundingClientRect().top;
		probe.style.height = ( content.clientHeight - gap - parseFloat( getComputedStyle( content ).paddingBottom ) + 60 ) + 'px';
		probe.dispatchEvent( new Event( 'load' ) );
	} );
	await settle();
	const initial = await metrics();
	assert.ok( initial.range > 50 && initial.range < 70, JSON.stringify( initial ) );
	await scroll( 10000 );
	const values = [];
	for ( let i = 0; i < 10; i++ ) { await settle(); values.push( await metrics() ); }
	assert.ok( values[ 0 ].height < initial.height );
	assert.ok( values[ 0 ].height > initial.line );
	assert.ok( Math.max( ...values.map( ( v ) => v.height ) ) - Math.min( ...values.map( ( v ) => v.height ) ) < 1 );
	assert.ok( Math.max( ...values.map( ( v ) => v.scroll ) ) - Math.min( ...values.map( ( v ) => v.scroll ) ) < 1 );
	const restored = await scroll( 0 );
	assert.ok( Math.abs( restored.height - initial.height ) < 1 );
} );

test( 'non-scrolling and single-line arguments keep their natural title height', async ( t ) => {
	const { open, metrics, scroll } = await fixture( t );
	await open( longTitle, 10 );
	const initial = await metrics();
	assert.equal( initial.range, 0 );
	assert.equal( ( await scroll( 80 ) ).height, initial.height );
	await open( 'Titre court' );
	const single = await metrics();
	assert.ok( Math.abs( single.height - single.line ) < 1 );
	assert.equal( ( await scroll( 80 ) ).height, single.height );
} );

test( 'viewport resize and argument replacement remeasure the title; closing releases observers', async ( t ) => {
	const { page, open, metrics, scroll, settle } = await fixture( t );
	await open();
	await scroll( 40 );
	await page.setViewportSize( { width: 600, height: 390 } );
	await settle();
	let state = await scroll( 0 );
	assert.ok( Math.abs( state.height - state.expanded ) < 1 );
	await page.setViewportSize( { width: 320, height: 700 } );
	await settle();
	state = await metrics();
	assert.ok( Math.abs( state.height - state.expanded ) < 1 );
	await open( 'Un autre titre court' );
	state = await metrics();
	assert.ok( Math.abs( state.height - state.line ) < 1 );
	assert.equal( state.progress, 0 );
	assert.equal( await page.evaluate( () => {
		const content = document.getElementById( 'wk-reading-content' );
		Wikidebates.wkCloseReadingMode();
		return content._wkH1Collapse === undefined;
	} ), true );
	await open();
	assert.equal( ( await metrics() ).progress, 0 );
} );

async function gestureFixture( t ) {
	const env = await fixture( t );
	await env.page.evaluate( () => {
		Wikidebates.WK_READING.state.siblingsByDepth[ 1 ] = [
			{ page: 'Previous', title: 'Previous' },
			{ page: 'Argument 1', title: 'Current' },
			{ page: 'Next', title: 'Next' }
		];
	} );
	await env.open();
	async function touch( type, x = 195, y = 300, scrollTop ) {
		return env.page.evaluate( ( { type, x, y, scrollTop } ) => {
			const content = document.getElementById( 'wk-reading-content' );
			if ( scrollTop !== undefined ) content.scrollTop = scrollTop;
			const touches = /touchend|touchcancel/.test( type ) ? [] : [
				new Touch( { identifier: 1, target: content, clientX: x, clientY: y } )
			];
			const event = new TouchEvent( type, { bubbles: true, cancelable: true, touches } );
			content.dispatchEvent( event );
			const transform = new DOMMatrix( content.style.transform );
			return {
				prevented: event.defaultPrevented,
				offsetX: transform.m41, offsetY: transform.m42,
				page: Wikidebates.WK_READING.state._wkCurrentPage,
				sheet: document.getElementById( 'wk-reading-mode' ).classList.contains( 'is-sheet-open' )
			};
		}, { type, x, y, scrollTop } );
	}
	return { ...env, touch };
}

test( 'vertical scrolling cannot turn into sibling navigation before release', async ( t ) => {
	const { touch, scroll, metrics } = await gestureFixture( t );
	const { range } = await metrics();
	for ( const [ offset, dy ] of [ [ 200, -30 ], [ 200, 30 ], [ 0, -30 ], [ range, 30 ] ] ) {
		for ( const x of [ 75, 315 ] ) {
			await scroll( offset );
			await touch( 'touchstart' );
			const vertical = await touch( 'touchmove', 195, 300 + dy );
			assert.equal( vertical.prevented, false, 'ordinary scrolling stays with the browser' );
			const lateral = await touch( 'touchmove', x, 300 + dy );
			assert.equal( lateral.offsetX, 0, 'no horizontal preview after vertical scrolling' );
			assert.equal( lateral.offsetY, 0 );
			assert.equal( ( await touch( 'touchend' ) ).page, 'Argument 1' );
		}
	}
} );

test( 'scrolling to an edge and reversing keeps the same gesture in scroll mode', async ( t ) => {
	const { touch, scroll, metrics } = await gestureFixture( t );
	const { range } = await metrics();
	for ( const [ edge, dy ] of [ [ 0, 30 ], [ range, -30 ] ] ) {
		await scroll( 200 );
		await touch( 'touchstart' );
		await touch( 'touchmove', 195, 300 - dy );
		const state = await touch( 'touchmove', 195, 300 + dy, edge );
		assert.equal( state.offsetX, 0 );
		assert.equal( state.offsetY, 0 );
		assert.equal( state.sheet, false );
		await touch( 'touchmove', 75, 300 + dy );
		assert.equal( ( await touch( 'touchend' ) ).page, 'Argument 1' );
	}
} );

test( 'release and cancellation allow a fresh horizontal gesture in either direction', async ( t ) => {
	for ( const end of [ 'touchend', 'touchcancel' ] ) {
		const { touch, scroll, settle } = await gestureFixture( t );
		await scroll( 200 );
		await touch( 'touchstart' );
		await touch( 'touchmove', 195, 270 );
		await touch( end );
		await touch( 'touchstart' );
		await touch( 'touchmove', 75, 300 );
		assert.equal( ( await touch( 'touchend' ) ).page, 'Next' );
		await settle();
		await touch( 'touchstart' );
		await touch( 'touchmove', 315, 300 );
		assert.equal( ( await touch( 'touchend' ) ).page, 'Argument 1' );
	}
} );

test( 'small or diagonal initial motion does not prematurely choose the vertical axis', async ( t ) => {
	const { touch, scroll } = await gestureFixture( t );
	await scroll( 200 );
	await touch( 'touchstart' );
	await touch( 'touchmove', 196, 303 );
	await touch( 'touchmove', 175, 280 );
	await touch( 'touchmove', 75, 280 );
	assert.equal( ( await touch( 'touchend' ) ).page, 'Next' );
} );

test( 'vertical edge gestures still reveal the parent and open the contents sheet', async ( t ) => {
	const { touch, scroll } = await gestureFixture( t );
	await scroll( 0 );
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 195, 340 ) ).offsetY, 40 );
	await touch( 'touchcancel' );
	await scroll( 10000 );
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 195, 220 ) ).sheet, true );
	assert.equal( ( await touch( 'touchend' ) ).sheet, true );
} );
