<?php

/** Real MediaWiki and SMW in an isolated database, without any Lua extension. */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_EXTENSIONS=ParserFunctions,Cite,SemanticMediaWiki,Variables,PageForms,ShortDescription,WikiSEO');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\NativeSemanticApi;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use SMW\Services\ServicesFactory;
use Wikimedia\ScopedCallback;

$registry = ExtensionRegistry::getInstance();
check($registry->isLoaded('SemanticMediaWiki') && NativeSemanticApi::available(), 'Real SMW native API available');
foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $name) {
	check(!$registry->isLoaded($name), 'Independent of ' . $name);
}
$services = MediaWikiServices::getInstance();
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false), 'Real SMW SQL store');
$schema = $GLOBALS;
(new \SMW\SetupFile)->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW ready in isolated database');
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates native semantic test', ['steal' => false]);
check($fsUser !== null, 'Synthetic test writer');
foreach (['Text' => 'Text', 'Number' => 'Number', 'Date' => 'Date', 'Page' => 'Page', 'Flag' => 'Boolean',
	'Parent A' => 'Page', 'Parent B' => 'Page'] as $name => $type) {
	fsSave('Property:WD FS ' . $name, '[[Has type::' . $type . ']]');
}
fsFlush();
$installProbe = static function($parser): void {
	$parser->setHook('wd-fs-native', static function($input, $args, $parser, $frame): string {
		$context = new MediaWikiContext($parser, $frame, [], ($args['mode'] ?? '') === 'optimized');
		check($context->smwAvailable(), 'Native semantic context available without a bridge');
		$context->smwSet(fsPayload(trim($input)));
		return '';
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $installProbe);
$installProbe($services->getParser());
$writes = [];
foreach (['reference', 'optimized'] as $mode) {
	fsSave('WD FS probe', 'Reset ' . $mode);
	fsFlush();
	$previous = fsStored('WD FS probe');
	foreach (['initial', 'updated', 'removed'] as $variant) {
		$guard = DeferredUpdates::preventOpportunisticUpdates();
		fsSave('WD FS probe', '<wd-fs-native mode="' . $mode . '">' . $variant . '</wd-fs-native>');
		check(DeferredUpdates::pendingUpdatesCount() > 0, 'Native write schedules deferred persistence');
		same($previous, fsStored('WD FS probe'), 'No early persistence: ' . $mode . '/' . $variant);
		fsFlush();
		ScopedCallback::consume($guard);
		$previous = fsStored('WD FS probe');
		check($variant === 'removed' ? !$previous['properties'] : count($previous['properties']) === 4,
			'Typed properties persisted or removed');
		$writes[$mode][$variant] = $previous;
	}
}
same($writes['reference'], $writes['optimized'], 'Native modes retain identical typed persistence');
same([[2, '0'], [2, 'A|B=C & é'], [2, 'first']], $writes['reference']['initial']['properties']['WD_FS_Text'], 'Literal text and zero preserved');
same([[1, '0']], $writes['reference']['updated']['properties']['WD_FS_Number'], 'Numeric zero preserved');
same([[6, '1/2026/2/2']], $writes['reference']['updated']['properties']['WD_FS_Date'], 'Date type preserved');
same([[9, 'WD_FS_replacement#0##']], $writes['reference']['updated']['properties']['WD_FS_Page'], 'Page type preserved');

fsSave('WD FS query target', '<wd-fs-native>updated</wd-fs-native>{{#set:WD FS Flag=no}}');
fsSave('WD FS typed values', '{{#set:WD FS Number=0|WD FS Number=12.5|WD FS Flag=yes|WD FS Page=WD FS A|WD FS Page=WD FS B}}');
fsFlush();
$parser = $services->getParserFactory()->create();
$parser->startExternalParse(Title::newFromText('WD FS query'), ParserOptions::newFromAnon(), $parser::OT_HTML);
$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], true);
$rows = $context->readSemantic(['[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number',
	'?WD FS Flag=flag', '?WD FS Parent A=missing', 'mainlabel=page', 'link=none', 'limit=10'], 'other')->rows;
same(1, count($rows ?? []), 'Direct SMW read returns persisted native data');
same('updated', $rows[1]['text'], 'Read text');
same(0, $rows[1]['number'], 'Read integer zero');
same(false, $rows[1]['flag'], 'Read boolean false');
check(array_key_exists('missing', $rows[1]) && $rows[1]['missing'] === null, 'Empty printout is null');
$rows = $context->readSemantic(['[[WD FS typed values]]', '?WD FS Number=numbers', '?WD FS Flag=flag',
	'?WD FS Page=pages', 'limit=10', 'link=none'], 'other')->rows;
same(true, $rows[1]['flag'], 'Read boolean true');
check(in_array(0, $rows[1]['numbers'], true) && in_array(12.5, $rows[1]['numbers'], true), 'Read multiple typed numbers');
check(is_array($rows[1]['pages']) && count($rows[1]['pages']) === 2, 'Read multiple formatted page values');
$query = [1 => '([[WD FS Parent A::WD FS absent parent]] OR [[WD FS Parent B::WD FS absent parent]] )',
	2 => '?WD FS Parent A=pro', 3 => '?WD FS Parent B=con', 4 => 'limit=200', 5 => 'link=none'];
$empty = $context->readSemantic($query, 'debate-parents');
same(null, $empty->rows, 'Missing parents remain null');
check($empty->completeEmpty, 'Parsed-query proof works without SemanticScribunto');

// Exercise both actual render entry points with a genuine SMW parser, not only adapter calls.
$cases = require __DIR__ . '/renderCases.php';
foreach (['complete debate en', 'complete argument en', 'complete debate fr', 'complete argument fr'] as $name) {
	$options = ParserOptions::newFromAnon();
	$options->setTimestamp('20261018000000');
	$out = $services->getParserFactory()->create()->parse($cases[$name], Title::newFromText('WD native ' . $name), $options);
	check(str_contains($out->getText(), 'application/ld+json') && !str_contains($out->getText(), 'scribunto-error'),
		'Native renderer produces structured data without a Lua engine: ' . $name);
	$data = $out->getExtensionData(\SMW\ParserData::DATA_ID);
	check($data instanceof \SMW\SemanticData, 'Renderer produces real semantic parser data: ' . $name);
	$snapshot = fsSnapshot($data);
	check($snapshot['properties'] !== [], 'Renderer writes semantic properties: ' . $name);
}
if (getenv('WD_FULLSTACK_NATIVE_RENDER') === '1') {
	$report = [];
	require __DIR__ . '/fullstackRender.php';
	require __DIR__ . '/fullstackParentLink.php';
	check($report['render_cases'] === 144, 'Complete native render corpus');
	check(!MediaWikiServices::getInstance()->getJobQueueGroup()->queuesHaveJobs(), 'All native render jobs drained');
	if (getenv('WD_FULLSTACK_OUTPUT')) {
		file_put_contents(getenv('WD_FULLSTACK_OUTPUT'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
	}
}
require __DIR__ . '/fullstackArgumentMapCases.php';
fsArgumentMapCases(false);
check(!class_exists(\SMW\Scribunto\LibraryFactory::class, false), 'Historical bridge class never loaded');
check(!class_exists(\MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine::class, false), 'Lua engine never loaded');
echo "OK: real SMW deferred writes, typed queries, empty parent proof and Argument/Debate rendering without Lua extensions\n";
