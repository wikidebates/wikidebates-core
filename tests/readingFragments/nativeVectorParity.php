<?php

/**
 * Compare genuine anonymous HTTP Vector HTML, core rebuildFileCache.php, and
 * Wikidebates view-only prewarming. Confirm that the history cache is still
 * created on its FIRST eligible anonymous HTTP request and reused thereafter.
 *
 * No production paths: this test requires a marked disposable MediaWiki lab.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite') ||
	!is_file($root . '/mediawiki/includes/Setup.php')) {
	throw new RuntimeException('Native parity requires an initialized disposable MediaWiki 1.43 lab');
}
$state = $root . '/state';
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');
putenv('WD_VECTOR_TEST_SKIN=vector-2022');
if (!touch($state . '/consistent-cache-epoch')) {
	throw new RuntimeException('Cannot stabilize disposable CacheEpoch');
}
$conf = $root . '/mediawiki/LocalSettings.php';
if (file_put_contents($conf, "<?php\n") === false ||
	!chmod($conf, 0600) || !touch($conf, strtotime('2026-01-01 00:00:00 UTC'))) {
	throw new RuntimeException('Cannot install disposable LocalSettings.php');
}

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

$services = MediaWiki\MediaWikiServices::getInstance();
$title = MediaWiki\Title\Title::newFromText('Vector 2022 native parity fixture');
$user = $services->getUserFactory()->newFromName('CacheStudyReader');
if (!$title || !$user) {
	throw new RuntimeException('Missing disposable page identity');
}

$wikitext = <<<'WIKITEXT'
== Summary ==
A [[Main Page|public link]] with '''formatting''' and a reference.<ref>Test source.</ref>
{{Study dependency}}
== Evidence ==
Plain evidence text with a [[Cache study argument|second link]].
== Objections ==
A third section with a short quotation.
== Replies ==
An ordinary explanation with a citation.
== Conclusion ==
Last section with references.
<references />
[[Category:Arguments]]
WIKITEXT;
$page = $services->getWikiPageFactory()->newFromTitle($title);
$updater = $page->newPageUpdater($user);
$updater->setContent(MediaWiki\Revision\SlotRecord::MAIN,
	MediaWiki\Content\ContentHandler::makeContent($wikitext, $title));
$updater->saveRevision(MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment(
	'Disposable native Vector parity'
));
if (!$updater->wasSuccessful()) {
	throw new RuntimeException('Cannot create disposable native parity fixture');
}
MediaWiki\Deferred\DeferredUpdates::doUpdates();
$id = (int)$title->getArticleID();
if ($id < 1) { throw new RuntimeException('Disposable page has no page_id'); }

(new HTMLFileCache($title, 'view'))->clearCache();
(new HTMLFileCache($title, 'history'))->clearCache();
clearstatcache(true);
$cache = static function (string $type) use ($title): HTMLFileCache {
	return new HTMLFileCache($title, $type);
};
$read = static function (string $type) use ($cache): string {
	clearstatcache(true);
	$handle = $cache($type);
	if (!$handle->isCached()) {
		throw new RuntimeException('Required ' . $type . ' cache file was not created');
	}
	$html = $handle->fetchText();
	if (!is_string($html) || strlen($html) < 512) {
		throw new RuntimeException('Invalid ' . $type . ' HTML file');
	}
	return $html;
};
$run = static function (array $args, string $label) use ($root): string {
	$command = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
		...$args];
	$process = proc_open($command,
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot start ' . $label); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0) {
		throw new RuntimeException($label . ' failed: ' .
			substr((string)$stdout . (string)$stderr, -2300));
	}
	return (string)$stdout;
};

// Compare semantic DOM of important rendered components, not dynamic cache
// timestamps. Keep the sections broad to detect lost links, menus and scripts.
$signature = static function (string $html): array {
	$dom = new DOMDocument();
	$prior = libxml_use_internal_errors(true);
	$ok = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
	libxml_clear_errors();
	libxml_use_internal_errors($prior);
	if (!$ok) { throw new RuntimeException('Invalid comparison HTML'); }
	$xpath = new DOMXPath($dom);
	$regions = [
		'title' => '//head/title',
		'head-resources' => '//head',
		'body' => '//body',
		'article' => '//*[@id="mw-content-text"]',
		'toc' => '//*[@id="mw-panel-toc"]',
		'left-column' => '//*[contains(concat(" ", normalize-space(@class), " "), " vector-column-start ")]',
		'right-column' => '//*[contains(concat(" ", normalize-space(@class), " "), " vector-column-end ")]',
		'toolbar' => '//*[contains(concat(" ", normalize-space(@class), " "), " vector-page-toolbar ")]',
		'titlebar' => '//*[contains(concat(" ", normalize-space(@class), " "), " vector-page-titlebar ")]',
		'footer' => '//*[contains(concat(" ", normalize-space(@class), " "), " mw-footer-container ")]',
		'categories' => '//*[@id="catlinks"]',
	];
	$result = [];
	foreach ($regions as $name => $query) {
		$matches = $xpath->query($query);
		if ($matches === false || $matches->length > 1) {
			throw new RuntimeException('Unexpected DOM region: ' . $name);
		}
		if ($matches->length === 0) {
			$result[$name] = null;
			continue;
		}
		$node = $matches->item(0);
		$fragment = $node->C14N();
		if (!is_string($fragment)) {
			throw new RuntimeException('Cannot canonicalize HTML region: ' . $name);
		}
		$fragment = preg_replace('/<!-- Cached(?:\/compressed)? [^>]* -->\s*/', '', $fragment);
		$result[$name] = $fragment;
	}
	return $result;
};
$compare = static function (string $left, string $right, string $label) use ($signature): array {
	$a = $signature($left);
	$b = $signature($right);
	$changes = [];
	foreach ($a as $name => $value) {
		if ($value !== $b[$name]) {
			$nativeString = (string)$value;
			$prewarmString = (string)$b[$name];
			$offset = 0;
			$limit = min(strlen($nativeString), strlen($prewarmString));
			while ($offset < $limit && $nativeString[$offset] === $prewarmString[$offset]) {
				$offset++;
			}
			$start = max(0, $offset - 120);
			$changes[$name] = [
				'offset' => $offset,
				'native_length' => strlen($nativeString),
				'prewarm_length' => strlen($prewarmString),
				'native' => substr($nativeString, $start, 320),
				'prewarm' => substr($prewarmString, $start, 320)
			];
		}
	}
	if ($changes) {
		throw new RuntimeException($label . ' differs in DOM regions: ' .
			json_encode($changes, JSON_THROW_ON_ERROR));
	}
	return array_keys(array_filter($a, static fn($value) => $value !== null));
};

