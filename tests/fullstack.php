<?php

/** Genuine extension/store tests. Always creates a new SQLite wiki, never loads site settings. */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_EXTENSIONS=Scribunto,ParserFunctions,Cite,SemanticMediaWiki,SemanticScribunto,Variables,VariablesLua,PageForms,ShortDescription,WikiSEO');
require __DIR__ . '/mediawikiEnvironment.php';
check(str_starts_with(MW_VERSION, '1.43.'), 'Full-stack tests target MediaWiki 1.43');

use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use SMW\Services\ServicesFactory;

$registry = ExtensionRegistry::getInstance();
foreach (['SemanticMediaWiki', 'SemanticScribunto', 'VariablesLua'] as $name) {
	check($registry->isLoaded($name), 'Real extension loaded: ' . $name);
}
foreach ([SMW\SQLStore\SQLStore::class, SMW\Scribunto\LibraryFactory::class, Liquipedia\Extension\VariablesLua\ScribuntoLuaLibrary::class] as $class) {
	$file = (new ReflectionClass($class))->getFileName();
	check(str_starts_with(realpath($file), realpath(MW_INSTALL_PATH . '/extensions') . '/'), 'Class belongs to an installed extension: ' . $class);
	check(!str_contains($file, 'Double'), 'No extension double: ' . $class);
}
same($testSocket === null ? 'sqlite' : 'mysql', MediaWikiServices::getInstance()->getMainConfig()->get('DBtype'), 'Isolated test database');
same($temporary, MediaWikiServices::getInstance()->getMainConfig()->get('SQLiteDataDir'), 'Database belongs to this test invocation');
same('reference', MediaWikiServices::getInstance()->getMainConfig()->get('DebatePHPMode'), 'Reference remains the default');
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof SMW\SQLStore\SQLStore, 'Real SQLStore');
check($store->setup(false), 'SMW schema initialized');
$schema = $GLOBALS;
(new \SMW\SetupFile)->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'New SMW schema is active for this process');
echo "Full-stack bootstrap OK: MediaWiki " . MW_VERSION . ', SMW ' . SMW_VERSION . ', SemanticScribunto ' . SMW_SCRIBUNTO_VERSION . "\n";
require __DIR__ . '/fullstackSupport.php';
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates isolated test', ['steal' => false]);
check($fsUser !== null, 'Synthetic test user created');
foreach (['Text' => 'Text', 'Number' => 'Number', 'Date' => 'Date', 'Page' => 'Page'] as $name => $type) {
	fsSave('Property:WD FS ' . $name, '[[Has type::' . $type . ']]');
}
fsFlush();
fsSave('WD FS probe', 'Seed');
fsFlush();
$guard = \MediaWiki\Deferred\DeferredUpdates::preventOpportunisticUpdates();
fsSave('WD FS probe', '{{#set:WD FS Text=first|WD FS Number=42|WD FS Date=2026-01-01|WD FS Page=WD FS target}}');
$pending = \MediaWiki\Deferred\DeferredUpdates::pendingUpdatesCount();
$before = fsValues('WD FS probe', 'WD FS Text');
check($pending > 0, 'Saving schedules real deferred updates');
same([], $before, 'Semantic value is not persisted before deferred updates');
$jobs = fsFlush();
\Wikimedia\ScopedCallback::consume($guard);
$after = fsValues('WD FS probe', 'WD FS Text');
check(count($after) === 1 && $after[0][1] === 'first', 'Deferred semantic write persisted');
$report = ['tested_at_utc' => gmdate('c'), 'php' => PHP_VERSION, 'sqlite' => $testSocket === null ? SQLite3::version()['versionString'] : null,
	'database' => MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()->getServerVersion(),
	'mediawiki' => MW_VERSION, 'extensions' => array_map(static fn($info) => $info['version'] ?? null, $registry->getAllThings()),
	'smw' => SMW_VERSION, 'semantic_scribunto' => SMW_SCRIBUNTO_VERSION,
	'baseline_deferred_write' => ['pending' => $pending, 'before' => $before, 'after' => $after], 'writes' => []];

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\DebatePHP\TextEscaper;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
$installProbe = static function($parser): void {
	$parser->setHook('wd-fs-native', static function($input, $args, $parser, $frame): string {
		$context = new MediaWikiContext($parser, $frame, [], ($args['mode'] ?? '') === 'optimized');
		$context->smwSet(Table::wrap(fsPayload(trim($input))));
		return '';
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $installProbe);
$installProbe($services->getParser());
fsSave('Module:WD FS probe', file_get_contents(__DIR__ . '/fixtures/fullstack-probe.lua'), CONTENT_MODEL_SCRIBUNTO);
fsFlush();
$writeSnapshots = [];
foreach (['lua', 'reference', 'optimized'] as $mode) {
	fsSave('WD FS probe', 'Reset ' . $mode);
	fsFlush();
	$previous = fsStored('WD FS probe');
	foreach (['initial', 'updated', 'removed'] as $variant) {
		$guard = \MediaWiki\Deferred\DeferredUpdates::preventOpportunisticUpdates();
		$text = $mode === 'lua' ? '{{#invoke:WD FS probe|write|' . $variant . '}}'
			: '<wd-fs-native mode="' . $mode . '">' . $variant . '</wd-fs-native>';
		fsSave('WD FS probe', $text . '<!--' . $mode . '-->');
		$pending = \MediaWiki\Deferred\DeferredUpdates::pendingUpdatesCount();
		$pendingTypes = array_values(array_map('get_class', \MediaWiki\Deferred\DeferredUpdates::getPendingUpdates()));
		check($pending > 0, 'Deferred queue populated: ' . $mode . '/' . $variant);
		same($previous, fsStored('WD FS probe'), 'No early semantic persistence: ' . $mode . '/' . $variant);
		$jobs = fsFlush();
		\Wikimedia\ScopedCallback::consume($guard);
		$previous = fsStored('WD FS probe');
		check($variant === 'removed' ? !$previous['properties'] : count($previous['properties']) === 4,
			'Typed properties persisted or removed: ' . $mode . '/' . $variant);
		$writeSnapshots[$mode][$variant] = $previous;
		$report['writes'][] = ['mode' => $mode, 'variant' => $variant, 'pending' => $pending, 'pending_types' => $pendingTypes, 'jobs' => $jobs];
	}
}
same($writeSnapshots['lua'], $writeSnapshots['reference'], 'Real Lua and reference typed semantic persistence');
same($writeSnapshots['lua'], $writeSnapshots['optimized'], 'Real Lua and optimized typed semantic persistence');
same([[2, '0'], [2, 'A|B=C & é'], [2, 'first']], $writeSnapshots['lua']['initial']['properties']['WD_FS_Text'], 'Multiple literal text values survive persistence');
same([[1, '0']], $writeSnapshots['lua']['updated']['properties']['WD_FS_Number'], 'Numeric zero remains a typed number');
same([[6, '1/2026/2/2']], $writeSnapshots['lua']['updated']['properties']['WD_FS_Date'], 'Date retains the declared semantic type');
same([[9, 'WD_FS_replacement#0##']], $writeSnapshots['lua']['updated']['properties']['WD_FS_Page'], 'Page value retains its namespace and semantic type');
$report['semantic_persistence'] = $writeSnapshots;

// Read the stored values through both genuine SemanticScribunto and the native bridge.
fsSave('WD FS query target', '<wd-fs-native>updated</wd-fs-native>');
fsFlush();
$parser = $services->getParserFactory()->create();
$json = $parser->preprocess('{{#invoke:WD FS probe|read}}', Title::newFromText('WD FS query'), ParserOptions::newFromAnon());
$luaQuery = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
check(count($luaQuery) === 1 && $luaQuery[0]['text'] === 'updated', 'Lua query reads persisted semantic data');
foreach (['reference', 'optimized'] as $mode) {
	$parser = $services->getParserFactory()->create();
	$parser->startExternalParse(Title::newFromText('WD FS query'), ParserOptions::newFromAnon(), $parser::OT_HTML);
	$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], $mode === 'optimized');
	$result = $context->smwAsk(Table::wrap(['[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number',
		'?WD FS Page=target', 'mainlabel=page', 'link=none', 'limit=10']));
	$native = TextEscaper::jsonValue($result);
	same(fsCanonical($luaQuery), fsCanonical($native), 'Real semantic query matches Lua: ' . $mode);
}
$report['query'] = $luaQuery;
require __DIR__ . '/fullstackDirectApiStudy.php';

