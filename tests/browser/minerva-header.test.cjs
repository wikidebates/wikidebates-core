const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright-core' );
const { resourceModuleFiles } = require( '../resourceModuleFiles.cjs' );

const root = path.resolve( __dirname, '../..' );
const longTitle = 'Un argument avec un titre particulièrement long qui doit rester lisible sur plusieurs lignes pendant la lecture et défiler naturellement avec son contenu sur mobile';

async function fixture( t, skin = 'minerva' ) {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
		headless: true, args: [ '--no-sandbox' ]
	} );
	const page = await browser.newPage( { viewport: { width: 390, height: 700 }, isMobile: true, hasTouch: true } );
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	t.after( async () => { await browser.close(); assert.deepEqual( errors, [] ); } );
	await page.setContent( '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="skin-' + skin + ' action-view"><div id="bodyContent"></div></body></html>' );
	await page.addStyleTag( { content: 'html,body { margin:0; font:16px Arial,sans-serif; } :root { --wd-arg-icon-size:20px; --background-color-base:#fff; --background-color-neutral-subtle:#fff; }' } );
	for ( const file of [ 'base.css', skin === 'minerva' ? 'minerva.css' : 'vector-2022.css' ] ) {
		await page.addStyleTag( { path: path.join( root, 'modules/reading', file ) } );
	}
	await page.addScriptTag( { path: require.resolve( 'jquery' ) } );
	await page.evaluate( ( skin ) => {
		const hooks = new Map();
		window.mw = {
			config: { get: ( key ) => ( { skin, wgUserLanguage: 'fr', wgNamespaceNumber: 0 } )[ key ] },
			util: { getUrl: ( title ) => '/wiki/' + encodeURIComponent( title ) },
			hook: ( key ) => {
				if ( !hooks.has( key ) ) {
					const callbacks = [];
					hooks.set( key, { add: ( fn ) => callbacks.push( fn ), fire: ( ...args ) => callbacks.forEach( ( fn ) => fn( ...args ) ) } );
				}
				return hooks.get( key );
			},
			Api: function () {
				this.get = () => {
					const reply = jQuery.Deferred();
					if ( window.deferRead ) window.pendingRead = reply;
					else reply.resolve( { parse: { text: window.argumentHTML } } );
					return reply.promise();
				};
			}
		};
	}, skin );
	for ( const file of [ 'modules/common/core.js', 'modules/common/parse.js', 'modules/reading/core.js',
		...resourceModuleFiles( 'ext.wikidebates.reading.ui.' + ( skin === 'minerva' ? 'minerva' : 'vector' ) ) ] ) {
		await page.addScriptTag( { path: path.join( root, file ) } );
	}
	let id = 0;
	async function beginOpen( title = longTitle, height = 1800 ) {
		await page.evaluate( ( { title, height, id } ) => {
			window.argumentHTML = '<h2 id="Summary">Résumé</h2><div id="body-probe" style="height:' + height + 'px"><span id="body-line">Contenu de l’argument</span></div><h2 id="Parent_debates">Débats parents</h2>';
			Wikidebates.wkOpenReadingModeFromPageTitle( 'Argument ' + id, [ { page: 'Argument ' + id, title, type: 'pour' } ], { history: false } );
		}, { title, height, id: ++id } );
	}
	async function open( title = longTitle, height = 1800 ) {
		await beginOpen( title, height );
		await page.waitForSelector( skin === 'minerva' ? '.wk-reading-full-title' : '.wk-reading-h1' );
		await settle();
	}
	async function settle() {
		await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
	}
	async function metrics() {
		return page.evaluate( () => {
			const header = document.querySelector( '.wk-reading-h1' );
			const title = document.querySelector( '.wk-reading-full-title' );
			const compact = header.querySelector( '.wk-reading-h1__title' );
			const content = document.getElementById( 'wk-reading-content' );
			return {
				headerHeight: header.getBoundingClientRect().height,
				titleHeight: title.getBoundingClientRect().height,
				contentTop: content.getBoundingClientRect().top, contentHeight: content.clientHeight,
				exit: title.getBoundingClientRect().bottom - content.getBoundingClientRect().top + content.scrollTop,
				scroll: content.scrollTop, range: content.scrollHeight - content.clientHeight,
				bodyTop: document.getElementById( 'body-line' ).getBoundingClientRect().top,
				opacity: parseFloat( getComputedStyle( compact ).opacity )
			};
		} );
	}
	async function scroll( offset ) {
		await page.evaluate( ( offset ) => { document.getElementById( 'wk-reading-content' ).scrollTop = offset; }, offset );
		await settle();
		return metrics();
	}
	return { page, beginOpen, open, settle, metrics, scroll };
}

