<?php

/** Real saved pages and database-backed ParserCache in a disposable wiki. */
define('WD_TEST_SEO_PUBLICATION_CACHE', true);
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\Extension\Wikidebates\Seo\PublicationDate;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$services = MediaWikiServices::getInstance();
$user = User::newSystemUser('Wikidebates publication fixture', ['steal' => false]);
check($user !== null, 'Test user');
$cache = $services->getParserCache();
$parseEvents = 0;

// This hook is triggered only by actual parse work, not deserialization.
$services->getHookContainer()->register('ParserFirstCallInit',
	static function() use (&$parseEvents): void { $parseEvents++; });

function publicationGraph($output): array {
	preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is',
		$output->getText(), $matches);
	same(1, count($matches[1]), 'One native JSON-LD document');
	return json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR)['@graph'];
}

function publicationCheck($output, string $kind, string $published, string $modified): void {
	$payload = $output->getExtensionData(Metadata::DATA_KEY);
	same($published, $payload['wd_published_time'] ?? null, 'Native SEO payload date');
	same(['timestamp' => $published], $output->getExtensionData(PublicationDate::DATA_KEY),
		'Shared parser date');
	$pageNodes = array_values(array_filter(publicationGraph($output),
		static fn($node) => in_array('WebPage', (array)($node['@type'] ?? []), true)));
	same(1, count($pageNodes), 'One specialized page');
	same($published, $pageNodes[0]['datePublished'] ?? null, 'Same JSON-LD publication date');
	same($modified, $pageNodes[0]['dateModified'] ?? null, 'Exact revision date');
	if ($kind === 'argument') {
		$values = Metadata::build($payload, 'https://example.test/wiki/Test', $modified);
		same($published, $values['open_graph']['article:published_time'] ?? null, 'Same Open Graph date');
		foreach (publicationGraph($output) as $node) {
			if (($node['@type'] ?? '') === 'Claim') {
				check(!isset($node['datePublished']), 'No spurious claim date');
			}
		}
	}
}

foreach (['debate', 'argument'] as $kind) {
	$title = Title::newFromText('SEO native publication ' . $kind);
	$revisions = [];
	foreach (['20240512143725', '20250604102539'] as $i => $timestamp) {
		try {
			\MWTimestamp::setFakeTime($timestamp);
			$page = $services->getWikiPageFactory()->newFromTitle($title);
			$updater = $page->newPageUpdater($user);
			$updater->setContent(SlotRecord::MAIN, new WikitextContent(
				'{{#' . $kind . ':render|lang=en|creation-date=2000-01-01|summary=Fixture ' . $i . '}}'
			));
			$updater->saveRevision(CommentStoreComment::newUnsavedComment('Native publication date'));
			check($updater->wasSuccessful(), 'Fixture saved');
			$revision = $updater->getNewRevision();
			$revisions[] = $revision;
			$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
			// Exercise the normal display path immediately after save.
			$page = $services->getWikiPageFactory()->newFromTitle($title);
			$options = $page->makeParserOptions('canonical');
			$output = $page->getParserOutput($options);
			check($output !== false, 'Saved page has parser output');
			publicationCheck($output, $kind, '2024-05-12T14:37:25Z', wfTimestamp(TS_ISO_8601, $timestamp));
			// Read the real persistent ParserCache rather than only the access service's local LRU.
			$before = $parseEvents;
			$cached = $cache->get($page, $options);
			check($cached !== false, 'Saved page has persistent ParserCache output');
			publicationCheck($cached, $kind, '2024-05-12T14:37:25Z', wfTimestamp(TS_ISO_8601, $timestamp));
			same($before, $parseEvents, 'Persistent ParserCache read does not start a parse');
		} finally {
			\MWTimestamp::setFakeTime(false);
		}
	}
	$old = $services->getRevisionRenderer()->getRenderedRevision(
		$revisions[0], ParserOptions::newFromAnon()
	)->getRevisionParserOutput();
	publicationCheck($old, $kind, '2024-05-12T14:37:25Z', '2024-05-12T14:37:25Z');
}

echo "OK: first save, edit, real ParserCache hit, old revision and consistent native dates\n";


// History operations use the real MediaWiki services and a persistent cache.
// Flush deferred invalidations and clear request-local state between views.
function publicationLifecycleFlush(): void {
	$services = MediaWikiServices::getInstance();
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__FUNCTION__);
	\DeferredUpdates::doUpdates();
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__FUNCTION__);
	Title::clearCaches();
	$access = $services->getParserOutputAccess();
	(new \ReflectionObject($access))->getProperty('localCache')->setValue($access, new \MapCacheLRU(10));
}

