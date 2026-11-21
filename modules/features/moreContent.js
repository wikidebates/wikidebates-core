/*	Wikidébats — more (commun) */
( function () {
	'use strict';

	var D = document;
	var $D = jQuery( D );
	var W = window;
	var WK = W.Wikidebates || ( W.Wikidebates = {} );

	var WK_OPEN = '{{';
	var WK_CLOSE = '}}';
	var WK_SEP = '|';

	function moreContentCall() {
		if ( !WK.wkOnce( 'wkMoreInit' ) ) return;

		$D.on( 'click.wkMore', '.more-content-button', function () {
			WK.wkWarmI18nCaches();

			var ds = this.dataset || null;
			var page = ds && ds.page ? ds.page : jQuery( this ).data( 'page' );
			if ( !page ) return;

			var query = WK_OPEN + WK.WK_T.more + WK_SEP + WK.WK_P.page + ' = ' + page + WK_CLOSE;
			WK.wkExpandWikitext( this, 'more-content-wrapper', 'more-content-drop', query, 'more' );
		} );
	}

	WK.moreContentCall = moreContentCall;

	try { moreContentCall(); } catch ( e ) {}

}() );
