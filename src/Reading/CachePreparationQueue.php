<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * An opt-in, filesystem-backed queue of pages needing cache preparation.
 *
 * A fresh random generation identifies each new edit or indirect semantic
 * invalidation, even when page_latest has not changed. A worker must acknowledge
 * both revision AND generation under the per-page lock; late events cannot be
 * lost while HTML is rendered. No jobs or cache files are touched here.
 */
final class CachePreparationQueue {
	private string $root;

	public function __construct(string $directory, string $wikiId) {
		if ($directory === '' || $directory[0] !== '/' || $wikiId === '') {
			throw new \InvalidArgumentException('An absolute private directory and wiki ID are required');
		}
		$real = realpath($directory);
		if ($real === false || is_link($directory) || !is_dir($real) || !is_writable($real) ||
			(fileperms($real) & 0077) !== 0) {
			throw new \RuntimeException('Preparation queue root must exist and be private');
		}
		foreach ([defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : '', $_SERVER['DOCUMENT_ROOT'] ?? ''] as $webRoot) {
			$webRoot = $webRoot === '' ? false : realpath($webRoot);
			if ($webRoot && ($real === $webRoot || str_starts_with($real . '/', rtrim($webRoot, '/') . '/'))) {
				throw new \RuntimeException('Preparation queue cannot be inside a web root');
			}
		}
		$this->root = rtrim($real, '/') . '/' . hash('sha256', $wikiId);
	}

	private function ensureDirectory(): void {
		if (!is_dir($this->root) && !@mkdir($this->root, 0700) && !is_dir($this->root)) {
			throw new \RuntimeException('Cannot create wiki preparation queue directory');
		}
		if (is_link($this->root) || (fileperms($this->root) & 0077) !== 0) {
			throw new \RuntimeException('Preparation queue directory is not private');
		}
		$locks = $this->root . '/locks';
		if (!is_dir($locks) && !@mkdir($locks, 0700) && !is_dir($locks)) {
			throw new \RuntimeException('Cannot create durable queue lock directory');
		}
		if (is_link($locks) || (fileperms($locks) & 0077) !== 0) {
			throw new \RuntimeException('Preparation queue locks are not private');
		}
	}


	/**
	 * Only one active coordinated worker per wiki. This lock is independent
	 * of page locks and is never removed while a process may still hold it.
	 * @return resource|false
	 */
	public function tryLockWorker() {
		$this->ensureDirectory();
		$path = $this->root . '/locks/preparation-worker.lock';
		if (is_link($path)) { throw new \RuntimeException('Refusing a symlinked worker lock'); }
		$handle = @fopen($path, 'c');
		if (!$handle || !@chmod($path, 0600)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot open a private preparation worker lock');
		}
		if (!@flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return false;
		}
		return $handle;
	}

	private function checkId(int $id): void {
		if ($id < 1) {
			throw new \InvalidArgumentException('Expected a positive page or revision ID');
		}
	}

	private function recordPath(int $pageId): string {
		return $this->root . '/' . $pageId . '.json';
	}

	private function lock(int $pageId, int $mode) {
		$this->ensureDirectory();
		$path = $this->root . '/locks/' . $pageId . '.lock';
		if (is_link($path)) {
			throw new \RuntimeException('Refusing a symlinked queue lock');
		}
		$handle = @fopen($path, 'c');
		if (!$handle || !@chmod($path, 0600) || !@flock($handle, $mode)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot lock preparation queue record');
		}
		return $handle;
	}

	/** Called only while holding the corresponding page lock. */
	private function readRecord(int $pageId): ?array {
		$path = $this->recordPath($pageId);
		if (is_link($path)) { throw new \RuntimeException('Refusing a symlinked preparation record'); }
		if (!is_file($path)) { return null; }
		clearstatcache(true, $path);
		if (filesize($path) > 4096) { throw new \RuntimeException('Preparation record is too large'); }
		$data = @file_get_contents($path);
		if ($data === false) { throw new \RuntimeException('Cannot read preparation record'); }
		$record = json_decode($data, true);
		$schema = $record['schema'] ?? null;
		if (!is_array($record) || !in_array($schema, [1, 2, 3], true) ||
			($record['pageId'] ?? null) !== $pageId ||
			!is_int($record['revisionId'] ?? null) || $record['revisionId'] < 1 ||
			!is_int($record['queuedAt'] ?? null) || $record['queuedAt'] < 0 ||
			($schema === 1 && array_key_exists('generation', $record)) ||
			($schema === 2 && (!is_string($record['generation'] ?? null) ||
				!preg_match('/\A[a-f0-9]{32}\z/D', $record['generation']))) ||
			($schema === 3 && (!is_string($record['generation'] ?? null) ||
				!preg_match('/\A[a-f0-9]{32}\z/D', $record['generation']) ||
				!is_string($record['touchAfter'] ?? null) ||
				!preg_match('/\A[0-9]{14}\z/D', $record['touchAfter']) ||
				!is_bool($record['ready'] ?? null)))) {
			throw new \RuntimeException('Invalid preparation record');
		}
		return $record;
	}

