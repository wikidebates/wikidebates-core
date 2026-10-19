<?php
require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/argument_context.php';
require __DIR__ . '/../../tests/nativeCandidatePrototypes.php';

use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, DecodedReferences, Lua, ReferenceRenderer, SerializedData, Table};

set_error_handler(static function($severity, $message, $file, $line): void { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
$equal = static function($a, $b, string $message) use (&$checks): void { same($a, $b, $message); $checks++; };
$context = new ArgumentTestContext(['title' => 'Measured regressions', 'smw' => false, 'args' => []]);
foreach ([ReferenceRenderer::class, ArgumentReferenceRenderer::class] as $class) {
	$r = new $class($context); $method = new ReflectionMethod($r, 'wkBuildArgumentData');
	$first = new Table(['page' => 'Page', 'title' => 'Shown', 'warnings' => '']);
	$second = new Table(['page' => 'Same', 'title' => 'Same', 'warnings' => '']);
	$items = new Table([1 => $first, 2 => $second, 4 => new Table(['page' => 'Hidden', 'title' => 'Hidden'])]);
	$d = $method->invoke($r, $items, 'en', 'pro');
	$equal(true, $d['items'] === $items, 'Argument map retains shared outer identity');
	$equal(true, $d['items'][1] === $first, 'Argument map retains shared row identity');
	$equal("* Shown\n* Same", $d['list'], 'Sequence stops at first missing index');
	$separator = $class === ReferenceRenderer::class ? ' -;- ' : '⟭';
	$equal('Page⟬Shown' . $separator . 'Same', $d['argsVar'], 'Historical argument-variable separators');
	$equal('<div class="argument-title--map wk-icon-before wk-icon-argument">[[Page|Shown]]</div>' . "\n" . '<div class="argument-title--map wk-icon-before wk-icon-argument">[[Same|Same]]</div>', $d['mapHover'], 'Exact hover bytes and newline');
	if ($class === ReferenceRenderer::class) {
		$equal('<div  id="Pro_argument_1_map" class="argument-title--map wk-icon-before wk-icon-argument">Shown</div>' . "\n" . '<div  id="Pro_argument_2_map" class="argument-title--map wk-icon-before wk-icon-argument">Same</div>', $d['map'], 'Historical double space and one-based map IDs');
	}
	$first['title'] = 'Changed';
	$equal('Changed', $d['items'][1]['title'], 'Legacy row mutation remains visible');
	$nil = new Table([1 => new Table, 2 => $second]);
	$equal('Same', $method->invoke($r, $nil, 'en', 'pro')['argsVar'], 'Nil fields do not add an argument-variable separator');
	$equal('', $method->invoke($r, new Table, 'en', 'pro')['list'], 'Empty argument map');
}

$r = new ArgumentReferenceRenderer($context); $normalize = new ReflectionMethod($r, 'normalizeTitleForCard');
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
	$equal(Table::unwrap(SerializedData::records($raw, '⟭', '⟬', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'])), $decoder->values('bibliography', $raw), 'Cache pressure cannot alter records');
	$s = $decoder->cacheStatistics();
	$equal(true, $s['lists'] <= 64 && $s['blocks'] <= 512 && $s['list_records'] <= 2048 && $s['string_bytes'] <= 1048576, 'All native cache budgets respected');
}
$raw = 'After cap⟬Article⟭';
$a = $decoder->parse('bibliography', $raw);
$b = $decoder->parse('bibliography', 'Other⟬Book⟭' . $raw);
$equal(true, $a[1] === $b[2], 'Legacy block identities survive native admission refusal');
$a[1]['authors'] = 'Legacy mutation';
$equal('Legacy mutation', $decoder->parse('bibliography', $raw)[1]['authors'], 'Legacy list identity survives pressure');
$equal('After cap', $decoder->values('bibliography', $raw)[1]['authors'], 'Legacy mutation cannot poison native values');
$large = new DecodedReferences;
$raw = str_repeat('a', 600000) . '⟬Article⟭';
$large->values('bibliography', $raw);
$equal(['lists' => 0, 'blocks' => 0, 'list_records' => 0, 'string_bytes' => 0], $large->cacheStatistics(), 'Oversized records are decoded without retention');
$repeated = new DecodedReferences;
$equal(3000, count($repeated->values('videography', str_repeat('A⟬B⟭', 3000))), 'Oversized list retains all output records');
$equal(0, $repeated->cacheStatistics()['lists'], 'List record budget prevents retaining oversized slot arrays');

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
	foreach ([false, true] as $required) { $equal(Table::unwrap(SerializedData::argumentItems($raw, $required)), NativeCandidatePrototypes::arguments($raw, $required), 'Experimental native argument decoder preserves marker grammar'); }
	$equal(Table::unwrap(SerializedData::records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true)), NativeCandidatePrototypes::introductions($raw), 'Experimental native intro decoder preserves marker grammar');
	$title = '';
	for ($j = 0, $n = mt_rand(0, 30); $j < $n; $j++) { $title .= $titleParts[mt_rand(0, count($titleParts) - 1)]; }
	foreach ([$title, '[[' . $title . '|label]]', ': [[' . $title . ']]'] as $text) {
		$equal($legacyTitle($text), $normalize->invoke($r, $text), 'Native title decoding against frozen compatibility algorithm');
	}
}
echo "$checks measured-candidate assertions: map identity/bytes, title decoding, native cache budgets and experimental record views\n";
