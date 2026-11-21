<?php

// Optional synthetic storage benchmark, never a production directory or database.
// php tests/readingFragments/concurrencyBenchmark.php [path/to/baseline/FragmentStore.php]
require __DIR__ . '/processHarness.php';
$storeFile = realpath($argv[1] ?? dirname(__DIR__, 2) . '/src/Reading/FragmentStore.php');
if (!$storeFile) { throw new RuntimeException('Store source not found'); }
require $storeFile;
use MediaWiki\Extension\Wikidebates\Reading\FragmentStore;

$directory = cacheTestDirectory();
$workers = [];
try {
	$store = new FragmentStore($directory, 'concurrency-test', 300, 4194304);
	$body = str_repeat('<p>Synthetic argument, reference and objection.</p>', 12000);
	$snapshot = $store->read('A', 'variant', ['revision' => 1]);
	if (!$store->publish('A', 'variant', ['revision' => 1], $snapshot['generation'], $body, [], time() + 300)) {
		throw new RuntimeException('Could not seed benchmark');
	}
	$report = ['php' => PHP_VERSION, 'source_sha256' => hash_file('sha256', $storeFile),
		'body_bytes' => strlen($body), 'iterations_per_worker' => 80, 'samples' => []];
	foreach ([1, 4] as $parallel) {
		for ($round = 0; $round < 3; $round++) {
			for ($i = 0; $i < $parallel; $i++) {
				$workers[$i] = cacheWorkerStart($directory, ['operation' => 'benchmark', 'storeFile' => $storeFile,
					'iterations' => 80, 'bodyBytes' => strlen($body)]);
			}
			$start = hrtime(true);
			foreach ($workers as $worker) { cacheWorkerGo($worker); }
			$durations = [];
			foreach ($workers as &$worker) { $durations[] = cacheWorkerResult($worker)['ms']; }
			unset($worker);
			$report['samples'][] = ['workers' => $parallel, 'wall_ms' => (hrtime(true) - $start) / 1e6, 'worker_ms' => $durations];
			$workers = [];
		}
	}
	echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} finally {
	foreach ($workers as &$worker) { cacheWorkerStop($worker); }
	unset($worker);
	cacheTestCleanup($directory);
}
