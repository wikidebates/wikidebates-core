<?php

require_once dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';
require_once dirname(__DIR__, 2) . '/src/Reading/DailyCacheScanIncident.php';
require_once dirname(__DIR__, 2) . '/src/Reading/ParallelBackfillFailures.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\DailyCacheScanIncident;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillFailures;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$reject = static function (callable $action) use ($assert): void {
	try {
		$action();
	} catch (RuntimeException | InvalidArgumentException $e) {
		$assert(true, 'Invalid private incident was rejected');
		return;
	}
	throw new RuntimeException('Unsafe daily incident accepted');
};
$root = sys_get_temp_dir() . '/wd-daily-cache-health-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
	throw new RuntimeException('Cannot make disposable test directory');
}
try {
	$queue = new CachePreparationQueue($root, 'testwikidebates');
	$health = new DailyCacheScanIncident($queue);
	$ledger = new ParallelBackfillFailures($queue);
	$assert($health->incident() === null && $ledger->summary()['open'] === 0,
		'New wiki has no incident and no page error');
	$health->failed('retry-required', 812);
	$incident = $health->incident();
	$assert($incident !== null && $incident['type'] === 'daily-scan-failed' &&
		$incident['page_id'] === 812 && $incident['reason'] === 'retry-required',
		'An inability to advance the daily cursor is persisted');
	$key = $incident['key'];
	$health->failed('retry-required', 812);
	$assert($health->incident()['key'] === $key,
		'Rechecking the same blocked page does not create a new alert identity');
	$sent = 0;
	$assert($ledger->alert(static function (array $data) use (&$sent): bool {
		$sent++;
		return $data['open'] === 0 &&
			$data['system']['type'] === 'daily-scan-failed';
	}, 3600, $incident) === 'sent' && $sent === 1,
		'Daily scan with no page failures can still send one aggregated alert');
	$health->failed('systemic-page-errors', 815);
	$assert($health->incident()['key'] !== $key,
		'Distinct system-wide daily incident has a new identity');
	$health->resolved();
	$assert($health->incident() === null,
		'Later successful scan closes a prior global incident');

	$reject(static fn() => $health->failed("bad\nreason", 18));
	$reject(static fn() => $health->failed('retry-required', -2));

	$path = $queue->parallelDirectory() . '/daily-scan-incident.json';
	unlink($path);
	file_put_contents($path, '{bad}');
	$reject(static fn() => $health->incident());
	unlink($path);
	symlink($root . '/nowhere', $path);
	$reject(static fn() => $health->incident());

	echo 'OK: ' . $checks . " durable daily scan incident assertions\n";
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
