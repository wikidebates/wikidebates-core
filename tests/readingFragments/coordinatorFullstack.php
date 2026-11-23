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
// Only this disposable HTTP laboratory selects unlimited cache retention.
// Existing duration-oriented integration tests keep their own finite TTL.
if (!touch($state . '/unlimited-ttl')) { throw new RuntimeException('Cannot enable lab-only no-TTL fixture'); }
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
	if (!$queue->acknowledge($record['pageId'], $record['revisionId'], $record['generation'] ?? null)) {
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
		// CI-only diagnostic for a failed child: do not weaken the production
		// coordinator's refusal to expose arbitrary subprocess stderr.
		$probe = [PHP_BINARY, $root . '/mediawiki/maintenance/run.php',
			'Wikidebates:prepareVectorViewCache',
			'--wiki=' . WikiMap::getCurrentWikiId(), '--page-id=' . $pageId,
			'--json', '--conf=' . $conf];
		$debug = proc_open($probe, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $debugPipes);
		$details = '';
		if (is_resource($debug)) {
			fclose($debugPipes[0]);
			$details = stream_get_contents($debugPipes[1]) . stream_get_contents($debugPipes[2]);
			fclose($debugPipes[1]);
			fclose($debugPipes[2]);
			proc_close($debug);
		}
		throw new RuntimeException('Three-cache coordinator did not safely acknowledge: ' .
			substr($stdout . $stderr, -2400) . '; isolated child probe: ' . substr($details, -2500));
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

	// Exercise actual HTTP Minerva GETs against the new TTL=0 production
	// Store, not just the prewarm subprocess or synthetic cache files.
	$smokeUA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 ' .
		'Chrome/130.0.0.0 Mobile Safari/537.36 WikidebatesNoTtlSmoke/1';
	$smokeRequest = static function (string $uri, array $extra = []) use ($smokeUA): array {
		$headers = array_merge(['User-Agent: ' . $smokeUA,
			'Accept-Encoding: identity'], $extra);
		$response = @file_get_contents('http://127.0.0.1:8080/' . $uri, false,
			stream_context_create(['http' => [
				'method' => 'GET', 'ignore_errors' => true, 'timeout' => 15,
				'header' => implode("\r\n", $headers)
			]]));
		$raw = $http_response_header ?? [];
		$status = (int)explode(' ', $raw[0] ?? 'HTTP/1.1 0')[1];
		$fields = [];
		foreach ($raw as $h) {
			if (!str_contains($h, ':')) { continue; }
			[$key, $value] = explode(':', $h, 2);
			$fields[strtolower($key)] = trim($value);
		}
		return ['status' => $status, 'body' => $response, 'headers' => $fields];
	};
	$url = 'index.php?title=Cache_study_argument';
	$mobileFirst = $smokeRequest($url);
	$mobileSecond = $smokeRequest($url);
	$firstState = $mobileFirst['headers']['x-minerva-file-cache'] ?? 'MISSING';
	$secondState = $mobileSecond['headers']['x-minerva-file-cache'] ?? 'MISSING';
	if ($mobileFirst['status'] !== 200 || $mobileSecond['status'] !== 200 ||
		$firstState !== 'MISS' || $secondState !== 'HIT' ||
		$mobileFirst['body'] !== $mobileSecond['body'] ||
		!str_contains((string)$mobileSecond['body'], 'ARGUMENT_VERSION_ONE')) {
		throw new RuntimeException('Minerva HTTP no-TTL MISS/HIT failed: ' .
			json_encode([$mobileFirst['status'], $mobileSecond['status'],
				$firstState, $secondState], JSON_THROW_ON_ERROR));
	}
	// A 90-day old file with the legacy 30-day metadata still has to be a
	// HIT, provided it belongs to the same unmodified, publicly readable page.
	$sample = null;
	foreach (glob($state . '/minerva-cache/*/*/*/*.cache') ?: [] as $path) {
		$entry = json_decode((string)gzdecode((string)file_get_contents($path)), true);
		if (is_array($entry) && str_contains((string)($entry['variant'] ?? ''), 'WikidebatesNoTtlSmoke')) {
			$sample = [$path, $entry];
			break;
		}
	}
	if ($sample === null) { throw new RuntimeException('Minerva HTTP variant not published'); }
	[$samplePath, $entry] = $sample;
	if (($entry['expires'] ?? null) !== PHP_INT_MAX) {
		throw new RuntimeException('Minerva file is still time-limited without configured TTL');
	}
	$entry['created'] = time() - 90 * 86400;
	$entry['expires'] = $entry['created'] + 2592000;
	if (@file_put_contents($samplePath, gzencode(json_encode($entry, JSON_THROW_ON_ERROR))) === false) {
		throw new RuntimeException('Cannot backdate private lab fixture');
	}
	$mobileOld = $smokeRequest($url);
	if ($mobileOld['status'] !== 200 ||
		($mobileOld['headers']['x-minerva-file-cache'] ?? '') !== 'HIT' ||
		$mobileOld['body'] !== $mobileSecond['body']) {
		throw new RuntimeException('Old Minerva file was replaced due to an obsolete TTL');
	}
	// A request carrying a cookie is never allowed to use the shared HTML.
	$cookie = $smokeRequest($url, ['Cookie: preview=private']);
	if (($cookie['headers']['x-minerva-file-cache'] ?? '') !== 'BYPASS') {
		throw new RuntimeException('Cookie-bearing request used anonymous Minerva file');
	}
	// Read the actual public fragment over HTTP. The same API action used by
	// anonymous readers must see the prepared fragment instead of reparsing.
	$readingUrl = 'api.php?' . http_build_query([
		'action' => 'wikidebatesreading', 'page' => 'Cache study argument',
		'useskin' => 'vector', 'format' => 'json', 'formatversion' => 2
	]);
	// One canonical request may establish its own fragment variant.
	// The following request must reuse the same stored anonymous HTML.
	$smokeRequest($readingUrl);
	$reading = $smokeRequest($readingUrl);
	if ($reading['status'] !== 200 || !str_contains((string)$reading['body'], 'fragment-cache')) {
		throw new RuntimeException('Prepared reading fragment not reused over HTTP: ' .
			$reading['status'] . ', ' . substr((string)$reading['body'], 0, 150));
	}


	// Ordinary edits still retire both unlimited HTML caches. No TTL is
	// involved in this transition; it is tied to the owning page revision.
	$edit = proc_open([PHP_BINARY, __DIR__ . '/manage.php', 'edit'],
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $editPipes);
	if (!is_resource($edit)) { throw new RuntimeException('Cannot edit disposable test page'); }
	fclose($editPipes[0]);
	$editOutput = stream_get_contents($editPipes[1]) . stream_get_contents($editPipes[2]);
	fclose($editPipes[1]);
	fclose($editPipes[2]);
	if (proc_close($edit) !== 0) {
		throw new RuntimeException('Cannot update disposable test page: ' . substr($editOutput, -500));
	}
	$newView = $smokeRequest($url);
	$reusedView = $smokeRequest($url);
	if ($newView['status'] !== 200 ||
		($newView['headers']['x-minerva-file-cache'] ?? '') !== 'MISS' ||
		!str_contains((string)$newView['body'], 'ARGUMENT_VERSION_TWO') ||
		($reusedView['headers']['x-minerva-file-cache'] ?? '') !== 'HIT') {
		throw new RuntimeException('Direct page edit did not renew unlimited Minerva HTML');
	}

	echo json_encode(['result' => 'PASS', 'pageId' => $pageId, 'revisionId' => $revision,
		'cachePhases' => $report['pages'][0]['phases'],
		'mobileFiles' => count($mobileFiles), 'readingFiles' => count($readingFiles),
		'acknowledged' => 1], JSON_THROW_ON_ERROR) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
