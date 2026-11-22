<?php

/**
 * Access-control regression on a marked, disposable MediaWiki 1.43 lab.
 * Tests actual PRIMARY revision suppression and real permission hooks.
 */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_file($root . '/state/minerva_file_cache_test.sqlite')) {
	throw new RuntimeException('Only an explicitly marked disposable wiki is supported');
}

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

use MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
$db = $services->getConnectionProvider()->getPrimaryDatabase();
$title = Title::newFromText('Cache study argument');
$page = $services->getWikiPageFactory()->newFromTitle($title);
$pageId = (int)$page->getId();
$revisionId = (int)$page->getLatest();
if ($pageId <= 0 || $revisionId <= 0) {
	throw new RuntimeException('Missing public argument in disposable wiki');
}
$record = $db->selectRow('revision', ['rev_deleted'],
	['rev_id' => $revisionId, 'rev_page' => $pageId], __METHOD__);
if (!$record || (int)$record->rev_deleted !== 0) {
	throw new RuntimeException('Expected a visible initial fixture revision');
}
$checkCount = 0;
$check = static function (bool $okay, string $reason) use (&$checkCount): void {
	if (!$okay) { throw new RuntimeException($reason); }
	$checkCount++;
};
$original = AnonymousCacheSafety::fingerprint($pageId);
$check(is_string($original) && strlen($original) === 64,
	'Actual public page has a stable anonymous PRIMARY fingerprint');
$check(AnonymousCacheSafety::fingerprint($pageId + 100000000) === null,
	'Deleted or absent pages never pass the public cache guard');

try {
	foreach ([RevisionRecord::DELETED_TEXT, RevisionRecord::DELETED_USER,
		RevisionRecord::SUPPRESSED_ALL] as $hidden) {
		$db->newUpdateQueryBuilder()->update('revision')
			->set(['rev_deleted' => $hidden])->where(['rev_id' => $revisionId])
			->caller(__METHOD__)->execute();
		$check((int)$page->getLatest() === $revisionId,
			'RevisionDelete does not increment page_latest');
		$check(AnonymousCacheSafety::fingerprint($pageId) === null,
			'Hidden text, user metadata or suppressed revision cannot be anonymously prewarmed');
	}
} finally {
	$db->newUpdateQueryBuilder()->update('revision')
		->set(['rev_deleted' => (int)$record->rev_deleted])
		->where(['rev_id' => $revisionId])->caller(__METHOD__)->execute();
}
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Unsuppression returns the existing public revision to eligible state');

$hookCalls = 0;
$denyRead = $services->getHookContainer()->scopedRegister('userCan',
	static function ($hookTitle, $user, $action, &$result) use ($title, &$hookCalls): bool {
		if ($action === 'read' && $hookTitle->getPrefixedDBkey() === $title->getPrefixedDBkey()) {
			$hookCalls++;
			$result = 'badaccess-group0';
			return false;
		}
		return true;
	});
$check(AnonymousCacheSafety::fingerprint($pageId) === null && $hookCalls > 0,
	'Read permission restriction without a new page revision blocks all anonymous preparation');
$denyRead = null;
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Restored read permissions re-enable eligible public preparation');

$denyEdit = $services->getHookContainer()->scopedRegister('userCan',
	static function ($hookTitle, $user, $action, &$result) use ($title): bool {
		if ($action === 'edit' && $hookTitle->getPrefixedDBkey() === $title->getPrefixedDBkey()) {
			$result = 'badaccess-group0';
			return false;
		}
		return true;
	});
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Edit-only page protection does not incorrectly classify public reading as private');
$denyEdit = null;

// A change to the same page row that preserves its revision must be detected
// by the fingerprint even if it did not run through PageSaveComplete.
$oldTouch = (string)$db->selectField('page', 'page_touched', ['page_id' => $pageId], __METHOD__);
$parsedTouch = DateTimeImmutable::createFromFormat('!YmdHis', $oldTouch, new DateTimeZone('UTC'));
if (!$parsedTouch) {
	throw new RuntimeException('Invalid disposable page_touched timestamp');
}
$newTouch = gmdate('YmdHis', max(time() + 1, $parsedTouch->getTimestamp() + 1));
try {
	$db->newUpdateQueryBuilder()->update('page')->set(['page_touched' => $newTouch])
		->where(['page_id' => $pageId])->caller(__METHOD__)->execute();
	$check($page->getLatest() === $revisionId && 
		AnonymousCacheSafety::fingerprint($pageId) !== $original,
		'page_touched-only invalidation changes the public identity without a new revision');
} finally {
	$db->newUpdateQueryBuilder()->update('page')->set(['page_touched' => $oldTouch])
		->where(['page_id' => $pageId])->caller(__METHOD__)->execute();
}
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Restoring the original primary identity restores the same cache fingerprint');

echo json_encode(['result' => 'PASS', 'assertions' => $checkCount,
	'pageId' => $pageId, 'revisionId' => $revisionId],
	JSON_THROW_ON_ERROR) . "\n";
