<?php

require dirname(__DIR__, 2) . '/src/Reading/SemanticPurgeScope.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeScope;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
	$checks++;
};

$check(!SemanticPurgeScope::isActive(), 'Normal requests and ordinary purges are not labelled semantic');
$result = SemanticPurgeScope::run(static function () use ($check): int {
	$check(SemanticPurgeScope::isActive(), 'Semantic scope is active only inside its callback');
	$nested = SemanticPurgeScope::run(static function () use ($check): string {
		$check(SemanticPurgeScope::isActive(), 'Nested semantic dispatch remains explicitly marked');
		return 'nested';
	});
	$check($nested === 'nested' && SemanticPurgeScope::isActive(),
		'Nested scope restores the outer semantic provenance');
	return 42;
});
$check($result === 42 && !SemanticPurgeScope::isActive(),
	'Callback result is retained and semantic scope does not leak to later purges');

try {
	SemanticPurgeScope::run(static function () use ($check): never {
		$check(SemanticPurgeScope::isActive(), 'Exception is raised inside an active semantic scope');
		throw new RuntimeException('synthetic failure');
	});
	throw new RuntimeException('Expected the synthetic exception to propagate');
} catch (RuntimeException $e) {
	$check($e->getMessage() === 'synthetic failure',
		'Exception from a failed semantic HTML purge is not hidden');
}
$check(!SemanticPurgeScope::isActive(),
	'Even a failed dispatch cannot misclassify a later explicit purge');
echo json_encode(['assertions' => $checks, 'result' => 'PASS'], JSON_THROW_ON_ERROR) . "\n";
