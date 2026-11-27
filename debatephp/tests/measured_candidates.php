<?php
require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/frozen_contract.php';
require __DIR__ . '/argument_context.php';
require __DIR__ . '/../../tests/nativeCandidatePrototypes.php';

use MediaWiki\Extension\DebatePHP\{ArgumentRenderer, DecodedReferences, Lua, DebateRenderer, SerializedData};

set_error_handler(static function($severity, $message, $file, $line): void { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
$equal = static function($a, $b, string $message) use (&$checks): void { same($a, $b, $message); $checks++; };
$context = new ArgumentTestContext(['title' => 'Measured regressions', 'smw' => false, 'args' => []]);
foreach ([DebateRenderer::class, ArgumentRenderer::class] as $class) {
	$r = new $class($context); $method = new ReflectionMethod($r, 'wkBuildArgumentData');
	$items = SerializedData::argumentItems('Page⟬Shown⟬⟭⟭Same⟬Same⟬⟭⟬Ignored⟭', true);
	$equal(true, array_is_list($items), 'Argument parser produces a dense zero-based list');
	$equal(2, count($items), 'Empty records and missing page names are discarded');
	$d = $method->invoke($r, $items, 'en', 'pro');
	$equal($items, $d['items'], 'Argument map retains all decoded records');
	$equal("* Shown\n* Same", $d['list'], 'Exact list bytes');
	$separator = $class === DebateRenderer::class ? ' -;- ' : '⟭';
	$equal('Page⟬Shown' . $separator . 'Same', $d['argsVar'], 'Historical argument-variable separators');
	$equal('<div class="argument-title--map wk-icon-before wk-icon-argument">[[Page|Shown]]</div>' . "\n" . '<div class="argument-title--map wk-icon-before wk-icon-argument">[[Same|Same]]</div>', $d['mapHover'], 'Exact hover bytes and newline');
	if ($class === DebateRenderer::class) {
		$equal('<div  id="Pro_argument_1_map" class="argument-title--map wk-icon-before wk-icon-argument">Shown</div>' . "\n" . '<div  id="Pro_argument_2_map" class="argument-title--map wk-icon-before wk-icon-argument">Same</div>', $d['map'], 'Historical double space and one-based map IDs');
		$negative = $method->invoke($r, $items, 'en', 'con');
		$equal(str_replace('Pro_argument_', 'Con_argument_', $d['map']), $negative['map'], 'Counterargument public IDs');
	}
	$items[0]['title'] = 'Changed';
	$equal('Shown', $d['items'][0]['title'], 'Caller mutation cannot alter prepared map');
	$d['items'][1]['title'] = 'Changed in map';
	$equal('Same', $items[1]['title'], 'Map mutation cannot alter caller records');
	$empty = SerializedData::argumentItems('⟭⟬No page⟭', true);
	$equal('', $method->invoke($r, $empty, 'en', 'pro')['argsVar'], 'Missing pages do not add argument separators');
	$equal('', $method->invoke($r, [], 'en', 'pro')['list'], 'Empty argument map');

}

$r = new ArgumentRenderer($context); $normalize = new ReflectionMethod($r, 'normalizeTitleForCard');
foreach ([[': [[A &amp;quot;B&amp;quot;|label]]', 'A "B"'], ['&amp;nbsp;', '&nbsp;'], ['&amp;lt;', '<'], [' 0 ', '0'], [false, ''], [': [[A_B|label]]', 'A_B'], [": [[A|line\nbreak]]", "A|line\nbreak"]] as [$input, $expected]) {
	$equal($expected, $normalize->invoke($r, $input), 'Historical title decoding order and link patterns');
	$equal($expected, $normalize->invoke($r, $input), 'Title memoization does not alter bytes');
}
$cacheProperty = new ReflectionProperty($r, 'cardTitleCache');
$old = $cacheProperty->getValue($r);
for ($i = 0; $i < 300; $i++) { $normalize->invoke($r, ': [[Unique ' . $i . ' &amp;|label]]'); }
$cache = $cacheProperty->getValue($r);
$equal(true, count((new ReflectionProperty($cache, 'data'))->getValue($cache)) <= 200, 'Title cache entry limit');
$beforeLarge = (new ReflectionProperty($cache, 'data'))->getValue($cache);
$normalize->invoke($r, ': [[' . str_repeat('Long', 2000) . '|label]]');
$equal($beforeLarge, (new ReflectionProperty($cache, 'data'))->getValue($cache), 'Long titles are normalized without cache admission');
$r->render();
$equal(false, $old === $cacheProperty->getValue($r), 'Title cache lifetime is one render');

$decoder = new DecodedReferences;
for ($i = 0; $i < 1100; $i++) {
	$raw = 'Author ' . $i . '⟬Article ' . $i . '⟭';
	$equal(SerializedData::recordsValues($raw, '⟭', '⟬', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']), $decoder->values('bibliography', $raw), 'Cache pressure cannot alter records');
	$statistics = $decoder->statistics();
	$decoder->values('bibliography', $raw);
	$equal($statistics, $decoder->statistics(), 'Every list is reused without a second decode after 1100 distinct inputs');
}
$raw = 'After many inputs⟬Article⟭';
$a = $decoder->values('bibliography', $raw);
$b = $decoder->values('bibliography', 'Other⟬Book⟭' . $raw);
$equal($a[1], $b[2], 'Native block reuse survives many lists');
$a[1]['authors'] = 'Caller mutation';
$equal('After many inputs', $decoder->values('bibliography', $raw)[1]['authors'], 'Native list stays immutable after pressure');
$equal('After many inputs', $decoder->values('bibliography', 'Other⟬Book⟭' . $raw)[2]['authors'], 'Mutation cannot poison reused blocks');
$large = new DecodedReferences;
$raw = str_repeat('a', 600000) . '⟬Article⟭';
$large->values('bibliography', $raw);
$statistics = $large->statistics();
$large->values('bibliography', $raw);
$equal($statistics, $large->statistics(), 'Large records are reused without decoding again');
$repeated = new DecodedReferences;
$equal(3000, count($repeated->values('videography', str_repeat('A⟬B⟭', 3000))), 'Oversized list retains all output records');
$statistics = $repeated->statistics();
$repeated->values('videography', str_repeat('A⟬B⟭', 3000));
$equal($statistics, $repeated->statistics(), 'Large repeated lists remain cached');
$distinct = new DecodedReferences;
$raw = implode('⟭', array_map(static fn($i) => 'Video ' . $i . '⟬https://example.test/' . $i, range(1, 4000))) . '⟭';
$first = $distinct->values('videography', $raw);
$equal(4000, count($first), 'All distinct records in a large list are retained');
$statistics = $distinct->statistics();
$first[1]['title'] = 'Caller mutation';
$equal('Video 1', $distinct->values('videography', $raw)[1]['title'], 'Large native lists remain isolated from caller mutations');
$equal($statistics, $distinct->statistics(), 'A second large-list consumer performs no additional block decode');

$contract = new FrozenNativeContract('title-decoding');
mt_srand(809);
$parts = ['', '0', 'é', ' [[Page|label]] ', '\0', '⟬', '⟭', '⟬⟬⟬', '⟭⟭⟭', "\t\n", "\xc2\xa0"];
$titleParts = array_merge($parts, [':', '[', ']', '|', '<', '&nbsp;', '&#160;', '&amp;quot;', '&amp;lt;', '&#39;', '&gt;', 'false']);
// Frozen aca0e6d algorithm: preserve the ordered replacements and newline rules.
$legacyTitle = static function($s): string {
	$s = Lua::trim(Lua::str(Lua::truth($s) ? $s : ''));
	if ($s === '' || (Lua::byte($s, 1) !== 58 && !Lua::truth(Lua::find($s, '[%[<&]')))) { return $s; }
	$s = Lua::gsub($s, '^:%s*', '');
	$t = Lua::match($s, '^%[%[([^%]|]+)%|.-%]%]$') ?? Lua::match($s, '^%[%[([^%]]+)%]%]$');
	if ($t !== null && $t !== '') { $s = $t; }
	$s = Lua::gsub(Lua::trim($s), '^:%s*', '');
	foreach (['&nbsp;' => ' ', '&#160;' => ' ', '&amp;' => '&', '&quot;' => '"', '&#39;' => "'", '&lt;' => '<', '&gt;' => '>'] as $a => $b) { $s = Lua::gsub($s, $a, $b); }
	return Lua::trim($s);
};
for ($i = 0; $i < 800; $i++) {
	$raw = '';
	for ($j = 0, $n = mt_rand(0, 30); $j < $n; $j++) { $raw .= $parts[mt_rand(0, count($parts) - 1)]; }
	foreach ([false, true] as $required) { $equal(array_values(NativeCandidatePrototypes::arguments($raw, $required)), SerializedData::argumentItems($raw, $required), 'Native argument decoder preserves the independently retained marker grammar'); }
	$equal(SerializedData::recordsValues($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true), NativeCandidatePrototypes::introductions($raw), 'Experimental native intro decoder preserves marker grammar');
	$title = '';
	for ($j = 0, $n = mt_rand(0, 30); $j < $n; $j++) { $title .= $titleParts[mt_rand(0, count($titleParts) - 1)]; }
	foreach ([$title, '[[' . $title . '|label]]', ': [[' . $title . ']]'] as $text) {
		$expected = $legacyTitle($text);
		$equal($expected, $normalize->invoke($r, $text), 'Native title decoding against frozen compatibility algorithm');
		$contract->add($text, $expected);
	}
}
$contract->report();
echo "$checks measured-candidate assertions: map isolation/bytes, title decoding, reference-cache reuse and experimental record views\n";
