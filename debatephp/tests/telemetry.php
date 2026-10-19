<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\Telemetry;
$events = [];
$sink = static function($event) use (&$events) { $events[] = $event; };
$silent = new Telemetry(false, false, [], $sink);
$silent->finish('messages', $silent->start()); $silent->problem('seo', 'exception', new RuntimeException('SECRET')); $silent->report();
if ($events !== []) { throw new RuntimeException('Disabled telemetry emitted data'); }
$active = new Telemetry(true, true, ['mode' => 'reference'], $sink);
$active->finish('messages', $active->start()); $active->problem('seo', 'exception', new RuntimeException('SECRET'));
$active->preprocess('field', static fn() => 'result');
$active->count('preprocess_cache_hits');
try { $active->preprocess('failing', static function() { throw new RuntimeException('SECRET'); }); }
catch (RuntimeException $e) {}
$active->report();
if (count($events) !== 2 || $events[1]['stages']['messages']['calls'] !== 1
	|| $events[1]['counters']['preprocess_executed'] !== 2 || $events[1]['counters']['preprocess_cache_hits'] !== 1
	|| $events[1]['preprocessing']['field']['calls'] !== 1 || $events[1]['preprocessing']['failing']['calls'] !== 1
	|| str_contains(json_encode($events), 'SECRET')) {
	throw new RuntimeException('Telemetry did not preserve counts or omitted-value policy');
}
$broken = new Telemetry(true, true, [], static function() { throw new RuntimeException('Log unavailable'); });
$broken->problem('semantic', 'exception'); $broken->report();
$silent->semanticFinish('SECRET', 'read', $silent->start());
$silent->semanticCacheHit('SECRET', 'query');
$silent->preparation(['calls' => 3, 'SECRET' => 'SECRET']);
$silent->semanticFallbackSkipped('SECRET');
$silent->report();
if (count($events) !== 2) { throw new RuntimeException('Disabled SMW profiling emitted data'); }
$safe = new Telemetry(true, false, [], $sink);
$safe->semanticFinish('SECRET', 'SECRET', $safe->start(), true);
$safe->semanticCacheHit('SECRET', 'SECRET');
$safe->preparation(['calls' => 3, 'hits' => 1, 'misses' => 2, 'SECRET' => 'SECRET', 'payload_bytes' => 'SECRET']);
$safe->semanticFallbackSkipped('SECRET');
$safe->report();
$semantic = $events[2]['semantic']['other']['read'];
if ($events[2]['preparation']['calls'] !== 3 || $events[2]['preparation']['payload_bytes'] !== 0) { throw new RuntimeException('CSV profiling allowlist failed'); }
if ($events[2]['semantic_fallbacks_skipped'] !== ['other' => 2]) { throw new RuntimeException('Skipped fallback profiling allowlist failed'); }
if ($semantic['calls'] !== 1 || $semantic['failures'] !== 1 || $semantic['max_ms'] !== $semantic['milliseconds']
	|| $events[2]['semantic_cache_hits']['other']['query'] !== 1 || str_contains(json_encode($events), 'SECRET')) {
	throw new RuntimeException('SMW profiling counts, maxima or origin allowlist failed');
}
echo "Disabled logging, actual/failed preprocessing counts, cache hits, omitted values and failing log sink: passed\n";
