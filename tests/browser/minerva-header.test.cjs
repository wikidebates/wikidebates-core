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
				bodyTop: document.getElementById( 'body-probe' ).getBoundingClientRect().top,
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

test( 'multiline title eases into and out of collapse without abrupt body movement', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	await open( longTitle, 5000 );
	const initial = await metrics();
	assert.ok( initial.expanded > initial.line * 2 );
	const distance = Math.ceil( Math.max( 400, ( initial.expanded - initial.line ) * 8 ) );
	const offsets = [ ...new Set( [ 0, 1, 2, 8, 16, 17, 18, 19, 20, 40, 60, 80,
		120, 160, 200, Math.floor( distance / 2 ), distance - 2, distance - 1, distance, distance + 20 ] ) ].sort( ( a, b ) => a - b );
	let previous = initial;
	for ( const offset of offsets.concat( offsets.slice().reverse() ) ) {
		const state = await scroll( offset );
		const step = Math.abs( offset - previous.scroll );
		assert.ok( Math.abs( state.height - previous.height ) <= step * 0.2 + 0.1,
			'the header must not add more than a fifth of a pixel of motion per scrolled pixel' );
		assert.ok( Math.abs( state.bodyTop - previous.bodyTop ) <= step * 1.2 + 0.1,
			'the reading text must move gently along with the scroll' );
		if ( offset <= 2 ) assert.ok( initial.height - state.height < 0.2, 'gentle start' );
		if ( offset >= distance - 2 ) assert.ok( state.height - initial.line < 0.2, 'gentle finish' );
		if ( offset === 80 ) assert.ok( state.progress < 0.5, 'collapse takes substantially more than 80 pixels' );
		if ( offset === distance ) assert.ok( Math.abs( state.height - initial.line ) < 0.1 );
		assert.equal( state.scroll, offset, 'header updates must not force scrollTop' );
		assert.equal( state.font, initial.font, 'text must not scale or rewrap during the gesture' );
		previous = state;
	}
	await scroll( 80 );
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

test( 'collapse covers at least 400 pixels and changes gently during small forward and backward scrolls', async ( t ) => {
	const { open, metrics, scroll } = await fixture( t );
	await open( 'Un titre assez long pour occuper plusieurs lignes sur un écran de téléphone' );
	const shorter = await metrics();
	assert.ok( shorter.expanded > shorter.line );
	assert.ok( ( await scroll( 200 ) ).height > shorter.line + 1 );
	assert.ok( Math.abs( ( await scroll( 400 ) ).height - shorter.line ) < 0.1 );
	let previous = await scroll( 0 );
	for ( const offset of [ 1, 2, 4, 8, 12, 16, 20, 24, 20, 16, 12, 8, 4, 2, 1, 0 ] ) {
		const state = await scroll( offset );
		assert.equal( state.scroll, offset );
		assert.ok( Math.abs( state.height - previous.height ) <= Math.abs( offset - previous.scroll ) * 0.05 + 0.02,
			'barely perceptible title changes near the top' );
		assert.ok( state.progress < 0.02 );
		previous = state;
	}
	await open( longTitle, 5000 );
	const taller = await metrics();
	assert.ok( taller.expanded > shorter.expanded );
	assert.ok( ( await scroll( 400 ) ).height > taller.line + 1 );
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
	let previous = initial;
	for ( const offset of [ 1, 8, 16, 24, 32 ] ) {
		const state = await scroll( offset );
		assert.equal( state.scroll, offset );
		assert.ok( previous.height - state.height <= ( offset - previous.scroll ) * 0.2 + 0.1 );
		previous = state;
	}
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
			const peek = document.querySelector( '.wk-reading-peek' );
			return {
				prevented: event.defaultPrevented,
				offsetX: transform.m41, offsetY: transform.m42,
				peek: !!peek && getComputedStyle( peek ).display !== 'none',
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

test( 'tiny native scroll during header collapse locks out sideways previews before and after scroll events', async ( t ) => {
	const { touch, scroll, settle, metrics } = await gestureFixture( t );
	for ( const waitForScroll of [ false, true ] ) {
		for ( const dy of [ -6, 6 ] ) {
			for ( const x of [ 75, 315 ] ) {
				await scroll( 120 );
				const before = await metrics();
				await touch( 'touchstart' );
				await touch( 'touchmove', 195, 300 + dy, 120 - dy );
				if ( waitForScroll ) {
					await settle();
					assert.notEqual( ( await metrics() ).height, before.height, 'the title is resizing during this gesture' );
				}
				const lateral = await touch( 'touchmove', x, 300 + dy );
				assert.equal( lateral.offsetX, 0, 'native scrolling must take priority even below the 12px gesture threshold' );
				assert.equal( lateral.peek, false, 'no sibling preview during header resizing' );
				assert.equal( ( await touch( 'touchend' ) ).page, 'Argument 1' );
			}
		}
	}
} );

test( 'a delayed native scroll cancels an already prepared sideways preview until release', async ( t ) => {
	const { touch, scroll, settle } = await gestureFixture( t );
	await scroll( 120 );
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 175, 300 ) ).peek, true );
	await scroll( 126 );
	const state = await touch( 'touchmove', 75, 306 );
	assert.equal( state.offsetX, 0 );
	assert.equal( state.peek, false );
	assert.equal( ( await touch( 'touchend' ) ).page, 'Argument 1' );
	await settle();
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 75, 300 ) ).peek, true );
	assert.equal( ( await touch( 'touchend' ) ).page, 'Next' );
} );

