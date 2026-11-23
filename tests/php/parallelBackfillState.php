<?php

require_once __DIR__ . '/../../src/Reading/ParallelBackfillState.php';

use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillState;

function assertCheckpoint(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

$state = ParallelBackfillState::create(100, 111, 3);
assertCheckpoint($state->lanes()[0]['end'] === 104 &&
	$state->lanes()[1]['end'] === 108, 'Disjoint lane partition');

$state = $state->completed(2, 109)->completed(2, 110)->completed(2, 111)->exhausted(2);
assertCheckpoint($state->contiguousAfter() === 100 && !$state->finished(),
	'Later completed lane must not move the contiguous frontier');

$state = $state->completed(0, 102);
assertCheckpoint($state->contiguousAfter() === 102, 'Partial first-lane checkpoint');
$state = $state->exhausted(0);
assertCheckpoint($state->contiguousAfter() === 104, 'First-lane exhaustion');
$state = $state->completed(1, 105);
assertCheckpoint($state->contiguousAfter() === 105, 'Partial second-lane checkpoint');
$state = $state->exhausted(1);
assertCheckpoint($state->finished() && $state->contiguousAfter() === 111,
	'All lanes completed');
$restored = new ParallelBackfillState($state->lanes());
assertCheckpoint($restored->finished(), 'State reconstruction after restart');

$invalid = [
	static fn() => ParallelBackfillState::create(1, 2, 3),
	static fn() => new ParallelBackfillState([
		['start' => 0, 'end' => 5, 'after' => 6, 'done' => false]
	]),
	static fn() => new ParallelBackfillState([
		['start' => 0, 'end' => 5, 'after' => 0, 'done' => false],
		['start' => 6, 'end' => 7, 'after' => 6, 'done' => false]
	]),
	static fn() => new ParallelBackfillState([
		['start' => 0, 'end' => 5, 'after' => 3, 'done' => true]
	]),
	static fn() => $state->completed(0, 103),
	static fn() => $state->completed(8, 1)
];
foreach ($invalid as $check) {
	try {
		$check();
		throw new \RuntimeException('An invalid checkpoint was accepted');
	} catch (\InvalidArgumentException $e) {
	}
}

echo "OK: parallel backfill checkpoint invariants\n";
