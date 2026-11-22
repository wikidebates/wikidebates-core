<?php

require dirname(__DIR__, 2) . '/src/Reading/SemanticVectorFileGuard.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticVectorFileGuard;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
};

$now = str_repeat('a', 64);
$previous = str_repeat('b', 64);
$secret = 'test-only-not-production';
$markup = "<!DOCTYPE html><html><body>parent: original</body></html>";
$marker = SemanticVectorFileGuard::marker($now, 1220, 241543, '20261122140001', $secret);
$file = $markup . $marker;

$check(SemanticVectorFileGuard::matches($file, $now, 1220, 241543, '20261122140001', $secret),
	'A native Vector HTML file bearing its own signed SMW marker is cacheable');
$check(!SemanticVectorFileGuard::matches($markup, $now, 1220, 241543, '20261122140001', $secret),
	'Unmarked historical Vector HTML must be rejected');
$check(!SemanticVectorFileGuard::matches($file, $previous, 1220, 241543, '20261122140001', $secret),
	'Indirect semantic change without a MediaWiki revision must invalidate HIT');
$check(!SemanticVectorFileGuard::matches($file, $now, 1220, 241544, '20261122140001', $secret),
	'Different MediaWiki revision cannot reuse the file');
$check(!SemanticVectorFileGuard::matches($file, $now, 1220, 241543, '20261122140002', $secret),
	'New page_touched invalidates previously signed HTML');
$check(!SemanticVectorFileGuard::matches($file, $now, 4766, 241543, '20261122140001', $secret),
	'Different page ID cannot reuse a signed file');
$check(!SemanticVectorFileGuard::matches($file, $now, 1220, 241543, '20261122140001', 'different-secret'),
	'Other secret keys invalidate the file');
$check(!SemanticVectorFileGuard::matches($markup . $marker . "\n<!-- inserted -->",
	$now, 1220, 241543, '20261122140001', $secret),
	'Only the terminal trusted marker can authorize an HIT');
$check(SemanticVectorFileGuard::matches($file . "\n", $now, 1220, 241543,
	'20261122140001', $secret),
	'Harmless trailing whitespace is accepted');

try {
	SemanticVectorFileGuard::marker('bad', 1220, 241543, '20261122140001', $secret);
	throw new RuntimeException('Invalid signature was accepted');
} catch (InvalidArgumentException $e) {
	$checks++;
}
try {
	SemanticVectorFileGuard::marker($now, 1220, 241543, '20261122140001', '');
	throw new RuntimeException('Empty secret was accepted');
} catch (InvalidArgumentException $e) {
	$checks++;
}

echo "OK: {$checks} signed Vector cache snapshot checks\n";
