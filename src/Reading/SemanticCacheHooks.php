<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
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
