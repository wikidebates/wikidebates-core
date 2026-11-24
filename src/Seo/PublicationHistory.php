<?php

namespace MediaWiki\Extension\Wikidebates\Seo;

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\Title;

/**
 * History-only changes can alter the first revision without changing
 * page_latest. Reuse MediaWiki's targeted purge (ParserCache and HTML),
 * including the existing TitleSquidURLs invalidators.
 *
 * These hooks do not run for ordinary page reads or ordinary edits.
 */
final class PublicationHistory {
	public static function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ): void {
		if ( $sRevCount > 0 ) {
			self::purge( $title );
		}
	}

	public static function onArticleMergeComplete( $sourceTitle, $destTitle ): void {
		self::purge( $sourceTitle );
		self::purge( $destTitle );
	}

	public static function onPageUndeleteComplete(
		ProperPageIdentity $page, $restorer, $reason, $restoredRev, $logEntry,
		$restoredRevisionCount, $created, $restoredPageIds
	): void {
		// Creating a page is handled by the normal core create/update path.
		// Restoring older revisions into an existing page may skip it.
		if ( !$created && $restoredRevisionCount > 0 ) {
			self::purge( Title::newFromPageIdentity( $page ) );
		}
	}

	private static function purge( Title $title ): void {
		if ( $title->canExist() && $title->isContentPage() ) {
			MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->doPurge();
		}
	}
}
