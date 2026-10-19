<?php

/** Synthetic cases with repeated/partial records; no site data or outbound requests. */
$cases = [];
$call = static function(string $module, array $args): string {
	$text = '{{#' . $module . ':render';
	foreach ($args as $key => $value) { $text .= '|' . $key . '=' . str_replace('|', '{{!}}', $value); }
	return $text . '}}<references/>';
};
foreach (['en', 'fr'] as $lang) {
	$books = [];
	$quotes = [];
	$web = [];
	$videos = [];
	for ($i = 0; $i < 40; $i++) {
		$books[] = implode('⟬', ['Author ' . ($i % 7), 'Article ' . $i, 'Book ' . $i, '0', '', 'Chapter', '0', 'Publisher', 'Paris', '2026', 'https://example.test/book/' . $i, '']);
	}
	for ($i = 0; $i < 12; $i++) {
		$quotes[] = implode('⟬⟬⟬', ['Quote ' . $i . ' é 0', 'Alice,Bob,Alice', 'Article ' . $i, 'Book', '1', '2', '', '0', 'Publisher', 'Paris', '2026', 'https://example.test/quote/' . $i, 'quote_translated']);
		$web[] = implode('⟬', ['Page ' . $i, 'Site', 'https://example.test/page/' . $i, 'Author', '2026', '']);
		$videos[] = implode('⟬', ['Video ' . $i, 'https://example.test/video/' . $i, 'Author', '']);
	}
	$cases[] = ['title' => 'WD FS rich argument ' . $lang, 'name' => 'rich native argument ' . $lang,
		'text' => $call('argument', ['lang' => $lang, 'summary' => 'Summary', 'creation-date' => '2026-01-01',
			'quotes' => implode('⟭⟭⟭', $quotes) . '⟭⟭⟭', 'bibliography' => implode('⟭', $books) . '⟭',
			'webliography' => implode('⟭', $web) . '⟭', 'videography' => implode('⟭', $videos) . '⟭'])];
	$args = ['lang' => $lang, 'topic' => 'Topic', 'creation-date' => '2026-01-01'];
	foreach (['pro', 'con', 'neutral'] as $side) {
		$prefix = $side === 'neutral' ? '' : $side . '-';
		$args[$prefix . 'bibliography'] = implode('⟭', array_slice($books, 0, 12));
		$args[$prefix . 'webliography'] = implode('⟭', $web);
		$args[$prefix . 'videography'] = implode('⟭', $videos);
	}
	$cases[] = ['title' => 'WD FS rich debate ' . $lang, 'name' => 'rich native debate ' . $lang, 'text' => $call('debate', $args)];
	$cases[] = ['title' => 'WD FS reference edges ' . $lang, 'name' => 'native reference edges ' . $lang,
		'text' => $call('argument', ['lang' => $lang, 'summary' => '0', 'creation-date' => '2026-01-01',
			'quotes' => '⟭⟭⟭ 0⟬⟬⟬0⟬⟬⟬⟬⟬⟬Work⟭⟭⟭ ⟬⟬⟬ ⟭⟭⟭',
			'bibliography' => '⟭ 0⟬0⟬Work⟬⟬⟬⟬⟬Publisher⟭⟭', 'webliography' => '⟭', 'videography' => '0⟬https://example.test⟭'])];
}
return $cases;
