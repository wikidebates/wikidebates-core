<?php

require_once __DIR__ . '/../../src/Reading/ParallelBackfillRetryPolicy.php';

use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillRetryPolicy as Policy;

function assertRetry(?int $expected, array $result, int $attempt, string $message): void {
	if (Policy::delay($result, $attempt) !== $expected) {
		throw new RuntimeException($message);
	}
}

assertRetry(30, ['state' => 'retry', 'phases' => ['minerva' => 'HTTP_429']],
	0, 'First HTTP 429 backoff');
assertRetry(60, ['state' => 'retry', 'phases' => ['minerva' => 'HTTP_429']],
	1, 'Second HTTP 429 backoff');
assertRetry(null, ['state' => 'retry', 'phases' => ['minerva' => 'HTTP_429']],
	2, 'Retry count must be bounded');
foreach (['HTTP_500', 'HTTP_502', 'HTTP_503', 'HTTP_504'] as $failure) {
	assertRetry(10, ['state' => 'retry', 'phases' => ['minerva' => $failure]],
		0, 'Known transient server error may retry');
}
foreach (['HTTP_403', 'NO_HEADER', 'REJECTED_SHORT_OUTPUT', 'UNKNOWN_HEADER',
	'BYPASS-extension-veto', 'subprocess-time-limit', 'HTTP_404'] as $failure) {
	assertRetry(null, ['state' => 'retry', 'phases' => ['vector' => $failure]],
		0, 'Unknown or security-sensitive failure must not retry');
}
assertRetry(null, ['state' => 'complete', 'phases' => ['minerva' => 'HTTP_429']],
	0, 'A completed page must not be retried');
assertRetry(null, ['state' => 'retry', 'phases' => []],
	0, 'A missing diagnostic is never transient');
assertRetry(null, ['state' => 'retry-page-changed',
	'phases' => ['minerva' => 'HTTP_503']],
	0, 'Identity changes must not be retried automatically');


foreach (['retry-page-changed', 'queue-changed-after-preparation'] as $state) {
	if (Policy::identityDelayMicroseconds(['state' => $state,
		'phases' => ['vector' => 'SKIPPED_NONCONTENT',
			'minerva' => 'android=BYPASS-parser-expiry;iphone=BYPASS-parser-expiry']], 0) !== 250000 ||
		Policy::identityDelayMicroseconds(['state' => $state, 'phases' => []], 1) !== 750000 ||
		Policy::identityDelayMicroseconds(['state' => $state], 2) !== null) {
		throw new RuntimeException('Concurrent identity changes must have two bounded fresh attempts');
	}
}
foreach (['retry', 'complete', 'skipped-nonpublic', 'unsafe-for-anonymous-cache',
	'public-identity-changed', 'revision-changed-or-deleted'] as $state) {
	if (Policy::identityDelayMicroseconds(['state' => $state, 'phases' => []], 0) !== null) {
		throw new RuntimeException('Unrelated failures may not bypass safety through identity retry');
	}
}

echo "OK: bounded HTTP retry backoff and fail-closed classifications\n";
