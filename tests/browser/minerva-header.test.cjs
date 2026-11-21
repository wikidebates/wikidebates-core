const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const path = require( 'node:path' );
const http = require( 'node:http' );
const { chromium } = require( 'playwright-core' );
const { resourceModuleFiles } = require( '../resourceModuleFiles.cjs' );

const root = path.resolve( __dirname, '../..' );
const longTitle = 'Un argument avec un titre particulièrement long qui doit rester lisible sur plusieurs lignes pendant la lecture et défiler naturellement avec son contenu sur mobile';

async function fixture( t, skin = 'minerva', prepared = false, localOrigin = false ) {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
		headless: true, args: [ '--no-sandbox' ]
	} );
	const page = await browser.newPage( { viewport: { width: 390, height: 700 }, isMobile: true, hasTouch: true } );
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	t.after( async () => { await browser.close(); assert.deepEqual( errors, [] ); } );
	if ( localOrigin ) {
		const server = http.createServer( ( request, response ) => {
			response.writeHead( 200, { 'Content-Type': 'text/html' } );
			response.end( '<!doctype html><body></body>' );
		} );
		await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		t.after( () => new Promise( ( resolve ) => { server.closeAllConnections(); server.close( resolve ); } ) );
		await page.goto( 'http://127.0.0.1:' + server.address().port + '/wiki/Debate' );
	}
	await page.setContent( '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="skin-' + skin + ' action-view"><div id="bodyContent"></div></body></html>' );
	await page.addStyleTag( { content: 'html,body { margin:0; font:16px Arial,sans-serif; } :root { --wd-arg-icon-size:20px; --background-color-base:#fff; --background-color-neutral-subtle:#fff; }' } );
	for ( const file of [ 'base.css', skin === 'minerva' ? 'minerva.css' : 'vector-2022.css' ] ) {
		await page.addStyleTag( { path: path.join( root, 'modules/reading', file ) } );
	}
	await page.addScriptTag( { path: require.resolve( 'jquery' ) } );
	await page.evaluate( ( { skin, prepared } ) => {
		const hooks = new Map();
		window.mw = {
			config: { get: ( key ) => ( { skin, wgUserLanguage: 'fr', wgNamespaceNumber: 0, wgWikidebatesReadingFragments: prepared } )[ key ] },
			util: { getUrl: ( title ) => '/wiki/' + encodeURIComponent( title ) },
			hook: ( key ) => {
				if ( !hooks.has( key ) ) {
					const callbacks = [];
					hooks.set( key, { add: ( fn ) => callbacks.push( fn ), fire: ( ...args ) => callbacks.forEach( ( fn ) => fn( ...args ) ) } );
				}
				return hooks.get( key );
			},
			Api: function () {
				this.get = ( params ) => {
					window.lastReadingAction = params.action;
					const reply = jQuery.Deferred();
					if ( window.deferRead ) window.pendingRead = reply;
					else reply.resolve( prepared ? { wikidebatesreading: { html: Wikidebates.wkReadingExtractFragment( window.argumentHTML ) } } :
						{ parse: { text: window.argumentHTML } } );
					return reply.promise();
				};
			}
		};
	}, { skin, prepared } );
	for ( const file of [ 'modules/common/core.js', 'modules/common/parse.js',
		...resourceModuleFiles( 'ext.wikidebates.reading.core' ),
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

test( 'prepared server fragments preserve mobile content, title and scrolling', async ( t ) => {
	const { page, open, scroll, metrics } = await fixture( t, 'minerva', true );
	await open();
	assert.equal( await page.evaluate( () => window.lastReadingAction ), 'wikidebatesreading' );
	assert.equal( await page.textContent( '#body-line' ), 'Contenu de l’argument' );
	assert.equal( await page.textContent( '.wk-reading-full-title' ), longTitle );
	const before = await metrics();
	await scroll( 300 );
	const after = await metrics();
	assert.ok( after.scroll > before.scroll );
	assert.equal( after.headerHeight, before.headerHeight );
	assert.equal( await page.locator( '#wk-reading-content #Parent_debates' ).count(), 0 );
} );

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
		Object.defineProperty( navigator, 'clipboard', { configurable: true,
			value: { writeText: ( text ) => { window.copied = text; return Promise.resolve(); } } } );
	} );
	await open();
	// This toolbar-only fixture supplies artificial links after navigation. Real
	// navigation now derives them from the current chain occurrence.
	await page.evaluate( () => {
		Wikidebates.WK_READING.state.h1Links = {
			editHref: 'https://example.test/edit-title', detailHref: 'https://example.test/argument'
		};
		Wikidebates.minervaReading.header.updateActions( { panel: document.getElementById( 'wk-reading-panel' ) } );
	} );
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

