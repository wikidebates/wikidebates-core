<?php

/** Exact baseline helpers and mocked renderers; real SMW/page timings are measured separately. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../debatephp/tests/native_context.php';

use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, JsonData, ReferenceRenderer, RenderData, SemanticReadResult, Table, TextEscaper};

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
	file_put_contents($temporary . '/src/' . basename($file), str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace NativeReadBefore;', file_get_contents($file)));
}
spl_autoload_register(static function($class) use ($temporary): void {
	if (str_starts_with($class, 'NativeReadBefore\\')) { require_once $temporary . '/src/' . substr($class, strlen('NativeReadBefore\\')) . '.php'; }
});
$context = file_get_contents($baseline . '/debatephp/tests/argument_context.php');
$context = str_replace("require __DIR__ . '/bootstrap.php';", '', $context);
$context = str_replace('<?php', "<?php\nnamespace NativeReadBefore;", $context);
$context = str_replace('MediaWiki\\Extension\\DebatePHP', 'NativeReadBefore', $context);
$context = str_replace("new I18n((require __DIR__ . '/../resources/I18nData.php')['data'], true)", 'I18n::bundled(true)', $context);
file_put_contents($temporary . '/src/ArgumentTestContext.php', $context);
$operations = [];
$rows = [];
for ($i = 0; $i < 100; $i++) {
	$rows[] = [1 => 'Page ' . $i, 'pro' => ['[[Target|label]]', '0'], 'number' => 1.25, 'boolean' => false, 'empty' => null, 'text' => 'é'];
}
// Frozen former callback, also checked against SemanticReadResult in randomized tests.
check(str_contains(file_get_contents($baseline . '/debatephp/src/MediaWikiContext.php'), "array_unshift($" . "value, ''); unset($" . "value[0]);"), 'Reviewed baseline SMW conversion required');
$convertBefore = static function($value) use (&$convertBefore) {
	if (!is_array($value)) { return $value; }
	array_unshift($value, ''); unset($value[0]);
	return new \NativeReadBefore\Table(array_map($convertBefore, $value));
};
$operations['semantic-result-conversion-100'] = [
	'before' => static fn() => $convertBefore($rows),
	'after' => static fn() => SemanticReadResult::values($rows)
];
$requests = [];
for ($i = 0; $i < 40; $i++) { $requests[] = implode(',', array_fill(0, 20, ' item ' . $i)); }
$requests = array_merge($requests, $requests, $requests, $requests);
$operations['csv-read-only-160'] = [
	'before' => static function() use ($requests): array {
		$d = new \NativeReadBefore\RenderData; $out = [];
		foreach ($requests as $text) { foreach ($d->csv($text)->sequence() as $item) { $out[] = $item; } }
		return $out;
	},
	'after' => static function() use ($requests): array {
		$d = new RenderData; $out = [];
		foreach ($requests as $text) { foreach ($d->csvValues($text) as $item) { $out[] = $item; } }
		return $out;
	}
];
$citations = [];
for ($i = 1; $i <= 40; $i++) { $citations[$i] = ['@type' => 'Book', 'name' => 'Book é ' . $i, 'author' => [1 => ['@type' => 'Person', 'name' => 'A </script> {{x}}']]]; }
$graph = ['@context' => 'https://schema.org', '@graph' => [1 => ['@type' => 'Claim', 'citation' => $citations]]];
$operations['json-construction-encoding-40'] = [
	'before' => static function() use ($graph): string {
		$json = json_encode(\NativeReadBefore\TextEscaper::jsonValue(\NativeReadBefore\Table::wrap($graph)), JSON_THROW_ON_ERROR);
		json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		return str_replace(['<', '>', '&'], ['\\u003C', '\\u003E', '\\u0026'], $json);
	},
	'after' => static function() use ($graph): string {
		$json = json_encode(JsonData::value($graph), JSON_THROW_ON_ERROR);
		return str_replace(['<', '>', '&'], ['\\u003C', '\\u003E', '\\u0026'], $json);
	}
];
$keywords = implode(',', array_map(static fn($i) => 'keyword ' . $i, range(1, 40)));
$sections = implode(',', array_map(static fn($i) => 'section ' . $i, range(1, 40)));
$related = implode('⟭', array_map(static fn($i) => 'debate ' . $i, range(1, 40)));
$case = ['title' => 'Benchmark native reads', 'smw' => false, 'args' => ['lang' => 'en', 'summary' => 'Summary', 'keywords' => $keywords, 'sections' => $sections, 'related-debates' => $related, 'creation-date' => '2026-01-01']];
foreach (['before' => ['NativeReadBefore\\', new \NativeReadBefore\ArgumentTestContext($case)],
	'after' => ['MediaWiki\\Extension\\DebatePHP\\', new NativeReadTestContext($case)]] as $mode => [$prefix, $context]) {
	$table = $prefix . 'Table'; $debateClass = $prefix . 'ReferenceRenderer'; $argumentClass = $prefix . 'ArgumentReferenceRenderer';
	$debate = new $debateClass($context); $argument = new $argumentClass($context);
	foreach (['renderKeywords', 'renderConnexes', 'renderRubriques'] as $method) {
		$reflection = new ReflectionMethod($debate, $method);
		$args = new $table($case['args']); $pv = $context->pageVars();
		$operations['html-' . $method . '-40'][$mode] = static function() use ($reflection, $debate, $args, $pv, $table, $method) {
			return $method === 'renderRubriques' ? $reflection->invoke($debate, $args, new $table, 'en') : $reflection->invoke($debate, $args, $pv, new $table, 'en');
		};
	}
	foreach (['debate' => $debate, 'argument' => $argument] as $kind => $renderer) {
		$operations['mocked-full-render-' . $kind][$mode] = static function() use ($renderer, $context): string {
			$context->effects = ['preprocess' => [], 'ask' => [], 'set' => [], 'seo' => [], 'variables' => []];
			return $renderer->render();
		};
	}
}
$plain = static function($value) use (&$plain) {
	if ($value instanceof Table || $value instanceof \NativeReadBefore\Table) { $value = $value->values; }
	return is_array($value) ? array_map($plain, $value) : $value;
};
$report = ['php' => PHP_VERSION, 'baseline_commit' => trim(shell_exec('git -C ' . escapeshellarg($baseline) . ' rev-parse HEAD')),
	'scope' => 'Pure/helper and mocked renderer probes; real SMW and page timings are measured separately', 'repeats' => 31, 'iterations' => 5, 'scenarios' => []];
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
