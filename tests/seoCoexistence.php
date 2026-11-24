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

// Read the payload and revision at the actual head boundary, not from a
// previously computed array. Late output changes must not produce stale tags.
$lateOutput = seoLabOutput('SEO late revision');
$lateData = [
	'wd_kind' => 'argument',
	'title' => "'''Original title'''",
	'wd_summary' => 'Original summary',
	'robots' => 'index,follow',
	'wd_creation_date' => '2024-02-01',
];
seoLabNative($lateOutput, $lateData);
Metadata::beforePageDisplay($lateOutput);
same('Original title', $lateOutput->getHTMLTitle(), 'Early native title is still normalized');
$lateData['wd_summary'] = 'Updated summary';
seoLabNative($lateOutput, $lateData);
$lateOutput->setRevisionTimestamp('20261124101500');
$lateHead = implode("\n", $lateOutput->getHeadLinksArray()) . "\n" .
	implode("\n", $lateOutput->getHeadItemsArray());
$lateTags = seoLabMeta($lateHead);
same(['Updated summary'], $lateTags['description'] ?? null, 'Final head reads the current payload');
same(['2026-11-24T10:15:00Z'], $lateTags['article:modified_time'] ?? null,
	'Final head reads the current revision timestamp');
check(!str_contains($lateHead, 'Original summary'), 'No early stale metadata in final head');
$lateHeadAgain = implode("\n", $lateOutput->getHeadLinksArray()) . "\n" .
	implode("\n", $lateOutput->getHeadItemsArray());
same($lateTags, seoLabMeta($lateHeadAgain), 'Repeated head assembly keeps the same metadata without duplicates');

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

	// Exercise the real modern hook together with the final native head, not
	// only the legacy full-mode compatibility path tested above.
	$nativeOutput->setIndexPolicy('noindex');
	$nativeOutput->setFollowPolicy('nofollow');
	$nativeOutput->addMeta('google-site-verification', 'GLOBAL-VERIFICATION');
	Metadata::beforePageDisplay($nativeOutput);
	$nativeHead = implode("\n", $nativeOutput->getHeadLinksArray()) . "\n" .
		implode("\n", $nativeOutput->getHeadItemsArray());
	$nativeTags = seoLabMeta($nativeHead);
	same(['Native Wikidebates description'], $nativeTags['description'] ?? null,
		'Modern global-only mode emits exactly one native description');
	same(['website'], $nativeTags['og:type'] ?? null, 'Modern global-only mode retains native Open Graph');
	same(['GLOBAL-VERIFICATION'], $nativeTags['google-site-verification'] ?? null,
		'Modern global-only mode preserves global verification');
	same('noindex', $nativeOutput->getIndexPolicy(), 'Modern global-only mode preserves noindex');
	same('nofollow', $nativeOutput->getFollowPolicy(), 'Modern global-only mode preserves nofollow');
	check(!str_contains($nativeHead, 'application/ld+json'), 'Modern global-only mode adds no competing head JSON-LD');

	// A specialized-looking title is not enough to opt out. An old cached
	// ParserOutput with legacy page properties must continue using WikiSEO.
	$legacyOutput = seoLabOutput('Old cached debate?');
	$legacyOutput->setProperty('description', 'Legacy parser cache description');
	same('full', $policy::forOutput($legacyOutput), 'A marker-less old cached debate retains WikiSEO');
	$hook->onBeforePageDisplay($legacyOutput, $skin);
	$legacyHead = implode("\n", $legacyOutput->getHeadLinksArray()) . "\n" .
		implode("\n", $legacyOutput->getHeadItemsArray());
	$legacyTags = seoLabMeta($legacyHead);
	same(['Legacy parser cache description'], $legacyTags['description'] ?? null,
		'Legacy description is preserved until the native marker is available');
	$projectOutput = seoLabOutput('Ordinary project page remains automatic');
	$hook->onBeforePageDisplay($projectOutput, $skin);
	check(isset($projectOutput->getHeadItemsArray()['jsonld-metadata']),
		'Ordinary page continues producing WikiSEO JSON-LD');

	// A manually authored #seo parser function still works on ordinary pages.
	$services = \MediaWiki\MediaWikiServices::getInstance();
	$parser = $services->getParserFactory()->create();
	$options = \MediaWiki\Parser\ParserOptions::newFromAnon();
	$manualTitle = \MediaWiki\Title\Title::newFromText('SEO manual parser project');
	$manualParserOutput = $parser->parse(
		'{{#seo:|title=Manual project SEO title|description=Manual SEO description|og_type=website|schema_type=AboutPage}}',
		$manualTitle,
		$options
	);
	$props = $manualParserOutput->getPageProperties();
	same('Manual project SEO title', $props['title'] ?? null, '#seo retains manually authored title');
	same('Manual SEO description', $props['description'] ?? null, '#seo retains authored description');
	$manual = seoLabOutput($manualTitle->getPrefixedText());
	foreach ($props as $key => $value) {
		$manual->setProperty($key, $value);
	}
	$hook->onBeforePageDisplay($manual, $skin);
	$manualHead = $manual->getHeadItemsArray();
	check(str_contains($manualHead['og:title'] ?? '', 'Manual project SEO title'),
		'Manually chosen SEO title takes priority in Open Graph');
	check(str_contains($manualHead['og:description'] ?? '', 'Manual SEO description'),
		'Manually chosen SEO description is preserved in Open Graph');
	check(str_contains($manualHead['jsonld-metadata'] ?? '', 'AboutPage'),
		'Manually chosen Schema.org page type remains available');

	// The old <seo> tag syntax must not disappear when a page is not opted out.
	$tagTitle = \MediaWiki\Title\Title::newFromText('SEO manual tag project');
	$tagParsed = $services->getParserFactory()->create()->parse(
		'<seo title="Manual tag title" description="Manual tag description"></seo>',
		$tagTitle,
		$options
	);
	$tagProps = $tagParsed->getPageProperties();
	same('Manual tag title', $tagProps['title'] ?? null, 'Old <seo> tag retains title support');
	same('Manual tag description', $tagProps['description'] ?? null, 'Old <seo> tag retains description support');

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


