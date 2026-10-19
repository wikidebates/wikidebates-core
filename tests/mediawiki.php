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
same('reference', $services->getMainConfig()->get('DebatePHPMode'), 'Reference is the default mode');
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
foreach (array_keys((array)$extensionManifest->ResourceModules) as $module) {
	check($services->getResourceLoader()->getModule($module) !== null, 'ResourceLoader module retained: ' . $module);
}
if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
	$services->getHookContainer()->register('WikiSEOLuaPreAddPageProps', static function(array &$params): bool {
		$params['wikidebates-test'] = 'seen';
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
$parse = static function(string $text, string $mode) use ($services, $fetch): array {
	$GLOBALS['wgDebatePHPMode'] = $mode;
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
	$reference = $parse($text, 'reference');
	$optimized = $parse($text, 'optimized');
	same($reference, $optimized, 'Renderer output and parser effects: ' . $name);
	$GLOBALS['wgDebatePHPProfiling'] = true;
	$GLOBALS['wgDebatePHPDiagnostics'] = true;
	try {
		same($reference, $parse($text, 'reference'), 'Observations preserve reference output: ' . $name);
		same($optimized, $parse($text, 'optimized'), 'Observations preserve optimized output: ' . $name);
	} finally {
		$GLOBALS['wgDebatePHPProfiling'] = false;
		$GLOBALS['wgDebatePHPDiagnostics'] = false;
	}
	check(strlen($reference[0]) > 200, 'Substantial renderer output: ' . $name);
	check(!str_contains($reference[0], '{{#debate:') && !str_contains($reference[0], '{{#argument:'), 'Functions registered: ' . $name);
	check(str_contains($reference[0], 'application/ld+json'), 'JSON-LD retained: ' . $name);
	check(!array_filter($reference[3], static fn(array $dep): bool => str_starts_with($dep[0], '828:')), 'No artificial Lua dependencies: ' . $name);
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('ShortDescription')
		&& (str_contains($text, '#debate:') || str_contains($text, '{{Débat|'))) {
		check(isset($reference[1]['shortdesc']), 'ShortDescription callback retained: ' . $name);
	}
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
		same('seen', $reference[1]['wikidebates-test'] ?? null, 'WikiSEO validation, hook and page properties: ' . $name);
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

// Exercise Page Forms through a genuine child frame, with literal delimiters in values.
if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('PageForms')) {
	$parser = $services->getParserFactory()->create();
	$options = ParserOptions::newFromAnon();
	$options->setTemplateCallback($fetch);
	$parser->startExternalParse(Title::newFromText('Integration test'), $options, $parser::OT_HTML);
	$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
		$parser, $parser->getPreprocessor()->newFrame(), [], true
	);
	$button = $context->autoEdit(new \MediaWiki\Extension\DebatePHP\Table([
		'form' => 'Test form', 'target' => 'Target page', 'link text' => 'Finish | A=B',
		'query string' => 'Test[field]=A%7CB%3DC', 'reload' => true
	]));
	$html = $parser->getStripState()->unstripBoth($button);
	check(str_contains($html, 'autoedit-trigger'), 'Registered Page Forms callback renders a button');
	check(str_contains($html, 'Finish | A=B'), 'Structured autoedit preserves delimiters');
	check(!Title::newFromText('Target page')->exists(), 'Rendering autoedit never edits a page');
}

// The original parser function remains active alongside the two new functions.
$original = $parse('{{#recentdebatechanges:}}', 'reference');
check(!str_contains($original[0], '{{#recentdebatechanges:'), 'Existing parser function remains registered');
if (!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('SemanticMediaWiki')) {
	check(!str_contains($original[0], 'DBQueryError') && !str_contains($original[0], 'SELECT'), 'Missing SMW is handled before any semantic SQL query');
}
$config = $services->getMainConfig();
same(false, $config->get('DebatePHPProfiling'), 'Profiling defaults to off');
same(false, $config->get('DebatePHPDiagnostics'), 'Diagnostics default to off');
$GLOBALS['wgDebatePHPHTMLAttributes'] = ['a' => ['href']];
same(['a' => ['href']], NativeTags::policy(), 'Explicit HTML attribute policy');
$GLOBALS['wgDebatePHPHTMLAttributes'] = null;
$GLOBALS['wgHTMLTagsAttributes'] = ['a' => ['class']];
same(['a' => ['class']], NativeTags::policy(), 'Legacy HTML Tags policy');
unset($GLOBALS['wgHTMLTagsAttributes']);
$native = $parse('<htmltag tagname="a" href="/test" class="allowed" onclick="bad()">body &amp; text</htmltag>', 'reference');
check(str_contains($native[0], 'class="allowed"'), 'Native HTML Tags retains allowed attributes');
check(!str_contains($native[0], 'onclick'), 'Native HTML Tags filters unsupported attributes');
check(str_contains($parse('<htmltag tagname="unknown">body</htmltag>', 'reference')[0], 'not supported'), 'Unsupported HTML tag message');
check(str_contains($parse('<htmltag>body</htmltag>', 'reference')[0], 'must be set'), 'Missing HTML tag name message');

// Registering DebatePHP must leave an already registered htmltag handler intact.
$parser = $services->getParserFactory()->create();
$parser->firstCallInit();
$parser->setHook('htmltag', static fn() => 'existing HTML Tags handler');
DebateHooks::onParserFirstCallInit($parser);
$output = $parser->parse('<htmltag tagname="a">body</htmltag>', Title::newFromText('Integration test'), ParserOptions::newFromAnon());
check(str_contains($output->getText(), 'existing HTML Tags handler'), 'Preserve external HTML Tags hook');

rejects(static fn() => $parse('{{#debate:render}}', 'invalid'), 'Invalid mode rejected');
same([], array_values(array_filter($sourceReads, static fn(string $name): bool => str_starts_with($name, 'Module:'))), 'No module source read in either mode');
check(!class_exists(\MediaWiki\Extension\DebatePHP\LiteralData::class, false), 'Runtime never loads the offline Lua reader');
require __DIR__ . '/mediawikiOperations.php';

echo "OK: MediaWiki " . MW_VERSION . ", $count render fixtures in both modes, single extension load, hooks, policy and no runtime Lua dependencies\n";
