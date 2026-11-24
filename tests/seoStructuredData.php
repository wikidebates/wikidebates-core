<?php

require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();

function seoSchemaParse(string $kind, string $lang, string $fields): array {
	$services = MediaWikiServices::getInstance();
	$options = ParserOptions::newFromAnon();
	// Opposite visitor language must not affect the content's schema labels.
	$options->setUserLang($services->getLanguageFactory()->getLanguage($lang === 'fr' ? 'en' : 'fr'));
	$title = Title::newFromText('SEO structured resource ' . $kind . ' ' . $lang);
	$output = $services->getParserFactory()->create()->parse(
		'{{#' . $kind . ':render|lang=' . $lang . $fields . '}}', $title, $options
	);
	preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is',
		$output->getText(), $matches);
	same(1, count($matches[1]), 'Exactly one native JSON-LD');
	$schema = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
	same('https://schema.org', $schema['@context'], 'Schema context');
	$pages = array_values(array_filter($schema['@graph'], static fn($node) =>
		in_array('WebPage', (array)($node['@type'] ?? []), true)));
	same(1, count($pages), 'One page');
	$page = $pages[0];
	same(['WebPage', 'LearningResource'], $page['@type'], 'Resource multi-type');
	same($lang, $page['inLanguage'], 'Resource content language');
	same($title->getFullURL() . ($kind === 'debate' ? '#qa' : ''), $page['@id'], 'Stable graph identity');
	$payload = $output->getExtensionData(Metadata::DATA_KEY);
	$description = Metadata::build($payload, $title->getFullURL(), null)['meta']['description'] ?? '';
	same($description, $page['description'] ?? '', 'Identical JSON-LD and head description');
	check(!str_contains($matches[1][0], '"QAPage"') && !str_contains($matches[1][0], '"suggestedAnswer"') &&
		!str_contains($matches[1][0], '"answerCount"'), 'No legacy Q&A semantics');
	check(!str_contains($matches[1][0], 'UNIQ-'), 'No parser strip marker in native JSON-LD');
	check(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'UNIQ-'), 'No parser strip marker in native SEO payload');
	return [$page, $schema['@graph'], $output];
}

function seoSchemaCheckLists(array $page, array $names, array $prefixes, int $count): void {
	$parts = $page['hasPart'] ?? [];
	same(count($names), count($parts), 'Expected sections');
	foreach ($parts as $index => $part) {
		same('WebPageElement', $part['@type'], 'List section type');
		$list = $part['mainEntity'];
		same($names[$index], $part['name'], 'Localized section name');
		same($names[$index], $list['name'], 'Localized list name');
		same('ItemList', $list['@type'], 'ItemList');
		same($count, $list['numberOfItems'], 'Complete direct relation count');
		same($count, count($list['itemListElement']), 'Complete list');
		foreach ($list['itemListElement'] as $i => $item) {
			$n = $i + 1;
			same('ListItem', $item['@type'], 'List item type');
			same($n, $item['position'], 'Contiguous visible order');
			same('Label ' . $n, $item['name'], 'Displayed label, not canonical title');
			same(['@type' => 'Claim', '@id' => Title::newFromText($prefixes[$index] . ' ' . $n)->getFullURL() . '#claim'],
				$item['item'], 'Claim identity is the linked page, not an Answer');
		}
	}
}

function seoSchemaItems(string $prefix, int $count): string {
	$out = '';
	for ($i = 1; $i <= $count; $i++) {
		$out .= $prefix . ' ' . $i . '⟬Label ' . $i . '⟬⟭';
	}
	return $out;
}