function assertStableFlow( before, after ) {
	assert.equal( after.headerHeight, before.headerHeight, 'the toolbar never changes height' );
	assert.equal( after.titleHeight, before.titleHeight, 'the complete title never changes height while scrolling' );
	assert.equal( after.contentTop, before.contentTop, 'the reading viewport never moves' );
	assert.equal( after.range, before.range, 'fading never changes the scroll range' );
	assert.ok( Math.abs( after.bodyTop - before.bodyTop + after.scroll - before.scroll ) < 0.25,
		'the reading text moves only by the distance actually scrolled' );
}

test( 'the toolbar overlays the content from opening without reserving an empty strip', async ( t ) => {
	const { page, beginOpen, settle, metrics } = await fixture( t );
	await page.evaluate( () => { window.deferRead = true; } );
	await beginOpen();
	await page.waitForSelector( '.wk-reading-h1' );
	const loading = await page.evaluate( () => ( {
		height: document.querySelector( '.wk-reading-h1' ).getBoundingClientRect().height,
		top: document.getElementById( 'wk-reading-content' ).getBoundingClientRect().top,
		close: !!document.getElementById( 'wk-reading-close' )
	} ) );
	assert.ok( loading.height > 0 && loading.close );
	assert.equal( loading.top, 0, 'the viewport starts at the top, underneath the overlay' );
	await page.evaluate( () => pendingRead.resolve( { parse: { text: argumentHTML } } ) );
	await page.waitForSelector( '.wk-reading-full-title' );
	await settle();
	assert.equal( ( await metrics() ).headerHeight, loading.height );
	assert.equal( ( await metrics() ).contentTop, loading.top );
} );

test( 'the toolbar background and border fade with the compact title; content remains touchable underneath', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	await open();
	const initial = await metrics();
	for ( const offset of [ 0, initial.exit + 16, initial.exit + 32, 0 ] ) {
		const state = await scroll( offset );
		assertStableFlow( initial, state );
		const chrome = await page.evaluate( () => {
			const bar = document.querySelector( '.wk-reading-h1' );
			const content = document.getElementById( 'wk-reading-content' );
			const style = getComputedStyle( bar, '::before' );
			return {
				opacity: parseFloat( style.opacity ),
				border: getComputedStyle( bar ).borderBottomWidth,
				contentReceivesTouch: content.contains( document.elementFromPoint( 195, 28 ) )
			};
		} );
		assert.equal( chrome.opacity, state.opacity );
		assert.equal( chrome.border, '0px', 'no permanent separator above the full heading' );
		assert.equal( chrome.contentReceivesTouch, true, 'the decorative overlay does not intercept scrolling' );
	}
} );

