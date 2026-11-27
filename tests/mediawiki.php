<?php

require __DIR__ . '/mediawikiEnvironment.php';
check(str_starts_with(MW_VERSION, '1.43.'), 'Run integration tests with MediaWiki 1.43');
$validator = new \JsonSchema\Validator;
$extensionManifest = json_decode(file_get_contents(dirname(__DIR__) . '/extension.json'));
$validator->validate($extensionManifest, (object)[
	'$ref' => 'file://' . MW_INSTALL_PATH . '/docs/extension.schema.v2.json'
]);
check($validator->isValid(), 'Extension manifest matches the MediaWiki 1.43 schema: ' . json_encode($validator->getErrors()));

use MediaWiki\Extension\DebatePHP\NativeTags;
use MediaWiki\Extension\DebatePHP\Hooks as DebateHooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
check(!$services->getMainConfig()->has('DebatePHPMode'), 'No production renderer mode configuration');
check(!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('DebatePHP'), 'No standalone DebatePHP extension is loaded');
if (!getenv('WD_TEST_EXTENSIONS')) {
	same(['Wikidebates'], array_keys(\MediaWiki\Registration\ExtensionRegistry::getInstance()->getAllThings()), 'One wfLoadExtension call is sufficient');
}
// Skin selection remains in the existing display hook; ResourceLoader ignores a 'skins' key.
foreach (['vector', 'vector-2022', 'minerva', 'minervaneue', 'minervaNeue'] as $skinName) {
	$skin = new class(['name' => $skinName]) extends \Skin {
		public function outputPage() {}
	};
	$out = new \MediaWiki\Output\OutputPage(new \MediaWiki\Context\RequestContext);
	\MediaWiki\Extension\Wikidebates\Hooks::onBeforePageDisplay($out, $skin);
	$expected = str_starts_with($skinName, 'vector') ? 'ext.wikidebates.boot.vector' : 'ext.wikidebates.boot.minerva';
	same([$expected], $out->getModules(), 'Existing display hook: ' . $skinName);
}
// Parser-cache extension data is transferred to OutputPage independently of WikiSEO.
$seoParserOutput = new \MediaWiki\Parser\ParserOutput();
$seoData = [
	'wd_kind' => 'argument',
	'title' => 'An argument – Wikidebates',
	'wd_creation_date' => '2024-05-01',
	'wd_summary' => 'A useful summary.',
];
$seoParserOutput->setExtensionData(
	\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY, $seoData
);
$seoPageOutput = new \MediaWiki\Output\OutputPage(new \MediaWiki\Context\RequestContext);
\MediaWiki\Extension\Wikidebates\Seo\Metadata::onOutputPageParserOutput(
	$seoPageOutput, $seoParserOutput
);
same( $seoData, $seoPageOutput->getProperty(\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY),
	'Native SEO data survives the ParserOutput to OutputPage boundary' );
if (!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
	$seoPageOutput->setTitle(Title::newFromText('Native SEO test'));
	$seoPageOutput->setArticleFlag(true);
	$seoPageOutput->setRevisionTimestamp('20261123094521');
	\MediaWiki\Extension\Wikidebates\Seo\Metadata::beforePageDisplay($seoPageOutput);
	same($seoData['title'], $seoPageOutput->getHTMLTitle(), 'Native SEO controls the browser title');
	$seoMeta = array_column($seoPageOutput->getMetaTags(), 1, 0);
	same('A useful summary.', $seoMeta['description'] ?? null, 'Native argument uses the summary');
	same('A useful summary.', $seoMeta['twitter:description'] ?? null, 'Native X card uses the summary');
	$seoHead = $seoPageOutput->getHeadItemsArray();
	check(str_contains(implode("\n", $seoHead), 'article:modified_time'), 'Native Open Graph has revision date');
	check(!str_contains(implode("\n", $seoHead), 'og:image'), 'No social image invented without an image');
}

