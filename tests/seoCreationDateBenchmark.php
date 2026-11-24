<?php

/**
 * Isolated lookup microbenchmark, not a production migration.
 * Use a real first revision, then synthetic later revision rows solely
 * to exercise the history index. Never render the synthetic revisions.
 */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$services = MediaWikiServices::getInstance();
$provider = $services->getConnectionProvider();
$dbw = $provider->getPrimaryDatabase();
$dbr = $provider->getReplicaDatabase();
$lookup = $services->getRevisionLookup();
$user = User::newSystemUser('Wikidebates SEO date fixture', ['steal' => false]);
check($user !== null, 'Fixture user is available');

// This observer can only reach the private, disposable MariaDB instance
// already checked and created by mediawikiEnvironment.php.
$observer = $testSocket !== null ? new mysqli('localhost', 'root', '', '', 0, $testSocket) : null;
function seoDateSelectCount(?mysqli $observer): ?int {
	if ($observer === null) {
		return null;
	}
	$row = $observer->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch_row();
	return (int)$row[1];
}

function seoDateStats(array $values): array {
	sort($values, SORT_NUMERIC);
	$n = count($values);
	return [
		'median' => round(($values[(int)floor(($n - 1) / 2)] + $values[(int)floor($n / 2)]) / 2, 3),
		'p95' => round($values[(int)ceil($n * 0.95) - 1], 3)
	];
}

function seoDateQuery($db, int $pageId) {
	return $db->newSelectQueryBuilder()
		->select('rev_timestamp')
		->from('revision')
		->where(['rev_page' => $pageId])
		->orderBy('rev_timestamp')
		->limit(1)
		->caller(__FUNCTION__);
}

$fixtures = [];
foreach ([1, 20, 2000] as $historySize) {
	$title = Title::newFromText('SEO creation date benchmark ' . $historySize);
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	$firstTimestamp = '20151201143725';
	try {
		\MWTimestamp::setFakeTime($firstTimestamp);
		$updater = $page->newPageUpdater($user);
		$updater->setContent(SlotRecord::MAIN, new WikitextContent('Creation date fixture'));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Real first revision'));
		check($updater->wasSuccessful(), 'Fixture saved');
		$first = $updater->getNewRevision();
	} finally {
		\MWTimestamp::setFakeTime(false);
	}
	same($firstTimestamp, $first->getTimestamp(), 'Real first revision timestamp');
	$pageId = $first->getPageId();
	$row = $dbw->newSelectQueryBuilder()
		->select('*')->from('revision')->where(['rev_id' => $first->getId()])
		->caller(__METHOD__)->fetchRow();
	check($row !== false, 'Stored revision row exists');
	$template = (array)$row;
	unset($template['rev_id']);
	$template['rev_parent_id'] = $first->getId();
	$rows = [];
	for ($i = 1; $i < $historySize; $i++) {
		$rows[] = array_replace($template, ['rev_timestamp' => gmdate('YmdHis', 1609459200 + $i)]);
		if (count($rows) === 100 || $i === $historySize - 1) {
			$dbw->newInsertQueryBuilder()->insertInto('revision')->rows($rows)->caller(__METHOD__)->execute();
			$rows = [];
		}
	}
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
	$identity = $services->getPageStore()->getPageById($pageId);
	check($identity !== null, 'Page identity available outside measurement');
	$count = $dbr->newSelectQueryBuilder()->select('COUNT(*)')->from('revision')
		->where(['rev_page' => $pageId])->caller(__METHOD__)->fetchField();
	same($historySize, (int)$count, 'Expected history size');
	$fixtures[] = [$historySize, $identity, $firstTimestamp];
}

$iterations = 200;
foreach ($fixtures as [$historySize, $identity, $firstTimestamp]) {
	$pageId = $identity->getId();
	$expected = wfTimestamp(TS_ISO_8601, $firstTimestamp);
	$payload = ['wd_published_time' => $expected];
	// Use equivalent date conversion for the three source mechanisms.
	$methods = [
		'editorial' => static fn() => '2015-12-01' . 'T00:00:00Z',
		'first-revision-api' => static fn() => wfTimestamp(TS_ISO_8601, $lookup->getFirstRevision($identity)?->getTimestamp()),
		'timestamp-query' => static fn() => wfTimestamp(TS_ISO_8601, seoDateQuery($dbr, $pageId)->fetchField()),
		'cached-value' => static fn() => $payload['wd_published_time'],
	];
	$expectedByMethod = [
		'editorial' => '2015-12-01T00:00:00Z',
		'first-revision-api' => $expected,
		'timestamp-query' => $expected,
		'cached-value' => $expected,
	];
	foreach ($methods as $name => $operation) {
		same($expectedByMethod[$name], $operation(), 'Correct timestamp: ' . $name);
		for ($i = 0; $i < 15; $i++) {
			$operation();
		}
	}

	$times = $peaks = [];
	for ($i = 0; $i < $iterations; $i++) {
		foreach ($i % 2 === 0 ? $methods : array_reverse($methods, true) as $name => $operation) {
			memory_reset_peak_usage();
			$memoryBefore = memory_get_usage();
			$start = hrtime(true);
			$value = $operation();
			$elapsed = (hrtime(true) - $start) / 1000;
			$peak = max(0, memory_get_peak_usage() - $memoryBefore);
			same($expectedByMethod[$name], $value, 'Stable timestamp: ' . $name);
			$times[$name][] = $elapsed;
			$peaks[$name][] = $peak;
		}
	}
	$report = [];
	foreach ($methods as $name => $operation) {
		$selectBefore = seoDateSelectCount($observer);
		for ($i = 0; $i < $iterations; $i++) {
			$operation();
		}
		$selectAfter = seoDateSelectCount($observer);
		$selects = $selectBefore === null ? null : ($selectAfter - $selectBefore) / $iterations;
		if ($selects !== null && in_array($name, ['editorial', 'cached-value'], true)) {
			same(0.0, (float)$selects, 'No SQL for in-memory value');
		}
		if ($selects !== null && $name === 'timestamp-query') {
			same(1.0, (float)$selects, 'Exactly one SQL SELECT for timestamp lookup');
		}
		$report[$name] = [
			'time_us' => seoDateStats($times[$name]),
			'peak_delta_bytes' => seoDateStats($peaks[$name]),
			'selects_per_call' => $selects,
		];
	}
	$plan = [];
	if ($observer !== null) {
		foreach (seoDateQuery($dbr, $pageId)->explain()->fetchResultSet() as $row) {
			$plan[] = (array)$row;
		}
	}
	echo 'WD_SEO_CREATION_DATE ' . json_encode([
		'db' => $testSocket === null ? 'sqlite' : 'mariadb',
		'php' => PHP_VERSION,
		'mediawiki' => MW_VERSION,
		'history_rows' => $historySize,
		'iterations' => $iterations,
		'expected' => $expected,
		'methods' => $report,
		'query_plan' => $plan
	], JSON_THROW_ON_ERROR) . PHP_EOL;
}
same(false, seoDateQuery($dbr, 0)->fetchField(), 'Missing page has no creation date');
echo "OK: first revision lookup matches indexed timestamp, no invented creation date\n";
