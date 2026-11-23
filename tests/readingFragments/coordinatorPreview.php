<?php

/** Prove that the default coordinator is read-only in disposable MediaWiki. */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_dir($root . '/state/preparation-queue')) {
	throw new RuntimeException('A prepared, marked and queue-enabled lab is required');
}
$state = $root . '/state';
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
$conf = $state . '/LocalSettings.php';
file_put_contents($conf, "<?php\n");
chmod($conf, 0600);

$records = static function () use ($state): array {
	$files = glob($state . '/preparation-queue/*/*.json') ?: [];
	sort($files);
	return array_map(static fn(string $path): string => hash_file('sha256', $path), $files);
};
$before = $records();
if (count($before) < 2) { throw new RuntimeException('Direct fixture edits must be queued first'); }
$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	dirname(__DIR__, 2) . '/maintenance/preparePendingCaches.php',
	'--conf=' . $conf, '--json', '--limit=5'];
$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start read-only preview'); }
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process) !== 0) {
	throw new RuntimeException('Preview CLI failed: ' . substr($stdout . $stderr, -1500));
}
$report = json_decode(trim($stdout), true);
if (!is_array($report) || ($report['mode'] ?? null) !== 'preview' ||
	($report['sampled'] ?? 0) < 2 || ($report['acknowledged'] ?? -1) !== 0 ||
	count(array_filter($report['pages'] ?? [],
		static fn(array $row): bool => ($row['state'] ?? '') !== 'preview')) !== 0) {
	throw new RuntimeException('CLI preview did not remain read-only: ' . $stdout);
}
if ($records() !== $before || (glob($state . '/reading-cache/*/*/*/*.cache') ?: []) !== [] ||
	(glob($state . '/minerva-cache/*/*/*/*.cache') ?: []) !== []) {
	throw new RuntimeException('Preview unexpectedly altered the pending journal or HTML caches');
}
// The daily coordinator shares the same queue but adds a read-only page sweep.
// A preview must not create a cursor, cache files, or acknowledge pending edits.
$daily = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	dirname(__DIR__, 2) . '/maintenance/prepareDailyCaches.php',
	'--conf=' . $conf, '--json', '--limit=5', '--queue-limit=2'];
$dailyProcess = proc_open($daily, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $dailyPipes);
if (!is_resource($dailyProcess)) { throw new RuntimeException('Cannot start daily preview'); }
fclose($dailyPipes[0]);
$dailyOut = stream_get_contents($dailyPipes[1]);
$dailyErr = stream_get_contents($dailyPipes[2]);
fclose($dailyPipes[1]);
fclose($dailyPipes[2]);
$dailyExit = proc_close($dailyProcess);
$dailyReport = json_decode(trim($dailyOut), true);
if ($dailyExit !== 0 || !is_array($dailyReport) ||
	($dailyReport['mode'] ?? null) !== 'preview' ||
	($dailyReport['queue_processed'] ?? 0) < 1 ||
	($dailyReport['scan_processed'] ?? 0) < 1 ||
	($dailyReport['start_after'] ?? null) !== 0 ||
	$records() !== $before ||
	(glob($state . '/preparation-queue/*/daily-cursor.json') ?: []) !== [] ||
	(glob($state . '/reading-cache/*/*/*/*.cache') ?: []) !== [] ||
	(glob($state . '/minerva-cache/*/*/*/*.cache') ?: []) !== []) {
	throw new RuntimeException('Daily preview mutated state or failed: ' .
		substr($dailyOut . $dailyErr, -2000));
}

echo json_encode(['result' => 'PASS', 'mode' => 'preview',
	'pending' => count($before), 'acknowledged' => 0], JSON_THROW_ON_ERROR) . "\n";