foreach (array_keys((array)$extensionManifest->ResourceModules) as $module) {
	check($services->getResourceLoader()->getModule($module) !== null, 'ResourceLoader module retained: ' . $module);
}
$legacySeoPagePropHooks = 0;
if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
	$services->getHookContainer()->register('WikiSEOLuaPreAddPageProps',
		static function(array &$params) use (&$legacySeoPagePropHooks): bool {
			$legacySeoPagePropHooks++;
			return true;
		});
}
$sourceReads = [];
$sources = [
	'Template:Débat' => 'Template-Debat.php.wikitext',
	'Template:Argument' => 'Template-Argument.php.wikitext'
];
$resources = dirname(__DIR__) . '/debatephp/resources/';
$fetch = static function($title) use (&$sources, &$sourceReads, $resources): array {
	$name = $title->getPrefixedText();
	$sourceReads[] = $name;
	if (str_starts_with($name, 'Module:')) { throw new RuntimeException('Unexpected runtime Lua dependency: ' . $name); }
	return [
		'text' => isset($sources[$name]) ? file_get_contents($resources . $sources[$name]) : false,
		'finalTitle' => $title,
		'deps' => [['title' => $title, 'page_id' => 0, 'rev_id' => 0]]
	];
};
$parse = static function(string $text) use ($services, $fetch): array {
	$parser = $services->getParserFactory()->create();
	$options = ParserOptions::newFromAnon();
	$options->setTemplateCallback($fetch);
	$output = $parser->parse($text, Title::newFromText('Integration test'), $options);
	$dependencies = array_map(static fn(array $entry): array => [
		$entry['link']->getNamespace() . ':' . $entry['link']->getDBkey(), $entry['pageid'], $entry['revid']
	], $output->getLinkList(\MediaWiki\Parser\ParserOutputLinkTypes::TEMPLATE));
	return [$output->getText(), $output->getPageProperties(), $output->getCategoryNames(), $dependencies];
};
$count = 0;
$observationLog = $temporary . '/observations.log';
$GLOBALS['wgDebugLogGroups']['DebatePHP'] = $observationLog;
$fixtures = require __DIR__ . '/renderCases.php';
$fixtures['argument initialization'] = '{{#argument:render|lang=en|initialization=Pro argument@42|creation-date=2000-01-01}}';
foreach ($fixtures as $name => $text) {
	$canonical = $parse($text);
	$GLOBALS['wgDebatePHPProfiling'] = true;
	$GLOBALS['wgDebatePHPDiagnostics'] = true;
	try {
		same($canonical, $parse($text), 'Observations preserve canonical output: ' . $name);
	} finally {
		$GLOBALS['wgDebatePHPProfiling'] = false;
		$GLOBALS['wgDebatePHPDiagnostics'] = false;
	}
	check(strlen($canonical[0]) > 200, 'Substantial renderer output: ' . $name);
	check(!str_contains($canonical[0], '{{#debate:') && !str_contains($canonical[0], '{{#argument:'), 'Functions registered: ' . $name);
	check(str_contains($canonical[0], 'application/ld+json'), 'JSON-LD retained: ' . $name);
	if (preg_match('/^complete (debate|argument) (fr|en)$/D', $name, $parts)) {
		$kind = $parts[1];
		$lang = $parts[2];
		$credit = $lang === 'fr' ? 'Contributeurs de Wikidébats' : 'Wikidebates contributors';
		$matches = [];
		preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is', $canonical[0], $matches);
		$pageNode = null;
		$graph = [];
		foreach ($matches[1] as $script) {
			$data = json_decode($script, true);
			if (!is_array($data) || !is_array($data['@graph'] ?? null)) {
				continue;
			}
			foreach ($data['@graph'] as $node) {
				if (is_array($node) && in_array('WebPage', (array)($node['@type'] ?? []), true)) {
					$pageNode = $node;
					$graph = $data['@graph'];
					break 2;
				}
			}
		}
		check(is_array($pageNode), 'Specialized page exists in JSON-LD: ' . $name);
		same($credit, $pageNode['creditText'] ?? null, 'Localized collective credit in JSON-LD: ' . $name);
		same(
			['@type' => 'Organization', 'name' => $credit],
			$pageNode['author'] ?? null,
			'Collective Organization author on the page only: ' . $name
		);
		$publisherId = $pageNode['publisher']['@id'] ?? null;
		check(is_string($publisherId) && str_ends_with($publisherId, '#organization'),
			'Publisher is a Wikidebates Organization reference: ' . $name);
		$publisherNodes = array_filter($graph, static fn($node): bool =>
			is_array($node) && ($node['@type'] ?? '') === 'Organization' && ($node['@id'] ?? '') === $publisherId
		);
		same(1, count($publisherNodes), 'Existing publisher node is reused: ' . $name);
		// These fixtures are raw parser previews, not saved revisions.
		check(!isset($pageNode['datePublished']), 'Unsaved fixture has no fabricated publication date: ' . $name);
		if ($kind === 'argument') {
			foreach ($graph as $node) {
				if (($node['@type'] ?? null) === 'Claim') {
					check(!isset($node['author']), 'Claim is not assigned a collective intellectual author: ' . $name);
					check(!isset($node['datePublished']), 'Claim is not assigned the page creation date: ' . $name);
				}
			}
		}
	}
	check(!array_filter($canonical[3], static fn(array $dep): bool => str_starts_with($dep[0], '828:')), 'No artificial Lua dependencies: ' . $name);
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('ShortDescription')
		&& (str_contains($text, '#debate:') || str_contains($text, '{{Débat|'))) {
		check(isset($canonical[1]['shortdesc']), 'ShortDescription callback retained: ' . $name);
	}
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
		foreach (['wikidebates-test', 'title', 'keywords', 'description', 'image', 'author'] as $legacyKey) {
			check(!isset($canonical[1][$legacyKey]), 'No WikiSEO page property for native page: ' . $legacyKey . ' / ' . $name);
		}
	}
	$count++;
}
$profileEvents = [];
foreach (file($observationLog, FILE_IGNORE_NEW_LINES) as $line) {
	$start = strpos($line, '{');
	if ($start === false) { continue; }
	$event = json_decode(substr($line, $start), true, 512, JSON_THROW_ON_ERROR);
	if ($event['event'] !== 'profile') { continue; }
	same($event['counters']['preprocess_executed'], array_sum(array_column($event['preprocessing'], 'calls')), 'Production hook reason counters agree');
	$profileEvents[] = $event;
}
same(2 * $count, count($profileEvents), 'Each observed invocation emits one profile');
if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
	same(0, $legacySeoPagePropHooks, 'Native SEO never invokes the WikiSEO parser page-property hook');
}