// Compare the actual native parser graph of saved revisions to the head
// assembled by OutputPage with WikiSEO loaded. No synthetic payload here.
$services = \MediaWiki\MediaWikiServices::getInstance();
$user = \MediaWiki\User\User::newSystemUser('SEO end-to-end fixture', ['steal' => false]);
check($user !== null, 'Saved SEO fixture user');
foreach (['fr', 'en'] as $lang) {
	foreach (['debate', 'argument'] as $kind) {
		$title = Title::newFromText('SEO end-to-end ' . $kind . ' ' . $lang);
		$revisions = [];
		foreach (['20240512143725', '20250604102539'] as $i => $timestamp) {
			try {
				\MWTimestamp::setFakeTime($timestamp);
				$page = $services->getWikiPageFactory()->newFromTitle($title);
				$updater = $page->newPageUpdater($user);
				$updater->setContent(\MediaWiki\Revision\SlotRecord::MAIN,
					new \MediaWiki\Content\WikitextContent('{{#' . $kind . ':render|lang=' . $lang .
						"|creation-date=2000-01-01|summary=Version " . $i . " avec ''emphase'' &amp; <nowiki>[[A|B]]</nowiki>}}"));
				$updater->saveRevision(\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('SEO head and graph'));
				check($updater->wasSuccessful(), 'Saved SEO revision fixture');
				$revisions[] = $updater->getNewRevision();
				$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
			} finally {
				\MWTimestamp::setFakeTime(false);
			}
		}
		foreach ($revisions as $i => $revision) {
			$label = $lang . '/' . $kind . '/' . $i;
			$parsed = $services->getRevisionRenderer()->getRenderedRevision($revision,
				\MediaWiki\Parser\ParserOptions::newFromAnon())->getRevisionParserOutput();
			$payload = $parsed->getExtensionData(Metadata::DATA_KEY);
			same($kind, $payload['wd_kind'] ?? null, 'Actual native payload: ' . $label);
			preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is',
				$parsed->getText(), $matches);
			same(1, count($matches[1]), 'One body graph: ' . $label);
			$graph = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR)['@graph'];
			$pages = array_values(array_filter($graph, static fn($node) =>
				in_array('WebPage', (array)($node['@type'] ?? []), true)));
			same(1, count($pages), 'One current page node: ' . $label);
			$resource = $pages[0];
			$out = seoLabOutput($title->getPrefixedText());
			$out->setRevisionTimestamp($revision->getTimestamp());
			Metadata::onOutputPageParserOutput($out, $parsed);
			$out->setProperty('description', 'Stale WikiSEO description');
			$out->addMeta('google-site-verification', 'KEEP-VERIFICATION');
			$out->setIndexPolicy('noindex');
			// Exercise both loading orders on the old and current revisions.
			if (class_exists(\MediaWiki\Extension\WikiSEO\PagePolicy::class)) {
				if ($i === 0) {
					Metadata::beforePageDisplay($out);
				}
				$hook->onBeforePageDisplay($out, $skin);
				if ($i !== 0) {
					Metadata::beforePageDisplay($out);
				}
				$head = implode("\n", $out->getHeadLinksArray()) . "\n" . implode("\n", $out->getHeadItemsArray());
				$htmlTitle = $out->getHTMLTitle();
			} else {
				[$head, $htmlTitle] = seoLabRender($out, $i === 0);
			}
			$tags = seoLabMeta($head);
			same($lang, $resource['inLanguage'], 'Content language: ' . $label);
			foreach (['description', 'og:description', 'twitter:description'] as $field) {
				same([$resource['description']], $tags[$field] ?? null, 'Head and graph description: ' . $label . '/' . $field);
			}
			same([$htmlTitle], $tags['og:title'] ?? null, 'One native Open Graph title: ' . $label);
			same([$htmlTitle], $tags['twitter:title'] ?? null, 'Same X title: ' . $label);
			same([$resource['url']], $tags['og:url'] ?? null, 'Same page URL: ' . $label);
			same([$lang === 'fr' ? 'fr_FR' : 'en_US'], $tags['og:locale'] ?? null, 'Content locale: ' . $label);
			same([$kind === 'argument' ? 'article' : 'website'], $tags['og:type'] ?? null, 'Approved Open Graph type: ' . $label);
			same('2024-05-12T14:37:25Z', $resource['datePublished'], 'Actual first saved date: ' . $label);
			same(wfTimestamp(TS_ISO_8601, $revision->getTimestamp()), $resource['dateModified'], 'Actual displayed revision: ' . $label);
			if ($kind === 'argument') {
				same([$resource['datePublished']], $tags['article:published_time'] ?? null, 'Same publication date in head: ' . $label);
				same([$resource['dateModified']], $tags['article:modified_time'] ?? null, 'Same modification date in head: ' . $label);
			} else {
				check(!isset($tags['article:published_time'], $tags['article:modified_time']), 'No article dates on debate: ' . $label);
			}
			$image = trim((string)($payload['image'] ?? ''));
			same($image !== '' ? [$image] : null, $tags['og:image'] ?? null, 'Only existing social image: ' . $label);
			same($image !== '' ? [$image] : null, $tags['twitter:image'] ?? null, 'Same image on X: ' . $label);
			same(['KEEP-VERIFICATION'], $tags['google-site-verification'] ?? null, 'Global verification untouched: ' . $label);
			same('noindex', $out->getIndexPolicy(), 'Stronger robots policy survives: ' . $label);
			check(!str_contains($head, 'application/ld+json'), 'No competing head graph: ' . $label);
			check(!str_contains($head, 'Stale WikiSEO description'), 'No stale competing description: ' . $label);
		}
	}
}
echo "OK: actual saved FR/EN debate and argument revisions have consistent head and JSON-LD metadata\n";
