<?php

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Reading/SemanticPageCacheHooks.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;

$reflection = new ReflectionProperty(SemanticPageCacheHooks::class, 'readingFragment');
$scoped = static fn(): bool => $reflection->getValue();

check(!$scoped(), 'Outside a reading request, the semantic validator is enabled');
$returned = SemanticPageCacheHooks::forReadingFragment(static function () use ($scoped): string {
	check($scoped(), 'Reading scope bypasses semantic freshness checks');
	SemanticPageCacheHooks::forReadingFragment(static function () use ($scoped): void {
		check($scoped(), 'Nested reading scope keeps the bypass active');
		check(SemanticPageCacheHooks::onRejectParserCacheValue(null, null, null),
			'Reading fragment lookup bypasses its full-page SMW validator');
	});
	check($scoped(), 'Nested reading scope restores its parent context');
	return 'ok';
});
same('ok', $returned, 'Scoped lookup retains its callback result');
check(!$scoped(), 'Successful lookup restores full-page validation');
try {
	SemanticPageCacheHooks::forReadingFragment(static function (): void {
		throw new RuntimeException('scope-test');
	});
	throw new RuntimeException('Expected exception was swallowed');
} catch (RuntimeException $e) {
	same('scope-test', $e->getMessage(), 'Callback failures propagate unchanged');
}
check(!$scoped(), 'Failed lookup restores full-page validation');
echo "OK: reading-only semantic bypass is scoped, nested and exception-safe\n";
