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

	[$dedicated] = seoSchemaParse('argument', $lang, '|dedicated-debate=Dedicated debate fixture|justifications=' .
		seoSchemaItems('Hidden fixture', 12));
	same([['@id' => Title::newFromText('Dedicated debate fixture')->getFullURL() . '#qa']],
		$dedicated['hasPart'], 'Dedicated debate replaces hidden local lists');
}
echo "OK: exhaustive native FR/EN lists, localized educational resources, stable IDs, plain text and dedicated debate\n";
