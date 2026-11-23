<?php

require dirname(__DIR__) . '/src/Store.php';
require dirname(__DIR__) . '/src/ResponsePolicy.php';
use MediaWiki\Extension\MinervaFileCache\Store;
use MediaWiki\Extension\MinervaFileCache\ResponsePolicy;

$assertions = 0;
function check($value, string $message): void {
	global $assertions;
	if (!$value) { throw new RuntimeException($message); }
	$assertions++;
}
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/extension.json'), true, 512, JSON_THROW_ON_ERROR);
check(($manifest['config']['MinervaFileCacheEnabled']['value'] ?? null) === true,
	'Loading the extension enables mobile file cache by default');
check(($manifest['config']['MinervaFileCacheTTL']['value'] ?? null) === 0,
	'Default Minerva cache does not expire by age');
$dir = sys_get_temp_dir() . '/minerva-store-unit-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
	$store = new Store($dir, 'wiki-one', 300, 4096);
	$identity = ['revision' => 1, 'touched' => '20261022000000'];
	// A cold page that never becomes cacheable must not allocate a directory,
	// lock file or generation file simply because it was viewed.
	$lazy = new Store($dir, 'lazy-wiki', 300, 4096);
	$unpublished = $lazy->readIfPresent('Uncacheable', 'android', $identity);
	check($unpublished['entry'] === null && $unpublished['fresh'] === true,
		'Optimistic cold read misses');
	check($lazy->statistics()['pages'] === 0, 'Uncacheable cold read creates no directory');
	$firstCandidate = $lazy->readIfPresent('Cacheable', 'android', $identity);
	check($lazy->publish('Cacheable', 'android', $identity, $firstCandidate['generation'],
		'FIRST_HTML', [], PHP_INT_MAX, true), 'Cold publication initializes a generation');
	$firstHit = $lazy->readIfPresent('Cacheable', 'android', $identity);
	check($firstHit['fresh'] === false && $firstHit['entry']['body'] === 'FIRST_HTML',
		'Published cold content becomes an ordinary valid HIT');
	// A purge between the cold snapshot and the first publish must prevent
	// stale HTML from ever creating a valid cache generation.
	$race = $lazy->readIfPresent('Concurrent_purge', 'android', $identity);
	$lazy->invalidate('Concurrent_purge');
	check(!$lazy->publish('Concurrent_purge', 'android', $identity, $race['generation'],
		'STALE_HTML', [], PHP_INT_MAX, true), 'Concurrent invalidation vetoes stale first publication');
	check($lazy->readIfPresent('Concurrent_purge', 'android', $identity)['entry'] === null,
		'Purged first render leaves no cached HTML');
	// Only the first simultaneous cold renderer may initialize an untracked page.
	$winner = $lazy->readIfPresent('First_wins', 'android', $identity);
	$loser = $lazy->readIfPresent('First_wins', 'android', $identity);
	check($lazy->publish('First_wins', 'android', $identity, $winner['generation'],
		'WINNER', [], PHP_INT_MAX, true), 'First cold renderer publishes');
	check(!$lazy->publish('First_wins', 'android', $identity, $loser['generation'],
		'LOSER', [], PHP_INT_MAX, true), 'Second cold renderer cannot overwrite the first');
	check($lazy->readIfPresent('First_wins', 'android', $identity)['entry']['body'] === 'WINNER',
		'Concurrent cold publications keep the first valid content');
	// Pre-existing page generations still use the ordinary locking protocol.
	$legacy = new Store($dir, 'pre-existing-wiki', 300, 4096);
	$legacySnapshot = $legacy->read('Existing', 'android', $identity);
	$legacyPeek = $legacy->readIfPresent('Existing', 'android', $identity);
	check(!$legacyPeek['fresh'] && $legacyPeek['generation'] === $legacySnapshot['generation'],
		'Existing page generation is respected by lazy reader');
	check($legacy->publish('Existing', 'android', $identity, $legacyPeek['generation'], 'LEGACY', []),
		'Pre-existing page remains writable without first-publication mode');


	$first = $store->read('A', 'android', $identity);
	check($first['entry'] === null, 'Cold read misses');
	$fill = $store->startFill('A');
	check(is_resource($fill) && $store->startFill('A') === false, 'Concurrent cache fills are excluded');
	check($store->publish('A', 'android', $identity, $first['generation'], '<html>Résumé</html>', ['content-type' => ['text/html']]), 'Atomic publication succeeds');
	fclose($fill);
	check($store->read('A', 'android', $identity)['entry']['body'] === '<html>Résumé</html>', 'Body bytes round-trip exactly');
	$cached = glob($dir . '/' . hash('sha256', 'wiki-one') . '/*/*/*.cache')[0];
	clearstatcache(true, $cached);
	check(filemtime($cached) > time(), 'New cache files store their expiry in mtime');
	check($store->prune() === 0, 'Prune preserves fresh files without decoding');
	check(touch($cached, time() - 5), 'Simulate an entry written by an older version');
	check($store->prune() === 0, 'Prune preserves a valid legacy file');
	clearstatcache(true, $cached);
	check(filemtime($cached) > time(), 'Prune migrates legacy metadata to the expiry timestamp');
	check(touch($cached, time() + 301), 'Simulate an implausible future timestamp');
	check($store->prune() === 0, 'Prune validates unexpected future timestamps');
	clearstatcache(true, $cached);
	check(filemtime($cached) <= time() + 300, 'Prune corrects unexpected future timestamps');
	check($store->read('A', 'iphone', $identity)['entry'] === null, 'User agent variants are isolated');
	$stats = $store->statistics();
	check($stats['pages'] === 1 && $stats['files'] === 1 &&
		$stats['bytes'] > 0 && $stats['maxVariantsPerPage'] === 1,
		'Statistics count stored files and variants without decoding payloads');
	check($stats['pagesWithMultipleVariants'] === 0 && $stats['variantDistribution']['one'] === 1,
		'A page with one variant is classified correctly');
	$fragmentVariant = json_encode(['https://fr.example.test/wiki/A', 'https', 'https://fr.example.test',
		'fr', 'fr.example.test', 'TestBrowser', 'fr', '']);
	check($store->publish('A', $fragmentVariant, $identity, $first['generation'], 'FRAGMENT',
		['content-type' => ['text/html']]), 'Publish a fragment-compatible variant');
	$stats = $store->statistics();
	check($stats['pagesWithMultipleVariants'] === 1 && $stats['variantDistribution']['twoToFive'] === 1 &&
		$stats['maxVariantsPerPage'] === 2, 'Two distinct variants are counted on one page');
	$audit = $store->analyzeVariants(1);
	check($audit['scannedPages'] === 1 && $audit['pagesWithMultipleFiles'] === 1 &&
		$audit['validEntries'] === 2 && $audit['divergentPages'] === 1 &&
		$audit['identicalExtraEntries'] === 0, 'Distinct mobile HTML remains distinguishable');
	$duplicateVariant = json_encode(['https://fr.example.test/wiki/A', 'https', 'https://fr.example.test',
		'fr', 'fr.example.test', 'AnotherBrowser', 'fr', '']);
	check($store->publish('A', $duplicateVariant, $identity, $first['generation'], 'FRAGMENT',
		['content-type' => ['text/html']]), 'Publish a separate but byte-identical variant');
	$audit = $store->analyzeVariants(1);
	check($audit['validEntries'] === 3 && $audit['identicalExtraEntries'] === 1 &&
		$audit['divergentPages'] === 1, 'Audit reports exactly identical duplicate cache entries');
	check($audit['comparablePages'] === 1 &&
		$audit['pagesWithIdentityDifferences'] === 0 &&
		$audit['pagesWithHtmlDifferences'] === 1 &&
		$audit['pagesWithHeaderDifferences'] === 0 &&
		$audit['sameIdentityHtmlDuplicates'] === 1 &&
		$audit['sameIdentityHeaderDuplicates'] === 2,
		'Audit separates HTML differences from identical headers within one revision');
	$headerVariant = 'browser-with-different-headers';
	check($store->publish('A', $headerVariant, $identity, $first['generation'], 'FRAGMENT',
		['content-type' => ['text/html'], 'vary' => ['User-Agent']]),
		'Publish identical HTML with different replayable headers');
	$audit = $store->analyzeVariants(1);
	check($audit['validEntries'] === 4 && $audit['identicalExtraEntries'] === 1 &&
		$audit['pagesWithHeaderDifferences'] === 1 &&
		$audit['sameIdentityHtmlDuplicates'] === 2 &&
		$audit['sameIdentityHeaderDuplicates'] === 2,
		'Audit identifies header-only differences without misclassifying repeated HTML');
	$olderIdentity = ['revision' => 0, 'touched' => '20261021000000'];
	check($store->publish('A', 'older-revision', $olderIdentity, $first['generation'],
		'FRAGMENT', ['content-type' => ['text/html']]),
		'Prepare a separate identity to test audit comparison boundaries');
	$audit = $store->analyzeVariants(1);
	check($audit['validEntries'] === 5 &&
		$audit['pagesWithIdentityDifferences'] === 1 &&
		$audit['sameIdentityHtmlDuplicates'] === 2 &&
		$audit['comparablePages'] === 1,
		'Audit never mixes differing revision identities in HTML comparison');
	check($store->analyzeVariants(30000, 250)['validEntries'] >= 5,
		'Full-wiki audit limits support large sites with over 10k directories and 200 variants');
	foreach ([[50001, 20], [100, 251]] as [$invalidPages, $invalidVariants]) {
		try {
			$store->analyzeVariants($invalidPages, $invalidVariants);
			throw new RuntimeException('Oversize audit limits accepted');
		} catch (InvalidArgumentException $e) {
			check(true, 'Unbounded audit limits are rejected');
		}
	}
	$audit = $store->analyzeVariants(1, 2);
	check($audit['truncatedPages'] === 1 && $audit['validEntries'] === 2,
		'Variant audit respects the per-page file limit');
	// A fresh MediaWiki request varies both its tracing ID and backend timing.
	// They must be ignored by diagnostics only, never by the serving cache.
	$volatileStore = new Store($dir, 'volatile-wiki', 300, 4096);
	$volatileHtml = static function (string $requestId, int $elapsed, string $content): string {
		return '<html><head><script>var RLCONF={"wgRequestId":"' . $requestId .
			'"};</script></head><body>' . $content .
			'</body><script>mw.config.set({"wgBackendResponseTime":' . $elapsed .
			'});</script></html>';
	};
	$volatileSnapshot = $volatileStore->read('Diagnostic', 'android', $identity);
	check($volatileStore->publish('Diagnostic', 'android', $identity,
		$volatileSnapshot['generation'], $volatileHtml('abc', 254, 'UNCHANGED'), []),
		'Publish HTML with per-request MediaWiki configuration');
	check($volatileStore->publish('Diagnostic', 'iphone', $identity,
		$volatileSnapshot['generation'], $volatileHtml('def', 247, 'UNCHANGED'), []),
		'Publish identical HTML except for request ID and backend time');
	$volatileAudit = $volatileStore->analyzeVariants(20, 20);
	check($volatileAudit['validEntries'] === 2 &&
		$volatileAudit['pagesWithHtmlDifferences'] === 1 &&
		$volatileAudit['volatileMetadataEntries'] === 2 &&
		$volatileAudit['volatileComparablePages'] === 1 &&
		$volatileAudit['pagesWithOnlyVolatileHtmlDifferences'] === 1 &&
		$volatileAudit['pagesWithResidualHtmlDifferences'] === 0 &&
		$volatileAudit['sameIdentityHtmlDuplicatesIgnoringVolatile'] === 1,
		'Audit explains otherwise-distinct HTML via the two request-specific config values');
	check($volatileStore->publish('Diagnostic', 'tablet', $identity,
		$volatileSnapshot['generation'], $volatileHtml('ghi', 300, 'REAL_DIFFERENCE'), []),
		'Publish HTML with a real content difference');
	$volatileAudit = $volatileStore->analyzeVariants(20, 20);
	check($volatileAudit['pagesWithOnlyVolatileHtmlDifferences'] === 0 &&
		$volatileAudit['pagesWithResidualHtmlDifferences'] === 1 &&
		$volatileAudit['pagesWithResidualOutsideScripts'] === 1 &&
		$volatileAudit['pagesWithResidualScriptOnlyDifferences'] === 0 &&
		$volatileAudit['sameIdentityHtmlDuplicatesIgnoringVolatile'] === 1,
		'Normalization cannot conceal unrelated content changes');
	check($volatileStore->publish('Diagnostic', 'unknown-metadata', $identity,
		$volatileSnapshot['generation'], '<html><body>NO_METADATA</body></html>', []),
		'Publish a variant without the expected MediaWiki configuration');
	$volatileAudit = $volatileStore->analyzeVariants(20, 20);
	check($volatileAudit['volatileMetadataEntries'] === 3 &&
		$volatileAudit['volatileComparablePages'] === 0 &&
		$volatileAudit['pagesWithOnlyVolatileHtmlDifferences'] === 0,
		'Missing config values do not lead to an unsupported equivalence claim');
	// Identify a residual HTML difference confined to an additional inline
	// script without confusing it with a difference in page markup.
	$scriptSnapshot = $volatileStore->read('Script_variation', 'android', $identity);
	foreach (['android' => 1, 'iphone' => 2] as $device => $counter) {
		$scriptHtml = $volatileHtml($device, 100 + $counter, 'SAME_MARKUP');
		$scriptHtml = str_replace('</body>', '<script>window.counter=' . $counter .
			';</script></body>', $scriptHtml);
		check($volatileStore->publish('Script_variation', $device, $identity,
			$scriptSnapshot['generation'], $scriptHtml, []),
			'Publish diagnostic HTML whose residual difference is script-only');
	}
	$volatileAudit = $volatileStore->analyzeVariants(20, 20);
	check($volatileAudit['pagesWithResidualScriptOnlyDifferences'] === 1 &&
		$volatileAudit['pagesWithResidualOutsideScripts'] === 0 &&
		$volatileAudit['sameIdentityHtmlDuplicatesIgnoringScripts'] >= 1,
		'Audit distinguishes residual script variation from actual markup differences');
	// Residual differences in full HTML are distinguished by region.
	// Request IDs and backend timings differ in every fixture, but must not
	// influence these region comparisons.
	$regionStore = new Store($dir, 'region-wiki', 300, 4096);
	$regionHtml = static function (
		string $id, int $ms, string $head, string $bodyClass, string $bodyText, string $lang = 'fr'
	): string {
		return '<!doctype html><html lang="' . $lang . '"><head><meta name="study" content="' .
			$head . '"><script>var RLCONF={"wgRequestId":"' . $id .
			'"};</script></head><body class="' . $bodyClass . '"><p>' . $bodyText .
			'</p><script>mw.config.set({"wgBackendResponseTime":' . $ms .
			'});</script></body></html>';
	};
	$regionCases = [
		'Head_change' => [
			$regionHtml('id1', 101, 'light', 'mobile', 'Same text'),
			$regionHtml('id2', 102, 'dark', 'mobile', 'Same text'),
		],
		'Body_attribute_change' => [
			$regionHtml('id3', 103, 'light', 'mobile', 'Same text'),
			$regionHtml('id4', 104, 'light', 'tablet', 'Same text'),
		],
		'Body_text_change' => [
			$regionHtml('id5', 105, 'light', 'mobile', 'Text A'),
			$regionHtml('id6', 106, 'light', 'mobile', 'Text B'),
		],
		'Document_shell_change' => [
			$regionHtml('id7', 107, 'light', 'mobile', 'Same text', 'fr'),
			$regionHtml('id8', 108, 'light', 'mobile', 'Same text', 'en'),
		],
	];
	foreach ($regionCases as $regionPage => [$html1, $html2]) {
		$regionSnapshot = $regionStore->read($regionPage, 'android', $identity);
		check($regionStore->publish($regionPage, 'android', $identity,
			$regionSnapshot['generation'], $html1, []), 'Store first regional audit variant');
		check($regionStore->publish($regionPage, 'iphone', $identity,
			$regionSnapshot['generation'], $html2, []), 'Store second regional audit variant');
	}
	$regionAudit = $regionStore->analyzeVariants(20, 20);
	check($regionAudit['regionEntries'] === 8 &&
		$regionAudit['regionComparablePages'] === 4 &&
		$regionAudit['pagesWithResidualHeadDifferences'] === 1 &&
		$regionAudit['pagesWithResidualBodyDifferences'] === 2 &&
		$regionAudit['pagesWithResidualShellDifferences'] === 1 &&
		$regionAudit['pagesWithResidualBodyTextDifferences'] === 1 &&
		$regionAudit['pagesWithResidualBodyMarkupOnlyDifferences'] === 1,
		'Regional audit correctly separates head, body text, body attributes and HTML shell');
	$fragmentContext = new class {
		public function getConfig() {
			return new class {
				public function get(string $key): string { return $key === 'Server' ? 'https://fr.example.test' : ''; }
			};
		}
		public function getRequest() {
			return new class {
				public function getHeader(string $name): string { return $name === 'Host' ? 'fr.example.test' : ''; }
				public function getProtocol(): string { return 'https'; }
			};
		}
		public function getLanguage() {
			return new class { public function getCode(): string { return 'fr'; } };
		}
	};
	$emptyFragmentStore = new Store($dir, 'fragment-only-wiki', 300, 4096);
	check($emptyFragmentStore->readForFragment('Never_cached', $identity, $fragmentContext) === null,
		'A fragment lookup safely misses without an existing page directory');
	check($emptyFragmentStore->statistics()['pages'] === 0,
		'A fragment-only MISS does not allocate cache directories or lock files');
	check($store->readForFragment('A', $identity, $fragmentContext)['body'] === 'FRAGMENT',
		'Fragment reuses a validated mobile variant');
	check($store->readForFragment('A', ['revision' => 99], $fragmentContext) === null,
		'Fragment rejects a mismatched revision');
	check($store->read('A', 'android', ['revision' => 2])['entry'] === null, 'Revision identity invalidates a file');
	$other = new Store($dir, 'wiki-two', 300, 4096);
	check($other->read('A', 'android', $identity)['entry'] === null, 'Wikis are isolated');
	$store->invalidate('A');
	check(!$store->publish('A', 'android', $identity, $first['generation'], 'STALE', []), 'Invalidation prevents an in-flight stale writer from publishing');
	check($store->read('A', 'android', $identity)['entry'] === null, 'Invalidation removes all variants');
	$new = $store->read('A', 'android', $identity);
	check(!$store->publish('A', 'android', $identity, $new['generation'], str_repeat('x', 4097), []), 'Oversize bodies are not cached');
	check($store->publish('A', 'android', $identity, $new['generation'], 'CURRENT', []), 'New generation can publish');
	$paths = glob($dir . '/' . hash('sha256', 'wiki-one') . '/*/*/*.cache');
	$path = $paths[0];
	$entry = json_decode(gzdecode(file_get_contents($path)), true);
	$entry['created'] = time() - 301;
	file_put_contents($path, gzencode(json_encode($entry)));
	check($store->read('A', 'android', $identity)['entry'] === null, 'Expired entries miss');
	check($store->prune() === 1 && !is_file($path), 'Cleanup removes expired payloads');
	$expiredPaths = [];
	foreach (['B', 'C', 'D'] as $otherPage) {
		$otherSnapshot = $store->read($otherPage, 'android', $identity);
		check($store->publish($otherPage, 'android', $identity, $otherSnapshot['generation'],
			'TO_BE_PRUNED', []), 'Publish another page for streaming prune');
		$wikiHash = hash('sha256', 'wiki-one');
		$pageHash = hash('sha256', $otherPage);
		$otherPath = $dir . '/' . $wikiHash . '/' . substr($pageHash, 0, 2) . '/' .
			$pageHash . '/' . hash('sha256', 'android') . '.cache';
		$otherEntry = json_decode(gzdecode(file_get_contents($otherPath)), true);
		$otherEntry['created'] = time() - 301;
		file_put_contents($otherPath, gzencode(json_encode($otherEntry)));
		touch($otherPath, time() - 1);
		$expiredPaths[] = $otherPath;
	}
	check($store->prune() === count($expiredPaths), 'Streaming prune removes entries across page directories');
	foreach ($expiredPaths as $expiredPath) {
		check(!is_file($expiredPath), 'Streaming prune removed an expired variant');
	}

	$store->publish('A', 'android', $identity, $new['generation'], 'CURRENT', []);
	file_put_contents($path, 'corrupt payload');
	check($store->read('A', 'android', $identity)['entry'] === null, 'Corrupt entries miss without executing data');
	$html = '<html><body class="skin-minerva">' . str_repeat('Résumé ', 100) . '</body></html>';
	$headers = ['Content-Type: text/html; charset=UTF-8', 'Vary: Cookie, User-Agent, Accept-Encoding',
		'Cache-Control: private, must-revalidate, max-age=0', 'Content-Security-Policy: default-src \'self\''];
	check(ResponsePolicy::headersForStorage($html, $headers) !== null, 'Static CSP and supported Vary can be replayed');
	foreach (['Set-Cookie: personal=yes', 'Vary: X-Personal', 'Vary: *', 'Content-Encoding: gzip',
		'Cache-Control: no-store', 'Cache-Control: no-cache', "Content-Security-Policy: script-src 'nonce-private'",
		'X-Unknown-Personal: private'] as $extra) {
		check(ResponsePolicy::headersForStorage($html, [...$headers, $extra]) === null, 'Reject unsafe response: ' . $extra);
	}
	check(ResponsePolicy::headersForStorage(str_replace('<body', '<body nonce="private"', $html), $headers) === null, 'Nonce markup is never replayed');
	check(ResponsePolicy::headersForStorage(str_replace('skin-minerva', 'skin-vector', $html), $headers) === null, 'Desktop content cannot enter mobile cache');
	// No TTL: old snapshots remain valid even when a historical payload has
	// an expired 30-day deadline or a very old creation timestamp.
	$unlimited = new Store($dir, 'unlimited-wiki', 0, 4096);
	$noAge = $unlimited->read('Old', 'android', $identity);
	check($unlimited->publish('Old', 'android', $identity, $noAge['generation'],
		'UNCHANGED_FOR_MONTHS', [], time() + 2),
		'Eligible parser output may be retained without a time limit');
	$oldPath = glob($dir . '/' . hash('sha256', 'unlimited-wiki') . '/*/*/*.cache')[0];
	$oldEntry = json_decode(gzdecode(file_get_contents($oldPath)), true, 512, JSON_THROW_ON_ERROR);
	$oldEntry['created'] = time() - 86400 * 90;
	$oldEntry['expires'] = $oldEntry['created'] + 2592000;
	file_put_contents($oldPath, gzencode(json_encode($oldEntry, JSON_THROW_ON_ERROR)));
	check($unlimited->read('Old', 'android', $identity)['entry']['body'] === 'UNCHANGED_FOR_MONTHS',
		'Legacy 30-day expiry does not invalidate an unlimited file');
	check($unlimited->prune() === 0 && is_file($oldPath),
		'Unlimited cleanup does not delete old snapshots');
	check($unlimited->publish('Old', $fragmentVariant, $identity, $noAge['generation'],
		'UNCHANGED_FRAGMENT', [], time() + 2),
		'Unlimited Minerva HTML remains a valid source for reading fragments');
	$mobileFragment = $unlimited->readForFragment('Old', $identity, $fragmentContext);
	check($mobileFragment['body'] === 'UNCHANGED_FRAGMENT' &&
		$mobileFragment['expires'] === PHP_INT_MAX,
		'Fragment fallback inherits unlimited Minerva file lifetime');
	check($unlimited->read('Old', 'android', ['revision' => 2])['entry'] === null,
		'Unlimited lifetime never bypasses page revision identity');
	$unlimited->invalidate('Old');
	check($unlimited->read('Old', 'android', $identity)['entry'] === null,
		'Direct invalidation still clears unlimited files');

	// An explicit TTL can exceed 30 days; parser expiry does not shorten it.
	$longTtl = new Store($dir, 'long-ttl-wiki', 86400 * 90, 4096);
	$longShot = $longTtl->read('Old', 'android', $identity);
	check($longTtl->publish('Old', 'android', $identity, $longShot['generation'],
		'LONG_TTL', [], time() + 2), 'Explicit TTL publishes independently of parser expiry');
	$longEntry = $longTtl->read('Old', 'android', $identity)['entry'];
	check($longEntry['expires'] === $longEntry['created'] + 86400 * 90,
		'Explicit 90-day limit is honored without a 30-day cap');
	foreach ([-1, -86400] as $badTtl) {
		try {
			new Store($dir, 'invalid-ttl', $badTtl, 4096);
			throw new RuntimeException('Negative TTL was accepted');
		} catch (RuntimeException $e) {
			check($e->getMessage() === 'Cache directory or limits are not usable',
				'Negative TTL remains invalid');
		}
	}

	$alias = new Store($dir . '/../' . basename($dir), 'wiki-one', 300, 4096);
	$snapshot = $alias->read('A', 'canonical-path', $identity);
	check($alias->publish('A', 'canonical-path', $identity, $snapshot['generation'], 'CANONICAL', []),
		'Equivalent noncanonical directory paths still publish atomically');
	echo json_encode(['assertions' => $assertions, 'result' => 'PASS']) . "\n";
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
	rmdir($dir);
}
