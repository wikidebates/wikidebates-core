<?php

require dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationCoordinator.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationResults.php';
require dirname(__DIR__, 2) . '/src/Reading/CachePreparationCommands.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationResults;

$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
};
$root = sys_get_temp_dir() . '/wd-coordinator-unit-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create queue fixture'); }

try {
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
	$done = $coordinator->process(['pageId' => 11, 'revisionId' => 100],
		static fn(int $id): ?int => 100, $prepare);
	$check($done['state'] === 'acknowledged' && count($done['phases']) === 3,
		'Revision acknowledged only after all three successful phases');
	$check(array_column($calls, 0) === CachePreparationCoordinator::PHASES,
		'The three caches run sequentially in the declared order');
	$check($queue->current(11) === null, 'Completed record is removed');

	$queue->enqueue(12, 200);
	$failure = $coordinator->process(['pageId' => 12, 'revisionId' => 200],
		static fn(int $id): ?int => 200,
		static fn(string $phase, int $id): array => $phase === 'minerva'
			? ['ok' => false, 'status' => 'HTTP_429'] : ['ok' => true, 'status' => 'REUSED']);
	$check($failure['state'] === 'retry' && !isset($failure['phases']['reading']),
		'Rate limiting stops this page before a reading render');
	$check($queue->current(12)['revisionId'] === 200,
		'Partial preparation does not acknowledge a queued revision');
	$done = $coordinator->process(['pageId' => 12, 'revisionId' => 200],
		static fn(int $id): ?int => 200, $prepare);
	$check($done['state'] === 'acknowledged',
		'After retry the successfully prepared revision can be acknowledged');

	$queue->enqueue(13, 300);
	$GLOBALS['unitLatestRevision'] = 300;
	$changed = $coordinator->process(['pageId' => 13, 'revisionId' => 300],
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
	$late = $coordinator->process(['pageId' => 14, 'revisionId' => 400],
		static fn(int $id): ?int => 401, $prepare);
	$check($late['state'] === 'revision-changed-or-deleted' &&
		$queue->current(14)['revisionId'] === 401,
		'Primary database revision repairs a missed direct-edit event');
	$queue->enqueue(15, 500);
	$deleted = $coordinator->process(['pageId' => 15, 'revisionId' => 500],
		static fn(int $id): ?int => null, $prepare);
	$check($deleted['state'] === 'revision-changed-or-deleted' &&
		$queue->current(15)['revisionId'] === 500,
		'Deleted pages are not automatically acknowledged without cleanup');
	$queue->enqueue(16, 600);
	$queue->enqueue(16, 601);
	$superseded = $coordinator->process(['pageId' => 16, 'revisionId' => 600],
		static fn(int $id): ?int => 601, $prepare);
	$check($superseded['state'] === 'superseded', 'Superseded snapshot is skipped');

	$vector = static fn(int $id, string $status): string =>
		json_encode(['pageId' => $id, 'state' => $status], JSON_THROW_ON_ERROR);
	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'CREATED'))['ok'],
		'Valid native Vector view is accepted');
	$check(CachePreparationResults::interpret('vector', 17, 0, $vector(17, 'REUSED'))['ok'],
		'Valid pre-existing Vector file is accepted');
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
		$minerva(['MISS' => 1, 'bypassReasons' => []]))['ok'],
		'Validated Minerva MISS publication is accepted');
	$check(CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['not-public' => 1]]))['ok'],
		'Explicit nonpublic veto skips storing without retry');
	$check(!CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['BYPASS' => 1, 'bypassReasons' => ['storage-error' => 1]]))['ok'],
		'Storage error cannot be acknowledged as a safe Minerva bypass');
	$check(!CachePreparationResults::interpret('minerva', 17, 0,
		$minerva(['NO_HEADER' => 1, 'bypassReasons' => []]))['ok'],
		'Missing diagnostics cannot silently become completion');
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
