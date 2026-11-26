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

// The Lua bridge must respect one-based inclusive bounds, including an empty range.
$concatValues = new Table([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']);
$concat = \MediaWiki\Extension\DebatePHP\Lua::class;
$equal('A, B, C, D', $concat::concat($concatValues, ', '), 'Unbounded concatenation');
$equal('A, B', $concat::concat($concatValues, ', ', 1, 2), 'Exclude trailing values');
$equal('B, C', $concat::concat($concatValues, ', ', 2, 3), 'Inclusive range');
$equal('C, D', $concat::concat($concatValues, ', ', 3), 'Default end');
$equal('', $concat::concat($concatValues, ', ', 3, 2), 'Empty range');
$equal('D', $concat::concat($concatValues, ', ', 4, 4), 'Single value range');
$equal('A, B, C, D', $concat::concat($concatValues, ', '), 'Input table remains unchanged');

// Check the final join, independently of title deduplication, for zero to four cards.
$join = new ReflectionMethod(ArgumentReferenceRenderer::class, 'joinCards');
$joinRenderer = new ArgumentReferenceRenderer(new ArgumentTestContext(['title' => 'Child', 'args' => ['lang' => 'fr']]));
foreach ([
	'' => [],
	'A' => [1 => 'A'],
	'A&#32;et&#32;B' => [1 => 'A', 2 => 'B'],
	'A, B&#32;et&#32;C' => [1 => 'A', 2 => 'B', 3 => 'C'],
	'A, B, C&#32;et&#32;D' => [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']
] as $expected => $cards) {
	$equal($expected, $join->invoke($joinRenderer, new Table($cards), 'fr'), 'French list with ' . count($cards) . ' cards');
}

// Equivalent raw SMW forms must produce one link per relation, preserving order.
$parentContext = new class(['title' => 'Child', 'args' => ['lang' => 'en']]) extends ArgumentTestContext {
	public function smwAsk(Table $query): ?Table {
		$fields = implode("\n", $query->values);
		if (str_contains($fields, '=pro')) {
			return Table::wrap([
				1 => ['fulltext' => 'Debate A', 'pro' => 'Child', 'con' => 'Child'],
				2 => ['fulltext' => 'Debate_A', 'pro' => 'Child'],
				3 => ['fulltext' => 'Debate B', 'pro' => 'Child'],
				4 => ['fulltext' => 'Debate C', 'pro' => 'Child']
			]);
		}
		if (str_contains($fields, '=justif')) {
			return Table::wrap([
				1 => ['fulltext' => 'Parent &amp; A', 'justif' => 'Child', 'obj' => 'Child'],
				2 => ['fulltext' => 'Parent & A', 'justif' => 'Child'],
				3 => ['fulltext' => 'Parent B', 'justif' => 'Child'],
				4 => ['fulltext' => 'Parent C', 'justif' => 'Child']
			]);
		}
		if (str_contains($fields, '=chain')) {
			return Table::wrap([
				1 => ['chain' => 'Debate A⟭Parent A'],
				2 => ['chain' => 'Debate_A⟭Parent B'],
				3 => ['chain' => 'Debate &amp; B⟭Parent A'],
				4 => ['chain' => 'Debate & B⟭Parent B'],
				5 => ['chain' => 'Debate C⟭Parent C']
			]);
		}
		return null;
	}
};
foreach ([ArgumentReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\ArgumentRenderer::class] as $class) {
	$rendered = (new $class($parentContext))->render();
	preg_match_all('~<div class="bandeau-en-tete searchaux wk-icon-before [^"]+">.*?</div>~s', $rendered, $matches);
	$parentBanners = array_values(array_filter($matches[0], static fn($banner) => str_contains($banner, 'class="hover-map"')));
	$equal(5, count($parentBanners), 'All five parent relation banners remain present');
	$expected = [
		['Debate A', 'Debate & B', 'Debate C'],
		['Debate A', 'Debate B', 'Debate C'],
		['Debate A'],
		['Parent & A', 'Parent B', 'Parent C'],
		['Parent & A']
	];
	foreach ($parentBanners as $i => $banner) {
		preg_match_all('~\[\[([^\]]+)\]\]~', $banner, $links);
		$equal($expected[$i], $links[1], 'Unique ordered parent links in banner ' . $i);
	}
}

echo "$assertions native-read assertions: semantic indexing, JSON bytes, CSV isolation, optional adapters and certified fallback/cache lifetimes\n";
