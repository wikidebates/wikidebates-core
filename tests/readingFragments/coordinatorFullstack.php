<?php

/**
 * Opt-in, full-stack disposable laboratory: all three targeted preparation
 * subprocesses must succeed before a direct-edit queue record is removed.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite') ||
	!is_dir($root . '/state/preparation-queue')) {
	throw new RuntimeException('Only a marked, disposable MediaWiki queue laboratory is supported');
}
$state = $root . '/state';
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

$title = Title::newFromText('Cache study argument');
$services = MediaWikiServices::getInstance();
$page = $services->getWikiPageFactory()->newFromTitle($title);
$pageId = (int)$page->getId();
$revision = (int)$page->getLatest();
if ($pageId < 1 || $revision < 1) { throw new RuntimeException('Missing argument fixture'); }
$queue = new CachePreparationQueue($state . '/preparation-queue', WikiMap::getCurrentWikiId());
foreach ($queue->pending(10000) as $record) {
	if (!$queue->acknowledge($record['pageId'], $record['revisionId'])) {
		throw new RuntimeException('Cannot clear pending fixture events');
	}
}
if (!$queue->enqueue($pageId, $revision) || count($queue->pending(100)) !== 1) {
	throw new RuntimeException('Expected exactly one fresh argument preparation record');
}

// A small, private PHP ini fragment propagates the safe lab bootstrap to the
// coordinator's grandchild PHP maintenance processes, without production hooks.
$iniDir = $state . '/php-ini';
if (!mkdir($iniDir, 0700)) { throw new RuntimeException('Cannot create private ini dir'); }
file_put_contents($iniDir . '/99-wikidebates-lab.ini',
	'auto_prepend_file=' . __DIR__ . '/bootstrap.php' . "\n");
putenv('PHP_INI_SCAN_DIR=:' . $iniDir);
$conf = $root . '/mediawiki/LocalSettings.php';
file_put_contents($conf, "<?php\n");
chmod($conf, 0600);

$server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	'-d', 'zlib.output_compression=0', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/coordinator-http.log', 'a'],
		['file', $state . '/coordinator-http.log', 'a']], $pipes);
if (!is_resource($server)) { throw new RuntimeException('Cannot launch isolated HTTP server'); }
fclose($pipes[0]);

try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$socket = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($socket) { fclose($socket); $ready = true; break; }
		usleep(100000);
	}
	if (!$ready) { throw new RuntimeException('Isolated HTTP server did not start'); }

	$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		dirname(__DIR__, 2) . '/maintenance/preparePendingCaches.php',
		'--conf=' . $conf, '--execute', '--limit=1', '--max-seconds=300',
		'--minerva-delay-ms=250', '--delay-ms=0', '--json'];
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $workerPipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot launch coordinated worker'); }
	fclose($workerPipes[0]);
	$stdout = stream_get_contents($workerPipes[1]);
	$stderr = stream_get_contents($workerPipes[2]);
	fclose($workerPipes[1]); fclose($workerPipes[2]);
	$exit = proc_close($process);
	$report = json_decode(trim($stdout), true);
	if ($exit !== 0 || !is_array($report) ||
		($report['acknowledged'] ?? -1) !== 1 || ($report['retries'] ?? -1) !== 0 ||
		($report['pages'][0]['pageId'] ?? null) !== $pageId ||
		($report['pages'][0]['revisionId'] ?? null) !== $revision ||
		($report['pages'][0]['state'] ?? null) !== 'acknowledged' ||
		array_keys($report['pages'][0]['phases'] ?? []) !== ['vector', 'minerva', 'reading']) {
		throw new RuntimeException('Three-cache coordinator did not safely acknowledge: ' .
			substr($stdout . $stderr, -2400));
	}
	if ($queue->current($pageId) !== null) {
		throw new RuntimeException('Completed revision is still queued');
	}

	$vector = new HTMLFileCache($title, 'view');
	if (!$vector->isCached() || (new HTMLFileCache($title, 'history'))->isCached()) {
		throw new RuntimeException('Expected view-only native Vector file and no history HTML');
	}

	$mobileFiles = glob($state . '/minerva-cache/*/*/*/*.cache') ?: [];
	$readingFiles = glob($state . '/reading-cache/*/*/*/*.cache') ?: [];
	if (count($mobileFiles) !== 1 || count($readingFiles) < 1) {
		throw new RuntimeException('Expected exactly one Minerva file and at least one reading fragment');
	}
	echo json_encode(['result' => 'PASS', 'pageId' => $pageId, 'revisionId' => $revision,
		'cachePhases' => $report['pages'][0]['phases'],
		'mobileFiles' => count($mobileFiles), 'readingFiles' => count($readingFiles),
		'acknowledged' => 1], JSON_THROW_ON_ERROR) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
