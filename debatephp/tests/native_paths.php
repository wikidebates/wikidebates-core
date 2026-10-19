<?php
require __DIR__ . '/../../tests/bootstrap.php';

use MediaWiki\Extension\DebatePHP\{CacheKey, DecodedReferences, Lua, RenderData, Table, TextEscaper};

// Frozen algorithms used before this phase, independent of the native helpers.
$legacyKey = static function($q): string {
	if (!$q instanceof Table) { $s = Lua::str(Lua::truth($q) ? $q : ''); return Lua::str(Lua::len($s)) . ':' . $s; }
	$idx = new Table;
	foreach (Lua::pairs($q) as $key => $_) { if (Lua::type($key) === 'number') { Lua::insert($idx, $key); } }
	Lua::sort($idx); $parts = new Table;
	foreach (Lua::ipairs($idx) as $i => $key) { $s = Lua::str(Lua::truth($q[$key]) ? $q[$key] : ''); $parts[$i] = Lua::str(Lua::len($s)) . ':' . $s; }
	return Lua::concat($parts, "\n");
};
$legacyJson = static function($v) use (&$legacyJson) {
	if (!$v instanceof Table) { return $v; }
	$a = $v->values; ksort($a, SORT_NUMERIC); $next = 1; $sequence = true;
	foreach ($a as $key => &$value) {
		$value = $legacyJson($value);
		if ($sequence) { $sequence = (is_int($key) || (is_string($key) && ctype_digit($key))) && $next++ === (int)$key; }
	} unset($value);
	return $sequence ? array_values($a) : $a;
};
$checks = 0;
foreach ([null, false, true, 0, '0', '', 'é', "\0:\n"] as $input) { same($legacyKey($input), CacheKey::semantic($input), 'Scalar query key'); $checks++; }
mt_srand(805);
$keys = [-5, -1, 0, 1, 2, 3, 20, '', '01', '02', '-0', '0.0', '1e0', '2x', 'name', '@type', 'a', ' 0', "\0", 'é'];
$values = [null, false, true, 0, '0', '', 'name', 'é', "a\nb:c\0", 1.25];
for ($sample = 0; $sample < 2000; $sample++) {
	$a = []; $node = [];
	for ($i = 0; $i < mt_rand(1, 30); $i++) {
		$key = $keys[mt_rand(0, count($keys) - 1)];
		$a[$key] = $values[mt_rand(0, count($values) - 1)];
		$node[$key] = $i % 3 ? $a[$key] : new Table([3 => new Table(['name' => 'é']), 1 => '0', 2 => false]);
	}
	$table = new Table($a);
	same($legacyKey($table), CacheKey::semantic($table), 'Mixed, sparse and numeric-string query keys');
	$table = new Table($node); $before = Table::unwrap($table);
	$expected = $legacyJson($table); $actual = TextEscaper::jsonValue($table);
	same($expected, $actual, 'JSON fallback and native shapes preserve values and ordering');
	same(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($actual, JSON_THROW_ON_ERROR), 'Exact encoded JSON bytes');
	same($before, Table::unwrap($table), 'JSON conversion never mutates shared Tables');
	$checks += 4;
}
foreach ([new Table, new Table([1 => 'é', 2 => '0']), new Table(['@type' => 'Thing', 'name' => new Table(['@id' => 'x'])])] as $table) {
	same($legacyJson($table), TextEscaper::jsonValue($table), 'Dense and mapping fast paths'); $checks++;
}

$decoder = new DecodedReferences;
$native = $decoder->values('bibliography', 'A⟬B⟭C⟬D');
check(is_array($native[1]), 'Native records have no Table wrapper');
$native[1]['authors'] = 'mutation'; $native[3] = ['authors' => 'new'];
$legacy = $decoder->parse('bibliography', 'A⟬B⟭C⟬D');
same('A', $legacy[1]['authors'], 'Native caller cannot poison cache or compatibility records');
$legacy[1]['authors'] = 'legacy mutation';
$combined = $decoder->parse('bibliography', 'A⟬B');
same($legacy[1], $combined[1], 'Legacy records keep shared identity across lists');
same('A', $decoder->values('bibliography', 'A⟬B')[1]['authors'], 'Legacy mutation cannot poison native view');
$checks += 4;

