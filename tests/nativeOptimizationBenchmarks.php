<?php

/** Paired, shuffled, warm parser benchmarks with the genuine SMW stack. */
$repeats = max(5, (int)(getenv('WD_NATIVE_BENCHMARK_REPEATS') ?: 15));
$scenarios = [];
foreach (require __DIR__ . '/nativeOptimizationCases.php' as $case) {
	if (str_contains($case['name'], 'rich native')) { $scenarios[$case['name']] = $case; }
}
foreach (['en', 'fr'] as $lang) {
	$scenarios['parents ' . $lang] = ['title' => 'WD FS related argument',
		'text' => '{{#argument:render|lang=' . $lang . '|summary=Summary|creation-date=2026-01-01}}'];
}
$scenarios['initialization'] = ['title' => 'WD FS orphan initialization',
	'text' => '{{#argument:render|lang=en|summary=Summary|initialization=Pro argument@42|creation-date=2020-01-01}}'];
$scenarios['cards'] = ['title' => 'WD FS related argument',
	'text' => '{{#argument:render|lang=en|summary=Summary|dedicated-debate=WD FS parent debate en|creation-date=2026-01-01}}'];
$benchmark = ['repeats' => $repeats, 'samples_ms' => [], 'profiles' => [], 'profile_samples_ms' => []];
$withoutDurations = static function(array $value) use (&$withoutDurations): array {
	unset($value['milliseconds'], $value['total_ms'], $value['render_other_ms'], $value['max_ms'], $value['preparation']);
	foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = $withoutDurations($item); } }
	return $value;
};
$benchmark['effect_profile_comparisons'] = 0;
mt_srand(803);
foreach ($scenarios as $name => $case) {
	foreach (['before-reference', 'before-optimized', 'reference', 'optimized'] as $mode) {
		fsRender($case['title'], $case['text'], $mode);
		$plain = fsRender($case['title'], $case['text'], $mode);
		$profiled = fsRender($case['title'], $case['text'], $mode, true);
		$benchmark['profiles'][$name][$mode] = $profiled['profiles'];
		unset($plain['milliseconds'], $profiled['milliseconds'], $plain['profiles'], $profiled['profiles']);
		same($plain, $profiled, 'Profiling cannot change rendered output or properties: ' . $name . '/' . $mode);
	}
	foreach (['reference', 'optimized'] as $mode) {
		same($withoutDurations($benchmark['profiles'][$name]['before-' . $mode]), $withoutDurations($benchmark['profiles'][$name][$mode]),
			'Native optimization preserves semantic query/write counts, cache hits, messages and preprocessing effects: ' . $name . '/' . $mode);
		$benchmark['effect_profile_comparisons']++;
	}
	for ($repeat = 0; $repeat < $repeats; $repeat++) {
		$modes = ['before-reference', 'before-optimized', 'reference', 'optimized'];
		shuffle($modes);
		foreach ($modes as $mode) {
			$benchmark['samples_ms'][$name][$mode][] = fsRender($case['title'], $case['text'], $mode)['milliseconds'];
		}
	}
	for ($repeat = 0; $repeat < $repeats; $repeat++) {
		$modes = ['reference', 'optimized']; shuffle($modes);
		foreach ($modes as $mode) {
			$benchmark['profile_samples_ms'][$name][$mode][] = fsRender($case['title'], $case['text'], $mode, true)['milliseconds'];
		}
	}
}
$origins = [];
foreach ($benchmark['profiles'] as $modes) {
	foreach (['reference', 'optimized'] as $mode) {
		foreach ($modes[$mode] as $event) {
			foreach ($event['semantic'] as $origin => $phases) { $origins[$origin] = true; }
			check(isset($event['preparation']), 'Real renderer emits CSV observations when profiling is enabled');
			if (isset($event['preparation'])) {
				$p = $event['preparation'];
				check($p['calls'] === $p['hits'] + $p['misses'] + $p['bypassed'] + $p['failures'], 'CSV profile accounts for every call');
				check($p['entries'] <= 256 && $p['admission_entries'] <= 256 && $p['peak_payload_bytes'] <= 131072, 'Real CSV cache respects both limits');
				foreach ($p as $value) { check(is_int($value) && $value >= 0, 'CSV profile contains only nonnegative integers'); }
			}
			check(!str_contains(json_encode($event), 'https://example.test'), 'Semantic profile omits content URLs');
		}
	}
}
foreach (['debate-parents', 'argument-parents', 'breadcrumb', 'cards', 'initialization', 'writes'] as $origin) {
	check(isset($origins[$origin]), 'Real profile observes origin: ' . $origin);
}
$report['native_optimization'] = $benchmark;
echo "Paired native optimization benchmarks and genuine SMW profile assertions: passed\n";
