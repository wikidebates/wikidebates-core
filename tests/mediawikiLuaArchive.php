<?php

/** Exercise the real transition on a disposable database in separate PHP processes. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\WikidebatesLuaArchive\ArchiveContentHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

$phase = $argv[1] ?? '';
$lab = realpath($argv[2] ?? '');
if (!in_array($phase, ['seed', 'verify'], true) || !$lab ||
	dirname($lab) !== realpath(sys_get_temp_dir()) ||
	!preg_match('/^wd-lua-archive-test\.[A-Za-z0-9]+$/D', basename($lab)) ||
	getenv('WD_TEST_MARIADB_SOCKET')) {
	throw new RuntimeException('Use only a private disposable SQLite archive test directory');
}
define('WD_FULLSTACK_TEST', true);
define('WD_TEST_WITHOUT_WIKIDEBATES', true);
putenv('WD_TEST_LANGUAGE=fr');
putenv('WD_TEST_EXTENSIONS=' . ($phase === 'seed' ? 'Scribunto' : ''));
if ($phase === 'verify') {
	define('WD_TEST_LUA_ARCHIVE', true);
	define('WD_TEST_LUA_ARCHIVE_FIXTURE', $lab . '/fixture.sqlite');
}
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

$services = MediaWikiServices::getInstance();
$registry = ExtensionRegistry::getInstance();
$lookup = $services->getRevisionLookup();
$old = "-- <script>alert(1)</script> {{SITENAME}}\nlocal p = {}; function p.hello() return 'WD_LUA_EXECUTED' end; return p";
$new = "local p = {}; function p.hello() return 'WD_LUA_NEW' end; return p";
$hidden = "return { secret = 'WD_PRIVATE_SOURCE' }";
$converted = "Public documentation, no interpreter required.";

if ($phase === 'seed') {
	check($registry->isLoaded('Scribunto') && !$registry->isLoaded('WikidebatesLuaArchive'), 'Original runtime loads alone');
	check(!file_exists($lab . '/fixture.sqlite') && !file_exists($lab . '/fixture.json'), 'Fresh export destination');
	$fsUser = \MediaWiki\User\User::newSystemUser('Lua archive migration fixture', ['steal' => false]);
	check($fsUser !== null, 'Disposable author exists');
	$save = static function(string $name, string $text, string $model) use ($services, $fsUser): int {
		$title = Title::newFromText($name);
		check($title !== null, 'Valid fixture title');
		$page = $services->getWikiPageFactory()->newFromTitle($title);
		$updater = $page->newPageUpdater($fsUser);
		$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title, $model));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Lua archive migration fixture'), EDIT_SUPPRESS_RC);
		check($updater->wasSuccessful(), 'Original runtime saves fixture: ' . $name);
		return $updater->getNewRevision()->getId();
	};
	$ids = [
		'old' => $save('Module:WD archive', $old, CONTENT_MODEL_SCRIBUNTO),
		'hidden' => $save('Module:WD archive', $hidden, CONTENT_MODEL_SCRIBUNTO),
		'current' => $save('Module:WD archive', $new, CONTENT_MODEL_SCRIBUNTO),
		'convertedOld' => $save('Module:WD converted', $old, CONTENT_MODEL_SCRIBUNTO),
		'converted' => $save('Module:WD converted', $converted, CONTENT_MODEL_WIKITEXT)
	];
	fsFlush();
	$services->getConnectionProvider()->getPrimaryDatabase()->newUpdateQueryBuilder()
		->update('revision')->set(['rev_deleted' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED])
		->where(['rev_id' => $ids['hidden']])->caller(__METHOD__)->execute();
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
	$source = new SQLite3($temporary . '/wikidebates_test.sqlite', SQLITE3_OPEN_READONLY);
	$destination = new SQLite3($lab . '/fixture.sqlite');
	check($source->backup($destination), 'Committed SQLite snapshot copied without altering original rows');
	$destination->close();
	$source->close();
	check(file_put_contents($lab . '/fixture.json', json_encode($ids, JSON_THROW_ON_ERROR)) !== false, 'Record original revision IDs');
	echo "OK: seeded real Scribunto revisions, converted page, restricted revision and original IDs\n";
	exit;
}

foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $name) {
	check(!$registry->isLoaded($name), 'No runtime extension: ' . $name);
}
check($registry->isLoaded('WikidebatesLuaArchive'), 'Archive loads independently');
$handler = $services->getContentHandlerFactory()->getContentHandler('Scribunto');
check($handler instanceof ArchiveContentHandler, 'Original stored model has an inert handler');
check($handler->isSupportedFormat(CONTENT_FORMAT_TEXT) && $handler->isSupportedFormat('CONTENT_FORMAT_TEXT'), 'Both historical format spellings remain readable');
same(828, Title::newFromText('Module:WD archive')->getNamespace(), 'Original module namespace');
same(829, Title::newFromText('Discussion module:WD archive')->getNamespace(), 'Original localized talk namespace');
$ids = json_decode(file_get_contents($lab . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$contents = [];
foreach (['old' => $old, 'current' => $new, 'convertedOld' => $old, 'converted' => $converted] as $key => $expected) {
	$rev = $lookup->getRevisionById($ids[$key]);
	check($rev !== null && $rev->getId() === $ids[$key], 'Original revision ID readable: ' . $key);
	$content = $rev->getContent(SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC);
	check($content !== null, 'Public source readable: ' . $key);
	same($expected, $content->serialize(), 'Original source bytes: ' . $key);
	same($key === 'converted' ? CONTENT_MODEL_WIKITEXT : 'Scribunto', $content->getModel(), 'Original slot model: ' . $key);
	$contents[$key] = $content;
	if ($key === 'converted') {
		continue;
	}
	same(false, $content->getWikitextForTransclusion(), 'Lua source cannot become a wiki transclusion');
	$title = Title::newFromText($key === 'convertedOld' ? 'Module:WD converted' : 'Module:WD archive');
	$html = $services->getContentRenderer()->getParserOutput($content, $title, $rev, ParserOptions::newFromAnon())->getRawText();
	check(str_contains($html, '<pre') && str_contains($html, 'mw-code'), 'Revision is rendered as literal source');
	check(!str_contains($html, '<script>'), 'No active script element in source display');
	if ($key !== 'current') {
		check(str_contains($html, '&lt;script&gt;') && str_contains($html, '{{SITENAME}}'), 'HTML escaped and magic word left literal');
	}
}
check(!$contents['old']->diff($contents['current'])->isEmpty(), 'Native text diff compares original revisions');
$restricted = $lookup->getRevisionById($ids['hidden']);
check($restricted !== null, 'Restricted revision metadata still exists');
same(null, $restricted->getContent(SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC), 'Restricted source is not exposed');
$title = Title::newFromText('WD archive caller');
$parser = $services->getParserFactory()->create();
$html = $parser->parse('{{#invoke:WD archive|hello}}{{:Module:WD archive}}', $title, ParserOptions::newFromAnon())->getText();
check(!str_contains($html, 'WD_LUA_EXECUTED') && !str_contains($html, 'WD_LUA_NEW'), 'No legacy invoke or module transclusion executes or includes Lua');
check(!class_exists(\MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine::class, false), 'No Lua engine is loaded');
echo "OK: same database and IDs; source, historical models, diffs and visibility preserved; no Lua execution\n";
