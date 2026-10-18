<?php

/** Isolated MediaWiki 1.43 integration tests. No existing wiki settings or database is used. */
$install = getenv('MW_INSTALL_PATH');
if (!$install || !is_file($install . '/includes/Setup.php')) {
	fwrite(STDERR, "Set MW_INSTALL_PATH to a MediaWiki 1.43 checkout with vendor/ and extensions/Scribunto/.\n");
	exit(1);
}
if (!extension_loaded('pdo_sqlite')) { throw new RuntimeException('Tests require pdo_sqlite'); }
$temporary = sys_get_temp_dir() . '/wikidebates-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
register_shutdown_function(static function() use ($temporary): void {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
	rmdir($temporary);
});
$db = new PDO('sqlite:' . $temporary . '/wikidebates_test.sqlite');
$db->exec(file_get_contents($install . '/maintenance/sqlite/tables-generated.sql'));
$db = null;

define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', realpath($install));
define('MW_CONFIG_CALLBACK', 'wikidebatesTestConfig');
function wikidebatesTestConfig($settings): void {
	global $temporary;
	$settings->overrideConfigValues([
		'Server' => 'https://example.test',
		'ScriptPath' => '/w',
		'ArticlePath' => '/wiki/$1',
		'Sitename' => 'Wikidebates tests',
		'LanguageCode' => 'en',
		'DBtype' => 'sqlite',
		'DBname' => 'wikidebates_test',
		'SQLiteDataDir' => $temporary,
		'SecretKey' => 'isolated-wikidebates-tests',
		'CacheDirectory' => $temporary,
		'MainCacheType' => CACHE_NONE,
		'MessageCacheType' => CACHE_NONE,
		'ParserCacheType' => CACHE_NONE,
		'UseDatabaseMessages' => false,
		'JobRunRate' => 0,
		'ShowExceptionDetails' => true
	]);
	wfLoadExtension('Scribunto');
	foreach (array_filter(explode(',', getenv('WD_TEST_EXTENSIONS') ?: '')) as $extension) {
		if (!in_array($extension, ['PageForms', 'ShortDescription', 'Variables', 'VariablesLua', 'WikiSEO'], true)) {
			throw new RuntimeException('Unsupported test extension: ' . $extension);
		}
		wfLoadExtension($extension);
	}
	wfLoadExtension('Wikidebates', dirname(__DIR__) . '/extension.json');
}
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';
require __DIR__ . '/bootstrap.php';
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
$sources = [
	'Module:Debate' => 'Module-Debate.reference.lua',
	'Module:Argument' => 'Module-Argument.reference.lua',
	'Module:WD/I18N' => 'WD-I18N.reference.lua',
	'Module:WD/I18N/Data' => 'WD-I18N-Data.snapshot.lua',
	'Template:Débat' => 'Template-Debat.php.wikitext',
	'Template:Argument' => 'Template-Argument.php.wikitext'
];
$resources = dirname(__DIR__) . '/debatephp/resources/';
$fetch = static function($title) use (&$sources, $resources): array {
	$name = $title->getPrefixedText();
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
$fixtures = require __DIR__ . '/renderCases.php';
$fixtures['argument initialization'] = '{{#argument:render|lang=en|initialization=Pro argument@42|creation-date=2000-01-01}}';
foreach ($fixtures as $name => $text) {
	$reference = $parse($text, 'reference');
	$optimized = $parse($text, 'optimized');
	same($reference, $optimized, 'Renderer output and parser effects: ' . $name);
	check(strlen($reference[0]) > 200, 'Substantial renderer output: ' . $name);
	check(!str_contains($reference[0], '{{#debate:') && !str_contains($reference[0], '{{#argument:'), 'Functions registered: ' . $name);
	check(str_contains($reference[0], 'application/ld+json'), 'JSON-LD retained: ' . $name);
	check(in_array('828:WD/I18N/Data', array_column($reference[3], 0), true), 'Translation dependency retained: ' . $name);
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('ShortDescription')
		&& (str_contains($text, '#debate:') || str_contains($text, '{{Débat|'))) {
		check(isset($reference[1]['shortdesc']), 'ShortDescription callback retained: ' . $name);
	}
	if (\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('WikiSEO')) {
		same('seen', $reference[1]['wikidebates-test'] ?? null, 'WikiSEO validation, hook and page properties: ' . $name);
	}
	$count++;
}

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
$sources['Module:Debate'] = 'Module-Argument.reference.lua';
rejects(static fn() => $parse('{{#debate:render}}', 'reference'), 'Changed module source rejected');
unset($sources['Module:Argument']);
rejects(static fn() => $parse('{{#argument:render}}', 'reference'), 'Missing module source rejected');

echo "OK: MediaWiki " . MW_VERSION . ", $count render fixtures in both modes, dependencies, hooks, policy and source guards\n";
