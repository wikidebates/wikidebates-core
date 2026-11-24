<?php

require dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationCoordinator.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationResults.php';
require dirname(__DIR__, 2) . '/src/Reading/VectorCacheEligibility.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationCommands.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationResults;
use MediaWiki\Extension\Wikidebates\Reading\VectorCacheEligibility;

if (!defined('SMW_NS_PROPERTY')) { define('SMW_NS_PROPERTY', 102); }
if (!defined('SMW_NS_CONCEPT')) { define('SMW_NS_CONCEPT', 108); }

$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
};
$root = sys_get_temp_dir() . '/wd-coordinator-unit-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create queue fixture'); }

try {
	$check(VectorCacheEligibility::hasLegacyShortDescription(
		'<html><head><script>RLCONF={"wgShortDesc":"Public description"};</script></head><body></body></html>'
	), 'Old ShortDescription JavaScript configuration is detected');
	$check(!VectorCacheEligibility::hasLegacyShortDescription(
		'<html><head></head><body><span class="ext-shortdesc shortdescription">Native</span></body></html>'
	), 'Native ShortDescription needs no repair');
	$check(!VectorCacheEligibility::hasLegacyShortDescription(
		'<html><head></head><body><pre>"wgShortDesc":</pre></body></html>'
	), 'Documentation in the article body is not legacy configuration');
	$check(VectorCacheEligibility::hasSmwSpecialArticle((int)constant('SMW_NS_PROPERTY')) &&
		VectorCacheEligibility::hasSmwSpecialArticle((int)constant('SMW_NS_CONCEPT')),
		'Exact SMW Property and Concept namespace constants are dynamically recognized');
	foreach ([0, 1, 4, 6, 14] as $ordinaryNamespace) {
		$check(!VectorCacheEligibility::hasSmwSpecialArticle($ordinaryNamespace),
			'Normal article, talk, project, file and category namespaces are not excluded');
	}

	$queue = new CachePreparationQueue($root, 'fr');
	$coordinator = new CachePreparationCoordinator($queue);
	$lock = $queue->tryLockWorker();
	$check(is_resource($lock), 'First worker owns its per-wiki lock');
	$check($queue->tryLockWorker() === false, 'Second worker cannot overlap');
	fclose($lock);
	$lock = $queue->tryLockWorker();
	$check(is_resource($lock), 'Stopped worker releases the durable lock');
	fclose($lock);

	$queue->enqueue(11, 100);
	$calls = [];
	$prepare = static function (string $phase, int $pageId) use (&$calls): array {
		$calls[] = [$phase, $pageId];
		return ['ok' => true, 'status' => 'HIT'];
	};
	$done = $coordinator->process($queue->current(11),
		static fn(int $id): ?int => 100, $prepare);
	$check($done['state'] === 'acknowledged' && count($done['phases']) === 3,
		'Revision acknowledged only after all three successful phases');
	$check(array_column($calls, 0) === CachePreparationCoordinator::PHASES,
		'The three caches run sequentially in the declared order');
	$check($queue->current(11) === null, 'Completed record is removed');

	$scanCalls = [];
	$scanPrepare = static function (string $phase, int $id) use (&$scanCalls): array {
		$scanCalls[] = $phase;
		return ['ok' => true, 'status' => 'REUSED'];
	};
	$scanDone = $coordinator->processUnqueued(99, 100,
		static fn(int $id): ?int => 100, $scanPrepare,
		static fn(int $id): ?string => 'public-identity');
	$check($scanDone['state'] === 'complete' &&
		$scanCalls === CachePreparationCoordinator::PHASES,
		'Historic page runs all phases exactly once in the correct order');
	$scanCalls = [];
	$scanSkipped = $coordinator->processUnqueued(99, 100,
		static fn(int $id): ?int => 100, $scanPrepare,
		static fn(int $id): ?string => null);
	$check($scanSkipped['state'] === 'skipped-nonpublic' && $scanCalls === [],
		'Nonpublic historic pages cannot generate HTML or block the cursor');
	$scanCalls = [];
	$GLOBALS['dailyScanRevision'] = 101;
	$scanChanged = $coordinator->processUnqueued(99, 100,
		static fn(int $id): ?int => $GLOBALS['dailyScanRevision'],
		$scanPrepare,
		static fn(int $id): ?string => 'public-identity');
	$check($scanChanged['state'] === 'retry-page-changed' && $scanCalls === [],
		'No page render is launched if its initial primary revision differs');
	$GLOBALS['dailyScanRevision'] = 100;
	$scanChanged = $coordinator->processUnqueued(99, 100,
		static fn(int $id): ?int => $GLOBALS['dailyScanRevision'],
		static function (string $phase) use (&$scanCalls): array {
			$scanCalls[] = $phase;
			$GLOBALS['dailyScanRevision'] = 101;
			return ['ok' => true, 'status' => 'CREATED'];
		},
		static fn(int $id): ?string => 'public-identity');
	$check($scanChanged['state'] === 'retry-page-changed' &&
		$scanCalls === ['vector'], 'Concurrent edit stops sweep after its first renderer');
	$scanCalls = [];
	$scanRate = $coordinator->processUnqueued(99, 100,
		static fn(int $id): ?int => 100,
		static function (string $phase) use (&$scanCalls): array {
			$scanCalls[] = $phase;
			return ['ok' => $phase !== 'minerva', 'status' => $phase === 'minerva' ? 'HTTP_429' : 'REUSED'];
		},
		static fn(int $id): ?string => 'public-identity');
	$check($scanRate['state'] === 'retry' && $scanCalls === ['vector', 'minerva'],
		'Rate limiting stops a historic sweep before fragment preparation');

	$queue->enqueue(12, 200);
	$failure = $coordinator->process($queue->current(12),
		static fn(int $id): ?int => 200,
		static fn(string $phase, int $id): array => $phase === 'minerva'
			? ['ok' => false, 'status' => 'HTTP_429'] : ['ok' => true, 'status' => 'REUSED']);
	$check($failure['state'] === 'retry' && !isset($failure['phases']['reading']),
		'Rate limiting stops this page before a reading render');
	$check($queue->current(12)['revisionId'] === 200,
		'Partial preparation does not acknowledge a queued revision');
	$done = $coordinator->process($queue->current(12),
		static fn(int $id): ?int => 200, $prepare);
	$check($done['state'] === 'acknowledged',
		'After retry the successfully prepared revision can be acknowledged');

	$queue->enqueue(13, 300);
	$GLOBALS['unitLatestRevision'] = 300;
	$changed = $coordinator->process($queue->current(13),
		static fn(int $id): ?int => $GLOBALS['unitLatestRevision'],
		static function (string $phase, int $id) use ($queue): array {
			if ($phase === 'vector') {
				$queue->enqueue(13, 301);
				$GLOBALS['unitLatestRevision'] = 301;
			}
			return ['ok' => true, 'status' => 'CREATED'];
		});
	$check($changed['state'] === 'revision-changed-or-deleted' &&
		$queue->current(13)['revisionId'] === 301,
		'Concurrent edit during a cache render cannot be acknowledged');
	$check(count($changed['phases']) === 1,
		'Later phases are not launched after revision drift');

	$queue->enqueue(14, 400);
	$late = $coordinator->process($queue->current(14),
		static fn(int $id): ?int => 401, $prepare);
	$check($late['state'] === 'revision-changed-or-deleted' &&
		$queue->current(14)['revisionId'] === 401,
		'Primary database revision repairs a missed direct-edit event');
	$queue->enqueue(15, 500);
	$deleted = $coordinator->process($queue->current(15),
		static fn(int $id): ?int => null, $prepare);
	$check($deleted['state'] === 'revision-changed-or-deleted' &&
		$queue->current(15)['revisionId'] === 500,
		'Deleted pages are not automatically acknowledged without cleanup');
	$queue->enqueue(16, 600);
	$beforeSuperseded = $queue->current(16);
	$queue->enqueue(16, 601);
	$superseded = $coordinator->process($beforeSuperseded,
		static fn(int $id): ?int => 601, $prepare);
	$check($superseded['state'] === 'superseded', 'Superseded snapshot is skipped');

	$queue->enqueue(18, 700);
	$oldSemantic = $queue->current(18);
	$semanticMutation = $coordinator->process($oldSemantic,
		static fn(int $id): ?int => 700,
		static function (string $phase, int $id) use ($queue): array {
			if ($phase === 'vector') {
				// SMW changed a parent relationship, but this page was not edited.
				$queue->enqueueInvalidation($id, 700, '20261122140001');
			}
			return ['ok' => true, 'status' => 'CREATED'];
		});
	$check($semanticMutation['state'] !== 'acknowledged' &&
		count($semanticMutation['phases']) === 1 &&
		$queue->current(18)['revisionId'] === 700 &&
		$queue->current(18)['generation'] !== $oldSemantic['generation'],
		'Same-revision semantic mutation survives a concurrent renderer');
	$blockedSemantic = $coordinator->process($queue->current(18),
		static fn(int $id): ?int => 700,
		static function (): never {
			throw new RuntimeException('Blocked semantic page was rendered before deferred touch');
		});
	$check($blockedSemantic['state'] === 'awaiting-deferred-touch' &&
		$queue->current(18)['ready'] === false,
		'Pending deferred invalidation cannot start any cache renderer');
	$check(!in_array(18, array_column($queue->pending(100, true), 'pageId'), true),
		'Legacy indirect-SMW requests cannot trigger direct-edit cache preparation');
	$check(in_array(18, array_column($queue->pending(100), 'pageId'), true),
		'Historic requests remain inspectable without being executed');

	$pendingSemantic = $queue->current(18);
	$check(!$queue->confirmTouched(18, 700, $pendingSemantic['generation'], '20261122140000'),
		'Touch prior to the scheduled primary barrier cannot release this page');
	$check($queue->confirmTouched(18, 700, $pendingSemantic['generation'], '20261122140001'),
		'After completed deferred touch, the exact semantic generation becomes ready');

	$semanticRetry = $coordinator->process($queue->current(18),
		static fn(int $id): ?int => 700, $prepare);
	$check($semanticRetry['state'] === 'acknowledged' && $queue->current(18) === null,
		'The refreshed semantic generation is prepared and acknowledged on retry');


	// A page can lose public-read access, have a revision suppressed, or be
	// moved without changing its page_latest. The independent primary/ACL
	// identity must therefore be checked before, between and after phases.
	$queue->enqueue(19, 701);
	$unsafeCalls = 0;
	$unsafe = $coordinator->process($queue->current(19),
		static fn(int $id): ?int => 701,
		static function () use (&$unsafeCalls): array {
			$unsafeCalls++;
			return ['ok' => true, 'status' => 'HIT'];
		},
		static fn(int $id): ?string => null);
	$check($unsafe['state'] === 'unsafe-for-anonymous-cache' &&
		$unsafeCalls === 0 && $queue->current(19) !== null,
		'Unreadable or suppressed page cannot start any HTML cache writer');

	$queue->enqueue(20, 702);
	$publicToken = 'public-identity-1';
	$publicCalls = [];
	$prewarmBlocked = $coordinator->process($queue->current(20),
		static fn(int $id): ?int => 702,
		static function (string $phase) use (&$publicCalls, &$publicToken): array {
			$publicCalls[] = $phase;
			$publicToken = null; // Read permission revoked during Vector render.
			return ['ok' => true, 'status' => 'CREATED'];
		},
		static function (int $id) use (&$publicToken): ?string { return $publicToken; });
	$check($prewarmBlocked['state'] === 'unsafe-for-anonymous-cache' &&
		$publicCalls === ['vector'] && $queue->current(20) !== null,
		'Mid-render anonymous permission revocation prevents subsequent phases and acknowledgement');

	$queue->enqueue(21, 703);
	$publicToken = 'before-move';
	$phaseCalls = 0;
	$move = $coordinator->process($queue->current(21),
		static fn(int $id): ?int => 703,
		static function () use (&$phaseCalls, &$publicToken): array {
			$phaseCalls++;
			$publicToken = 'after-move';
			return ['ok' => true, 'status' => 'CREATED'];
		},
		static function (int $id) use (&$publicToken): ?string { return $publicToken; });
	$check($move['state'] === 'public-identity-changed' && $phaseCalls === 1 &&
		$queue->current(21)['revisionId'] === 703,
		'Move or page_touched drift without a new revision cannot be acknowledged');

	$queue->enqueue(22, 704);
	$publicCalls = [];
	$stable = $coordinator->process($queue->current(22),
		static fn(int $id): ?int => 704,
		static function (string $phase) use (&$publicCalls): array {
			$publicCalls[] = $phase;
			return ['ok' => true, 'status' => 'REUSED'];
		},
		static fn(int $id): ?string => 'still-public');
	$check($stable['state'] === 'acknowledged' &&
		count($publicCalls) === 3 && $queue->current(22) === null,
		'Public page remains eligible after every stable primary/ACL check');

	$queue->enqueue(23, 705);
	$accessCalls = 0;
	$lastMoment = $coordinator->process($queue->current(23),
		static fn(int $id): ?int => 705,
		static fn(string $phase, int $id): array => ['ok' => true, 'status' => 'REUSED'],
		static function () use (&$accessCalls): ?string {
			$accessCalls++;
			// Baseline, 3 preflight checks, 3 postflight checks, final check.
			return $accessCalls >= 8 ? null : 'public';
		});
	$check($lastMoment['state'] === 'unsafe-for-anonymous-cache' &&
		$queue->current(23) !== null,
		'Permission revocation immediately before acknowledgement preserves the pending request');

	$vector = static fn(int $id, string $status): string =>
		json_encode(['pageId' => $id, 'state' => $status], JSON_THROW_ON_ERROR);
	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'CREATED'))['ok'],
		'Valid native Vector view is accepted');
	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'REUSED'))['ok'],
		'Valid pre-existing Vector file is accepted');
	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'SKIPPED_SMW_ARTICLE')) ===
		['ok' => true, 'status' => 'SKIPPED_SMW_ARTICLE'],
		'SMW custom Article pages have an explicit safe Vector no-cache status');
	$check(!CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'REJECTED_SHORT_OUTPUT'))['ok'],
		'A short HTML capture remains an error on all other Vector pages');
	$check(!CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'SKIPPED_UNKNOWN'))['ok'],
		'Unrecognized Vector skip statuses remain rejected');

	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'SKIPPED_CACHE_POLICY'))
		=== ['ok' => true, 'status' => 'SKIPPED_CACHE_POLICY'],
		'An explicit Vector policy veto is a completed skip, not a retry loop');
	$check(CachePreparationResults::interpret('vector', 17, 0,
		$vector(17, 'SKIPPED_REDIRECT_OR_INVALID'))['ok'],
		'An intentional Vector redirect exclusion must not stall other cache preparation');
	$queue->enqueue(24, 706);
	$policyPhases = [];
	$policySkip = $coordinator->process($queue->current(24),
		static fn(int $id): ?int => 706,
		static function (string $phase, int $id) use (&$policyPhases): array {
			$policyPhases[] = $phase;
			return $phase === 'vector'
				? CachePreparationResults::interpret('vector', $id, 0,
					json_encode(['pageId' => $id, 'state' => 'SKIPPED_CACHE_POLICY'], JSON_THROW_ON_ERROR))
				: ['ok' => true, 'status' => 'REUSED'];
		});
	$check($policySkip['state'] === 'acknowledged' &&
		$policyPhases === CachePreparationCoordinator::PHASES && $queue->current(24) === null,
		'Vector cache exclusion still prepares Minerva and Lecture before acknowledging');
	$check(!CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'WRITE_FAILED'))['ok'],
		'Failed Vector publication is rejected');
	$check(!CachePreparationResults::interpret('vector', 17, 0, $vector(18, 'CREATED'))['ok'],
		'Wrong target page ID is rejected');
	$reading = static fn(int $id, int $count, array $statuses): string =>
		json_encode(['target_page_id' => $id, 'complete' => true,
			'inspected' => $count, 'statuses' => $statuses], JSON_THROW_ON_ERROR);
	$check(CachePreparationResults::interpret('reading', 17, 0,
		$reading(17, 1, ['already-valid' => 1]))['ok'],
		'Reused fragment is accepted');
	$check(CachePreparationResults::interpret('reading', 17, 0,
		$reading(17, 0, []))['ok'], 'A nonargument page skips the reading phase');
	$check(CachePreparationResults::interpret('reading', 17, 0,
		$reading(17, 1, ['deferred-no-html' => 1])) ===
		['ok' => true, 'status' => 'deferred-no-html'],
		'No full HTML available defers unattended fragment preparation without parsing');
	$check(!CachePreparationResults::interpret('reading', 17, 0,
		$reading(17, 1, ['not-stored' => 1]))['ok'],
		'Failed fragment storage preserves the pending queue record');

	$minerva = static fn(array $statuses): string =>
		'Starting Minerva prewarm: wiki=fr' . "\n" .
		'Completed 1 pages; ' . json_encode($statuses, JSON_THROW_ON_ERROR) . "\n";
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['HIT' => 1, 'bypassReasons' => []]))['ok'],
		'Validated Minerva HIT is accepted');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['LOCAL_HIT' => 1, 'bypassReasons' => []])) ===
		['ok' => true, 'status' => 'LOCAL_HIT'],
		'Exact local Minerva hit avoids anonymous HTTP and is accepted');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['SKIPPED_SMW_ARTICLE' => 1, 'bypassReasons' => []])) ===
		['ok' => true, 'status' => 'SKIPPED_SMW_ARTICLE'],
		'Explicit SMW custom Article classification is a safe no-HTTP exclusion');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['SKIPPED_FLOW_BOARD' => 1, 'bypassReasons' => []])) ===
		['ok' => true, 'status' => 'SKIPPED_FLOW_BOARD'],
		'Explicit Flow board content model reports safe no-HTTP Minerva exclusion');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['MISS' => 1, 'bypassReasons' => []]))['ok'],
		'Validated Minerva MISS publication is accepted');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['not-public' => 1]]))['ok'],
		'Explicit nonpublic veto skips storing without retry');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['page' => 1]]))['ok'],
		'Intentional mobile redirect/page exclusion is a completed skip');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['redirect' => 1]]))['ok'],
		'Mobile redirect after rendering must not block the preparation queue');
	$check(!CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['storage-error' => 1]]))['ok'],
		'Storage error cannot be acknowledged as a safe Minerva bypass');
	$check(!CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['SKIPPED_UNKNOWN_ARTICLE' => 1, 'bypassReasons' => []]))['ok'],
		'Unknown explicit skip labels remain untrusted and must stop the scan');
	$check(!CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['NO_HEADER' => 1, 'bypassReasons' => []]))['ok'],
		'Missing diagnostics cannot silently become completion');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['NO_HEADER' => 1, 'bypassReasons' => []])) ===
		['ok' => false, 'status' => 'NO_HEADER'],
		'A missing diagnostic header is reported without acknowledging the page');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['UNKNOWN_HEADER' => 1, 'bypassReasons' => []])) ===
		['ok' => false, 'status' => 'UNKNOWN_HEADER'],
		'Unknown Minerva header is reported and blocks the cursor');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['HTTP_404' => 1, 'bypassReasons' => []])) ===
		['ok' => false, 'status' => 'HTTP_404'],
		'HTTP 404 is distinguishable from a cache HIT without being accepted');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['header-set-cookie' => 1]])) ===
		['ok' => true, 'status' => 'BYPASS-header-set-cookie'],
		'A Set-Cookie output is never cached; daily scans must safely skip it');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['header-authorization' => 1]])) ===
		['ok' => false, 'status' => 'BYPASS-header-authorization'],
		'Unknown response headers remain retryable, never broaden header exceptions');
	$scanCookieCalls = [];
	$scanCookie = $coordinator->processUnqueued(11, 100,
		static fn(int $id): ?int => 100,
		static function (string $phase) use (&$scanCookieCalls, $minerva): array {
			$scanCookieCalls[] = $phase;
			return $phase === 'minerva'
				? CachePreparationResults::interpret('minerva', 11, 0,
					$minerva(['BYPASS' => 1, 'bypassReasons' => ['header-set-cookie' => 1]]))
				: ['ok' => true, 'status' => $phase === 'vector' ? 'SKIPPED_NONCONTENT' : 'not-an-argument'];
		},
		static fn(int $id): ?string => 'public-identity');
	$scanSmw = $coordinator->processUnqueued(17, 29,
		static fn(int $id): ?int => 29,
		static fn(string $phase, int $id): array => [
			'ok' => true, 'status' => $phase === 'minerva' ? 'SKIPPED_SMW_ARTICLE' : 'REUSED'
		],
		static fn(int $id): ?string => 'public-identity');
	$check($scanSmw['state'] === 'complete' &&
		($scanSmw['phases']['minerva'] ?? null) === 'SKIPPED_SMW_ARTICLE',
		'Resumable scan skips SMW Property and Concept mobile HTML without masking other phases');
	$scanFlow = $coordinator->processUnqueued(17, 29,
		static fn(int $id): ?int => 29,
		static fn(string $phase, int $id): array => [
			'ok' => true, 'status' => $phase === 'minerva' ? 'SKIPPED_FLOW_BOARD' :
				($phase === 'reading' ? 'not-an-argument' : 'SKIPPED_NONCONTENT')
		],
		static fn(int $id): ?string => 'public-identity');
	$check($scanFlow['state'] === 'complete' &&
		($scanFlow['phases']['minerva'] ?? null) === 'SKIPPED_FLOW_BOARD',
		'Resumable scan advances past Flow board without a fabricated anonymous cache');
	$check($scanCookie['state'] === 'complete' &&
		($scanCookie['phases']['minerva'] ?? null) === 'BYPASS-header-set-cookie' &&
		$scanCookieCalls === CachePreparationCoordinator::PHASES,
		'The scan can advance past an uncachable cookie-setting response without saving Minerva HTML');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['storage-error' => 1]])) ===
		['ok' => false, 'status' => 'BYPASS-storage-error'],
		'Storage failures remain fatal and expose a bounded safe reason');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['busy' => 1]])) ===
		['ok' => false, 'status' => 'BYPASS-busy'],
		'Concurrent cache fills are not acknowledged as completed');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['unsafe\nvalue' => 1]])) ===
		['ok' => false, 'status' => 'BYPASS-invalid-reason'],
		'Untrusted bypass labels never appear unescaped in logs');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['HTTP_200' => 1, 'MISS' => 1, 'bypassReasons' => []])) ===
		['ok' => false, 'status' => 'minerva-not-prepared'],
		'Multiple conflicting statuses cannot be accepted or reported as one result');
	$check(!CachePreparationResults::interpret('minerva', 17, 7,
		$minerva(['HIT' => 1, 'bypassReasons' => []]))['ok'],
		'Nonzero process exit always rejects even an apparently successful body');

	mkdir($root . '/maintenance', 0700);
	$script = <<<'PHP'