for ( const skin of [ 'minerva', 'vector' ] ) {
	test( 'reading failure and retry remain usable in the real ' + skin + ' panel', async ( t ) => {
		const { page, open, beginOpen } = await fixture( t, skin, true );
		await open( 'Original argument', 900 );
		await page.evaluate( () => {
			window.deferRead = true;
			Wikidebates.wkMsgD = ( key, fallback ) => ( {
				'wk-reading-load-error': 'The argument could not be loaded.',
				'wk-reading-retry': 'Retry',
				'wk-view-detailed-page': 'Open full page'
			} )[ key ] || fallback || key;
		} );
		await beginOpen( 'Requested argument', 900 );
		await page.evaluate( () => { window.pendingRead.reject( 'http' ); } );
		await page.waitForSelector( '.wk-reading-error' );
		const retry = page.locator( '.wk-reading-retry' );
		assert.equal( await retry.isVisible(), true );
		assert.equal( await page.locator( '#body-line' ).count(), 0, 'old argument is not displayed beneath the new heading' );
		assert.equal( await page.textContent( skin === 'minerva' ? '.wk-reading-full-title' : '.wk-reading-h1__title' ), 'Requested argument' );
		await retry.click();
		await page.evaluate( () => window.pendingRead.resolve( { wikidebatesreading: {
			html: '<h2 id="Summary">Summary</h2><p id="recovered-reading">Recovered argument</p>'
		} } ) );
		await page.waitForSelector( '#recovered-reading' );
		assert.equal( await page.locator( '.wk-reading-error' ).count(), 0 );
		assert.equal( await page.getAttribute( '#wk-reading-content', 'aria-busy' ), null );
	} );
}

test( 'a real mobile opening promotes its queued neighbor without waiting for another prefetch', async ( t ) => {
	const f = await fixture( t );
	await f.open();
	await f.page.evaluate( () => {
		window.schedulerCalls = [];
		Wikidebates.wkGetApi().get = ( params ) => {
			const reply = jQuery.Deferred();
			window.schedulerCalls.push( { page: params.page, reply } );
			return reply.promise();
		};
		const navigation = Wikidebates.minervaReading.createNavigation( {
			root: document.getElementById( 'wk-reading-mode' ),
			siblings: document.createElement( 'div' )
		} );
		navigation.prefetchPage( 'Slow neighbor' );
		navigation.prefetchPage( 'Argument 2' );
		navigation.prefetchPage( 'Obsolete neighbor' );
	} );
	assert.deepEqual( await f.page.evaluate( () => schedulerCalls.map( ( call ) => call.page ) ), [ 'Slow neighbor' ] );
	await f.beginOpen( 'Promoted argument' );
	assert.deepEqual( await f.page.evaluate( () => schedulerCalls.map( ( call ) => call.page ) ), [ 'Slow neighbor', 'Argument 2' ] );
	await f.page.evaluate( () => schedulerCalls[ 1 ].reply.resolve( { parse: { text: argumentHTML } } ) );
	await f.page.waitForFunction( () => document.querySelector( '.wk-reading-full-title' ).textContent.includes( 'Promoted argument' ) );
	assert.equal( await f.page.locator( '#body-line' ).textContent(), 'Contenu de l\u2019argument' );
	await f.page.evaluate( () => schedulerCalls[ 0 ].reply.resolve( { parse: { text: '<p>Unused neighbor</p>' } } ) );
	await f.settle();
	assert.deepEqual( await f.page.evaluate( () => schedulerCalls.map( ( call ) => call.page ) ), [ 'Slow neighbor', 'Argument 2' ], 'obsolete queued work was never sent' );
} );

