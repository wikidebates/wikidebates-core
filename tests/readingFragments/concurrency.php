<?php

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/processHarness.php';
use MediaWiki\Extension\Wikidebates\Reading\FragmentStore;

$directory = cacheTestDirectory();
$workers = [];
$held = null;
try {
	$store = new FragmentStore($directory, 'concurrency-test', 300, 4194304);
	$identity = ['revision' => 1];
	$snapshot = $store->read('A', 'variant', $identity);
	check($store->publish('A', 'variant', $identity, $snapshot['generation'], 'initial', [], time() + 300), 'Initial entry published');
	$lockPath = glob($directory . '/*/*/*/page.lock')[0];
	$inode = fileinode($lockPath);
	$held = fopen($lockPath, 'c');
	check(flock($held, LOCK_SH), 'Parent holds a shared lock');
	$workers[] = cacheWorkerStart($directory, ['operation' => 'read']);
	cacheWorkerGo($workers[0]);
	$result = cacheWorkerResult($workers[0]);
	same('initial', $result['result']['entry']['body'], 'A different process reads while the shared lock is held');
	fclose($held); $held = null;

	// All initializers start before any reads are allowed to run.
	for ($i = 0; $i < 8; $i++) {
		$workers[$i] = cacheWorkerStart($directory, ['operation' => 'read', 'page' => 'Cold']);
	}
	foreach ($workers as $worker) { cacheWorkerGo($worker); }
	$generations = [];
	foreach ($workers as &$worker) {
		$result = cacheWorkerResult($worker)['result'];
		check($result['entry'] === null, 'Cold read is a miss');
		$generations[] = $result['generation'];
	}
	unset($worker);
	same(1, count(array_unique($generations)), 'Concurrent cold readers initialize exactly one generation');

	// Pause inside the real publication pipeline before compression. Invalidation
	// must finish in another process before compression is allowed to continue.
	$workers[0] = cacheWorkerStart($directory, ['operation' => 'publish', 'pauseCompression' => true,
		'generation' => $snapshot['generation'], 'body' => 'stale']);
	cacheWorkerGo($workers[0]);
	same('COMPRESSING', cacheWorkerLine($workers[0]), 'Publisher reached the compression barrier');
	$workers[1] = cacheWorkerStart($directory, ['operation' => 'invalidate']);
	cacheWorkerGo($workers[1]);
	same(true, cacheWorkerResult($workers[1])['result'], 'Invalidation is not blocked by compression');
	cacheWorkerGo($workers[0]);
	same(false, cacheWorkerResult($workers[0])['result'], 'Prepared stale payload is rejected after the intervening purge');
	same(null, $store->read('A', 'variant', $identity)['entry'], 'No stale payload became visible');
	same([], glob(dirname($lockPath) . '/.write-*'), 'Rejected publisher cleans its temporary file');
	clearstatcache(true, $lockPath);
	same($inode, fileinode($lockPath), 'Readers, publishers and purges retain one lock domain');

	$fresh = $store->read('A', 'variant', $identity);
	check($store->publish('A', 'variant', $identity, $fresh['generation'], 'fresh', [], time() + 300), 'New generation can publish');
	check(!$store->publish('A', 'variant', $identity, $fresh['generation'], 'expired', [], time() - 1), 'Already expired writes are refused');
	check(!$store->publish('A', 'variant', $identity, $fresh['generation'], 'bad', ['bad' => "\xff"], time() + 300), 'Invalid JSON is refused');
	same('fresh', $store->read('A', 'variant', $identity)['entry']['body'], 'Failed writes preserve the valid entry');
	echo "OK: shared readers, eight cold initializers, purge during compression and stale publication guard\n";
} finally {
	if (is_resource($held)) { fclose($held); }
	foreach ($workers as &$worker) { cacheWorkerStop($worker); }
	unset($worker);
	cacheTestCleanup($directory);
}
