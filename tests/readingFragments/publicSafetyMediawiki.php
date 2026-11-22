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
use MediaWiki\Extension\Wikidebates\Reading\ReadingHooks;
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
$articleContext = new \MediaWiki\Context\RequestContext();
$articleContext->setTitle($title);
$article = \Article::newFromTitle($title, $articleContext);
$originalTouch = (string)$db->selectField('page', 'page_touched',
	['page_id' => $pageId], __METHOD__);
$check(ReadingHooks::onIsFileCacheable($article) === null &&
	AnonymousCacheSafety::matchesView($title, $revisionId, $originalTouch),
	'Native Vector file-cache hook allows the currently public PRIMARY revision');
$check(!AnonymousCacheSafety::matchesView($title, $revisionId + 1, $originalTouch),
	'Native file cache rejects a stale revision even at the same title');

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
		$check(ReadingHooks::onIsFileCacheable($article) === false &&
			AnonymousCacheSafety::forTitle($title) === null,
			'Even an already-published native Vector file is vetoed during RevisionDelete before purge');
	}
} finally {
	$db->newUpdateQueryBuilder()->update('revision')
		->set(['rev_deleted' => (int)$record->rev_deleted])
		->where(['rev_id' => $revisionId])->caller(__METHOD__)->execute();
}
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Unsuppression returns the existing public revision to eligible state');
$check(ReadingHooks::onIsFileCacheable($article) === null,
	'Native HTML-file reuse is allowed again only after explicit unsuppression');

$hookCalls = 0;
$denyRead = $services->getHookContainer()->scopedRegister('getUserPermissionsErrors',
	static function ($hookTitle, $user, $action, &$result) use ($title, &$hookCalls): bool {
		if ($action === 'read' && !$user->isRegistered()) {
			$hookCalls++;
			$result = 'badaccess-group0';
			return false;
		}
		return true;
	});
$check(AnonymousCacheSafety::fingerprint($pageId) === null && $hookCalls > 0,
	'Read permission restriction without a new page revision blocks all anonymous preparation');
$check(ReadingHooks::onIsFileCacheable($article) === false,
	'Read permission revocation vetoes a previously cached native HTML file');
$denyRead = null;
$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
	'Restored read permissions re-enable eligible public preparation');
$check(ReadingHooks::onIsFileCacheable($article) === null,
	'Previously cached HTML is eligible only after public rights return');

// A genuinely edit-protected page may still be public. Protect only 'edit'
// in this disposable database and ensure the anonymous read cache remains
// eligible. Nothing here edits a production page or touches a live wiki.
$existingProtection = $db->selectField('page_restrictions', 'pr_id',
	['pr_page' => $pageId, 'pr_type' => 'edit'], __METHOD__);
$check($existingProtection === false, 'Disposable argument initially has no edit protection');
$db->newInsertQueryBuilder()->insertInto('page_restrictions')->row([
	'pr_page' => $pageId, 'pr_type' => 'edit', 'pr_level' => 'sysop',
	'pr_cascade' => 0, 'pr_expiry' => $db->encodeExpiry('infinity')
])->caller(__METHOD__)->execute();
try {
	$check(AnonymousCacheSafety::fingerprint($pageId) === $original,
		'Actual edit-only protection must not block a publicly readable page');
} finally {
	$db->newDeleteQueryBuilder()->deleteFrom('page_restrictions')
		->where(['pr_page' => $pageId, 'pr_type' => 'edit'])
		->caller(__METHOD__)->execute();
}

$denyEdit = $services->getHookContainer()->scopedRegister('getUserPermissionsErrors',
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

// CoordinatorFullstack created a real reading fragment before this test.
// A genuine RevisionDelete postcommit event must rotate that generation and
// remove the already-published HTML, independently of CDN URL configuration.
$readingFiles = $root . '/state/reading-cache/*/*/*/*.cache';
$beforeFiles = glob($readingFiles) ?: [];
$check(count($beforeFiles) > 0, 'Existing reading fragment was published in disposable lab');
$fragmentStore = \MediaWiki\Extension\Wikidebates\Reading\FragmentService::store();
$oldGeneration = $fragmentStore->read($title->getPrefixedDBkey(), '', [])['generation'];
ReadingHooks::onArticleRevisionVisibilitySet($title, [$revisionId],
	[$revisionId => ['oldBits' => 0, 'newBits' => 1]]);
$newGeneration = $fragmentStore->read($title->getPrefixedDBkey(), '', [])['generation'];
$afterFiles = glob($readingFiles) ?: [];
$check($oldGeneration !== $newGeneration && count($afterFiles) < count($beforeFiles),
	'Postcommit RevisionDelete hook removes existing reading HTML and prevents stale writers');

echo json_encode(['result' => 'PASS', 'assertions' => $checkCount,
	'pageId' => $pageId, 'revisionId' => $revisionId],
	JSON_THROW_ON_ERROR) . "\n";