test( 'mobile swipe previews preserve the displayed title of a quoted backslash page name', async ( t ) => {
	const { page, touch, scroll, open, settle } = await gestureFixture( t );
	const target = 'C:\\temp "Quoted"';
	await page.evaluate( ( target ) => {
		Wikidebates.wkCloseReadingMode();
		Wikidebates.WK_READING.state.siblingsByDepth[ 1 ] = [
			{ page: 'Argument 2', title: 'Current label' },
			{ page: target, title: 'Neighbor displayed label' }
		];
	}, target );
	await open( 'Current label' );
	await page.waitForFunction( ( target ) => Wikidebates.wkPageParseCache.state(
		Wikidebates.wkPageParseCache.key( Wikidebates.wkReadingBuildParseRequestParams( target ) )
	) === 'response', target, { timeout: 5000 } );
	await scroll( 0 );
	await touch( 'touchstart' );
	assert.equal( ( await touch( 'touchmove', 75, 300 ) ).peek, true );
	assert.equal( await page.textContent( '.wk-reading-peek .wk-reading-full-title' ), 'Neighbor displayed label' );
	assert.equal( ( await touch( 'touchend' ) ).page, target );
	await settle();
	assert.equal( await page.textContent( '#wk-reading-content .wk-reading-full-title' ), 'Neighbor displayed label' );
} );

test( 'real Chromium and jQuery keep wikitext results separate when interface language changes', async ( t ) => {
	const { page } = await fixture( t );
	const result = await page.evaluate( async () => {
		let language = 'fr', calls = 0;
		const get = mw.config.get;
		mw.config.get = ( key ) => key === 'wgUserLanguage' ? language : get( key );
		Wikidebates.wkGetApi().post = ( params ) => {
			calls++;
			return jQuery.Deferred().resolve( { parse: { text: params.uselang } } ).promise();
		};
		const french = await Wikidebates.wkParseWikitext( 'Same text', 'hover' );
		language = 'en';
		const english = await Wikidebates.wkParseWikitext( 'Same text', 'hover' );
		language = 'fr';
		const cached = await Wikidebates.wkParseWikitext( 'Same text', 'hover' );
		return { french, english, cached, calls };
	} );
	assert.deepEqual( result, { french: 'fr', english: 'en', cached: 'fr', calls: 2 } );
} );