test( 'buttons stay centered and the larger reading title aligns with the compact title', async ( t ) => {
	const { page, open, settle } = await fixture( t );
	await open( 'Titre court' );
	// Reproduce the reported 24.3px heading with an 18px parent, even when
	// a wiki-wide heading rule is loaded after the extension stylesheet.
	await page.addStyleTag( { content: '#wk-reading-mode h1 { font-size: 135% !important; }' } );
	for ( const width of [ 320, 390 ] ) {
		await page.setViewportSize( { width, height: 700 } );
		for ( const fontSize of [ 18, 16, 20, 24 ] ) {
			await page.evaluate( ( size ) => { document.body.style.fontSize = size + 'px'; }, fontSize );
			await settle();
			const boxes = await page.evaluate( () => {
				const rect = ( selector ) => document.querySelector( selector ).getBoundingClientRect().toJSON();
				const text = ( selector ) => {
					const element = document.querySelector( selector );
					const style = getComputedStyle( element );
					const range = document.createRange();
					range.setStart( element.firstChild, 0 );
					range.setEnd( element.firstChild, 1 );
					return {
						font: [ style.fontFamily, style.fontWeight, style.letterSpacing ],
						fontSize: parseFloat( style.fontSize ),
						lineHeight: parseFloat( style.lineHeight ),
						lineCenter: element.getBoundingClientRect().top + parseFloat( style.paddingTop ) + parseFloat( style.lineHeight ) / 2,
						firstLetter: range.getBoundingClientRect().toJSON()
					};
				};
				return {
					bar: rect( '.wk-reading-h1' ), close: rect( '#wk-reading-close' ),
					menu: rect( '.wk-h1-more > .wk-icon-more' ),
					title: rect( '.wk-reading-full-title' ),
					fullText: text( '.wk-reading-full-title' ), compactText: text( '.wk-reading-h1__title' ),
					closeIcon: rect( '#wk-reading-close svg' ), menuIcon: rect( '.wk-icon-more svg' )
				};
			} );
			const center = boxes.bar.top + boxes.bar.height / 2;
			for ( const button of [ boxes.close, boxes.menu ] ) {
				assert.equal( button.width, 44 );
				assert.equal( button.height, 44 );
				assert.equal( button.top + button.height / 2, center );
			}
			for ( const icon of [ boxes.closeIcon, boxes.menuIcon ] ) {
				assert.ok( Math.abs( icon.top + icon.height / 2 - center ) < 0.25 );
			}
			assert.equal( boxes.close.left, 4 );
			assert.equal( boxes.menu.right, width - 4 );
			assert.ok( boxes.title.left >= boxes.close.right && boxes.title.right <= boxes.menu.left );
			assert.equal( boxes.title.top, boxes.bar.top, 'the full heading starts alongside the buttons' );
			assert.deepEqual( boxes.fullText.font, boxes.compactText.font, 'both titles retain the same font family, weight and spacing' );
			assert.equal( boxes.compactText.fontSize, fontSize, 'the compact title keeps the normal text size' );
			assert.ok( Math.abs( boxes.fullText.fontSize - fontSize * 1.15 ) < 0.001, 'the reading title is moderately larger, including 20.7px for 18px text' );
			assert.ok( Math.abs( boxes.fullText.lineHeight - boxes.fullText.fontSize * 1.25 ) < 0.001 );
			assert.equal( boxes.fullText.firstLetter.left, boxes.compactText.firstLetter.left, 'both titles start at the same horizontal position' );
			assert.ok( Math.abs( boxes.fullText.lineCenter - boxes.compactText.lineCenter ) < 0.02, 'the first line stays centered with the toolbar despite its larger font' );
		}
	}
} );

test( 'the full heading scrolls naturally; only the short title fades after it disappears', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	await open( longTitle, 5000 );
	const initial = await metrics();
	assert.equal( initial.opacity, 0 );
	assert.ok( initial.titleHeight > 50 );
	assert.equal( await page.evaluate( () => document.getElementById( 'wk-reading-content' ).firstElementChild.matches( 'h1.wk-reading-full-title' ) ), true );
	const offsets = [ 0, 1, 2, 8, 40, initial.exit - 1, initial.exit, initial.exit + 1,
		initial.exit + 8, initial.exit + 16, initial.exit + 24, initial.exit + 32, initial.exit + 80 ];
	let previous = initial;
	for ( const offset of offsets.concat( offsets.slice().reverse() ) ) {
		const state = await scroll( offset );
		assertStableFlow( previous, state );
		assert.ok( Math.abs( state.opacity - Math.max( 0, Math.min( 1, ( state.scroll - initial.exit ) / 32 ) ) ) < 0.001 );
		previous = state;
	}
	await scroll( initial.exit + 16 );
	const writes = await page.evaluate( async () => {
		let count = 0;
		const observer = new MutationObserver( ( records ) => { count += records.length; } );
		observer.observe( document.querySelector( '.wk-reading-h1' ), { attributes: true } );
		for ( let i = 0; i < 8; i++ ) await new Promise( requestAnimationFrame );
		observer.disconnect();
		return count;
	} );
	assert.equal( writes, 0, 'no animation or layout writes continue after scrolling stops' );
} );

