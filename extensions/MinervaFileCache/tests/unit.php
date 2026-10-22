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
	check($store->read('A', 'iphone', $identity)['entry'] === null, 'User agent variants are isolated');
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