for ( const skin of [ 'minerva', 'vector' ] ) {
	test( skin + ': native keyboard controls open menus, copy manually and restore focus with Escape', async ( t ) => {
		const { page, open } = await fixture( t, skin );
		await page.evaluate( () => {
			const opener = document.createElement( 'button' );
			opener.id = 'keyboard-opener'; opener.textContent = 'Open argument';
			document.body.appendChild( opener ); opener.focus();
			window.copyNotices = [];
			mw.notify = ( message ) => copyNotices.push( message );
			Object.defineProperty( navigator, 'clipboard', { configurable: true, value: {
				writeText: () => Promise.reject( new Error( 'Denied by browser' ) )
			} } );
			document.execCommand = () => false;
		} );
		await open();
		await page.evaluate( () => { Wikidebates.WK_READING.state.h1Links.detailHref = 'https://wiki.test/wiki/Current'; } );
		assert.equal( await page.evaluate( () => document.activeElement.id ), 'wk-reading-content' );
		const more = page.locator( '.wk-h1-more > .wk-icon-more' );
		await more.focus();
		await page.keyboard.press( 'Enter' );
		assert.equal( await more.getAttribute( 'aria-expanded' ), 'true' );
		await page.keyboard.press( 'Tab' );
		assert.equal( await page.evaluate( () => document.activeElement.classList.contains( 'wk-h1-menu__detail' ) ), true );
		await page.locator( '.wk-h1-menu__copy' ).focus();
		await page.keyboard.press( 'Space' );
		await page.waitForSelector( '.wk-copy-manual input' );
		assert.deepEqual( await page.evaluate( () => copyNotices ), [] );
		assert.equal( await page.evaluate( () => document.activeElement.matches( '.wk-copy-manual input' ) ), true );
		const selection = await page.locator( '.wk-copy-manual input' ).evaluate( ( el ) => [ el.selectionStart, el.selectionEnd, el.value.length ] );
		assert.deepEqual( selection, [ 0, selection[ 2 ], selection[ 2 ] ] );
		await page.keyboard.press( 'Escape' );
		assert.equal( await more.getAttribute( 'aria-expanded' ), 'false' );
		assert.equal( await more.evaluate( ( el ) => document.activeElement === el ), true );
		assert.equal( await page.locator( '#wk-reading-mode' ).count(), 1 );
		await page.keyboard.press( 'Space' );
		assert.equal( await more.getAttribute( 'aria-expanded' ), 'true', 'Space activates the native disclosure button' );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.press( 'Escape' );
		assert.equal( await page.locator( '#wk-reading-mode' ).count(), 0 );
		assert.equal( await page.evaluate( () => document.activeElement.id ), 'keyboard-opener' );
	} );

	test( skin + ': clipboard success notification waits for the asynchronous browser result', async ( t ) => {
		const { page, open } = await fixture( t, skin );
		await page.evaluate( () => {
			window.copyNotices = [];
			mw.notify = ( message ) => copyNotices.push( message );
			Object.defineProperty( navigator, 'clipboard', { configurable: true, value: {
				writeText: () => new Promise( ( resolve ) => { window.finishCopy = resolve; } )
			} } );
		} );
		await open();
		await page.evaluate( () => { Wikidebates.WK_READING.state.h1Links.detailHref = 'https://wiki.test/wiki/Current'; } );
		await page.locator( '.wk-icon-more' ).click();
		await page.locator( '.wk-h1-menu__copy' ).click();
		assert.deepEqual( await page.evaluate( () => copyNotices ), [] );
		assert.equal( await page.locator( '.wk-h1-menu__copy' ).getAttribute( 'aria-disabled' ), 'true' );
		await page.evaluate( () => finishCopy() );
		await page.waitForFunction( () => copyNotices.length === 1 );
		assert.deepEqual( await page.evaluate( () => copyNotices ), [ 'wk-link-copied' ] );
	} );
}

test( 'Minerva keyboard contents exclude the collapsed sheet, navigate parents and close one layer per Escape', async ( t ) => {
	const { page, open } = await fixture( t );
	await open();
	await page.evaluate( () => {
		Wikidebates.wkOpenReadingModeFromPageTitle( 'Child keyboard', [
			{ page: '', title: 'Debate', type: '' },
			{ page: 'Parent keyboard', title: 'Parent keyboard', type: 'pour' },
			{ page: 'Child keyboard', title: 'Child keyboard', type: 'pour' }
		], { history: false } );
	} );
	await page.waitForFunction( () => !document.getElementById( 'wk-reading-content' ).hasAttribute( 'aria-busy' ) );
	assert.equal( await page.locator( '#wk-reading-left' ).evaluate( ( el ) => el.inert ), true );
	await page.locator( '#wk-reading-navbtn' ).focus();
	await page.keyboard.press( 'Enter' );
	assert.equal( await page.locator( '#wk-reading-left' ).evaluate( ( el ) => el.inert ), false );
	assert.equal( await page.evaluate( () => document.activeElement.matches( '#wk-reading-stack button.is-root' ) ), true );
	await page.keyboard.press( 'Tab' );
	assert.equal( await page.evaluate( () => document.activeElement.dataset.page ), 'Parent keyboard' );
	await page.keyboard.press( 'Space' );
	await page.waitForFunction( () => Wikidebates.WK_READING.state._wkCurrentPage === 'Parent keyboard' );
	assert.equal( await page.locator( '#wk-reading-left' ).evaluate( ( el ) => el.inert ), true );
	assert.equal( await page.evaluate( () => document.activeElement.id ), 'wk-reading-content' );
	await page.locator( '#wk-reading-navbtn' ).focus();
	await page.keyboard.press( 'Space' );
	await page.keyboard.press( 'Escape' );
	assert.equal( await page.locator( '#wk-reading-mode' ).count(), 1 );
	assert.equal( await page.evaluate( () => document.activeElement.id ), 'wk-reading-navbtn' );
	assert.equal( await page.locator( '#wk-reading-left' ).getAttribute( 'aria-hidden' ), 'true' );
} );

