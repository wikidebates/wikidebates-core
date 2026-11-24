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
		static fn($node) => ($node['@type'] ?? '') === ($kind === 'argument' ? 'WebPage' : 'QAPage')));
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
