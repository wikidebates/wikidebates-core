<?php

/**
 * Reproduce a legitimate non-content page with parser-expiry vetoes, whose
 * PRIMARY page_touched or revision changes between the mobile and Reading
 * phases. A new attempt with FRESH identity must pass every phase again.
 */
require_once __DIR__ . '/../../src/Reading/CachePreparationQueue.php';
require_once __DIR__ . '/../../src/Reading/CachePreparationCoordinator.php';
require_once __DIR__ . '/../../src/Reading/ParallelBackfillRetryPolicy.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillRetryPolicy;

function ensureIdentity(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

$root = sys_get_temp_dir() . '/wd-backfill-identity-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
	throw new \RuntimeException('Cannot create disposable identity test directory');
}
try {
	$coordinator = new CachePreparationCoordinator(new CachePreparationQueue($root, 'testwikidebates'));
	$latestRevision = 100;
	$touched = 'touch-1';
	$phaseCalls = [];
	$touchOnce = true;
	$revisionOnce = false;
	$latest = static function (int $id) use (&$latestRevision): ?int {
		return $id === 6429 ? $latestRevision : null;
	};
	$public = static function (int $id) use (&$touched): ?string {
		return $id === 6429 ? hash('sha256', $touched) : null;
	};
	$prepare = static function (string $phase, int $id) use (
		&$phaseCalls, &$touched, &$latestRevision, &$touchOnce, &$revisionOnce
	): array {
		$phaseCalls[] = [$id, $phase];
		if ($phase === 'minerva' && $touchOnce) {
			$touchOnce = false;
			$touched = 'touch-2';
		} elseif ($phase === 'minerva' && $revisionOnce) {
			$revisionOnce = false;
			$latestRevision++;
		}
		return ['ok' => true, 'status' => match ($phase) {
			'vector' => 'SKIPPED_NONCONTENT',
			'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry',
			'reading' => 'not-an-argument'
		}];
	};

	$first = $coordinator->processUnqueued(6429, $latestRevision, $latest, $prepare, $public);
	ensureIdentity($first['state'] === 'retry-page-changed' &&
		$first['phases']['minerva'] === 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry',
		'Page-touched change must never be checkpointed despite cache-policy vetoes');
	ensureIdentity(ParallelBackfillRetryPolicy::identityDelayMicroseconds($first, 0) === 250000,
		'Page may receive a bounded retry');
	$second = $coordinator->processUnqueued(6429, $latest(6429), $latest, $prepare, $public);
	ensureIdentity($second['state'] === 'skipped-cache-policy' &&
		$second['phases']['reading'] === 'skipped-cache-policy',
		'Stable no-cache page must be safely skipped');
	ensureIdentity(count($phaseCalls) === 4,
		'Skip must not invoke the Reading renderer on a no-cache page');

	// A real edit must similarly invalidate the old revision; retrying
	// against the old revision would get stuck in retry-page-changed.
	$revisionOnce = true;
	$third = $coordinator->processUnqueued(6429, $latest(6429), $latest, $prepare, $public);
	ensureIdentity($third['state'] === 'retry-page-changed' && $latest(6429) === 101,
		'Actual revision changes must invalidate the in-flight preparation');
	$fourth = $coordinator->processUnqueued(6429, $latest(6429), $latest, $prepare, $public);
	ensureIdentity($fourth['state'] === 'skipped-cache-policy',
		'New PRIMARY revision must also honor explicit no-cache policy');
	ensureIdentity(count(array_filter($phaseCalls,
		static fn(array $call): bool => $call[1] === 'reading')) === 0,
		'No Reading cache may be generated for explicit no-cache policy');

	// A real direct-edit queue record may be acknowledged after all identity
	// and generation checks even though the page opts out of caching.
	$queue = new CachePreparationQueue($root, 'testwikidebates');
	ensureIdentity($queue->enqueue(6429, $latestRevision),
		'Queue record should be present for the current revision');
	$queuedCoordinator = new CachePreparationCoordinator($queue);
	$queuedResult = $queuedCoordinator->process(
		$queue->current(6429), $latest, $prepare, $public
	);
	ensureIdentity($queuedResult['state'] === 'acknowledged' &&
		$queuedResult['phases']['reading'] === 'skipped-cache-policy' &&
		$queue->current(6429) === null,
		'Explicit no-cache queue item should not remain indefinitely pending');

	// Merely seeing parser expiry is insufficient if a previous phase has
	// created or reused HTML; such a page must retain all safety checks.
	ensureIdentity(!CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'CREATED',
		'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry'
	]), 'Skip forbidden after native Vector HTML was created');
	ensureIdentity(!CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'SKIPPED_NONCONTENT',
		'minerva' => 'android=HIT;iphone=BYPASS-parser-expiry'
	]), 'Skip forbidden if a Minerva file was reused');

	echo "OK: non-content page_touched and revision races retry with fresh identity\n";
} finally {
	$files = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $file) {
		if ($file->isDir()) {
			rmdir($file->getPathname());
		} else {
			unlink($file->getPathname());
		}
	}
	rmdir($root);
}
