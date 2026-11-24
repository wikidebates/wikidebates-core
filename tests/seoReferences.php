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
