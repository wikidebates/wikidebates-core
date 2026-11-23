<?php

/**
 * Verify that the opt-in Vector migration checkpoints separately, is bounded,
 * refuses overlapping workers, prioritizes edits and never touches histories.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('Vector rebuild test requires a marked disposable wiki');
}
$state = $root . '/state';
// MediaWiki maintenance/run.php resolves an extension CLI by its installed
// extensions/<name>/maintenance directory. Earlier targeted test commands
// invoked the script by path and did not need this disposable symlink.
$extensionLink = $root . '/mediawiki/extensions/Wikidebates';
if (file_exists($extensionLink) || is_link($extensionLink) ||
	!symlink(dirname(__DIR__, 2), $extensionLink)) {
	throw new RuntimeException('Could not create isolated Wikidebates maintenance registration');
}
$queueDir = $state . '/preparation-queue';
if (!is_dir($queueDir) && !mkdir($queueDir, 0700)) {
	throw new RuntimeException('Could not create disposable queue directory');
}
putenv('WD_VECTOR_TEST_SKIN=vector-2022');
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

$wiki = MediaWiki\WikiMap\WikiMap::getCurrentWikiId();
$queue = new MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue($queueDir, $wiki);
$dailyBefore = $queue->dailyCursor();
$fixture = MediaWiki\Title\Title::newFromText('Vector 2022 native parity fixture');
$subject = MediaWiki\Title\Title::newFromText('Cache study argument');
if (!$fixture || !$subject || $fixture->getArticleID() < 1) {
	throw new RuntimeException('Previous parity fixtures must be present');
}
$history = static function () use ($fixture): array {
	clearstatcache(true);
	$file = new HTMLFileCache($fixture, 'history');
	if (!$file->isCached()) { throw new RuntimeException('Native fixture history is missing'); }
	$html = $file->fetchText();
	return [hash('sha256', $html), $file->cacheTimestamp()];
};
$historyBefore = $history();
// The coordinator launches a grandchild php maintenance process for each
// Vector page. Carry the lab-only bootstrap through to those children, as in
// coordinatorFullstack.php; production boots through normal LocalSettings.
$iniDir = $state . '/vector-batch-php-ini';
if (!mkdir($iniDir, 0700)) {
	throw new RuntimeException('Cannot create private disposable PHP ini directory');
}
if (file_put_contents($iniDir . '/99-vector-batch-lab.ini',
	'auto_prepend_file=' . __DIR__ . '/bootstrap.php' . "\n") === false) {
	throw new RuntimeException('Cannot configure lab-only grandchildren');
}
putenv('PHP_INI_SCAN_DIR=:' . $iniDir);

$normalView = new HTMLFileCache($subject, 'view');
if (!$normalView->isCached()) { throw new RuntimeException('Missing prepared native view fixture'); }
$normalViewBefore = $normalView->fetchText();

$run = static function (array $args) use ($root): array {
	$command = [
		PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		dirname(__DIR__, 2) . '/maintenance/rebuildVectorViews.php',
		'--conf=' . $root . '/mediawiki/LocalSettings.php', '--json', ...$args
	];
	$proc = proc_open($command,
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	if (!is_resource($proc)) { throw new RuntimeException('Cannot launch Vector batch maintenance'); }
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($proc) !== 0) {
		throw new RuntimeException('Vector batch maintenance failed: ' . substr($out . $err, -1600));
	}
	$data = json_decode(trim($out), true);
	if (!is_array($data) || ($data['type'] ?? '') !== 'wikidebates-vector-view-rebuild') {
		throw new RuntimeException('Invalid bounded Vector batch JSON: ' . substr($out, -400));
	}
	return $data;
};
$settings = [
	'--execute', '--limit=2', '--max-seconds=90', '--delay-ms=0', '--max-load=256'
];
if ($queue->vectorRebuildCheckpoint() !== null) {
	throw new RuntimeException('Unexpected existing Vector rebuild checkpoint');
}
$status = $run(['--status']);
$preview = $run(['--limit=3']);
if ($status['stop_reason'] !== 'not-started' ||
	($preview['checkpoint'] ?? null) !== null || count($preview['pages'] ?? []) === 0 ||
	$queue->vectorRebuildCheckpoint() !== null) {
	throw new RuntimeException('Read-only Vector status or preview mutated its checkpoint');
}
$worker = $queue->tryLockWorker();
if (!$worker) { throw new RuntimeException('Cannot lock disposable wiki worker'); }
try {
	$blocked = $run($settings);
	if ($blocked['stop_reason'] !== 'worker-busy' ||
		$queue->vectorRebuildCheckpoint() !== null) {
		throw new RuntimeException('Vector batch did not reject overlapping wiki worker');
	}
} finally {
	fclose($worker);
}
$page = MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($subject);
$pageId = (int)$subject->getArticleID();
$revisionId = (int)$page->getLatest();
if (!$queue->enqueue($pageId, $revisionId)) {
	throw new RuntimeException('Cannot seed disposable priority edit');
}
try {
	$deferred = $run($settings);
	if ($deferred['stop_reason'] !== 'pending-edits' ||
		$queue->vectorRebuildCheckpoint() !== null) {
		throw new RuntimeException('Vector batch started while priority edits awaited processing');
	}
} finally {
	$record = $queue->current($pageId);
	if ($record === null || !$queue->acknowledge(
		$pageId, $revisionId, $record['generation'] ?? null
	)) {
		throw new RuntimeException('Cannot clean up disposable priority edit');
	}
}

$previous = 0;
$reports = [];
$completed = false;
for ($i = 0; $i < 30; $i++) {
	$report = $run($settings);
	$reports[] = $report;
	$current = $queue->vectorRebuildCheckpoint();
	if ($current === null || $current['after'] < $previous ||
		$current['after'] > $current['high_watermark'] ||
		$report['checkpoint'] !== $current ||
		$report['stop_reason'] === 'page-failed' ||
		$report['stop_reason'] === 'worker-busy') {
		throw new RuntimeException('Vector batch lost its independent safe cursor: ' .
			json_encode($report, JSON_THROW_ON_ERROR));
	}
	if (count($report['pages']) > 2) {
		throw new RuntimeException('Vector batch exceeded its bounded page limit');
	}
	$previous = $current['after'];
	if ($current['state'] === 'complete') {
		$completed = true;
		break;
	}
}
if (!$completed || $previous < $pageId) {
	throw new RuntimeException('Resumable Vector migration did not reach the final page');
}
$afterStatus = $run(['--status']);
$afterRetry = $run($settings);
if (($afterStatus['checkpoint']['state'] ?? null) !== 'complete' ||
	$afterRetry['stop_reason'] !== 'cycle-complete' ||
	$queue->dailyCursor() !== $dailyBefore ||
	$historyBefore !== $history()) {
	throw new RuntimeException('Completed view migration touched normal cursors or history cache');
}
clearstatcache(true);
$normalAfter = (new HTMLFileCache($subject, 'view'))->fetchText();
if (!str_contains($normalAfter, 'ARGUMENT_VERSION_ONE') ||
	!str_contains($normalAfter, 'skin-vector-2022') ||
	strlen($normalAfter) < 512) {
	throw new RuntimeException('Native Vector view not preserved after forced migration');
}
$totalRebuilt = array_sum(array_column($reports, 'rebuilt'));
if ($totalRebuilt < 2 || $normalViewBefore === '' ||
	(new HTMLFileCache($fixture, 'history'))->isCached() === false) {
	throw new RuntimeException('Vector-only sweep did not rebuild existing views');
}
echo json_encode([
	'result' => 'PASS', 'wiki' => $wiki,
	'processed_batches' => count($reports),
	'view_rebuilt' => $totalRebuilt,
	'cursor_after' => $previous, 'history_unchanged' => true,
	'daily_cursor_unchanged' => true, 'busy_rejected' => true,
	'pending_edits_prioritized' => true
], JSON_THROW_ON_ERROR) . "\n";
