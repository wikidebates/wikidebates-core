<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Safely pass an isolated daily-sweep failure to the durable edit queue.
 * The caller holds the per-wiki worker lock and advances its cursor only
 * after this method confirms that an eligible retry was persisted.
 */
final class DailyCacheDeferral {
	public function __construct(
		private CachePreparationQueue $queue,
		private ParallelBackfillFailures $failures
	) {
	}

	/**
	 * @param callable(int):?int $latestRevision Read page_latest from PRIMARY.
	 * @return bool Whether the caller may checkpoint this deferred page.
	 */
	public function enqueue(int $pageId, callable $latestRevision, string $reason): bool {
		$build = $this->queue->lockBuildPage($pageId);
		try {
			$revision = $latestRevision($pageId);
			if (!is_int($revision) || $revision < 1) {
				// A stale replica row or missing PRIMARY revision must not
				// authorize advancing past a page without a recovery record.
				return false;
			}
			$record = $this->queue->current($pageId);
			if ($record !== null && $record['schema'] === 3) {
				// A semantic touch barrier cannot be replaced by a direct-edit
				// record or consumed by the queue-only cron.
				return false;
			}
			// enqueue() refuses stale revisions and preserves newer edits.
			$this->queue->enqueue($pageId, $revision);
			$queued = $this->queue->current($pageId);
			if ($queued === null || $queued['schema'] === 3 ||
				$queued['revisionId'] < $revision) {
				return false;
			}
			$this->failures->record($pageId, $queued['revisionId'], $reason);
			return true;
		} finally {
			fclose($build);
		}
	}
}
