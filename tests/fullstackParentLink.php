<?php

// The compatibility fix must also preserve the existing PageSaveComplete hook.
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

$GLOBALS['wgDebatePHPMode'] = 'reference';
fsSave('Template:Debate', '<includeonly>{{#debate:render|lang=en|topic={{{topic|}}}|pro-arguments={{{pro-arguments|}}}}}</includeonly>');
fsSave('Template:Pro argument', '<includeonly>{{{page|}}}⟬{{{displayed-title|}}}⟬{{{warnings|}}}⟭</includeonly>');
fsSave('WD FS auto parent', '{{Debate|topic=Synthetic parent}}');
fsFlush();
$parent = Title::newFromText('WD FS auto parent');
fsSave('WD FS auto child', '{{Argument|initialization=Pro argument@' . $parent->getArticleID() . '|résumé=Synthetic child}}');
fsFlush();
$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($parent);
$content = $page->getContent(SlotRecord::MAIN)->getText();
check(str_contains($content, 'WD FS auto child'), 'Existing automatic parent hook inserts the new child');
check(str_contains($content, 'pro-arguments'), 'Existing hook updates the expected parent parameter');
$values = fsValues('WD FS auto parent', 'Pro argument');
same([[9, 'WD_FS_auto_child#0##']], $values, 'Automatic parent edit reaches the real semantic store');
$report['automatic_parent_link'] = ['child_inserted' => true, 'semantic_value' => $values];
echo "OK: existing automatic parent link hook edits and persists the synthetic parent\n";

// Exercise the existing direct SQL reader against the genuine SMW 6 schema.
fsSave('WD FS recent child', '{{#set:Parent debate=WD FS auto parent|Breadcrumb=WD FS auto parent⟭WD FS auto child⟭WD FS recent child}}');
fsFlush();
$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
$out = $parser->parse('{{#recentdebatechanges:page=WD FS auto parent|limit=10}}', $parent, \MediaWiki\Parser\ParserOptions::newFromAnon());
check(str_contains($out->getText(), 'WD FS recent child'), 'Recent contributions finds the semantic child in real SQLStore tables');
check(str_contains($out->getText(), 'related argument:') && str_contains($out->getText(), 'WD FS auto child'), 'Recent contributions reads the real semantic breadcrumb');
check(!str_contains($out->getText(), 'Exception'), 'Recent contributions has no SQL/schema exception');
$report['recent_contributions'] = ['semantic_child_found' => true, 'related_argument_found' => true];
echo "OK: recent contributions reads real SMW parent relations and breadcrumbs\n";
