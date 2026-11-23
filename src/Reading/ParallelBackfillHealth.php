<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Read-only classification of fatal or unresponsive parallel supervisors.
 * A page error ledger may be empty even when the entire backfill has failed.
 */
final class ParallelBackfillHealth {
	/**
	 * EN is expected after FR. Detect a missing EN journal only once FR has
	 * finished and its supervisor had ample time to launch the next command.
	 * No alert is generated while FR itself is still filling.
	 *
	 * @return array<string,int|string>|null
	 */
	public static function missingNextWiki(
		?array $previousCheckpoint, ?int $now = null, int $graceSeconds = 3600
	): ?array {
		if ($graceSeconds < 900 || $graceSeconds > 86400) {
			throw new \InvalidArgumentException('Invalid next-backfill grace period');
		}
		if ($previousCheckpoint === null ||
			($previousCheckpoint['state'] ?? null) !== 'complete') {
			return null;
		}
		$runId = $previousCheckpoint['run_id'] ?? null;
		$lastHeartbeat = $previousCheckpoint['heartbeat_at'] ?? null;
		if (!is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $runId) ||
			!is_int($lastHeartbeat)) {
			throw new \InvalidArgumentException('Invalid previous backfill checkpoint');
		}
		$now ??= time();
		if ($now - $lastHeartbeat <= $graceSeconds) {
			return null;
		}
		return [
			'type' => 'next-backfill-missing',
			'reason' => 'checkpoint-not-created',
			'lane' => -1,
			'page_id' => 0,
			'at' => $lastHeartbeat,
			'key' => hash('sha256', $runId . ':next-backfill-missing')
		];
	}

	/** @return array<string,int|string>|null Safe, bounded incident metadata. */
	public static function incident(
		?array $checkpoint, ?int $now = null, int $staleAfterSeconds = 900
	): ?array {
		if ($staleAfterSeconds < 60 || $staleAfterSeconds > 86400) {
			throw new \InvalidArgumentException('Invalid supervisor heartbeat threshold');
		}
		if ($checkpoint === null || ($checkpoint['state'] ?? null) === 'complete') {
			return null;
		}
		$runId = $checkpoint['run_id'] ?? null;
		if (!is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $runId)) {
			throw new \InvalidArgumentException('Invalid supervisor session');
		}
		if ($checkpoint['state'] === 'failed') {
			$failure = $checkpoint['last_failure'] ?? null;
			$failure = is_array($failure) ? $failure : [];
			$reason = $failure['reason'] ?? 'unknown-supervisor-failure';
			if (!is_string($reason) ||
				!preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $reason)) {
				$reason = 'invalid-outcome';
			}
			$at = is_int($failure['at'] ?? null) ? $failure['at'] : 0;
			return [
				'type' => 'supervisor-failed',
				'reason' => $reason,
				'lane' => is_int($failure['lane'] ?? null) ? $failure['lane'] : -1,
				'page_id' => is_int($failure['page_id'] ?? null) ?
					$failure['page_id'] : 0,
				'at' => max(0, $at),
				// Stable across hourly checks, distinct for a new session.
				'key' => hash('sha256', $runId . ':failed:' . $at . ':' . $reason)
			];
		}
		if ($checkpoint['state'] !== 'running' ||
			!is_int($checkpoint['heartbeat_at'] ?? null)) {
			throw new \InvalidArgumentException('Invalid supervisor checkpoint');
		}
		$now ??= time();
		if ($now - $checkpoint['heartbeat_at'] <= $staleAfterSeconds) {
			return null;
		}
		return [
			'type' => 'supervisor-unresponsive',
			'reason' => 'heartbeat-stale',
			'lane' => -1,
			'page_id' => 0,
			'at' => $checkpoint['heartbeat_at'],
			'key' => hash('sha256', $runId . ':unresponsive')
		];
	}
}
