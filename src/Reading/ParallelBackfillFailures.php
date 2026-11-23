<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Persistent, private diagnostics for pages whose anonymous HTML preparation
 * could not be completed. This ledger is NOT a success checkpoint or a queue:
 * failed pages must remain in CachePreparationQueue for another attempt.
 *
 * Only numeric IDs and bounded internal status codes are recorded; never
 * persist a title, URL, rendered HTML, HTTP response or exception message.
 */
final class ParallelBackfillFailures {
	private string $directory;

	public function __construct(CachePreparationQueue $queue) {
		$this->directory = $queue->parallelDirectory() . '/page-errors';
		if (is_link($this->directory) ||
			(file_exists($this->directory) && !is_dir($this->directory)) ||
			(!is_dir($this->directory) && !@mkdir($this->directory, 0700) &&
				!is_dir($this->directory)) ||
			(fileperms($this->directory) & 0077) !== 0) {
			throw new \RuntimeException('Unsafe private cache failure directory');
		}
	}

	private static function safeCode(string $value): string {
		return preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $value) ?
			$value : 'invalid-outcome';
	}

	private function path(int $pageId): string {
		if ($pageId < 1) {
			throw new \InvalidArgumentException('A cache error requires a positive page ID');
		}
		return $this->directory . '/' . $pageId . '.json';
	}

	/** Write a 0600 JSON record atomically; never silently lose a failed page. */
	private function write(int $pageId, array $record): void {
		$path = $this->path($pageId);
		if (is_link($path)) {
			throw new \RuntimeException('Symlinked cache error record');
		}
		$json = json_encode($record, JSON_THROW_ON_ERROR);
		$temp = @tempnam($this->directory, '.page-error-');
		if (!$temp || dirname($temp) !== $this->directory) {
			throw new \RuntimeException('Cannot allocate cache failure record');
		}
		try {
			if (!@chmod($temp, 0600) ||
				@file_put_contents($temp, $json) !== strlen($json) ||
				!@rename($temp, $path)) {
				throw new \RuntimeException('Cannot persist cache failure record');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	private function read(int $pageId): ?array {
		$path = $this->path($pageId);
		if (is_link($path)) {
			throw new \RuntimeException('Symlinked cache error record');
		}
		if (!is_file($path)) { return null; }
		clearstatcache(true, $path);
		if (filesize($path) > 2048) {
			throw new \RuntimeException('Oversized cache error record');
		}
		$raw = @file_get_contents($path);
		$value = $raw === false ? null : json_decode($raw, true);
		if (!is_array($value) || ($value['version'] ?? null) !== 1 ||
			($value['page_id'] ?? null) !== $pageId ||
			!is_int($value['revision_id'] ?? null) || $value['revision_id'] < 1 ||
			!is_string($value['reason'] ?? null) ||
			!is_int($value['first_at'] ?? null) ||
			!is_int($value['last_at'] ?? null) ||
			!is_int($value['attempts'] ?? null) ||
			!array_key_exists('resolved_at', $value) ||
			!($value['resolved_at'] === null || is_int($value['resolved_at']))) {
			throw new \RuntimeException('Malformed cache failure record');
		}
		return $value;
	}

	/**
	 * Called BEFORE a lane's deferred cursor checkpoint. A crash before the
	 * checkpoint can repeat this write, but cannot lose the page's error.
	 */
	public function record(int $pageId, int $revisionId, string $reason): void {
		if ($revisionId < 1) {
			throw new \InvalidArgumentException('Invalid revision for cache failure');
		}
		$old = $this->read($pageId);
		$now = time();
		$this->write($pageId, [
			'version' => 1, 'page_id' => $pageId,
			'revision_id' => $revisionId,
			'reason' => self::safeCode($reason),
			'first_at' => $old !== null && $old['resolved_at'] === null ?
				$old['first_at'] : $now,
			'last_at' => $now,
			'attempts' => min(1000000, ($old['attempts'] ?? 0) + 1),
			'resolved_at' => null
		]);
	}

	/**
	 * Success can resolve a previous failure without erasing its audit
	 * history. Direct-edit queues must still be acknowledged separately.
	 */
	public function resolved(int $pageId): void {
		$old = $this->read($pageId);
		if ($old !== null && $old['resolved_at'] === null) {
			$old['resolved_at'] = time();
			$this->write($pageId, $old);
		}
	}

	/** @return array{open:int,resolved:int,recent:array<int,array>,signature:string} */
	public function summary(int $limit = 30): array {
		if ($limit < 1 || $limit > 100) {
			throw new \InvalidArgumentException('Invalid error report limit');
		}
		$open = 0;
		$resolved = 0;
		$recent = [];
		$signatureParts = [];
		foreach (new \FilesystemIterator(
			$this->directory, \FilesystemIterator::SKIP_DOTS
		) as $file) {
			if (!$file->isFile() || $file->isLink() ||
				!preg_match('/\A([1-9][0-9]*)\.json\z/D', $file->getFilename(), $m)) {
				continue;
			}
			$id = (int)$m[1];
			if ((string)$id !== $m[1]) { continue; }
			$record = $this->read($id);
			if ($record['resolved_at'] !== null) {
				$resolved++;
				continue;
			}
			$open++;
			$signatureParts[] = $id . ':' . $record['revision_id'] . ':' .
				$record['reason'] . ':' . $record['first_at'];
			$recent[] = [
				'page_id' => $id, 'revision_id' => $record['revision_id'],
				'reason' => $record['reason'],
				'attempts' => $record['attempts'],
				'last_at' => $record['last_at']
			];
			usort($recent, static fn(array $a, array $b): int =>
				($b['last_at'] <=> $a['last_at']) ?: ($a['page_id'] <=> $b['page_id']));
			if (count($recent) > $limit) {
				array_pop($recent);
			}
		}
		sort($signatureParts, SORT_STRING);
		return [
			'open' => $open, 'resolved' => $resolved, 'recent' => $recent,
			'signature' => hash('sha256', implode("\n", $signatureParts))
		];
	}

	/**
	 * Rate-limit an opt-in email digest. Only mark delivery after a successful
	 * send; failures stay retryable. Never email from a cache-rendering worker.
	 *
	 * @param callable(array):bool $deliver
	 * @return string sent|unchanged|rate-limited|empty|failed
	 */
	public function alert(callable $deliver, int $intervalSeconds = 3600): string {
		if ($intervalSeconds < 300 || $intervalSeconds > 86400) {
			throw new \InvalidArgumentException('Invalid alert interval');
		}
		$path = $this->directory . '/alert.lock';
		if (is_link($path)) {
			throw new \RuntimeException('Symlinked cache alert lock');
		}
		$handle = @fopen($path, 'c');
		if (!$handle || !@chmod($path, 0600) || !@flock($handle, LOCK_EX | LOCK_NB)) {
			if ($handle) { fclose($handle); }
			throw new \RuntimeException('Cannot acquire private cache alert lock');
		}
		try {
			$summary = $this->summary();
			if ($summary['open'] === 0) { return 'empty'; }
			$stamp = $this->directory . '/alert.json';
			if (is_link($stamp)) {
				throw new \RuntimeException('Symlinked cache alert stamp');
			}
			$previous = is_file($stamp) ? json_decode(
				(string)@file_get_contents($stamp), true
			) : null;
			if (is_file($stamp) && !is_array($previous)) {
				throw new \RuntimeException('Malformed cache alert stamp');
			}
			if (($previous['signature'] ?? '') === $summary['signature']) {
				return 'unchanged';
			}
			if (time() - ($previous['sent_at'] ?? 0) < $intervalSeconds) {
				return 'rate-limited';
			}
			if (!$deliver($summary)) { return 'failed'; }
			$payload = json_encode([
				'version' => 1, 'signature' => $summary['signature'],
				'sent_at' => time()
			], JSON_THROW_ON_ERROR);
			$temp = @tempnam($this->directory, '.alert-');
			if (!$temp || dirname($temp) !== $this->directory) {
				throw new \RuntimeException('Cannot allocate cache alert stamp');
			}
			try {
				if (!@chmod($temp, 0600) ||
					@file_put_contents($temp, $payload) !== strlen($payload) ||
					!@rename($temp, $stamp)) {
					throw new \RuntimeException('Cannot record successful cache alert');
				}
			} finally {
				if (is_file($temp)) { @unlink($temp); }
			}
			return 'sent';
		} finally {
			@flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
