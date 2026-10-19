<?php

/** Isolated helper benchmark against an explicit checkout, including its real FIFO. */
require __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer;
use MediaWiki\Extension\DebatePHP\RenderData;
use MediaWiki\Extension\DebatePHP\Table;

$options = getopt('', ['baseline:', 'output:']);
$baseline = realpath($options['baseline'] ?? '');
check(isset($options['baseline']) && $baseline !== false && $baseline !== realpath(dirname(__DIR__))
	&& is_file($baseline . '/debatephp/src/ArgumentReferenceRenderer.php'), 'Distinct --baseline checkout with renderer sources required');
$temporary = sys_get_temp_dir() . '/wikidebates-collections-' . bin2hex(random_bytes(8));
mkdir($temporary . '/src', 0700, true);
symlink($baseline . '/debatephp/resources', $temporary . '/resources');
register_shutdown_function(static function() use ($temporary): void {
	foreach (glob($temporary . '/src/*.php') as $file) { unlink($file); }
	unlink($temporary . '/resources'); rmdir($temporary . '/src'); rmdir($temporary);
});
foreach (glob($baseline . '/debatephp/src/*.php') as $file) {
	file_put_contents($temporary . '/src/' . basename($file), str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace NativeCollectionsBefore;', file_get_contents($file)));
}
spl_autoload_register(static function($class) use ($temporary): void {
	if (str_starts_with($class, 'NativeCollectionsBefore\\')) { require_once $temporary . '/src/' . substr($class, strlen('NativeCollectionsBefore\\')) . '.php'; }
});
$beforeClass = new ReflectionClass(\NativeCollectionsBefore\ArgumentReferenceRenderer::class);
$afterClass = new ReflectionClass(ArgumentReferenceRenderer::class);
$before = $beforeClass->newInstanceWithoutConstructor();
$after = $afterClass->newInstanceWithoutConstructor();
$values = array_fill(1, 2000, 'value');
$oldTable = new \NativeCollectionsBefore\Table($values);
$newTable = new Table($values);
$operations = ['sequence-2000' => [
	'before' => static function() use ($oldTable): int {
		$sum = 0;
		foreach (\NativeCollectionsBefore\Lua::ipairs($oldTable) as $key => $value) { $sum += $key + strlen($value); }
		return $sum;
	},
	'after' => static function() use ($newTable): int {
		$sum = 0;
		foreach ($newTable->sequence() as $key => $value) { $sum += $key + strlen($value); }
		return $sum;
	}
]];
foreach ([600, 2000] as $limit) {
	foreach (['before' => [$beforeClass, $before], 'after' => [$afterClass, $after]] as $mode => [$class, $renderer]) {
		$make = $class->getMethod('cacheMake'); $put = $class->getMethod('cachePut'); $get = $class->getMethod('cacheGet');
		$operations['fifo-' . $limit][$mode] = static function() use ($limit, $renderer, $make, $put, $get): array {
			$cache = $make->invoke($renderer, $limit);
			for ($i = 0; $i < $limit + 1000; $i++) { $put->invoke($renderer, $cache, 'key-' . $i, $i); }
			return [$get->invoke($renderer, $cache, 'key-0'), $get->invoke($renderer, $cache, 'key-1000'), $get->invoke($renderer, $cache, 'key-' . ($limit + 999))];
		};
	}
}
$requests = [];
for ($i = 0; $i < 40; $i++) { $requests[] = implode(',', array_fill(0, 20, ' item ' . $i)); }
$requests = array_merge($requests, $requests, $requests, $requests);
$operations['csv-reused-four-times'] = [
	'before' => static function() use ($requests): array {
		$out = [];
		foreach ($requests as $raw) { $out[] = \NativeCollectionsBefore\SerializedData::csv($raw)->values; }
		return $out;
	},
	'after' => static function() use ($requests): array {
		$out = []; $prepared = new RenderData;
		foreach ($requests as $raw) { $out[] = $prepared->csv($raw)->values; }
		return $out;
	}
];
$uniqueRequests = array_slice($requests, 0, 40);
$operations['csv-unique-only'] = [
	'before' => static function() use ($uniqueRequests): array {
		$out = [];
		foreach ($uniqueRequests as $raw) { $out[] = \NativeCollectionsBefore\SerializedData::csv($raw)->values; }
		return $out;
	},
	'after' => static function() use ($uniqueRequests): array {
		$out = []; $prepared = new RenderData;
		foreach ($uniqueRequests as $raw) { $out[] = $prepared->csv($raw)->values; }
		return $out;
	}
];
foreach (['before' => [$beforeClass, $before, $oldTable], 'after' => [$afterClass, $after, $newTable]] as $mode => [$class, $renderer, $table]) {
	$join = $class->getMethod('seoJoinList');
	$operations['seo-join-2000'][$mode] = static fn() => $join->invoke($renderer, $table);
}
$report = ['php' => PHP_VERSION,
	'scope' => 'Helper operations only; warm shuffled samples; no database or whole-page gain claim', 'repeats' => 21, 'iterations' => 3, 'scenarios' => []];
$report['baseline_commit'] = trim(shell_exec('git -C ' . escapeshellarg($baseline) . ' rev-parse HEAD'));
$cpu = static function(): float {
	$usage = getrusage();
	return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
};
mt_srand(806);
foreach ($operations as $name => $modes) {
	same($modes['before'](), $modes['after'](), 'Exact helper equivalence: ' . $name);
	foreach ($modes as $mode => $operation) {
		$memory = memory_get_usage(); memory_reset_peak_usage(); $operation();
		$report['scenarios'][$name]['peak_extra_bytes'][$mode] = memory_get_peak_usage() - $memory;
	}
	for ($sample = 0; $sample < $report['repeats']; $sample++) {
		$order = ['before', 'after']; shuffle($order);
		foreach ($order as $mode) {
			$cpuStart = $cpu(); $start = hrtime(true);
			for ($i = 0; $i < $report['iterations']; $i++) { $modes[$mode](); }
			$report['scenarios'][$name]['samples_ms'][$mode][] = (hrtime(true) - $start) / 1e6 / $report['iterations'];
			$report['scenarios'][$name]['cpu_ms'][$mode][] = ($cpu() - $cpuStart) / $report['iterations'];
		}
	}
	foreach (['samples_ms', 'cpu_ms'] as $metric) {
		foreach (['before', 'after'] as $mode) {
			$samples = $report['scenarios'][$name][$metric][$mode]; sort($samples);
			$report['scenarios'][$name]['median_' . $metric][$mode] = $samples[intdiv(count($samples), 2)];
		}
	}
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (isset($options['output'])) { file_put_contents($options['output'], $json); }
echo $json;
