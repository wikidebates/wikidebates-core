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
		'--wiki=' . $wiki, '--execute', '--workers=2', '--minerva-devices=android',
		'--minerva-delay-ms=250', '--max-load=100', '--reserve-mb=128',
		'--max-health-ms=15000', '--max-seconds=600'
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
		($checkpoint['metrics']['completed'] ?? 0) < 1) {
		throw new RuntimeException('Parallel scan missed pages or failed to finalize: ' .
			substr($out . $err, -3000));
	}
	$mobile = glob($stateDir . '/minerva-cache/*/*/*/*.cache') ?: [];
	$reading = glob($stateDir . '/reading-cache/*/*/*/*.cache') ?: [];
	if (count($mobile) < 1 || count($reading) < 1 ||
		$queue->current($pageId) !== null) {
		throw new RuntimeException('Real mobile or fragment cache missing after parallel fill');
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
	echo json_encode([
		'result' => 'PASS', 'wiki' => $wiki, 'workers' => 2,
		'vector' => true, 'minerva' => count($mobile),
		'reading' => count($reading), 'metrics' => $checkpoint['metrics'],
		'completed' => true
	], JSON_THROW_ON_ERROR) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
