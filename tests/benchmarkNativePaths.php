<?php

/** Compare the actual previous implementations, including the already-cached decoder/CSV. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../debatephp/tests/argument_context.php';

use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, DecodedReferences, RenderData, Table, TextEscaper};

$options = getopt('', ['baseline:', 'output:']);
$baseline = realpath($options['baseline'] ?? '');
check(isset($options['baseline']) && $baseline !== false && $baseline !== realpath(dirname(__DIR__))
	&& is_file($baseline . '/debatephp/src/RenderData.php'), 'Distinct baseline with existing native caches required');
$temporary = sys_get_temp_dir() . '/wikidebates-paths-' . bin2hex(random_bytes(8));
mkdir($temporary . '/src', 0700, true);
symlink($baseline . '/debatephp/resources', $temporary . '/resources');
register_shutdown_function(static function() use ($temporary): void {
	foreach (glob($temporary . '/src/*.php') as $file) { unlink($file); }
	unlink($temporary . '/resources'); rmdir($temporary . '/src'); rmdir($temporary);
});
foreach (glob($baseline . '/debatephp/src/*.php') as $file) {
	file_put_contents($temporary . '/src/' . basename($file), str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace NativePathsBefore;', file_get_contents($file)));
}
spl_autoload_register(static function($class) use ($temporary): void {
	if (str_starts_with($class, 'NativePathsBefore\\')) { require_once $temporary . '/src/' . substr($class, strlen('NativePathsBefore\\')) . '.php'; }
});
$context = file_get_contents($baseline . '/debatephp/tests/argument_context.php');
$context = str_replace("require __DIR__ . '/bootstrap.php';", '', $context);
$context = str_replace('<?php', "<?php\nnamespace NativePathsBefore;", $context);
$context = str_replace('MediaWiki\\Extension\\DebatePHP', 'NativePathsBefore', $context);
$context = str_replace("new I18n((require __DIR__ . '/../resources/I18nData.php')['data'], true)", 'I18n::bundled(true)', $context);
file_put_contents($temporary . '/src/ArgumentTestContext.php', $context);
$case = ['title' => 'Benchmark', 'smw' => false, 'args' => []];
$before = new \NativePathsBefore\ArgumentReferenceRenderer(new \NativePathsBefore\ArgumentTestContext($case));
$after = new ArgumentReferenceRenderer(new ArgumentTestContext($case));
$oldClass = new ReflectionClass($before); $newClass = new ReflectionClass($after);
$operations = [];
foreach (['before' => [$oldClass, $before, \NativePathsBefore\Table::class], 'after' => [$newClass, $after, Table::class]] as $mode => [$class, $renderer, $table]) {
	$queries = [];
	for ($i = 0; $i < 100; $i++) { $queries[] = new $table([4 => '?Author=a', 1 => '[[Page::' . $i . ']]', 2 => 'limit=200', 8 => 'link=none', 'ignored' => false]); }
	$key = $class->getMethod('smwKeyFromArray');
	$operations['query-keys-100'][$mode] = static function() use ($queries, $key, $renderer): array {
		$out = []; foreach ($queries as $query) { $out[] = $key->invoke($renderer, $query); } return $out;
	};
}
$fields = ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'];
$rows = [];
for ($i = 0; $i < 40; $i++) { $rows[] = implode('⟬', ['Author ' . $i, 'Article é ' . $i, 'Work', '2', '3', 'Chapter', '42', 'Publisher', 'Paris', '2026', 'https://example.test', '']); }
$raw = implode('⟭', $rows);
$oldRecords = (new \NativePathsBefore\DecodedReferences)->parse('bibliography', $raw);
$newRecords = (new DecodedReferences)->values('bibliography', $raw);
foreach (['before' => [$oldClass, $before, \NativePathsBefore\Table::class, $oldRecords->values], 'after' => [$newClass, $after, Table::class, $newRecords]] as $mode => [$class, $renderer, $table, $records]) {
	$render = $class->getMethod('wkRenderOneBiblioLi');
	$pf = new $table(['unknown_author' => '?', 'volume_label' => 'vol', 'issue_label' => 'no']);
	$categories = new $table;
	$operations['html-bibliography-40'][$mode] = static function() use ($records, $render, $renderer, $pf, $categories): array {
		$out = []; foreach ($records as $record) { $out[] = $render->invoke($renderer, $record, null, 'en', $categories, $pf, null, null); } return $out;
	};
}
$operations['records-two-consumers'] = [
	'before' => static function() use ($raw): array { $d = new \NativePathsBefore\DecodedReferences; return [$d->parse('bibliography', $raw), $d->parse('bibliography', $raw)]; },
	'after' => static function() use ($raw): array { $d = new DecodedReferences; return [$d->values('bibliography', $raw), $d->values('bibliography', $raw)]; }
];
$citations = [];
for ($i = 1; $i <= 40; $i++) {
	$citations[$i] = ['@type' => 'Book', 'name' => 'Book ' . $i, 'author' => [1 => ['@type' => 'Person', 'name' => 'Author é']], 'url' => 'https://example.test/' . $i];
}
$graph = ['@context' => 'https://schema.org', '@type' => 'Claim', 'name' => 'Title', 'citation' => $citations];
foreach (['graph' => $graph, 'mixed-fallback' => ['3x' => 'x', 5 => 'five', '01' => ['b' => 'x', 'a' => 'y'], -1 => false, 'name' => 'n']] as $name => $data) {
	$old = \NativePathsBefore\Table::wrap($data); $new = Table::wrap($data);
	$operations['json-' . $name] = [
		'before' => static fn() => \NativePathsBefore\TextEscaper::jsonValue($old),
		'after' => static fn() => TextEscaper::jsonValue($new)
	];
}
$unique = [];
for ($i = 0; $i < 40; $i++) { $unique[] = implode(',', array_fill(0, 20, ' item ' . $i)); }
$large = [];
for ($i = 0; $i < 10; $i++) { $large[] = implode(',', array_fill(0, 200, ' large item ' . $i)); }
foreach (['unique' => $unique, 'four-reads' => array_merge($unique, $unique, $unique, $unique), 'hinted-four-reads' => array_merge($unique, $unique, $unique, $unique), 'single-tokens' => array_fill(0, 160, ' token '),
	'large-unique' => $large, 'large-four-reads' => array_merge($large, $large, $large, $large), 'large-hinted-four-reads' => array_merge($large, $large, $large, $large)] as $name => $requests) {
	$operations['csv-' . $name] = [
		'before' => static function() use ($requests): array {
			$d = new \NativePathsBefore\RenderData; $out = [];
			foreach ($requests as $text) { $out[] = $d->csv($text)->values; } return $out;
		},
		'after' => static function() use ($requests, $name): array {
			$d = new RenderData; $out = [];
			if (str_contains($name, 'hinted')) { $d->expectReuse(array_slice($requests, 0, 40)); }
			foreach ($requests as $text) { $out[] = $d->csv($text)->values; } return $out;
		}
	];
}
$stress = [];
for ($i = 0; $i < 64; $i++) { $stress[] = implode(',', array_fill(0, 200, ' stress ' . $i)); }
foreach (['before' => \NativePathsBefore\RenderData::class, 'after' => RenderData::class] as $mode => $class) {
	$operations['csv-pressure-unique-64'][$mode] = static function() use ($class, $stress): int {
		$d = new $class; $sum = 0;
		foreach ($stress as $text) { $sum += count($d->csv($text)); }
		return $sum;
	};
}
$plain = static function($value) use (&$plain) {
	if ($value instanceof Table || $value instanceof \NativePathsBefore\Table) { $value = $value->values; }
	return is_array($value) ? array_map($plain, $value) : $value;
};
$report = ['php' => PHP_VERSION, 'baseline_commit' => trim(shell_exec('git -C ' . escapeshellarg($baseline) . ' rev-parse HEAD')),
	'scope' => 'Pure helper operations against the actual previous caches; no whole-page performance claim', 'repeats' => 31, 'iterations' => 5, 'scenarios' => []];
$observed = new RenderData;
foreach ($stress as $text) { $observed->csv($text); }
$report['cache_pressure_observation'] = $observed->statistics();
$cpu = static function(): float {
	$u = getrusage(); return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000;
};
mt_srand(805);
foreach ($operations as $name => $modes) {
	same($plain($modes['before']()), $plain($modes['after']()), 'Exact helper equivalence: ' . $name);
	foreach ($modes as $mode => $operation) {
		$memory = memory_get_usage(); memory_reset_peak_usage(); $operation();
		$report['scenarios'][$name]['peak_extra_bytes'][$mode] = memory_get_peak_usage() - $memory;
	}
	for ($sample = 0; $sample < $report['repeats']; $sample++) {
		$order = ['before', 'after']; shuffle($order);
		foreach ($order as $mode) {
			$c = $cpu(); $start = hrtime(true);
			for ($i = 0; $i < $report['iterations']; $i++) { $modes[$mode](); }
			$report['scenarios'][$name]['samples_ms'][$mode][] = (hrtime(true) - $start) / 1e6 / $report['iterations'];
			$report['scenarios'][$name]['cpu_ms'][$mode][] = ($cpu() - $c) / $report['iterations'];
		}
	}
	foreach (['samples_ms', 'cpu_ms'] as $metric) {
		foreach (['before', 'after'] as $mode) { $s = $report['scenarios'][$name][$metric][$mode]; sort($s); $report['scenarios'][$name]['median_' . $metric][$mode] = $s[intdiv(count($s), 2)]; }
	}
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (isset($options['output'])) { file_put_contents($options['output'], $json); }
echo $json;
