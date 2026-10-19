<?php

/** Optional diagnostic: real parsing with natural versus pre-render cyclic collection. */
if (!getenv('WD_OPTIMIZATION_BASELINE') || !getenv('WD_FOCUSED_BENCHMARK_OUTPUT')) {
	fwrite(STDERR, "Set WD_OPTIMIZATION_BASELINE and WD_FOCUSED_BENCHMARK_OUTPUT in the disposable full-stack laboratory\n");
	exit(1);
}
// Keep the full correctness matrix; the diagnostic runs before its database is dropped.
putenv('WD_NATIVE_BENCHMARK_REPEATS=5');
require __DIR__ . '/fullstack.php';
$case = null;
foreach (require __DIR__ . '/nativeOptimizationCases.php' as $candidate) {
	if ($candidate['name'] === 'measured lists debate en') { $case = $candidate; break; }
}
check(is_array($case), 'Focused case exists');
$out = ['php' => PHP_VERSION, 'case' => $case['name'], 'repeats' => 41,
	'baseline_commit' => trim(shell_exec('git -C ' . escapeshellarg(realpath(getenv('WD_OPTIMIZATION_BASELINE'))) . ' rev-parse HEAD')),
	'scope' => 'Warm CLI parsing in an isolated real SMW store; collection before render is diagnostic, not a production change', 'samples' => []];
$expected = null;
mt_srand(811);
foreach (['natural', 'collect-before-render'] as $phase) {
	gc_collect_cycles();
	for ($i = 0; $i < $out['repeats']; $i++) {
		$modes = ['before-reference', 'before-optimized', 'reference', 'optimized']; shuffle($modes);
		foreach ($modes as $mode) {
			if ($phase === 'collect-before-render') { gc_collect_cycles(); }
			$memory = memory_get_usage(); $gc = $after = null;
			$value = fsRender($case['title'], $case['text'], $mode, false,
				static function(array $before, array $end) use (&$gc, &$after): void { $gc = $before; $after = $end; });
			$out['samples'][$phase][$mode][] = ['milliseconds' => $value['milliseconds'],
				'gc_runs' => $after['runs'] - $gc['runs'], 'gc_collected' => $after['collected'] - $gc['collected'],
				'gc_collector_ms' => isset($gc['collector_time']) ? 1000 * ($after['collector_time'] - $gc['collector_time']) : null,
				'roots_before' => $gc['roots'], 'roots_after' => $after['roots'], 'threshold' => $gc['threshold'],
				'memory_before' => $memory, 'memory_after' => memory_get_usage()];
			unset($value['milliseconds'], $value['profiles']);
			$expected ??= $value;
			same($expected, $value, 'Collection policy and source version cannot change rendered bytes, semantics or dependencies');
		}
	}
}
foreach ($out['samples'] as $phase => $modes) {
	foreach ($modes as $mode => $samples) {
		$times = array_column($samples, 'milliseconds'); sort($times);
		$collected = array_filter($samples, static fn($v) => $v['gc_runs'] > 0);
		$out['summary'][$phase][$mode] = ['median_ms' => $times[intdiv(count($times), 2)],
			'p95_ms' => $times[(int)ceil(count($times) * .95) - 1], 'gc_samples' => count($collected),
			'collector_ms' => array_sum(array_column($samples, 'gc_collector_ms'))];
	}
}
$out['outputs_equal'] = true;
file_put_contents(getenv('WD_FOCUSED_BENCHMARK_OUTPUT'), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo "Focused native parsing and cyclic collection diagnostic: passed\n";
