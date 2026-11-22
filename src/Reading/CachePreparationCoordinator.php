<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Small, testable state machine for one page from the persistent edit queue.
 * It never acknowledges a revision unless all three cache operations report
 * safe completion and a primary-database revision check still matches.
 */
final class CachePreparationCoordinator {
	public const PHASES = ['vector', 'minerva', 'reading'];

	public function __construct(private CachePreparationQueue $queue) {
	}

	/**
	 * @param array{pageId:int,revisionId:int} $record
	 * @param callable(int):?int $latestRevision Read current page_latest from PRIMARY.
	 * @param callable(string,int):array{ok:bool,status:string} $prepare Run one phase.
	 * @return array<string,mixed>
	 */
	public function process(array $record, callable $latestRevision, callable $prepare): array {
		$pageId = $record['pageId'] ?? 0;
		$revision = $record['revisionId'] ?? 0;
		if (!is_int($pageId) || $pageId < 1 || !is_int($revision) || $revision < 1) {
			throw new \InvalidArgumentException('Invalid pending page revision');
		}
		$report = ['pageId' => $pageId, 'revisionId' => $revision, 'state' => 'pending',
			'phases' => []];
		try {
			if (($this->queue->current($pageId)['revisionId'] ?? null) !== $revision) {
				$report['state'] = 'superseded';
				return $report;
			}
			if (!$this->revisionMatches($pageId, $revision, $latestRevision)) {
				$report['state'] = 'revision-changed-or-deleted';
				return $report;
			}
			foreach (self::PHASES as $phase) {
				// A new edit can be recorded while a previous renderer runs.
				if (($this->queue->current($pageId)['revisionId'] ?? null) !== $revision ||
					!$this->revisionMatches($pageId, $revision, $latestRevision)) {
					$report['state'] = 'revision-changed-or-deleted';
					return $report;
				}
				$outcome = $prepare($phase, $pageId);
				if (!is_array($outcome) || !is_bool($outcome['ok'] ?? null) ||
					!is_string($outcome['status'] ?? null)) {
					throw new \RuntimeException('Malformed cache preparation outcome');
				}
				$report['phases'][$phase] = $outcome['status'];
				if (!$outcome['ok']) {
					$report['state'] = 'retry';
					return $report;
				}
				if (!$this->revisionMatches($pageId, $revision, $latestRevision)) {
					$report['state'] = 'revision-changed-or-deleted';
					return $report;
				}
			}
			if (($this->queue->current($pageId)['revisionId'] ?? null) !== $revision ||
				!$this->revisionMatches($pageId, $revision, $latestRevision)) {
				$report['state'] = 'revision-changed-or-deleted';
				return $report;
			}
			// This is the sole destructive queue operation and is revision-safe.
			$report['state'] = $this->queue->acknowledge($pageId, $revision) ?
				'acknowledged' : 'superseded';
		} catch (\Throwable $e) {
			// The record stays queued. Do not log exceptions or response bodies
			// containing potentially private HTTP/DB data.
			$report['state'] = 'retry';
			$report['errorType'] = get_class($e);
		}
		return $report;
	}

	/**
	 * An edit arriving without a successful PageSaveComplete callback must not
	 * lose its pending work. In that case advance the record to the actual
	 * primary-database revision and leave it for a subsequent worker pass.
	 */
	private function revisionMatches(int $pageId, int $expected, callable $latestRevision): bool {
		$actual = $latestRevision($pageId);
		if (!is_int($actual) || $actual < 1) { return false; }
		if ($actual > $expected) {
			$this->queue->enqueue($pageId, $actual);
		}
		return $actual === $expected;
	}
}