$csvHits = 0; $csvBypassed = 0;
foreach ($profileEvents as $event) {
	check(isset($event['preparation']), 'Real hooks expose pure preprocessing observations');
	$p = $event['preparation'];
	same($p['calls'], $p['hits'] + $p['misses'] + $p['bypassed'] + $p['failures'], 'CSV observations account for all calls');
	check($p['entries'] <= 256 && $p['peak_payload_bytes'] <= 131072, 'Real parser respects CSV retention bounds');
	$csvHits += $p['hits']; $csvBypassed += $p['bypassed'];
}
check($csvHits > 0 && $csvBypassed > 0, 'Real keyword/section reuse and empty-field bypass are observed');

// Exercise Page Forms through a genuine child frame, with literal delimiters in values.
if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('PageForms')) {
	$parser = $services->getParserFactory()->create();
	$options = ParserOptions::newFromAnon();
	$options->setTemplateCallback($fetch);
	$parser->startExternalParse(Title::newFromText('Integration test'), $options, $parser::OT_HTML);
	$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
		$parser, $parser->getPreprocessor()->newFrame(), [], true
	);
	$button = $context->autoEdit([
		'form' => 'Test form', 'target' => 'Target page', 'link text' => 'Finish | A=B',
		'query string' => 'Test[field]=A%7CB%3DC', 'reload' => true
	]);
	$html = $parser->getStripState()->unstripBoth($button);
	check(str_contains($html, 'autoedit-trigger'), 'Registered Page Forms callback renders a button');
	check(str_contains($html, 'Finish | A=B'), 'Structured autoedit preserves delimiters');
	check(!Title::newFromText('Target page')->exists(), 'Rendering autoedit never edits a page');
}

