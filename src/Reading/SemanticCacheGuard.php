<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

/**
 * Cache-hit guard: the SMW 7.3.1 ArticleViewHeader hook is skipped by
 * action=wikidebatesreading and by direct fragment-cache hits.
 *
 * The durable semantic revision fingerprint (not smw_touched) validates
 * these hits. Returning it by reference also avoids querying SQL twice.
 */
final class SemanticCacheGuard {
	public static function safeForRead($title, $page, ?string &$semanticRevision = null): bool {
		$semanticRevision = null;
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

			// An unparsed argument may have no _ASK profile yet. Such
			// an empty graph is unsafe if native argument queries execute.
			// A debate-only render writes semantic properties, but does
			// not itself execute semantic queries; its existing cache
			// must remain available when no _ASK profile exists.
			// Do not read that property twice via isQueryPage() on every hit.
			// Only inspect the source when no saved profile exists yet.
			$profiles = $store->getPropertyValues($subject, new Property('_ASK'));
			if ($profiles === [] && SemanticPageCacheHooks::isQueryPage($title, $page)) {
				return false;
			}

			$validator = (new QueryDependencyLinksStoreFactory())->newDependencyLinksValidator();
			if (!$validator->canCheckDependencies()) {
				return false;
			}

			// SMW's strict smw_touched comparison may reject a fresh parse:
			// query/dependency timestamps can advance without changed data.
			// The exact SQL dependency graph and transactionally incremented
			// entity revisions provide a stronger, stable freshness boundary.
			// Missing query links or SQL errors throw and fail closed.
			$semanticRevision = SemanticRevisionStore::fingerprint($store, $subject);
			return true;
		} catch (\Throwable $e) {
			FragmentService::log($e);
			return false;
		}
	}
}
