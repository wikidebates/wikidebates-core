<?php

require_once __DIR__ . '/../../src/Reading/VectorCacheEligibility.php';
require_once __DIR__ . '/../../src/Reading/CachePreparationResults.php';

use MediaWiki\Extension\Wikidebates\Reading\VectorCacheEligibility;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationResults;

function assertVectorName(bool $condition, string $description): void {
	if (!$condition) {
		throw new \RuntimeException($description);
	}
}

// FileCacheBase 1.43 builds urlencode('ns0:' . title) plus .html[.gz].
$prefix = 'ns0:';
if (VectorCacheEligibility::nativeViewFilenameTooLong(
	$prefix . str_repeat('a', 241), true
)) {
	throw new \RuntimeException('A 255-byte gzip filename must remain valid');
}
assertVectorName(
	VectorCacheEligibility::nativeViewFilenameTooLong(
		$prefix . str_repeat('a', 242), true
	), 'A 256-byte gzip filename must be excluded'
);
assertVectorName(
	!VectorCacheEligibility::nativeViewFilenameTooLong(
		$prefix . str_repeat('a', 244), false
	), 'A 255-byte plain filename must remain valid'
);
assertVectorName(
	VectorCacheEligibility::nativeViewFilenameTooLong(
		$prefix . str_repeat('a', 245), false
	), 'A 256-byte plain filename must be excluded'
);
assertVectorName(
	VectorCacheEligibility::nativeViewFilenameTooLong(
		$prefix . str_repeat('a', 80) . str_repeat('é', 60), true
	), 'Percent-encoded multibyte title must use encoded byte length'
);
assertVectorName(
	VectorCacheEligibility::nativeViewFilenameTooLong(
		$prefix . str_repeat('a', 237) . 'é', true
	), 'Encoded multibyte title may exceed NAME_MAX even if raw bytes fit'
);
assertVectorName(
	!VectorCacheEligibility::nativeViewFilenameTooLong('ns0:Short_title', true),
	'A normal Vector page must not be excluded'
);

$skipped = CachePreparationResults::interpret('vector', 15810, 0,
	json_encode([
		'pageId' => 15810, 'state' => 'SKIPPED_FILENAME_TOO_LONG'
	], JSON_THROW_ON_ERROR)
);
assertVectorName($skipped === [
	'ok' => true, 'status' => 'SKIPPED_FILENAME_TOO_LONG'
], 'Recognized native filename overflow must allow Minerva and Reading next');
$writeFailure = CachePreparationResults::interpret('vector', 15810, 0,
	json_encode(['pageId' => 15810, 'state' => 'WRITE_FAILED'], JSON_THROW_ON_ERROR)
);
assertVectorName($writeFailure['ok'] === false,
	'Real write failures must still stop unsafe cache preparation');
$wrongPage = CachePreparationResults::interpret('vector', 99, 0,
	json_encode(['pageId' => 15810, 'state' => 'SKIPPED_FILENAME_TOO_LONG'],
		JSON_THROW_ON_ERROR)
);
assertVectorName($wrongPage['ok'] === false,
	'Skip status cannot be accepted from a different page');

echo "OK: bounded native Vector filename skip and fail-closed output parser\n";
