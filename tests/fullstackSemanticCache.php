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
use MediaWiki\Extension\DebatePHP\SemanticReadResult;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
use MediaWiki\Extension\Wikidebates\Reading\SemanticCacheGuard;
use MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob;
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
check($services->getMainConfig()->get('WikidebatesSemanticCacheEagerPurge') === true,
	'Disposable semantic cache lab explicitly enables eager purge');
$jobTitle = Title::newFromText('WD Cache child');
$jobA = new SemanticCachePurgeJob($jobTitle, ['dependencyIds' => [31, 12, 31], 'cursor' => 0]);
$jobB = new SemanticCachePurgeJob($jobTitle, ['dependencyIds' => [12, 31], 'cursor' => 0]);
$jobC = new SemanticCachePurgeJob($jobTitle, ['dependencyIds' => [12, 32], 'cursor' => 0]);
$jobNextPage = new SemanticCachePurgeJob($jobTitle, ['dependencyIds' => [31, 12], 'cursor' => 100]);
check($jobA->ignoreDuplicates(), 'Semantically equivalent purge jobs are deduplicable');
same($jobA->getDeduplicationInfo(), $jobB->getDeduplicationInfo(),
	'Dependency order and duplicate IDs do not affect purge deduplication');
check($jobA->getDeduplicationInfo() !== $jobC->getDeduplicationInfo(),
	'Distinct dependency sets are never merged by deduplication');
check($jobA->getDeduplicationInfo() !== $jobNextPage->getDeduplicationInfo(),
	'Distinct pagination cursors are never merged by deduplication');

