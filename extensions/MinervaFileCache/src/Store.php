<?php

namespace MediaWiki\Extension\MinervaFileCache;

/** Private local disk store. A page lock serializes publication and invalidation. */
class Store {
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

	private function pageDirectory(string $page): string {
		$key = hash('sha256', $page);
		$dir = $this->root . '/' . substr($key, 0, 2) . '/' . $key;
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
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

	/** Validate entries consistently for ordinary reads and fragment fallback. */
	private function validEntry(?array $entry, string $generation, array $identity): bool {
		return $entry !== null &&
			($entry['generation'] ?? null) === $generation &&
			($entry['identity'] ?? null) === $identity &&
			is_int($entry['expires'] ?? null) &&
			is_int($entry['created'] ?? null) &&
			min($entry['expires'], $entry['created'] + $this->ttl) > time() &&
			is_string($entry['body'] ?? null) &&
			is_array($entry['headers'] ?? null);
	}

	/** @return array{generation:string,entry:?array} */
	public function read(string $page, string $variant, array $identity): array {
		$dir = $this->pageDirectory($page);
		// Ordinary hits may read in parallel; writers and invalidations remain exclusive.
		$lock = $this->lock($dir, 'page.lock', LOCK_SH);
		if (!$lock) { throw new \RuntimeException('Cannot lock page cache'); }
		try {
			$generation = @file_get_contents($dir . '/generation');
			if (!$generation) {
				// Never upgrade SH to EX while holding SH: two first readers could deadlock.
				fclose($lock);
				$lock = $this->lock($dir, 'page.lock', LOCK_EX);
				if (!$lock) { throw new \RuntimeException('Cannot initialize page cache lock'); }
				$generation = @file_get_contents($dir . '/generation');
				if (!$generation) {
					$generation = bin2hex(random_bytes(16));
					if (@file_put_contents($dir . '/generation', $generation) === false) {
						throw new \RuntimeException('Cannot initialize cache generation');
					}
				}
			}
			$path = $dir . '/' . hash('sha256', $variant) . '.cache';
			$entry = $this->decode($path);
			return ['generation' => $generation,
				'entry' => $this->validEntry($entry, $generation, $identity) ? $entry : null];
		} finally {
			if (is_resource($lock)) { fclose($lock); }
		}
	}

	/** Reuse only validated same-origin, content-language anonymous view entries. */
	public function readForFragment(string $page, array $identity, $context): ?array {
		$dir = $this->pageDirectory($page);
		$server = parse_url($context->getConfig()->get('Server'));
		$host = $context->getRequest()->getHeader('Host') ?: (($server['host'] ?? '') .
			(isset($server['port']) ? ':' . $server['port'] : ''));
		// One shared-lock pass: validate the decoded candidate without reading it twice.
		$lock = $this->lock($dir, 'page.lock', LOCK_SH);
		if (!$lock) { throw new \RuntimeException('Cannot lock fragment cache'); }
		try {
			$generation = @file_get_contents($dir . '/generation');
			if (!$generation) { return null; }
			foreach (glob($dir . '/*.cache') ?: [] as $path) {
				$entry = $this->decode($path);
				if (!$this->validEntry($entry, $generation, $identity)) { continue; }
				$variant = $entry['variant'] ?? null;
				$v = is_string($variant) ? json_decode($variant, true) : null;
				if (!is_array($v) || count($v) !== 8 ||
					$v[1] !== $context->getRequest()->getProtocol() ||
					$v[2] !== $context->getConfig()->get('Server') ||
					$v[3] !== $context->getLanguage()->getCode() ||
					strtolower((string)$v[4]) !== strtolower($host) ||
					(string)$v[7] !== (string)$context->getRequest()->getHeader('X-Subdomain')) {
					continue;
				}
				$entry['expires'] = min($entry['expires'], $entry['created'] + $this->ttl);
				return $entry;
			}
			return null;
		} finally { fclose($lock); }
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
		string $body, array $headers, int $expires = PHP_INT_MAX): bool {
		if (strlen($body) > $this->maxBytes) { return false; }
		$dir = $this->pageDirectory($page);
		$created = time();
		$expires = min($created + $this->ttl, $expires);
		if ($expires <= $created) { return false; }
		$entry = ['generation' => $generation, 'identity' => $identity, 'created' => $created,
			'expires' => $expires, 'variant' => $variant, 'body' => $body, 'headers' => $headers];
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false || strlen($json) > $this->maxBytes * 2) { return false; }
		// Prepare and compress outside the exclusive page lock.
		$payload = gzencode($json, 3);
		if ($payload === false) { return false; }
		$temp = @tempnam($dir, '.write-');
		if (!$temp || dirname($temp) !== $dir) { return false; }
		try {
			if (@file_put_contents($temp, $payload) !== strlen($payload) || !@chmod($temp, 0600)) {
				return false;
			}
			// mtime is the expiry of new cache files. Older files are migrated by prune().
			// This is only a cleaning hint: read() always validates the stored expiry.
			@touch($temp, $expires);
			$lock = $this->lock($dir, 'page.lock', LOCK_EX);
			if (!$lock) { return false; }
			try {
				// A concurrent purge must prevent an older rendering from being published.
				if (@file_get_contents($dir . '/generation') !== $generation || $expires <= time()) {
					return false;
				}
				return @rename($temp, $dir . '/' . hash('sha256', $variant) . '.cache');
			} finally { fclose($lock); }
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	public function invalidate(string $page): void {
		$dir = $this->pageDirectory($page);
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
		$now = time();
		foreach (glob($this->root . '/*/*/generation') ?: [] as $generation) {
			$dir = dirname($generation);
			$lock = $this->lock($dir, 'page.lock', LOCK_EX | LOCK_NB);
			if (!$lock) { continue; }
			try {
				foreach (glob($dir . '/*.cache') ?: [] as $path) {
					clearstatcache(true, $path);
					$modified = @filemtime($path);
					// New files carry the expiry in mtime, so most live files
					// need no decompression. Reject implausibly future timestamps.
					if ($modified !== false && $modified > $now && $modified <= $now + $this->ttl) {
						continue;
					}
					// Legacy files and expired candidates still receive full validation.
					$entry = $this->decode($path);
					$expires = is_int($entry['expires'] ?? null) && is_int($entry['created'] ?? null)
						? min($entry['expires'], $entry['created'] + $this->ttl) : 0;
					if ($expires <= $now) {
						$count += (int)@unlink($path);
					} else {
						// Upgrade an existing valid legacy entry for the next pass.
						@touch($path, $expires);
					}
				}
			} finally { fclose($lock); }
		}
		return $count;
	}

	/**
	 * Read-only maintenance diagnostics. This scans metadata only, not compressed HTML.
	 * Never invoke it on a visitor request.
	 * @return array<string,mixed>
	 */
	public function statistics(): array {
		$stats = ['pages' => 0, 'files' => 0, 'bytes' => 0,
			'maxVariantsPerPage' => 0, 'pagesWithMultipleVariants' => 0,
			'variantDistribution' => ['zero' => 0, 'one' => 0, 'twoToFive' => 0, 'sixToTen' => 0, 'overTen' => 0]];
		if (!is_dir($this->root)) { return $stats; }
		$prefixes = new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS);
		foreach ($prefixes as $prefix) {
			if (!$prefix->isDir() || $prefix->isLink()) { continue; }
			foreach (new \FilesystemIterator($prefix->getPathname(), \FilesystemIterator::SKIP_DOTS) as $page) {
				if (!$page->isDir() || $page->isLink()) { continue; }
				$stats['pages']++;
				$variants = 0;
				foreach (new \FilesystemIterator($page->getPathname(), \FilesystemIterator::SKIP_DOTS) as $file) {
					if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'cache') { continue; }
					$variants++;
					$stats['files']++;
					$stats['bytes'] += $file->getSize();
				}
				$stats['maxVariantsPerPage'] = max($stats['maxVariantsPerPage'], $variants);
				if ($variants > 1) { $stats['pagesWithMultipleVariants']++; }
				$group = $variants === 0 ? 'zero' : ($variants === 1 ? 'one' :
					($variants <= 5 ? 'twoToFive' : ($variants <= 10 ? 'sixToTen' : 'overTen')));
				$stats['variantDistribution'][$group]++;
			}
		}
		return $stats;
	}
}