$log = $state . '/native-parity-http.log';
$server = proc_open([
	PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	'-d', 'zlib.output_compression=0', '-S', '127.0.0.1:8080',
	'-t', $root . '/mediawiki'
], [
	['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']
], $serverPipes);
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
	if (!$ready) { throw new RuntimeException('Disposable HTTP server did not start'); }

	$get = static function (string $action) use ($title): array {
		$url = 'http://127.0.0.1:8080/index.php?' . http_build_query([
			'title' => $title->getPrefixedDBkey(), 'action' => $action
		]);
		$body = @file_get_contents($url, false,
			stream_context_create(['http' => [
				'method' => 'GET', 'timeout' => 20, 'ignore_errors' => true,
				'header' => "Accept-Encoding: identity\r\nUser-Agent: Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0\r\n"
			]]));
		$headers = $http_response_header ?? [];
		$status = (int)explode(' ', $headers[0] ?? 'HTTP/1.1 0')[1];
		if ($body === false || $status !== 200) {
			throw new RuntimeException('Anonymous HTTP ' . $action .
				' did not return 200 (HTTP ' . $status . '): ' . substr((string)$body, 0, 250));
		}
		return ['body' => $body, 'status' => $status];
	};

	// First anonymous article request creates only the native view file.
	$firstView = $get('view');
	$nativeHttp = $read('view');
	if ($cache('history')->isCached()) {
		throw new RuntimeException('Visiting a page incorrectly pre-generated history HTML');
	}
	$secondView = $get('view');
	if (!str_contains($secondView['body'], 'Evidence') ||
		!str_contains($secondView['body'], 'Conclusion') ||
		!str_contains($nativeHttp, 'id="mw-panel-toc"')) {
		throw new RuntimeException('Native HTTP Vector article or table of contents is incomplete: ' .
			json_encode([
				'http_contains_section' => str_contains($secondView['body'], 'Evidence') &&
				str_contains($secondView['body'], 'Conclusion'),
				'http_body_length' => strlen($secondView['body']),
				'http_body_excerpt' => substr($secondView['body'], 0, 320),
				'file_contains_toc' => str_contains($nativeHttp, 'id="mw-panel-toc"'),
				'file_available' => str_contains($nativeHttp, 'vector-toc-available'),
				'file_unavailable' => str_contains($nativeHttp, 'vector-toc-not-available'),
				'file_contains_heading' => str_contains($nativeHttp, 'Evidence'),
				'file_length' => strlen($nativeHttp)
			], JSON_THROW_ON_ERROR));
	}

	// First anonymous history request creates history; second MUST reuse it.
	$firstHistory = $get('history');
	$historyHtml = $read('history');
	if (!str_contains($historyHtml, 'mw-history') ||
		!str_contains($firstHistory['body'], 'mw-history')) {
		throw new RuntimeException('Native anonymous history page is not represented in history cache');
	}
	$historyPathHash = hash('sha256', $historyHtml);
	$historyFileMTime = $cache('history')->cacheTimestamp();
	sleep(1);
	$secondHistory = $get('history');
	if ($firstHistory['body'] !== $secondHistory['body'] ||
		$historyPathHash !== hash('sha256', $read('history')) ||
		$historyFileMTime !== $cache('history')->cacheTimestamp()) {
		throw new RuntimeException('Second anonymous history request rewrote instead of reusing history HTML');
	}

	// Build the native maintenance view reference, which deliberately also
	// creates history. The fixture is disposable so this is harmless.
	$nativeOutput = $run([
		$root . '/mediawiki/maintenance/rebuildFileCache.php',
		'--conf=' . $conf, '--start=' . $id, '--end=' . $id, '--overwrite'
	], 'Core rebuildFileCache');
	$nativeMaintenance = $read('view');
	$nativeRegions = $compare($nativeHttp, $nativeMaintenance,
		'Native anonymous HTTP and native rebuildFileCache');

	// Replace ONLY view. The original history file remains untouched.
	$cache('view')->clearCache();
	$historyBefore = $read('history');
	$historyBeforeTimestamp = $cache('history')->cacheTimestamp();
	$stdout = $run([
		dirname(__DIR__, 2) . '/maintenance/prepareVectorViewCache.php',
		'--conf=' . $conf, '--page-id=' . $id, '--json'
	], 'Wikidebates view-only prewarming');
	$report = json_decode(trim($stdout), true);
	if (($report['state'] ?? null) !== 'CREATED' || ($report['pageId'] ?? null) !== $id) {
		throw new RuntimeException('Wikidebates view-only preparation did not create a native HTML file: ' .
			substr($stdout, -700));
	}
	$prewarmed = $read('view');
	$httpRegions = $compare($nativeHttp, $prewarmed, 'Native HTTP and Wikidebates');
	$cliRegions = $compare($nativeMaintenance, $prewarmed, 'Native CLI and Wikidebates');
	if ($historyBefore !== $read('history') ||
		$historyBeforeTimestamp !== $cache('history')->cacheTimestamp()) {
		throw new RuntimeException('Wikidebates view prewarming unexpectedly modified native history HTML');
	}
	$httpThird = $get('view');
	if (!str_contains($httpThird['body'], 'Evidence') ||
		!str_contains($read('view'), 'vector-toc-available')) {
		throw new RuntimeException('Native HTTP failed to reuse complete prewarmed Vector HTML');
	}

	echo json_encode([
		'result' => 'PASS', 'mediawiki' => MW_VERSION, 'skin' => 'vector-2022',
		'page_id' => $id, 'history_first_visit_created' => true,
		'history_second_visit_reused' => true, 'prewarm_preserved_history' => true,
		'http_parity_regions' => $httpRegions, 'core_cli_parity_regions' => $cliRegions,
		'prewarm_state' => $report['state']
	], JSON_THROW_ON_ERROR) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
