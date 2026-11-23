<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Private, atomic, wiki-scoped parallel scan checkpoint.
 *
 * Caller holds CachePreparationQueue::tryLockWorker() for the entire run,
 * excluding the ordinary daily/queue coordinators. Workers independently
 * hold a lane lock, and all journal mutations are serialized here.
 *
 * The journal never acknowledges queue records, publishes HTML, or resets
 * the daily cursor. Only the controller may reset the legacy cursor after
 * the journal reports that every lane is exhausted.
 */
final class ParallelBackfillJournal {
	private string $directory;
	private string $path;
	private string $lockPath;

	public function __construct(CachePreparationQueue $queue, private string $wiki) {
		if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $wiki)) {
			throw new \InvalidArgumentException('Invalid backfill wiki');
		}
		$this->directory = $queue->parallelDirectory();
		$this->path = $this->directory . '/checkpoint.json';
		$this->lockPath = $this->directory . '/checkpoint.lock';
	}

	/** @return resource|false */
	public function tryLockLane(int $lane) {
		if ($lane < 0 || $lane >= 64) {
			throw new \InvalidArgumentException('Invalid backfill lane');
		}
		$path = $this->directory . '/lane-' . $lane . '.lock';
		if (is_link($path)) {
			throw new \RuntimeException('Symlinked parallel lane lock');
		}
		$handle = @fopen($path, 'c');
		if (!$handle || !@chmod($path, 0600)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot open private parallel lane lock');
		}
		if (!@flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return false;
		}
		return $handle;
	}

	/** @return array<string,mixed>|null */
	public function snapshot(): ?array {
		return $this->locked(false, fn() => $this->read());
	}

	/**
	 * Begin or resume the same bounded sweep. Repartitioning an unfinished
	 * sweep is forbidden: each saved lane cursor is tied to its original range.
	 *
	 * @return array<string,mixed>
	 */
	public function activate(
		int $startAfter, int $highWatermark, int $workers, string $cacheSignature,
		bool $newCycle = false
	): array {
		if (!preg_match('/\A[a-f0-9]{64}\z/D', $cacheSignature) ||
			$startAfter < 0 || $highWatermark < $startAfter ||
			$workers < 1 || $workers > 16) {
			throw new \InvalidArgumentException('Invalid parallel backfill bounds');
		}
		return $this->locked(true, function () use ($startAfter, $highWatermark, $workers, $cacheSignature, $newCycle) {
			$data = $this->read();
			if ($data !== null && $data['state'] !== 'complete') {
				if ($newCycle || $data['start_after'] !== $startAfter ||
					!hash_equals($data['cache_signature'], $cacheSignature) ||
					count($data['lanes']) !== $workers) {
					throw new \RuntimeException('Unfinished parallel scan must resume unchanged');
				}
				// A high-water mark is a fixed snapshot; new page IDs belong
				// to the next maintenance cycle or the direct-edit queue.
				$data['run_id'] = bin2hex(random_bytes(16));
				$data['state'] = 'running';
				$data['stop_requested'] = false;
				$data['desired_workers'] = min(2, $workers);
				$data['heartbeat_at'] = time();
				$data['cooldown_until'] = 0;
				$data['last_failure'] = null;
			} else {
				if ($data !== null && !$newCycle) {
					throw new \RuntimeException('Parallel cycle complete; --new-cycle required');
				}
				if ($highWatermark <= $startAfter) {
					throw new \RuntimeException('No page IDs above the starting cursor');
				}
				$data = [
					'version' => 1,
					'wiki' => $this->wiki,
					'start_after' => $startAfter,
					'high_watermark' => $highWatermark,
					'cache_signature' => $cacheSignature,
					'created_at' => time(),
					'run_id' => bin2hex(random_bytes(16)),
					'state' => 'running',
					'heartbeat_at' => time(),
					'desired_workers' => min(2, $workers),
					'stop_requested' => false,
					'cooldown_until' => 0,
					'lanes' => ParallelBackfillState::create($startAfter, $highWatermark, $workers)->lanes(),
					'metrics' => [
						'completed' => 0, 'skipped' => 0, 'queue_acknowledged' => 0,
						'cache_created' => 0, 'cache_reused' => 0,
						'rate_current' => 0.0, 'rate_average' => 0.0,
						'by_phase' => array_fill_keys(
							CachePreparationCoordinator::PHASES,
							['created' => 0, 'reused' => 0, 'skipped' => 0]
						)
					],
					'last_failure' => null
				];
			}
			$this->write($data);
			return $data;
		});
	}

	public function heartbeat(
		string $runId, int $desiredWorkers, float $currentRate = 0.0, float $averageRate = 0.0
	): void {
		$this->locked(true, function () use ($runId, $desiredWorkers, $currentRate, $averageRate) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot renew failed supervisor heartbeat');
			}
			if ($desiredWorkers < 0 || $desiredWorkers > count($data['lanes'])) {
				throw new \InvalidArgumentException('Invalid desired worker count');
			}
			if (!is_finite($currentRate) || !is_finite($averageRate) ||
				$currentRate < 0 || $averageRate < 0) {
				throw new \InvalidArgumentException('Invalid measured preparation throughput');
			}
			$data['heartbeat_at'] = time();
			$data['desired_workers'] = $desiredWorkers;
			$data['metrics']['rate_current'] = round($currentRate, 3);
			$data['metrics']['rate_average'] = round($averageRate, 3);
			$this->write($data);
		});
	}

	/**
	 * A transient HTTP throttle applies to EVERY lane, not only its origin.
	 * The supervisor temporarily reports zero permits while this barrier
	 * is active. No failed result is checkpointed.
	 */
	public function cooldown(string $runId, int $seconds): void {
		if ($seconds < 1 || $seconds > 120) {
			throw new \InvalidArgumentException('Invalid parallel HTTP retry cooldown');
		}
		$this->locked(true, function () use ($runId, $seconds) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot cool down inactive backfill');
			}
			$data['cooldown_until'] = max($data['cooldown_until'], time() + $seconds);
			$this->write($data);
		});
	}

	public function stop(): void {
		$this->locked(true, function () {
			$data = $this->read();
			if ($data !== null && $data['state'] === 'running') {
				$data['stop_requested'] = true;
				$this->write($data);
			}
		});
	}

	/**
	 * Called only after the real coordinator has confirmed a successful,
	 * final-identity-checked full page. A failed page is never checkpointed.
	 */
	public function completed(string $runId, int $lane, int $pageId, string $status, array $phases): void {
		$this->locked(true, function () use ($runId, $lane, $pageId, $status, $phases) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot checkpoint a failed run');
			}
			if (!in_array($status, ['complete', 'skipped-nonpublic', 'acknowledged'], true)) {
				throw new \InvalidArgumentException('Unsafe parallel completion');
			}
			$state = (new ParallelBackfillState($data['lanes']))->completed($lane, $pageId);
			$data['lanes'] = $state->lanes();
			$key = $status === 'skipped-nonpublic' ? 'skipped' : 'completed';
			$data['metrics'][$key]++;
			if ($status === 'acknowledged') {
				$data['metrics']['queue_acknowledged']++;
			}
			$this->recordPhases($data['metrics'], $phases);
			$this->write($data);
		});
	}

	/** Successful edit-queue work outside a scan lane does not advance the frontier. */
	public function acknowledgedQueue(string $runId, array $phases = []): void {
		$this->locked(true, function () use ($runId, $phases) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot update failed run statistics');
			}
			$data['metrics']['queue_acknowledged']++;
			$this->recordPhases($data['metrics'], $phases);
			$this->write($data);
		});
	}

	/** Exhaustion is only legal after the caller has queried the entire lane. */
	public function exhausted(string $runId, int $lane): void {
		$this->locked(true, function () use ($runId, $lane) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot exhaust a lane after failure');
			}
			$data['lanes'] = (new ParallelBackfillState($data['lanes']))->exhausted($lane)->lanes();
			$this->write($data);
		});
	}

	/**
	 * Use a bounded diagnostic from the last phase when possible, otherwise
	 * retain the safe coordinator state. Composite Minerva statuses contain
	 * delimiters and must never be replaced by an unhelpful generic label.
	 *
	 * @param array<string,mixed> $result
	 */
	public static function safeFailureReason(array $result): string {
		$phases = $result['phases'] ?? null;
		$lastPhase = is_array($phases) && $phases !== [] ?
			$phases[array_key_last($phases)] : null;
		foreach ([$lastPhase, $result['state'] ?? null] as $candidate) {
			if (is_string($candidate) &&
				preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $candidate)) {
				return $candidate;
			}
		}
		return 'invalid-outcome';
	}

	public function failed(string $runId, int $lane, int $pageId, string $reason): void {
		$this->locked(true, function () use ($runId, $lane, $pageId, $reason) {
			$data = $this->requireRun($runId);
			if ($data['state'] === 'failed') {
				// Preserve the first failure rather than masking its cause.
				return;
			}
			$data['state'] = 'failed';
			$data['stop_requested'] = true;
			// Never store a response body, URL, title or exception message.
			$data['last_failure'] = [
				'lane' => $lane, 'page_id' => $pageId,
				'reason' => preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $reason)
					? $reason : 'invalid-outcome',
				'at' => time()
			];
			$this->write($data);
		});
	}

	/**
	 * Only the controller calls this AFTER the legacy daily cursor was reset.
	 * A crash between reset and this write is harmless: the finished lanes
	 * survive and a resumed controller can repeat the idempotent reset.
	 */
	public function markComplete(string $runId): void {
		$this->locked(true, function () use ($runId) {
			$data = $this->requireRun($runId);
			if ($data['state'] !== 'running') {
				throw new \RuntimeException('Cannot complete failed backfill');
			}
			if (!(new ParallelBackfillState($data['lanes']))->finished()) {
				throw new \RuntimeException('Cannot complete parallel scan with unfinished lanes');
			}
			$data['state'] = 'complete';
			$data['stop_requested'] = true;
			$this->write($data);
		});
	}

	/**
	 * Account for cache writes separately for Vector, both Minerva variants,
	 * and Reading. Explicit policy exclusions remain skips, never creations.
	 */
	private function recordPhases(array &$metrics, array $phases): void {
		foreach ($phases as $phase => $phaseStatus) {
			if (!in_array($phase, CachePreparationCoordinator::PHASES, true) ||
				!is_string($phaseStatus)) {
				throw new \InvalidArgumentException('Invalid phase outcome');
			}
			$tokens = $phase === 'minerva' ? explode(';', $phaseStatus) : [$phaseStatus];
			foreach ($tokens as $token) {
				$state = str_contains($token, '=') ? substr($token, strpos($token, '=') + 1) : $token;
				$key = in_array($state, ['CREATED', 'MISS', 'created'], true) ? 'created' :
					(in_array($state, ['REUSED', 'HIT', 'LOCAL_HIT', 'already-valid'], true) ?
						'reused' : 'skipped');
				$metrics['by_phase'][$phase][$key]++;
				if ($key === 'created') { $metrics['cache_created']++; }
				if ($key === 'reused') { $metrics['cache_reused']++; }
			}
		}
	}

	/** Serialize state updates across all worker processes. */
	private function locked(bool $exclusive, callable $action): mixed {
		if (is_link($this->lockPath)) {
			throw new \RuntimeException('Symlinked parallel state lock');
		}
		$handle = @fopen($this->lockPath, 'c');
		if (!$handle || !@chmod($this->lockPath, 0600) ||
			!@flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot lock parallel backfill state');
		}
		try {
			return $action();
		} finally {
			@flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private function requireRun(string $runId): array {
		$data = $this->read();
		if ($data === null || !preg_match('/\A[a-f0-9]{32}\z/D', $runId) ||
			!hash_equals($data['run_id'], $runId) ||
			!in_array($data['state'], ['running', 'failed'], true)) {
			throw new \RuntimeException('Stale or inactive parallel backfill run');
		}
		return $data;
	}

	private function read(): ?array {
		if (is_link($this->path)) {
			throw new \RuntimeException('Symlinked parallel checkpoint');
		}
		if (!is_file($this->path)) {
			return null;
		}
		clearstatcache(true, $this->path);
		if (filesize($this->path) > 32768) {
			throw new \RuntimeException('Oversized parallel checkpoint');
		}
		$raw = @file_get_contents($this->path);
		$data = $raw === false ? null : json_decode($raw, true);
		if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
			($data['wiki'] ?? null) !== $this->wiki ||
			!is_int($data['start_after'] ?? null) ||
			!is_int($data['high_watermark'] ?? null) ||
			!is_string($data['cache_signature'] ?? null) ||
			!preg_match('/\A[a-f0-9]{64}\z/D', $data['cache_signature']) ||
			!is_int($data['heartbeat_at'] ?? null) ||
			!is_string($data['run_id'] ?? null) ||
			!preg_match('/\A[a-f0-9]{32}\z/D', $data['run_id']) ||
			!in_array($data['state'] ?? null, ['running', 'failed', 'complete'], true) ||
			!is_int($data['desired_workers'] ?? null) ||
			!is_bool($data['stop_requested'] ?? null) ||
			!is_int($data['cooldown_until'] ?? null) || $data['cooldown_until'] < 0 ||
			!is_array($data['metrics'] ?? null) ||
			!is_array($data['lanes'] ?? null)) {
			throw new \RuntimeException('Malformed parallel backfill checkpoint');
		}
		$state = new ParallelBackfillState($data['lanes']);
		if ($data['start_after'] !== $data['lanes'][0]['start'] ||
			$data['high_watermark'] !== $data['lanes'][array_key_last($data['lanes'])]['end'] ||
			$data['desired_workers'] < 0 || $data['desired_workers'] > count($data['lanes']) ||
			($data['state'] === 'complete' && !$state->finished())) {
			throw new \RuntimeException('Inconsistent parallel backfill bounds');
		}
		foreach (['completed', 'skipped', 'queue_acknowledged', 'cache_created', 'cache_reused'] as $key) {
			if (!is_int($data['metrics'][$key] ?? null) || $data['metrics'][$key] < 0) {
				throw new \RuntimeException('Invalid parallel backfill counter');
			}
		}
		foreach (['rate_current', 'rate_average'] as $rateName) {
			if (!is_float($data['metrics'][$rateName] ?? null) ||
				!is_finite($data['metrics'][$rateName]) || $data['metrics'][$rateName] < 0) {
				throw new \RuntimeException('Invalid parallel throughput counter');
			}
		}
		if (!is_array($data['metrics']['by_phase'] ?? null) ||
			array_keys($data['metrics']['by_phase']) !== CachePreparationCoordinator::PHASES) {
			throw new \RuntimeException('Malformed per-cache preparation counters');
		}
		foreach ($data['metrics']['by_phase'] as $counters) {
			if (!is_array($counters) ||
				array_keys($counters) !== ['created', 'reused', 'skipped']) {
				throw new \RuntimeException('Invalid per-cache counter schema');
			}
			foreach ($counters as $value) {
				if (!is_int($value) || $value < 0) {
					throw new \RuntimeException('Invalid per-cache counter value');
				}
			}
		}
		return $data;
	}

	private function write(array $data): void {
		if (is_link($this->path)) {
			throw new \RuntimeException('Symlinked parallel checkpoint');
		}
		$json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		$temp = @tempnam($this->directory, '.parallel-state-');
		if (!$temp || dirname($temp) !== $this->directory) {
			throw new \RuntimeException('Cannot allocate parallel checkpoint file');
		}
		try {
			$handle = @fopen($temp, 'wb');
			if (!$handle || !@chmod($temp, 0600)) {
				throw new \RuntimeException('Cannot write private parallel checkpoint');
			}
			try {
				if (fwrite($handle, $json) !== strlen($json) ||
					!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
					throw new \RuntimeException('Cannot flush parallel checkpoint');
				}
			} finally {
				fclose($handle);
			}
			if (!@rename($temp, $this->path)) {
				throw new \RuntimeException('Cannot atomically publish parallel checkpoint');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}
}