test( 'slow movement and repeated direction reversals add no displacement to the reading text', async ( t ) => {
	const { open, metrics, scroll } = await fixture( t );
	await open();
	const initial = await metrics();
	for ( const start of [ 0, initial.exit - 4, initial.exit + 12, initial.exit + 28 ] ) {
		let previous = await scroll( start );
		for ( const step of [ 1, 2, 3, 4, 3, 2, 1, 0, 1, 0 ] ) {
			const state = await scroll( start + step );
			assertStableFlow( previous, state );
			previous = state;
		}
	}
} );

test( 'short pages remain stable at the bottom, including a partially faded compact title', async ( t ) => {
	const { page, open, metrics, scroll, settle } = await fixture( t );
	for ( const fadeAtBottom of [ false, true ] ) {
		await open();
		const start = await metrics();
		await page.evaluate( ( range ) => {
			const content = document.getElementById( 'wk-reading-content' );
			const probe = document.getElementById( 'body-probe' );
			const gap = probe.getBoundingClientRect().top - content.getBoundingClientRect().top;
			probe.style.height = ( content.clientHeight - gap - parseFloat( getComputedStyle( content ).paddingBottom ) + range ) + 'px';
		}, fadeAtBottom ? start.exit + 16 : 60 );
		await settle();
		const initial = await metrics();
		let previous = initial;
		for ( const offset of [ 1, 8, 16, 24, 32, 10000 ] ) {
			const state = await scroll( offset );
			assertStableFlow( previous, state );
			previous = state;
		}
		if ( fadeAtBottom ) assert.ok( previous.opacity > 0 && previous.opacity < 1 );
		else assert.equal( previous.opacity, 0 );
		for ( let i = 0; i < 8; i++ ) {
			await settle();
			assert.deepEqual( await metrics(), previous );
		}
		assert.equal( ( await scroll( 0 ) ).opacity, 0 );
	}
} );

test( 'short and non-scrolling arguments leave the toolbar in place and the full title readable', async ( t ) => {
	const { open, metrics, scroll } = await fixture( t );
	await open( longTitle, 10 );
	const initial = await metrics();
	assert.equal( initial.range, 0 );
	assert.deepEqual( await scroll( 80 ), initial );
	await open( 'Titre court' );
	const single = await metrics();
	assertStableFlow( single, await scroll( 80 ) );
	assert.equal( ( await scroll( single.exit + 32 ) ).opacity, 1 );
} );

test( 'very long titles wrap without clipping and can be read completely by native scrolling', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	const title = longTitle.repeat( 8 ) + ' MotSansEspaces'.repeat( 20 ).replaceAll( ' ', '' );
	await open( title, 1800 );
	const initial = await metrics();
	assert.ok( initial.titleHeight > initial.contentHeight );
	assert.equal( await page.textContent( 'h1.wk-reading-full-title' ), title );
	assert.equal( await page.evaluate( () => {
		const h = document.querySelector( '.wk-reading-full-title' );
		return h.scrollWidth <= h.clientWidth && h.scrollHeight <= h.clientHeight;
	} ), true );
	// Native scrolling may round a fractional heading height to a whole pixel.
	const state = await scroll( Math.floor( initial.exit ) );
	assertStableFlow( initial, state );
	assert.equal( state.opacity, 0 );
	assert.equal( ( await scroll( Math.ceil( initial.exit ) + 32 ) ).opacity, 1 );
	assert.equal( await page.getAttribute( '.wk-reading-h1__title', 'aria-hidden' ), 'true' );
} );

