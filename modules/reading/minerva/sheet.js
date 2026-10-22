/* Wikidebates — Minerva reading contents sheet */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;
	var UI_IDS = M.ids;
	var wkMsgD = M.msg;

	var WKReadingLeftDocClickBound = false;

	function wkReadingEnsureFloatingButton( opt ) {
		if ( !opt || !opt.id ) return null;

		var btn = D.getElementById( opt.id );
		if ( btn ) return btn;

		btn = D.createElement( 'button' );
		btn.id = opt.id;
		btn.type = 'button';
		btn.className = opt.className || '';
		if ( opt.alt ) {
			btn.setAttribute( 'aria-label', opt.alt );
			btn.setAttribute( 'title', opt.alt );
		}

		var img = D.createElement( 'img' );
		img.src = opt.src || '';
		img.alt = opt.alt || '';
		btn.appendChild( img );

		( opt.container || D.body || D.documentElement ).appendChild( btn );

		return btn;
	}

	function wkReadingBuildOverlay() {
		var ov = D.createElement( 'div' );
		ov.id = UI_IDS.overlayId;
		ov.className = 'wk-reading-overlay';
		return ov;
	}

	function wkReadingBuildLeftHandle() {
		var h = D.createElement( 'div' );
		h.id = UI_IDS.leftHandleId;
		h.className = 'wk-reading-left__handle';
		h.setAttribute( 'aria-hidden', 'true' );
		return h;
	}

	function wkReadingBuildLeftHeader() {
		var head = D.createElement( 'div' );
		head.id = UI_IDS.leftHeaderId;
		head.className = 'wk-reading-left__header';

		var handle = wkReadingBuildLeftHandle();
		head.appendChild( handle );

		return head;
	}

	function wkReadingBindLeftSheet( ui ) {
		if ( !ui || !ui.root || !ui.panel || !ui.left ) return;

		if ( ui.left.dataset.wkLeftSheetBound === '1' ) return;
		ui.left.dataset.wkLeftSheetBound = '1';

		var left = ui.left;

		var overlay = D.getElementById( UI_IDS.overlayId );
		if ( !overlay ) {
			overlay = wkReadingBuildOverlay();
			ui.root.appendChild( overlay );
		}

		var btn = D.getElementById( UI_IDS.navBtnId );
		if ( !btn ) {
			btn = wkReadingEnsureFloatingButton( {
				id: UI_IDS.navBtnId,
				className: 'wk-reading-navbtn',
				src: '/w/images/fr/4/4d/Bouton-fil-Ariane.svg',
				alt: wkMsgD( 'wk-breadcrumb-aria-label' ),
				container: ui.root
			} );
		}
		btn.setAttribute( 'aria-expanded', 'false' );

		var header = D.getElementById( UI_IDS.leftHeaderId );
		if ( !header ) {
			header = wkReadingBuildLeftHeader();
			left.insertBefore( header, left.firstChild );
		}

		var handle = header.querySelector( '#' + UI_IDS.leftHandleId );
		if ( handle && handle.dataset.wkTapCloseBound !== '1' ) {
			handle.dataset.wkTapCloseBound = '1';

			handle.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				if ( ui._wkLeftClose ) ui._wkLeftClose();
			} );
		}

		function isOpen() {
			return left.classList.contains( 'is-open' );
		}

		function open() {
			if ( isOpen() ) return;
			left.classList.add( 'is-open' );
			overlay.classList.add( 'is-on' );
			btn.classList.add( 'is-hidden-when-open' );
			btn.setAttribute( 'aria-expanded', 'true' );
			ui.root.classList.add( 'is-sheet-open' );
		}

		function close() {
			if ( !isOpen() ) return;
			left.classList.remove( 'is-open' );
			left.style.transform = '';
			overlay.classList.remove( 'is-on' );
			btn.classList.remove( 'is-hidden-when-open' );
			btn.setAttribute( 'aria-expanded', 'false' );
			ui.root.classList.remove( 'is-sheet-open' );
		}

		function toggle() {
			if ( isOpen() ) close();
			else open();
		}

		if ( btn.dataset.wkBound !== '1' ) {
			btn.dataset.wkBound = '1';
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				toggle();
			} );
		}

		if ( overlay.dataset.wkBound !== '1' ) {
			overlay.dataset.wkBound = '1';
			overlay.addEventListener( 'click', function ( e0 ) {
				e0.preventDefault();
				e0.stopPropagation();
				close();
			} );
		}

		if ( left.dataset.wkLinkCloseBound !== '1' ) {
			left.dataset.wkLinkCloseBound = '1';

			left.addEventListener( 'click', function ( e2 ) {
				if ( !isOpen() ) return;

				var t = e2.target;
				if ( !t ) return;

				var a = t.closest ? t.closest( 'a' ) : null;
				if ( a ) {
					var href = a.getAttribute( 'href' ) || '';
					if ( href && href !== '#' ) close();
					return;
				}

				var nav = t.closest ? t.closest( '.wk-reading__item, [data-page], [data-wk-page]' ) : null;
				if ( nav ) close();
			}, true );
		}

		if ( !WKReadingLeftDocClickBound ) {
			WKReadingLeftDocClickBound = true;

			D.addEventListener( 'click', function ( e3 ) {
				var root = D.getElementById( UI_IDS.rootId );
				if ( !root ) return;

				var left2 = D.getElementById( UI_IDS.leftId );
				var btn2 = D.getElementById( UI_IDS.navBtnId );
				var ov2 = D.getElementById( UI_IDS.overlayId );

				if ( !left2 || !ov2 ) return;
				if ( !left2.classList.contains( 'is-open' ) ) return;

				var t = e3.target;
				if ( !t ) return;

				if ( left2.contains( t ) ) return;
				if ( btn2 && btn2.contains( t ) ) return;
				if ( ov2 && ov2.contains( t ) ) return;

				left2.classList.remove( 'is-open' );
				left2.style.transform = '';
				ov2.classList.remove( 'is-on' );
				root.classList.remove( 'is-sheet-open' );

				if ( btn2 ) {
					btn2.classList.remove( 'is-hidden-when-open' );
					btn2.setAttribute( 'aria-expanded', 'false' );
				}
			}, true );
		}

		var startY = 0;
		var startX = 0;
		var dragging = false;
		var dy = 0;
		var startScrollTop = 0;

		function onStart( e ) {
			if ( !isOpen() ) return;
			if ( !e.touches || !e.touches.length ) return;

			startScrollTop = left.scrollTop || 0;

			startY = e.touches[ 0 ].clientY;
			startX = e.touches[ 0 ].clientX;
			dy = 0;
			dragging = false;

			try { left.style.transition = 'none'; } catch ( e0 ) {}
		}

		function onMove( e ) {
			if ( !isOpen() ) return;
			if ( !e.touches || !e.touches.length ) return;
			if ( startScrollTop > 0 ) return;

			var y = e.touches[ 0 ].clientY;
			var x = e.touches[ 0 ].clientX;

			dy = y - startY;
			var dx = x - startX;

			if ( Math.abs( dx ) > Math.abs( dy ) ) return;
			if ( dy <= 0 ) return;

			if ( !dragging ) dragging = true;

			e.preventDefault();
			e.stopPropagation();

			var max = left.offsetHeight || 500;
			var t = Math.min( dy, max );
			left.style.transform = 'translate3d(0,' + t + 'px,0)';
		}

		function onEnd() {
			if ( !isOpen() ) return;

			try { left.style.transition = ''; } catch ( e0 ) {}

			if ( dragging ) {
				if ( dy > 90 ) close();
				else left.style.transform = '';
			}

			dragging = false;
			dy = 0;
		}

		if ( left.dataset.wkDragBound !== '1' ) {
			left.dataset.wkDragBound = '1';
			left.addEventListener( 'touchstart', onStart, { passive: true } );
			left.addEventListener( 'touchmove', onMove, { passive: false } );
			left.addEventListener( 'touchend', onEnd, { passive: true } );
			left.addEventListener( 'touchcancel', onEnd, { passive: true } );
		}

		ui._wkLeftOpen = open;
		ui._wkLeftClose = close;
		ui._wkLeftToggle = toggle;
	}

	M.bindLeftSheet = wkReadingBindLeftSheet;

}() );
