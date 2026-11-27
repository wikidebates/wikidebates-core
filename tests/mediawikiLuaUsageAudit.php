<?php

/** Disposable wiki with actual Scribunto modules, revision storage and template links. */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_EXTENSIONS=Scribunto');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

use MediaWiki\Extension\Wikidebates\LuaUsageAudit;
use MediaWiki\MediaWikiServices;

$services = MediaWikiServices::getInstance();
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates Lua inventory lab', ['steal' => false]);
check($fsUser !== null, 'Synthetic writer');
fsSave('Module:WD audit', "local p = {}; function p.hello() return 'old' end; return p", CONTENT_MODEL_SCRIBUNTO);
fsSave('Module:WD audit', "local p = {}; function p.hello() return 'new' end; return p", CONTENT_MODEL_SCRIBUNTO);
fsSave('WD Lua caller', '{{#invoke:WD audit|hello}}');
fsSave('Template:WD localized caller', '<nowiki>{{#invoque:WD audit|hello}}</nowiki>');
fsSave('MediaWiki:Wd-audit-message', '<nowiki>{{#aufrufen:WD audit|hello}}</nowiki>');
fsSave('WD ordinary page', 'Ordinary wikitext');
fsSave('WD native marker', '<nowiki>{{#debate:render}}</nowiki>');
fsFlush();

$db = $services->getConnectionProvider()->getReplicaDatabase();
$events = [];
$emit = static function(array $event) use (&$events): void { $events[] = $event; };
$summary = LuaUsageAudit::scan($db, $services->getRevisionLookup(), 0, PHP_INT_MAX, 2, $emit);
check($summary['full_current_scan'] && !$summary['errors'] && !$summary['unreadable'], 'Full real scan succeeds across batches');
same(1, $summary['module_pages'], 'One current module');
same(1, $summary['lua_model_pages'], 'Actual Lua content handler');
same(3, $summary['signals']['invoke'], 'English, French and German calls in separate namespaces');
same(1, $summary['signals']['native_debate'], 'Native marker counted');
$matches = array_column(array_values(array_filter($events, static fn($event) => $event['type'] === 'candidate')), null, 'title');
foreach (['Module:WD audit', 'WD Lua caller', 'Template:WD localized caller', 'MediaWiki:Wd-audit-message'] as $title) {
	check(isset($matches[$title]), 'Expected candidate ' . $title);
	same('main', $matches[$title]['slot'], 'Slot identified');
	check($matches[$title]['revision_id'] > 0, 'Exact current revision identified');
}
check(!isset($matches['WD native marker'], $matches['WD ordinary page']), 'Only review candidates emitted');
$evidence = LuaUsageAudit::storedEvidence($db, $emit);
check(($evidence['stored_lua_content_rows_by_model']['Scribunto'] ?? 0) >= 2, 'Historical content model rows are not mistaken for current modules');
check($evidence['stored_module_links'] >= 1 && $evidence['stored_module_link_sources'] >= 1, 'Actual module transclusion recorded');
$partial = LuaUsageAudit::scan($db, $services->getRevisionLookup(), 0, 1, 1, $emit);
same(1, $partial['pages'], 'Page limit enforced');
check(!$partial['full_current_scan'] && !$partial['range_exhausted'], 'Partial scan never described as complete');
$resumed = LuaUsageAudit::scan($db, $services->getRevisionLookup(), $partial['resume_after'], PHP_INT_MAX, 2, $emit);
same($summary['pages'], $partial['pages'] + $resumed['pages'], 'Cursor covers remaining pages exactly once');
check($resumed['range_exhausted'] && !$resumed['full_current_scan'], 'Resumed segment is not a full independent inventory');
echo "OK: real current-slot inventory, system messages, historical Lua rows and stored module transclusions\n";
