<?php

require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/argument_context.php';

use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, JsonData, NativeJsonContext, NativeSemanticContext, RenderData, SemanticReadResult, Table, TextEscaper};

set_error_handler(static function($severity, $message, $file, $line): void { throw new ErrorException($message, 0, $severity, $file, $line); });
$assertions = 0;
$equal = static function($a, $b, string $label) use (&$assertions): void { same($a, $b, $label); $assertions++; };
$legacyValues = static function($value) use (&$legacyValues) {
	if (!is_array($value)) { return $value; }
	array_unshift($value, ''); unset($value[0]);
	return new Table(array_map($legacyValues, $value));
};
mt_srand(806);
$keys = [0, 1, 2, 7, -1, '01', '1e2', '2x', 'name', 'fulltext', 'page', '0.0', 'é'];
$make = static function(int $depth = 0) use (&$make, $keys) {
	if ($depth > 2 || mt_rand(0, 2) === 0) { return [null, false, true, 0, 1.25, '', '0', 'é </script> {{x}}'][mt_rand(0, 7)]; }
	$out = [];
	for ($i = 0, $n = mt_rand(0, 12); $i < $n; $i++) { $out[$keys[mt_rand(0, count($keys) - 1)]] = $make($depth + 1); }
	return $out;
};
for ($i = 0; $i < 2500; $i++) {
	$data = $make();
	$equal(Table::unwrap($legacyValues($data)), SemanticReadResult::values($data), 'SMW numeric reindexing, null and named fields');
	$equal(TextEscaper::jsonValue(Table::wrap($data)), JsonData::value($data), 'Internal JSON native/Table equivalence');
	if (is_array($data)) {
		$equal(iterator_to_array((new Table($data))->sequence()), iterator_to_array(SemanticReadResult::sequence($data)), 'First-hole/nil sequence semantics');
		$equal(json_encode(TextEscaper::jsonValue(Table::wrap($data)), JSON_THROW_ON_ERROR), json_encode(JsonData::value($data), JSON_THROW_ON_ERROR), 'Exact JSON byte/key order');
	}
}
$data = ['@graph' => [1 => new Table(['name' => 'é', 'values' => new Table([1 => '0', 2 => false])])]];
$originalData = Table::unwrap(Table::wrap($data));
$equal(TextEscaper::jsonValue(Table::wrap($data)), JsonData::value($data), 'Mixed internal native/legacy graph');
$equal($originalData, Table::unwrap(Table::wrap($data)), 'JSON leaves remain unmodified');
$cache = new RenderData;
$first = $cache->csvValues(' a,0,é,,b '); $first[1] = 'mutated'; unset($first[2]);
$legacy = $cache->csv(' a,0,é,,b '); $legacy[1] = 'legacy mutation';
$equal([1 => 'a', 2 => '0', 3 => 'é', 4 => 'b'], $cache->csvValues(' a,0,é,,b '), 'Native and legacy CSV views cannot poison cache');
$equal(2, $cache->statistics()['hits'], 'Both CSV APIs share one cache');
$cache->csvValues('empty', 'no match');
try { $cache->csvValues('x', ''); throw new RuntimeException('Expected ValueError'); }
catch (ValueError $e) { $equal(1, $cache->statistics()['failures'], 'Native CSV failure counted'); }

require_once __DIR__ . '/native_context.php';
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/argument-cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
	$legacy = new ArgumentTestContext($case); $native = new NativeReadTestContext($case);
	$equal((new ArgumentReferenceRenderer($legacy))->render(), (new ArgumentReferenceRenderer($native))->render(), 'Optional native context exact HTML/JSON: ' . $case['title']);
	$equal($legacy->effects, $native->effects, 'Optional native context exact effects');
}
$case = ['title' => 'An isolated orphan', 'args' => ['lang' => 'en', 'summary' => 'Summary', 'creation-date' => '2026-01-01']];
$old = new ArgumentTestContext($case); $native = new NativeReadTestContext($case); $native->certify = true;
$renderer = new ArgumentReferenceRenderer($native);
$equal((new ArgumentReferenceRenderer($old))->render(), $renderer->render(), 'Certified empty parents preserve output');
$equal(4, count($old->effects['ask']) - count($native->effects['ask']), 'Two fallback reads skipped for each proven empty parent union');
$equal(['debate-parents' => 2, 'argument-parents' => 2], $native->skipped, 'Only certified fallbacks recorded');
$beforeCalls = count($native->effects['ask']); $renderer->render();
$equal($beforeCalls, count($native->effects['ask']) - $beforeCalls, 'Native semantic cache resets for every render');
$native->failJson = true;
try { $renderer->render(); throw new LogicException('Expected JSON failure'); }
catch (RuntimeException $e) { $equal('Synthetic JSON insertion failure', $e->getMessage(), 'JSON failures propagate'); }
$equal(false, (new ReflectionProperty($renderer, 'nativeJson'))->getValue($renderer), 'Native JSON construction mode is restored even after failure');
$native->failJson = false;
$probe = new ReflectionMethod($renderer, 'containsTitle');
foreach ([['label' => 'Target'], [1 => 'no', 3 => 'Target'], [1 => null, 2 => 'Target'], ['fulltext' => 'Target'], [1 => false, 2 => 'Target']] as $value) {
	$equal($probe->invoke($renderer, Table::wrap($value), 'Target'), $probe->invoke($renderer, $value, 'Target'), 'Native title lookup matches legacy table');
}
echo "$assertions native-read assertions: semantic indexing, JSON bytes, CSV isolation, optional adapters and certified fallback/cache lifetimes\n";
