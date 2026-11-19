<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;
use SMW\SQLStore\SQLStore;

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
			if (!$validator->hasArchaicDependencies($subject) &&
				!self::hasAmbiguousTimestampDependencies($store, $subject)) {
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

	/**
	 * SMW 7.3.1's validator uses a strict 'dependency.smw_touched > query.smw_touched'
	 * comparison. Both SQL timestamps have SECOND resolution. An edit committed
	 * within the same second as a query may therefore return stale HTML until
	 * the eventual purge job runs (or forever if that job fails).
	 *
	 * The embedding page itself is an implicit SMW dependency: its new revision
	 * is already part of the fragment identity, so exclude only that single ID
	 * from equality checks (not other subjects or referenced properties).
	 * Treat equality and missing query-link registrations as ambiguous and
	 * force a cache-free parse instead. This
	 * deliberately errs on the side of extra work until SMW re-registers the
	 * query at a later timestamp. It does not modify SMW's own database rows.
	 */
	private static function hasAmbiguousTimestampDependencies($store, WikiPage $subject): bool {
		if (!$store instanceof SQLStore) {
			throw new \RuntimeException('Query freshness requires the SMW SQLStore');
		}
		$lookup = $store->getPropertyTableInfoFetcher();
		$tableId = $lookup->findTableIdForProperty(new Property('_ASK'));
		$tables = $store->getPropertyTables();
		if (!isset($tables[$tableId])) {
			throw new \RuntimeException('SMW _ASK profile table is missing');
		}

		$db = $store->getConnection('mw.db');
		$row = $db->newSelectQueryBuilder()
			->select('dependency.smw_id')
			->from($tables[$tableId]->getName(), 'ask_profile')
			->join(SQLStore::ID_TABLE, 'page', ['ask_profile.s_id=page.smw_id'])
			->join(SQLStore::ID_TABLE, 'query', ['ask_profile.o_id=query.smw_id'])
			->leftJoin(SQLStore::QUERY_LINKS_TABLE, 'links', ['links.s_id=query.smw_id'])
			->leftJoin(SQLStore::ID_TABLE, 'dependency', ['links.o_id=dependency.smw_id'])
			->where([
				'page.smw_hash' => $db->escape_bytea($subject->getSha1()),
				$db->expr('page.smw_iw', '!=', SMW_SQL3_SMWIW_OUTDATED),
				$db->expr('page.smw_iw', '!=', SMW_SQL3_SMWDELETEIW),
				'(links.s_id IS NULL OR dependency.smw_id IS NULL OR (dependency.smw_id != page.smw_id AND dependency.smw_touched >= query.smw_touched))'
			])
			->limit(1)
			->caller(__METHOD__)
			->fetchRow();

		return $row !== false;
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
