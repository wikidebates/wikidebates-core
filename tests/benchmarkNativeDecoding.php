<?php

/** Focused decoding benchmark; no parser, database, network, or site configuration. */
require __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\DebatePHP\DecodedReferences;
use MediaWiki\Extension\DebatePHP\Table;

$options = getopt('', ['baseline:', 'output:']);
$baseline = realpath($options['baseline'] ?? '');
check(isset($options['baseline']) && $baseline !== false && $baseline !== realpath(dirname(__DIR__))
	&& is_file($baseline . '/debatephp/src/SerializedData.php'), 'Pass --baseline=/path/to/before-checkout');
$temporary = sys_get_temp_dir() . '/wikidebates-decoding-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
register_shutdown_function(static function() use ($temporary): void {
	foreach (glob($temporary . '/*.php') as $file) { unlink($file); }
	rmdir($temporary);
});
foreach (['SerializedData', 'Table', 'Lua'] as $name) {
	$source = str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace WikidebatesDecodingBefore;', file_get_contents($baseline . '/debatephp/src/' . $name . '.php'));
	file_put_contents($temporary . '/' . $name . '.php', $source);
	require $temporary . '/' . $name . '.php';
}
$formats = [
	'bibliography' => ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'],
	'webliography' => ['page', 'site', 'link', 'authors', 'date', 'warnings'],
	'videography' => ['title', 'link', 'authors', 'warnings'],
	'quotes' => ['quote', 'authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']
];
$records = [];
foreach ($formats as $format => $fields) {
	$count = $format === 'bibliography' ? 40 : 12;
	$separator = $format === 'quotes' ? '⟭⟭⟭' : '⟭';
	$fieldSeparator = $format === 'quotes' ? '⟬⟬⟬' : '⟬';
	$rows = [];
	for ($i = 0; $i < $count; $i++) {
		$rows[] = implode($fieldSeparator, array_map(static fn($field) => $field . ' é ' . $i, $fields));
	}
	$records[$format] = implode($separator, $rows) . $separator;
}
$oldParse = static fn($format, $raw) => $format === 'quotes' ? \WikidebatesDecodingBefore\SerializedData::quotes($raw)
	: \WikidebatesDecodingBefore\SerializedData::records($raw, '⟭', '⟬', $formats[$format]);
$scenarios = [];
$argument = [];
foreach ($records as $format => $raw) { $argument[] = [$format, $raw]; }
$scenarios['argument'] = array_merge($argument, $argument);
$debate = [];
foreach (['bibliography', 'webliography', 'videography'] as $format) {
	for ($side = 0; $side < 3; $side++) { $debate[] = [$format, $records[$format]]; }
	$debate[] = [$format, implode('⟭', array_fill(0, 3, $records[$format]))];
}
$scenarios['debate'] = $debate;
$plain = static function($value) use (&$plain) {
	return $value instanceof Table || $value instanceof \WikidebatesDecodingBefore\Table ? array_map($plain, $value->values) : $value;
};
$report = ['php' => PHP_VERSION, 'baseline_source_sha256' => hash_file('sha256', $baseline . '/debatephp/src/SerializedData.php'),
	'scope' => 'Pure per-render reference decoding only; no HTML rendering, database, or whole-page speed claim',
	'repeats' => 31, 'iterations_per_sample' => 20, 'scenarios' => []];
$cpu = static function(): float {
	$usage = getrusage();
	return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
		+ ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
};
mt_srand(803);
foreach ($scenarios as $name => $requests) {
	$decoder = new DecodedReferences;
	foreach ($requests as [$format, $raw]) { same($plain($oldParse($format, $raw)), $plain($decoder->parse($format, $raw)), 'Old/new records exactly equal'); }
	$report['scenarios'][$name]['decoding'] = $decoder->statistics();
	$operations = [
		'before' => static function() use ($requests, $oldParse): void {
			foreach ($requests as [$format, $raw]) { $oldParse($format, $raw); }
		},
		'after' => static function() use ($requests): void {
			$decoder = new DecodedReferences;
			foreach ($requests as [$format, $raw]) { $decoder->parse($format, $raw); }
		}
	];
	foreach ($operations as $operation) { $operation(); }
	foreach ($operations as $mode => $operation) {
		$memory = memory_get_usage(); memory_reset_peak_usage();
		$operation();
		$report['scenarios'][$name]['peak_extra_bytes'][$mode] = max(0, memory_get_peak_usage() - $memory);
	}
	$samples = [];
	$cpuSamples = [];
	for ($repeat = 0; $repeat < $report['repeats']; $repeat++) {
		$order = ['before', 'after']; shuffle($order);
		foreach ($order as $mode) {
			$cpuStart = $cpu();
			$start = hrtime(true);
			for ($iteration = 0; $iteration < $report['iterations_per_sample']; $iteration++) { $operations[$mode](); }
			$samples[$mode][] = (hrtime(true) - $start) / 1e6 / $report['iterations_per_sample'];
			$cpuSamples[$mode][] = ($cpu() - $cpuStart) / $report['iterations_per_sample'];
		}
	}
	foreach ($samples as $mode => $values) {
		$sorted = $values; sort($sorted);
		$report['scenarios'][$name][$mode] = ['samples_ms' => $values, 'median_ms' => $sorted[15], 'p95_ms' => $sorted[29]];
		$sortedCpu = $cpuSamples[$mode]; sort($sortedCpu);
		$report['scenarios'][$name][$mode]['cpu_median_ms'] = $sortedCpu[15];
		$report['scenarios'][$name][$mode]['cpu_p95_ms'] = $sortedCpu[29];
	}
}
$json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (isset($options['output'])) { file_put_contents($options['output'], $json . "\n"); }
echo $json . "\n";