// The original parser function remains active alongside the two new functions.
$original = $parse('{{#recentdebatechanges:}}');
check(!str_contains($original[0], '{{#recentdebatechanges:'), 'Existing parser function remains registered');
if (!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('SemanticMediaWiki')) {
	check(!str_contains($original[0], 'DBQueryError') && !str_contains($original[0], 'SELECT'), 'Missing SMW is handled before any semantic SQL query');
}
$config = $services->getMainConfig();
same(false, $config->get('DebatePHPProfiling'), 'Profiling defaults to off');
same(false, $config->get('DebatePHPDiagnostics'), 'Diagnostics default to off');
// Wikidebates does not install a public htmltag hook; any external hook is independent.
$parser = $services->getParserFactory()->create();
$parser->firstCallInit();
if (!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('HTMLTags')) {
	check(!in_array('htmltag', $parser->getTags(), true), 'No public htmltag hook registered');
}
$parser->startExternalParse(Title::newFromText('Integration test'), ParserOptions::newFromAnon(), $parser::OT_HTML);
$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
	$parser, $parser->getPreprocessor()->newFrame(), [], true
);
$link = $context->htmlTag('a', 'Open A=B | C', [
	'href' => 'javascript:void(0)',
	'data-href' => '/wiki/Test?x=1&amp;y=2',
	'class' => 'wk-btn__a wk-js-nav',
	'onclick' => 'alert(1)'
], true);
$html = $parser->getStripState()->unstripBoth($link);
check(str_contains($html, 'href="javascript:void(0)"'), 'Legacy inert RunQuery href retained');
check(str_contains($html, 'data-href="/wiki/Test?x=1&amp;y=2"'), 'Encoded query URL retained');
check(str_contains($html, 'Open A=B | C'), 'Link label keeps delimiters');
check(!str_contains($html, 'onclick'), 'Unsupported attributes filtered');
$link = $context->htmlTag('a', 'Safe label', [
	'href' => 'javascript:alert(1)', 'data-href' => 'java&#x73;cript:alert(1)', 'class' => 'wk-js-nav'
], true);
$html = $parser->getStripState()->unstripBoth($link);
check(!str_contains($html, 'javascript:alert'), 'Unsafe link targets excluded');
rejects(static fn() => $context->htmlTag('script', 'alert(1)',
	[], true), 'Arbitrary script rendering disabled');
rejects(static fn() => $context->htmlTag('a', 'body',
	[], false), 'Generic tag fallback disabled');

rejects(static fn() => $parse('{{#debate:invalid}}'), 'Invalid renderer entry rejected');
same([], array_values(array_filter($sourceReads, static fn(string $name): bool => str_starts_with($name, 'Module:'))), 'No module source read by the canonical renderer');
check(!class_exists(\MediaWiki\Extension\DebatePHP\LiteralData::class, false), 'Runtime never loads the offline Lua reader');
require __DIR__ . '/mediawikiOperations.php';
require __DIR__ . '/mediawikiRevisionVisibility.php';
require __DIR__ . '/mediawikiParentCAS.php';

