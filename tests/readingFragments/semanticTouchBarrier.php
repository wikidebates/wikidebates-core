<?php

require dirname(__DIR__, 2) . '/src/Reading/SemanticTouchBarrier.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticTouchBarrier;

$checks = 0;
$check = static function (bool $valid, string $message) use (&$checks): void {
	if (!$valid) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$expectFailure = static function (callable $candidate, string $message) use ($check): void {
	try {
		$candidate();
	} catch (InvalidArgumentException $e) {
		$check(true, $message);
		return;
	}
	throw new RuntimeException('Expected timestamp rejection: ' . $message);
};

$time = static function (string $timestamp): int {
	return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
};

$check(
	SemanticTouchBarrier::targetAfter('20261122120000', $time('2026-11-22 12:00:00')) ===
	'20261122120001',
	'An SMW touch in the same second must strictly advance the stored timestamp'
);
$check(
	SemanticTouchBarrier::targetAfter('20261122120100', $time('2026-11-22 12:00:00')) ===
	'20261122120101',
	'An existing future page_touched cannot be overwritten by an earlier target'
);
$check(
	SemanticTouchBarrier::targetAfter('20261122120000', $time('2026-11-22 12:03:00')) ===
	'20261122120301',
	'The barrier must always be in the future relative to the purge scheduling moment'
);
$check(
	SemanticTouchBarrier::targetAfter('20240229235959', $time('2024-02-29 23:59:59')) ===
	'20240301000000',
	'Touch targets normalize leap-day and date boundaries correctly'
);
foreach (['', '2026-11-22', '20260230000000', '202611220000000', 'foo'] as $bad) {
	$expectFailure(static fn() => SemanticTouchBarrier::targetAfter($bad, time()),
		'Malformed or impossible MediaWiki timestamps fail closed');
}
$expectFailure(
	static fn() => SemanticTouchBarrier::targetAfter('20261122120000', 0),
	'Missing time anchor is rejected'
);
echo json_encode(['assertions' => $checks, 'result' => 'PASS'], JSON_THROW_ON_ERROR) . "\n";
