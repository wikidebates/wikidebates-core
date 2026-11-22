<?php

/**
 * Page-local anonymous cache regression on a disposable MediaWiki 1.43 wiki.
 *
 * Semantic query results may change, but the three snapshots remain tied
 * to their owning page. Never run this script on a production database.
 */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_SEMANTIC_TRACKING=1');
putenv('WD_TEST_PAGE_LOCAL_CACHE=1');
putenv('WD_TEST_EXTENSIONS=Scribunto,SemanticMediaWiki,SemanticScribunto');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\Extension\Wikidebates\Reading\PageLocalCachePolicy;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\Services\ServicesFactory;

check(str_starts_with(MW_VERSION, '1.43.'), 'MediaWiki 1.43 disposable test');
check(SMW_VERSION === '7.3.1', 'SMW 7.3.1 disposable test');
$services = MediaWikiServices::getInstance();
check(PageLocalCachePolicy::enabled(), 'Page-local policy is explicitly enabled in the lab');
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false),
	'The genuine SMW SQL store is initialized');
$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW is ready');

$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates page-local cache lab', ['steal' => false]);
check($fsUser !== null, 'Disposable test writer is available');

fsSave('Property:WD Local Parent', '[[Has type::Page]]');
fsSave('WD Local Parent A', 'Parent A');
fsSave('WD Local query page',
	'== Summary ==' . "\n" . '{{#ask:[[WD Local Parent::WD Local Parent A]]|format=count}}');
fsFlush();

$title = Title::newFromText('WD Local query page');
$context = new RequestContext();
$context->setTitle($title);
$context->setSkin($services->getSkinFactory()->makeSkin('vector'));
$article = \Article::newFromTitle($title, $context);
$context->setWikiPage($article->getPage());
check($article->isFileCacheable(\HTMLFileCache::MODE_REBUILD),
	'Vector accepts an otherwise eligible page with an SMW query');

$db = $services->getConnectionProvider()->getPrimaryDatabase();
$identity = static function () use ($db, $title): array {
	$row = $db->selectRow('page', ['page_latest', 'page_touched'],
		['page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey()],
		__METHOD__);
	if (!$row) { throw new RuntimeException('The page disappeared from the disposable database'); }
	return [(int)$row->page_latest, (string)$row->page_touched];
};

[$beforeRevision, $beforeTouched] = $identity();
$view = new \HTMLFileCache($title, 'view');
$view->saveToFileCache('<!DOCTYPE html><html><body>' .
	str_repeat('Cached anonymous Vector snapshot. ', 32) . '</body></html>');
check((new \HTMLFileCache($title, 'view'))->isCacheGood($beforeTouched),
	'The native Vector HTML file is present and valid before an indirect semantic change');

$fragments = FragmentService::store();
$key = $title->getPrefixedDBkey();
$beforeGeneration = $fragments->read($key, 'page-local-regression', [])['generation'];

// This changes the answer to the query above but never edits its owning page.
fsSave('WD Local relation', '{{#set:WD Local Parent=WD Local Parent A}}');
fsFlush();

[$afterRevision, $afterTouched] = $identity();
check($afterRevision === $beforeRevision, 'Indirect SMW change has not edited the query page');
check($fragments->read($key, 'page-local-regression', [])['generation'] === $beforeGeneration,
	'Reading fragment generation is not invalidated by changed SMW results');
check((new \HTMLFileCache($title, 'view'))->isCacheGood($afterTouched),
	'Native Vector HTML remains valid despite changed SMW results');

// Direct edits to the owning page MUST still invalidate the usual caches.
// Keep at least a second between the saved HTML mtime and page_touched.
sleep(2);
fsSave('WD Local query page',
	'== Summary ==' . "\n" . '{{#ask:[[WD Local Parent::WD Local Parent A]]|format=count}}' .
	"\n" . 'direct-page-edit');
fsFlush();

[$newRevision, $newTouched] = $identity();
check($newRevision > $beforeRevision, 'Direct page edit creates a new revision');
check($fragments->read($key, 'page-local-regression', [])['generation'] !== $beforeGeneration,
	'Direct page edit invalidates the reading generation');
check(!(new \HTMLFileCache($title, 'view'))->isCacheGood($newTouched),
	'Direct page edit invalidates the native Vector file');

echo "OK: page-local anonymous cache integration on a disposable wiki\n";