// A timestamp supplied to ParserOutput alone is not proof of a stored revision.
$parser = $services->getParserFactory()->create();
$parser->startExternalParse(Title::newFromText('Native SEO timestamps'), ParserOptions::newFromAnon(), $parser::OT_HTML);
$parser->getOutput()->setRevisionTimestamp('20261123094521');
$seoContext = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
	$parser, $parser->getPreprocessor()->newFrame(), [], true
);
$seoSchema = [
	'@context' => 'https://schema.org',
	'@graph' => [
		1 => [ '@type' => 'WebSite', '@id' => 'https://example.org/#website', 'url' => 'https://example.org/' ],
		2 => [ '@type' => 'QAPage', '@id' => 'https://example.org/test#qa', 'url' => 'https://example.org/test' ],
	],
];
$seoJson = $parser->getStripState()->unstripBoth($seoContext->jsonLdData($seoSchema));
check(!str_contains($seoJson, '2026-11-23T09:45:21Z'), 'ParserOutput-only timestamp is not published');
check(!str_contains($seoJson, 'dateModified'), 'No stored revision means no dateModified');
check($parser->getOutput()->getOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_REVISION),
	'Unstored parse requires canonical reparse');

// Reproduce the actual ContentRenderer ordering, not just an already-filled
// ParserOutput. A real stored revision is rendered through MediaWiki's normal
// RevisionRenderer, with the timestamp unavailable in ParserOutput until the
// renderer finishes. Use two historical revision timestamps to detect any
// accidental fallback to the current revision or the rendering clock.
$seoTitle = Title::newFromText('SEO revision timestamp fixture');
$seoUser = \MediaWiki\User\User::newSystemUser('Wikidebates SEO fixture', ['steal' => false]);
check($seoUser !== null, 'SEO fixture user created');
$seoRevisions = [];
try {
	foreach ([
		['debate', '20241121091530'],
		['argument', '20251122143542']
	] as [$kind, $timestamp]) {
		\MWTimestamp::setFakeTime($timestamp);
		$seoWikiPage = $services->getWikiPageFactory()->newFromTitle($seoTitle);
		$updater = $seoWikiPage->newPageUpdater($seoUser);
		$updater->setContent(
			\MediaWiki\Revision\SlotRecord::MAIN,
			new \MediaWiki\Content\WikitextContent(
				'{{#' . $kind . ':render|lang=en|creation-date=2020-05-01|summary=Revision fixture}}'
			)
		);
		$updater->saveRevision(\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment('SEO date fixture'));
		check($updater->wasSuccessful(), 'Stored SEO revision: ' . $kind);
		$revision = $updater->getNewRevision();
		same($timestamp, $revision->getTimestamp(), 'Real stored revision timestamp: ' . $kind);
		$seoRevisions[] = [$kind, $revision];
	}
} finally {
	\MWTimestamp::setFakeTime(false);
}
check($seoRevisions[0][1]->getId() !== $seoRevisions[1][1]->getId(), 'Distinct stored revisions');
foreach ($seoRevisions as [$kind, $revision]) {
	$rendered = $services->getRevisionRenderer()->getRenderedRevision(
		$revision, ParserOptions::newFromAnon()
	);
	$output = $rendered->getRevisionParserOutput();
	$expected = wfTimestamp(TS_ISO_8601, $revision->getTimestamp());
	same($revision->getTimestamp(), $output->getRevisionTimestamp(),
		'ContentRenderer finishes with the correct revision timestamp: ' . $kind);
	check($output->getOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_REVISION_TIMESTAMP),
		'Timestamp-dependent output is marked for reparse if a speculative timestamp changes: ' . $kind);
	same($revision->getTimestamp(), $output->getRevisionTimestampUsed(),
		'MediaWiki records the exact timestamp used by JSON-LD: ' . $kind);
	$nodes = [];
	preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is', $output->getText(), $matches);
	foreach ($matches[1] as $script) {
		$graph = json_decode($script, true, 512, JSON_THROW_ON_ERROR);
		foreach (($graph['@graph'] ?? []) as $node) {
			if (in_array('WebPage', (array)($node['@type'] ?? []), true)) {
				$nodes[] = $node;
			}
		}
	}
	same(1, count($nodes), 'Exactly one specialized JSON-LD page: ' . $kind);
	same($expected, $nodes[0]['dateModified'] ?? null,
		'JSON-LD uses the exact rendered revision, including an old revision: ' . $kind);
	same('2024-11-21T09:15:30Z', $nodes[0]['datePublished'] ?? null,
		'The first stored revision supplies publication date: ' . $kind);
	same('2024-11-21T09:15:30Z',
		$output->getExtensionData(\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY)['wd_published_time'] ?? null,
		'Same native publication date in parser metadata: ' . $kind);
}
// Reproduce old SMW's ParserData::markParserOutput behavior: it writes
// the current time into ParserOutput during parsing. The exact stored revision
// must win even when the competing timestamp is already present.
foreach ($seoRevisions as [$kind, $revision]) {
	$parser = $services->getParserFactory()->create();
	$parser->startExternalParse($seoTitle, ParserOptions::newFromAnon(), $parser::OT_HTML, true, $revision->getId());
	$parser->getOutput()->setRevisionTimestamp('20261123094521');
	$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
		$parser, $parser->getPreprocessor()->newFrame(), [], true
	);
	$script = $parser->getStripState()->unstripBoth($context->jsonLdData($seoSchema));
	check(str_contains($script, wfTimestamp(TS_ISO_8601, $revision->getTimestamp())), 'Stored revision wins over parser timestamp: ' . $kind);
	check(!str_contains($script, '2026-11-23T09:45:21Z'), 'No competing render timestamp in JSON-LD: ' . $kind);
	same($revision->getTimestamp(), $parser->getOutput()->getRevisionTimestampUsed(),
		'Exact stored timestamp recorded for invalidation: ' . $kind);
}
// An ordinary parser preview must not invent a date from the clock or inherit
// the existing page's latest revision when no revision is being rendered.
$preview = $services->getParserFactory()->create()->parse(
	'{{#debate:render|lang=en|creation-date=2020-05-01}}',
	$seoTitle, ParserOptions::newFromAnon()
);
check(!str_contains($preview->getText(), '"dateModified"'), 'No fabricated modification date in preview');
check(!str_contains($preview->getText(), '"datePublished"'), 'No fabricated publication date in preview');

