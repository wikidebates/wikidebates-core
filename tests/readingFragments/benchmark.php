<?php
// Run on a freshly initialized disposable lab, once per checkout.
require __DIR__ . '/http.php';
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) { throw new RuntimeException('Disposable lab required'); }
$state = $root . '/state';
$server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'output_buffering=0',
	'-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/bench-server.log', 'a'], ['file', $state . '/bench-server.log', 'a']], $pipes);
fclose($pipes[0]);
function sample(): array {
	$pipeline = countLines('pipeline.log');
	$r = request(['api' => 1, 'action' => 'wikidebatesreading', 'page' => 'Cache study argument',
		'useskin' => 'minerva', 'format' => 'json', 'formatversion' => 2], true);
	$d = json_decode($r['body'], true)['wikidebatesreading'] ?? null;
	if (!$d || !str_contains(html_entity_decode($d['html']), 'Une référence réelle.')) { throw new RuntimeException('Missing reference content'); }
	$rows = file(getenv('WD_MINERVA_STUDY_ROOT') . '/state/resources.jsonl');
	$resource = json_decode(end($rows), true);
	return ['ms' => $r['totalMs'], 'cpu_us' => $resource['cpu_us'],
		'peak_bytes' => $resource['peak_bytes'], 'pipelines' => countLines('pipeline.log') - $pipeline, 'source' => $d['source']];
}
try {
	for ($i = 0; $i < 50; $i++) {
		$s = @fsockopen('127.0.0.1', 8080, $errno, $error, .1);
		if ($s) { fclose($s); break; } usleep(100000);
	}
	operation('purge');
	// Warm parser cache and both real full-page sources before timing.
	request(['title' => 'Cache_study_argument'], false);
	request(['title' => 'Cache_study_argument'], true);
	$report = ['timestamp' => gmdate(DATE_ATOM), 'samples' => []];
	$new = is_file(dirname(__DIR__, 2) . '/src/Reading/FileSources.php');
	foreach ($new ? ['vector-file', 'minerva-file', 'parser', 'fragment-cache'] : ['parser', 'fragment-cache'] as $mode) {
		if ($mode === 'minerva-file' || $mode === 'parser') { operation('clear-vector'); }
		if ($mode === 'parser') { touch($state . '/minerva-disabled'); }
		for ($i = 0; $i < 16; $i++) {
			if ($mode !== 'fragment-cache') { operation('clear-fragments'); }
			$r = sample();
			if ($r['source'] !== $mode) { throw new RuntimeException('Expected ' . $mode . ', got ' . $r['source']); }
			if ($i) { $report['samples'][$mode][] = $r; }
		}
	}
	file_put_contents($state . '/benchmark.json', json_encode($report, JSON_PRETTY_PRINT) . "\n");
	echo "Benchmark complete\n";
} finally { @unlink($state . '/minerva-disabled'); proc_terminate($server); proc_close($server); }