$register = static function($parser): void {
	$parser->setHook('wd-cache-parents', static function($input, $args, $parser, $frame): string {
		$ctx = new MediaWikiContext($parser, $frame, [], false);
		$rows = $ctx->readSemantic(Table::wrap([
			'[[WD Cache Parent::' . trim($input) . ']]',
			'limit=200', 'link=none'
		]), 'cache-tracking');
		return 'WD_MATCHES=' . count($rows->rows ?? []);
	});
	// Mirror the actual ArgumentReferenceRenderer parent queries: two
	// alternatives, both property printouts, and the native PHP reader.
	foreach ([
		'wd-cache-debate-parents' => ['WD Cache Pro', 'WD Cache Con', 'debate-parents'],
		'wd-cache-argument-parents' => ['WD Cache Justif', 'WD Cache Obj', 'argument-parents'],
	] as $tag => $properties) {
		$parser->setHook($tag, static function($input, $args, $parser, $frame) use ($properties): string {
			[$left, $right, $origin] = $properties;
			$context = new MediaWikiContext($parser, $frame, [], false);
			$rows = $context->readSemantic(Table::wrap([
				'([[' . $left . '::' . trim($input) . ']] OR [[' . $right . '::' . trim($input) . ']] )',
				'?' . $left . '=a',
				'?' . $right . '=b',
				'limit=200',
				'link=none',
			]), $origin);
			$a = $b = 0;
			foreach (SemanticReadResult::sequence($rows->rows ?? []) as $row) {
				if (!is_array($row)) {
					continue;
				}
				if (($row['a'] ?? null) !== null && ($row['a'] ?? null) !== '') {
					$a++;
				}
				if (($row['b'] ?? null) !== null && ($row['b'] ?? null) !== '') {
					$b++;
				}
			}
			return 'WD_OR_A=' . $a . ';WD_OR_B=' . $b .
				';WD_OR_TOTAL=' . count($rows->rows ?? []);
		});
	}
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

// Exercise the reading service with an anonymous context and revision-keyed fragment store.
// Semantic freshness belongs to full-page ParserOutput, not this API.
$read = static function(string $name) use ($services, $store): array {
	// A real HTTP request has a fresh SMW store/object cache. This isolated
	// CLI test reuses one PHP process, so reset that request-local state.
	// Otherwise a property removed moments earlier may still appear in
	// a later query, even when the persistent SQLStore is already current.
	$store->clear();
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
check($observedParseOutput->getExtensionData(SemanticPageCacheHooks::OUTPUT_REVISION) === null,
	'Reading-only ApiParse never computes a semantic freshness fingerprint');
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

$purgeTargetTitle = Title::newFromText('WD Cache query A');
$smwPurgeKey = smwfCacheKey(\SMW\MediaWiki\Hooks\ArticlePurge::CACHE_NAMESPACE,
	$purgeTargetTitle->getArticleID());
$smwPurgeCache = ServicesFactory::getInstance()->getObjectCache();
$smwPurgeCache->delete($smwPurgeKey);
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
check($beforePurge['source'] === 'fragment-cache' &&
	str_contains($beforePurge['html'], 'WD_MATCHES=0'),
	'An external semantic update does not invalidate an unchanged argument fragment');
check($services->getJobQueueGroup()->queuesHaveJobs(),
	'Reading the cached fragment does not drain the semantic purge queue');

fsFlush();
check($smwPurgeCache->get($smwPurgeKey) === false,
	'Cache-only dependent purge never schedules an SMW purge-driven semantic rewrite');

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
check($deletedRead['source'] !== 'busy' && $deletedRead['html'] !== '',
	'Fragment remains readable without forcing a semantic refresh during deletion');
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
check($missingResponse['source'] === 'fragment-cache',
	'Reading API reuses a valid fragment without consulting broken SMW query links');



// __NOCACHE__ currently applies to Argument pages, not Debate pages.
// A debate-only native render writes properties but contains no SMW
// query; it must keep its parser cache and full HTML file cache.
$plainDebateTitle = Title::newFromText('WD Cache plain debate');
fsSave('WD Cache plain debate', str_repeat('Ordinary debate prose. ', 380) .
	"\n{{#debate:render|topic=Ordinary debate without a query}}");
fsFlush();
$plainDebatePage = $services->getWikiPageFactory()->newFromTitle($plainDebateTitle);
$store->clear();
check($store->getPropertyValues(SemanticWikiPage::newFromTitle($plainDebateTitle),
	new Property('_ASK')) === [],
	'Debate-only native rendering has no _ASK query profile');
check(!SemanticPageCacheHooks::isQueryPage($plainDebateTitle, $plainDebatePage),
	'A debate-only render is not a semantic query page, even after 8 KiB');
check(SemanticCacheGuard::safeForRead($plainDebateTitle, $plainDebatePage),
	'Debate without semantic queries retains eligibility for prepared fragments');
$fullPageContext->setTitle($plainDebateTitle);
check(SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
	'Debate-only pages keep their full HTML file cache enabled');
$debateViewOutput = new \MediaWiki\Output\OutputPage($fullPageContext);
SemanticPageCacheHooks::onBeforePageDisplay($debateViewOutput, null);
$debateViewOutput->considerCacheSettingsFinal();
check($debateViewOutput->couldBePublicCached(),
	'Debate-only pages retain public HTML caching');
$debateParserOutput = $plainDebatePage->getParserOutput(ParserOptions::newFromAnon());
check($debateParserOutput instanceof \MediaWiki\Parser\ParserOutput &&
	$debateParserOutput->isCacheable() &&
	$debateParserOutput->getCacheExpiry() > 0,
	'Real #debate:render ParserOutput remains cacheable');
check(SemanticPageCacheHooks::onRejectParserCacheValue(
	new \MediaWiki\Parser\ParserOutput(), $plainDebatePage, ParserOptions::newFromAnon()
), 'Ordinary debate ParserOutput does not require an _ASK revision marker');

// Argument pages must still fail closed before their first query
// profile, including when a transclusion is beyond the first 8 KiB.
$unregisteredTitle = Title::newFromText('WD Cache unregistered argument');
fsSave('WD Cache unregistered argument',
	str_repeat('Prose without a query. ', 380) . "\n{{Argument}}");
fsFlush();
$unregisteredPage = $services->getWikiPageFactory()->newFromTitle($unregisteredTitle);
$store->clear();
check($store->getPropertyValues(SemanticWikiPage::newFromTitle($unregisteredTitle),
	new Property('_ASK')) === [],
	'Unregistered argument has no _ASK profile yet');
check(SemanticPageCacheHooks::isQueryPage($unregisteredTitle, $unregisteredPage),
	'Argument transclusion beyond the first 8 KiB is detected');
check(!SemanticCacheGuard::safeForRead($unregisteredTitle, $unregisteredPage),
	'Unregistered argument refuses a shared fragment');
$fullPageContext->setTitle($unregisteredTitle);
check(!SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
	'Unregistered argument bypasses unsafe full HTML file cache');

// A direct PHP Argument invocation requires protection even without
// its template. A standalone Debate invocation does not. Legacy Lua
// Argument readers also execute semantic queries.
foreach ([
	'argument' => ['{{#argument:render|summary=Native argument}}', true],
	'debate' => ['{{#debate:render|topic=Native debate}}', false],
	'legacy-argument' => ['{{#invoke:Argument|render}}', true],
	'mixed' => ['{{#debate:render|topic=Debate}}{{#argument:render|summary=Argument}}', true],
] as $kind => [$source, $expected]) {
	$title = Title::newFromText('WD Cache source ' . $kind);
	$content = \MediaWiki\Content\ContentHandler::makeContent($source, $title);
	$page = new class($content) {
		private $content;
		public function __construct($content) { $this->content = $content; }
		public function getContent() { return $this->content; }
	};
	$store->clear();
	check($store->getPropertyValues(SemanticWikiPage::newFromTitle($title),
		new Property('_ASK')) === [],
		'Unparsed ' . $kind . ' page has no semantic profile');
	check(SemanticPageCacheHooks::isQueryPage($title, $page) === $expected,
		'Query classification for ' . $kind . ' matches actual semantic reads');
	check(SemanticCacheGuard::safeForRead($title, $page) === !$expected,
		'Fragment guard preserves debate-only caching, protects ' . $kind);
}

// Indirect custom wrappers still require a preproduction inventory.

// The production Argument renderer uses combined OR queries with two
// property printouts for its debate parents and justification/objection
// parents. A single-property #ask is not sufficient to prove that an empty
// OR query will notice its first match or that a same-cardinality switch
// will invalidate HTML while a purge job is delayed.
$combined = [
	'debate' => [
		'tag' => 'wd-cache-debate-parents',
		'properties' => ['WD Cache Pro', 'WD Cache Con'],
	],
	'argument' => [
		'tag' => 'wd-cache-argument-parents',
		'properties' => ['WD Cache Justif', 'WD Cache Obj'],
	],
];
fsSave('WD Cache searched argument', 'Target argument for combined-query tests');
foreach ($combined as $kind => $case) {
	foreach ($case['properties'] as $property) {
		fsSave('Property:' . $property, '[[Has type::Page]]');
	}
	fsSave('WD Cache combined parent ' . $kind, 'No semantic parent yet');
	fsSave('WD Cache combined query ' . $kind, 'Initial stub');
}
fsFlush();
sleep(2);
foreach ($combined as $kind => $case) {
	fsSave('WD Cache combined query ' . $kind,
		"== Summary ==\n" .
		'<' . $case['tag'] . '>WD Cache searched argument</' . $case['tag'] . '>' .
		"\n== Parent debates ==\n" . $kind);
}
fsFlush();
foreach ($combined as $kind => $case) {
	$queryTitle = 'WD Cache combined query ' . $kind;
	$parentTitle = 'WD Cache combined parent ' . $kind;
	$subject = SemanticWikiPage::newFromTitle(Title::newFromText($queryTitle));
	$store->clear();
	check($store->getPropertyValues($subject, new Property('_ASK')) !== [],
		'Combined ' . $kind . ' OR query persists a real _ASK profile');

	$empty = $read($queryTitle);
	check(str_contains($empty['html'], 'WD_OR_A=0;WD_OR_B=0;WD_OR_TOTAL=0'),
		'Combined ' . $kind . ' parent query is initially empty; source=' .
		($empty['source'] ?? '?') . '; html=' . substr($empty['html'] ?? '', 0, 1200));
	$cached = $read($queryTitle);
	check($cached['source'] === 'fragment-cache',
		'Empty combined ' . $kind . ' query safely reuses its fragment');
	$previous = SemanticRevisionStore::fingerprint($store, $subject);

	// The first member appears while the dependent purge job is blocked.
	fsSave($parentTitle, '{{#set:' . $case['properties'][0] . '=WD Cache searched argument}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $subject) !== $previous,
		'First ' . $kind . ' OR result changes the revision before purge');
	$added = $read($queryTitle);
	check($added['source'] === 'fragment-cache' &&
		str_contains($added['html'], 'WD_OR_A=0;WD_OR_B=0;WD_OR_TOTAL=0'),
		'First ' . $kind . ' external OR change retains the old fragment');
	fsFlush();
	$read($queryTitle);
	check($read($queryTitle)['source'] === 'fragment-cache',
		'Optional eager purge permits the updated fragment to be warmed again');

	$previous = SemanticRevisionStore::fingerprint($store, $subject);
	// Same query-result count, but the matching property and printout flip.
	fsSave($parentTitle, '{{#set:' . $case['properties'][1] . '=WD Cache searched argument}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $subject) !== $previous,
		'Same-cardinality ' . $kind . ' relation switch changes the revision');
	$switched = $read($queryTitle);
	check($switched['source'] === 'fragment-cache' &&
		str_contains($switched['html'], 'WD_OR_A=1;WD_OR_B=0;WD_OR_TOTAL=1'),
		'Combined ' . $kind . ' OR switch preserves the existing cached excerpt');
	fsFlush();
	$read($queryTitle);
	check($read($queryTitle)['source'] === 'fragment-cache',
		'Updated OR switch is cacheable after the optional eager purge');

	$previous = SemanticRevisionStore::fingerprint($store, $subject);
	fsSave($parentTitle, 'No parent');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $subject) !== $previous,
		'Last ' . $kind . ' OR relation removal changes the revision');
	$removed = $read($queryTitle);
	check($removed['source'] === 'fragment-cache' &&
		str_contains($removed['html'], 'WD_OR_A=0;WD_OR_B=1;WD_OR_TOTAL=1'),
		'Combined ' . $kind . ' OR removal retains the prior cached excerpt');
	fsFlush();
}

