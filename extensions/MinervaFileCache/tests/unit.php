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
$dir = sys_get_temp_dir() . '/minerva-store-unit-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
	$store = new Store($dir, 'wiki-one', 300, 4096);
	$identity = ['revision' => 1, 'touched' => '20261022000000'];
	$first = $store->read('A', 'android', $identity);
	check($first['entry'] === null, 'Cold read misses');
	$fill = $store->startFill('A');
	check(is_resource($fill) && $store->startFill('A') === false, 'Concurrent cache fills are excluded');
	check($store->publish('A', 'android', $identity, $first['generation'], '<html>Résumé</html>', ['content-type' => ['text/html']]), 'Atomic publication succeeds');
	fclose($fill);
	check($store->read('A', 'android', $identity)['entry']['body'] === '<html>Résumé</html>', 'Body bytes round-trip exactly');
	$cached = glob($dir . '/*/*/*/*.cache')[0];
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
	$paths = glob($dir . '/*/*/*/*.cache');
	$path = $paths[0];
	$entry = json_decode(gzdecode(file_get_contents($path)), true);
	$entry['expires'] = time() - 1;
	file_put_contents($path, gzencode(json_encode($entry)));
	check($store->read('A', 'android', $identity)['entry'] === null, 'Expired entries miss');
	check($store->prune() === 1 && !is_file($path), 'Cleanup removes expired payloads');
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
