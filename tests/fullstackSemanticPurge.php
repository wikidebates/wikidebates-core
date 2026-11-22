<?php

/** Existing genuine cache tests also exercise the pre-migration fallback. */
require __DIR__ . '/fullstackSemanticCache.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeScope;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeState;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;

$registerPurgeQuery = static function($parser): void {
	$parser->setHook('wd-purge-query', static function($input, $args, $parser, $frame): string {
		$ctx = new MediaWikiContext($parser, $frame, [], false);
		$result = $ctx->readSemantic(Table::wrap([
			'[[WD Purge Shared::' . trim($input) . ']]', 'limit=200', 'link=none'
		]), 'purge-integration');
		return 'PURGE_MATCHES=' . count($result->rows ?? []);
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $registerPurgeQuery);
$registerPurgeQuery($services->getParser());
fsSave('Property:WD Purge Shared', '[[Has type::Page]]');
fsSave('WD Purge Target A', 'Target A');
fsSave('WD Purge Target B', 'Target B');
fsSave('WD Purge Owner A', 'Initial stub');
fsSave('WD Purge Owner B', 'Initial stub');
fsFlush();
fsSave('WD Purge Owner A', "== Summary ==\n" .
	'<wd-purge-query>WD Purge Target A</wd-purge-query>' .
	'<wd-purge-query>WD Purge Target B</wd-purge-query>');
fsSave('WD Purge Owner B', "== Summary ==\n" .
	'<wd-purge-query>WD Purge Target A</wd-purge-query>');
fsFlush();

$state = new SemanticPurgeState($store);
check(!$state->available(), 'Existing fullstack tests ran without the optional receipt table');
$db->query(file_get_contents(__DIR__ . '/../sql/' . $backend . '/wd_smw_purge_state.sql'), __FILE__);
check($state->available(), 'Backend-specific receipt migration works on ' . $backend);
$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
$GLOBALS['wgWikidebatesSemanticCachePurgeBatchSize'] = 1000;
$GLOBALS['wgWikidebatesSemanticCachePurgeLog'] = false;
check($services->getMainConfig()->has('WikidebatesSemanticCachePurgeBatchSize'),
	'Optional tuning is visible through MediaWiki GlobalVarConfig');
$depId = (int)$store->getObjectIds()->getId((new Property('WD_Purge_Shared'))->getCanonicalDiWikiPage());
$targetId = (int)$store->getObjectIds()->getId(SemanticWikiPage::newFromTitle(Title::newFromText('WD Purge Target A')));
check($depId > 0 && $targetId > 0, 'Real dependency IDs');
$owners = [Title::newFromText('WD Purge Owner A'), Title::newFromText('WD Purge Owner B')];
$firstQueries = $links->findEmbeddedQueryIdListBySubject(SemanticWikiPage::newFromTitle($owners[0]));
check(count($firstQueries) === 2, 'Two distinct genuine SMW queries belong to one owner');

$fragmentStore = FragmentService::store();
$parserCache = $services->getParserCache();
$options = ParserOptions::newFromAnon();
$oldGenerations = [];
// Real filesystem fragments and real ParserCache storage, not adapter counters.
sleep(1);
foreach ($owners as $title) {
	$key = $title->getPrefixedDBkey();
	$snapshot = $fragmentStore->read($key, 'purge-test', ['test' => 1]);
	$oldGenerations[$key] = $snapshot['generation'];
	check($fragmentStore->publish($key, 'purge-test', ['test' => 1], $snapshot['generation'],
		'<p>old cached test fragment</p>', [], time() + 3600), 'Real fragment prepared');
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	$output = new ParserOutput('old cached parser result');
	$output->updateCacheExpiry(3600);
	$output->setExtensionData(SemanticPageCacheHooks::OUTPUT_REVISION,
		SemanticRevisionStore::fingerprint($store, SemanticWikiPage::newFromTitle($title)));
	$parserCache->save($output, $page, $options, wfTimestampNow(), $page->getLatest());
	check($parserCache->get($page, $options) !== false, 'Real parser entry is readable before purge');
}
$articlePurges = 0;
$htmlTargets = [];
$htmlOrigins = [];
check(!SemanticPurgeScope::isActive(), 'Ordinary requests begin outside semantic purge scope');
$articleObserver = $services->getHookContainer()->scopedRegister('ArticlePurge',
	static function() use (&$articlePurges): void { $articlePurges++; });
$htmlObserver = $services->getHookContainer()->scopedRegister('TitleSquidURLs',
	static function($title, &$urls) use (&$htmlTargets, &$htmlOrigins): void {
		$htmlTargets[] = $title->getPrefixedDBkey();
		$htmlOrigins[] = SemanticPurgeScope::isActive();
	});
$run = static function(array $ids, int $cursor = 0, array $extra = []) use ($owners, $services): SemanticCachePurgeJob {
	$job = new SemanticCachePurgeJob($owners[0], $extra + ['dependencyIds' => $ids, 'cursor' => $cursor]);
	check($job->run(), 'Real purge succeeds: ' . $job->getLastError());
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
	return $job;
};
// Only this disposable database opts into semantic-dependent preparation.
// Production defaults remain false, and the writable root is not web-served.
$queueRoot = sys_get_temp_dir() . '/wd-smw-prewarm-' . bin2hex(random_bytes(8));
check(mkdir($queueRoot, 0700), 'Private semantic cache preparation lab root created');
$GLOBALS['wgWikidebatesCachePreparationDirectory'] = $queueRoot;
$prewarmQueue = new CachePreparationQueue($queueRoot, \MediaWiki\WikiMap\WikiMap::getCurrentWikiId());

$sqlPrimary = $services->getConnectionProvider()->getPrimaryDatabase();
$touchBefore = [];
foreach ($owners as $title) {
	$touchBefore[$title->getArticleID()] = $sqlPrimary->newSelectQueryBuilder()
		->select(['page_latest', 'page_touched'])->from('page')
		->where(['page_id' => $title->getArticleID()])
		->caller(__FILE__)->fetchRow();
	check($touchBefore[$title->getArticleID()] !== false,
		'Semantic owner page exists before invalidation');
}
$ledgerBefore = SemanticPurgeState::revisions($store, [$depId]);
$first = $run([$depId]);
same(2, $first->getMetrics()['invalidations'], 'Three queries invalidate exactly two pages');
same(2, $first->getMetrics()['dependent_pages_queued_for_prewarm'],
	'Both semantically dependent pages are queued after successful HTML purge');
$queuedGenerations = [];
foreach ($owners as $title) {
	$id = $title->getArticleID();
	$record = $prewarmQueue->current($id);
	check($record && $record['schema'] === 2 &&
		$record['revisionId'] === $title->getLatestRevID() &&
		is_string($record['generation']),
		'Targeted semantic job recorded a durable generation without editing its owner page');
	$queuedGenerations[$id] = $record['generation'];
}
same(2, count($prewarmQueue->pending()), 'Only the two real SMW query-owning pages are enqueued');
same(3, $first->getMetrics()['queries_processed'], 'All three real query subobjects scanned');
same(2, count($htmlTargets), 'HTML/CDN hooks dispatched once per page');
same([true, true], $htmlOrigins, 'SMW dependent HTML dispatches are marked without suppressing invalidation');
check(!SemanticPurgeScope::isActive(), 'Semantic provenance does not leak beyond the purge job');
foreach ($owners as $title) {
	$key = $title->getPrefixedDBkey();
	$after = $fragmentStore->read($key, 'purge-test', ['test' => 1]);
	check($after['entry'] === null && $after['generation'] !== $oldGenerations[$key],
		'Real fragment removed and its publication generation fenced');
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	check($parserCache->get($page, $options) === false, 'Parser cache metadata actually evicted');
}
$overlap = $run([$depId, $targetId]);
same(0, $overlap->getMetrics()['invalidations'], 'Overlapping dependency sets reuse completed receipts');
same(0, $overlap->getMetrics()['dependent_pages_queued_for_prewarm'],
	'Coalesced redundant purge does not enqueue unnecessary duplicate prewarm work');
foreach ($owners as $title) {
	same($queuedGenerations[$title->getArticleID()],
		$prewarmQueue->current($title->getArticleID())['generation'],
		'Redundant job preserves the existing queued generation');
}
same(2, $overlap->getMetrics()['coalesced_pages'], 'Both actual page purges coalesced');
same(2, count($htmlTargets), 'Repeated work does not dispatch more CDN/HTML purges');
same($ledgerBefore, SemanticPurgeState::revisions($store, [$depId]), 'Purging does not mutate semantic revisions');
same(0, $articlePurges, 'No SMW ArticlePurge feedback');
check(!$services->getJobQueueGroup()->queuesHaveJobs(), 'Cache-only purge does not spawn semantic jobs');

SemanticRevisionStore::record($store, [$depId]);
$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
$newRevision = $run([$depId]);
same(2, $newRevision->getMetrics()['invalidations'], 'Successive semantic revision is never coalesced with old receipts');
same(2, $newRevision->getMetrics()['dependent_pages_queued_for_prewarm'],
	'New semantic revision requeues both dependent pages for targeted preparation');
foreach ($owners as $title) {
	$id = $title->getArticleID();
	$current = $prewarmQueue->current($id);
	check($current['generation'] !== $queuedGenerations[$id] &&
		!$prewarmQueue->acknowledge($id, $current['revisionId'], $queuedGenerations[$id]),
		'A previous worker cannot erase new semantic work with the same page revision');
	$queuedGenerations[$id] = $current['generation'];
}
SemanticRevisionStore::record($store, [$depId]);
$changedDuring = false;
$interleaving = $services->getHookContainer()->scopedRegister('TitleSquidURLs',
	static function() use (&$changedDuring, $store, $depId): void {
		if (!$changedDuring) {
			SemanticRevisionStore::record($store, [$depId]);
			$changedDuring = true;
		}
	});
$run([$depId]);
$interleaving = null;
check($changedDuring, 'Deterministic semantic mutation interleaved with HTML purge');
same(2, $run([$depId])->getMetrics()['invalidations'], 'Mutation during purge prevents a reusable receipt');

// Exercise genuine JobQueue continuations, including a reconstructed legacy job.
SemanticRevisionStore::record($store, [$depId]);
$GLOBALS['wgWikidebatesSemanticCachePurgeBatchSize'] = 1;
$initial = $run([$depId]);
same(1, $initial->getMetrics()['continuations'], 'Bounded first batch creates one continuation');
$totalInvalidations = $initial->getMetrics()['invalidations'];
$continuations = 0;
$lastCursor = 0;
$group = $services->getJobQueueGroup();
while ($queued = $group->pop('wikidebatesSemanticCachePurge')) {
	check(++$continuations <= 3, 'Pagination terminates');
	$params = $queued->getParams();
	check($params['cursor'] > $lastCursor, 'Legacy query cursor increases without reset');
	check(!isset($params['semanticRevision']), 'Legacy payload remains legacy');
	$lastCursor = $params['cursor'];
	$resumed = new SemanticCachePurgeJob($queued->getTitle(), $params);
	check($resumed->run(), 'Reconstructed continuation executes');
	$totalInvalidations += $resumed->getMetrics()['invalidations'];
	$group->ack($queued);
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
}
same(2, $continuations, 'Exactly three queries use three batches, no empty fourth job');
same(2, $totalInvalidations, 'A page spanning batches is invalidated once');

// A failed HTML hook must neither certify completion nor advance the cursor.
SemanticRevisionStore::record($store, [$depId]);
$throwing = $services->getHookContainer()->scopedRegister('TitleSquidURLs',
	static function(): void { throw new RuntimeException('Injected HTML invalidation failure'); });
$failed = new SemanticCachePurgeJob($owners[0], ['dependencyIds' => [$depId], 'cursor' => 0]);
$beforeFailure = $prewarmQueue->current($owners[0]->getArticleID())['generation'];
check(!$failed->run(), 'HTML exception remains a retryable job failure');
same(0, $failed->getMetrics()['dependent_pages_queued_for_prewarm'],
	'Failed HTML purge cannot falsely report successful queued prewarming');
same($beforeFailure,
	$prewarmQueue->current($owners[0]->getArticleID())['generation'],
	'Exception during CDN/HTML purge leaves existing pending work unchanged');
check(!SemanticPurgeScope::isActive(), 'A failed HTML purge always clears the semantic provenance marker');
same(0, $failed->getMetrics()['continuations'], 'Failed batch never queues a successor');
$throwing = null;
$GLOBALS['wgWikidebatesSemanticCachePurgeBatchSize'] = 1000;
$retry = $run([$depId]);
same(2, $retry->getMetrics()['invalidations'], 'Retry completes all invalidations after failure');
same(2, $retry->getMetrics()['dependent_pages_queued_for_prewarm'],
	'After retry, successfully purged semantic dependents receive fresh queue generations');

$versionA = SemanticPurgeState::revisions($store, [$depId]);
$params = ['dependencyIds' => [$depId], 'cursor' => 0,
	'semanticRevision' => SemanticPurgeState::revisionToken($versionA, [$depId])];
$a = new SemanticCachePurgeJob($owners[0], $params);
$b = new SemanticCachePurgeJob($owners[1], $params);
same($a->getDeduplicationInfo(), $b->getDeduplicationInfo(), 'Real core dedup ignores only new job source titles');
SemanticRevisionStore::record($store, [$depId]);
$params['semanticRevision'] = SemanticPurgeState::revisionToken(SemanticPurgeState::revisions($store, [$depId]), [$depId]);
$c = new SemanticCachePurgeJob($owners[0], $params);
check($a->getDeduplicationInfo() !== $c->getDeduplicationInfo(), 'Two same-second revisions have distinct real queue identities');
// Invalid queue configuration must fail *before* the purge, leaving
// semantic work retryable rather than silently dropping a prewarm request.
$beforeBrokenConfig = $prewarmQueue->current($owners[0]->getArticleID())['generation'];
$GLOBALS['wgWikidebatesCachePreparationDirectory'] = $queueRoot . '/missing-directory';
$brokenQueueJob = new SemanticCachePurgeJob($owners[0], ['dependencyIds' => [$depId], 'cursor' => 0]);
check(!$brokenQueueJob->run() && $brokenQueueJob->getMetrics()['status'] === 'failed' &&
	$brokenQueueJob->getMetrics()['invalidations'] === 0,
	'Misconfigured private journal fails the semantic job without performing invalidations');
$GLOBALS['wgWikidebatesCachePreparationDirectory'] = $queueRoot;
same($beforeBrokenConfig, $prewarmQueue->current($owners[0]->getArticleID())['generation'],
	'Rejected queue directory does not replace valid pending work');

// A normal explicit HTML purge must never inherit the semantic-only marker.
$observerCount = count($htmlOrigins);
$htmlCache = $services->getHtmlCacheUpdater();
$htmlCache->purgeTitleUrls([$owners[0]], $htmlCache::PURGE_NAIVE);
same(false, $htmlOrigins[$observerCount] ?? null,
	'Explicit ordinary HTML purge is not classified as a semantic dependent purge');
check(!SemanticPurgeScope::isActive(), 'Explicit HTML purge leaves provenance clean');
$articleObserver = $htmlObserver = null;
$GLOBALS['wgWikidebatesCachePreparationDirectory'] = false;
fsFlush();

// Real MediaWiki Title::invalidateCache defers the page_touched update.
// A semantic dependent changes its touched timestamp, not its MediaWiki
// revision: callers must not equate the two for cache freshness.
foreach ($owners as $title) {
	$id = $title->getArticleID();
	$afterTouch = $sqlPrimary->newSelectQueryBuilder()
		->select(['page_latest', 'page_touched'])->from('page')
		->where(['page_id' => $id])->caller(__FILE__)->fetchRow();
	check($afterTouch !== false &&
		(string)$afterTouch->page_latest === (string)$touchBefore[$id]->page_latest &&
		(string)$afterTouch->page_touched >= (string)$touchBefore[$id]->page_touched,
		'Semantic-only dependent invalidation retains page_latest while page_touched can advance');
}

// All persistent files belong to the disposable test, never a real wiki.
foreach ($prewarmQueue->pending() as $record) {
	check($prewarmQueue->acknowledge($record['pageId'], $record['revisionId'],
		$record['generation']), 'Disposable semantic queue drains exact request generations');
}
$cleanup = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($queueRoot, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanup as $path) {
	$path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
}
rmdir($queueRoot);

echo json_encode(['backend' => $backend, 'first' => $first->getMetrics(),
	'overlap' => $overlap->getMetrics(), 'continuations' => $continuations], JSON_PRETTY_PRINT) . "\n";
echo "OK: real SQL, durable receipts, filesystem generations, ParserCache eviction, overlap, legacy resume and no feedback\n";
