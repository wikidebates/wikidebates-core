<?php

require_once dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';
require_once dirname(__DIR__, 2) . '/src/Reading/ParallelBackfillFailures.php';
require_once dirname(__DIR__, 2) . '/src/Reading/ParallelBackfillHealth.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillFailures;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillHealth;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$now = 1800000000;
$session = str_repeat('a', 32);
$running = [
	'state' => 'running', 'run_id' => $session,
	'heartbeat_at' => $now - 10, 'stop_requested' => false
];
$assert(ParallelBackfillHealth::incident(null, $now) === null &&
	ParallelBackfillHealth::incident($running, $now) === null,
	'Missing and healthy active checkpoints are not incidents');
$running['stop_requested'] = true;
$assert(ParallelBackfillHealth::incident($running, $now) === null,
	'A planned graceful stop gets a heartbeat grace period');
$running['heartbeat_at'] = $now - 901;
$stale = ParallelBackfillHealth::incident($running, $now);
$assert($stale['type'] === 'supervisor-unresponsive' &&
	$stale['reason'] === 'heartbeat-stale' &&
	$stale['at'] === $now - 901 &&
	$stale['key'] === ParallelBackfillHealth::incident($running, $now + 500)['key'],
	'A stale supervisor is identified once per session, without repeated alerts');
$failure = [
	'state' => 'failed', 'run_id' => $session, 'heartbeat_at' => $now,
	'last_failure' => ['reason' => 'worker-exit-unknown', 'lane' => -1,
		'page_id' => 0, 'at' => $now - 1]
];
$incident = ParallelBackfillHealth::incident($failure, $now);
$assert($incident['type'] === 'supervisor-failed' &&
	$incident['reason'] === 'worker-exit-unknown' && $incident['page_id'] === 0,
	'System-wide supervisor failure is reported without any page failure');
$failure['last_failure']['reason'] = "unsafe\nHTML";
$assert(ParallelBackfillHealth::incident($failure, $now)['reason'] === 'invalid-outcome',
	'Unexpected error messages cannot be leaked into mail');

$done = [
	'state' => 'complete', 'run_id' => $session, 'heartbeat_at' => $now - 3700
];
$assert(ParallelBackfillHealth::incident($done, $now) === null,
	'Completed FR does not produce an incident');
$missing = ParallelBackfillHealth::missingNextWiki($done, $now);
$assert($missing['type'] === 'next-backfill-missing' &&
	$missing['reason'] === 'checkpoint-not-created',
	'Missing EN after an hour of completed FR is a supervisor incident');
$done['heartbeat_at'] = $now - 120;
$assert(ParallelBackfillHealth::missingNextWiki($done, $now) === null,
	'Normal handoff from FR to EN is not an alert');
$done['state'] = 'running';
$assert(ParallelBackfillHealth::missingNextWiki($done, $now) === null,
	'Waiting for unfinished FR must not alert on EN');

$root = sys_get_temp_dir() . '/wd-backfill-alert-unit-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
	throw new RuntimeException('Cannot make disposable test directory');
}
try {
	$queue = new CachePreparationQueue($root, 'incidenttest');
	$ledger = new ParallelBackfillFailures($queue);
	$calls = 0;
	$deliver = static function (array $summary) use (&$calls): bool {
		$calls++;
		return $summary['open'] === 0 &&
			$summary['system']['type'] === 'supervisor-failed';
	};
	$assert($ledger->summary()['open'] === 0 &&
		$ledger->alert($deliver) === 'empty' && $calls === 0,
		'Empty page ledger without supervisor failure sends nothing');
	$assert($ledger->alert($deliver, 3600, $incident) === 'sent' &&
		$ledger->alert($deliver, 3600, $incident) === 'unchanged' &&
		$calls === 1,
		'Supervisor-only incident is delivered and deduplicated');
	$assert($ledger->alert($deliver, 3600, $missing) === 'rate-limited',
		'A second incident cannot flood mail inside the hourly rate limit');
	$assert($ledger->alert($deliver) === 'empty',
		'Recovery restores clean empty reporting');
	echo 'OK: ' . $checks . " supervisor alert assertions\n";
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
