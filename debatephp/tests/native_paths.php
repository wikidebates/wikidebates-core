<?php
require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/frozen_contract.php';

use MediaWiki\Extension\DebatePHP\{CacheKey, DecodedReferences, JsonData, Lua, RenderData, Table};

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
foreach ([null, false, true, 0, '0', '', 'é', "\0:\n"] as $input) {
	try { CacheKey::semantic($input); throw new LogicException('Scalar query should be rejected'); }
	catch (TypeError $error) { $checks++; }
}
$contract = new FrozenNativeContract('cache-key-json');
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
	same($legacyKey($table), CacheKey::semantic($a), 'Mixed, sparse and numeric-string query keys');
	$table = new Table($node); $before = Table::unwrap($table);
	$expected = $legacyJson($table); $actual = JsonData::value($before);
	same($expected, $actual, 'JSON fallback and native shapes preserve values and ordering');
	same(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($actual, JSON_THROW_ON_ERROR), 'Exact encoded JSON bytes');
	same($before, Table::unwrap($table), 'The independent oracle and native conversion preserve input');
	$contract->add([$a, $before], [$legacyKey(new Table($a)), $expected, json_encode($expected, JSON_THROW_ON_ERROR)]);
	$checks += 4;
}
$contract->report();
foreach ([new Table, new Table([1 => 'é', 2 => '0']), new Table(['@type' => 'Thing', 'name' => new Table(['@id' => 'x'])])] as $table) {
	same($legacyJson($table), JsonData::value(Table::unwrap($table)), 'Dense and mapping fast paths'); $checks++;
}

$decoder = new DecodedReferences;
$native = $decoder->values('bibliography', 'A⟬B⟭C⟬D');
check(is_array($native[1]), 'Native records have no Table wrapper');
$native[1]['authors'] = 'mutation'; $native[3] = ['authors' => 'new'];
$again = $decoder->values('bibliography', 'A⟬B⟭C⟬D');
same('A', $again[1]['authors'], 'Caller cannot poison native cached list');
same(2, count($again), 'Caller cannot add records to the cached list');
$again[1]['authors'] = 'second mutation';
$combined = $decoder->values('bibliography', 'A⟬B');
same('A', $combined[1]['authors'], 'Cached blocks are isolated across lists');
same(['decoded_blocks' => 2, 'reused_blocks' => 1], $decoder->statistics(), 'Native lists still share decoded blocks');
$checks += 5;

$prepared = new RenderData(3, 80, 60);
$first = $prepared->csvValues('one,two');
same(1, $prepared->statistics()['entries'], 'Small reusable fields retain immediate admission');
$prepared->csvValues('one,two');
same(1, $prepared->statistics()['entries'], 'Repeated read reuses the entry');
$first[1] = 'mutated';
same('one', $prepared->csvValues('one,two')[1], 'Cache read returns an independent array');
$prepared->expectReuse(['three,four']); $prepared->csvValues('three,four');
same(2, $prepared->statistics()['entries'], 'Known reuse admits on first read');
for ($i = 0; $i < 200; $i++) { $raw = $i . ',' . str_repeat('x', $i % 35); $prepared->csvValues($raw); $prepared->csvValues($raw); }
$prepared->csvValues(str_repeat('huge,', 100)); $prepared->csvValues("\0 a\0"); $prepared->csvValues('');
try { $prepared->csvValues('a', ''); throw new LogicException('Empty separator accepted'); }
catch (ValueError $e) {}
$s = $prepared->statistics();
check($s['entries'] <= 3 && $s['admission_entries'] <= 3 && $s['peak_payload_bytes'] <= 80 && $s['evicted'] > 0, 'Entry and byte bounds enforced under pressure');
same($s['calls'], $s['hits'] + $s['misses'] + $s['bypassed'] + $s['failures'], 'All calls accounted for, including errors');
same(1, $s['failures'], 'Errors counted without poisoning cache');
check($s['bypassed'] >= 2 && !str_contains(json_encode($s), 'huge'), 'Empty/oversized texts bypass caching and observations contain no text');
foreach ([[0, 80, 60], [3, 0, 60], [3, 80, 0]] as $limits) {
	$disabled = new RenderData(...$limits);
	for ($i = 0; $i < 3; $i++) { same([1 => 'a', 2 => 'b'], $disabled->csvValues('a,b'), 'Disabled cache retains CSV results'); }
	same(0, $disabled->statistics()['entries'], 'Disabled cache keeps no payload');
}
$checks += 20;
$largeText = str_repeat('large,', 200);
$large = new RenderData(1, 6000, 6000); $large->csvValues('seed');
$large->csvValues($largeText);
same(1, $large->statistics()['entries'], 'Large unique text keeps existing entry under pressure');
same(0, $large->statistics()['evicted'], 'First large read does not evict hot data');
$large->csvValues($largeText);
same(1, $large->statistics()['entries'], 'Second read admits large text');
$hinted = new RenderData(1, 6000, 6000); $hinted->csvValues('seed'); $hinted->expectReuse([$largeText]); $hinted->csvValues($largeText);
same(1, $hinted->statistics()['entries'], 'Known large keyword/section reuse admits immediately');
$checks += 4;