// Reparse through SMW's actual queued update job, not only LinksUpdate's callbacks.
$beforeJob = fsStored('WD FS query target');
$services->getJobQueueGroup()->push(new \SMW\MediaWiki\Jobs\UpdateJob(Title::newFromText('WD FS query target'), ['forcedUpdate' => true]));
$jobs = fsFlush();
check(($jobs['smw.update'] ?? 0) === 1, 'Genuine asynchronous SMW update job executes');
same($beforeJob, fsStored('WD FS query target'), 'SMW update job preserves typed native properties');
$report['semantic_update_jobs'] = $jobs;

// One parse must share Variables' genuine parser-local storage across Lua and PHP.
$parser = $services->getParserFactory()->create();
$seen = [];
$parser->setFunctionHook('argument', static function($parser) use (&$seen): string {
	$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], false);
	$seen = [$context->variableGet('WD FS shared'), $context->variableGet('WD FS zero')];
	$context->variableSet('WD FS shared', 'PHP → Lua');
	return '';
});
$text = $parser->preprocess('{{#invoke:WD FS probe|variables}}{{#argument:fs-vars}}{{#invoke:WD FS probe|get|WD FS shared}}',
	Title::newFromText('WD FS variables'), ParserOptions::newFromAnon());
same(['Lua → PHP', '0'], $seen, 'PHP reads variables written by real VariablesLua');
same('Lua → PHP/0PHP → Lua', $text, 'Lua reads the variable updated by PHP in the same parse');
$fresh = $services->getParserFactory()->create()->preprocess('{{#invoke:WD FS probe|get|WD FS shared}}',
	Title::newFromText('WD FS other page'), ParserOptions::newFromAnon());
same('MISSING', $fresh, 'Variables do not leak between page parses');
$report['variables'] = ['lua_to_php' => $seen, 'php_to_lua' => $text, 'fresh_parser' => $fresh];
echo "OK: real Lua/native deferred persistence, typed properties, updates/removal, queries and VariablesLua sharing\n";
require __DIR__ . '/fullstackRender.php';
require __DIR__ . '/fullstackParentLink.php';
$report['queues_empty'] = !$services->getJobQueueGroup()->queuesHaveJobs();
check($report['queues_empty'], 'Queues remain empty after the automatic parent hook');
require __DIR__ . '/fullstackDatabaseErrors.php';
if (getenv('WD_FULLSTACK_OUTPUT')) {
	file_put_contents(getenv('WD_FULLSTACK_OUTPUT'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
