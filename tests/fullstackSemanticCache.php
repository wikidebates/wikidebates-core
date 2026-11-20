<?php

/**
 * Integration regression for SMW 7.3.1 on a disposable SQLite or MariaDB wiki.
 * Run only after installing the genuine SMW and SemanticScribunto extensions.
 */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_SEMANTIC_TRACKING=1');
putenv('WD_TEST_EXTENSIONS=Scribunto,SemanticMediaWiki,SemanticScribunto');
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\DebatePHP\TrackedSemanticQuery;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
use MediaWiki\Extension\Wikidebates\Reading\SemanticCacheGuard;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

check(str_starts_with(MW_VERSION, '1.43.'), 'Only MediaWiki 1.43 is supported');
check(SMW_VERSION === '7.3.1', 'Exact SMW 7.3.1 integration regression');
check(SMW_SCRIBUNTO_VERSION === '2.3.3', 'Exact SemanticScribunto 2.3.3 semantics');

$services = MediaWikiServices::getInstance();
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore, 'Real SMW SQLStore');
check($store->setup(false), 'Disposable SMW tables initialized');
// update.php would create this table through LoadExtensionSchemaUpdates.
// The disposable harness initializes the exact backend-specific DDL directly.
$db = $store->getConnection('mw.db');
$backend = $db->getType();
check(in_array($backend, ['mysql', 'sqlite'], true), 'Supported isolated semantic ledger backend');
$ddl = file_get_contents(__DIR__ . '/../sql/' . $backend . '/wd_smw_entity_revisions.sql');
check($ddl !== false, 'Disposable semantic revision SQL schema is available');
$db->query($ddl, 'fullstackSemanticCacheInstallSchema');
check($db->tableExists(SemanticRevisionStore::TABLE),
	'Durable semantic revision table installed in isolated ' . $backend . ' database');

$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW ready after isolated schema setup');
require __DIR__ . '/fullstackSupport.php';
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates semantic cache lab', ['steal' => false]);
check($fsUser !== null, 'Disposable test writer');

