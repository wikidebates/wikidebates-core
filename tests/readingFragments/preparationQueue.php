<?php

require dirname(__DIR__, 2) . '/src/Reading/CachePreparationQueue.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
	if (!$ok) { throw new RuntimeException($message); }
	$checks++;
};
$throws = static function (callable $callback, string $message) use ($check): void {
	try {
		$callback();
	} catch (InvalidArgumentException | RuntimeException $e) {
		$check(true, $message);
		return;
	}
	throw new RuntimeException('Expected rejection: ' . $message);
};

$config = json_decode(file_get_contents(dirname(__DIR__, 2) . '/extension.json'),
	true, 512, JSON_THROW_ON_ERROR);
$check(($config['config']['WikidebatesCachePreparationDirectory']['value'] ?? null) === false,
	'Direct-edit queue is disabled by default');

$root = sys_get_temp_dir() . '/wd-preparation-queue-unit-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot initialize private test root'); }
try {
	$fr = new CachePreparationQueue($root, 'fr');
	$en = new CachePreparationQueue($root, 'en');
	$check($fr->pending() === [] && $fr->current(42) === null,
		'A never-used queue has no pending pages');
	$check($fr->enqueue(42, 100), 'Initial direct revision is persisted');
	$check(!$fr->enqueue(42, 100), 'Repeated edit notification is deduplicated');
	$check(!$fr->enqueue(42, 99), 'Delayed older notification cannot replace a newer revision');
	$check($fr->current(42)['revisionId'] === 100 &&
		preg_match('/\A[a-f0-9]{32}\z/D', $fr->current(42)['generation']) === 1,
		'Direct edits persist a non-repeating generation fence');
	$check($fr->enqueue(42, 101), 'Next direct edit replaces the pending revision');
	$check(!$fr->acknowledge(42, 100, $fr->current(42)['generation']),
		'Worker cannot acknowledge a revision it did not prepare');
	$check($fr->current(42)['revisionId'] === 101, 'Newer edit survives stale acknowledgement');

	$frReopened = new CachePreparationQueue($root, 'fr');
	$check($frReopened->pending()[0]['revisionId'] === 101 &&
		$frReopened->pending()[0]['generation'] === $fr->current(42)['generation'],
		'Queue generation survives process reconstruction and restart');
	$check($en->pending() === [], 'English wiki has an independent queue');
	$check($en->enqueue(42, 10), 'Identical numeric page ID is valid on another wiki');
	$check($en->current(42)['revisionId'] === 10 &&
		$fr->current(42)['revisionId'] === 101, 'Both wiki identities stay isolated');

	$check($fr->enqueue(51, 1000), 'Second page enters queue');
	$check(count($fr->pending(1)) === 1 && count($fr->pending(2)) === 2,
		'Batch snapshots are bounded, without loading the whole queue');
	$throws(static fn() => $fr->pending(0), 'Zero batch size is rejected');
	$throws(static fn() => $fr->pending(10001), 'Unbounded batch size is rejected');
	$throws(static fn() => $fr->enqueue(0, 100), 'Invalid page ID is rejected');
	$throws(static fn() => $fr->enqueue(5, 0), 'Invalid revision ID is rejected');
	$throws(static fn() => new CachePreparationQueue('relative/cache', 'fr'),
		'Relative storage path is rejected');
	$throws(static fn() => new CachePreparationQueue($root, ''),
		'Missing wiki identity is rejected');

	$beforeSemantic = $fr->current(42);
	$fr->enqueueInvalidation(42, 101);
	$afterSemantic = $fr->current(42);
	$check($afterSemantic['revisionId'] === 101 &&
		$afterSemantic['generation'] !== $beforeSemantic['generation'],
		'Semantic change supersedes pending work at the same page revision');
	$check(!$fr->acknowledge(42, 101, $beforeSemantic['generation']),
		'Worker cannot erase a same-revision semantic event with an obsolete generation');
	$check(!$fr->acknowledge(42, 101),
		'Schema-2 records cannot be acknowledged by legacy revision-only consumers');
	$check(!$fr->enqueue(42, 101) &&
		$fr->current(42)['generation'] === $afterSemantic['generation'],
		'Duplicate direct hooks do not erase a pending semantic invalidation');
	$fr->enqueueInvalidation(42, 99);
	$afterOlderSemantic = $fr->current(42);
	$check($afterOlderSemantic['revisionId'] === 101 &&
		$afterOlderSemantic['generation'] !== $afterSemantic['generation'],
		'A delayed SMW event preserves a newer direct revision while fencing work again');

	$check($fr->acknowledge(42, 101, $afterOlderSemantic['generation']),
		'Worker removes exactly the prepared revision and generation');
	$check($fr->current(42) === null && count($fr->pending()) === 1,
		'Completed page disappears and other pages remain queued');
	$check(!$fr->acknowledge(42, 101, $afterOlderSemantic['generation']),
		'Acknowledging the same generation twice has no effect');

	// Legacy schema-1 files remain readable and can be acknowledged without a token.
	$legacyPath = $root . '/' . hash('sha256', 'fr') . '/51.json';
	file_put_contents($legacyPath,
		json_encode(['schema' => 1, 'pageId' => 51, 'revisionId' => 1000,
			'queuedAt' => time()], JSON_THROW_ON_ERROR));
	$check($fr->current(51)['schema'] === 1 && $fr->acknowledge(51, 1000),
		'Existing schema-1 records can be drained during migration');
	$check($fr->enqueue(51, 1001) && $fr->current(51)['schema'] === 2,
		'Next edit upgrades legacy records to generation-fenced schema 2');

	// Corrupt entries must not be interpreted as valid pending edits.
	$frRoot = $root . '/' . hash('sha256', 'fr');
	file_put_contents($frRoot . '/51.json', '{"schema":2,"pageId":51,"revisionId":1001,"queuedAt":1,"generation":"bad"}');
	$throws(static fn() => $fr->pending(), 'Malformed persisted revisions fail closed');
	$check($en->current(42)['revisionId'] === 10,
		'Corruption in one wiki does not poison the other');

	// The directory must remain private. Restore before recursive cleanup.
	chmod($root, 0755);
	$throws(static fn() => new CachePreparationQueue($root, 'fr'),
		'World-readable preparation directory is rejected');
	chmod($root, 0700);
	$check($en->acknowledge(42, 10, $en->current(42)['generation']),
		'English queue remains usable');
	echo json_encode(['assertions' => $checks, 'result' => 'PASS']) . "\n";
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
