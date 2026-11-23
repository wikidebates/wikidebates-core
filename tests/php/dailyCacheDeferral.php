<?php

require_once dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';
require_once dirname(__DIR__, 2) . '/src/Reading/CachePreparationCoordinator.php';
require_once dirname(__DIR__, 2) . '/src/Reading/ParallelBackfillFailures.php';
require_once dirname(__DIR__, 2) . '/src/Reading/DailyCacheDeferral.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillFailures;
use MediaWiki\Extension\Wikidebates\Reading\DailyCacheDeferral;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$root = sys_get_temp_dir() . '/wd-daily-deferral-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
	throw new RuntimeException('Cannot make disposable test directory');
}
try {
	$queue = new CachePreparationQueue($root, 'testwikidebates');
	$failures = new ParallelBackfillFailures($queue);
	$deferral = new DailyCacheDeferral($queue, $failures);
	$coordinator = new CachePreparationCoordinator($queue);
	$failure = $coordinator->processUnqueued(
		101, 301, static fn(int $id): ?int => 301,
		static function (string $phase, int $id): array {
			if ($phase !== 'vector') {
				throw new RuntimeException('Later phases cannot start after Vector failure');
			}
			return ['ok' => false, 'status' => 'HTTP_503'];
		},
		static fn(int $id): ?string => 'public-revision-301'
	);
	$assert($failure['state'] === 'retry' && count($failure['phases']) === 1,
		'Coordinator detects a failed first phase and does not publish subsequent HTML');
	$assert($deferral->enqueue(101, static fn(int $id): ?int => 301, 'HTTP_503'),
		'Isolated failed page must have a durable retry record');
	$assert(($queue->current(101)['revisionId'] ?? null) === 301 &&
		$failures->summary()['open'] === 1 &&
		$queue->dailyCursor() === 0,
		'Retry and error are durable, while caller alone owns cursor progress');

	$assert(!$deferral->enqueue(102, static fn(int $id): ?int => null, 'HTTP_500') &&
		$queue->current(102) === null && $failures->summary()['open'] === 1,
		'Missing PRIMARY revision cannot be checkpointed as deferred');
	$assert(!$deferral->enqueue(102, static fn(int $id): ?int => 0, 'HTTP_500'),
		'Invalid PRIMARY revision remains an error');

	$queue->enqueue(103, 402);
	$assert($deferral->enqueue(103, static function (int $id) use ($queue): ?int {
		$queue->enqueue($id, 405);
		return 403;
	}, 'HTTP_503'), 'Concurrent edit must not prevent safe deferral');
	$assert(($queue->current(103)['revisionId'] ?? null) === 405 &&
		in_array(103, array_column($failures->summary()['recent'], 'page_id'), true),
		'Deferred scan cannot replace a newer queued edit');

	$generation = $queue->enqueueInvalidation(104, 501, gmdate('YmdHis', time() + 300));
	$assert(is_string($generation) && strlen($generation) === 32,
		'Semantic invalidation establishes a separate generation');
	$assert(!$deferral->enqueue(104, static fn(int $id): ?int => 501, 'HTTP_503') &&
		$queue->current(104)['schema'] === 3 &&
		$failures->summary()['open'] === 2,
		'Deferred scan cannot overwrite an unready semantic touch barrier');

	$ready = $coordinator->process(
		$queue->current(101),
		static fn(int $id): ?int => 301,
		static fn(string $phase, int $id): array => ['ok' => true, 'status' => 'REUSED'],
		static fn(int $id): ?string => 'public-revision-301'
	);
	$assert($ready['state'] === 'acknowledged' && $queue->current(101) === null,
		'Later queue-only processing can safely acknowledge the same page');
	$failures->resolved(101);
	$assert($failures->summary()['open'] === 1 &&
		$failures->summary()['resolved'] === 1,
		'Successful retry resolves the incident but preserves its history');

	echo 'OK: ' . $checks . " daily cache deferral assertions\n";
} finally {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $file) {
		if ($file->isDir() && !$file->isLink()) {
			rmdir($file->getPathname());
		} else {
			unlink($file->getPathname());
		}
	}
	rmdir($root);
}
