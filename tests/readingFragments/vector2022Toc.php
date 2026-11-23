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

// A default MediaWiki TOC requires enough headings (four or more).
// The original cache fixture has only two and legitimately has no sidebar TOC.
$title = MediaWiki\Title\Title::newFromText('Vector 2022 toc fixture');
if (!$title) { throw new RuntimeException('Missing test title'); }
$body = '';
foreach (['Section alpha', 'Section beta', 'Section gamma', 'Section delta', 'Section epsilon'] as $heading) {
	$body .= '== ' . $heading . " ==\n" .
		str_repeat("Independent public content with a section anchor.\n", 20);
}
$services = MediaWiki\MediaWikiServices::getInstance();
$user = $services->getUserFactory()->newFromName('CacheStudyReader');
$page = $services->getWikiPageFactory()->newFromTitle($title);
$updater = $page->newPageUpdater($user);
$updater->setContent(MediaWiki\Revision\SlotRecord::MAIN,
	MediaWiki\Content\ContentHandler::makeContent($body, $title));
$updater->saveRevision(MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment(
	'Disposable Vector 2022 TOC coverage'
));
if (!$updater->wasSuccessful()) {
	throw new RuntimeException('Failed to create an eligible multi-heading test page');
}
MediaWiki\Deferred\DeferredUpdates::doUpdates();
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
// FileCacheBase memoizes isCached() on each object. Our previous object
// deliberately observed a missing file after clearCache(); use a fresh one.
clearstatcache(true);
$view = new HTMLFileCache($title, 'view');
if (!$view->isCached()) {
	throw new RuntimeException('Vector 2022 cache file was not created');
}
$html = $view->fetchText();
$flags = [
	'vector-2022' => str_contains($html, 'skin-vector-2022'),
	'markup-toc' => str_contains($html, 'id="mw-panel-toc"'),
	'toc-available' => str_contains($html, 'vector-toc-available'),
	'toc-not-available' => str_contains($html, 'vector-toc-not-available'),
	'heading-alpha' => str_contains($html, 'Section alpha'),
	'heading-epsilon' => str_contains($html, 'Section epsilon')
];
if (!$flags['vector-2022'] || !$flags['markup-toc'] ||
	!$flags['toc-available'] || $flags['toc-not-available'] ||
	!$flags['heading-alpha'] || !$flags['heading-epsilon']) {
	throw new RuntimeException('CLI-prepared Vector 2022 HTML lost the anonymous sidebar TOC: ' .
		json_encode(['flags' => $flags, 'bytes' => strlen($html),
			'parser_diagnostics' => is_file($root . '/state/toc-diagnostics.jsonl') ?
				file_get_contents($root . '/state/toc-diagnostics.jsonl') : null
		], JSON_THROW_ON_ERROR));
}
echo json_encode(['result' => 'PASS', 'page_id' => $pageId, 'toc' => true],
	JSON_THROW_ON_ERROR) . "\n";