// Render a real native #argument:render page in French, not merely a
// diagnostic test tag. Its parent-query shapes come from ArgumentReferenceRenderer
// and the resulting HTML passes through the same reading fragment pipeline.
$i18n = \MediaWiki\Extension\DebatePHP\I18n::bundled();
$proProperty = $i18n->message('Argument.props', 'fr', 'arg_pro');
$conProperty = $i18n->message('Argument.props', 'fr', 'arg_con');
$justificationProperty = $i18n->message('Argument.props', 'fr', 'justif');
$objectionProperty = $i18n->message('Argument.props', 'fr', 'obj');
foreach ([$proProperty, $conProperty, $justificationProperty, $objectionProperty] as $property) {
	fsSave('Property:' . $property, '[[Has type::Page]]');
}
$actualArgument = 'WD Cache real argument fr';
$actualParentA = 'WD Cache real parent A';
$actualParentB = 'WD Cache real parent B';
fsSave($actualArgument, 'Initial stub');
fsSave($actualParentA, 'No semantic relation');
fsSave($actualParentB, 'No semantic relation');
fsFlush();
sleep(2);
fsSave($actualArgument,
	'{{#argument:render|lang=fr|summary=Résumé du véritable argument de test}}');
fsFlush();

$actualTitle = Title::newFromText($actualArgument);
$actualSubject = SemanticWikiPage::newFromTitle($actualTitle);
$store->clear();
check($store->getPropertyValues($actualSubject, new Property('_ASK')) !== [],
	'The real French Argument renderer persists native SMW query profiles');