$register = static function($parser): void {
	$parser->setHook('wd-cache-parents', static function($input, $args, $parser, $frame): string {
		$ctx = new MediaWikiContext($parser, $frame, [], false);
		$rows = $ctx->readSemantic(Table::wrap([
			'[[WD Cache Parent::' . trim($input) . ']]',
			'limit=200', 'link=none'
		]), 'cache-tracking');
		return 'WD_MATCHES=' . count($rows->rows ?? []);
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $register);
$register($services->getParser());

fsSave('Property:WD Cache Parent', '[[Has type::Page]]');
fsSave('WD Cache A', 'Parent A');
fsSave('WD Cache B', 'Parent B');
fsSave('WD Cache child', 'No parents yet');
fsSave('WD Cache query A', 'Initial stub');
fsSave('WD Cache query B', 'Initial stub');
fsFlush();

// The query pages must exist before their query profiles are written.
// SMW timestamps have second resolution. Register the query profiles after
// their dependencies to ensure a stable, non-ambiguous baseline; subsequent
// same-second edits must still be detected without the purge job.
sleep(2);
fsSave('WD Cache query A', "== Summary ==\n<wd-cache-parents>WD Cache A</wd-cache-parents>\n== Parent debates ==\nParent A");
fsSave('WD Cache query B', "== Summary ==\n<wd-cache-parents>WD Cache B</wd-cache-parents>\n== Parent debates ==\nParent B");
fsFlush();

$links = (new QueryDependencyLinksStoreFactory())->newQueryDependencyLinksStore($store);
$fragmentStore = FragmentService::store();
$generation = static function(string $name) use ($fragmentStore): string {
	return $fragmentStore->read(Title::newFromText($name)->getPrefixedDBkey(), 'semantic-test', [])['generation'];
};
$queries = ['WD Cache query A' => $generation('WD Cache query A'),
	'WD Cache query B' => $generation('WD Cache query B')];

foreach (array_keys($queries) as $queryTitle) {
	$title = Title::newFromText($queryTitle);
	$subject = SemanticWikiPage::newFromTitle($title);
	$store->clear();
	check($store->getPropertyValues($subject, new Property('_ASK')) !== [],
		'Native PHP query persists a genuine _ASK profile: ' . $queryTitle);
	check($links->findEmbeddedQueryIdListBySubject($subject) !== [],
		'Profile IDs are discoverable for dependency validation: ' . $queryTitle);
}

// Exercise the actual reading service in a synthetic anonymous API context,
// including its ParserOutput freshness check and private fragment store.
$read = static function(string $name) use ($services): array {
	// This test executes several synthetic HTTP requests in ONE CLI
	// process. MediaWiki's ParserOutputAccess otherwise keeps an in-memory
	// ParserOutput for the entire CLI process even after an external purge.
	// Real PHP web requests start with a fresh request-local L1 cache.
	$access = $services->getParserOutputAccess();
	$local = (new \ReflectionObject($access))->getProperty('localCache');
	$local->setValue($access, new \MapCacheLRU(10));
	$context = new RequestContext();
	$context->setRequest(new FauxRequest([
		'action' => 'wikidebatesreading', 'page' => $name, 'useskin' => 'vector',
		'format' => 'json', 'formatversion' => 2
	], false));
	$context->setUser($services->getUserFactory()->newAnonymous());
	$context->setLanguage($services->getContentLanguage());
	$context->setTitle(Title::newFromText($name));
	return FragmentService::read($context, $name, 'vector');
};
$observedParseOutput = null;
$parseObserver = $services->getHookContainer()->scopedRegister('ParserOutputPostCacheTransform',
	static function($output, &$text, &$options) use (&$observedParseOutput): void {
		$observedParseOutput = $output;
	});
$first = $read('WD Cache query A');
check(str_contains($first['html'], 'WD_MATCHES=0'),
	'First reading API render includes the empty parent list');
check($first['source'] !== 'fragment-cache',
	'A first request does not reuse a fragment that did not exist');
$second = $read('WD Cache query A');
check($second['source'] === 'fragment-cache',
	'Second identical anonymous reading request hits fragment-cache');

// MediaWiki's parser-cache keys vary with ApiParse's options; a raw
// ParserOptions::newFromAnon() lookup does not necessarily address that
// same entry. Observe the actual parser output from the API pipeline.
check($observedParseOutput instanceof \MediaWiki\Parser\ParserOutput,
	'The real API parse produces a ParserOutput');
check(is_string($observedParseOutput->getExtensionData(SemanticPageCacheHooks::OUTPUT_REVISION)),
	'ParserAfterParse recorded its semantic revision on the actual API output');
$parseObserver = null;


// Check the exact empty-query case: the two queries currently match no subjects.
// Their query dependency records must nonetheless exist before any child link.
// Normal article views must not serve a Vector/Minerva HTML file before
// the SMW freshness check. Unrelated ordinary pages remain file-cacheable.
$fullPageContext = new RequestContext();
$fullPageContext->setTitle(Title::newFromText('WD Cache query A'));
check(!SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
	'Query-containing full HTML pages bypass unsafe file-cache hits');

$publicOutput = new \MediaWiki\Output\OutputPage($fullPageContext);
SemanticPageCacheHooks::onBeforePageDisplay($publicOutput, null);
$publicOutput->considerCacheSettingsFinal();
check(!$publicOutput->couldBePublicCached(),
	'Query-containing full HTML pages cannot enter public HTTP/CDN caches');

$internalParseContext = new RequestContext();
$internalParseContext->setRequest(new FauxRequest(['action' => 'parse']));
$internalParseContext->setTitle(Title::newFromText('WD Cache query A'));
$internalParseOutput = new \MediaWiki\Output\OutputPage($internalParseContext);
SemanticPageCacheHooks::onBeforePageDisplay($internalParseOutput, null);
$internalParseOutput->considerCacheSettingsFinal();
check($internalParseOutput->couldBePublicCached(),
	'Internal ApiParse OutputPage remains eligible to publish a private reading fragment');


$fullPageContext->setTitle(Title::newFromText('WD Cache A'));
check(SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
	'An unrelated ordinary page retains its normal file cache');

$ordinaryOutput = new \MediaWiki\Output\OutputPage($fullPageContext);
SemanticPageCacheHooks::onBeforePageDisplay($ordinaryOutput, null);
$ordinaryOutput->considerCacheSettingsFinal();
check($ordinaryOutput->couldBePublicCached(),
	'Public caching stays available for pages without tracked semantic queries');


$dependentPage = $services->getWikiPageFactory()->newFromTitle(Title::newFromText('WD Cache query A'));
$cachedOutput = new \MediaWiki\Parser\ParserOutput();
$cachedOutput->setExtensionData(SemanticPageCacheHooks::OUTPUT_REVISION,
	SemanticRevisionStore::fingerprint($store,
		SemanticWikiPage::newFromTitle(Title::newFromText('WD Cache query A'))));
check(SemanticPageCacheHooks::onRejectParserCacheValue($cachedOutput, $dependentPage,
	ParserOptions::newFromAnon()), 'Current semantic revision permits the parser cache');

$subjectA = SemanticWikiPage::newFromTitle(Title::newFromText('WD Cache query A'));
$validator = (new QueryDependencyLinksStoreFactory())->newDependencyLinksValidator();
check($validator->canCheckDependencies(), 'SMW dependency checks are enabled');
check(!$validator->hasArchaicDependencies($subjectA), 'A stable empty query is not archaic');
$initialFingerprint = SemanticRevisionStore::fingerprint($store, $subjectA);
check(is_string($initialFingerprint) && $initialFingerprint !== '',
	'A stable _ASK query exposes a durable dependency revision fingerprint');

fsSave('WD Cache child', '{{#set:WD Cache Parent=WD Cache A}}');
// Persist the SMW edit but deliberately hold the targeted purge job in queue.
fsFlush(['wikidebatesSemanticCachePurge']);
check($services->getJobQueueGroup()->queuesHaveJobs(),
	'Semantic purge is genuinely still pending after the data update');
check(!SemanticPageCacheHooks::onRejectParserCacheValue($cachedOutput, $dependentPage,
	ParserOptions::newFromAnon()),
	'Parser output from before a semantic edit is rejected while the purge job waits');
check(SemanticRevisionStore::fingerprint($store, $subjectA) !== $initialFingerprint,
	'The relevant query fingerprint advances before deferred purge jobs execute');
check($generation('WD Cache query A') === $queries['WD Cache query A'],
	'Neither the background purge nor a guard has invalidated the fragment yet');
check(SemanticRevisionStore::fingerprint($store,
	SemanticWikiPage::newFromTitle(Title::newFromText('WD Cache query B'))) !== '',
	'Unrelated query fingerprints remain readable while purge jobs are blocked');

$beforePurge = $read('WD Cache query A');
check($beforePurge['source'] !== 'fragment-cache',
	'A pending semantic purge must bypass the old fragment');
check(str_contains($beforePurge['html'], 'WD_MATCHES=1'),
	'Before any targeted purge runs, the reading API must show the new relation');
check($services->getJobQueueGroup()->queuesHaveJobs(),
	'The freshness check works without draining or relying on the queued purge');

fsFlush();

$valuesAfterInsert = fsValues('WD Cache child', 'WD Cache Parent');
check(count($valuesAfterInsert) === 1, 'The changed relation is persisted in the real SMW store');
$store->clear();
$directAfterInsert = (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom([
	'[[WD Cache Parent::WD Cache A]]', 'limit=200', 'link=none'
]);
check($directAfterInsert instanceof \SMW\Query\QueryResult && $directAfterInsert->getCount() === 1,
	'SMW query directly returns the newly added relation');

check($generation('WD Cache query A') !== $queries['WD Cache query A'],
	'Empty -> nonempty invalidates the already existing dependent page');
$queries['WD Cache query A'] = $generation('WD Cache query A');
$added = $read('WD Cache query A');
check($added['source'] !== 'fragment-cache' && str_contains($added['html'], 'WD_MATCHES=1'),
	'After a distant relation appears, the API reconstructs a non-stale fragment' . ' [source=' . ($added['source'] ?? '?') . ', expires=' . ($added['_expires'] ?? '?') . ', html=' . substr($added['html'] ?? '', 0, 300) . ']');
$repeatAfterInsert = $read('WD Cache query A');
check(str_contains($repeatAfterInsert['html'], 'WD_MATCHES=1') &&
	in_array($repeatAfterInsert['source'], ['parser', 'fragment-cache'], true),
	'An ambiguous same-second update must stay fresh even when caching is conservatively bypassed');


fsSave('WD Cache child', '{{#set:WD Cache Parent=WD Cache B}}');
fsFlush();
check($generation('WD Cache query A') !== $queries['WD Cache query A'],
	'Removing a relation invalidates the former parent query');
check($generation('WD Cache query B') !== $queries['WD Cache query B'],
	'Adding a relation invalidates the new parent query');
$queries['WD Cache query B'] = $generation('WD Cache query B');
check(str_contains($read('WD Cache query A')['html'], 'WD_MATCHES=0'),
	'Removing a parent removes it from the formerly cached API response');
check(str_contains($read('WD Cache query B')['html'], 'WD_MATCHES=1'),
	'Moving a child updates the new parent in the API response');


fsSave('WD Cache child', 'No parent');
fsFlush();
check($generation('WD Cache query B') !== $queries['WD Cache query B'],
	'Deleting the last relation invalidates the parent query');
$queries['WD Cache query B'] = $generation('WD Cache query B');

// Unchanged semantic values must not cause another cross-page invalidation.
fsSave('WD Cache child', 'No parent<!-- changed prose only -->');
fsFlush();
check($generation('WD Cache query B') === $queries['WD Cache query B'],
	'Unchanged semantic relations retain the dependent fragment generation');

// Moving and deleting a matching subject must purge the containing page,
// including a page with the same count but different matching subject titles.
$queryMembers = static function() use ($store): array {
	$store->clear();
	$result = (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom([
		'[[WD Cache Parent::WD Cache A]]', 'limit=200', 'link=none'
	]);
	check($result instanceof \SMW\Query\QueryResult, 'Real SQLStore returns a query result');
	$names = [];
	foreach ($result->getResults() as $item) {
		if ($item instanceof SemanticWikiPage && $item->getTitle()) {
			$names[] = $item->getTitle()->getPrefixedText();
		}
	}
	sort($names);
	return $names;
};
fsSave('WD Cache child', '{{#set:WD Cache Parent=WD Cache A}}');
fsFlush();
same(['WD Cache child'], $queryMembers(), 'Original subject is the only result before its move');
$priorMoveGeneration = $generation('WD Cache query A');
$move = $services->getMovePageFactory()->newMovePage(
	Title::newFromText('WD Cache child'), Title::newFromText('WD Cache child moved')
)->moveIfAllowed($fsUser, 'Disposable semantic move regression');
check($move->isOK(), 'Disposable subject page moves to another title');
fsFlush();
same(['WD Cache child moved'], $queryMembers(),
	'Move replaces the old query-result subject rather than duplicating it');
check($generation('WD Cache query A') !== $priorMoveGeneration,
	'Moving a matching subject purges the parent query despite an unchanged result count');
$priorDeleteGeneration = $generation('WD Cache query A');
$priorDeleteFingerprint = SemanticRevisionStore::fingerprint($store, $subjectA);
$services->getUserGroupManager()->addUserToGroup($fsUser, 'sysop');
$deletedPage = $services->getWikiPageFactory()->newFromTitle(Title::newFromText('WD Cache child moved'));
$deleted = $services->getDeletePageFactory()->newDeletePage($deletedPage, $fsUser)
	->deleteIfAllowed('Disposable semantic deletion regression');
check($deleted->isOK(), 'Synthetic matching page can be deleted');
// Keep targeted purge jobs pending while SMW completes deferred deletion.
fsFlush(['wikidebatesSemanticCachePurge']);
check($services->getJobQueueGroup()->queuesHaveJobs(),
	'Deleting a subject schedules a targeted dependent purge job');
check(SemanticRevisionStore::fingerprint($store, $subjectA) !== $priorDeleteFingerprint,
	'Deletion advances the semantic revision before dependent purge jobs run');
$deletedRead = $read('WD Cache query A');
check($deletedRead['source'] !== 'fragment-cache' &&
	str_contains($deletedRead['html'], 'WD_MATCHES=0'),
	'A deleted subject disappears from the API while purge jobs remain pending');
fsFlush();
same([], $queryMembers(), 'Deleting a matching page removes it from the SMW result');
check($generation('WD Cache query A') !== $priorDeleteGeneration,
	'Deleting a matching subject purges the dependent query page');

$parser = $services->getParserFactory()->create();
$parser->startExternalParse(Title::newFromText('WD Cache query A'),
	ParserOptions::newFromAnon(), $parser::OT_HTML);
$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], false);
$context->readSemantic(Table::wrap(['[[WD Cache Parent::WD Cache A]]',
	'limit=200', 'link=none']), 'cache-tracking');
check($parser->getOutput()->getExtensionData(TrackedSemanticQuery::OUTPUT_MARKER) === true,
	'Native read marks the ParserOutput when the dependency profile is recorded');
check($parser->getOutput()->getExtensionData(\SMW\ParserData::DATA_ID) instanceof \SMW\SemanticData,
	'Native _ASK annotation is written to the same SMW ParserOutput as #ask');

check(!$services->getJobQueueGroup()->queuesHaveJobs(), 'Invalidation jobs drained');

// A dropped query-link registration must fail closed, even when the
// corresponding _ASK profile still exists. This simulates a failed SMW
// dependency flush without introducing any persistent production changes.
$subjectForMissingLinks = SemanticWikiPage::newFromTitle(Title::newFromText('WD Cache query A'));
$storedQueries = array_values($links->findEmbeddedQueryIdListBySubject($subjectForMissingLinks));
check($storedQueries !== [], 'The query still has persisted _ASK profiles before simulated link loss');
$db = $store->getConnection('mw.db');
$db->newDeleteQueryBuilder()
	->deleteFrom(\SMW\SQLStore\SQLStore::QUERY_LINKS_TABLE)
	->where(['s_id' => $storedQueries])
	->caller('FullstackSemanticCacheTest')
	->execute();
$linksRejected = false;
try {
	SemanticRevisionStore::fingerprint($store, $subjectForMissingLinks);
} catch (\RuntimeException $e) {
	$linksRejected = true;
}
check($linksRejected,
	'Missing SMW query links are an unsafe, non-cacheable dependency state');
$missingResponse = $read('WD Cache query A');
check($missingResponse['source'] !== 'fragment-cache',
	'Reading API refuses a fragment with an _ASK profile but lost query links');



// A debate containing native semantic rendering may be created before its
// _ASK profiles are registered. It must not be cached as an empty query.
// The transclusion is deliberately beyond 8 KiB of harmless wikitext.
$unregisteredTitle = Title::newFromText('WD Cache unregistered debate');
fsSave('WD Cache unregistered debate', str_repeat('Prose without a query. ', 380) . "\n{{Débat}}");
fsFlush();
$unregisteredPage = $services->getWikiPageFactory()->newFromTitle($unregisteredTitle);
$unregisteredSubject = SemanticWikiPage::newFromTitle($unregisteredTitle);
$store->clear();
check($store->getPropertyValues($unregisteredSubject, new Property('_ASK')) === [],
	'New debate still has no _ASK profile before its semantic renderer executes');
check(SemanticPageCacheHooks::isQueryPage($unregisteredTitle, $unregisteredPage),
	'Full source scan identifies a debate transclusion beyond the first 8 KiB');
check(!SemanticCacheGuard::safeForRead($unregisteredTitle, $unregisteredPage),
	'The API refuses shared fragments for unregistered semantic debates');
$fullPageContext->setTitle($unregisteredTitle);
check(!SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
	'An unregistered semantic debate also bypasses full-page HTML file caching');


echo "OK: SMW 7.3.1 native profiles, empty results, inserts, moves, deletes and fragment invalidation\n";