for ( const skin of [ 'vector', 'minerva' ] ) {
	test( skin + ': parent actions, copied URL and browser history follow the displayed page, including percent titles', async ( t ) => {
		const { page } = await fixture( t, skin, true, true );
		await page.evaluate( () => {
			window.copiedLinks = [];
			Object.defineProperty( navigator, 'clipboard', { configurable: true, value: {
				writeText: ( value ) => { copiedLinks.push( value ); return Promise.resolve(); }
			} } );
			window.argumentHTML = '<h2 id="Summary">Summary</h2><p>Test argument</p><h2 id="Parent_debates">Parents</h2>';
			const source = document.createElement( 'div' );
			source.innerHTML = '<h1 id="firstHeading">Debate</h1>' +
				'<h2><span class="bouton-modifier"><a href="/edit/debate-pro">Edit parent list</a></span></h2>' +
				'<ul class="argument-list pour"><li class="argument level-1">' +
				'<div class="argument-title"><a href="/w/index.php?title=Parent_A">Parent label</a></div>' +
				'<div class="argument-wrapper"><h2><span class="bouton-modifier"><a href="/edit/parent-objections">Edit child list</a></span></h2>' +
				'<ul class="argument-list contre"><li class="argument"><div id="test-child" class="argument-title">' +
				'<a href="/w/index.php?title=Hausse%20de%2010%20%25">Child label</a>' +
				'</div></li></ul></div></li></ul>';
			document.getElementById( 'bodyContent' ).appendChild( source );
			Wikidebates.wkOpenReadingModeFromTitleEl( document.getElementById( 'test-child' ) );
		} );
		const current = async ( title ) => page.waitForFunction( ( title ) =>
			Wikidebates.WK_READING.state._wkCurrentPage === title &&
			!document.getElementById( 'wk-reading-content' ).hasAttribute( 'aria-busy' ), title );
		const editSelector = skin === 'minerva' ? '.wk-h1-menu__edit' : '.wk-h1-btn.bouton-modifier a';
		await current( 'Hausse de 10 %' );
		assert.equal( await page.locator( editSelector ).getAttribute( 'href' ), '/edit/parent-objections' );
		if ( skin === 'minerva' ) await page.locator( '#wk-reading-navbtn' ).click();
		await page.evaluate( () => { window.deferRead = true; } );
		await page.locator( '#wk-reading-stack button[data-page="Parent A"]' ).click();
		assert.equal( await page.locator( '.wk-h1-menu__detail' ).getAttribute( 'href' ), '/w/index.php?title=Parent_A' );
		assert.equal( await page.locator( editSelector ).getAttribute( 'href' ), '/edit/debate-pro' );
		await page.evaluate( () => {
			window.deferRead = false;
			pendingRead.resolve( { wikidebatesreading: { html: '<p>Parent content</p>' } } );
		} );
		await current( 'Parent A' );
		await page.locator( '.wk-icon-more' ).click();
		await page.locator( '.wk-h1-menu__copy' ).click();
		await page.waitForFunction( () => copiedLinks.length === 1 );
		assert.deepEqual( await page.evaluate( () => copiedLinks ), [ new URL( '/w/index.php?title=Parent_A', page.url() ).href ] );
		await page.goBack();
		await current( 'Hausse de 10 %' );
		assert.equal( await page.locator( '.wk-h1-menu__detail' ).getAttribute( 'href' ), '/w/index.php?title=Hausse%20de%2010%20%25' );
		assert.equal( await page.locator( editSelector ).getAttribute( 'href' ), '/edit/parent-objections' );
		await page.goForward();
		await current( 'Parent A' );
		assert.equal( await page.locator( '.wk-h1-menu__detail' ).getAttribute( 'href' ), '/w/index.php?title=Parent_A' );
		assert.equal( await page.locator( editSelector ).getAttribute( 'href' ), '/edit/debate-pro' );
	} );
}

