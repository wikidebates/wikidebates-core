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
	 * Explicit parser cache policy: the native Vector renderer has not
	 * written HTML and each requested Minerva variant was refused because
	 * ParserOutput's cache expiry is zero (__NOCACHE__ is one cause).
	 *
	 * This is intentionally stricter than any BYPASS: it must never silently
	 * reclassify a storage failure, a stale page, or an access-control error.
	 * It detects the real parser outcome instead of searching raw wikitext.
	 *
	 * @param array<string,string> $phases
	 */
	public static function hasExplicitNoCachePolicy(array $phases): bool {
		if (!in_array($phases['vector'] ?? null,
			['SKIPPED_NONCONTENT', 'SKIPPED_CACHE_POLICY'], true)) {
			return false;
		}
		$mobile = $phases['minerva'] ?? null;
		if (!is_string($mobile) || $mobile === '') {
			return false;
		}
		if ($mobile !== 'BYPASS-parser-expiry') {
			$seen = [];
			foreach (explode(';', $mobile) as $device) {
				if (!preg_match('/\A(android|iphone)=BYPASS-parser-expiry\z/D', $device, $match) ||
					isset($seen[$match[1]])) {
					return false;
				}
				$seen[$match[1]] = true;
			}
			if ($seen === []) { return false; }
		}
		// A fragment actually published on a previous phase would make a
		// blanket "no cache was created" assertion unsafe.
		return !isset($phases['reading']) ||
			$phases['reading'] === 'skipped-cache-policy';
	}

	/**
	 * A confirmed no-cache render has produced no anonymous HTML to publish.
	 * After a concurrent page_touched change, allow the scan to skip it only
	 * if two fresh PRIMARY reads still find the same nonredirect revision and
	 * the anonymous visibility guard still accepts the page. A newer revision
	 * must not inherit a previous revision's cache-policy exclusion.
	 *
	 * @param array<string,mixed> $result
	 * @param callable(int):?array{revisionId:int,isRedirect:bool} $pageSnapshot
	 * @param callable(int):?string $publicIdentity
	 */
	public static function canCheckpointNoCacheRace(
		array $result, callable $pageSnapshot, callable $publicIdentity
	): bool {
		if (!self::isExplicitNoCacheRace($result) ||
			!is_int($result['pageId'] ?? null) || $result['pageId'] < 1) {
			return false;
		}
		$id = $result['pageId'];
		$revision = $result['revisionId'];
		$before = $pageSnapshot($id);
		$public = $publicIdentity($id);
		$after = $pageSnapshot($id);
		return is_array($before) && is_array($after) &&
			($before['revisionId'] ?? null) === $revision &&
			($after['revisionId'] ?? null) === $revision &&
			($before['isRedirect'] ?? null) === false &&
			($after['isRedirect'] ?? null) === false &&
			is_string($public) && $public !== '';
	}

	/** @param array<string,mixed> $result */
	public static function isExplicitNoCacheRace(array $result): bool {
		return in_array($result['state'] ?? null, [
			'retry-page-changed', 'public-identity-changed'
		], true) &&
			is_int($result['revisionId'] ?? null) &&
			($result['revisionId'] ?? 0) > 0 &&
			is_array($result['phases'] ?? null) &&
			self::hasExplicitNoCachePolicy($result['phases']);
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
				if ($phase === 'reading' && self::hasExplicitNoCachePolicy($report['phases'])) {
					// A zero-expiry parser response cannot supply a durable
					// Reading fragment either. Do not render it just to retry.
					$report['phases']['reading'] = 'skipped-cache-policy';
					continue;
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
	 * Prepare a page discovered by the background cursor rather than the edit queue.
	 * The cursor advances only after a stable primary revision and public identity
	 * have been checked before and after every cache operation.
	 *
	 * @param callable(int):?int $latestRevision
	 * @param callable(string,int):array{ok:bool,status:string} $prepare
	 * @param callable(int):?string $publicIdentity
	 * @return array<string,mixed>
	 */
	public function processUnqueued(
		int $pageId, int $revision, callable $latestRevision, callable $prepare,
		callable $publicIdentity
	): array {
		if ($pageId < 1 || $revision < 1) {
			throw new \InvalidArgumentException('Invalid page or revision for cache sweep');
		}
		$report = ['pageId' => $pageId, 'revisionId' => $revision,
			'state' => 'pending', 'phases' => []];
		try {
			$baselineIdentity = $publicIdentity($pageId);
			if ($baselineIdentity === null) {
				// Permanently ineligible pages must not trap a resumable cursor.
				$report['state'] = 'skipped-nonpublic';
				return $report;
			}
			if (!is_string($baselineIdentity) || $baselineIdentity === '') {
				throw new \RuntimeException('Unexpected public page identity');
			}
			foreach (self::PHASES as $phase) {
				if ($latestRevision($pageId) !== $revision ||
					$publicIdentity($pageId) !== $baselineIdentity) {
					$report['state'] = 'retry-page-changed';
					return $report;
				}
				if ($phase === 'reading' && self::hasExplicitNoCachePolicy($report['phases'])) {
					// A zero-expiry parser response cannot supply a durable
					// Reading fragment either. Do not render it just to retry.
					$report['phases']['reading'] = 'skipped-cache-policy';
					continue;
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
				if ($latestRevision($pageId) !== $revision ||
					$publicIdentity($pageId) !== $baselineIdentity) {
					$report['state'] = 'retry-page-changed';
					return $report;
				}
			}
			// The Reading no-op must pass the same final identity checks as
			// the real renderer before a normal no-cache skip is checkpointed.
			if ($latestRevision($pageId) !== $revision ||
				$publicIdentity($pageId) !== $baselineIdentity) {
				$report['state'] = 'retry-page-changed';
				return $report;
			}
			$report['state'] = self::hasExplicitNoCachePolicy($report['phases']) ?
				'skipped-cache-policy' : 'complete';
		} catch (\Throwable $e) {
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
