<?php

/**
 * End-to-end disposable multi-process cache backfill.
 * Requires the marked, temporary MediaWiki 1.43 lab used by coordinator CI.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('Parallel full-stack test requires a disposable marked lab');
}
$stateDir = $root . '/state';
touch($stateDir . '/consistent-cache-epoch');
touch($stateDir . '/unlimited-ttl');
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillJournal;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillState;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

$wiki = WikiMap::getCurrentWikiId();
$queue = new CachePreparationQueue($stateDir . '/preparation-queue', $wiki);
$journal = new ParallelBackfillJournal($queue, $wiki);
if ($journal->snapshot() !== null || $queue->dailyCursor() !== 0) {
	throw new RuntimeException('Parallel full-stack test must start with a clean private checkpoint');
}
$pageId = (int)Title::newFromText('Cache study argument')->getArticleID();
if ($pageId < 1) {
	throw new RuntimeException('Missing test argument');
}
// A real __NOCACHE__ magic-word page in a non-content namespace is
// deliberately uncacheable for Vector/Minerva and must not block the sweep.
$noCacheTitle = Title::newFromText('Talk:Explicit no cache');
$testUser = \MediaWiki\MediaWikiServices::getInstance()
	->getUserFactory()->newFromName('CacheStudyReader');
$noCachePage = \MediaWiki\MediaWikiServices::getInstance()
	->getWikiPageFactory()->newFromTitle($noCacheTitle);
$updater = $noCachePage->newPageUpdater($testUser);
$updater->setContent(\MediaWiki\Revision\SlotRecord::MAIN,
	\MediaWiki\Content\ContentHandler::makeContent(
		"__NOCACHE__\n== Deliberately uncached discussion ==\nThis body must not be snapshot.\n",
		$noCacheTitle
	)
);
$updater->saveRevision(
	\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('Disposable no-cache study')
);
if (!$updater->wasSuccessful()) {
	throw new RuntimeException('Failed to create disposable __NOCACHE__ test page');
}
\MediaWiki\Deferred\DeferredUpdates::doUpdates();
$noCacheId = (int)\MediaWiki\MediaWikiServices::getInstance()
	->getConnectionProvider()->getPrimaryDatabase()->selectField(
		'page', 'page_id',
		['page_namespace' => $noCacheTitle->getNamespace(),
			'page_title' => $noCacheTitle->getDBkey()], __METHOD__
	);
if ($noCacheId < 1) {
	throw new RuntimeException('Disposable no-cache page has no page ID');
}
// Reproduce a valid MediaWiki title whose native URL-encoded Vector cache
// filename is too long for Linux. Minerva and Reading must still be tried.
$longTitle = Title::newFromText('Long file ' . str_repeat('z', 240));
if (!$longTitle) {
	throw new RuntimeException('Cannot construct disposable long-title page');
}
$longPage = \MediaWiki\MediaWikiServices::getInstance()
	->getWikiPageFactory()->newFromTitle($longTitle);
$longUpdater = $longPage->newPageUpdater($testUser);
$longUpdater->setContent(\MediaWiki\Revision\SlotRecord::MAIN,
	\MediaWiki\Content\ContentHandler::makeContent(
		"== Summary ==\nA publicly readable page with a very long title.\n" .
		str_repeat("Substantial HTML output for a long title. ", 70),
		$longTitle
	)
);
$longUpdater->saveRevision(
	\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('Disposable long title')
);
if (!$longUpdater->wasSuccessful()) {
	throw new RuntimeException('Failed to create disposable long-title page');
}
\MediaWiki\Deferred\DeferredUpdates::doUpdates();
$longId = (int)\MediaWiki\MediaWikiServices::getInstance()
	->getConnectionProvider()->getPrimaryDatabase()->selectField(
		'page', 'page_id',
		['page_namespace' => $longTitle->getNamespace(),
			'page_title' => $longTitle->getDBkey()], __METHOD__
	);
if ($longId < 1) {
	throw new RuntimeException('Disposable long-title page has no page ID');
}
// Reproduce one uncacheable-for-technical-reasons Vector renderer. A safe
// backfill must save a retry record, continue every other page and finish
// the scan with this page explicitly unresolved.
$faultTitle = Title::newFromText('Synthetic Vector fault');
$faultPage = \MediaWiki\MediaWikiServices::getInstance()
	->getWikiPageFactory()->newFromTitle($faultTitle);
$faultUpdater = $faultPage->newPageUpdater($testUser);
$faultUpdater->setContent(\MediaWiki\Revision\SlotRecord::MAIN,
	\MediaWiki\Content\ContentHandler::makeContent(
		"== Render fault ==\n" . str_repeat("Visible safe public test body. ", 70),
		$faultTitle
	)
);
$faultUpdater->saveRevision(
	\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('Disposable Vector fault')
);
if (!$faultUpdater->wasSuccessful()) {
	throw new RuntimeException('Failed to create disposable Vector fault test page');
}
\MediaWiki\Deferred\DeferredUpdates::doUpdates();
$faultId = (int)\MediaWiki\MediaWikiServices::getInstance()
	->getConnectionProvider()->getPrimaryDatabase()->selectField(
		'page', 'page_id',
		['page_namespace' => $faultTitle->getNamespace(),
			'page_title' => $faultTitle->getDBkey()], __METHOD__
	);
if ($faultId < 1) {
	throw new RuntimeException('Disposable Vector fault page missing');
}
// The initial lab creates unrelated fixture edits, including deliberately
// uncacheable examples. Isolate one real direct edit for this integration test.
foreach ($queue->pending(10000) as $record) {
	if (!$queue->acknowledge($record['pageId'], $record['revisionId'],
		$record['generation'] ?? null)) {
		throw new RuntimeException('Cannot clear disposable fixture events');
	}
}
$primary = \MediaWiki\MediaWikiServices::getInstance()
	->getConnectionProvider()->getPrimaryDatabase();
$revision = (int)$primary->selectField('page', 'page_latest',
	['page_id' => $pageId], __METHOD__);
if ($revision < 1 || !$queue->enqueue($pageId, $revision)) {
	throw new RuntimeException('Cannot seed a single direct-edit queue record');
}
$iniDir = $stateDir . '/parallel-ini';
if (!mkdir($iniDir, 0700)) {
	throw new RuntimeException('Cannot initialize private PHP ini folder');
}
file_put_contents($iniDir . '/99-parallel-lab.ini',
	'auto_prepend_file=' . __DIR__ . '/bootstrap.php' . "\n");
putenv('PHP_INI_SCAN_DIR=:' . $iniDir);
$conf = $root . '/mediawiki/LocalSettings.php';
file_put_contents($conf, "<?php\n");
chmod($conf, 0600);
touch($conf, strtotime('2026-01-01 00:00:00 UTC'));

$server = proc_open(
	[PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		'-d', 'zlib.output_compression=0', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $stateDir . '/parallel-http.log', 'a'],
		['file', $stateDir . '/parallel-http.log', 'a']], $serverPipes
);
if (!is_resource($server)) {
	throw new RuntimeException('Cannot start disposable HTTP server');
}
fclose($serverPipes[0]);

try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$socket = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($socket) {
			fclose($socket);
			$ready = true;
			break;
		}
		usleep(100000);
	}
	if (!$ready) {
		throw new RuntimeException('No disposable HTTP listener');
	}
	$command = [
		PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		MW_INSTALL_PATH . '/maintenance/run.php', 'Wikidebates:prepareParallelCaches',
		'--wiki=' . $wiki, '--execute', '--workers=4', '--minerva-devices=android,iphone',
		'--minerva-delay-ms=250', '--max-load=100', '--reserve-mb=128',
		'--max-health-ms=15000', '--max-seconds=600',
		'--health-title=Cache study argument'
	];
	$process = proc_open($command,
		[['file', '/dev/null', 'r'],
			['file', $stateDir . '/parallel-supervisor.out', 'w'],
			['file', $stateDir . '/parallel-supervisor.err', 'w']],
		$pipes
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start parallel backfill supervisor');
	}
	$exit = proc_close($process);
	$out = (string)file_get_contents($stateDir . '/parallel-supervisor.out');
	$err = (string)file_get_contents($stateDir . '/parallel-supervisor.err');
	if ($exit !== 0) {
		throw new RuntimeException('Parallel backfill failed: ' . substr($out . $err, -4000));
	}
	$checkpoint = $journal->snapshot();
	if ($checkpoint === null || $checkpoint['state'] !== 'complete' ||
		!(new ParallelBackfillState($checkpoint['lanes']))->finished() ||
		$queue->dailyCursor() !== 0 ||
		($checkpoint['metrics']['completed'] ?? 0) < 1 ||
		($checkpoint['metrics']['skipped'] ?? 0) < 1 ||
		($checkpoint['metrics']['deferred'] ?? 0) < 1 ||
		$queue->current($faultId) === null ||
		!str_contains($out . $err, '"page_id":' . $noCacheId . ',"reason":"parser-expiry"') ||
		!str_contains($out . $err, '"mode":"scan-deferred"')) {
		throw new RuntimeException('Parallel scan missed pages or failed to finalize: ' .
			substr($out . $err, -3000));
	}
	$mobile = glob($stateDir . '/minerva-cache/*/*/*/*.cache') ?: [];
	$reading = glob($stateDir . '/reading-cache/*/*/*/*.cache') ?: [];
	if (count($mobile) < 2 || count($reading) < 1 ||
		$queue->current($pageId) !== null) {
		throw new RuntimeException('Real mobile or fragment cache missing after parallel fill');
	}
	$failureLedger = new \MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillFailures($queue);
	$faultSummary = $failureLedger->summary();
	if ($faultSummary['open'] < 1 || count(array_filter(
		$faultSummary['recent'],
		static fn(array $item): bool => $item['page_id'] === $faultId
	)) !== 1) {
		throw new RuntimeException('Fullscan lost its durable failed-page diagnostics');
	}
	$noCacheVector = new HTMLFileCache($noCacheTitle, 'view');
	if ($noCacheVector->isCached()) {
		throw new RuntimeException('Native Vector HTML must never be created for __NOCACHE__ test page');
	}
	// A real child invocation, not just a unit-level length calculation:
	// the command must accept a valid overlong page with an explicit safe
	// skip, without trying to write a native Vector file.
	$longCommand = [
		PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		MW_INSTALL_PATH . '/maintenance/run.php', 'Wikidebates:prepareVectorViewCache',
		'--wiki=' . $wiki, '--page-id=' . $longId, '--json', '--dry-run'
	];
	$longProbe = proc_open($longCommand, [
		['file', '/dev/null', 'r'],
		['pipe', 'w'], ['pipe', 'w']
	], $longPipes);
	if (!is_resource($longProbe)) {
		throw new RuntimeException('Cannot run long-title native Vector dry-run');
	}
	$longOutput = stream_get_contents($longPipes[1]);
	$longErrors = stream_get_contents($longPipes[2]);
	fclose($longPipes[1]);
	fclose($longPipes[2]);
	$longExit = proc_close($longProbe);
	$longReport = json_decode(trim($longOutput), true);
	if ($longExit !== 0 || ($longReport['state'] ?? null) !== 'SKIPPED_FILENAME_TOO_LONG' ||
		($longReport['pageId'] ?? null) !== $longId || trim($longErrors) !== '') {
		throw new RuntimeException('Native long-title Vector skip was not reported: ' .
			substr($longOutput . $longErrors, -2000));
	}
	$vector = new HTMLFileCache(Title::newFromText('Cache study argument'), 'view');
	if (!$vector->isCached()) {
		throw new RuntimeException('Real native Vector cache not prepared');
	}
	// Completed runs do not implicitly restart and overwrite the old files.
	$again = proc_open($command,
		[['file', '/dev/null', 'r'], ['file', $stateDir . '/parallel-repeat.out', 'w'],
			['file', $stateDir . '/parallel-repeat.err', 'w']], $repeatPipes);
	if (!is_resource($again)) {
		throw new RuntimeException('Cannot check completed cycle refusal');
	}
	$repeatExit = proc_close($again);
	if ($repeatExit === 0) {
		throw new RuntimeException('Completed parallel sweep restarted without --new-cycle');
	}
	// Recreate the state of a crash before any new page was committed.
	// A real supervisor must resume from that same durable journal, reuse
	// all existing files and reject the stale previous run generation.
	$existingHashes = [];
	foreach (array_merge($mobile, $reading) as $file) {
		$existingHashes[$file] = hash_file('sha256', $file);
	}
	$testCycle = $journal->activate(0, $checkpoint['high_watermark'], 4,
		$checkpoint['cache_signature'], true);
	$journal->failed($testCycle['run_id'], 1, $pageId, 'injected-worker-crash');
	$oldGeneration = $testCycle['run_id'];

	// Ordinary daily work must never pass a page in an unfinished parallel
	// lane. This command is invoked with the shared wiki lock available, so
	// its failure proves the durable journal guard, not a temporary lock.
	$ordinary = [
		PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		MW_INSTALL_PATH . '/maintenance/run.php', 'Wikidebates:prepareDailyCaches',
		'--wiki=' . $wiki, '--execute', '--limit=1', '--queue-limit=0',
		'--minerva-delay-ms=250', '--max-seconds=480'
	];
	$ordinaryProc = proc_open($ordinary, [
		['file', '/dev/null', 'r'],
		['file', $stateDir . '/daily-during-parallel.out', 'w'],
		['file', $stateDir . '/daily-during-parallel.err', 'w']
	], $ordinaryPipes);
	if (!is_resource($ordinaryProc)) {
		throw new RuntimeException('Cannot probe daily cursor protection');
	}
	$ordinaryExit = proc_close($ordinaryProc);
	if ($ordinaryExit === 0 || $queue->dailyCursor() !== 0) {
		throw new RuntimeException('Ordinary daily scan ignored unfinished parallel checkpoint');
	}

	$resumedProcess = proc_open($command,
		[['file', '/dev/null', 'r'],
			['file', $stateDir . '/parallel-resume.out', 'w'],
			['file', $stateDir . '/parallel-resume.err', 'w']], $resumePipes);
	if (!is_resource($resumedProcess)) {
		throw new RuntimeException('Cannot restart interrupted parallel scan');
	}
	$resumeExit = proc_close($resumedProcess);
	$resumeOut = (string)file_get_contents($stateDir . '/parallel-resume.out');
	$resumeErr = (string)file_get_contents($stateDir . '/parallel-resume.err');
	$recovered = $journal->snapshot();
	if ($resumeExit !== 0 || $recovered['state'] !== 'complete' ||
		$recovered['run_id'] === $oldGeneration ||
		$recovered['metrics']['cache_reused'] < 1) {
		throw new RuntimeException('Parallel resume failed or could not reuse any validated HTML: ' .
			substr($resumeOut . $resumeErr, -3500));
	}
	foreach ($existingHashes as $file => $hash) {
		if (!is_file($file) || hash_file('sha256', $file) !== $hash) {
			throw new RuntimeException('Pre-existing cache was rewritten after journal resume');
		}
	}
	echo json_encode([
		'result' => 'PASS', 'wiki' => $wiki, 'workers' => 4,
		'vector' => true, 'minerva' => count($mobile),
		'reading' => count($reading), 'metrics' => $checkpoint['metrics'],
		'completed' => true
	], JSON_THROW_ON_ERROR) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
