<?php

/**
 * A/B cost of a public PRIMARY visibility guard on a disposable MediaWiki.
 *
 * Returned callable assumes MediaWiki has already been bootstrapped. No
 * production database, HTTP target or timing threshold is involved.
 *
 * @return callable(string):array
 */
return static function (string $pageName): array {
	$root = getenv('WD_MINERVA_STUDY_ROOT');
	$readingLab = is_string($root) && $root !== '' &&
		is_file($root . '/.wikidebates-minerva-lab') &&
		is_file($root . '/state/minerva_file_cache_test.sqlite');
	if (!$readingLab && (!defined('WD_FULLSTACK_TEST') || WD_FULLSTACK_TEST !== true)) {
		throw new RuntimeException('Public guard benchmark requires a disposable MediaWiki lab');
	}
	$services = \MediaWiki\MediaWikiServices::getInstance();
	$db = $services->getConnectionProvider()->getPrimaryDatabase();
	$title = \MediaWiki\Title\Title::newFromText($pageName);
	if (!$title || !$title->canExist()) {
		throw new RuntimeException('Invalid disposable benchmark title');
	}
	$pageFields = [
		'page_id', 'page_namespace', 'page_title', 'page_latest',
		'page_touched', 'page_is_redirect'
	];
	$current = $db->selectRow('page', $pageFields,
		['page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey()],
		__METHOD__);
	if (!$current || (int)$current->page_latest < 1) {
		throw new RuntimeException('Benchmark fixture has no latest revision');
	}
	$revisionId = (int)$current->page_latest;
	$touched = (string)$current->page_touched;

	// Exact pre-optimization visibility logic: two distinct PRIMARY selects.
	$legacy = static function () use ($title, $pageFields, $revisionId, $touched): bool {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$page = $db->selectRow('page', $pageFields, [
			'page_namespace' => $title->getNamespace(),
			'page_title' => $title->getDBkey()
		], __METHOD__);
		if (!$page || (int)$page->page_id < 1 || (int)$page->page_latest < 1) {
			return false;
		}
		$id = (int)$page->page_id;
		$revision = $db->selectRow('revision', ['rev_deleted'], [
			'rev_id' => (int)$page->page_latest, 'rev_page' => $id
		], __METHOD__);
		if (!$revision || (int)$revision->rev_deleted !== 0) {
			return false;
		}
		$fromRow = \MediaWiki\Title\Title::newFromRow($page);
		if (!$fromRow || !$fromRow->canExist() || $fromRow->getArticleID() !== $id ||
			!$services->getPermissionManager()->userCan(
				'read', $services->getUserFactory()->newAnonymous(), $fromRow
			)) {
			return false;
		}
		$fingerprint = hash('sha256', json_encode([
			$id, (int)$page->page_latest, (int)$page->page_namespace,
			(string)$page->page_title, (string)$page->page_touched,
			(int)$page->page_is_redirect
		], JSON_THROW_ON_ERROR));
		return $fingerprint !== '' &&
			(int)$page->page_latest === $revisionId &&
			(string)$page->page_touched === $touched;
	};
	$joined = static fn(): bool =>
		\MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety::matchesView(
			$title, $revisionId, $touched
		);
	if (!$legacy() || !$joined()) {
		throw new RuntimeException('Neither variant may benchmark a non-public page');
	}
	foreach ([$legacy, $joined] as $call) {
		for ($i = 0; $i < 20; $i++) {
			if (!$call()) { throw new RuntimeException('Public visibility changed during warmup'); }
		}
	}
	$rounds = 36;
	$perRound = 30;
	$timings = ['two_selects' => [], 'joined_select' => []];
	for ($round = 0; $round < $rounds; $round++) {
		// Alternate order to avoid favoring one variant after connection warmup.
		$order = $round % 2 === 0
			? ['two_selects' => $legacy, 'joined_select' => $joined]
			: ['joined_select' => $joined, 'two_selects' => $legacy];
		foreach ($order as $variant => $call) {
			$start = hrtime(true);
			for ($i = 0; $i < $perRound; $i++) {
				if (!$call()) { throw new RuntimeException('Public visibility changed during benchmark'); }
			}
			$timings[$variant][] = (hrtime(true) - $start) / (1000 * $perRound);
		}
	}
	$summarize = static function (array $values): array {
		sort($values, SORT_NUMERIC);
		$n = count($values);
		return [
			'median_us' => round(($values[$n / 2 - 1] + $values[$n / 2]) / 2, 2),
			'p95_us' => round($values[(int)ceil(.95 * $n) - 1], 2)
		];
	};
	$old = $summarize($timings['two_selects']);
	$new = $summarize($timings['joined_select']);
	return [
		'type' => 'anonymous_primary_visibility_ab',
		'database' => $db->getType(),
		'rounds' => $rounds,
		'calls_per_round' => $perRound,
		'baseline' => $old,
		'joined' => $new,
		'median_change_percent' => $old['median_us'] > 0
			? round(100 * ($new['median_us'] / $old['median_us'] - 1), 1) : null
	];
};
