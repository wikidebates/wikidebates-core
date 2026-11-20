<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\SQLStore;

/**
 * Purges the pages whose registered SMW queries depend on changed entities.
 * Work is paginated by a monotonically increasing query-subobject ID, so
 * query registrations changing during a purge cannot invalidate an offset.
 */
final class SemanticCachePurgeJob extends \Job {
	private const BATCH_SIZE = 150;

	public function __construct($title, array $params = []) {
		// A canonical set makes exactly equivalent jobs deduplicable without
		// dropping distinct chunks or distinct cursor continuations.
		if (isset($params['dependencyIds'])) {
			$params['dependencyIds'] = SemanticCacheHooks::changedIds((array)$params['dependencyIds']);
			sort($params['dependencyIds'], SORT_NUMERIC);
		}
		parent::__construct('wikidebatesSemanticCachePurge', $title, $params);
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (!$config->get('WikidebatesSemanticCacheTracking') ||
			!$config->get('WikidebatesSemanticCacheEagerPurge')) {
			return true;
		}
		$ids = SemanticCacheHooks::changedIds(
			(array)($this->params['dependencyIds'] ?? [])
		);
		if ($ids === []) {
			return true;
		}
		$cursor = max(0, (int)($this->params['cursor'] ?? 0));

		try {
			$store = SemanticServices::getInstance()->getStore();
			if (!$store instanceof SQLStore) {
				throw new \RuntimeException('SMW SQLStore is required for targeted dependency invalidation');
			}
			$database = $store->getConnection('mw.db');
			// A newly committed semantic update might not yet be visible
			// on a replica; do not drop dependent purges due to lag.
			$rows = $database->newPrimarySelectQueryBuilder()
				->select('s_id')
				->distinct()
				->from(SQLStore::QUERY_LINKS_TABLE)
				->where(['o_id' => $ids, 's_id > ' . $cursor])
				->orderBy('s_id')
				->limit(self::BATCH_SIZE)
				->caller(__METHOD__)
				->fetchResultSet();

			$queryIds = [];
			foreach ($rows as $row) {
				$queryIds[] = (int)$row->s_id;
			}
			if ($queryIds === []) {
				return true;
			}

			$targets = $store->getObjectIds()->getDataItemsFromList($queryIds);
			$titles = [];
			foreach ($targets as $target) {
				if (!$target instanceof SemanticWikiPage) {
					continue;
				}
				$title = $target->getTitle();
				if ($title !== null && $title->canExist() && $title->exists()) {
					$titles[$title->getPrefixedDBkey()] = $title;
				}
			}

			// Requeue before purging: if a purge triggers a reparse, it may
			// change the query-link table. A stable ID cursor avoids skips.
			if (count($queryIds) === self::BATCH_SIZE) {
				MediaWikiServices::getInstance()->getJobQueueGroup()->push(
					new self($this->title, [
						'dependencyIds' => $ids,
						'cursor' => max($queryIds)
					])
				);
			}

			$factory = MediaWikiServices::getInstance()->getWikiPageFactory();
			foreach ($titles as $title) {
				// Invalidate HTML/file/CDN caches without ArticlePurge: SMW's
				// ArticlePurge hook otherwise marks this page for a forced semantic
				// rewrite at its next parse, creating a purge-refresh feedback loop.
				// HTMLCacheUpdater still fires TitleSquidURLs (reading fragments
				// and MinervaFileCache). Do not mutate semantic data here.
				FragmentService::invalidate($title);
				$page = $factory->newFromTitle($title);
				$title->invalidateCache();
				$htmlCache = MediaWikiServices::getInstance()->getHtmlCacheUpdater();
				$htmlCache->purgeTitleUrls($title, $htmlCache::PURGE_PRESEND);
				// page_touched is second-resolution; evict the parser cache key
				// even for multiple purges in the same second.
				MediaWikiServices::getInstance()->getParserCache()->deleteOptionsKey($page);
			}
			return true;
		} catch (\Throwable $e) {
			LoggerFactory::getInstance('WikidebatesSemanticCache')->error(
				'Failed to purge semantic dependents ({type})',
				['type' => get_class($e)]
			);
			$this->setLastError(get_class($e));
			return false;
		}
	}
}
