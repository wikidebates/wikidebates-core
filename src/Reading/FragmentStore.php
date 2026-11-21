<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/** Private local disk store. A page lock serializes publication and invalidation. */
class FragmentStore {
	private string $root;
	private int $ttl;
	private int $maxBytes;

	public function __construct(string $directory, string $wiki, int $ttl, int $maxBytes) {
		$directory = realpath($directory) ?: '';
		if (!is_dir($directory) || !is_writable($directory) || $ttl < 1 || $maxBytes < 512) {
			throw new \RuntimeException('Cache directory or limits are not usable');
		}
		$this->root = rtrim($directory, '/') . '/' . hash('sha256', $wiki);
		$this->ttl = min($ttl, 2592000);
		$this->maxBytes = $maxBytes;
	}

	private function pageDirectory(string $page, bool $create = true): string {
		$key = hash('sha256', $page);
		$dir = $this->root . '/' . substr($key, 0, 2) . '/' . $key;
		if ($create && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create a page cache directory');
		}
		return $dir;
	}

	private function lock(string $dir, string $name, int $mode) {
		$handle = @fopen($dir . '/' . $name, 'c');
		if (!$handle || !@flock($handle, $mode)) {
			if ($handle) { fclose($handle); }
			return false;
		}
		return $handle;
	}

	/** @return array{generation:string,entry:?array} */
	public function read(string $page, string $variant, array $identity): array {
		$dir = $this->pageDirectory($page);
		$lock = $this->lock($dir, 'page.lock', LOCK_SH);
		if (!$lock) { throw new \RuntimeException('Cannot lock page cache'); }
		try {
			$generation = @file_get_contents($dir . '/generation');
			if (!$generation) {
				// Never upgrade a shared lock in place: concurrent initializers must
				// release it, acquire the writer lock and re-read the generation.
				fclose($lock);
				$lock = $this->lock($dir, 'page.lock', LOCK_EX);
				if (!$lock) { throw new \RuntimeException('Cannot initialize page cache'); }
				$generation = @file_get_contents($dir . '/generation');
				if (!$generation) {
					$generation = bin2hex(random_bytes(16));
					if (@file_put_contents($dir . '/generation', $generation) !== strlen($generation)) {
						throw new \RuntimeException('Cannot initialize cache generation');
					}
				}
				// Initialization is rare; keep this exclusive snapshot through the
				// read. All subsequent reads can share the page lock.
			}
			$path = $dir . '/' . hash('sha256', $variant) . '.cache';
			$entry = $this->decode($path);
			if (!$entry || ($entry['generation'] ?? null) !== $generation ||
				($entry['identity'] ?? null) !== $identity ||
				min($entry['expires'] ?? 0, ($entry['created'] ?? 0) + $this->ttl) <= time() ||
				!is_string($entry['body'] ?? null) || !is_array($entry['metadata'] ?? null)) {
				$entry = null;
			}
			return ['generation' => $generation, 'entry' => $entry];
		} finally { if (is_resource($lock)) { fclose($lock); } }
	}

	private function decode(string $path): ?array {
		clearstatcache(true, $path);
		if (!is_file($path) || filesize($path) > $this->maxBytes * 2) { return null; }
		$compressed = @file_get_contents($path);
		$data = $compressed === false ? false : @gzdecode($compressed, $this->maxBytes * 2);
		$entry = $data === false ? null : json_decode($data, true);
		return is_array($entry) ? $entry : null;
	}

	/** Only one request per page may fill a cache. Other requests render normally. */
	public function startFill(string $page) {
		return $this->lock($this->pageDirectory($page), 'fill.lock', LOCK_EX | LOCK_NB);
	}

	public function publish(string $page, string $variant, array $identity, string $generation,
		string $body, array $metadata, int $expires): bool {
		$created = time();
		if (strlen($body) > $this->maxBytes || $expires <= $created) { return false; }
		$entry = ['generation' => $generation, 'identity' => $identity, 'created' => $created,
			'expires' => min($created + $this->ttl, $expires), 'body' => $body, 'metadata' => $metadata];
		// Serialization, compression and the private temporary write must not
		// block readers. Recheck generation and expiry under the publication lock.
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false || strlen($json) > $this->maxBytes * 2) { return false; }
		$payload = gzencode($json, 3);
		if ($payload === false) { return false; }
		$dir = $this->pageDirectory($page);
		$temp = null;
		$lock = false;
		try {
			$temp = @tempnam($dir, '.write-');
			if (!$temp || dirname($temp) !== $dir || @file_put_contents($temp, $payload) !== strlen($payload)) { return false; }
			if (!@chmod($temp, 0600)) { return false; }
			$lock = $this->lock($dir, 'page.lock', LOCK_EX);
			if (!$lock || @file_get_contents($dir . '/generation') !== $generation || $entry['expires'] <= time()) { return false; }
			$published = @rename($temp, $dir . '/' . hash('sha256', $variant) . '.cache');
			if ($published) { @touch($dir . '/known'); }
			return $published;
		} finally {
			if ($temp && is_file($temp)) { @unlink($temp); }
			if (is_resource($lock)) { fclose($lock); }
		}
	}

	public function known(string $page): bool {
		return is_file($this->pageDirectory($page, false) . '/known');
	}

	public function invalidate(string $page): void {
		$dir = $this->pageDirectory($page, false);
		// A writer creates this directory before taking its generation snapshot.
		// Never remove directories/lock files: existing writers share this lock.
		clearstatcache(true, $dir);
		if (!is_dir($dir)) { return; }
		$lock = $this->lock($dir, 'page.lock', LOCK_EX);
		if (!$lock) { throw new \RuntimeException('Cannot lock page invalidation'); }
		try {
			// Keep lock files: unlinking an active lock would create two lock domains.
			if (@file_put_contents($dir . '/generation', bin2hex(random_bytes(16))) === false) {
				throw new \RuntimeException('Cannot advance cache generation');
			}
			foreach (glob($dir . '/*.cache') as $path) { @unlink($path); }
		} finally { fclose($lock); }
	}

	public function prune(): int {
		$count = 0;
		foreach (glob($this->root . '/*/*/generation') as $generation) {
			$dir = dirname($generation);
			$lock = $this->lock($dir, 'page.lock', LOCK_EX | LOCK_NB);
			if (!$lock) { continue; }
			try {
				foreach (glob($dir . '/*.cache') as $path) {
					$entry = $this->decode($path);
					if (!$entry || min($entry['expires'] ?? 0, ($entry['created'] ?? 0) + $this->ttl) <= time()) {
						$count += (int)@unlink($path);
					}
				}
			} finally { fclose($lock); }
		}
		return $count;
	}
}
