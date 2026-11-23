<?php

require_once __DIR__ . '/../../src/Reading/ParallelBackfillWorkerExit.php';

use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillWorkerExit;

function checkExit(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$normal = ['running' => false, 'exitcode' => 0, 'signaled' => false, 'termsig' => 0];
$unknown = ['running' => false, 'exitcode' => -1, 'signaled' => false, 'termsig' => 0];
$signal = ['running' => false, 'exitcode' => -1, 'signaled' => true, 'termsig' => 9];
$bad = ['running' => false, 'exitcode' => 1, 'signaled' => false, 'termsig' => 0];

checkExit(
	ParallelBackfillWorkerExit::decide($normal, 0, false, 0)['action'] === 'finished',
	'A completed lane must accept a clean child exit'
);
checkExit(
	ParallelBackfillWorkerExit::decide($unknown, -1, false, 0)['action'] === 'finished',
	'A completed durable lane must not be failed by an unavailable exit code'
);
checkExit(
	ParallelBackfillWorkerExit::decide($unknown, -1, true, 0)['action'] === 'restart',
	'An unfinished lane with exitcode -1 must restart, never be marked complete'
);
checkExit(
	ParallelBackfillWorkerExit::decide($unknown, -1, true, 2)['action'] === 'fatal',
	'Repeated unknown exits must fail closed'
);
checkExit(
	ParallelBackfillWorkerExit::decide($normal, 0, true, 0)['reason'] ===
		'worker-premature-exit',
	'An exit(0) cannot fake a missing durable lane-complete checkpoint'
);
checkExit(
	ParallelBackfillWorkerExit::decide($bad, 1, true, 0)['action'] === 'restart' &&
	ParallelBackfillWorkerExit::decide($bad, 1, true, 2)['action'] === 'fatal',
	'An actual failing worker must never retry without a finite budget'
);
checkExit(
	ParallelBackfillWorkerExit::decide($signal, 0, true, 0)['reason'] ===
		'worker-signal-9',
	'An uncaught SIGKILL must never be interpreted as success after proc_close'
);
checkExit(
	ParallelBackfillWorkerExit::decide($unknown, 0, true, 0)['reason'] ===
		'worker-premature-exit',
	'Use a real proc_close exit code after an unavailable status on PHP >= 8.3'
);
checkExit(
	ParallelBackfillWorkerExit::decide($unknown, 3, true, 0)['reason'] ===
		'worker-exit-3',
	'Recover an actual nonzero close exit code when status was unavailable'
);
checkExit(
	ParallelBackfillWorkerExit::decide($signal, -1, false, 0)['action'] === 'finished',
	'An already-stopped session must not spawn new workers after a signal'
);
try {
	ParallelBackfillWorkerExit::decide(['running' => true] + $normal, -1, true, 0);
	throw new RuntimeException('A running child was accepted as exited');
} catch (InvalidArgumentException $e) {
	// Expected.
}

// Verify a genuine PHP subprocess, not only fabricated proc_get_status data.
foreach ([0, 3] as $expected) {
	$proc = proc_open(
		[PHP_BINARY, '-r', 'exit(' . $expected . ');'],
		[['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes
	);
	if (!is_resource($proc)) {
		throw new RuntimeException('Cannot start child for process-status test');
	}
	$status = proc_get_status($proc);
	$deadline = microtime(true) + 5;
	while ($status['running'] && microtime(true) < $deadline) {
		usleep(10000);
		$status = proc_get_status($proc);
	}
	fclose($pipes[1]);
	fclose($pipes[2]);
	$closeExit = proc_close($proc);
	checkExit(!$status['running'] && !$status['signaled'],
		'Child must terminate normally for this test');
	$decision = ParallelBackfillWorkerExit::decide($status, $closeExit, true, 0);
	checkExit($decision['exit_code'] === $expected && $decision['action'] === 'restart',
		'Actual PHP child exit code must be recovered correctly');
}
echo "OK: PHP worker exit codes, unknown -1, signal diagnostics and bounded restart\n";