$actualFirst = $read($actualArgument);
check(str_contains($actualFirst['html'], 'Résumé du véritable argument de test'),
	'Real native Argument HTML contains its summary: ' . substr($actualFirst['html'], 0, 700));
$actualCached = null;
for ($attempt = 0; $attempt < 5; $attempt++) {
	$candidate = $read($actualArgument);
	if ($candidate['source'] === 'fragment-cache') {
		$actualCached = $candidate;
		break;
	}
}
check($actualCached !== null, 'Real French argument reuses a semantic-tracked fragment');

$previousActual = SemanticRevisionStore::fingerprint($store, $actualSubject);
fsSave($actualParentA, '{{#set:' . $proProperty . '=' . $actualArgument . '}}');
fsFlush(['wikidebatesSemanticCachePurge']);
check($services->getJobQueueGroup()->queuesHaveJobs(),
	'Real Argument parent edit leaves a targeted purge job pending');
check(SemanticRevisionStore::fingerprint($store, $actualSubject) !== $previousActual,
	'Real Argument parent relation advances the revision before purge');
$actualAdded = $read($actualArgument);
check($actualAdded['source'] === 'fragment-cache',
	'Real Argument excerpt survives a parent-only semantic update');
$actualWikiPage = $services->getWikiPageFactory()->newFromTitle($actualTitle);
$fullAfterAdd = $actualWikiPage->getParserOutput(ParserOptions::newFromAnon());
check($fullAfterAdd instanceof \MediaWiki\Parser\ParserOutput,
	'MediaWiki returns a full native Argument ParserOutput after parent edit');