test( 'resize, argument replacement and reopening preserve the fixed toolbar and release observers', async ( t ) => {
	const { page, open, metrics, scroll, settle } = await fixture( t );
	await open();
	const initial = await metrics();
	await scroll( initial.exit + 32 );
	await page.evaluate( () => { window.originalToolbar = document.querySelector( '.wk-reading-h1' ); } );
	for ( const viewport of [ { width: 600, height: 390 }, { width: 320, height: 700 } ] ) {
		await page.setViewportSize( viewport );
		await settle();
		assert.equal( ( await metrics() ).headerHeight, initial.headerHeight );
		assert.equal( ( await scroll( 0 ) ).opacity, 0 );
	}
	await open( 'Un autre titre court' );
	assert.equal( await page.evaluate( () => window.originalToolbar === document.querySelector( '.wk-reading-h1' ) ), true );
	assert.equal( await page.textContent( '.wk-reading-full-title' ), 'Un autre titre court' );
	assert.equal( ( await metrics() ).opacity, 0 );
	assert.equal( ( await metrics() ).scroll, 0 );
	assert.equal( await page.evaluate( () => {
		const content = document.getElementById( 'wk-reading-content' );
		Wikidebates.wkCloseReadingMode();
		return content._wkTitleFade === undefined;
	} ), true );
	await open();
	assert.equal( ( await metrics() ).opacity, 0 );
} );

test( 'toolbar actions, copy, contents sheet and close still work after the title fades', async ( t ) => {
	const { page, open, metrics, scroll } = await fixture( t );
	await page.evaluate( () => {
		Wikidebates.WK_READING.state.h1Links = {
			editHref: 'https://example.test/edit-title', detailHref: 'https://example.test/argument'
		};
		Object.defineProperty( navigator, 'clipboard', { configurable: true,
			value: { writeText: ( text ) => { window.copied = text; return Promise.resolve(); } } } );
	} );
	await open();
	await scroll( ( await metrics() ).exit + 32 );
	const before = await metrics();
	await page.click( '.wk-h1-more > .wk-icon-more' );
	assert.equal( await page.getAttribute( '.wk-h1-more > .wk-icon-more', 'aria-expanded' ), 'true' );
	assert.equal( await page.getAttribute( '.wk-h1-menu__detail', 'href' ), 'https://example.test/argument' );
	assert.equal( await page.getAttribute( '.wk-h1-menu__edit', 'href' ), 'https://example.test/edit-title' );
	await page.click( '.wk-h1-menu__copy' );
	assert.equal( await page.evaluate( () => window.copied ), 'https://example.test/argument' );
	assert.equal( await page.getAttribute( '.wk-h1-more > .wk-icon-more', 'aria-expanded' ), 'false' );
	assertStableFlow( before, await metrics() );
	await page.click( '#wk-reading-navbtn' );
	assert.equal( await page.evaluate( () => document.getElementById( 'wk-reading-mode' ).classList.contains( 'is-sheet-open' ) ), true );
	await page.click( '#wk-reading-overlay', { position: { x: 10, y: 10 } } );
	assert.equal( await page.evaluate( () => document.getElementById( 'wk-reading-mode' ).classList.contains( 'is-sheet-open' ) ), false );
	assertStableFlow( before, await metrics() );
	await page.click( '#wk-reading-close' );
	assert.equal( await page.locator( '#wk-reading-mode' ).count(), 0 );
} );

