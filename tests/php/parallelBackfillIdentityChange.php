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
	ensureIdentity($second['state'] === 'complete' &&
		$second['phases']['reading'] === 'not-an-argument',
		'Stable revalidation must accept a page with no cacheable anonymous output');
	ensureIdentity(count($phaseCalls) === 5,
		'Second attempt must redo Vector, Minerva and Reading in order');

	// A real edit must similarly invalidate the old revision; retrying
	// against the old revision would get stuck in retry-page-changed.
	$revisionOnce = true;
	$third = $coordinator->processUnqueued(6429, $latest(6429), $latest, $prepare, $public);
	ensureIdentity($third['state'] === 'retry-page-changed' && $latest(6429) === 101,
		'Actual revision changes must invalidate the in-flight preparation');
	$fourth = $coordinator->processUnqueued(6429, $latest(6429), $latest, $prepare, $public);
	ensureIdentity($fourth['state'] === 'complete',
		'New PRIMARY revision must pass a complete fresh guarded preparation');

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
