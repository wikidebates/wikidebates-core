<?php

/**
 * Real MediaWiki/SMW regression on an automatically deleted database.
 * Changes to another page must not invalidate an existing Vector HTML file
 * or the reading fragment generation of the page owning an SMW query.
 */
define('WD_FULLSTACK_TEST', true);
define('WD_TEST_PAGE_OWNED_CACHE', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_SEMANTIC_TRACKING=1');
putenv('WD_TEST_EXTENSIONS=SemanticMediaWiki');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use SMW\Services\ServicesFactory;

check(str_starts_with(MW_VERSION, '1.43.'), 'Disposable MediaWiki 1.43');
check(SMW_VERSION === '7.3.1', 'SMW 7.3.1 query integration');
foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $extension) {
	check(!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded($extension), 'No runtime dependency on ' . $extension);
}
$services = MediaWikiServices::getInstance();
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false), 'Real SMW SQL store');
$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW is ready');
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates page-owned cache lab', ['steal' => false]);
check($fsUser !== null, 'Disposable writer');

fsSave('Property:WD Snapshot Parent', '[[Has type::Page]]');
fsSave('WD Snapshot Parent', 'Target');
fsSave('WD Snapshot owner',
	'== Summary ==' . "\n" . '{{#ask:[[WD Snapshot Parent::WD Snapshot Parent]]|format=count}}' .
	"\n== Parent debates ==\nNo parents yet");
fsFlush();

$title = Title::newFromText('WD Snapshot owner');
$context = new RequestContext();
$context->setTitle($title);
$context->setSkin($services->getSkinFactory()->makeSkin('vector'));
$article = \Article::newFromTitle($title, $context);
$context->setWikiPage($article->getPage());
check($article->isFileCacheable(\HTMLFileCache::MODE_REBUILD),
	'Native Vector must not veto an otherwise public SMW query page');
$parsed = $article->getPage()->getParserOutput(ParserOptions::newFromAnon());
check($parsed instanceof \MediaWiki\Parser\ParserOutput &&
	$parsed->isCacheable() && $parsed->getCacheExpiry() > 0,
	'A real SMW #ask parser output remains cacheable for the anonymous Vector file');
check(str_contains($parsed->getText(), 'Summary'),
	'The real SMW page was parsed rather than accepting only a synthetic file');

$db = $services->getConnectionProvider()->getPrimaryDatabase();
$identity = static function () use ($db, $title): array {
	$row = $db->selectRow('page', ['page_latest', 'page_touched'],
		['page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey()],
		__METHOD__);
	if (!$row) { throw new RuntimeException('Owner page missing'); }
	return [(int)$row->page_latest, (string)$row->page_touched];
};

[$revision, $touched] = $identity();

// Exercise the real anonymous reading API, not just the store generation.
$read = static function (?\MediaWiki\User\User $reader = null) use ($services, $store, $title): array {
	$store->clear();
	$access = $services->getParserOutputAccess();
	$local = (new \ReflectionObject($access))->getProperty('localCache');
	$local->setValue($access, new \MapCacheLRU(10));
	$context = new RequestContext();
	$context->setRequest(new FauxRequest([
		'action' => 'wikidebatesreading', 'page' => $title->getPrefixedText(),
		'useskin' => 'vector', 'format' => 'json', 'formatversion' => 2
	], false));
	$context->setUser($reader ?? $services->getUserFactory()->newAnonymous());
	$context->setLanguage($services->getContentLanguage());
	$context->setTitle($title);
	return FragmentService::read($context, $title->getPrefixedText(), 'vector');
};
$firstReading = $read();
check(($firstReading['source'] ?? '') !== 'fragment-cache' && ($firstReading['html'] ?? '') !== '',
	'First real anonymous reading request renders the SMW query page: source=' .
		($firstReading['source'] ?? 'missing') . ', bytes=' . strlen((string)($firstReading['html'] ?? '')));
$warmReading = $read();
check(($warmReading['source'] ?? '') === 'fragment-cache',
	'Second anonymous reading request is a genuine fragment-cache HIT: ' .
		($warmReading['source'] ?? 'missing'));
$registeredReading = $read($fsUser);
check(($registeredReading['source'] ?? '') !== 'fragment-cache',
	'Logged-in requests never receive the shared anonymous fragment');

$view = new \HTMLFileCache($title, 'view');
$view->saveToFileCache('<!DOCTYPE html><html><body>' .
	str_repeat('Original anonymous Vector snapshot. ', 32) . '</body></html>');
check((new \HTMLFileCache($title, 'view'))->isCacheGood($touched),
	'Native Vector file exists before semantic changes');

$key = $title->getPrefixedDBkey();
$fragments = FragmentService::store();
$generation = $fragments->read($key, 'page-owned-test', [])['generation'];

// Edit a DIFFERENT page: the owner's query now returns a different result.
fsSave('WD Snapshot relation', '{{#set:WD Snapshot Parent=WD Snapshot Parent}}');
fsFlush();
check(count(fsValues('WD Snapshot relation', 'WD Snapshot Parent')) === 1,
	'External semantic relationship is actually persisted');
[$afterRevision, $afterTouched] = $identity();
check($afterRevision === $revision, 'External change did not edit owner revision');
check($afterTouched === $touched, 'External change did not touch owner page');
check((new \HTMLFileCache($title, 'view'))->isCacheGood($afterTouched),
	'Vector keeps the same file despite the new semantic result');
check($fragments->read($key, 'page-owned-test', [])['generation'] === $generation,
	'Reading generation survives the external semantic change');
$afterSemanticReading = $read();
check(($afterSemanticReading['source'] ?? '') === 'fragment-cache' &&
	$afterSemanticReading['html'] === $warmReading['html'],
	'Real Lecture HIT preserves exactly the old HTML after an unrelated SMW edit');

// Direct owner edit does invalidate native HTML and reading.
sleep(2);
fsSave('WD Snapshot owner',
	'== Summary ==' . "\nDirect edit\n" .
	'{{#ask:[[WD Snapshot Parent::WD Snapshot Parent]]|format=count}}' .
	"\n== Parent debates ==\nNo parents yet");
fsFlush();
[$afterEdit, $afterEditTouched] = $identity();
check($afterEdit > $revision, 'Direct owner edit creates a new revision');
check(!(new \HTMLFileCache($title, 'view'))->isCacheGood($afterEditTouched),
	'Direct owner edit makes the old Vector HTML unusable');
check($fragments->read($key, 'page-owned-test', [])['generation'] !== $generation,
	'Direct owner edit invalidates the reading generation');
$freshReading = $read();
check(($freshReading['source'] ?? '') !== 'fragment-cache' &&
	($freshReading['html'] ?? '') !== ($warmReading['html'] ?? ''),
	'Direct owner edit cannot return the previously cached anonymous fragment');

echo "OK: no indirect SMW invalidation, direct edits still invalidate anonymous HTML\n";