// The shared lookup retains message order, last-label wins, and the different
// fallback policies of page-level and subsection warnings.
$warningProbe = new class {
	use \MediaWiki\Extension\DebatePHP\WarningLabels;
	public array $calls = [];
	protected function subMsg($lang, $key): string {
		$this->calls[] = [$lang, $key];
		return ['first_label' => " \tMême\f", 'second_label' => 'Même', 'zero_label' => '0'][$key] ?? '';
	}
	protected function bannerMsg($lang, $key): string { return $this->subMsg($lang, $key); }
	public function labels($lang, $keys): array { return $this->buildSummaryLabelLookup($lang, $keys); }
	public function banners($lang, $keys): array { return $this->buildBannerLabelLookup($lang, $keys); }
	public function resolve($raw, $lookup, $allowed, $aliases): string { return $this->resolveKeyFromLookup($raw, $lookup, $allowed, $aliases); }
	public function resolveBanner($raw, $lookup): string { return $this->resolveBannerKeyFromLookup($raw, $lookup); }
};
$keys = ['first', 'missing', 'second', 'zero'];
$lookup = $warningProbe->labels('fr', $keys);
same(['Même' => 'second', 0 => 'zero'], $lookup, 'Localized labels are trimmed, including literal zero');
same(array_map(static fn($k) => ['fr', $k . '_label'], $keys), $warningProbe->calls, 'Message order and count remain unchanged');
same($lookup, $warningProbe->banners('fr', $keys), 'Banner and subsection label construction agree');
same('second', $warningProbe->resolve(' Même ', $lookup, $keys, ['Même' => 'first']), 'Localized label takes priority over alias');
same('first', $warningProbe->resolve('alias', $lookup, $keys, ['alias' => 'first']), 'Explicit alias');
same('second', $warningProbe->resolve('second', $lookup, $keys, []), 'Canonical subsection key');
same('', $warningProbe->resolveBanner('second', $lookup), 'No canonical-key fallback for page banners');
same('zero', $warningProbe->resolveBanner('0', $lookup), 'Zero is a valid localized label');
same('', $warningProbe->resolve(null, $lookup, $keys, []), 'Missing warning');
same('', $warningProbe->resolve(false, $lookup, $keys, []), 'False warning');
same('', $warningProbe->resolve('unknown', $lookup, $keys, []), 'Unknown warning');
same('', $warningProbe->resolveBanner('', ['' => 'second']), 'Empty labels are never resolved');
$checks += 12;

trait NativeBannerProbe {
	public function __construct() { $this->REFERENCE_WARNING_BANNER_KEYS = ['dead_link']; }
	protected function buildSummaryLabelLookup($lang = null, array $keys = []): array { return ['dead_link' => 'dead_link']; }
	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, ?array $opts = null, ?array &$cats = null) {
		$cats[] = 'category effect';
		return '<banner>';
	}
	public function append(&$out, array &$cats): void { $this->renderReferenceItemBanners('dead_link,dead_link', null, 'en', $cats, $out); }
}
foreach ([new class extends \MediaWiki\Extension\DebatePHP\DebateRenderer { use NativeBannerProbe; },
	new class extends \MediaWiki\Extension\DebatePHP\ArgumentRenderer { use NativeBannerProbe; }] as $probe) {
	$parts = ['prefix']; $categories = [];
	$probe->append($parts, $categories);
	same(['prefix', '<banner>', '<banner>'], $parts, 'Banner append mutates caller native fragments');
	same(['category effect', 'category effect'], $categories, 'Banner category effects stay ordered');
	$empty = []; $categories = [];
	$probe->append($empty, $categories);
	same(['<banner>', '<banner>'], $empty, 'Native fragment list preserves duplicates and order');
	$legacyParts = new Table([1 => 'prefix']);
	try {
		$probe->append($legacyParts, $categories);
		throw new LogicException('Legacy fragment Table must be rejected');
	} catch (TypeError $error) {
		same([1 => 'prefix'], $legacyParts->values, 'No mutation through retired Table boundary');
	}
	$checks += 4;
}
echo "$checks native-path assertions: exact cache keys, JSON shape/order, native/legacy isolation and byte-bounded selective CSV\n";