$actualFullHtml = $fullAfterAdd->getText();
// FragmentExtractor intentionally excludes the Parent_debates section.
// Parent breadcrumbs and banner links must be validated in the FULL page.
$hasActualParent = static fn(string $html, string $name): bool =>
	str_contains($html, $name) || str_contains($html, str_replace(' ', '_', $name));
check($hasActualParent($actualFullHtml, $actualParentA),
	'Real full French Argument HTML shows its new parent before purge: ' .
	substr($actualFullHtml, -2200));
fsFlush();
$read($actualArgument);
check($read($actualArgument)['source'] === 'fragment-cache',
	'Real Argument excerpt is warmed after optional eager purge');

$previousActual = SemanticRevisionStore::fingerprint($store, $actualSubject);
fsSave($actualParentA, 'No semantic relation');
fsSave($actualParentB, '{{#set:' . $conProperty . '=' . $actualArgument . '}}');
fsFlush(['wikidebatesSemanticCachePurge']);
check(SemanticRevisionStore::fingerprint($store, $actualSubject) !== $previousActual,
	'Real Argument parent transfer advances the semantic revision');
$actualMoved = $read($actualArgument);
check($actualMoved['source'] === 'fragment-cache',
	'Real Argument excerpt survives a parent transfer before purge');
$fullAfterMove = $actualWikiPage->getParserOutput(ParserOptions::newFromAnon());
check($fullAfterMove instanceof \MediaWiki\Parser\ParserOutput,
	'Full native Argument ParserOutput survives a parent transfer');
$fullAfterMoveHtml = $fullAfterMove->getText();
check($hasActualParent($fullAfterMoveHtml, $actualParentB) &&
	!$hasActualParent($fullAfterMoveHtml, $actualParentA),
	'Real Argument replaces its displayed debate parent before purge: ' .
	substr($fullAfterMoveHtml, -2600));
fsFlush();


// Model a real, old Argument page like production page_id=1220: ordinary
// {{Argument|résumé=...}} transclusion, initially rendered with tracking OFF.
// Enabling tracking must reject its old ParserOutput, record the first real
// _ASK graph, and invalidate a warmed fragment when a parent changes.
$bundledArgumentTemplate = file_get_contents(
	__DIR__ . '/../debatephp/resources/Template-Argument.php.wikitext'
);
check(is_string($bundledArgumentTemplate) &&
	!str_contains(strtoupper($bundledArgumentTemplate), '__NOCACHE__'),
	'Bundled Argument template remains cacheable without the legacy magic word');
fsSave('Template:Argument',
	str_replace('{{#argument:render', '{{#argument:render|lang=fr',
		$bundledArgumentTemplate));
$historicalArgument = 'WD Cache previously untracked Argument';
$historicalParent = 'WD Cache previously untracked parent';
fsSave($historicalArgument, 'Before the Argument transclusion');
fsSave($historicalParent, 'No parent relation yet');
fsFlush();

