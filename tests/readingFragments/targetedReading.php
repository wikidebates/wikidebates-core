<?php

/** Run a single-argument cache prewarm in the disposable MediaWiki 1.43 lab. */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('A marked disposable MediaWiki lab is required');
}
$db = new PDO('sqlite:' . $root . '/state/minerva_file_cache_test.sqlite');
$argumentId = (int)$db->query("SELECT page_id FROM page WHERE page_title = 'Cache_study_argument'")->fetchColumn();
$nonArgumentId = (int)$db->query("SELECT page_id FROM page WHERE page_title = 'Another_public_page'")->fetchColumn();
if ($argumentId < 1 || $nonArgumentId < 1) {
	throw new RuntimeException('Missing initialized fixture pages');
}
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
$conf = $root . '/state/LocalSettings.php';
file_put_contents($conf, "<?php\n");
chmod($conf, 0600);

$invoke = static function (array $options) use ($conf): array {
	$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		dirname(__DIR__, 2) . '/maintenance/prepareReadingCache.php', '--conf=' . $conf,
		'--json', ...$options];
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot run reading-cache maintenance'); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr,
		'data' => json_decode(trim($stdout), true)];
};

$first = $invoke(['--page-id=' . $argumentId, '--sleep-ms=0']);
if ($first['exit'] !== 0 || !is_array($first['data']) ||
	($first['data']['target_page_id'] ?? null) !== $argumentId ||
	($first['data']['inspected'] ?? null) !== 1 ||
	($first['data']['complete'] ?? false) !== true ||
	($first['data']['statuses']['created'] ?? 0) !== 1) {
	throw new RuntimeException('Single-argument preparation failed: ' .
		substr($first['stdout'] . $first['stderr'], -1500));
}
$again = $invoke(['--page-id=' . $argumentId, '--sleep-ms=0']);
if ($again['exit'] !== 0 || ($again['data']['statuses']['already-valid'] ?? 0) !== 1) {
	throw new RuntimeException('Prepared argument was not reused: ' . $again['stdout'] . $again['stderr']);
}
$other = $invoke(['--page-id=' . $nonArgumentId, '--sleep-ms=0']);
if ($other['exit'] !== 0 || ($other['data']['inspected'] ?? -1) !== 0 ||
	($other['data']['target_page_id'] ?? null) !== $nonArgumentId) {
	throw new RuntimeException('Nonargument page ID must not warm a neighboring argument');
}
$invalid = $invoke(['--page-id=0']);
if ($invalid['exit'] === 0 || !str_contains($invalid['stdout'] . $invalid['stderr'], 'Invalid --page-id')) {
	throw new RuntimeException('Zero page ID was not rejected');
}
$conflict = $invoke(['--page-id=' . $argumentId, '--after=0']);
if ($conflict['exit'] === 0 ||
	!str_contains($conflict['stdout'] . $conflict['stderr'], '--page-id cannot be combined')) {
	throw new RuntimeException('Targeted preparation incorrectly accepted a global cursor');
}
echo json_encode(['result' => 'PASS', 'argumentId' => $argumentId,
	'created' => 1, 'reused' => 1, 'otherInspected' => 0], JSON_THROW_ON_ERROR) . "\n";
