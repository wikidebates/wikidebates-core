<?php

/** Reuse the existing adapter doubles and run their complete regression suite first. */
require __DIR__ . '/semanticPurge.php';

use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use MediaWiki\MediaWikiServices;

class CountingPurgeFragments extends Fragments {
	public int $reads = 0;

	public function read($title, $variant, $identity) {
		$this->reads++;
		return parent::read($title, $variant, $identity);
	}
}

function countingPurgeSetup(): array {
	[$db, $services, $old] = setup();
	$fragments = new CountingPurgeFragments;
	$fragments->entries = $old->entries;
	FragmentService::$store = $fragments;
	return [$db, $services, $fragments];
}

[$db, $services, $fragments] = countingPurgeSetup();
$first = job();
check($first->run(), 'Cold purge succeeds');
check($fragments->reads === 2 && $first->getMetrics()['generation_reads'] === 2,
	'Cold two-page purge reads generations only after completion (previously four reads)');
check(!$fragments->entries && !$services->parser && !$services->html && !$services->minerva,
	'Skipping useless reads does not skip any invalidation');
$overlap = job([100, 200]);
check($overlap->run() && $overlap->getMetrics()['coalesced_pages'] === 2,
	'Exact receipt matches still coalesce');
check($fragments->reads === 4 && $overlap->getMetrics()['generation_reads'] === 2,
	'Every reused receipt still reads its current local generation');
$db->revisions[100] = '2';
$newRevision = job();
check($newRevision->run() && $newRevision->getMetrics()['invalidations'] === 2,
	'Successive semantic revisions still invalidate both pages');
check($fragments->reads === 6 && $newRevision->getMetrics()['generation_reads'] === 2,
	'Obsolete receipts require no generation read before invalidating');
$fragments->invalidate('Page_2');
$localChange = job();
check($localChange->run() && $localChange->getMetrics()['invalidations'] === 1,
	'A local generation change cannot be coalesced away');
check($localChange->getMetrics()['generation_reads'] === 3,
	'Both candidate generations plus the single completed receipt are read');

// Preserve all required query IDs, not only the first query for each Title.
$db->queries[2]['profile'] = false;
for ($i = 0; $i < 2; $i++) {
	$orphan = job();
	check($orphan->run() && $orphan->getMetrics()['invalidations'] === 1,
		'A non-first query with missing ownership never obtains a reusable receipt');
}

[$db, $services, $fragments] = countingPurgeSetup();
$db->schema = false;
$legacy = job();
check($legacy->run() && $legacy->getMetrics()['invalidations'] === 2,
	'Pre-migration fallback still invalidates both pages');
check($fragments->reads === 0 && $legacy->getMetrics()['generation_reads'] === 0,
	'No receipt table means no generation lookup, not even for initialization');

[$db, $services, $fragments] = countingPurgeSetup();
$services->settings['WikidebatesSemanticCacheEagerPurge'] = false;
$disabled = job();
check($disabled->run() && $disabled->getMetrics()['status'] === 'disabled',
	'Historical disabled-job behavior remains explicit');
check($db->reads === 0 && $fragments->reads === 0 && $fragments->invalidations === 0 && !$services->queue,
	'Disabled jobs do not access SQL/fragments or enqueue continuations');

// Failure after invalidations but before continuation acceptance must be retryable.
[$db, $oldServices] = setup();
$services = new class extends Services {
	public bool $failPush = true;

	public function push($job): void {
		if ($this->failPush) {
			throw new RuntimeException('Injected continuation enqueue failure');
		}
		parent::push($job);
	}
};
$services->parser = $oldServices->parser;
$services->html = $oldServices->html;
$services->minerva = $oldServices->minerva;
$services->settings['WikidebatesSemanticCachePurgeBatchSize'] = 2;
MediaWikiServices::$instance = $services;
$retry = job();
check(!$retry->run() && $retry->error === RuntimeException::class,
	'Queue failure does not acknowledge the original job');
check($retry->getParams()['cursor'] === 0 && $retry->getMetrics()['next_cursor'] === null &&
	$retry->getMetrics()['continuations'] === 0 && !$services->queue,
	'Unaccepted continuation is never reported as queued and original cursor is unchanged');
$services->failPush = false;
check($retry->run(), 'The same job can be retried at its original cursor');
check($retry->getMetrics()['next_cursor'] === 2 && $retry->getMetrics()['invalidations'] === 0,
	'Retry reuses completed page work, then actually queues the continuation');
$remaining = drain($services);
check($remaining['jobs'] === 2 && array_sum(Lab::$touches) === 2 && !$services->parser && !$services->html && !$services->minerva,
	'All pages are invalidated with no lost continuation or repeated completed page purge');
$last = job([100], 6);
check($last->run() && $last->getMetrics()['next_cursor'] === null && !$services->queue,
	'Terminal empty batch has no successor');

echo "OK: lazy generation reads, pre-migration fallback, full query ownership and enqueue-failure recovery\n";
