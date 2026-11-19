<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

/**
 * Cache-hit guard: the SMW 7.3.1 ArticleViewHeader hook is skipped by
 * action=wikidebatesreading and by direct fragment-cache hits.
 */
final class SemanticCacheGuard {
	public static function safeForRead($title, $page): bool {
		try {
			if (!class_exists(QueryDependencyLinksStoreFactory::class)) {
				return false;
			}
			$services = SemanticServices::getInstance();
			$settings = $services->getSettings();
			if (!$settings->get('smwgEnabledQueryDependencyLinksStore') ||
				$settings->get('smwgQueryProfiler') === false) {
				return false;
			}

			$subject = WikiPage::newFromTitle($title);
			$store = $services->getStore();

			// During migration, previously parsed arguments may have no
			// stored _ASK profiles. Never cache them based on an empty graph.
			if (self::isArgument($page) &&
				$store->getPropertyValues($subject, new Property('_ASK')) === []) {
				return false;
			}

			$validator = (new QueryDependencyLinksStoreFactory())->newDependencyLinksValidator();
			if (!$validator->canCheckDependencies()) {
				return false;
			}
			if (!$validator->hasArchaicDependencies($subject)) {
				return true;
			}

			// SMW normally marks the parser cache dirty on ArticleViewHeader,
			// which the reading API skips. doPurge() invokes MediaWiki's
			// TitleSquidURLs path, purging the parser/file cache and our own
			// fragment generations (including MinervaFileCache).
			FragmentService::invalidate($title);
			$page->doPurge();
			return false;
		} catch (\Throwable $e) {
			FragmentService::log($e);
			// Never return a prebuilt fragment when dependency checks fail.
			return false;
		}
	}

	private static function isArgument($page): bool {
		$content = $page->getContent();
		if (!$content instanceof \TextContent) {
			return false;
		}
		$text = $content->getText();
		return preg_match('/^\s*\{\{\s*Argument\s*(?:[|}])/iu', $text) === 1;
	}
}
