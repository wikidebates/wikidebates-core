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

	/**
	 * The first view of a page should not create any cache files until its
	 * fully rendered response is known to be cacheable. This is an optimistic
	 * snapshot: a concurrent purge creates a real generation and causes a
	 * later first publication to fail closed.
	 *
	 * @return array{generation:string,entry:?array,fresh:bool}
	 */
	public function readIfPresent(string $page, string $variant, array $identity): array {
		$dir = $this->pageDirectory($page, false);
		if (!is_file($dir . '/generation')) {
			return [
				'generation' => bin2hex(random_bytes(16)),
				'entry' => null,
				'fresh' => true,
			];
		}
		return $this->read($page, $variant, $identity) + ['fresh' => false];
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
		// Fragment lookups never initiate cache fills; do not create empty page
		// directories for entries that have never been cached.
		$dir = $this->pageDirectory($page, false);
		if (!is_dir($dir)) { return null; }
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
		string $body, array $headers, int $expires = PHP_INT_MAX, bool $initialize = false): bool {
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
				// A cold first render has no generation file. Create it only after
				// the HTML and response headers pass all cacheability checks.
				// Exclusive creation rejects an intervening purge or other writer.
				$generationPath = $dir . '/generation';
				if ($initialize) {
					if (!preg_match('/\\A[a-f0-9]{32}\\z/D', $generation)) {
						return false;
					}
					$handle = @fopen($generationPath, 'x');
					if (!$handle) { return false; }
					try {
						$written = @fwrite($handle, $generation);
					} finally { fclose($handle); }
					if ($written !== strlen($generation)) {
						@unlink($generationPath);
						return false;
					}
					@chmod($generationPath, 0600);
				}
				// A concurrent purge must prevent an older rendering from being published.
				if (@file_get_contents($generationPath) !== $generation || $expires <= time()) {
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


	/**
	 * Stream page-cache directories instead of materializing every generation
	 * path into a potentially enormous glob() array.
	 * @return \Generator<int,string>
	 */
	private function cachedPageDirectories(): \Generator {
		if (!is_dir($this->root)) { return; }
		foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS) as $prefix) {
			if (!$prefix->isDir() || $prefix->isLink()) { continue; }
			foreach (new \FilesystemIterator($prefix->getPathname(), \FilesystemIterator::SKIP_DOTS) as $page) {
				if ($page->isDir() && !$page->isLink() &&
					is_file($page->getPathname() . '/generation')) {
					yield $page->getPathname();
				}
			}
		}
	}

	public function prune(): int {
		$count = 0;
		$now = time();
		foreach ($this->cachedPageDirectories() as $dir) {
			$lock = $this->lock($dir, 'page.lock', LOCK_EX | LOCK_NB);
			if (!$lock) { continue; }
			try {
				// Iterating over files is bounded-memory even for high variant counts.
				foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
					if (!$file->isFile() || $file->getExtension() !== 'cache') { continue; }
					$path = $file->getPathname();
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

	/**
	 * Offline diagnostic only: hash HTML after masking exactly one occurrence
	 * each of MediaWiki's request ID and backend timing in inline scripts.
	 * Never use these hashes to select or serve a cache variant.
	 * @return array{normalized:string,withoutScripts:string,regions:?array}|null
	 */
	private function hashWithoutVolatileMetadata(string $html): ?array {
		$requestIds = 0;
		$timings = 0;
		$normalized = preg_replace_callback(
			'~<script\b[^>]*>.*?</script\s*>~is',
			static function (array $match) use (&$requestIds, &$timings): string {
				$script = preg_replace_callback(
					'~("wgRequestId"\s*:\s*")[^"]*(")~',
					static function (array $value) use (&$requestIds): string {
						$requestIds++;
						return $value[1] . '__REQUEST_ID__' . $value[2];
					},
					$match[0]
				);
				if ($script === null) { return $match[0]; }
				$script = preg_replace_callback(
					'~("wgBackendResponseTime"\s*:\s*)[0-9]+(?:\.[0-9]+)?(?=\s*[,}])~',
					static function (array $value) use (&$timings): string {
						$timings++;
						return $value[1] . '0';
					},
					$script
				);
				return $script ?? $match[0];
			},
			$html
		);
		if ($normalized === null || $requestIds !== 1 || $timings !== 1) {
			return null;
		}
		// Removing scripts is a diagnostic partition, not an HTML rewrite
		// used by the live cache. It includes inline and external scripts.
		$withoutScripts = preg_replace('~<script\\b[^>]*>.*?</script\\s*>~is', '', $normalized);
		return $withoutScripts === null ? null : [
			'normalized' => hash('sha256', $normalized),
			'withoutScripts' => hash('sha256', $withoutScripts),
			'regions' => $this->hashHtmlRegions($withoutScripts),
		];
	}

	/**
	 * Partition a scriptless HTML document into head, body, and the remaining
	 * document shell, without exposing the sections themselves.
	 * The body-text approximation removes tags and inline CSS; it is not an
	 * accessibility/rendered-text check and never affects the serving cache.
	 *
	 * @return array{head:string,body:string,shell:string,bodyText:string}|null
	 */
	private function hashHtmlRegions(string $html): ?array {
		if (preg_match_all('~<head\b[^>]*>.*?</head\s*>~is',
				$html, $heads, PREG_OFFSET_CAPTURE) !== 1 ||
			preg_match_all('~<body\b[^>]*>.*?</body\s*>~is',
				$html, $bodies, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}
		[$head, $headStart] = $heads[0][0];
		[$body, $bodyStart] = $bodies[0][0];
		$headEnd = $headStart + strlen($head);
		$bodyEnd = $bodyStart + strlen($body);
		if ($headEnd > $bodyStart) { return null; }

		$shell = substr($html, 0, $headStart) .
			substr($html, $headEnd, $bodyStart - $headEnd) .
			substr($html, $bodyEnd);
		$bodyWithoutStyles = preg_replace(
			'~<style\b[^>]*>.*?</style\s*>~is', '', $body
		);
		if ($bodyWithoutStyles === null) { return null; }
		return [
			'head' => hash('sha256', $head),
			'body' => hash('sha256', $body),
			'shell' => hash('sha256', $shell),
			'bodyText' => hash('sha256', strip_tags($bodyWithoutStyles)),
		];
	}

	/**
	 * Sample actual stored HTML/header equivalence without exposing page content.
	 * This is an offline diagnostic, never part of a visitor request.
	 * It does not prove equivalence of future responses or different browsers.
	 * @return array<string,int>
	 */
	public function analyzeVariants(int $limitPages = 100, int $maxVariantsPerPage = 20): array {
		if ($limitPages < 1 || $limitPages > 50000 ||
			$maxVariantsPerPage < 2 || $maxVariantsPerPage > 250) {
			throw new \InvalidArgumentException('Invalid variant-audit sampling limits');
		}
		$stats = [
			'scannedPages' => 0, 'pagesWithMultipleFiles' => 0,
			'analyzedPages' => 0, 'validEntries' => 0,
			'identicalExtraEntries' => 0, 'divergentPages' => 0,
			'ignoredEntries' => 0, 'truncatedPages' => 0, 'racedPages' => 0,
			// Compare HTML and replayed headers only among entries with identical
			// identity (revision, touched timestamp, epochs, and format).
			'comparablePages' => 0, 'pagesWithIdentityDifferences' => 0,
			'pagesWithHtmlDifferences' => 0, 'pagesWithHeaderDifferences' => 0,
			'sameIdentityHtmlDuplicates' => 0, 'sameIdentityHeaderDuplicates' => 0,
			// Mask only the two confirmed per-request JavaScript config values,
			// and only for offline comparison of the existing stored HTML.
			'volatileMetadataEntries' => 0, 'volatileComparablePages' => 0,
			'pagesWithOnlyVolatileHtmlDifferences' => 0,
			'pagesWithResidualHtmlDifferences' => 0,
			'sameIdentityHtmlDuplicatesIgnoringVolatile' => 0,
			// Further distinguish differences inside script elements from
			// differences elsewhere in the HTML document (diagnostic only).
			'pagesWithResidualScriptOnlyDifferences' => 0,
			'pagesWithResidualOutsideScripts' => 0,
			'sameIdentityHtmlDuplicatesIgnoringScripts' => 0,
			// Subdivide residual HTML changes by document region, and compare
			// a conservative tag-stripped approximation of the body text.
			'regionEntries' => 0, 'regionComparablePages' => 0,
			'pagesWithResidualHeadDifferences' => 0,
			'pagesWithResidualBodyDifferences' => 0,
			'pagesWithResidualShellDifferences' => 0,
			'pagesWithResidualBodyTextDifferences' => 0,
			'pagesWithResidualBodyMarkupOnlyDifferences' => 0,
		];
		if (!is_dir($this->root)) { return $stats; }
		foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS) as $prefix) {
			if (!$prefix->isDir() || $prefix->isLink()) { continue; }
			foreach (new \FilesystemIterator($prefix->getPathname(), \FilesystemIterator::SKIP_DOTS) as $page) {
				if (!$page->isDir() || $page->isLink()) { continue; }
				if ($stats['scannedPages'] >= $limitPages) { return $stats; }
				$stats['scannedPages']++;
				$dir = $page->getPathname();
				$paths = glob($dir . '/*.cache') ?: [];
				if (count($paths) < 2) { continue; }
				$stats['pagesWithMultipleFiles']++;
				if (count($paths) > $maxVariantsPerPage) { $stats['truncatedPages']++; }
				$generation = @file_get_contents($dir . '/generation');
				if (!is_string($generation) || $generation === '') {
					$stats['racedPages']++;
					continue;
				}
				$hashes = [];
				$byIdentity = [];
				$valid = 0;
				$ignored = 0;
				$normalizedEntries = 0;
				$regionEntries = 0;
				foreach (array_slice($paths, 0, $maxVariantsPerPage) as $path) {
					$entry = is_link($path) ? null : $this->decode($path);
					$identity = $entry['identity'] ?? null;
					if (!is_array($identity) || !$this->validEntry($entry, $generation, $identity)) {
						$ignored++;
						continue;
					}
					$identityJson = json_encode($identity,
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					$headersJson = json_encode($entry['headers'],
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					if ($identityJson === false || $headersJson === false) {
						$ignored++;
						continue;
					}
					$bodyHash = hash('sha256', $entry['body']);
					$headerHash = hash('sha256', $headersJson);
					$identityHash = hash('sha256', $identityJson);
					// No HTML, headers, or per-visitor fields leave the process.
					// This normalization is diagnostic only; stored and served HTML
					// remains byte-for-byte unchanged.
					$normalizedHashes = $this->hashWithoutVolatileMetadata($entry['body']);
					if ($normalizedHashes !== null) { $normalizedEntries++; }
					if (($normalizedHashes['regions'] ?? null) !== null) { $regionEntries++; }
					$byIdentity[$identityHash][] = [$bodyHash, $headerHash,
						$normalizedHashes['normalized'] ?? null,
						$normalizedHashes['withoutScripts'] ?? null,
						$normalizedHashes['regions'] ?? null];
					$fingerprint = hash('sha256', $identityHash . $headerHash . $bodyHash);
					$hashes[$fingerprint] = true;
					$valid++;
				}
				// Ignore samples interrupted by an invalidation; never block writers.
				if (@file_get_contents($dir . '/generation') !== $generation) {
					$stats['racedPages']++;
					continue;
				}
				$stats['analyzedPages']++;
				$stats['validEntries'] += $valid;
				$stats['ignoredEntries'] += $ignored;
				$stats['volatileMetadataEntries'] += $normalizedEntries;
				$stats['regionEntries'] += $regionEntries;
				$stats['identicalExtraEntries'] += $valid - count($hashes);
				if (count($hashes) > 1) { $stats['divergentPages']++; }
				if (count($byIdentity) > 1) { $stats['pagesWithIdentityDifferences']++; }
				$comparable = false;
				$htmlDifferent = false;
				$headersDifferent = false;
				$volatileComparable = false;
				$volatileOnly = false;
				$residualHtml = false;
				$scriptOnly = false;
				$outsideScripts = false;
				$regionComparable = false;
				$headDifferent = false;
				$bodyDifferent = false;
				$shellDifferent = false;
				$bodyTextDifferent = false;
				$bodyMarkupOnly = false;
				foreach ($byIdentity as $group) {
					if (count($group) < 2) { continue; }
					$comparable = true;
					$bodyHashes = [];
					$headerHashes = [];
					$normalizedHashes = [];
					$scriptlessHashes = [];
					$regionHashes = ['head' => [], 'body' => [], 'shell' => [], 'bodyText' => []];
					$normalizedCount = 0;
					$regionCount = 0;
					foreach ($group as [$bodyHash, $headerHash, $normalizedHash, $scriptlessHash, $regions]) {
						$bodyHashes[$bodyHash] = true;
						$headerHashes[$headerHash] = true;
						if ($normalizedHash !== null && $scriptlessHash !== null) {
							$normalizedHashes[$normalizedHash] = true;
							$scriptlessHashes[$scriptlessHash] = true;
							$normalizedCount++;
						}
						if ($regions !== null) {
							foreach ($regionHashes as $part => &$values) {
								$values[$regions[$part]] = true;
							}
							unset($values);
							$regionCount++;
						}
					}
					// Require every member of this identity group to contain
					// exactly one of each expected metadata field.
					if ($normalizedCount === count($group)) {
						$volatileComparable = true;
						$stats['sameIdentityHtmlDuplicatesIgnoringVolatile'] +=
							count($group) - count($normalizedHashes);
						$stats['sameIdentityHtmlDuplicatesIgnoringScripts'] +=
							count($group) - count($scriptlessHashes);
						if (count($bodyHashes) > 1 && count($normalizedHashes) === 1) {
							$volatileOnly = true;
						}
						if (count($normalizedHashes) > 1) {
							$residualHtml = true;
							if (count($scriptlessHashes) === 1) {
								$scriptOnly = true;
							} else {
								$outsideScripts = true;
							}
						}
					}
					// Region statistics are compared only if every variant in
					// this revision group was recognized as a full document.
					if ($regionCount === count($group)) {
						$regionComparable = true;
						if (count($regionHashes['head']) > 1) { $headDifferent = true; }
						if (count($regionHashes['body']) > 1) {
							$bodyDifferent = true;
							if (count($regionHashes['bodyText']) > 1) {
								$bodyTextDifferent = true;
							} else {
								$bodyMarkupOnly = true;
							}
						}
						if (count($regionHashes['shell']) > 1) { $shellDifferent = true; }
					}
					$stats['sameIdentityHtmlDuplicates'] += count($group) - count($bodyHashes);
					$stats['sameIdentityHeaderDuplicates'] += count($group) - count($headerHashes);
					if (count($bodyHashes) > 1) { $htmlDifferent = true; }
					if (count($headerHashes) > 1) { $headersDifferent = true; }
				}
				if ($comparable) { $stats['comparablePages']++; }
				if ($htmlDifferent) { $stats['pagesWithHtmlDifferences']++; }
				if ($headersDifferent) { $stats['pagesWithHeaderDifferences']++; }
				if ($volatileComparable) { $stats['volatileComparablePages']++; }
				if ($volatileOnly && !$residualHtml) {
					$stats['pagesWithOnlyVolatileHtmlDifferences']++;
				}
				if ($residualHtml) { $stats['pagesWithResidualHtmlDifferences']++; }
				if ($scriptOnly && !$outsideScripts) {
					$stats['pagesWithResidualScriptOnlyDifferences']++;
				}
				if ($outsideScripts) { $stats['pagesWithResidualOutsideScripts']++; }
				if ($regionComparable) { $stats['regionComparablePages']++; }
				if ($headDifferent) { $stats['pagesWithResidualHeadDifferences']++; }
				if ($bodyDifferent) { $stats['pagesWithResidualBodyDifferences']++; }
				if ($shellDifferent) { $stats['pagesWithResidualShellDifferences']++; }
				if ($bodyTextDifferent) { $stats['pagesWithResidualBodyTextDifferences']++; }
				if ($bodyMarkupOnly && !$bodyTextDifferent) {
					$stats['pagesWithResidualBodyMarkupOnlyDifferences']++;
				}
			}
		}
		return $stats;
	}
}
