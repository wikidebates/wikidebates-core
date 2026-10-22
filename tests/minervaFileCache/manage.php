<?php

require __DIR__ . '/bootstrap.php';
$root = getenv('WD_MINERVA_STUDY_ROOT');
$install = $root . '/mediawiki';
$operation = $argv[1] ?? 'initialize';
if ($operation === 'initialize') {
	if (is_file($root . '/state/minerva_file_cache_test.sqlite')) {
		throw new RuntimeException('Refusing to initialize an existing study database');
	}
	$db = new PDO('sqlite:' . $root . '/state/minerva_file_cache_test.sqlite');
	$db->exec(file_get_contents($install . '/maintenance/sqlite/tables-generated.sql'));
	$db = null;
}
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $install);
require $install . '/includes/BootstrapHelperFunctions.php';
require $install . '/includes/Setup.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Deferred\DeferredUpdates;

$services = MediaWikiServices::getInstance();
$user = $services->getUserFactory()->newFromName('CacheStudyReader');
if (!$user->isRegistered()) {
	$user->addToDatabase();
	$auth = new MediaWiki\Auth\PasswordAuthenticationRequest();
	$auth->username = $user->getName();
	$auth->password = $auth->retype = 'Disposable-cache-study-2026!';
	$auth->action = MediaWiki\Auth\AuthManager::ACTION_CHANGE;
	$services->getAuthManager()->changeAuthenticationData($auth);
}
$save = static function ($title, $text) use ($services, $user): void {
	$title = Title::newFromText($title);
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	$updater = $page->newPageUpdater($user);
	$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title));
	$updater->saveRevision(CommentStoreComment::newUnsavedComment('Disposable cache study'));
	if (!$updater->wasSuccessful()) { throw new RuntimeException('Study page update failed'); }
};
if ($operation === 'initialize') {
	$save('Template:Study dependency', 'DEPENDENCY_VERSION_ONE');
	$save('Cache study argument', "== Summary ==\nARGUMENT_VERSION_ONE\n{{Study dependency}}\n" .
		str_repeat("A synthetic argument with a [[Main Page|link]] and '''formatted text'''.\n\n", 200) .
		"== Parent debates ==\nParent content");
	$save('Study redirect', '#REDIRECT [[Cache study argument]]');
} elseif ($operation === 'edit') {
	$save('Cache study argument', "== Summary ==\nARGUMENT_VERSION_TWO\n{{Study dependency}}\n== Parent debates ==\nParent content");
} elseif ($operation === 'template') {
	$save('Template:Study dependency', 'DEPENDENCY_VERSION_TWO');
} elseif ($operation === 'purge') {
	$services->getWikiPageFactory()->newFromTitle(Title::newFromText('Cache study argument'))->doPurge();
} else {
	throw new RuntimeException('Unknown laboratory operation');
}
DeferredUpdates::doUpdates();
// Drain dependency refresh jobs in this disposable wiki.
$services->getJobRunner()->run(['type' => false, 'maxJobs' => 100]);
DeferredUpdates::doUpdates();
echo json_encode(['operation' => $operation, 'mediawiki' => MW_VERSION]) . "\n";
