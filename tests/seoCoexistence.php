<?php

/** WikiSEO 2.7.0 and Wikidebates coexistence on a disposable MediaWiki 1.43 lab. */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

check(ExtensionRegistry::getInstance()->isLoaded('WikiSEO'), 'Coexistence lab explicitly loads real WikiSEO');

function seoLabOutput(string $title, bool $main = false): OutputPage {
	$context = $main ? RequestContext::getMain() : new RequestContext();
	$context->setTitle(Title::newFromText($title));
	$out = $context->getOutput();
	$out->setArticleFlag(true);
	$out->setRevisionTimestamp('20261123120000');
	return $out;
}

function seoLabRender(OutputPage $out, bool $nativeFirst): array {
	if ($nativeFirst) {
		Metadata::beforePageDisplay($out);
	}
	$legacy = new \MediaWiki\Extension\WikiSEO\WikiSEO();
	$legacy->setMetadataFromPageProps($out);
	$legacy->addMetadataToPage($out);
	if (!$nativeFirst) {
		Metadata::beforePageDisplay($out);
	}
	$head = implode("\n", $out->getHeadLinksArray()) . "\n" .
		implode("\n", $out->getHeadItemsArray());
	return [$head, $out->getHTMLTitle()];
}

function seoLabMeta(string $html): array {
	$tags = [];
	preg_match_all(
		'~<meta\b[^>]*\b(?:name|property)="([^"]+)"[^>]*\bcontent="([^"]*)"[^>]*>~i',
		$html,
		$matches,
		PREG_SET_ORDER
	);
	foreach ($matches as $match) {
		$tags[$match[1]][] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
	return $tags;
}

function seoLabNative(OutputPage $out, array $data): void {
	$parser = new ParserOutput();
	$parser->setExtensionData(Metadata::DATA_KEY, $data);
	Metadata::onOutputPageParserOutput($out, $parser);
}

$originalImage = 'https://fr.wikidebates.org/w/resources/assets/logo-Wikidebats-Twitter.png';
$debate = seoLabOutput('SEO coexistence debate', true);
seoLabNative($debate, [
	'wd_kind' => 'debate',
	'wd_creation_date' => '2020-12-31',
	'title' => 'Faut-il débattre ? – Wikidébats',
	'description' => 'Débat de test avec des arguments pour et contre.',
	'image' => $originalImage,
	'image_alt' => 'Balance de la raison',
	'site_name' => 'Wikidébats',
	'locale' => 'fr_FR',
	'twitter_site' => '@Wikidebats',
	'robots' => 'index,follow',
]);
$debate->setProperty('title', 'ANCIEN TITRE WIKISEO');
$debate->setProperty('description', 'ANCIENNE DESCRIPTION WIKISEO');
$debate->setProperty('robots', 'index,follow');
$debate->setProperty('image', 'https://example.test/obsolete.png');
$debate->addMeta('google-site-verification', 'STILL-VERIFIED');
$debate->setIndexPolicy('noindex');
[$debateHead, $debateTitle] = seoLabRender($debate, true);
$debateTags = seoLabMeta($debateHead);
same('Faut-il débattre ? – Wikidébats', $debateTitle, 'Native title wins even if WikiSEO is called after it');
same(['website'], $debateTags['og:type'] ?? null, 'Only native debate website Open Graph type');
same([$originalImage], $debateTags['og:image'] ?? null, 'Native image wins over stale WikiSEO image');
same(['Débat de test avec des arguments pour et contre.'], $debateTags['description'] ?? null, 'Native description replaces legacy value');
same(['summary_large_image'], $debateTags['twitter:card'] ?? null, 'Only one X card');
same([$originalImage], $debateTags['twitter:image'] ?? null, 'Native X image is the exact existing asset');
same(['STILL-VERIFIED'], $debateTags['google-site-verification'] ?? null, 'Global verification is unaffected');
check(!isset($debateTags['article:author']), 'Invalid Open Graph collective author is absent on debate');
check(!isset($debateTags['article:published_time']), 'No article dates on debate');
check(!str_contains($debateHead, 'ANCIENNE DESCRIPTION WIKISEO'), 'No stale description in HTML');
check(!str_contains($debateHead, 'obsolete.png'), 'No stale social image in HTML');
check(!str_contains($debateHead, 'application/ld+json'), 'WikiSEO generic JSON-LD suppressed on debate');
same('noindex', $debate->getIndexPolicy(), 'MediaWiki noindex policy remains in force');
check(in_array('noindex,follow', $debateTags['robots'] ?? [], true) ||
	in_array('noindex,nofollow', $debateTags['robots'] ?? [], true) ||
	in_array('noindex', $debateTags['robots'] ?? [], true), 'Robots HTML retains noindex');

$argument = seoLabOutput('SEO coexistence argument');
seoLabNative($argument, [
	'wd_kind' => 'argument',
	'wd_creation_date' => '2024-02-01',
	'wd_summary' => "'''Une affirmation''' détaillée.",
	'title' => 'Un argument – Wikidébats',
	'description' => 'Description de secours.',
	'image' => '',
	'site_name' => 'Wikidébats',
	'locale' => 'fr_FR',
]);
$argument->setProperty('image', 'https://example.test/obsolete.png');
[$argumentHead, $argumentTitle] = seoLabRender($argument, false);
$argumentTags = seoLabMeta($argumentHead);
same('Un argument – Wikidébats', $argumentTitle, 'Native argument title is used when WikiSEO ran first');
same(['article'], $argumentTags['og:type'] ?? null, 'Argument keeps article Open Graph type');
same(['Une affirmation détaillée.'], $argumentTags['description'] ?? null, 'Argument summary beats old description');
same(['2024-02-01T00:00:00Z'], $argumentTags['article:published_time'] ?? null, 'Argument uses creation-date');
same(['2026-11-23T12:00:00Z'], $argumentTags['article:modified_time'] ?? null, 'Argument uses revision date');
check(!isset($argumentTags['og:image']), 'No substitute social image is generated');
check(!isset($argumentTags['twitter:image']), 'No substitute X image is generated');
check(!isset($argumentTags['article:author']), 'Invalid article author string is suppressed');
check(!str_contains($argumentHead, 'obsolete.png'), 'No obsolete image survives WikiSEO');
check(!str_contains($argumentHead, 'application/ld+json'), 'WikiSEO generic JSON-LD suppressed on argument');

$ordinary = seoLabOutput('SEO ordinary project page');
$ordinary->setProperty('title', 'Ordinary page from WikiSEO');
$ordinary->setProperty('description', 'A manually entered description.');
$ordinary->setProperty('image', 'https://example.test/project.png');
[$ordinaryHead, $ordinaryTitle] = seoLabRender($ordinary, false);
$ordinaryTags = seoLabMeta($ordinaryHead);
same('Ordinary page from WikiSEO', $ordinaryTitle, 'Ordinary page title still managed by WikiSEO');
same(['A manually entered description.'], $ordinaryTags['description'] ?? null, 'WikiSEO description preserved on other pages');
same(['https://example.test/project.png'], $ordinaryTags['og:image'] ?? null, 'WikiSEO image preserved on other pages');
check(str_contains($ordinaryHead, 'application/ld+json'), 'WikiSEO generic structured data kept on other pages');

echo "OK: selective WikiSEO coexistence, debate/argument native head, other pages unchanged\n";