<?php
$pageId = 0;
foreach ($argv as $arg) {
	if (str_starts_with($arg, '--page-id=')) { $pageId = (int)substr($arg, 10); }
}
if ($pageId === 99) { exit(2); }
switch ($argv[1] ?? '') {
	case 'Wikidebates:prepareVectorViewCache':
		echo json_encode(['pageId' => $pageId, 'state' => 'REUSED']), "\n";
		break;
	case 'Wikidebates:prepareReadingCache':
		echo json_encode(['target_page_id' => $pageId, 'complete' => true,
			'inspected' => 1, 'statuses' => ['already-valid' => 1]]), "\n";
		break;
	case 'MinervaFileCache:prewarm':
		echo 'Completed 1 pages; ', json_encode(['HIT' => 1, 'bypassReasons' => []]), "\n";
		break;
	default:
		exit(5);
}
PHP;
	file_put_contents($root . '/maintenance/run.php', $script);
	$commands = new CachePreparationCommands($root, 'fr', 250, 10);
	foreach (CachePreparationCoordinator::PHASES as $phase) {
		$check($commands->prepare($phase, 17)['ok'],
			'Real shell-free subprocess runner accepts correct ' . $phase . ' report');
		$check(!$commands->prepare($phase, 99)['ok'],
			'Real shell-free subprocess runner rejects failing ' . $phase . ' exit');
	}
	$dual = new CachePreparationCommands($root, 'fr', 250, 10, ['android', 'iphone']);
	$dualStarted = microtime(true);
	$dualResult = $dual->prepare('minerva', 17);
	$check($dualResult ===
		['ok' => true, 'status' => 'android=HIT;iphone=HIT'],
		'Two distinct mobile variants are coordinated within a single Minerva phase');
	$check(microtime(true) - $dualStarted >= 0.2,
		'Coordinated variants with actual HTTP output are spaced before the second GET');
	try {
		new CachePreparationCommands($root, 'fr', 250, 10, ['android', 'android']);
		throw new RuntimeException('Duplicate mobile variant accepted');
	} catch (InvalidArgumentException $e) {
		$check(true, 'Duplicate mobile variant selection is rejected');
	}
	$check((new CachePreparationQueue($root, 'en'))->pending() === [],
		'No subprocess can accidentally alter the other wiki queue');

	echo json_encode(['assertions' => $checks, 'result' => 'PASS'], JSON_THROW_ON_ERROR) . "\n";
} finally {
	@chmod($root, 0700);
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $path) {
		$path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
	}
	rmdir($root);
}
