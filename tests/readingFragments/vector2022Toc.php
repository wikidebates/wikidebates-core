<?php

/**
 * A real Vector 2022 native file-cache render must retain the anonymous TOC
 * when ParserOutput contains headings. The normal Vector test uses legacy skin.
 * All work runs inside a marked disposable SQLite cache lab.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('Vector 2022 TOC test requires the disposable cache lab');
}
putenv('WD_VECTOR_TEST_SKIN=vector-2022');
putenv('MW_INSTALL_PATH=' . $root . '/mediawiki');

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

$title = MediaWiki\Title\Title::newFromText('Cache study argument');
if (!$title) { throw new RuntimeException('Missing test title'); }
$pageId = (int)$title->getArticleID();
if ($pageId < 1) { throw new RuntimeException('Missing test page ID'); }

$view = new HTMLFileCache($title, 'view');
$view->clearCache();
$command = [
	PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php',
	dirname(__DIR__, 2) . '/maintenance/prepareVectorViewCache.php',
	'--conf=' . $root . '/state/LocalSettings.php',
	'--page-id=' . $pageId, '--json'
];
$proc = proc_open($command, [
	['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']
], $pipes);
if (!is_resource($proc)) { throw new RuntimeException('Cannot start native Vector 2022 cache preparation'); }
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($proc);
$report = json_decode(trim($stdout), true);
if ($status !== 0 || !is_array($report) ||
	($report['pageId'] ?? null) !== $pageId ||
	($report['state'] ?? null) !== 'CREATED') {
	throw new RuntimeException('Vector 2022 view file creation failed: ' .
		json_encode(['exit' => $status, 'report' => $report,
			'stderr_tail' => substr($stderr, -700)], JSON_THROW_ON_ERROR));
}
// The renderer wrote the HTML in a child process after the parent
// inspected and removed the old cache. Clear this process's stat cache.
clearstatcache(true);
if (!$view->isCached()) {
	throw new RuntimeException('Vector 2022 cache file was not created');
}
$html = $view->fetchText();
$flags = [
	'vector-2022' => str_contains($html, 'skin-vector-2022'),
	'markup-toc' => str_contains($html, 'id="mw-panel-toc"'),
	'toc-available' => str_contains($html, 'vector-toc-available'),
	'toc-not-available' => str_contains($html, 'vector-toc-not-available'),
	'heading-summary' => str_contains($html, 'Summary'),
	'heading-parents' => str_contains($html, 'Parent debates')
];
if (!$flags['vector-2022'] || !$flags['markup-toc'] ||
	!$flags['toc-available'] || $flags['toc-not-available'] ||
	!$flags['heading-summary'] || !$flags['heading-parents']) {
	throw new RuntimeException('CLI-prepared Vector 2022 HTML lost the anonymous sidebar TOC: ' .
		json_encode(['flags' => $flags, 'bytes' => strlen($html)], JSON_THROW_ON_ERROR));
}
echo json_encode(['result' => 'PASS', 'page_id' => $pageId, 'toc' => true],
	JSON_THROW_ON_ERROR) . "\n";