function publicationLifecycleSave(string $name, string $timestamp, string $summary, User $user): void {
	$services = MediaWikiServices::getInstance();
	try {
		\MWTimestamp::setFakeTime($timestamp);
		$page = $services->getWikiPageFactory()->newFromTitle(Title::newFromText($name));
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, new WikitextContent(
			'{{#argument:render|lang=en|creation-date=2000-01-01|summary=' . $summary . '}}'
		));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('History fixture'));
		check($updater->wasSuccessful(), 'History fixture saved: ' . $name);
		// Keep create/edit deferred timestamps consistent with the fixture.
		publicationLifecycleFlush();
	} finally {
		\MWTimestamp::setFakeTime(false);
	}
}

function publicationLifecyclePage(string $name) {
	return MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle(Title::newFromText($name));
}

function publicationLifecycleView(string $name, string $published): array {
	$services = MediaWikiServices::getInstance();
	$page = publicationLifecyclePage($name);
	$revision = $page->getRevisionRecord();
	check($revision !== null, 'Lifecycle page exists: ' . $name);
	$options = $page->makeParserOptions('canonical');
	$output = $page->getParserOutput($options);
	check($output !== false, 'Lifecycle output: ' . $name);
	$modified = wfTimestamp(TS_ISO_8601, $revision->getTimestamp());
	publicationCheck($output, 'argument', $published, $modified);
	$nodes = array_values(array_filter(publicationGraph($output),
		static fn($node) => in_array('WebPage', (array)($node['@type'] ?? []), true)));
	same($page->getTitle()->getFullURL(), $nodes[0]['url'] ?? null, 'JSON-LD follows the page URL');
	$cached = $services->getParserCache()->get($page, $options);
	check($cached !== false, 'Persistent cache populated after lifecycle operation');
	publicationCheck($cached, 'argument', $published, $modified);
	return ['page' => $page->getId(), 'revision' => $revision->getId()];
}

$authority = new \MediaWiki\Permissions\UltimateAuthority($user);
$name = 'SEO lifecycle original';
$renamed = 'SEO lifecycle renamed';
publicationLifecycleSave($name, '20200102131415', 'Original', $user);
publicationLifecycleSave($name, '20210102131415', 'Edited', $user);
$original = publicationLifecycleView($name, '2020-01-02T13:14:15Z');
// page_touched and cache time have only second resolution.
sleep(1);
$status = $services->getMovePageFactory()->newMovePage(
	Title::newFromText($name), Title::newFromText($renamed)
)->move($user, 'SEO lifecycle move', false);
check($status->isOK(), 'Real move succeeds');
publicationLifecycleFlush();
$moved = publicationLifecycleView($renamed, '2020-01-02T13:14:15Z');
same($original['page'], $moved['page'], 'Renaming retains page identity and publication date');
echo "OK: real rename keeps native publication date and updates the URL\n";

sleep(1);
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
	. '<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.10/" version="0.10" xml:lang="en">'
	. '<siteinfo><sitename>SEO fixtures</sitename><base>https://example.test/wiki/Main_Page</base>'
	. '<generator>MediaWiki 1.43</generator><case>first-letter</case>'
	. '<namespaces><namespace key="0" case="first-letter" /></namespaces></siteinfo>'
	. '<page><title>' . htmlspecialchars($renamed, ENT_XML1) . '</title><ns>0</ns><id>999</id>'
	. '<revision><id>999999</id><timestamp>2017-01-02T03:04:05Z</timestamp>'
	. '<contributor><username>SEO imported fixture</username><id>999</id></contributor>'
	. '<comment>Earlier history</comment><model>wikitext</model><format>text/x-wiki</format>'
	. '<text xml:space="preserve">An earlier imported revision</text></revision></page></mediawiki>';
$stream = fopen('php://temp', 'w+');
fwrite($stream, $xml);
rewind($stream);
$importer = $services->getWikiImporterFactory()->getWikiImporter(new \ImportStreamSource($stream), $authority);
check($importer->doImport(), 'Actual XML import succeeds');
publicationLifecycleFlush();
$imported = publicationLifecycleView($renamed, '2017-01-02T03:04:05Z');
same($moved, $imported, 'Older import changes publication date without changing latest revision');
echo "OK: actual XML import of an older revision invalidates stale current ParserCache\n";