test( 'browser-driven diagonal touch scrolling resizes the title without revealing a sibling', async ( t ) => {
	const { page, scroll, settle, metrics } = await gestureFixture( t );
	await scroll( 180 );
	const before = await metrics();
	await page.evaluate( () => {
		window.sidewaysPreviews = 0;
		document.getElementById( 'wk-reading-content' ).addEventListener( 'touchmove', () => {
			const peek = document.querySelector( '.wk-reading-peek' );
			if ( peek && getComputedStyle( peek ).display !== 'none' ) window.sidewaysPreviews++;
		} );
	} );
	const session = await page.context().newCDPSession( page );
	await session.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: 195, y: 500 } ] } );
	for ( const [ x, y ] of [ [ 215, 480 ], [ 235, 460 ], [ 75, 460 ] ] ) {
		await session.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x, y } ] } );
		await settle();
	}
	const after = await metrics();
	assert.ok( after.scroll > before.scroll, 'Chromium itself scrolls the content with the finger held down' );
	assert.ok( after.height < before.height, 'the multiline title is collapsing during the gesture' );
	await session.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
	assert.deepEqual( await page.evaluate( () => ( {
		previews: window.sidewaysPreviews,
		page: Wikidebates.WK_READING.state._wkCurrentPage
	} ) ), { previews: 0, page: 'Argument 1' } );
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
	const { page, touch, scroll } = await gestureFixture( t );
	await scroll( 0 );
	await touch( 'touchstart' );
	// Reproduce Safari's out-of-range scrollTop while bouncing at an edge.
	await page.evaluate( () => {
		Object.defineProperty( document.getElementById( 'wk-reading-content' ), 'scrollTop', {
			configurable: true, get: () => -3
		} );
	} );
	await touch( 'touchmove', 195, 306 );
	assert.equal( ( await touch( 'touchmove', 195, 340 ) ).offsetY, 40 );
	await touch( 'touchcancel' );
	await page.evaluate( () => { delete document.getElementById( 'wk-reading-content' ).scrollTop; } );
	await scroll( 10000 );
	await touch( 'touchstart' );
	await page.evaluate( () => {
		const content = document.getElementById( 'wk-reading-content' );
		const bottom = content.scrollHeight - content.clientHeight;
		Object.defineProperty( content, 'scrollTop', { configurable: true, get: () => bottom + 3 } );
	} );
	await touch( 'touchmove', 195, 294 );
	assert.equal( ( await touch( 'touchmove', 195, 220 ) ).sheet, true );
	assert.equal( ( await touch( 'touchend' ) ).sheet, true );
} );
