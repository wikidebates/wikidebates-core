<?php

require_once __DIR__ . '/../../src/Reading/CachePreparationQueue.php';
require_once __DIR__ . '/../../src/Reading/ParallelBackfillState.php';
require_once __DIR__ . '/../../src/Reading/CachePreparationCoordinator.php';
require_once __DIR__ . '/../../src/Reading/ParallelBackfillJournal.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillJournal;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillState;

function parallelAssert(bool $yes, string $message): void {
	if (!$yes) {
		throw new \RuntimeException($message);
	}
}

function mustReject(callable $action): void {
	try {
		$action();
	} catch (\InvalidArgumentException | \RuntimeException $e) {
		return;
	}
	throw new \RuntimeException('Invalid state unexpectedly accepted');
}

$root = sys_get_temp_dir() . '/wd-parallel-backfill-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
	throw new \RuntimeException('Cannot create private test root');
}
try {
	$signature = hash('sha256', 'known initial cache configuration');
	$queue = new CachePreparationQueue($root, 'testwikidebates');
	$journal = new ParallelBackfillJournal($queue, 'testwikidebates');
	parallelAssert($journal->snapshot() === null, 'Missing journal must be null');

	$first = $journal->activate(100, 111, 3, $signature);
	$token1 = $first['run_id'];
	parallelAssert(count($first['lanes']) === 3, 'Three disjoint lanes');
	parallelAssert($first['desired_workers'] === 2, 'Conservative initial worker limit');

	$laneLock = $journal->tryLockLane(2);
	parallelAssert(is_resource($laneLock), 'First lane claim');
	parallelAssert($journal->tryLockLane(2) === false, 'Duplicate worker prevented');
	fclose($laneLock);

	$journal->completed($token1, 2, 109, 'complete', [
		'vector' => 'CREATED', 'minerva' => 'android=HIT;iphone=MISS',
		'reading' => 'already-valid'
	]);
	$journal->completed($token1, 2, 110, 'complete', []);
	$journal->completed($token1, 2, 111, 'complete', []);
	$journal->exhausted($token1, 2);
	$saved = $journal->snapshot();
	parallelAssert((new ParallelBackfillState($saved['lanes']))->contiguousAfter() === 100,
		'Finished later lane must not skip earlier pages');
	parallelAssert($saved['metrics']['cache_created'] === 2 &&
		$saved['metrics']['cache_reused'] === 2, 'Phase status counters');
	parallelAssert($saved['metrics']['by_phase']['vector']['created'] === 1 &&
		$saved['metrics']['by_phase']['minerva']['created'] === 1 &&
		$saved['metrics']['by_phase']['minerva']['reused'] === 1 &&
		$saved['metrics']['by_phase']['reading']['reused'] === 1,
		'Per-phase creation and reuse counters');


	mustReject(fn() => $journal->completed($token1, 0, 101, 'retry', []));
	mustReject(fn() => $journal->completed($token1, 2, 111, 'complete', []));
	mustReject(fn() => $journal->activate(100, 111, 4, $signature));
	mustReject(fn() => $journal->activate(101, 111, 3, $signature));
	mustReject(fn() => $journal->activate(100, 111, 3,
		hash('sha256', 'changed cache configuration')));
	mustReject(fn() => $journal->activate(100, 111, 3, $signature, true));

	$journal->cooldown($token1, 30);
	$cooldown = $journal->snapshot()['cooldown_until'];
	parallelAssert($cooldown >= time() + 29, 'Transient throttle must reach every lane');
	$journal->cooldown($token1, 10);
	parallelAssert($journal->snapshot()['cooldown_until'] === $cooldown,
		'Shorter throttle must not shorten the barrier');
	mustReject(fn() => $journal->cooldown($token1, 121));

	$journal->failed($token1, 0, 101, 'HTTP_503');
	$failed = $journal->snapshot();
	parallelAssert($failed['last_failure']['page_id'] === 101 &&
		$failed['state'] === 'failed' &&
		$failed['lanes'][0]['after'] === 100, 'Failure cannot advance cursor');
	$journal->failed($token1, 2, 111, 'HTTP_404');
	parallelAssert($journal->snapshot()['last_failure']['page_id'] === 101,
		'First failure reason must not be replaced');
	mustReject(fn() => $journal->completed($token1, 0, 101, 'complete', []));
	mustReject(fn() => $journal->exhausted($token1, 0));
	mustReject(fn() => $journal->cooldown($token1, 10));


	$resumed = $journal->activate(100, 999, 3, $signature);
	$token2 = $resumed['run_id'];
	parallelAssert($token2 !== $token1 && $resumed['high_watermark'] === 111 &&
		$resumed['lanes'][2]['done'], 'Restart resumes original bounds and completed lanes');
	parallelAssert($resumed['cooldown_until'] === 0,
		'Resume must clear old transient cooldown but keep completed lanes');

	mustReject(fn() => $journal->completed($token1, 0, 101, 'complete', []));
	mustReject(fn() => $journal->markComplete($token2));

	$journal->heartbeat($token2, 3);
	parallelAssert($journal->snapshot()['desired_workers'] === 3, 'Adaptive worker permits');
	$journal->completed($token2, 0, 102, 'complete', ['vector' => 'REUSED']);
	$journal->exhausted($token2, 0);
	parallelAssert((new ParallelBackfillState($journal->snapshot()['lanes']))->contiguousAfter() === 104,
		'Completed first lane allows frontier to reach its end');
	$journal->completed($token2, 1, 105, 'skipped-nonpublic', []);
	$journal->exhausted($token2, 1);
	parallelAssert((new ParallelBackfillState($journal->snapshot()['lanes']))->finished(),
		'All lanes exhausted');
	$journal->markComplete($token2);
	parallelAssert($journal->snapshot()['state'] === 'complete', 'Final state');
	mustReject(fn() => $journal->activate(100, 111, 3, $signature));
	$newCycle = $journal->activate(0, 111, 2, $signature, true);
	parallelAssert($newCycle['start_after'] === 0, 'New cycle explicitly resets lanes');
	$journal->stop();
	parallelAssert($journal->snapshot()['stop_requested'], 'Stop request persists');

	$checkpointPath = $queue->parallelDirectory() . '/checkpoint.json';
	file_put_contents($checkpointPath, '{"version":1,"wiki":"testwikidebates","lanes":[]}');
	mustReject(fn() => $journal->snapshot());
	echo "OK: atomic journal, out-of-order lanes, restart, stale tokens and stop\n";
} finally {
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $item) {
		if ($item->isDir()) {
			rmdir($item->getPathname());
		} else {
			unlink($item->getPathname());
		}
	}
	rmdir($root);
}
