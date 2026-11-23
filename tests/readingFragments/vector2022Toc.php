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
$run = static function (array $options = []) use ($command, $pageId): array {
	$proc = proc_open([...$command, ...$options], [
		['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']
	], $pipes);
	if (!is_resource($proc)) { throw new RuntimeException('Cannot run Vector 2022 cache preparation'); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($proc);
	$report = json_decode(trim($stdout), true);
	if ($status !== 0 || !is_array($report) || ($report['pageId'] ?? null) !== $pageId) {
		throw new RuntimeException('Vector 2022 preparation returned an unexpected result: ' .
			json_encode(['exit' => $status, 'report' => $report,
				'stderr_tail' => substr($stderr, -700)], JSON_THROW_ON_ERROR));
	}
	return $report;
};
$report = $run();
if ($report['state'] !== 'CREATED') {
	throw new RuntimeException('Vector 2022 view file was not created: ' .
		json_encode($report, JSON_THROW_ON_ERROR));
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
		json_encode(['flags' => $flags, 'bytes' => strlen($html)], JSON_THROW_ON_ERROR));
}
// Simulate a valid, pre-fix anonymous file: five sections but no sidebar TOC.
// The targeted repair must restore only the stale Vector view, not any other cache.
$broken = str_replace(
	['vector-toc-available', 'id="mw-panel-toc"'],
	['vector-toc-not-available', 'id="mw-panel-toc-legacy-broken"'],
	$html
);
if ($broken === $html) {
	throw new RuntimeException('Could not assemble a representative old TOC-less HTML fixture');
}
$view->saveToFileCache($broken);
$probe = $run(['--repair-missing-toc', '--dry-run']);
if ($probe['state'] !== 'MISSING_TOC') {
	throw new RuntimeException('Dry-run did not detect the missing TOC in valid HTML');
}
$repaired = $run(['--repair-missing-toc']);
if ($repaired['state'] !== 'CREATED' || ($repaired['repaired_toc'] ?? null) !== true) {
	throw new RuntimeException('Targeted TOC repair did not regenerate the affected HTML: ' .
		json_encode($repaired, JSON_THROW_ON_ERROR));
}
clearstatcache(true);
$after = (new HTMLFileCache($title, 'view'))->fetchText();
if (!str_contains($after, 'id="mw-panel-toc"') ||
	!str_contains($after, 'vector-toc-available') ||
	str_contains($after, 'id="mw-panel-toc-legacy-broken"')) {
	throw new RuntimeException('TOC repair did not publish complete Vector 2022 HTML');
}
$reused = $run(['--repair-missing-toc']);
if ($reused['state'] !== 'REUSED') {
	throw new RuntimeException('Already repaired TOC should be reused, not rebuilt');
}
echo json_encode(['result' => 'PASS', 'page_id' => $pageId, 'toc' => true,
	'repair_dry_run' => $probe['state'], 'repair' => $repaired['state'],
	'after' => $reused['state']], JSON_THROW_ON_ERROR) . "\n";
