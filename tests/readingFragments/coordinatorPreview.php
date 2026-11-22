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
echo json_encode(['result' => 'PASS', 'mode' => 'preview',
	'pending' => count($before), 'acknowledged' => 0], JSON_THROW_ON_ERROR) . "\n";