// The real Parser also allows a revision callback to supply an unsaved
// MutableRevisionRecord (e.g. an edit preview with self-transclusion).
// A timestamp on that object must not be treated as a published modification.
$unsaved = new \MediaWiki\Revision\MutableRevisionRecord($seoTitle->toPageIdentity());
$unsaved->setTimestamp('20261020131415');
$unsaved->setUser($seoUser);
$unsaved->setContent(\MediaWiki\Revision\SlotRecord::MAIN,
	new \MediaWiki\Content\WikitextContent('{{#debate:render|lang=en}}'));
check(!$unsaved->getId(), 'Speculative revision has not been saved');
$previewOptions = ParserOptions::newFromAnon();
$previewOptions->setCurrentRevisionRecordCallback(static fn() => $unsaved);
foreach (['debate', 'argument'] as $kind) {
	$preview = $services->getParserFactory()->create()->parse(
		'{{#' . $kind . ':render|lang=en|summary=Speculative content}}',
		$seoTitle, $previewOptions
	);
	check(!str_contains($preview->getText(), '"dateModified"'), 'Unsaved revision callback does not publish dateModified: ' . $kind);
	check(!str_contains($preview->getText(), '"datePublished"'), 'Unsaved revision callback does not publish datePublished: ' . $kind);
	check($preview->getOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_REVISION),
		'Unsaved revision requires canonical reparse: ' . $kind);
}



echo "OK: MediaWiki " . MW_VERSION . ", $count canonical render fixtures, single extension load, hooks, policy and no runtime Lua dependencies\n";