test( 'dynamically inserted collapsible roots keep hidden links out of the real keyboard tab order', async ( t ) => {
	const { page } = await fixture( t, 'vector' );
	await page.addScriptTag( { path: path.join( root, 'modules/features/frCollapsible.js' ) } );
	await page.evaluate( () => {
		const section = document.createElement( 'section' );
		section.className = 'fr-collapsible fr-collapsed';
		section.id = 'dynamic-section';
		section.innerHTML = '<div class="fr-collapsible-toggle" id="dynamic-toggle">Toggle</div>' +
			'<div class="fr-collapsible-content" id="dynamic-content"><a id="hidden-link" href="#nested">Hidden link</a></div>';
		document.body.appendChild( section );
		const after = document.createElement( 'a' );
		after.id = 'after-toggle';
		after.href = '#after';
		after.textContent = 'After section';
		document.body.appendChild( after );
		mw.hook( 'wikipage.content' ).fire( jQuery( section ) );
	} );
	assert.equal( await page.locator( '#dynamic-content' ).getAttribute( 'inert' ), '' );
	await page.focus( '#dynamic-toggle' );
	await page.keyboard.press( 'Tab' );
	assert.equal( await page.evaluate( () => document.activeElement.id ), 'after-toggle' );
	await page.focus( '#dynamic-toggle' );
	await page.keyboard.press( 'Enter' );
	assert.equal( await page.locator( '#dynamic-content' ).getAttribute( 'inert' ), null );
	await page.keyboard.press( 'Tab' );
	assert.equal( await page.evaluate( () => document.activeElement.id ), 'hidden-link' );
	await page.evaluate( () => document.getElementById( 'dynamic-toggle' ).click() );
	assert.equal( await page.evaluate( () => document.activeElement.id ), 'dynamic-toggle' );
	assert.equal( await page.locator( '#dynamic-content' ).getAttribute( 'inert' ), '' );
} );

test( 'an early title click in Chromium is remembered until the lazy UI resolves', async ( t ) => {
	const { page } = await fixture( t, 'vector' );
	await page.evaluate( () => {
		Wikidebates.hrefToPageTitle = () => 'Argument'; // Isolate module readiness from about:blank URL parsing.
		Wikidebates.wkOpenReadingModeFromTitleEl = null;
		window.openedLazyTitles = [];
		window.lazyUi = jQuery.Deferred();
		window.lazyUiCalls = [];
		mw.loader = { using: ( module ) => {
			window.lazyUiCalls.push( module );
			return window.lazyUi.promise();
		} };
		const node = document.createElement( 'div' );
		node.id = 'lazy-click';
		node.className = 'argument-title';
		node.innerHTML = '<a href="https://wiki.test/wiki/Argument">Argument</a>';
		document.getElementById( 'bodyContent' ).appendChild( node );
	} );
	await page.addScriptTag( { path: path.join( root, 'modules/features/view.ns0.js' ) } );
	await page.click( '#lazy-click' );
	await page.waitForFunction( () => lazyUiCalls.length === 1 );
	assert.equal( await page.locator( '#lazy-click' ).getAttribute( 'aria-busy' ), 'true' );
	await page.evaluate( () => {
		Wikidebates.wkOpenReadingModeFromTitleEl = ( el ) => openedLazyTitles.push( el.id );
		lazyUi.resolve();
	} );
	await page.waitForFunction( () => openedLazyTitles.length === 1 );
	assert.deepEqual( await page.evaluate( () => openedLazyTitles ), [ 'lazy-click' ] );
	assert.equal( await page.locator( '#lazy-click' ).getAttribute( 'aria-busy' ), null );
} );

