/* Wikidebates — Minerva reading gesture coordination */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;
	var UI_IDS = M.ids;

	var WKReadingParseCacheHook = null;

	mw.hook( 'wikidebates.pageParsed' ).add( function ( key ) {
		if ( typeof WKReadingParseCacheHook === 'function' ) WKReadingParseCacheHook( key );
	} );

	function wkReadingBindSiblingSwipeMinerva( ui ) {
		if ( !ui || !ui.content || !ui.siblings || !ui.root || !ui.panel ) return;

		if ( ui.content.dataset.wkSiblingSwipeBound === '1' ) return;
		ui.content.dataset.wkSiblingSwipeBound = '1';

		var navigation = M.createNavigation( ui );
		var previews = M.createPreviews( ui, navigation );
		var getListEls = navigation.getListEls;
		var getCurrentIndex = navigation.getCurrentIndex;
		var openByIndex = navigation.openByIndex;
		var getSiblingTargets = navigation.getSiblingTargets;
		var prefetchPage = navigation.prefetchPage;
		var prefetchSiblings = navigation.prefetchSiblings;
		var setPeekContentByPage = previews.setContent;
		var setDragOffset = previews.setOffset;
		var resetDragOffset = previews.resetOffset;
		var setH1SwipeOffset = previews.setHeadingOffset;
		var resetH1SwipeOffset = previews.resetHeadingOffset;
		var bounceEdge = previews.bounceEdge;
		var ensureParentPeek = previews.ensureParent;
		var parentPeekHide = previews.hideParent;
		var parentDragSet = previews.setParentOffset;
		var parentDragReset = previews.resetParentOffset;

		var startX = 0;
		var startY = 0;
		var startScrollTop = 0;
		var lastX = 0;
		var lastY = 0;
		var tracking = false;
		var locked = false;
		var scrollLocked = false;
		var lockedDir = 0;

		var vLocked = false;
		var vStartAtTop = false;
		var vStartAtBottom = false;
		var vAction = '';
		var vPull = 0;
		var vPullLastY = 0;
		var vTocLastDeltaY = 0;

		WKReadingParseCacheHook = function ( key ) {
			try {
				if ( !tracking || !locked ) return;
				if ( !ui._wkPeekPendingKey || ui._wkPeekPendingKey !== key ) return;
				if ( !ui._wkPeekPendingPage || !ui._wkPeekPendingDir ) return;
				setPeekContentByPage( ui._wkPeekPendingPage, ui._wkPeekPendingDir );
				setDragOffset( lastX - startX );
			} catch ( e ) {}
		};

		function isSheetOpen() {
			return ui.root.classList.contains( 'is-sheet-open' );
		}

		function navigate( dir ) {
			var items = getListEls();
			if ( !items || items.length < 2 ) return false;

			var cur = getCurrentIndex( items );
			if ( cur === -1 ) return false;

			var next = cur + ( dir > 0 ? 1 : -1 );

			if ( next < 0 ) {
				bounceEdge( -1 );
				return false;
			}
			if ( next >= items.length ) {
				bounceEdge( +1 );
				return false;
			}

			var ok = openByIndex( items, next );
			if ( ok ) W.setTimeout( prefetchSiblings, 0 );

			return ok;
		}

		function atTop() {
			try { return ( ui.content.scrollTop <= 1 ); } catch ( e ) {}
			return false;
		}

		function atBottom() {
			/* Tolérance pour les arrondis et le rebond natif près du bas. */
			try {
				var el = ui.content;
				var st = el.scrollTop || 0;
				var ch = el.clientHeight || 0;
				var sh = el.scrollHeight || 0;

				var exact = ( st + ch >= sh - 2 );
				var remain = sh - ( st + ch );

				return ( exact || remain <= 64 );
			} catch ( e ) {}
			return false;
		}

		function openTocSheet() {
			try {
				if ( ui && ui._wkLeftOpen ) ui._wkLeftOpen();
			} catch ( e ) {}
		}

		var _wkTocDragOpen = false;
		var _wkTocDragMax = 0;

		function tocDragEnsureOpen() {
			if ( _wkTocDragOpen ) return true;
			if ( !ui || !ui.left || !ui.root ) return false;

			var left = ui.left;
			var overlay = D.getElementById( UI_IDS.overlayId );
			var btn = D.getElementById( UI_IDS.navBtnId );

			/*	IMPORTANT : mettre le panneau en état "ouvert" sans le faire apparaître d’un coup.
				On l’ancre fermé via transform = max, puis on réduit max->0 pendant le drag.	*/
			if ( !_wkTocDragMax ) _wkTocDragMax = left.offsetHeight || 500;

			left.classList.add( 'is-open' );
			if ( overlay ) {
				overlay.classList.add( 'is-on' );
				/*	Pendant le drag, on garde la main sur les events touch de ui.content.
					Sinon l’overlay peut capter le gesture et figer le mouvement. */
				overlay.dataset.wkDragPe = '1';
				overlay.style.pointerEvents = 'none';
			}
			if ( btn ) {
				btn.classList.add( 'is-hidden-when-open' );
				btn.setAttribute( 'aria-expanded', 'true' );
			}
			ui.root.classList.add( 'is-sheet-open' );

			try { left.style.transition = 'none'; } catch ( e0 ) {}
			try { left.style.willChange = 'transform'; } catch ( e1 ) {}
			try { left.style.transform = 'translate3d(0,' + _wkTocDragMax + 'px,0)'; } catch ( e2 ) {}

			_wkTocDragOpen = true;
			return true;
		}

		function tocDragSet( pullPx ) {
			if ( !ui || !ui.left ) return;
			var left = ui.left;

			if ( !_wkTocDragMax ) _wkTocDragMax = left.offsetHeight || 500;

			var max = _wkTocDragMax;
			var pull = pullPx;
			if ( pull < 0 ) pull = 0;
			if ( pull > max ) pull = max;

			var t = max - pull;
			left.style.transform = 'translate3d(0,' + t + 'px,0)';
		}

		function tocDragCommit() {
			if ( !ui || !ui.left ) return;
			var left = ui.left;

			var overlay = D.getElementById( UI_IDS.overlayId );
			if ( overlay && overlay.dataset.wkDragPe === '1' ) {
				overlay.style.pointerEvents = '';
				delete overlay.dataset.wkDragPe;
			}

			openTocSheet();
			try { left.style.transition = ''; } catch ( e0 ) {}
			try { left.style.transform = ''; } catch ( e1 ) {}
			try { left.style.willChange = ''; } catch ( e2 ) {}

			_wkTocDragOpen = false;
			_wkTocDragMax = 0;
		}

		function tocDragCancel() {
			var overlay = D.getElementById( UI_IDS.overlayId );
			if ( overlay && overlay.dataset.wkDragPe === '1' ) {
				overlay.style.pointerEvents = '';
				delete overlay.dataset.wkDragPe;
			}

			try {
				if ( ui && typeof ui._wkLeftClose === 'function' ) ui._wkLeftClose();
				else if ( ui && ui.left ) {
					ui.left.classList.remove( 'is-open' );
					ui.left.style.transform = '';
				}
			} catch ( e0 ) {}

			_wkTocDragOpen = false;
			_wkTocDragMax = 0;
		}

		function openParentOrClose() {
			try {
				/*	Sécurité : ne jamais garder un transform inline avant navigation */
				parentDragReset( true );
				if ( !ui || !ui.stack ) return;

				var cur = ui.stack.querySelector( '.wk-reading__item.is-current' );
				if ( !cur ) return;

				var parent = cur.previousElementSibling;
				if ( !parent || !parent.classList ) return;

				if ( parent.classList.contains( 'is-root' ) ) {
					if ( typeof WK.wkCloseReadingMode === 'function' ) WK.wkCloseReadingMode();
					return;
				}

				if ( parent.click ) parent.click();
			} catch ( e ) {}
		}

		function shouldIgnoreTarget( t ) {
			if ( !t ) return false;
			if ( t.closest && t.closest( 'a, button, input, textarea, select, label' ) ) return true;
			return false;
		}

		function getScrollPosition() {
			// Safari can report positions outside the scroll range during its
			// edge bounce. Leave these movements to the parent and TOC gestures.
			var max = Math.max( 0, ui.content.scrollHeight - ui.content.clientHeight );
			return Math.max( 0, Math.min( max, ui.content.scrollTop ) );
		}

		function lockToNativeScroll() {
			if ( !tracking || scrollLocked || vLocked || getScrollPosition() === startScrollTop ) return;
			// Native scrolling can start before the 12px touch threshold or
			// before its scroll event is delivered. Keep it in control until release.
			scrollLocked = true;
			if ( locked ) {
				locked = false;
				resetDragOffset( true );
				resetH1SwipeOffset( true );
			}
		}

		ui.content.addEventListener( 'scroll', lockToNativeScroll, { passive: true } );

		ui.content.addEventListener( 'touchstart', function ( e ) {
			if ( isSheetOpen() ) return;
			if ( !e.touches || e.touches.length !== 1 ) return;
			if ( shouldIgnoreTarget( e.target ) ) return;

			tracking = true;
			locked = false;
			scrollLocked = false;
			lockedDir = 0;

			startX = e.touches[ 0 ].clientX;
			startY = e.touches[ 0 ].clientY;
			startScrollTop = getScrollPosition();
			lastX = startX;
			lastY = startY;

			vLocked = false;
			vAction = '';
			vPull = 0;
			vPullLastY = startY;
			vTocLastDeltaY = 0;

			_wkTocDragOpen = false;
			_wkTocDragMax = 0;

			vStartAtTop = atTop();
			vStartAtBottom = atBottom();
		}, { passive: true } );

		ui.content.addEventListener( 'touchmove', function ( e ) {
			// Check the position too: a touch event may precede its scroll event.
			lockToNativeScroll();
			if ( !tracking || scrollLocked ) return;
			if ( isSheetOpen() && !vLocked ) return;
			if ( !e.touches || e.touches.length !== 1 ) return;

			lastX = e.touches[ 0 ].clientX;
			lastY = e.touches[ 0 ].clientY;

			var dx = lastX - startX;
			var dy = lastY - startY;

			if ( locked ) {
				setDragOffset( dx );
				setH1SwipeOffset( dx );
				e.preventDefault();
				return;
			}

			if ( vLocked ) {
				if ( vAction === 'toc' ) {
					if ( tocDragEnsureOpen() ) {
						var max = _wkTocDragMax || ( ui.left ? ( ui.left.offsetHeight || 500 ) : 500 );

						/*	IMPORTANT :
							Le panneau suit le mouvement *incrémental* du doigt.
							Donc si l’utilisateur remonte puis redescend un peu,
							l’ouverture est immédiatement annulée (sans devoir revenir au point de départ). */
						var deltaY = lastY - vPullLastY;
						vTocLastDeltaY = deltaY;

						/*	Finger down => deltaY > 0 => on ferme (pull diminue)
							Finger up   => deltaY < 0 => on ouvre (pull augmente) */
						vPull -= deltaY;

						if ( vPull < 0 ) vPull = 0;
						if ( vPull > max ) vPull = max;

						vPullLastY = lastY;
						tocDragSet( vPull );
					}
					e.preventDefault();
					e.stopPropagation();
					return;
				}

				/*	parent : effet progressif (révéler l’argument parent) */
				var pullP = dy;
				if ( pullP < 0 ) pullP = 0;
				if ( pullP > 120 ) pullP = 120 + ( pullP - 120 ) * 0.35;
				if ( pullP > 180 ) pullP = 180;
				parentDragSet( pullP );
				e.preventDefault();
				e.stopPropagation();
				return;
			}

			if ( Math.abs( dx ) > 12 && Math.abs( dx ) > Math.abs( dy ) * 1.2 ) {
				locked = true;
				lockedDir = ( dx < 0 ? +1 : -1 );

				e.preventDefault();
				e.stopPropagation();

				var t = getSiblingTargets();

				if ( lockedDir > 0 ) {
					if ( t.next ) {
						prefetchPage( t.next );
						setPeekContentByPage( t.next, +1 );
					}
				} else {
					if ( t.prev ) {
						prefetchPage( t.prev );
						setPeekContentByPage( t.prev, -1 );
					}
				}

				setDragOffset( dx );
				return;
			}

			var vOk = ( Math.abs( dy ) > 12 && Math.abs( dy ) > Math.abs( dx ) * 1.2 );
			if ( vOk ) {
				var canTop = vStartAtTop;
				var canBottom = vStartAtBottom;

				if ( canTop && dy > 12 ) {
					vLocked = true;
					vAction = 'parent';

					ensureParentPeek();
					parentDragSet( dy );

					e.preventDefault();
					e.stopPropagation();
					return;
				}

				if ( canBottom && dy < -12 && Math.abs( dx ) < 10 ) {
					vLocked = true;
					vAction = 'toc';
					vPull = 0;

					tocDragEnsureOpen();

					var max2 = _wkTocDragMax || ( ui.left ? ( ui.left.offsetHeight || 500 ) : 500 );
					var pull2 = -dy;
					if ( pull2 > max2 ) pull2 = max2;
					vPull = pull2;
					tocDragSet( vPull );

					e.preventDefault();
					e.stopPropagation();
					return;
				}

				/* Le défilement garde la main jusqu'au relâchement, même si
				 * le doigt change de direction ou atteint une extrémité. */
				scrollLocked = true;
			}
		}, { passive: false } );

		function endGesture() {
			if ( !tracking ) return;
			lockToNativeScroll();
			tracking = false;

			var dx = lastX - startX;
			var dy = lastY - startY;

			if ( vLocked ) {
				vLocked = false;

				if ( vAction === 'toc' ) {
					/*	Si l’utilisateur termine en redescendant, on annule (fermeture),
						même si le panneau a été tiré assez haut. */
					if ( vTocLastDeltaY > 0 ) tocDragCancel();
					else if ( vPull >= 60 ) tocDragCommit();
					else tocDragCancel();
					vTocLastDeltaY = 0;
				} else if ( vAction === 'parent' ) {
					if ( dy >= 60 && Math.abs( dy ) > Math.abs( dx ) * 1.2 ) openParentOrClose();
				}

				vAction = '';
				vPull = 0;
				return;
			}

			resetDragOffset();
			resetH1SwipeOffset();

			if ( !locked ) return;
			locked = false;

			if ( Math.abs( dx ) < 60 ) return;
			if ( Math.abs( dx ) < Math.abs( dy ) * 1.2 ) return;

			if ( dx < 0 ) navigate( +1 );
			else navigate( -1 );
		}

		ui.content.addEventListener( 'touchend', endGesture, { passive: true } );
		ui.content.addEventListener( 'touchcancel', function () {
			locked = false;
			vLocked = false;
			vAction = '';
			try {
				if ( ui && ui.content ) {
					ui.content.style.transition = '';
					ui.content.style.transform = '';
				}
				parentPeekHide( true );
			} catch ( e ) {}
			endGesture();
		}, { passive: true } );

		W.setTimeout( prefetchSiblings, 0 );
	}

	M.bindGestures = wkReadingBindSiblingSwipeMinerva;

}() );
