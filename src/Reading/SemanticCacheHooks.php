<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\DataModel\SemanticData;
use SMW\SQLStore\ChangeOp\ChangeOp;
use SMW\Store;

/** Use SMW's changed entities and dependency graph, not a second graph. */
final class SemanticCacheHooks {
	public static function onSMW__SQLStore__AfterDataUpdateComplete(
		Store $store, SemanticData $semanticData, ChangeOp $changeOp
	): bool {
		// A semantic update, including changed query results elsewhere, must
		// never queue a purge of another page's anonymous HTML snapshot.
		if (PageLocalCachePolicy::enabled()) { return true; }
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (!$config->get('WikidebatesSemanticCacheTracking')) {
			return true;
		}
		$title = $semanticData->getSubject()->getTitle();
		if (!$title) {
			return true;
		}
		$ids = self::changedIds($changeOp->getChangedEntityIdSummaryList());
		if ($ids === []) {
			return true;
		}
		// This hook runs INSIDE SMW's section transaction. Both the ledger
		// increment and its enqueue identity must describe that transaction.
		SemanticRevisionStore::record($store, $ids);
		if ($config->get('WikidebatesSemanticCacheEagerPurge')) {
			self::schedule($store, $title, $ids);
		}
		return true;
	}

	/** Moves can change query results without an ordinary property-table diff. */
	public static function onPageMoveComplete($old, $new, $user, $pageid, $redirid, $reason, $revision): bool {
		// MediaWiki handles the moved page; do not purge semantic dependents.
		if (PageLocalCachePolicy::enabled()) { return true; }
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (!$config->get('WikidebatesSemanticCacheTracking')) {
			return true;
		}
		$oldTitle = Title::newFromLinkTarget($old);
		$newTitle = Title::newFromLinkTarget($new);
		$store = SemanticServices::getInstance()->getStore();
		$objectIds = $store->getObjectIds();
		$affectedIds = [];
		foreach ([$oldTitle, $newTitle] as $title) {
			$subject = WikiPage::newFromTitle($title);
			$affectedIds[] = $objectIds->getId($subject);
			// Empty queries already depend on their properties, even before
			// newly matching result subjects acquire their own query links.
			foreach ($store->getSemanticData($subject)->getProperties() as $property) {
				if ($property instanceof Property) {
					$affectedIds[] = $objectIds->getId($property->getCanonicalDiWikiPage());
				}
			}
		}
		$ids = self::changedIds($affectedIds);
		if ($ids === []) {
			return true;
		}
		SemanticRevisionStore::record($store, $ids);
		if ($config->get('WikidebatesSemanticCacheEagerPurge')) {
			self::schedule($store, $oldTitle, $ids);
		}
		return true;
	}

	private static function schedule(Store $store, Title $title, array $ids): void {
		// Capture NOW, not in the deferred callback: a later change must
		// never share the enqueue identity of an older semantic revision.
		$revisions = SemanticPurgeState::revisions($store, $ids);
		sort($ids, SORT_NUMERIC);
		DeferredUpdates::addCallableUpdate(static function () use ($title, $ids, $revisions): void {
			$group = MediaWikiServices::getInstance()->getJobQueueGroup();
			foreach (array_chunk($ids, 128) as $chunk) {
				$group->push(new SemanticCachePurgeJob($title, [
					'dependencyIds' => $chunk,
					'cursor' => 0,
					'semanticRevision' => SemanticPurgeState::revisionToken($revisions, $chunk)
				]));
			}
		});
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
