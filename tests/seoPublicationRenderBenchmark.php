<?php

/**
 * Same fixture and instrumentation on the baseline and candidate, each in
 * its own disposable database. Measure fresh revision rendering, not HTTP.
 */
$root = getenv('WD_SEO_BENCHMARK_ROOT') ?: dirname(__DIR__);
require $root . '/tests/mediawikiEnvironment.php';

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$mode = getenv('WD_SEO_BENCHMARK_MODE') ?: 'candidate';
check(in_array($mode, ['baseline', 'candidate'], true), 'Expected benchmark mode');
$services = MediaWikiServices::getInstance();
$user = User::newSystemUser('Publication render benchmark', ['steal' => false]);
check($user !== null, 'Fixture user available');
$observer = $testSocket !== null ? new mysqli('localhost', 'root', '', '', 0, $testSocket) : null;

function publicationRenderSelects(?mysqli $observer): ?int {
	return $observer === null ? null :
		(int)$observer->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch_row()[1];
}

function publicationRenderStats(array $values): array {
	sort($values, SORT_NUMERIC);
	$n = count($values);
	return [
		'median' => round(($values[(int)floor(($n - 1) / 2)] + $values[(int)floor($n / 2)]) / 2, 3),
		'p95' => round($values[(int)ceil($n * 0.95) - 1], 3)
	];
}

$list = implode('', array_map(static fn(int $n): string =>
	'Fixture argument ' . $n . '⟬Argument ' . $n . '⟬⟭', range(1, 10)));
foreach (['debate', 'argument'] as $kind) {
	$title = Title::newFromText('Native date full render ' . $kind);
	$fields = $kind === 'debate'
		? '|topic=Example|pro-arguments=' . $list . '|con-arguments=' . $list
		: '|summary=An example argument with sources.|justifications=' . $list . '|objections=' . $list;
	try {
		\MWTimestamp::setFakeTime('20240512143725');
		$page = $services->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, new WikitextContent(
			'{{#' . $kind . ':render|lang=en|creation-date=2000-01-01' . $fields . '}}'
		));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Benchmark stored page'));
		check($updater->wasSuccessful(), 'Fixture saved');
		$revision = $updater->getNewRevision();
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
	} finally {
		\MWTimestamp::setFakeTime(false);
	}
	$render = static function() use ($services, $revision) {
		return $services->getRevisionRenderer()->getRenderedRevision(
			$revision, ParserOptions::newFromAnon()
		)->getRevisionParserOutput();
	};
	$verify = static function($output) use ($mode, $kind): void {
		$expected = $mode === 'candidate' ? '2024-05-12T14:37:25Z' : '2000-01-01T00:00:00Z';
		$payload = $output->getExtensionData(Metadata::DATA_KEY);
		same($kind, $payload['wd_kind'] ?? null, 'Rendered native SEO page');
		if ($mode === 'candidate') {
			same($expected, $payload['wd_published_time'] ?? null, 'Native date in payload');
		}
		preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is',
			$output->getText(), $matches);
		same(1, count($matches[1]), 'Exactly one schema document');
		$graph = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR)['@graph'];
		$pages = array_values(array_filter($graph, static fn($n) =>
			($n['@type'] ?? '') === ($kind === 'argument' ? 'WebPage' : 'QAPage')));
		same(1, count($pages), 'Specialized page node');
		same($expected, $pages[0]['datePublished'] ?? null, 'Correct date for benchmark mode');
	};
	for ($i = 0; $i < 12; $i++) {
		$verify($render());
	}
	$iterations = 60;
	$time = $memory = [];
	$selectBefore = publicationRenderSelects($observer);
	for ($i = 0; $i < $iterations; $i++) {
		memory_reset_peak_usage();
		$memoryBefore = memory_get_usage();
		$start = hrtime(true);
		$output = $render();
		$time[] = (hrtime(true) - $start) / 1000;
		$memory[] = max(0, memory_get_peak_usage() - $memoryBefore);
		unset($output);
	}
	$selectAfter = publicationRenderSelects($observer);
	$verify($render());
	echo 'WD_SEO_PUBLICATION_RENDER ' . json_encode([
		'mode' => $mode,
		'kind' => $kind,
		'php' => PHP_VERSION,
		'mediawiki' => MW_VERSION,
		'db' => $testSocket === null ? 'sqlite' : 'mariadb',
		'iterations' => $iterations,
		'time_us' => publicationRenderStats($time),
		'peak_delta_bytes' => publicationRenderStats($memory),
		'selects_per_render' => $selectBefore === null ? null : ($selectAfter - $selectBefore) / $iterations
	], JSON_THROW_ON_ERROR) . PHP_EOL;
}
echo "OK: isolated fresh-revision render benchmark\n";