test( 'Vector keeps its existing header outside the argument content', async ( t ) => {
	const { page, open } = await fixture( t, 'vector-2022' );
	await open();
	assert.equal( await page.textContent( '.wk-reading-h1__title' ), longTitle );
	assert.equal( await page.locator( '.wk-reading-full-title' ).count(), 0 );
	assert.equal( await page.evaluate( () => document.getElementById( 'wk-reading-content' ).contains( document.querySelector( '.wk-reading-h1' ) ) ), false );
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

test( 'tiny native scroll during title fading locks out sideways previews before and after scroll events', async ( t ) => {
	const { touch, scroll, settle, metrics } = await gestureFixture( t );
	for ( const waitForScroll of [ false, true ] ) {
		for ( const dy of [ -6, 6 ] ) {
			for ( const x of [ 75, 315 ] ) {
				const offset = Math.ceil( ( await metrics() ).exit + 16 );
				await scroll( offset );
				const before = await metrics();
				await touch( 'touchstart' );
				await touch( 'touchmove', 195, 300 + dy, offset - dy );
				if ( waitForScroll ) {
					await settle();
					assert.notEqual( ( await metrics() ).opacity, before.opacity, 'the compact title is fading during this gesture' );
					assertStableFlow( before, await metrics() );
				}
				const lateral = await touch( 'touchmove', x, 300 + dy );
				assert.equal( lateral.offsetX, 0, 'native scrolling must take priority even below the 12px gesture threshold' );
				assert.equal( lateral.peek, false, 'no sibling preview during title fading' );
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

test( 'a fresh sibling preview includes the complete incoming heading and matches the committed layout', async ( t ) => {
	const { page, touch, scroll, settle, metrics } = await gestureFixture( t );
	await scroll( 0 );
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 75, 300 ) ).peek, true );
	const preview = await page.evaluate( () => {
		const layer = document.querySelector( '.wk-reading-peek' );
		return {
			title: layer.querySelector( '.wk-reading-full-title' ).textContent,
			line: layer.querySelector( '#body-line' ).getBoundingClientRect().top,
			decorative: layer.getAttribute( 'aria-hidden' )
		};
	} );
	assert.equal( preview.title, 'Next' );
	assert.equal( preview.decorative, 'true' );
	assert.equal( ( await touch( 'touchend' ) ).page, 'Next' );
	await settle();
	assert.equal( await page.textContent( '#wk-reading-content .wk-reading-full-title' ), 'Next' );
	assert.ok( Math.abs( ( await metrics() ).bodyTop - preview.line ) < 0.25,
		'committing a preview does not introduce a different heading layout' );
} );

test( 'browser-driven touch scrolling and reversal fade the title without displacing text or previewing siblings', async ( t ) => {
	const { page, scroll, settle, metrics } = await gestureFixture( t );
	await scroll( ( await metrics() ).exit - 12 );
	const before = await metrics();
	await page.evaluate( () => {
		window.touchSamples = [];
		window.touchPreviews = 0;
		window.trustedTouchMoves = 0;
		window.touchSampling = true;
		document.getElementById( 'wk-reading-content' ).addEventListener( 'touchmove', ( event ) => {
			if ( event.isTrusted ) window.trustedTouchMoves++;
			const peek = document.querySelector( '.wk-reading-peek' );
			if ( peek && getComputedStyle( peek ).display !== 'none' ) window.touchPreviews++;
		} );
		function sample() {
			const content = document.getElementById( 'wk-reading-content' );
			const peek = document.querySelector( '.wk-reading-peek' );
			if ( peek && getComputedStyle( peek ).display !== 'none' ) window.touchPreviews++;
			window.touchSamples.push( {
				scroll: content.scrollTop,
				bodyTop: document.getElementById( 'body-line' ).getBoundingClientRect().top,
				headerHeight: document.querySelector( '.wk-reading-h1' ).getBoundingClientRect().height,
				opacity: parseFloat( getComputedStyle( document.querySelector( '.wk-reading-h1__title' ) ).opacity )
			} );
			if ( window.touchSampling ) requestAnimationFrame( sample );
		}
		sample();
	} );
	const session = await page.context().newCDPSession( page );
	await session.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: 195, y: 500 } ] } );
	// Native touch input: Chromium, not the test, changes scrollTop. Cross the
	// fade boundary slowly, reverse, then move sharply sideways without release.
	for ( const [ x, y ] of [ [ 195, 480 ], [ 195, 470 ], [ 195, 460 ], [ 195, 450 ],
		[ 195, 440 ], [ 195, 430 ], [ 195, 440 ], [ 195, 450 ], [ 195, 460 ], [ 75, 460 ] ] ) {
		await session.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x, y } ] } );
		await settle();
	}
	const after = await metrics();
	assert.ok( after.scroll > before.scroll, 'Chromium scrolls natively with the finger held down' );
	assertStableFlow( before, after );
	await session.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
	const result = await page.evaluate( () => {
		window.touchSampling = false;
		return { samples: window.touchSamples, previews: window.touchPreviews, trustedMoves: window.trustedTouchMoves,
			page: Wikidebates.WK_READING.state._wkCurrentPage };
	} );
	assert.equal( result.previews, 0 );
	assert.equal( result.page, 'Argument 1' );
	assert.ok( result.trustedMoves > 0, 'Chromium dispatches trusted touch input, not synthetic DOM events' );
	assert.ok( result.samples.some( ( sample ) => sample.opacity > 0 && sample.opacity < 1 ), 'the actual touch crosses the fade' );
	assert.ok( result.samples.some( ( sample, i, all ) => i && sample.scroll < all[ i - 1 ].scroll ), 'native scroll reverses while the finger remains down' );
	for ( const sample of result.samples ) {
		assert.equal( sample.headerHeight, before.headerHeight );
		assert.ok( Math.abs( sample.bodyTop - before.bodyTop + sample.scroll - before.scroll ) < 0.25,
			'every rendered frame moves the text only by the native scroll distance' );
	}
	const maxExtraMovement = Math.max( ...result.samples.map( ( sample ) => Math.abs( sample.bodyTop - before.bodyTop + sample.scroll - before.scroll ) ) );
	t.diagnostic( 'Trusted touch moves: ' + result.trustedMoves + '; maximum extra line movement: ' + maxExtraMovement.toFixed( 4 ) + ' CSS px' );
} );