test( 'a failed extra-content request stays retryable in Chromium without waiting for the throttle', async ( t ) => {
	const { page } = await fixture( t, 'vector' );
	await page.evaluate( () => {
		const originalGet = mw.config.get;
		mw.config.get = ( key ) => key === 'wgWikidebatesWikiNames'
			? { 'wk-param-page': 'page', 'wk-tpl-more-content': 'More_content' } : originalGet( key );
		mw.message = () => ( { exists: () => true } );
		mw.msg = ( key ) => ( { 'wk-expand-loading': 'Chargement...',
			'wk-expand-load-error': 'Chargement impossible. Cliquez pour reessayer.' } )[ key ] || key;
		window.expandCalls = [];
		mw.Api = function () {
			this.post = ( params ) => {
				const deferred = jQuery.Deferred();
				window.expandCalls.push( { params, deferred } );
				return deferred.promise();
			};
		};
		document.getElementById( 'bodyContent' ).innerHTML =
			'<section><div id="browser-more" class="more-content-button" data-page="Example">Show more</div>' +
			'<div class="more-content-wrapper"></div></section>';
	} );
	await page.addScriptTag( { path: path.join( root, 'modules/common/i18n.js' ) } );
	await page.addScriptTag( { path: path.join( root, 'modules/features/moreContent.js' ) } );
	await page.click( '#browser-more' );
	await page.waitForFunction( () => window.expandCalls.length === 1 );
	assert.equal( await page.getAttribute( '#browser-more', 'aria-busy' ), 'true' );
	await page.evaluate( () => { expandCalls[ 0 ].deferred.reject( 'http' ); } );
	await page.waitForSelector( '.wk-expand-status[role="alert"]' );
	assert.equal( await page.isVisible( '#browser-more' ), true );
	await page.click( '#browser-more' );
	await page.waitForFunction( () => window.expandCalls.length === 2 );
	await page.evaluate( () => { expandCalls[ 1 ].deferred.resolve( { parse: { text: '<p id="recovered-more">Recovered</p>' } } ); } );
	await page.waitForSelector( '#recovered-more' );
	assert.equal( await page.isVisible( '#browser-more' ), false );
	assert.equal( await page.locator( '.wk-expand-status' ).count(), 0 );
} );

test( 'recent-contributions requests operate only on the clicked block in Chromium', async ( t ) => {
	const { page } = await fixture( t, 'minerva' );
	await page.evaluate( () => {
		const originalGet = mw.config.get;
		mw.config.get = ( key ) => key === 'wgWikidebatesWikiNames' ? {} : originalGet( key );
		mw.message = () => ( { exists: () => true } );
		mw.msg = ( key ) => key;
		window.expandCalls = [];
		mw.Api = function () {
			this.post = ( params ) => {
				const deferred = jQuery.Deferred();
				window.expandCalls.push( { params, deferred } );
				return deferred.promise();
			};
		};
		document.getElementById( 'bodyContent' ).innerHTML = [ 'A', 'B' ].map( ( id ) =>
			'<section id="recent-' + id + '"><h2 class="latest-changes-button">Recent</h2>' +
			'<div class="latest-changes-button mw-ui-button" data-page="Debate ' + id + '">Show</div>' +
			'<div class="latest-changes-wrapper"></div></section>' ).join( '' );
	} );
	await page.addScriptTag( { path: path.join( root, 'modules/common/i18n.js' ) } );
	await page.addScriptTag( { path: path.join( root, 'modules/features/view.ns0.idle.js' ) } );
	await page.click( '#recent-B h2' );
	assert.equal( await page.evaluate( () => window.expandCalls.length ), 0 );
	await page.click( '#recent-B .mw-ui-button' );
	await page.waitForFunction( () => window.expandCalls.length === 1 );
	assert.equal( await page.evaluate( () => window.expandCalls[ 0 ].params.text ),
		'{{#recentdebatechanges: page=Debate B|limit=100}}' );
	await page.evaluate( () => { window.expandCalls[ 0 ].deferred.reject( 'http' ); } );
	await page.waitForSelector( '#recent-B .wk-expand-status[role="alert"]' );
	assert.equal( await page.isVisible( '#recent-A .mw-ui-button' ), true );
	assert.equal( await page.isVisible( '#recent-B .mw-ui-button' ), true );
	await page.click( '#recent-B .mw-ui-button' );
	await page.waitForFunction( () => window.expandCalls.length === 2 );
	await page.evaluate( () => { window.expandCalls[ 1 ].deferred.resolve( { parse: { text: '<ul id="recovered-latest"><li>New</li></ul>' } } ); } );
	await page.waitForSelector( '#recent-B #recovered-latest' );
	assert.equal( await page.isVisible( '#recent-B .mw-ui-button' ), false );
	assert.equal( await page.isVisible( '#recent-A .mw-ui-button' ), true );
} );