$prepared = new RenderData(3, 80, 60);
$first = $prepared->csv('one,two');
same(1, $prepared->statistics()['entries'], 'Small reusable fields retain immediate admission');
$prepared->csv('one,two');
same(1, $prepared->statistics()['entries'], 'Repeated read reuses the entry');
$first[1] = 'mutated';
same('one', $prepared->csv('one,two')[1], 'Cache read returns a fresh mutable wrapper');
$prepared->expectReuse(['three,four']); $prepared->csv('three,four');
same(2, $prepared->statistics()['entries'], 'Known reuse admits on first read');
for ($i = 0; $i < 200; $i++) { $raw = $i . ',' . str_repeat('x', $i % 35); $prepared->csv($raw); $prepared->csv($raw); }
$prepared->csv(str_repeat('huge,', 100)); $prepared->csv("\0 a\0"); $prepared->csv('');
try { $prepared->csv('a', ''); throw new LogicException('Empty separator accepted'); }
catch (ValueError $e) {}
$s = $prepared->statistics();
check($s['entries'] <= 3 && $s['admission_entries'] <= 3 && $s['peak_payload_bytes'] <= 80 && $s['evicted'] > 0, 'Entry and byte bounds enforced under pressure');
same($s['calls'], $s['hits'] + $s['misses'] + $s['bypassed'] + $s['failures'], 'All calls accounted for, including errors');
same(1, $s['failures'], 'Errors counted without poisoning cache');
check($s['bypassed'] >= 2 && !str_contains(json_encode($s), 'huge'), 'Empty/oversized texts bypass caching and observations contain no text');
foreach ([[0, 80, 60], [3, 0, 60], [3, 80, 0]] as $limits) {
	$disabled = new RenderData(...$limits);
	for ($i = 0; $i < 3; $i++) { same([1 => 'a', 2 => 'b'], $disabled->csv('a,b')->values, 'Disabled cache retains CSV results'); }
	same(0, $disabled->statistics()['entries'], 'Disabled cache keeps no payload');
}
$checks += 20;
$largeText = str_repeat('large,', 200);
$large = new RenderData(1, 6000, 6000); $large->csv('seed');
$large->csv($largeText);
same(1, $large->statistics()['entries'], 'Large unique text keeps existing entry under pressure');
same(0, $large->statistics()['evicted'], 'First large read does not evict hot data');
$large->csv($largeText);
same(1, $large->statistics()['entries'], 'Second read admits large text');
$hinted = new RenderData(1, 6000, 6000); $hinted->csv('seed'); $hinted->expectReuse([$largeText]); $hinted->csv($largeText);
same(1, $hinted->statistics()['entries'], 'Known large keyword/section reuse admits immediately');
$checks += 4;

trait NativeBannerProbe {
	public function __construct() { $this->REFERENCE_WARNING_BANNER_KEYS = new Table([1 => 'dead_link']); }
	protected function buildSummaryLabelLookup($lang = null, $keys = null) { return new Table(['dead_link' => 'dead_link']); }
	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		Lua::insert($cats, 'category effect');
		return '<banner>';
	}
	public function append(&$out, $cats): void { $this->renderReferenceItemBanners('dead_link,dead_link', null, 'en', $cats, $out); }
}
foreach ([new class extends \MediaWiki\Extension\DebatePHP\ReferenceRenderer { use NativeBannerProbe; },
	new class extends \MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer { use NativeBannerProbe; }] as $probe) {
	$parts = ['prefix']; $categories = new Table;
	$probe->append($parts, $categories);
	same(['prefix', '<banner>', '<banner>'], $parts, 'Banner append mutates caller native fragments');
	same([1 => 'category effect', 2 => 'category effect'], $categories->values, 'Banner category effects stay ordered');
	$legacyParts = new Table([1 => 'prefix']); $categories = new Table;
	$alias = $legacyParts; $probe->append($legacyParts, $categories);
	same($alias, $legacyParts, 'Legacy fragment Table keeps shared identity');
	same([1 => 'prefix', 2 => '<banner>', 3 => '<banner>'], $alias->values, 'Legacy fragment append compatibility');
	$checks += 4;
}
echo "$checks native-path assertions: exact cache keys, JSON shape/order, native/legacy isolation and byte-bounded selective CSV\n";
