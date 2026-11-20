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

			// On a newly migrated page, the saved _ASK profile may not
			// exist yet. This includes both arguments and debate pages, not
			// only the former. Never trust an empty dependency graph for a
			// page whose source invokes a native semantic renderer.
			if (SemanticPageCacheHooks::isQueryPage($title, $page) &&
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
			MediaWikiServices::getInstance()->getParserCache()->deleteOptionsKey($page);
			return false;
		} catch (\Throwable $e) {
			FragmentService::log($e);
			// Never return a prebuilt fragment when dependency checks fail.
			return false;
		}
	}

}
