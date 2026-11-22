<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Small, testable state machine for one page from the persistent edit queue.
 * It never acknowledges a request unless all three cache operations report
 * safe completion, its generation is unchanged, and a primary-database
 * revision check still matches.
 */
final class CachePreparationCoordinator {
	public const PHASES = ['vector', 'minerva', 'reading'];

	public function __construct(private CachePreparationQueue $queue) {
	}

	/**
	 * @param array{schema:int,pageId:int,revisionId:int,generation?:string,ready?:bool} $record
	 * @param callable(int):?int $latestRevision Read current page_latest from PRIMARY.
	 * @param callable(string,int):array{ok:bool,status:string} $prepare Run one phase.
	 * @param callable(int):?string|null $publicIdentity PRIMARY/ACL fingerprint, null if unsafe.
	 * @return array<string,mixed>
	 */
	public function process(
		array $record, callable $latestRevision, callable $prepare, ?callable $publicIdentity = null
	): array {
		$pageId = $record['pageId'] ?? 0;
		$revision = $record['revisionId'] ?? 0;
		if (!is_int($pageId) || $pageId < 1 || !is_int($revision) || $revision < 1 ||
			!in_array($record['schema'] ?? null, [1, 2, 3], true) ||
			($record['schema'] >= 2 && (!is_string($record['generation'] ?? null) ||
				!preg_match('/\A[a-f0-9]{32}\z/D', $record['generation']))) ||
			($record['schema'] === 3 && !is_bool($record['ready'] ?? null))) {
			throw new \InvalidArgumentException('Invalid pending page revision');
		}
		$report = ['pageId' => $pageId, 'revisionId' => $revision, 'state' => 'pending',
			'phases' => []];
		try {
			if ($record['schema'] === 3 && !$record['ready']) {
				$report['state'] = 'awaiting-deferred-touch';
				return $report;
			}
			if (!$this->requestMatches($record)) {
				$report['state'] = 'superseded';
				return $report;
			}
			if (!$this->revisionMatches($pageId, $revision, $latestRevision)) {
				$report['state'] = 'revision-changed-or-deleted';
				return $report;
			}
			$baselineIdentity = $publicIdentity !== null ? $publicIdentity($pageId) : null;
			if ($publicIdentity !== null && (!is_string($baselineIdentity) ||
				$baselineIdentity === '')) {
				$report['state'] = 'unsafe-for-anonymous-cache';
				return $report;
			}
			foreach (self::PHASES as $phase) {
				// A new edit can be recorded while a previous renderer runs.
				if (!$this->requestMatches($record) ||
					!$this->revisionMatches($pageId, $revision, $latestRevision)) {
					$report['state'] = 'revision-changed-or-deleted';
					return $report;
				}
				if ($publicIdentity !== null) {
					$currentIdentity = $publicIdentity($pageId);
					if (!is_string($currentIdentity) || $currentIdentity === '') {
						$report['state'] = 'unsafe-for-anonymous-cache';
						return $report;
					}
					if (!hash_equals($baselineIdentity, $currentIdentity)) {
						$report['state'] = 'public-identity-changed';
						return $report;
					}
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
				if ($publicIdentity !== null) {
					$currentIdentity = $publicIdentity($pageId);
					if (!is_string($currentIdentity) || $currentIdentity === '') {
						$report['state'] = 'unsafe-for-anonymous-cache';
						return $report;
					}
					if (!hash_equals($baselineIdentity, $currentIdentity)) {
						$report['state'] = 'public-identity-changed';
						return $report;
					}
				}
			}
			if (!$this->requestMatches($record) ||
				!$this->revisionMatches($pageId, $revision, $latestRevision)) {
				$report['state'] = 'revision-changed-or-deleted';
				return $report;
			}
			if ($publicIdentity !== null) {
				$currentIdentity = $publicIdentity($pageId);
				if (!is_string($currentIdentity) || $currentIdentity === '') {
					$report['state'] = 'unsafe-for-anonymous-cache';
					return $report;
				}
				if (!hash_equals($baselineIdentity, $currentIdentity)) {
					$report['state'] = 'public-identity-changed';
					return $report;
				}
			}
			// This is the sole destructive queue operation and is both revision- and
			// generation-safe under the per-page exclusive lock.
			$report['state'] = $this->queue->acknowledge($pageId, $revision, $record['generation'] ?? null) ?
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
	 * A semantic invalidation can supersede a request without changing its
	 * revision. This check must be repeated before each phase and after the
	 * last render; acknowledge() performs its own locked final comparison.
	 */
	private function requestMatches(array $record): bool {
		$current = $this->queue->current($record['pageId']);
		if (!$current || $current['revisionId'] !== $record['revisionId'] ||
			$current['schema'] !== $record['schema']) {
			return false;
		}
		if ($record['schema'] === 1) {
			return !isset($record['generation']);
		}
		return hash_equals($current['generation'], $record['generation']) &&
			($current['schema'] !== 3 || ($record['ready'] && $current['ready']));
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
