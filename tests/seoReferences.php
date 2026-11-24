<?php

use MediaWiki\Extension\DebatePHP\SchemaData;
use MediaWiki\Extension\DebatePHP\JsonData;
use MediaWiki\Extension\DebatePHP\Table;

foreach ([
	'1994' => '1994', '2026-2' => '2026-02', '2024/2/29' => '2024-02-29',
	'23 janvier 2017' => '2017-01-23', 'January 19, 2020' => '2020-01-19',
	'février 2018' => '2018-02', '19/1/2020' => '2020-01-19',
	'7/02/2018' => null, '2023-02-29' => null, '2020-00' => null,
	'0000' => null, 'vers 1994' => null, '2020-2021' => null
] as $raw => $expected) {
	same($expected, SchemaData::referenceDate((string)$raw), 'Bibliographic date: ' . $raw);
}
foreach (['https://example.org/a?x=1&y=2', 'http://example.org', 'https://example.org/a%20b'] as $url) {
	check(SchemaData::referenceUrl($url), 'Valid citation URL: ' . $url);
}
foreach (['Texte', 'javascript:alert(1)', 'https://', 'https://example.org/a b',
	'https://example.org/%xx', 'https://user@example.org'] as $url) {
	check(!SchemaData::referenceUrl($url), 'Invalid citation URL: ' . $url);
}
$article = ['@type' => 'Article', 'name' => 'Paper', 'isPartOf' => ['@type' => 'Periodical', 'name' => 'Journal'],
	'volumeNumber' => 'IV', 'issueNumber' => '2', 'datePublished' => '23 janvier 2017', 'url' => 'https://example.org/paper',
	'author' => ['@type' => 'Person', 'name' => 'Ada']];
$expected = ['@type' => 'Article', 'name' => 'Paper', 'isPartOf' => [
	'@type' => 'PublicationIssue', 'issueNumber' => '2', 'isPartOf' => [
		'@type' => 'PublicationVolume', 'volumeNumber' => 'IV', 'isPartOf' => ['@type' => 'Periodical', 'name' => 'Journal']
	]], 'datePublished' => '2017-01-23', 'url' => 'https://example.org/paper',
	'author' => ['@type' => 'Person', 'name' => 'Ada']];
foreach ([$article, Table::wrap($article)] as $node) {
	same($expected, JsonData::value(SchemaData::reference($node)), 'Article, issue, volume, periodical');
}
$book = ['@type' => 'Book', 'name' => 'Book', 'volumeNumber' => '0', 'issueNumber' => '3', 'url' => 'bad', 'datePublished' => 'unknown'];
same(['@type' => ['Book', 'PublicationVolume'], 'name' => 'Book', 'volumeNumber' => '0',
	'identifier' => ['@type' => 'PropertyValue', 'propertyID' => 'number', 'value' => '3']],
	JsonData::value(SchemaData::reference($book)), 'Book number is an identifier, not a periodical issue');
echo "OK: citation volume, issue, date precision and absolute URLs\n";


// Clean only optional SEO data during conversion, not the generic JSON tree.
// Keep a numbered issue/volume even if its periodical name is unavailable.
$emptyFields = [
	'@type' => 'Article', 'name' => 'Paper', 'description' => '',
	'author' => [1 => ['@type' => 'Person', 'name' => ''], 2 => ['@type' => 'Person', 'name' => '0']],
	'publisher' => ['@type' => 'Organization', 'name' => '   '],
	'isPartOf' => ['@type' => 'PublicationIssue', 'issueNumber' => '2',
		'isPartOf' => ['@type' => 'PublicationVolume', 'volumeNumber' => 'IV',
			'isPartOf' => ['@type' => 'Periodical', 'name' => '']]],
	'identifier' => [1 => ['@type' => 'PropertyValue', 'propertyID' => 'number', 'value' => ''],
		2 => ['@type' => 'PropertyValue', 'propertyID' => 'volume', 'value' => '0']],
	'about' => [1 => ['@type' => 'Thing', 'name' => ''], 2 => ['@type' => 'Thing', 'name' => 'Science']],
	'mentions' => [1 => ['@type' => 'Thing', 'name' => "\u{00a0}"]],
	'mainEntity' => ['@id' => 'https://example.org/page#claim'],
];
$cleanFields = [
	'@type' => 'Article', 'name' => 'Paper',
	'author' => [['@type' => 'Person', 'name' => '0']],
	'isPartOf' => ['@type' => 'PublicationIssue', 'issueNumber' => '2',
		'isPartOf' => ['@type' => 'PublicationVolume', 'volumeNumber' => 'IV']],
	'identifier' => [['@type' => 'PropertyValue', 'propertyID' => 'volume', 'value' => '0']],
	'about' => [['@type' => 'Thing', 'name' => 'Science']],
	'mainEntity' => ['@id' => 'https://example.org/page#claim'],
];
foreach ([$emptyFields, Table::wrap($emptyFields)] as $input) {
	same($cleanFields, JsonData::schemaValue($input), 'Only empty optional metadata removed; lists reindexed');
	check(isset(JsonData::value($input)['publisher']), 'Generic JSON conversion unchanged');
}
same(['@type' => 'Person', '@id' => 'https://example.org/person'],
	JsonData::schemaValue(['@type' => 'Person', '@id' => 'https://example.org/person', 'name' => '']),
	'An explicit identity is not deleted');
same(['@type' => 'Audience', 'audienceType' => 'General public'],
	JsonData::schemaValue(['@type' => 'Audience', 'audienceType' => 'General public']),
	'Not every useful entity requires a name');
echo "OK: empty optional SEO data, explicit identities and numbered publication containers\n";