test( 'native scrolling back to the top never unlocks sibling previews before finger release', async ( t ) => {
	const { page, scroll, settle, metrics } = await gestureFixture( t );
	const session = await page.context().newCDPSession( page );
	for ( const initialOffset of [ 0, 40 ] ) {
		for ( const x of [ 15, 375 ] ) {
			await scroll( initialOffset );
			const before = await metrics();
			await page.evaluate( () => {
				window.edgeSamples = [];
				window.edgeSampling = true;
				function sample() {
					const content = document.getElementById( 'wk-reading-content' );
					const peek = document.querySelector( '.wk-reading-peek' );
					window.edgeSamples.push( {
						scroll: content.scrollTop,
						bodyTop: document.getElementById( 'body-line' ).getBoundingClientRect().top,
						headerHeight: document.querySelector( '.wk-reading-h1' ).getBoundingClientRect().height,
						preview: !!peek && getComputedStyle( peek ).display !== 'none',
						offsetX: new DOMMatrix( content.style.transform ).m41,
						offsetY: new DOMMatrix( content.style.transform ).m42
					} );
					if ( window.edgeSampling ) requestAnimationFrame( sample );
				}
				sample();
			} );
			await session.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: 195, y: 400 } ] } );
			// Scroll away from the top, reverse to the top and keep holding there.
			// The final lateral displacement exceeds the horizontal threshold even
			// relative to the original touch point, so only the scroll lock blocks it.
			for ( const y of [ 380, 360, 380, 400, 420, 440, 460 ] ) {
				await session.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x: 195, y } ] } );
				await settle();
			}
			assert.equal( ( await metrics() ).scroll, 0, 'Chromium has returned to the top without releasing the finger' );
			await session.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x, y: 460 } ] } );
			await settle();
			await session.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );
			const result = await page.evaluate( () => {
				window.edgeSampling = false;
				return { samples: window.edgeSamples, page: Wikidebates.WK_READING.state._wkCurrentPage };
			} );
			assert.ok( result.samples.some( ( sample ) => sample.scroll > initialOffset ), 'native scrolling actually began' );
			assert.equal( result.page, 'Argument 1' );
			for ( const sample of result.samples ) {
				assert.equal( sample.preview, false, 'no lateral preview, even after returning to the top' );
				assert.equal( sample.offsetX, 0 );
				assert.equal( sample.offsetY, 0, 'returning to the top must not trigger a parent pull' );
				assert.equal( sample.headerHeight, before.headerHeight );
				assert.ok( Math.abs( sample.bodyTop - before.bodyTop + sample.scroll - before.scroll ) < 0.25 );
			}
			await settle();
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

test( 'a fresh pull from the top still opens the parent argument', async ( t ) => {
	const { page, open, touch, scroll, settle } = await gestureFixture( t );
	await open();
	await page.evaluate( () => Wikidebates.wkOpenReadingModeFromPageTitle( 'Child', [
		{ page: 'Parent', title: 'Titre parent', type: 'pour' },
		{ page: 'Child', title: 'Titre enfant', type: 'pour' }
	], { history: false } ) );
	await settle();
	await scroll( 0 );
	await touch( 'touchstart' );
	await touch( 'touchmove', 195, 390 );
	assert.equal( ( await touch( 'touchend' ) ).page, 'Parent' );
	await settle();
	assert.equal( await page.textContent( '#wk-reading-content .wk-reading-full-title' ), 'Titre parent' );
} );