$source = 'SEO lifecycle merge source';
publicationLifecycleSave($source, '20120102131415', 'Earlier source', $user);
publicationLifecycleSave($source, '20220102131415', 'Remaining source', $user);
$sourceBefore = publicationLifecycleView($source, '2012-01-02T13:14:15Z');
sleep(1);
$status = $services->getMergeHistoryFactory()->newMergeHistory(
	publicationLifecyclePage($source), publicationLifecyclePage($renamed), '20120102131415'
)->merge($authority, 'SEO lifecycle merge');
check($status->isOK(), 'Real partial history merge succeeds');
publicationLifecycleFlush();
$merged = publicationLifecycleView($renamed, '2012-01-02T13:14:15Z');
$sourceAfter = publicationLifecycleView($source, '2022-01-02T13:14:15Z');
same($imported, $merged, 'History merge leaves current destination revision unchanged');
same($sourceBefore, $sourceAfter, 'Partial merge leaves current source revision unchanged');
echo "OK: real partial history merge refreshes publication dates on both pages\n";

$restore = 'SEO lifecycle restore';
publicationLifecycleSave($restore, '20180102131415', 'Original archived revision', $user);
publicationLifecycleSave($restore, '20190102131415', 'Latest archived revision', $user);
publicationLifecycleView($restore, '2018-01-02T13:14:15Z');
sleep(1);
$status = $services->getDeletePageFactory()->newDeletePage(
	publicationLifecyclePage($restore), $authority
)->forceImmediate(true)->deleteUnsafe('SEO lifecycle delete');
check($status->isOK(), 'Real deletion succeeds');
publicationLifecycleFlush();
check(!publicationLifecyclePage($restore)->exists(), 'Page no longer exists');
$status = $services->getUndeletePageFactory()->newUndeletePage(
	Title::newFromText($restore)->toPageIdentity(), $authority
)->setUndeleteOnlyTimestamps(['20190102131415'])->undeleteUnsafe('Restore latest only');
check($status->isOK(), 'Real partial restoration succeeds');
publicationLifecycleFlush();
$latestOnly = publicationLifecycleView($restore, '2019-01-02T13:14:15Z');
sleep(1);
$status = $services->getUndeletePageFactory()->newUndeletePage(
	Title::newFromText($restore)->toPageIdentity(), $authority
)->setUndeleteOnlyTimestamps(['20180102131415'])->undeleteUnsafe('Restore earlier history');
check($status->isOK(), 'Restore earlier history into existing page');
publicationLifecycleFlush();
$restored = publicationLifecycleView($restore, '2018-01-02T13:14:15Z');
same($latestOnly, $restored, 'Restoring old revision changes date without changing current revision');
echo "OK: real delete, partial restore and earlier-history restore refresh current publication dates\n";


// The obsolete field cannot influence rendering or the orphan grace period.
foreach (['fr', 'en'] as $language) {
	$title = Title::newFromText('SEO orphan publication day ' . $language);
	$initialization = $language === 'fr' ? 'Argument pour' : 'Pro argument';
	$category = $language === 'fr' ? "Arguments sans paramètre d'initialisation" : 'Arguments without an initialization parameter';
	$missingCategory = $language === 'fr' ? 'Articles sans date de création' : 'Articles without creation date';
	$source = '{{#argument:render|lang=' . $language . '|initialization=' . $initialization .
		'@42|creation-date=1990-01-01|summary=Native date initialization}}';
	try {
		\MWTimestamp::setFakeTime('20260510143725');
		$page = $services->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, new WikitextContent($source));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Orphan initialization fixture'));
		check($updater->wasSuccessful(), 'Stored argument fixture');
		$revision = $updater->getNewRevision();
		publicationLifecycleFlush();
		$render = static fn() => $services->getRevisionRenderer()->getRenderedRevision(
			$revision, ParserOptions::newFromAnon()
		)->getRevisionParserOutput();
		$dayOne = $render();
		$categories = $dayOne->getCategoryNames();
		check(!in_array(str_replace(' ', '_', $category), $categories, true),
			'Creation day grace period ignores false editorial date');
		check(!in_array(str_replace(' ', '_', $missingCategory), $categories, true),
			'No obsolete missing-date category');
		\MWTimestamp::setFakeTime('20260511000100');
		$dayTwo = $render();
		check(in_array(str_replace(' ', '_', $category), $dayTwo->getCategoryNames(), true),
			'Uninitialized category resumes after the native creation day');
	} finally {
		\MWTimestamp::setFakeTime(false);
	}
}
echo "OK: native creation-day grace period and retired date category in both languages\n";
