<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Bounded, explicit recovery for an abandoned schema-3 semantic touch barrier.
 *
 * An observed page_touched alone is never proof of safe HTML: a cache might
 * have been regenerated before that SQL write. Always invalidate again, renew
 * the generation via CAS, and wait for the new deferred touch + final purge.
 *
 * No job or cron is spawned, and no cache is rendered by this class.
 */
final class SemanticTouchRecovery {
	public function __construct(private CachePreparationQueue $queue) {
	}

	/**
	 * @param array<string,mixed> $snapshot Read from the private journal.
	 * @return array{pageId:int,state:string,errorType?:string}
	 */
	public function recover(array $snapshot, int $minimumAgeSeconds = 600): array {
		if ($minimumAgeSeconds < 0 || $minimumAgeSeconds > 86400) {
			throw new \InvalidArgumentException('Invalid semantic recovery minimum age');
		}
		$pageId = $snapshot['pageId'] ?? null;
		$revision = $snapshot['revisionId'] ?? null;
		$expectedGeneration = $snapshot['generation'] ?? null;
		if (!is_int($pageId) || $pageId < 1 || !is_int($revision) || $revision < 1 ||
			!is_string($expectedGeneration) || !preg_match('/\A[a-f0-9]{32}\z/D', $expectedGeneration)) {
			throw new \InvalidArgumentException('Invalid semantic recovery snapshot');
		}
		$report = ['pageId' => $pageId, 'state' => 'not-blocked'];
		if (($snapshot['schema'] ?? null) !== 3 || ($snapshot['ready'] ?? null) !== false) {
			return $report;
		}

		try {
			$current = $this->queue->current($pageId);
			if (!$this->matches($snapshot, $current)) {
				$report['state'] = 'superseded';
				return $report;
			}
			// Do not compete with the callback of a job still running.
			if ($current['queuedAt'] > time() - $minimumAgeSeconds) {
				$report['state'] = 'too-recent';
				return $report;
			}

			$services = MediaWikiServices::getInstance();
			if ($services->getReadOnlyMode()->isReadOnly()) {
				$report['state'] = 'read-only';
				return $report;
			}
			$db = $services->getConnectionProvider()->getPrimaryDatabase();
			$row = $db->selectRow('page', ['page.*'], ['page_id' => $pageId], __METHOD__);
			if (!$row || (int)$row->page_latest !== $revision) {
				$report['state'] = 'missing-or-revised';
				return $report;
			}
			$title = Title::newFromRow($row);
			if (!$title || !$title->canExist() || $title->getArticleID() !== $pageId) {
				$report['state'] = 'missing-or-revised';
				return $report;
			}
			// The recovery is not a mechanism for publishing private page HTML.
			// The normal prewarm worker has its own access checks as well.
			if (!$services->getPermissionManager()->userCan(
				'read', $services->getUserFactory()->newAnonymous(), $title
			)) {
				$report['state'] = 'not-public';
				return $report;
			}

			$observedTouch = (string)$row->page_touched;
			$oldBarrier = $current['touchAfter'];
			$newBarrier = SemanticTouchBarrier::targetAfter(
				strcmp($observedTouch, $oldBarrier) > 0 ? $observedTouch : $oldBarrier,
				time()
			);
			// Once we have swapped the generation, *any* error below must leave
			// it blocked for another explicit attempt. Never revert this CAS.
			$newGeneration = $this->queue->retryBlockedInvalidation(
				$pageId, $revision, $expectedGeneration, $newBarrier
			);
			if ($newGeneration === null) {
				$report['state'] = 'superseded';
				return $report;
			}

			$report['state'] = 'retry';
			// Re-evict all independently generated outputs, not just page_touched.
			// This also handles content cached after the original HTML purge.
			FragmentService::store()->invalidate($title->getPrefixedDBkey());
			$page = $services->getWikiPageFactory()->newFromTitle($title);
			if (!$title->invalidateCache($newBarrier)) {
				throw new \RuntimeException('Cannot schedule recovery page_touched');
			}
			$services->getParserCache()->deleteOptionsKey($page);
			$htmlCache = $services->getHtmlCacheUpdater();
			SemanticPurgeScope::run(static function () use ($htmlCache, $title): void {
				$htmlCache->purgeTitleUrls([$title], $htmlCache::PURGE_NAIVE);
			});
			SemanticTouchBarrier::schedule($this->queue, [
				$pageId => [
					'title' => $title,
					'revisionId' => $revision,
					'generation' => $newGeneration,
					'touchAfter' => $newBarrier,
					'pageNamespace' => (int)$row->page_namespace,
					'pageTitle' => (string)$row->page_title,
					'requireAnonymousRead' => true
				]
			], $db, $htmlCache);

			// JobRunner normally performs these steps after job->run(). This
			// isolated maintenance command must do so before reporting success.
			$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
			DeferredUpdates::doUpdates();

			$after = $this->queue->current($pageId);
			if (!$after || $after['schema'] !== 3 ||
				!hash_equals($after['generation'], $newGeneration)) {
				$report['state'] = 'superseded';
			} elseif ($after['ready']) {
				$report['state'] = 'recovered';
			}
		} catch (\Throwable $e) {
			$report['state'] = 'retry';
			// No exception text, SQL, page title or HTTP body in reports.
			$report['errorType'] = get_class($e);
		}
		return $report;
	}

	private function matches(array $expected, ?array $actual): bool {
		return $actual !== null && $actual['schema'] === 3 &&
			$actual['ready'] === false &&
			$actual['revisionId'] === $expected['revisionId'] &&
			hash_equals($actual['generation'], $expected['generation']);
	}
}