$originalTracking = $GLOBALS['wgWikidebatesSemanticCacheTracking'];
$originalEagerPurge = $GLOBALS['wgWikidebatesSemanticCacheEagerPurge'];
try {
	// A staging migration uses tracking without eager purge jobs.
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = false;
	check($services->getMainConfig()->get('WikidebatesSemanticCacheEagerPurge') === false,
		'Historical Argument migration does not require eager purges');
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = false;
	fsSave($historicalArgument,
		'{{Argument|résumé=Un argument historique sans profil enregistré}}');
	fsFlush();

	$historicalTitle = Title::newFromText($historicalArgument);
	$historicalPage = $services->getWikiPageFactory()->newFromTitle($historicalTitle);
	$historicalSubject = SemanticWikiPage::newFromTitle($historicalTitle);
	$store->clear();
	check($store->getPropertyValues($historicalSubject, new Property('_ASK')) === [],
		'The actual Argument transclusion has no _ASK profile while tracking is off');
	$untrackedOutput = $historicalPage->getParserOutput(ParserOptions::newFromAnon());
	check($untrackedOutput instanceof \MediaWiki\Parser\ParserOutput &&
		str_contains($untrackedOutput->getText(), 'Un argument historique') &&
		$untrackedOutput->getExtensionData(SemanticPageCacheHooks::OUTPUT_REVISION) === null,
		'Previously untracked Argument HTML is parsed without a semantic revision marker');

	// Transition to the opt-in pilot without editing the page again.
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = true;
	$store->clear();
	check(SemanticPageCacheHooks::isQueryPage($historicalTitle, $historicalPage),
		'The unprofiled transcluded Argument is recognized before the first traced parse');
	check(!SemanticPageCacheHooks::onRejectParserCacheValue(
		$untrackedOutput, $historicalPage, ParserOptions::newFromAnon()
	), 'Enabling tracking rejects the former untracked Argument parser cache');
	check(!SemanticCacheGuard::safeForRead($historicalTitle, $historicalPage),
		'An unprofiled Argument is never treated as safe for fragment cache reuse');
	$fullPageContext->setTitle($historicalTitle);
	check(!SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
		'Old full-page file caches cannot bypass the first traced Argument parse');

	$observedFirstTrackedOutput = null;
	$firstTrackedObserver = $services->getHookContainer()->scopedRegister('ParserOutputPostCacheTransform',
		static function($output, &$text, &$options) use (&$observedFirstTrackedOutput): void {
			$observedFirstTrackedOutput = $output;
		});
	$historicalFirst = $read($historicalArgument);
	$firstTrackedObserver = null;
	check($historicalFirst['source'] !== 'fragment-cache' &&
		str_contains($historicalFirst['html'], 'Un argument historique'),
		'First tracked Argument reading is freshly rendered with its real summary');
	fsFlush(['wikidebatesSemanticCachePurge']);
	$store->clear();
	$historicalProfilesAfterApi = $store->getPropertyValues(
		$historicalSubject, new Property('_ASK'));
	check($observedFirstTrackedOutput instanceof \MediaWiki\Parser\ParserOutput &&
		$observedFirstTrackedOutput->getExtensionData(TrackedSemanticQuery::OUTPUT_MARKER) === true,
		'Fresh API parse executes the tracked native Argument query');

	// ApiParse may annotate its temporary ParserOutput without writing the
	// canonical SMW subject. Exercise a real page-view parse separately
	// before concluding that a pre-existing argument needs migration.
	$access = $services->getParserOutputAccess();
	$local = (new \ReflectionObject($access))->getProperty('localCache');
	$local->setValue($access, new \MapCacheLRU(10));
	$canonicalTrackedOutput = $historicalPage->getParserOutput(ParserOptions::newFromAnon());
	check($canonicalTrackedOutput instanceof \MediaWiki\Parser\ParserOutput &&
		$canonicalTrackedOutput->getExtensionData(TrackedSemanticQuery::OUTPUT_MARKER) === true,
		'Canonical Argument parse after enabling tracking executes native traced queries');
	fsFlush(['wikidebatesSemanticCachePurge']);
	$store->clear();
	$historicalProfiles = $store->getPropertyValues($historicalSubject, new Property('_ASK'));
	check($historicalProfilesAfterApi === [] && $historicalProfiles === [],
		'Anonymous API and ordinary page reads annotate ParserOutput but do not update SMW storage');
	// MediaWiki 1.43 RefreshLinksJob performs the actual secondary data
	// update without a page edit. This is a standard, revision-preserving
	// recovery path for old Argument pages; exercise it on the disposable
	// page only, not on production.
	$revisionBeforeRefresh = $historicalPage->getLatest();
	$refresh = new \RefreshLinksJob($historicalTitle, [
		'causeAction' => 'WikidebatesSemanticColdArgumentTest',
		'causeAgent' => 'Wikidebates disposable integration',
	]);
	check($refresh->run() !== false,
		'Single-page MediaWiki refreshLinks job updates the semantic subject without editing');
	fsFlush(['wikidebatesSemanticCachePurge']);
	$store->clear();
	$historicalProfiles = $store->getPropertyValues($historicalSubject, new Property('_ASK'));
	check($historicalProfiles !== [],
		'Standard MediaWiki refreshLinks writes the missing _ASK query profiles; ' . json_encode([
			'api_marker' => $observedFirstTrackedOutput->getExtensionData(
				TrackedSemanticQuery::OUTPUT_MARKER),
			'canonical_marker' => $canonicalTrackedOutput->getExtensionData(
				TrackedSemanticQuery::OUTPUT_MARKER),
			'links' => count($links->findEmbeddedQueryIdListBySubject($historicalSubject)),
			'revision_before' => $revisionBeforeRefresh,
			'revision_after' => $historicalPage->getLatest(),
		]));
	check($historicalPage->getLatest() === $revisionBeforeRefresh,
		'Refreshing semantic profiles does not create or change the Argument revision');
	check($links->findEmbeddedQueryIdListBySubject($historicalSubject) !== [],
		'First traced parse also creates SMW query dependency links');

	$firstTrackedFingerprint = SemanticRevisionStore::fingerprint($store, $historicalSubject);
	check(SemanticCacheGuard::safeForRead($historicalTitle, $historicalPage),
		'After the profile is stored, the Argument can safely use a tracked fragment');
	$warmedHistorical = null;
	for ($attempt = 0; $attempt < 6; $attempt++) {
		$candidate = $read($historicalArgument);
		if ($candidate['source'] === 'fragment-cache') {
			$warmedHistorical = $candidate;
			break;
		}
	}
	check($warmedHistorical !== null,
		'Formerly untracked Argument eventually reuses a verified fragment cache');

	fsSave($historicalParent,
		'{{#set:' . $proProperty . '=' . $historicalArgument . '}}');
	// Hold deliberately queued purges. The read guard must detect the change.
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $historicalSubject) !==
		$firstTrackedFingerprint,
		'Tracked Argument fingerprint changes after first new parent relation');
	$updatedHistorical = $read($historicalArgument);
	check($updatedHistorical['source'] === 'fragment-cache',
		'Parent update leaves the formerly untracked Argument excerpt available');

	$historicalParserOutput = $historicalPage->getParserOutput(
		ParserOptions::newFromAnon()
	);
	check($historicalParserOutput instanceof \MediaWiki\Parser\ParserOutput &&
		(str_contains($historicalParserOutput->getText(), $historicalParent) ||
			str_contains($historicalParserOutput->getText(),
				str_replace(' ', '_', $historicalParent))),
		'Full tracked Argument HTML displays its new parent after the transition');

	// Check the reverse transition on the same formerly untracked Argument.
	// Delayed eager purges must not permit a stale parent to survive.
	$parentAddedFingerprint = SemanticRevisionStore::fingerprint($store, $historicalSubject);
	fsSave($historicalParent, 'Parent relation removed again');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $historicalSubject) !==
		$parentAddedFingerprint,
		'Removing the parent changes the previously untracked Argument fingerprint');
	$removedHistorical = $read($historicalArgument);
	check($removedHistorical['source'] === 'fragment-cache',
		'Parent removal leaves the unchanged Argument excerpt available');
	$historicalAfterRemoval = $historicalPage->getParserOutput(
		ParserOptions::newFromAnon()
	);
	check($historicalAfterRemoval instanceof \MediaWiki\Parser\ParserOutput &&
		!str_contains($historicalAfterRemoval->getText(), $historicalParent) &&
		!str_contains($historicalAfterRemoval->getText(),
			str_replace(' ', '_', $historicalParent)),
		'Full tracked Argument HTML no longer displays the removed parent');
	check(SemanticCacheGuard::safeForRead($historicalTitle, $historicalPage),
		'Formerly untracked Argument remains eligible for guarded caching after parent removal');
	$recachedAfterRemoval = false;
	for ($attempt = 0; $attempt < 6; $attempt++) {
		$candidate = $read($historicalArgument);
		if ($candidate['source'] === 'fragment-cache') {
			$recachedAfterRemoval = true;
			break;
		}
	}
	check($recachedAfterRemoval,
		'Formerly untracked Argument resumes fragment cache hits after parent removal');

	// Enabling tracking and migrating one Argument must never disable
	// caching for an ordinary Debate with no semantic queries.
	check(SemanticCacheGuard::safeForRead($plainDebateTitle, $plainDebatePage),
		'Ordinary Debate stays eligible for cached reading after Argument migration');
	$fullPageContext->setTitle($plainDebateTitle);
	check(SemanticPageCacheHooks::onHTMLFileCache__useFileCache($fullPageContext),
		'Ordinary Debate still allows the full HTML file cache after Argument migration');
	fsFlush();
} finally {
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = $originalTracking;
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = $originalEagerPurge;
}

echo "OK: SMW 7.3.1 native profiles, empty results, inserts, moves, deletes and fragment invalidation\n";