	/**
	 * Enqueue the latest known edit without allowing a late older hook to
	 * overwrite a newer revision. Returns false for duplicate or stale events.
	 */
	public function enqueue(int $pageId, int $revisionId): bool {
		$this->checkId($pageId);
		$this->checkId($revisionId);
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$current = $this->readRecord($pageId);
			if ($current && $current['revisionId'] >= $revisionId) { return false; }
			$this->publishRecord($pageId, $revisionId);
			return true;
		} finally { fclose($lock); }
	}


	/**
	 * A semantic dependency change may leave page_latest unchanged while
	 * Title::invalidateCache's PRESEND database update is still queued.
	 * Write schema-3 records in a deliberately unready state. A second
	 * deferred step will prove the primary DB touch and reopen this request.
	 *
	 * @return string The exact generation token for the deferred callback.
	 */
	public function enqueueInvalidation(int $pageId, int $revisionId, string $touchAfter): string {
		$this->checkId($pageId);
		$this->checkId($revisionId);
		if (!preg_match('/\A[0-9]{14}\z/D', $touchAfter)) {
			throw new \InvalidArgumentException('Expected a MediaWiki UTC touch timestamp');
		}
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$current = $this->readRecord($pageId);
			$record = [
				'schema' => 3, 'pageId' => $pageId,
				'revisionId' => max($revisionId, $current['revisionId'] ?? 0),
				'generation' => bin2hex(random_bytes(16)), 'queuedAt' => time(),
				'touchAfter' => $touchAfter, 'ready' => false
			];
			$this->writeRecord($pageId, $record);
			return $record['generation'];
		} finally { fclose($lock); }
	}


	/**
	 * Manual recovery must never bless an abandoned semantic generation.
	 * Instead, replace that exact blocked request with a fresh generation and
	 * a strictly newer touch barrier. Concurrent edits, SMW jobs or callbacks
	 * make the compare-and-swap fail rather than dropping newer work.
	 *
	 * The caller must then repeat parser/fragment/HTML invalidation, schedule a
	 * new deferred touch and verify the post-commit result. This method alone
	 * never authorizes cache preparation.
	 *
	 * @return string|null Fresh generation, or null if the request changed.
	 */
	public function retryBlockedInvalidation(
		int $pageId, int $expectedRevisionId, string $expectedGeneration, string $touchAfter
	): ?string {
		$this->checkId($pageId);
		$this->checkId($expectedRevisionId);
		if (!preg_match('/\A[a-f0-9]{32}\z/D', $expectedGeneration) ||
			!preg_match('/\A[0-9]{14}\z/D', $touchAfter)) {
			throw new \InvalidArgumentException('Invalid semantic recovery generation or touch');
		}
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$current = $this->readRecord($pageId);
			if (!$current || $current['schema'] !== 3 || $current['ready'] ||
				$current['revisionId'] !== $expectedRevisionId ||
				!hash_equals($current['generation'], $expectedGeneration)) {
				return null;
			}
			if ($touchAfter <= $current['touchAfter']) {
				throw new \InvalidArgumentException('Recovery touch must advance the old barrier');
			}
			$current['generation'] = bin2hex(random_bytes(16));
			$current['queuedAt'] = time();
			$current['touchAfter'] = $touchAfter;
			$this->writeRecord($pageId, $current);
			return $current['generation'];
		} finally { fclose($lock); }
	}

	/**
	 * Publish the same generation only after the scheduled primary DB touch
	 * is visible. The deferred caller checks page_latest itself and repeats
	 * the HTML purge before making this method call.
	 *
	 * Never revive a superseded generation, even for the same revision.
	 */
	public function confirmTouched(
		int $pageId, int $revisionId, string $generation, string $observedTouched
	): bool {
		$this->checkId($pageId);
		$this->checkId($revisionId);
		if (!preg_match('/\A[a-f0-9]{32}\z/D', $generation) ||
			!preg_match('/\A[0-9]{14}\z/D', $observedTouched)) {
			throw new \InvalidArgumentException('Invalid touch confirmation parameters');
		}
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$record = $this->readRecord($pageId);
			if (!$record || $record['schema'] !== 3 ||
				$record['revisionId'] !== $revisionId ||
				!hash_equals($record['generation'], $generation)) {
				return false;
			}
			if ($observedTouched < $record['touchAfter']) {
				return false;
			}
			if (!$record['ready']) {
				$record['ready'] = true;
				$this->writeRecord($pageId, $record);
			}
			return true;
		} finally { fclose($lock); }
	}

	/** Called with the corresponding page's exclusive lock held. */
	private function publishRecord(int $pageId, int $revisionId): void {
		$this->writeRecord($pageId, [
			'schema' => 2, 'pageId' => $pageId, 'revisionId' => $revisionId,
			'generation' => bin2hex(random_bytes(16)), 'queuedAt' => time()
		]);
	}

	/** Atomic replacement also preserves generation across readiness changes. */
	private function writeRecord(int $pageId, array $record): void {
		$encoded = json_encode($record, JSON_THROW_ON_ERROR);
		$temp = @tempnam($this->root, '.queue-');
		if (!$temp || dirname($temp) !== $this->root) {
			throw new \RuntimeException('Cannot allocate a private temporary queue record');
		}
		try {
			if (!@chmod($temp, 0600) ||
				@file_put_contents($temp, $encoded) !== strlen($encoded) ||
				!@rename($temp, $this->recordPath($pageId))) {
				throw new \RuntimeException('Cannot publish preparation queue record');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	/** @return array{schema:int,pageId:int,revisionId:int,queuedAt:int,generation?:string,touchAfter?:string,ready?:bool}|null */
	public function current(int $pageId): ?array {
		$this->checkId($pageId);
		if (!is_dir($this->root)) { return null; }
		$lock = $this->lock($pageId, LOCK_SH);
		try { return $this->readRecord($pageId); }
		finally { fclose($lock); }
	}

	/**
	 * Remove only the exact request the worker prepared. A semantic change can
	 * create a new generation at the same page revision. Legacy schema-1 records
	 * remain acknowledgeable with no generation (migration-only compatibility).
	 */
	public function acknowledge(int $pageId, int $preparedRevisionId, ?string $expectedGeneration = null): bool {
		$this->checkId($pageId);
		$this->checkId($preparedRevisionId);
		if (!is_dir($this->root)) { return false; }
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$current = $this->readRecord($pageId);
			if (!$current || $current['revisionId'] !== $preparedRevisionId ||
				($current['schema'] === 1 && $expectedGeneration !== null) ||
				($current['schema'] >= 2 && !hash_equals($current['generation'], $expectedGeneration ?? '')) ||
				($current['schema'] === 3 && !$current['ready'])) {
				return false;
			}
			if (!@unlink($this->recordPath($pageId))) {
				throw new \RuntimeException('Cannot acknowledge preparation queue record');
			}
			return true;
		} finally { fclose($lock); }
	}


	/**
	 * A separate one-time Vector view migration cursor. It never changes the
	 * daily scan cursor, direct edit queue, or parallel backfill journal.
	 *
	 * @return array{version:int,high_watermark:int,after:int,created_at:int,state:string}|null
	 */
	public function vectorRebuildCheckpoint(): ?array {
		$path = $this->root . '/vector-rebuild-checkpoint.json';
		if (is_link($path)) { throw new \RuntimeException('Symlinked Vector rebuild checkpoint'); }
		if (!is_file($path)) { return null; }
		clearstatcache(true, $path);
		if (filesize($path) > 512) {
			throw new \RuntimeException('Oversized Vector rebuild checkpoint');
		}
		$raw = @file_get_contents($path);
		$data = $raw === false ? null : json_decode($raw, true);
		if (!is_array($data) || !self::validVectorRebuildCheckpoint($data)) {
			throw new \RuntimeException('Malformed Vector rebuild checkpoint');
		}
		return $data;
	}

	/** Called only with tryLockWorker() held; atomic and separate from other cursors. */
	public function saveVectorRebuildCheckpoint(array $data): void {
		if (!self::validVectorRebuildCheckpoint($data)) {
			throw new \InvalidArgumentException('Invalid Vector rebuild checkpoint');
		}
		$this->ensureDirectory();
		$path = $this->root . '/vector-rebuild-checkpoint.json';
		if (is_link($path)) { throw new \RuntimeException('Symlinked Vector rebuild checkpoint'); }
		$temp = @tempnam($this->root, '.vector-rebuild-');
		if ($temp === false || dirname($temp) !== $this->root) {
			throw new \RuntimeException('Cannot allocate private Vector checkpoint');
		}
		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
			if (!@chmod($temp, 0600) ||
				@file_put_contents($temp, $json) !== strlen($json) ||
				!@rename($temp, $path)) {
				throw new \RuntimeException('Cannot persist Vector checkpoint');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	private static function validVectorRebuildCheckpoint(array $data): bool {
		return ($data['version'] ?? null) === 1 &&
			is_int($data['high_watermark'] ?? null) && $data['high_watermark'] > 0 &&
			is_int($data['after'] ?? null) && $data['after'] >= 0 &&
			$data['after'] <= $data['high_watermark'] &&
			is_int($data['created_at'] ?? null) && $data['created_at'] > 0 &&
			in_array($data['state'] ?? null, ['running', 'complete'], true) &&
			($data['state'] !== 'complete' ||
				$data['after'] === $data['high_watermark']);
	}

	/**
	 * The daily sweep cursor lives inside this wiki's private queue directory.
	 * Missing cursor means the first cycle; malformed state must fail closed.
	 */
	public function dailyCursor(): int {
		$path = $this->root . '/daily-cursor.json';
		if (is_link($path)) { throw new \RuntimeException('Symlinked daily cursor'); }
		if (!is_file($path)) { return 0; }
		clearstatcache(true, $path);
		if (filesize($path) > 512) { throw new \RuntimeException('Oversized daily cursor'); }
		$raw = @file_get_contents($path);
		$data = $raw === false ? null : json_decode($raw, true);
		if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
			!is_int($data['after'] ?? null) || $data['after'] < 0) {
			throw new \RuntimeException('Malformed daily cursor');
		}
		return $data['after'];
	}

	/** Call only under tryLockWorker(); atomic replacement survives interruption. */
	public function saveDailyCursor(int $after): void {
		if ($after < 0) { throw new \InvalidArgumentException('Invalid daily cursor'); }
		$this->ensureDirectory();
		$path = $this->root . '/daily-cursor.json';
		if (is_link($path)) { throw new \RuntimeException('Symlinked daily cursor'); }
		$temp = @tempnam($this->root, '.daily-cursor-');
		if ($temp === false || dirname($temp) !== $this->root) {
			throw new \RuntimeException('Cannot allocate daily cursor');
		}
		try {
			$json = json_encode(['version' => 1, 'after' => $after], JSON_THROW_ON_ERROR) . "\n";
			if (!@chmod($temp, 0600) ||
				@file_put_contents($temp, $json) !== strlen($json) ||
				!@rename($temp, $path)) {
				throw new \RuntimeException('Cannot persist daily cursor');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	/**
	 * Bounded, unordered snapshot. Completed items are removed by acknowledge,
	 * so an interrupted worker needs no fragile numeric offset or global cursor.
	 * The worker must recheck the current page revision before acknowledging.
	 *
	 * @return array<int,array{schema:int,pageId:int,revisionId:int,queuedAt:int,generation?:string,touchAfter?:string,ready?:bool}>
	 */
	public function pending(int $limit = 1000, bool $directEditsOnly = false): array {
		if ($limit < 1 || $limit > 10000) {
			throw new \InvalidArgumentException('Preparation queue batch must contain 1 to 10000 pages');
		}
		if (!is_dir($this->root)) { return []; }
		$batch = [];
		foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->isLink() ||
				!preg_match('/\A([1-9][0-9]*)\.json\z/D', $file->getFilename(), $match)) {
				continue;
			}
			$pageId = (int)$match[1];
			if ((string)$pageId !== $match[1]) { continue; }
			$record = $this->current($pageId);
			// Legacy schema-3 entries came from indirect SMW changes. Keep
			// them inspectable, but never prepare them in the direct-edit worker.
			if ($record !== null && (!$directEditsOnly || $record['schema'] !== 3)) {
				$batch[] = $record;
			}
			if (count($batch) >= $limit) { break; }
		}
		return $batch;
	}

	/**
	 * Serialize expensive preparation for one page across queue and sweep
	 * workers. Separate from the short per-record lock so edits/invalidation
	 * hooks never wait for an HTML render.
	 *
	 * @return resource
	 */
	public function lockBuildPage(int $pageId) {
		$this->checkId($pageId);
		$this->ensureDirectory();
		$path = $this->root . '/locks/build-' . $pageId . '.lock';
		if (is_link($path)) {
			throw new \RuntimeException('Symlinked page preparation lock');
		}
		$handle = @fopen($path, 'c');
		if (!$handle || !@chmod($path, 0600) || !@flock($handle, LOCK_EX)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot lock page cache preparation');
		}
		return $handle;
	}

	/**
	 * Dedicated private workspace for resumable parallel backfill state.
	 * This does not change the existing queue or the daily scan cursor.
	 */
	public function parallelDirectory(): string {
		$this->ensureDirectory();
		$path = $this->root . '/parallel';
		if (is_link($path) || (file_exists($path) && !is_dir($path))) {
			throw new \RuntimeException('Invalid parallel backfill workspace');
		}
		if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
			throw new \RuntimeException('Cannot create parallel backfill workspace');
		}
		if ((fileperms($path) & 0077) !== 0) {
			throw new \RuntimeException('Parallel backfill workspace is not private');
		}
		return $path;
	}

}