test( 'Vector hover uses the user language in its element-local cache and rejects late stale replies', async ( t ) => {
	const { page } = await fixture( t, 'vector' );
	await page.evaluate( () => {
		const get = mw.config.get;
		window.tooltipLanguage = 'fr';
		mw.config.get = ( key ) => key === 'wgUserLanguage' ? window.tooltipLanguage : get( key );
		window.tooltipRequests = [];
		mw.Api = function () {
			this.post = ( params ) => {
				const deferred = jQuery.Deferred();
				window.tooltipRequests.push( { params, deferred } );
				return deferred.promise();
			};
		};
		document.getElementById( 'bodyContent' ).innerHTML =
			'<div id="browser-hover" class="hover-map" data-argument="Debate" ' +
			'style="display:block;width:210px;height:40px;">' +
			'<a href="/wiki/Argument" title="Argument">Hover me</a></div>';
	} );
	await page.addScriptTag( { path: path.join( root, 'modules/common/i18n.js' ) } );
	await page.addScriptTag( { path: path.join( root, 'modules/features/hover.js' ) } );
	await page.hover( '#browser-hover' );
	await page.waitForFunction( () => tooltipRequests.length === 1 );
	assert.equal( await page.evaluate( () => tooltipRequests[ 0 ].params.uselang ), 'fr' );
	await page.evaluate( () => { tooltipRequests[ 0 ].deferred.resolve( { parse: { text: '<strong>French result</strong>' } } ); } );
	await page.waitForFunction( () => document.querySelector( '#browser-hover .hover-wrapper' )?.textContent === 'French result' );
	await page.mouse.move( 350, 680 );
	await page.evaluate( () => { window.tooltipLanguage = 'en'; } );
	await page.hover( '#browser-hover' );
	await page.waitForFunction( () => tooltipRequests.length === 2 );
	assert.equal( await page.evaluate( () => tooltipRequests[ 1 ].params.uselang ), 'en' );
	assert.equal( await page.locator( '#browser-hover .hover-wrapper' ).textContent(), '' );
	await page.evaluate( () => { tooltipRequests[ 1 ].deferred.resolve( { parse: { text: '<strong>English result</strong>' } } ); } );
	await page.waitForFunction( () => document.querySelector( '#browser-hover .hover-wrapper' )?.textContent === 'English result' );
	await page.mouse.move( 350, 680 );
	await page.hover( '#browser-hover' );
	assert.equal( await page.evaluate( () => tooltipRequests.length ), 2, 'repeat hover uses the local English cache' );
	await page.mouse.move( 350, 680 );
	await page.evaluate( () => { window.tooltipLanguage = 'fr'; } );
	await page.hover( '#browser-hover' );
	await page.waitForFunction( () => document.querySelector( '#browser-hover .hover-wrapper' )?.textContent === 'French result' );
	assert.equal( await page.evaluate( () => tooltipRequests.length ), 2, 'the shared parse cache already holds the French response' );
} );
