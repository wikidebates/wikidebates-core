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
$mode = 'full';
Metadata::onWikiSEOPageMetadataMode($debate, $mode);
same('global-only', $mode, 'Native debate reduces WikiSEO to global-only metadata');
$mode = 'off';
Metadata::onWikiSEOPageMetadataMode($debate, $mode);
same('off', $mode, 'Native debate cannot undo a stronger off decision from another extension');
$parser = new ParserOutput();
$parser->setExtensionData(Metadata::DATA_KEY, ['wd_kind' => 'debate']);
$revisionMode = 'full';
Metadata::onWikiSEOPageRevisionMode($debate->getTitle(), $parser, $revisionMode);
same('global-only', $revisionMode, 'Native debate skips WikiSEO revision data updates');

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
if (isset($debateTags['robots'])) {
	check(str_contains(strtolower(implode(',', $debateTags['robots'])), 'noindex'),
		'Any emitted robots meta must retain MediaWiki noindex');
}

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
$mode = 'full';
Metadata::onWikiSEOPageMetadataMode($argument, $mode);
same('global-only', $mode, 'Native argument reduces WikiSEO to global-only metadata');

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
$mode = 'full';
Metadata::onWikiSEOPageMetadataMode($ordinary, $mode);
same('full', $mode, 'Ordinary pages retain the entire WikiSEO metadata engine');
$parser = new ParserOutput();
$revisionMode = 'full';
Metadata::onWikiSEOPageRevisionMode($ordinary->getTitle(), $parser, $revisionMode);
same('full', $revisionMode, 'Ordinary pages retain WikiSEO revision data updates');

$ordinary->setProperty('title', 'Ordinary page from WikiSEO');
$ordinary->setProperty('description', 'A manually entered description.');
$ordinary->setProperty('image', 'https://example.test/project.png');
[$ordinaryHead, $ordinaryTitle] = seoLabRender($ordinary, false);
$ordinaryTags = seoLabMeta($ordinaryHead);
same('Ordinary page from WikiSEO', $ordinaryTitle, 'Ordinary page title still managed by WikiSEO');
same(['A manually entered description.'], $ordinaryTags['description'] ?? null, 'WikiSEO description preserved on other pages');
same(['https://example.test/project.png'], $ordinaryTags['og:image'] ?? null, 'WikiSEO image preserved on other pages');
check(str_contains($ordinaryHead, 'application/ld+json'), 'WikiSEO generic structured data kept on other pages');

// New fork: exercise the real WikiSEO hook entrypoints as well as the old
// final-HTML coexistence strategy. The installed upstream 2.7.0 is still
// tested by the normal baseline CI when PagePolicy is not present.
if (class_exists(\MediaWiki\Extension\WikiSEO\PagePolicy::class)) {
	$policy = \MediaWiki\Extension\WikiSEO\PagePolicy::class;
	same('global-only', $policy::forOutput($debate), 'HookContainer opts debate out of page metadata');
	same('global-only', $policy::forOutput($argument), 'HookContainer opts argument out of page metadata');
	same('full', $policy::forOutput($ordinary), 'HookContainer preserves ordinary WikiSEO page metadata');

	$parserOutput = new ParserOutput();
	$parserOutput->setExtensionData(Metadata::DATA_KEY, ['wd_kind' => 'debate']);
	same('global-only', $policy::forRevision($debate->getTitle(), $parserOutput),
		'HookContainer opts native parser output out of revision updates');
	$parserOutput = new ParserOutput();
	same('full', $policy::forRevision($ordinary->getTitle(), $parserOutput),
		'HookContainer preserves ordinary page revision updates');

	$hook = new \MediaWiki\Extension\WikiSEO\Hooks\PageHooks(
		\MediaWiki\MediaWikiServices::getInstance()->getMainConfig()
	);
	$skin = new class(['name' => 'vector']) extends \Skin {
		public function outputPage() {}
	};
	$nativeOutput = seoLabOutput('Native policy without duplication');
	seoLabNative($nativeOutput, [
		'wd_kind' => 'debate',
		'title' => 'Native Wikidebates title',
		'description' => 'Native Wikidebates description',
		'robots' => 'index,follow',
	]);
	$hook->onBeforePageDisplay($nativeOutput, $skin);
	check(!isset($nativeOutput->getHeadItemsArray()['jsonld-metadata']),
		'WikiSEO JSON-LD is not generated at all for native debate');
	check(!isset($nativeOutput->getHeadItemsArray()['og:title']),
		'WikiSEO Open Graph generator is skipped before execution for native debate');
	$projectOutput = seoLabOutput('Ordinary project page remains automatic');
	$hook->onBeforePageDisplay($projectOutput, $skin);
	check(isset($projectOutput->getHeadItemsArray()['jsonld-metadata']),
		'Ordinary page continues producing WikiSEO JSON-LD');
	check(isset($projectOutput->getHeadItemsArray()['og:title']),
		'Ordinary page continues producing WikiSEO Open Graph');

	// Any other extension or site administrator can opt out a single ordinary
	// page without disabling WikiSEO globally or changing neighboring pages.
	$services = \MediaWiki\MediaWikiServices::getInstance();
	$services->getHookContainer()->register('WikiSEOPageMetadataMode',
		static function($out, &$mode): void {
			if ($out->getTitle()?->getPrefixedText() === 'SEO explicitly disabled page') {
				$mode = 'off';
			}
		}
	);
	$off = seoLabOutput('SEO explicitly disabled page');
	same('off', $policy::forOutput($off), 'A custom extension may disable WikiSEO on one ordinary page');
	$hook->onBeforePageDisplay($off, $skin);
	check(!isset($off->getHeadItemsArray()['og:title']), 'Off mode does not produce Open Graph');
	check(!isset($off->getHeadItemsArray()['jsonld-metadata']), 'Off mode does not produce JSON-LD');
	$headLinks = [];
	$hook->onOutputPageAfterGetHeadLinksArray($headLinks, $off);
	same([], $headLinks, 'Off mode does not rewrite the canonical URL');

}

echo "OK: selective WikiSEO coexistence, debate/argument native head, other pages unchanged\n";
