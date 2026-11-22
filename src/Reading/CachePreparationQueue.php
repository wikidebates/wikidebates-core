<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * An opt-in, filesystem-backed queue of directly edited page revisions.
 *
 * One record per page is retained until an explicit consumer acknowledges the
 * same revision. The queue does not run jobs or touch cache files itself.
 * Page-level locks protect against a newer edit being lost during acknowledgement.
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
		$path = $this->root . '/' . $pageId . '.lock';
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
		if (!is_array($record) || ($record['schema'] ?? null) !== 1 ||
			($record['pageId'] ?? null) !== $pageId ||
			!is_int($record['revisionId'] ?? null) || $record['revisionId'] < 1 ||
			!is_int($record['queuedAt'] ?? null)) {
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
			$record = ['schema' => 1, 'pageId' => $pageId, 'revisionId' => $revisionId,
				'queuedAt' => time()];
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
			return true;
		} finally { fclose($lock); }
	}

	/** @return array{schema:int,pageId:int,revisionId:int,queuedAt:int}|null */
	public function current(int $pageId): ?array {
		$this->checkId($pageId);
		if (!is_dir($this->root)) { return null; }
		$lock = $this->lock($pageId, LOCK_SH);
		try { return $this->readRecord($pageId); }
		finally { fclose($lock); }
	}

	/**
	 * Remove only the revision that was actually prepared. If an edit arrived
	 * while a worker rendered, its higher revision stays pending.
	 */
	public function acknowledge(int $pageId, int $preparedRevisionId): bool {
		$this->checkId($pageId);
		$this->checkId($preparedRevisionId);
		if (!is_dir($this->root)) { return false; }
		$lock = $this->lock($pageId, LOCK_EX);
		try {
			$current = $this->readRecord($pageId);
			if (!$current || $current['revisionId'] !== $preparedRevisionId) { return false; }
			if (!@unlink($this->recordPath($pageId))) {
				throw new \RuntimeException('Cannot acknowledge preparation queue record');
			}
			return true;
		} finally { fclose($lock); }
	}

	/**
	 * Bounded, unordered snapshot. Completed items are removed by acknowledge,
	 * so an interrupted worker needs no fragile numeric offset or global cursor.
	 * The worker must recheck the current page revision before acknowledging.
	 *
	 * @return array<int,array{schema:int,pageId:int,revisionId:int,queuedAt:int}>
	 */
	public function pending(int $limit = 1000): array {
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
			if ($record !== null) { $batch[] = $record; }
			if (count($batch) >= $limit) { break; }
		}
		return $batch;
	}
}
