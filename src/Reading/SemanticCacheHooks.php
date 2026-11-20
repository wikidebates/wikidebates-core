<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\DataModel\SemanticData;
use SMW\SQLStore\ChangeOp\ChangeOp;
use SMW\Store;

/**
 * Build the invalidation set from SMW's own changed entity IDs and query
 * dependency links, not from a second, Wikidebates-specific relation graph.
 */
final class SemanticCacheHooks {
	public static function onSMW__SQLStore__AfterDataUpdateComplete(
		Store $store, SemanticData $semanticData, ChangeOp $changeOp
	): bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (!$config->get('WikidebatesSemanticCacheTracking')) {
			return true;
		}
		$subject = $semanticData->getSubject();
		$title = $subject->getTitle();
		if (!$title) {
			return true;
		}
		$ids = self::changedIds($changeOp->getChangedEntityIdSummaryList());
		if ($ids === []) {
			return true;
		}

		// SMW 7.3.1 invokes this hook INSIDE the SQLStore section
		// transaction, before endSectionTransaction(). Write the durable
		// revision ledger here so a concurrent reader cannot observe new
		// semantic data with an old watermark. Jobs remain asynchronous.
		SemanticRevisionStore::record($store, $ids);

		// The SQLStore callback can run inside a transaction. Queue jobs only
		// after the request's transaction has finished, never purge against
		// uncommitted semantic results.
		DeferredUpdates::addCallableUpdate(static function () use ($title, $ids): void {
			$group = MediaWikiServices::getInstance()->getJobQueueGroup();
			foreach (array_chunk($ids, 128) as $chunk) {
				$group->push(new SemanticCachePurgeJob($title, ['dependencyIds' => $chunk, 'cursor' => 0]));
			}
		});
		return true;
	}


	/**
	 * A page move is handled partly by SMW's PageMoveComplete hook and may
	 * change a query's result subjects without a corresponding table diff
	 * reaching AfterDataUpdateComplete. Include both the old and new IDs:
	 * stored query links can still refer to the old subject at move time.
	 *
	 * This is intentionally guarded by the same opt-in as normal updates.
	 */
	public static function onPageMoveComplete($old, $new, $user, $pageid, $redirid, $reason, $revision): bool {
		if (!MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesSemanticCacheTracking')) {
			return true;
		}

		$oldTitle = Title::newFromLinkTarget($old);
		$newTitle = Title::newFromLinkTarget($new);
		$store = SemanticServices::getInstance()->getStore();
		$objectIds = $store->getObjectIds();
		$ids = self::changedIds([
			$objectIds->getId(WikiPage::newFromTitle($oldTitle)),
			$objectIds->getId(WikiPage::newFromTitle($newTitle))
		]);
		if ($ids === []) {
			return true;
		}

		// A move is a semantic identity mutation even if no property values
		// differ. A revision fence makes old fragments unsafe immediately.
		SemanticRevisionStore::record($store, $ids);
		DeferredUpdates::addCallableUpdate(static function () use ($oldTitle, $ids): void {
			MediaWikiServices::getInstance()->getJobQueueGroup()->push(
				new SemanticCachePurgeJob($oldTitle, ['dependencyIds' => $ids, 'cursor' => 0])
			);
		});
		return true;
	}

	/** @return int[] */
	public static function changedIds(array $ids): array {
		$unique = [];
		foreach ($ids as $id) {
			if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) {
				$unique[(int)$id] = (int)$id;
			}
		}
		return array_values($unique);
	}
}
