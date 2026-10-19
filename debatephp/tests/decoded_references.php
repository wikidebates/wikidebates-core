<?php
require __DIR__ . '/../../tests/bootstrap.php';

use MediaWiki\Extension\DebatePHP\DecodedReferences;
use MediaWiki\Extension\DebatePHP\SerializedData;
use MediaWiki\Extension\DebatePHP\Table;

$formats = [
	'bibliography' => ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'],
	'webliography' => ['page', 'site', 'link', 'authors', 'date', 'warnings'],
	'videography' => ['title', 'link', 'authors', 'warnings'],
	'quotes' => ['quote', 'authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']
];
$samples = [null, '', '0', ' ⟭ ', 'A⟬B', 'A⟭B', '⟬⟬⟬', '⟭⟭⟭', "\0 A⟬B\0", "\vA⟬B\f", " A⟬B ", 'A⟬B⟭C⟬D', 'A⟬⟬⟬B⟭⟭⟭C⟬⟬⟬D'];
mt_srand(803);
$alphabet = ['A', '0', '⟬', '⟭', ' ', "\t", "\n", "\v", "\0", 'é'];
for ($i = 0; $i < 400; $i++) {
	$text = '';
	for ($j = mt_rand(0, 80); $j > 0; $j--) { $text .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
	$samples[] = $text;
}
$decoder = new DecodedReferences;
$count = 0;
foreach ($formats as $format => $fields) {
	foreach ($samples as $text) {
		$expected = $format === 'quotes' ? SerializedData::quotes($text) : SerializedData::records($text, '⟭', '⟬', $fields);
		$actual = $decoder->parse($format, $text);
		same(Table::unwrap($expected), Table::unwrap($actual), 'Decoded record equivalence: ' . $format);
		$statistics = $decoder->statistics();
		same($actual, $decoder->parse($format, $text), 'Same read-only records are reused');
		same($statistics, $decoder->statistics(), 'No second decode for HTML/JSON-LD consumer');
		$count++;
	}
}
$decoder = new DecodedReferences;
$decoder->parse('bibliography', 'A⟬B');
$decoder->parse('bibliography', 'C⟬D');
$decoder->parse('bibliography', 'A⟬B⟭C⟬D');
same(['decoded_blocks' => 2, 'reused_blocks' => 2], $decoder->statistics(), 'Debate merged JSON-LD reuses HTML-side blocks');
$other = new DecodedReferences;
same(['decoded_blocks' => 0, 'reused_blocks' => 0], $other->statistics(), 'Invocation isolation');
echo "$count decoded-reference equivalences, repeat reads, combined Debate sides and invocation isolation: passed\n";
