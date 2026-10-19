<?php

/** Exact baseline helpers and mocked renderers; real SMW/page timings are measured separately. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../debatephp/tests/native_context.php';
require __DIR__ . '/nativeCandidatePrototypes.php';

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

$context = file_get_contents($baseline . '/debatephp/tests/native_context.php');
$context = preg_replace("/require_once __DIR__[^;]+;/", '', $context);
$context = str_replace('<?php', "<?php\nnamespace NativeReadBefore;", $context);
$context = str_replace('MediaWiki\\Extension\\DebatePHP', 'NativeReadBefore', $context);
file_put_contents($temporary . '/src/NativeReadTestContext.php', $context);
$operations = [];

$rawItems = implode('⟭', array_map(static fn($i) => 'Page ' . $i . '⟬Displayed ' . $i . '⟬review', range(1, 100))) . '⟭';
$intro = implode('⟭⟭⟭', array_map(static fn($i) => 'Heading ' . $i . '⟬⟬⟬Content ' . $i . '⟬⟬⟬', range(1, 40))) . '⟭⟭⟭';
$case = ['title' => 'Measured candidate', 'smw' => false, 'args' => ['lang' => 'en', 'summary' => 'Summary', 'creation-date' => '2026-01-01', 'pro-arguments' => $rawItems, 'con-arguments' => $rawItems, 'justifications' => $rawItems, 'objections' => $rawItems, 'introduction' => $intro]];
foreach (['before' => ['NativeReadBefore\\', new \NativeReadBefore\NativeReadTestContext($case)],
	'after' => ['MediaWiki\\Extension\\DebatePHP\\', new NativeReadTestContext($case)]] as $mode => [$prefix, $context]) {
	$table = $prefix . 'Table'; $decoder = $prefix . 'DecodedReferences'; $serialized = $prefix . 'SerializedData';
	$debateClass = $prefix . 'ReferenceRenderer'; $argumentClass = $prefix . 'ArgumentReferenceRenderer';
	$debate = new $debateClass($context); $argument = new $argumentClass($context);
	$items = $serialized::argumentItems($rawItems, false);
	foreach (['debate' => $debate, 'argument' => $argument] as $kind => $renderer) {
		$m = new ReflectionMethod($renderer, 'wkBuildArgumentData');
		$operations['argument-data-' . $kind . '-100'][$mode] = static fn() => $m->invoke($renderer, $items, 'en', 'pro');
		$operations['mocked-rich-' . $kind][$mode] = static function() use ($renderer, $context): string {
			$context->effects = ['preprocess' => [], 'ask' => [], 'set' => [], 'seo' => [], 'variables' => []];
			return $renderer->render();
		};
	}
	$m = new ReflectionMethod($debate, 'wkRenderIntroSubsectionsBlock');
	$pv = $context->pageVars(); $cats = new $table;
	$operations['introduction-html-40'][$mode] = static fn() => $m->invoke($debate, $pv, 'en', $intro, $cats);
	$m = new ReflectionMethod($argument, 'normalizeTitleForCard');
	foreach (['plain' => 'A simple title', 'encoded' => ': [[A &amp;quot;title&amp;quot;|label]]'] as $shape => $title) {
		$operations['title-' . $shape . '-100'][$mode] = static function() use ($m, $argument, $title): string {
			$out = ''; for ($i = 0; $i < 100; $i++) { $out .= $m->invoke($argument, $title); } return $out;
		};
	}
	$operations['title-cold-unique-encoded-100'][$mode] = static function() use ($m, $argumentClass, $context): string {
		$r = new $argumentClass($context);
		$out = ''; for ($i = 0; $i < 100; $i++) { $out .= $m->invoke($r, ': [[Unique ' . $i . ' &amp;quot;|label]]'); } return $out;
	};
	foreach (['normal' => 40, 'pressure' => 4000] as $shape => $count) {
		$operations['references-' . $shape][$mode] = static function() use ($decoder, $count): array {
			$d = new $decoder; $out = [];
			for ($i = 0; $i < $count; $i++) {
				$out = $d->values('bibliography', 'Author ' . $i . '⟬Title ' . $i . '⟭');
			}
			// Returning the decoder keeps its retained cache in the memory probe.
			return ['last' => $out, 'decoder' => $d];
		};
	}
	foreach ([40, 4000] as $count) {
		$raw = implode('⟭', array_map(static fn($i) => 'Author ' . $i . '⟬Article ' . $i, range(1, $count))) . '⟭';
		$operations['references-reused-list-' . $count][$mode] = static function() use ($decoder, $raw): array {
			$d = new $decoder;
			$d->values('bibliography', $raw);
			return ['last' => $d->values('bibliography', $raw), 'decoder' => $d];
		};
	}
}
$operations['prototype-arguments-native-100'] = [
	'before' => static fn() => \NativeReadBefore\SerializedData::argumentItems($rawItems, false),
	'after' => static fn() => NativeCandidatePrototypes::arguments($rawItems, false)
];
$operations['prototype-introductions-native-40'] = [
	'before' => static fn() => \NativeReadBefore\SerializedData::records($intro, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true),
	'after' => static fn() => NativeCandidatePrototypes::introductions($intro)
];
$operations['prototype-arguments-compatibility-roundtrip-100'] = [
	'before' => $operations['prototype-arguments-native-100']['before'],
	'after' => static fn() => Table::wrap(NativeCandidatePrototypes::arguments($rawItems, false))
];
$operations['prototype-introductions-compatibility-roundtrip-40'] = [
	'before' => $operations['prototype-introductions-native-40']['before'],
	'after' => static fn() => Table::wrap(NativeCandidatePrototypes::introductions($intro))
];
$plain = static function($value) use (&$plain) {
	if ($value instanceof Table || $value instanceof \NativeReadBefore\Table) { $value = $value->values; }
	if ($value instanceof \MediaWiki\Extension\DebatePHP\DecodedReferences || $value instanceof \NativeReadBefore\DecodedReferences) { return null; }
	return is_array($value) ? array_map($plain, $value) : $value;
};
$report = ['php' => PHP_VERSION, 'baseline_commit' => trim(shell_exec('git -C ' . escapeshellarg($baseline) . ' rev-parse HEAD')),
	'scope' => 'Pure/helper and mocked renderer probes; real SMW and page timings are measured separately', 'repeats' => 31, 'iterations' => 10, 'scenarios' => []];
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