foreach (['fr', 'en'] as $lang) {
	$fr = $lang === 'fr';
	$pro = seoSchemaItems('Pro fixture', 12);
	$con = seoSchemaItems('Con fixture', 12);
	[$page, $graph] = seoSchemaParse('debate', $lang, '|pro-arguments=' . $pro . '|con-arguments=' . $con);
	same('Question', $page['mainEntity']['@type'], 'Debate question retained');
	same($fr ? 'Débat' : 'Debate', $page['learningResourceType'], 'Localized debate resource type');
	seoSchemaCheckLists($page, $fr ? ['Arguments pour', 'Arguments contre'] : ['Arguments for', 'Arguments against'],
		['Pro fixture', 'Con fixture'], 12);
	same($fr ? ['Débat', 'Esprit critique'] : ['Debate', 'Critical thinking'], $page['educationalUse'], 'Localized debate uses');
	same($fr ? ['Enseignement secondaire', 'Enseignement supérieur'] : ['Secondary education', 'Higher education'],
		$page['educationalLevel'], 'Localized levels');
	same($fr ? 'Grand public' : 'General public', $page['audience'][0]['audienceType'], 'General audience retained');
	same($fr ? ['Élève', 'Étudiant', 'Enseignant'] : ['Pupil', 'Student', 'Teacher'],
		$page['audience'][1]['educationalRole'], 'Localized educational roles');
	[$empty] = seoSchemaParse('debate', $lang, '');
	check(!isset($empty['hasPart']), 'Empty lists omitted');
	$relatedTitles = [];
	for ($i = 1; $i <= 7; $i++) {
		$relatedTitles[] = 'Related debate fixture ' . $i;
	}
	// A piped link has a label in the displayed section, not in its target URL.
	$relatedRaw = implode('⟭', $relatedTitles) . '⟭Related labelled debate{{!}}Visible label';
	[$related] = seoSchemaParse('debate', $lang, '|related-debates=' . $relatedRaw);
	$relatedTitles[] = 'Related labelled debate';
	same(array_map(static fn($title) => Title::newFromText($title)->getFullURL(), $relatedTitles),
		$related['relatedLink'] ?? null, 'Related debates are complete URL-valued links');
	check(!isset($related['isRelatedTo']), 'No product/service relationship on a WebPage');
	check(!isset($empty['relatedLink']), 'Empty related links omitted');

	[$oneSide] = seoSchemaParse('debate', $lang, '|con-arguments=' . seoSchemaItems('Con fixture', 1));
	seoSchemaCheckLists($oneSide, [$fr ? 'Arguments contre' : 'Arguments against'], ['Con fixture'], 1);

	[$page, $graph] = seoSchemaParse('argument', $lang,
		"|summary=Texte avec ''emphase'' et [[Example|libellé]].|justifications=" .
		seoSchemaItems('Justification fixture', 12) . '|objections=' . seoSchemaItems('Objection fixture', 12));
	seoSchemaCheckLists($page, ['Justifications', 'Objections'], ['Justification fixture', 'Objection fixture'], 12);
	same('Argumentation', $page['learningResourceType'], 'Localized argument resource type');
	same($fr ? ['Analyse', 'Argumentation', 'Esprit critique'] : ['Analysis', 'Argumentation', 'Critical thinking'],
		$page['educationalUse'], 'Localized argument uses');
	$claims = array_values(array_filter($graph, static fn($node) => ($node['@type'] ?? '') === 'Claim'));
	same(1, count($claims), 'One main Claim node');
	$claim = $claims[0];
	same($page['url'] . '#claim', $page['mainEntity']['@id'], 'Main entity identity');
	same(['@id' => $page['@id']], $claim['appearance'], 'Appearance is the Wikidebates page');
	check(!isset($claim['educationalUse']) && !isset($claim['learningResourceType']) &&
		!isset($claim['educationalLevel']) && !isset($claim['audience']) && !isset($claim['author']) &&
		!isset($claim['datePublished']) && !isset($claim['hasPart']), 'Claim is not the learning page');
	same('Texte avec emphase et libellé.', $claim['abstract'], 'Plain summary');
	same($claim['abstract'], $page['description'], 'Summary and description agree');


	// Both renderers use the same reference normalization on decoded fields.
	$record = 'Ada⟬Paper⟬Journal⟬IV⟬2⟬⟬⟬Publisher⟬Paris⟬23 janvier 2017⟬https://example.org/paper⟬⟭';
	foreach (['debate', 'argument'] as $kind) {
		foreach (['reference', 'optimized'] as $mode) {
			$GLOBALS['wgDebatePHPMode'] = $mode;
			[$resource, $nodes] = seoSchemaParse($kind, $lang, '|bibliography=' . $record);
			$owner = $kind === 'debate' ? $resource : array_values(array_filter($nodes,
				static fn($node) => ($node['@type'] ?? '') === 'Claim'))[0];
			$ref = $owner['citation'][0];
			same('Article', $ref['@type'], 'Article reference retained');
			check(!isset($ref['issueNumber']) && !isset($ref['volumeNumber']), 'No issue/volume on Article');
			same('PublicationIssue', $ref['isPartOf']['@type'], 'Issue container');
			same('2', $ref['isPartOf']['issueNumber'], 'Issue number');
			same('PublicationVolume', $ref['isPartOf']['isPartOf']['@type'], 'Volume container');
			same('IV', $ref['isPartOf']['isPartOf']['volumeNumber'], 'Volume number');
			same('Journal', $ref['isPartOf']['isPartOf']['isPartOf']['name'], 'Periodical identity');
			same('2017-01-23', $ref['datePublished'], 'Normalized date');
			same('https://example.org/paper', $ref['url'], 'URL retained');
		}
	}
	$GLOBALS['wgDebatePHPMode'] = 'reference';

	// Literal nowiki syntax must survive both graph encoding and the later head build.
	foreach (['reference', 'optimized'] as $mode) {
		$GLOBALS['wgDebatePHPMode'] = $mode;
		$literal = '<nowiki>[[Literal|label]] &lt;b&gt; A &amp; B</nowiki>';
		$expected = '[[Literal|label]] <b> A & B';
		[$withLiteral, $nodes, $out] = seoSchemaParse('argument', $lang,
			'|summary=Before ' . $literal . ' after.|justifications=Target fixture⟬' . $literal . '⟬⟭');
		$claim = array_values(array_filter($nodes, static fn($node) => ($node['@type'] ?? '') === 'Claim'))[0];
		same('Before ' . $expected . ' after.', $claim['abstract'], 'Nowiki summary remains literal');
		same($claim['abstract'], $withLiteral['description'], 'Head and graph use the same literal summary');
		same($expected, $withLiteral['hasPart'][0]['mainEntity']['itemListElement'][0]['name'], 'Nowiki list label');
		$payload = $out->getExtensionData(Metadata::DATA_KEY);
		check(in_array('wd_summary', $payload['wd_plain_fields'] ?? [], true), 'Plain summary explicitly marked');
		$record = 'Ada⟬Paper ' . $literal . '⟬Journal⟬IV⟬2⟬⟬⟬Publisher⟬Paris⟬<nowiki>23 janvier 2017</nowiki>⟬<nowiki>https://example.org/paper</nowiki>⟬⟭';
		[$resource] = seoSchemaParse('debate', $lang, '|bibliography=' . $record . '|expanded-topic=' . $literal);
		$ref = $resource['citation'][0];
		same('Paper ' . $expected, $ref['name'], 'Nowiki citation name');
		same('2017-01-23', $ref['datePublished'], 'Restore nowiki before date validation');
		same('https://example.org/paper', $ref['url'], 'Restore nowiki before URL validation');
		check(str_contains($resource['mainEntity']['text'], $expected), 'Literal question text');
	}
	$GLOBALS['wgDebatePHPMode'] = 'reference';

	[$dedicated] = seoSchemaParse('argument', $lang, '|dedicated-debate=Dedicated debate fixture|justifications=' .
		seoSchemaItems('Hidden fixture', 12));
	same([['@id' => Title::newFromText('Dedicated debate fixture')->getFullURL() . '#qa']],
		$dedicated['hasPart'], 'Dedicated debate replaces hidden local lists');
}
echo "OK: exhaustive native FR/EN lists, localized educational resources, stable IDs, plain text and dedicated debate\n";


// A general strip item must be discarded without running its callback.
$parser = $services->getParserFactory()->create();
$parser->startExternalParse(Title::newFromText('SEO marker fixture'), ParserOptions::newFromAnon(), $parser::OT_HTML);
$marker = $parser::MARKER_PREFIX . '-ref-00000123' . $parser::MARKER_SUFFIX;
$called = false;
$parser->getStripState()->addGeneral($marker, static function() use (&$called) {
	$called = true;
	return '<sup>Reference</sup>';
});
$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], true);
same('Before after', $context->resolveSeoMarkers('Before ' . $marker . ' after'), 'General marker removed');
check(!$called, 'General marker callback never executed');
