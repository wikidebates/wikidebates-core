/* Wikidebates — Minerva reading sibling and parent previews */
( function () {
	'use strict';

	var D = document;
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );
	var M = WK.minervaReading;

	var WKReadingParseCache = WK.wkPageParseCache;
	var wkReadingBuildFullTitle = M.buildFullTitle;

	M.createPreviews = function ( ui, navigation ) {
		var prefetchPage = navigation.prefetchPage;
		var getSiblingTitleByPage = navigation.getSiblingTitleByPage;

		function getRealH1() {
			return ui.panel ? ui.panel.querySelector( '.wk-reading-h1' ) : null;
		}

		function hideRealH1( on ) {
			var h1 = getRealH1();
			if ( !h1 ) return;

			if ( on ) {
				h1.style.transition = 'opacity 120ms linear';
				h1.style.opacity = '0';
				h1.style.pointerEvents = 'none';
			} else {
				h1.style.transition = 'opacity 120ms linear';
				h1.style.opacity = '';
				h1.style.pointerEvents = '';
				W.setTimeout( function () {
					var h12 = getRealH1();
					if ( h12 ) h12.style.transition = '';
				}, 140 );
			}
		}

		function ensurePeekLayer() {
			if ( ui._wkPeekLayer ) return ui._wkPeekLayer;

			var layer = D.createElement( 'div' );
			layer.className = 'wk-reading-peek';
			layer.setAttribute( 'aria-hidden', 'true' );
			layer.style.position = 'absolute';
			layer.style.top = '0';
			layer.style.left = '0';
			layer.style.right = '0';
			layer.style.bottom = '0';
			layer.style.overflow = 'auto';
			layer.style.webkitOverflowScrolling = 'touch';
			layer.style.pointerEvents = 'none';
			layer.style.zIndex = '1';
			layer.style.background = 'inherit';
			layer.style.paddingLeft = '16px';
			layer.style.paddingRight = '16px';

			layer.style.boxShadow = '0 0 12px rgba(0,0,0,0.15)';
			layer.style.willChange = 'transform';

			try {
				ui.content.style.position = ui.content.style.position || 'relative';
				ui.content.style.zIndex = '2';
				ui.content.style.willChange = 'transform';
			} catch ( e ) {}

			layer.style.display = 'none';
			layer.style.transform = 'translateX(100%)';

			if ( !ui.panel.style.position ) ui.panel.style.position = 'relative';

			var body = D.createElement( 'div' );
			body.className = 'wk-reading-peek-body';
			layer.appendChild( body );
			ui._wkPeekBody = body;

			ui.panel.appendChild( layer );

			ui._wkPeekLayer = layer;
			return layer;
		}


		function getParentTitle() {
			try {
				if ( !ui || !ui.stack ) return '';
				var cur = ui.stack.querySelector( '.wk-reading__item.is-current' );
				if ( !cur ) return '';
				var parent = cur.previousElementSibling;
				if ( !parent ) return '';

				var t = parent.querySelector( '.wk-reading__title, .wk-reading__label, .wk-reading__link, a, span, div' );
				var txt = t ? ( t.textContent || '' ) : ( parent.textContent || '' );
				return txt.replace( /\s+/g, ' ' ).trim();
			} catch ( e ) {}
			return '';
		}

		function ensureParentPeek() {
			if ( ui._wkParentPeek ) return ui._wkParentPeek;

			var peek = D.createElement( 'div' );
			peek.className = 'wk-reading-parent-peek';
			peek.style.position = 'absolute';
			peek.style.top = '0';
			peek.style.left = '0';
			peek.style.right = '0';
			peek.style.height = '72px';
			peek.style.display = 'none';
			peek.style.pointerEvents = 'none';
			peek.style.zIndex = '1';
			peek.style.background = 'inherit';
			peek.style.boxShadow = 'none';
			peek.style.borderBottom = '1px solid rgba(0,0,0,0.08)';
			peek.style.opacity = '0';
			peek.style.transform = 'translate3d(0,-8px,0)';
			peek.style.willChange = 'transform, opacity';

			var inner = D.createElement( 'div' );
			inner.style.display = 'flex';
			inner.style.alignItems = 'center';
			inner.style.gap = '10px';
			inner.style.padding = '16px 16px 12px 16px';
			inner.style.fontSize = '14px';
			inner.style.lineHeight = '1.2';

			var sub = D.createElement( 'div' );
			sub.className = 'wk-reading-parent-peek__sub';
			sub.style.whiteSpace = 'nowrap';
			sub.style.overflow = 'hidden';
			sub.style.textOverflow = 'ellipsis';
			sub.style.opacity = '0.75';
			sub.style.marginTop = '2px';
			sub.style.fontSize = '13px';

			var col = D.createElement( 'div' );
			col.style.minWidth = '0';

			peek.appendChild( inner );

			try {
				if ( !ui.panel.style.position ) ui.panel.style.position = 'relative';
				ui.panel.appendChild( peek );

				ui.content.style.position = ui.content.style.position || 'relative';
				ui.content.style.zIndex = '2';
				ui.content.style.willChange = 'transform';
			} catch ( e ) {}

			ui._wkParentPeek = peek;

			return peek;
		}

		function parentPeekShow( pull ) {
			var peek = ensureParentPeek();
			if ( !peek ) return;

			peek.style.display = '';
			var p = pull / 90;
			if ( p < 0 ) p = 0;
			if ( p > 1 ) p = 1;

			peek.style.opacity = String( 0.15 + p * 0.85 );
			peek.style.transform = 'translate3d(0,' + ( -8 + p * 8 ) + 'px,0)';
		}

		function parentPeekHide( immediate ) {
			var peek = ui._wkParentPeek;
			if ( !peek ) return;

			if ( immediate ) {
				peek.style.display = 'none';
				peek.style.opacity = '0';
				peek.style.transform = 'translate3d(0,-8px,0)';
				return;
			}

			peek.style.transition = 'opacity 160ms linear, transform 160ms cubic-bezier(0.2,0,0.2,1)';
			peek.style.opacity = '0';
			peek.style.transform = 'translate3d(0,-8px,0)';
			W.setTimeout( function () {
				if ( ui._wkParentPeek ) {
					ui._wkParentPeek.style.transition = '';
					ui._wkParentPeek.style.display = 'none';
				}
			}, 170 );
		}

		function parentDragSet( pull ) {
			if ( !ui || !ui.content ) return;

			ui.content.style.transform = 'translate3d(0,' + pull + 'px,0)';
			parentPeekShow( pull );
		}

		function parentDragReset( immediate ) {
			if ( !ui || !ui.content ) return;

			if ( immediate ) {
				try {
					ui.content.style.transition = '';
					ui.content.style.transform = 'translate3d(0,0,0)';
					ui.content.style.transform = '';
				} catch ( e0 ) {}
				parentPeekHide( true );
				return;
			}

			ui.content.style.transition = 'transform 180ms cubic-bezier(0.2,0,0.2,1)';
			ui.content.style.transform = 'translate3d(0,0,0)';

			W.setTimeout( function () {
				if ( ui && ui.content ) ui.content.style.transition = '';
			}, 190 );

			parentPeekHide( false );
		}

		function getFragmentFromParseData( data ) {
			var html = ( data && data.parse && data.parse.text ) ? data.parse.text : '';
			if ( !html ) return '';

			try {
				if ( typeof WK.wkReadingExtractFragment === 'function' ) return WK.wkReadingExtractFragment( html ) || '';
			} catch ( e ) {}

			return '';
		}

		function setPeekContentByPage( page, dir ) {
			var layer = ensurePeekLayer();

			var params = ( WK && typeof WK.wkReadingBuildParseRequestParams === 'function' )
				? WK.wkReadingBuildParseRequestParams( page )
				: null;

			if ( !params ) {
				ui._wkPeekPendingKey = '';
				ui._wkPeekPendingDir = 0;
				if ( layer ) layer.style.display = 'none';
				if ( ui._wkPeekBody ) ui._wkPeekBody.innerHTML = '';
				return false;
			}

			var key = WKReadingParseCache.key( params );
			var cached = WKReadingParseCache.get( key );

			var frag = cached ? getFragmentFromParseData( cached ) : '';

			if ( !frag ) {
				ui._wkPeekPendingKey = key;
				ui._wkPeekPendingDir = dir;
				ui._wkPeekPendingPage = page;

				if ( ui._wkPeekBody ) ui._wkPeekBody.innerHTML = '';
				layer.style.display = 'none';

				prefetchPage( page );
				return false;
			}

			ui._wkPeekPendingKey = '';
			ui._wkPeekPendingDir = 0;
			ui._wkPeekPendingPage = '';

			if ( ui._wkPeekBody ) {
				ui._wkPeekBody.innerHTML = frag;
				ui._wkPeekBody.insertBefore( wkReadingBuildFullTitle( getSiblingTitleByPage( page ) || page ), ui._wkPeekBody.firstChild );
			}

			layer.style.display = '';
			hideRealH1( true );
			layer.style.pointerEvents = 'none';

			if ( dir > 0 ) layer.style.transform = 'translateX(100%)';
			else layer.style.transform = 'translateX(-100%)';

			return true;
		}

		function setH1SwipeOffset( dx ) {
			var h1 = ui.panel ? ui.panel.querySelector( '.wk-reading-h1' ) : null;
			if ( !h1 ) return;

			var max = 18;
			var x = dx * 0.2;
			if ( x > max ) x = max;
			if ( x < -max ) x = -max;

			h1.style.transform = 'translateX(' + x + 'px)';
			h1.style.willChange = 'transform';
		}

		function resetH1SwipeOffset( immediate ) {
			var h1 = ui.panel ? ui.panel.querySelector( '.wk-reading-h1' ) : null;
			if ( !h1 ) return;

			h1.style.transition = immediate ? '' : 'transform 180ms cubic-bezier(0.2,0,0.2,1)';
			h1.style.transform = 'translateX(0)';

			W.setTimeout( function () {
				h1.style.transition = '';
				h1.style.transform = '';
				h1.style.willChange = '';
			}, 180 );
		}

		function setDragOffset( dx ) {
			if ( !ui.content ) return;

			var max = ( ui.panel && ui.panel.clientWidth ) ? ui.panel.clientWidth : ( W.innerWidth || 360 );
			if ( dx > max ) dx = max;
			if ( dx < -max ) dx = -max;

			ui.content.style.transform = 'translateX(' + dx + 'px)';

			var layer = ui._wkPeekLayer;
			if ( layer && layer.style.display !== 'none' ) {
				if ( dx < 0 ) layer.style.transform = 'translateX(calc(100% + ' + dx + 'px))';
				else layer.style.transform = 'translateX(calc(-100% + ' + dx + 'px))';
			}
		}

		function resetDragOffset( immediate ) {
			if ( !ui.content ) return;

			ui.content.style.transition = immediate ? '' : 'transform 180ms cubic-bezier(0.2,0,0.2,1)';
			ui.content.style.transform = 'translateX(0)';

			var layer = ui._wkPeekLayer;
			if ( layer ) {
				layer.style.transition = 'transform 180ms cubic-bezier(0.2,0,0.2,1)';
				layer.style.transform = 'translateX(100%)';
				layer.style.pointerEvents = 'none';
				layer.style.display = 'none';
				hideRealH1( false );
			}

			W.setTimeout( function () {
				if ( ui && ui.content ) ui.content.style.transition = '';
				if ( layer ) layer.style.transition = '';
			}, 180 );
		}

		function bounceEdge( dir ) {
			var el = ui.content;
			if ( !el ) return;

			var dist = ( dir > 0 ? 14 : -14 );

			if ( el.animate ) {
				el.animate(
					[
						{ transform: 'translateX(0)' },
						{ transform: 'translateX(' + dist + 'px)' },
						{ transform: 'translateX(0)' }
					],
					{
						duration: 170,
						easing: 'cubic-bezier(0.2, 0.0, 0.2, 1 )'
					}
				);
				return;
			}

			var prevTransition = el.style.transition;
			var prevTransform = el.style.transform;

			el.style.transition = 'transform 120ms cubic-bezier(0.2, 0.0, 0.2, 1 )';
			el.style.transform = 'translateX(' + dist + 'px)';

			W.setTimeout( function () {
				el.style.transform = 'translateX(0)';
				W.setTimeout( function () {
					el.style.transition = prevTransition || '';
					el.style.transform = prevTransform || '';
				}, 140 );
			}, 90 );
		}

		return {
			setContent: setPeekContentByPage, setOffset: setDragOffset, resetOffset: resetDragOffset,
			setHeadingOffset: setH1SwipeOffset, resetHeadingOffset: resetH1SwipeOffset,
			bounceEdge: bounceEdge, ensureParent: ensureParentPeek, hideParent: parentPeekHide,
			setParentOffset: parentDragSet, resetParentOffset: parentDragReset
		};
	};

}() );
