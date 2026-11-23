<?php

/** Assert that the targeted native view prewarm never creates history HTML. */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('Only a previously initialized disposable MediaWiki lab is supported');
}
$database = new PDO('sqlite:' . $root . '/state/minerva_file_cache_test.sqlite');
$pageId = (int)$database->query("SELECT page_id FROM page WHERE page_title = 'Cache_study_argument'")->fetchColumn();
if ($pageId < 1) { throw new RuntimeException('No fixture page to prewarm'); }
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
$localSettings = $root . '/state/LocalSettings.php';
file_put_contents($localSettings, "<?php\n");
chmod($localSettings, 0600);

$run = static function (int $id, bool $dryRun = false, array $extra = []) use ($root): array {
	$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		dirname(__DIR__, 2) . '/maintenance/prepareVectorViewCache.php',
		'--conf=' . $root . '/state/LocalSettings.php', '--page-id=' . $id, '--json'];
	if ($dryRun) { $command[] = '--dry-run'; }
	array_push($command, ...$extra);
	$proc = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	if (!is_resource($proc)) { throw new RuntimeException('Cannot start Vector view maintenance'); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	$status = proc_close($proc);
	if ($status !== 0) { throw new RuntimeException('Vector maintenance failed: ' . substr($stdout . $stderr, -1700)); }
	$lines = preg_split('/\R/', trim($stdout));
	$report = json_decode(end($lines), true);
	if (!is_array($report) || ($report['pageId'] ?? null) !== $id) {
		throw new RuntimeException('Vector maintenance did not return a valid result: ' . $stdout);
	}
	return $report;
};

$dryRun = $run($pageId, true);
if (($dryRun['state'] ?? '') !== 'MISSING_FILE') {
	throw new RuntimeException('Dry-run must report a missing view without creating HTML: ' . json_encode($dryRun));
}

putenv('WD_VECTOR_TEST_VETO=1');
try { $veto = $run($pageId); }
finally { putenv('WD_VECTOR_TEST_VETO'); }
if (($veto['state'] ?? '') !== 'SKIPPED_CACHE_POLICY') {
	throw new RuntimeException('Public policy veto must not be reported as nonpublic: ' . json_encode($veto));
}

$denyRead = $root . '/state/deny-read';
if (!touch($denyRead)) { throw new RuntimeException('Cannot create isolated read-veto fixture'); }
try { $nonpublic = $run($pageId, true); }
finally { unlink($denyRead); }
if (($nonpublic['state'] ?? '') !== 'SKIPPED_NONPUBLIC') {
	throw new RuntimeException('Nonpublic page must still fail closed: ' . json_encode($nonpublic));
}

$first = $run($pageId);
if (($first['state'] ?? '') !== 'CREATED') {
	throw new RuntimeException('Expected a newly created native view file: ' . json_encode($first));
}
$second = $run($pageId);
if (($second['state'] ?? '') !== 'REUSED') {
	throw new RuntimeException('Valid native view must be reused without regeneration: ' . json_encode($second));
}
$forcePreview = $run($pageId, true, ['--force-view', '--existing-only']);
if (($forcePreview['state'] ?? '') !== 'WOULD_REBUILD_VIEW') {
	throw new RuntimeException('Force-view preview did not detect existing native file');
}
$forced = $run($pageId, false, ['--force-view', '--existing-only']);
if (($forced['state'] ?? '') !== 'CREATED' || ($forced['rebuilt_view'] ?? false) !== true) {
	throw new RuntimeException('Force-view did not rebuild a valid native view file');
}
$anotherId = (int)$database->query(
	"SELECT page_id FROM page WHERE page_title = 'Another_public_page'"
)->fetchColumn();
if ($anotherId < 1) { throw new RuntimeException('No missing-view fixture'); }
$skip = $run($anotherId, false, ['--force-view', '--existing-only']);
if (($skip['state'] ?? '') !== 'SKIPPED_MISSING_VIEW') {
	throw new RuntimeException('Existing-only rebuilt a missing native view file');
}

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

$title = MediaWiki\Title\Title::newFromText('Cache study argument');
if (!$title) { throw new RuntimeException('Invalid fixture title'); }
$view = new HTMLFileCache($title, 'view');
$history = new HTMLFileCache($title, 'history');
if (!$view->isCacheGood($title->getTouched()) || $history->isCached()) {
	throw new RuntimeException('Vector view or history files do not match the view-only contract');
}
$html = $view->fetchText();
if (!str_contains($html, 'ARGUMENT_VERSION_ONE') || !str_contains($html, 'skin-vector')) {
	throw new RuntimeException('Native Vector file did not contain complete anonymous HTML');
}
echo json_encode(['result' => 'PASS', 'pageId' => $pageId,
	'dryRun' => $dryRun['state'], 'policyVeto' => $veto['state'],
	'nonpublic' => $nonpublic['state'], 'first' => $first['state'], 'second' => $second['state'],
	'historyCreated' => false, 'forcePreview' => $forcePreview['state'],
	'forced' => $forced['state'], 'skipMissingView' => $skip['state']], JSON_THROW_ON_ERROR) . "\n";
