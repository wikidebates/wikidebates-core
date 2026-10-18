<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer;
$class = new ReflectionClass(ArgumentReferenceRenderer::class);
$object = $class->newInstanceWithoutConstructor();
$call = static fn($name, ...$args) => $class->getMethod($name)->invokeArgs($object, $args);
$count = 0;
$check = static function($condition) use (&$count) { $count++; if (!$condition) { throw new RuntimeException('FIFO cache regression'); } };
$cache = $call('cacheMake', 2);
$call('cachePut', $cache, 'a', 'first'); $call('cachePut', $cache, 'b', false);
$check($call('cacheGet', $cache, 'b') === false);
$call('cachePut', $cache, 'a', 'updated'); $call('cachePut', $cache, 'c', 'third');
$check($call('cacheGet', $cache, 'a') === null); // Updating does not refresh FIFO position.
$check($call('cacheGet', $cache, 'b') === false);
$check($call('cacheGet', $cache, 'c') === 'third');
$call('cachePut', $cache, 'd', '');
$check($call('cacheGet', $cache, 'b') === null);
$check($call('cacheGet', $cache, 'd') === '');
foreach ([0, 1] as $limit) {
	$small = $call('cacheMake', $limit);
	$call('cachePut', $small, 'a', 'first'); $call('cachePut', $small, 'b', 'second');
	$check($call('cacheGet', $small, 'a') === null);
	$check($call('cacheGet', $small, 'b') === ($limit === 0 ? null : 'second'));
}
$check($call('cacheMake', false)['max'] === 200);
$check($call('cacheGet', $call('cacheMake', 2), 'c') === null); // Invocation isolation.
echo "$count FIFO assertions passed: bounds, update order, false/empty values and isolated caches.\n";
