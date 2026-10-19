<?php

/** Actual MediaWiki messages, ResourceLoader, namespace overrides and content-language names. */
define('WD_I18N_TEST', true);
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\MessageCatalog;
use MediaWiki\Extension\Wikidebates\AutoParentLink;
use MediaWiki\Extension\Wikidebates\RequireEmailPreAuthenticationProvider;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$services = MediaWikiServices::getInstance();
$contentLanguage = $services->getContentLanguage()->getCode();
$loader = $services->getResourceLoader();
$module = $loader->getModule('ext.wikidebates.common.i18n');
$languageCases = 0;
foreach (['en' => 'Edit', 'fr' => 'Modifier', 'fr-ca' => 'Modifier', 'fr-fr' => 'Modifier', 'de' => 'Edit', 'xx' => 'Edit'] as $code => $expected) {
	$context = new \MediaWiki\ResourceLoader\Context($loader, new \MediaWiki\Request\FauxRequest(['lang' => $code]));
	$blob = json_decode($module->getModuleContent($context)['messagesBlob'], true, 512, JSON_THROW_ON_ERROR);
	same($expected, $blob['wk-edit'], 'Real ResourceLoader language fallback: ' . $code);
	foreach (MessageCatalog::json('resources/i18n-map.json')['browser_aliases'] as $alias => $key) {
		same($blob[$key], $blob[$alias], 'Legacy browser message alias: ' . $alias . '/' . $code);
	}
	check(!isset($blob['wk-param-display-title']), 'Technical parameters are not selected in the UI language');
	$languageCases++;
}
foreach (['en', 'fr'] as $legacy) {
	check($loader->getModule('ext.wikidebates.i18n.' . $legacy) !== null, 'Historical language module remains registered');
}

RequestContext::getMain()->setLanguage($services->getLanguageFactory()->getLanguage('fr'));
$names = MessageCatalog::siteNames($contentLanguage);
$expectedNames = MessageCatalog::json('resources/wiki-names.json')['site'][MessageCatalog::language($contentLanguage)];
same($expectedNames, $names, 'French UI does not select French wiki identifiers on an English wiki');
$skin = new class(['name' => 'vector']) extends \Skin { public function outputPage() {} };
$out = new \MediaWiki\Output\OutputPage(new RequestContext);
\MediaWiki\Extension\Wikidebates\Hooks::onBeforePageDisplay($out, $skin);
same($names, $out->getJsConfigVars()['wgWikidebatesWikiNames'], 'Browser configuration uses content-language technical names');

$provider = new RequireEmailPreAuthenticationProvider;
$error = $provider->testForAccountCreation(null, null, []);
same('wikidebates-email-required', $error->getMessages('error')[0]->getKey(), 'Missing email produces a translatable message key');
same('Une adresse électronique est obligatoire.', wfMessage($error->getMessages('error')[0])->inLanguage('fr')->plain(), 'Account error is translated');
check($provider->testForAccountCreation(null, null, [(object)['email' => 'test@example.test']])->isGood(), 'An email still satisfies the existing requirement');
$summary = new ReflectionMethod(AutoParentLink::class, 'buildEditSummary');
foreach (['en' => ['Debate', 'Pro argument', '/* Pro arguments */ Added argument: “Title”'],
	'fr' => ['Débat', 'Argument pour', '/* Arguments « pour » */ Ajout de l’argument : « Title »']] as $code => [$template, $type, $expected]) {
	same($expected, $summary->invoke(null, $type, 'Title', ['debateTemplate' => $template]), 'Parent edit summary preserves its content language and text: ' . $code);
}

$user = User::newSystemUser('Wikidebates i18n test', ['steal' => false]);
$saveMessage = static function(string $key, string $language, string $text) use ($services, $user, $contentLanguage): void {
	$name = ucfirst($key) . ($language === $contentLanguage ? '' : '/' . $language);
	$title = Title::makeTitle(NS_MEDIAWIKI, $name);
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	$updater = $page->newPageUpdater($user);
	$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title));
	$updater->saveRevision(CommentStoreComment::newUnsavedComment('Isolated i18n override'), EDIT_SUPPRESS_RC);
	check($updater->wasSuccessful(), 'MediaWiki namespace override saved');
	DeferredUpdates::doUpdates();
	$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
};
$saveMessage('wikidebates-common-labels-edit-label', 'en', 'Custom edit');
foreach ([false, true] as $memoize) {
	same('Custom edit', I18n::bundled($memoize)->message('Common.labels', 'en', 'edit_label'), 'Real MediaWiki namespace override reaches the renderer');
}
$saveMessage('wikidebates-common-labels-arg-pro-label', 'en', 'A visitor-facing label');
same('A visitor-facing label', I18n::bundled()->message('Common.labels', 'en', 'arg_pro_label'), 'Visible shared label is customizable');
same('Pro argument', I18n::bundled()->message('Argument.props', 'en', 'arg_pro'), 'UI override cannot rename a semantic property');
$saveMessage('wikidebates-debate-text-arg-add-tt', 'en', '$2 then $1');
foreach ([false, true] as $memoize) {
	same('DEBATE then SIDE', I18n::bundled($memoize)->message('Debate.text', 'en', 'arg_add_tt', 'SIDE', 'DEBATE'), 'Translated parameters can change order');
}
$saveMessage('wikidebates-debate-text-arg-add-tt', 'en', '100%: $2 then $1');
same('100%: DEBATE then $2', I18n::bundled()->message('Debate.text', 'en', 'arg_add_tt', '$2', 'DEBATE'), 'Literal percent signs and parameter text are not reinterpreted');
$template = I18n::bundled()->message('Debate.text', 'en', 'arg_add_tt');
same('100%: DEBATE then SIDE', sprintf($template, 'SIDE', 'DEBATE'), 'Deferred historical formatting also preserves percentages and parameter order');
$saveMessage('wikidebates-recent-changes-parent-property', $contentLanguage, 'Custom parent property');
same('Custom parent property', MessageCatalog::siteNames($contentLanguage)['wikidebates-recent-changes-parent-property'], 'Historical semantic-property override is retained');

$result = ['mediawiki' => MW_VERSION, 'php' => PHP_VERSION, 'content_language' => $contentLanguage,
	'resource_loader_languages' => $languageCases, 'legacy_aliases' => 5,
	'mediawiki_namespace_overrides' => true, 'semantic_names_isolated' => true,
	'parameter_reordering' => true, 'parent_summaries' => true, 'email_message' => true];
if (getenv('WD_I18N_OUTPUT')) { file_put_contents(getenv('WD_I18N_OUTPUT'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
echo "OK: MediaWiki i18n, $languageCases real ResourceLoader languages, namespace overrides, technical names, parameter order, parent summaries and email requirement\n";
