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

	ensureIdentity(CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'SKIPPED_CACHE_POLICY',
		'minerva' => 'BYPASS-parser-expiry'
	]), 'Explicit no-cache policy is valid with one mobile device');
	ensureIdentity(!CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'SKIPPED_NONCONTENT',
		'minerva' => 'android=BYPASS-parser-expiry;iphone=HTTP_403'
	]), 'HTTP errors must never be treated as a no-cache skip');
	ensureIdentity(!CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'SKIPPED_NONCONTENT',
		'minerva' => 'android=BYPASS-parser-expiry;android=BYPASS-parser-expiry'
	]), 'Duplicate device statuses cannot prove the whole cache policy');
	ensureIdentity(!CachePreparationCoordinator::hasExplicitNoCachePolicy([
		'vector' => 'SKIPPED_NONCONTENT',
		'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry',
		'reading' => 'created'
	]), 'A published Reading fragment cannot be classified as an empty cache');
	ensureIdentity(CachePreparationCoordinator::isExplicitNoCacheRace([
		'state' => 'retry-page-changed', 'revisionId' => 100,
		'phases' => [
			'vector' => 'SKIPPED_NONCONTENT',
			'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry'
		]
	]), 'Page-touched race after both noncacheable variants is eligible for a guarded skip');
	ensureIdentity(!CachePreparationCoordinator::isExplicitNoCacheRace([
		'state' => 'retry-page-changed', 'revisionId' => 101,
		'phases' => [
			'vector' => 'REUSED',
			'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry'
		]
	]), 'An actual cached file blocks a page-touched skip');

	$race = [
		'pageId' => 6429, 'revisionId' => 101, 'state' => 'retry-page-changed',
		'phases' => [
			'vector' => 'SKIPPED_NONCONTENT',
			'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry'
		]
	];
	$readStable = static fn(int $id): ?array => $id === 6429 ?
		['revisionId' => 101, 'isRedirect' => false] : null;
	$publicStable = static fn(int $id): ?string => $id === 6429 ?
		hash('sha256', 'current-public-identity') : null;
	ensureIdentity(CachePreparationCoordinator::canCheckpointNoCacheRace(
		$race, $readStable, $publicStable
	), 'Repeated touch changes with the same public revision must allow safe skip');
	$readNewRevision = static fn(int $id): ?array =>
		['revisionId' => 102, 'isRedirect' => false];
	ensureIdentity(!CachePreparationCoordinator::canCheckpointNoCacheRace(
		$race, $readNewRevision, $publicStable
	), 'Newly edited revision may not inherit the old __NOCACHE__ classification');
	$readRedirect = static fn(int $id): ?array =>
		['revisionId' => 101, 'isRedirect' => true];
	ensureIdentity(!CachePreparationCoordinator::canCheckpointNoCacheRace(
		$race, $readRedirect, $publicStable
	), 'Redirected page may not be treated as the old version');
	ensureIdentity(!CachePreparationCoordinator::canCheckpointNoCacheRace(
		$race, $readStable, static fn(int $id): ?string => null
	), 'Anonymous ACL or revision suppression must still block a stale skip');
	$unstableReads = 0;
	$readConcurrentEdit = static function (int $id) use (&$unstableReads): ?array {
		$unstableReads++;
		return [
			'revisionId' => $unstableReads === 1 ? 101 : 102,
			'isRedirect' => false
		];
	};
	ensureIdentity(!CachePreparationCoordinator::canCheckpointNoCacheRace(
		$race, $readConcurrentEdit, $publicStable
	), 'Revision change between PRIMARY snapshots must fail closed');



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
