<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Fail-closed PRIMARY-DB identity for anonymous cache preparation.
 *
 * page_latest alone does not change on RevisionDelete, title move or read
 * permission changes. No public cache writer may treat an unchanged revision
 * as proof that an already-rendered page is still safe to publish.
 */
final class AnonymousCacheSafety {
	/**
	 * Return a stable identity only while the current revision is fully visible
	 * to an anonymous reader. Any missing row, revision suppression or read
	 * restriction denies preparation. In particular, edit protection alone is
	 * not a read restriction.
	 *
	 * @return string|null Hash of the current publicly readable page identity.
	 */
	public static function fingerprint(int $pageId): ?string {
		if ($pageId < 1) {
			throw new \InvalidArgumentException('Expected positive page ID');
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$page = $db->selectRow('page',
			['page_id', 'page_namespace', 'page_title', 'page_latest',
				'page_touched', 'page_is_redirect'],
			['page_id' => $pageId], __METHOD__);
		if (!$page || (int)$page->page_latest < 1) {
			return null;
		}
		$revision = $db->selectRow('revision', ['rev_id', 'rev_deleted'],
			['rev_id' => (int)$page->page_latest, 'rev_page' => $pageId],
			__METHOD__);
		// Conservative for a shared anonymous HTML file: any RevisionDelete
		// bit, even metadata-only suppression, forbids prewarming. A later
		// explicit unsuppression can make it eligible again.
		if (!$revision || (int)$revision->rev_deleted !== 0) {
			return null;
		}
		$title = Title::newFromRow($page);
		if (!$title || !$title->canExist() || $title->getArticleID() !== $pageId) {
			return null;
		}
		$anonymous = $services->getUserFactory()->newAnonymous();
		if (!$services->getPermissionManager()->userCan('read', $anonymous, $title)) {
			return null;
		}
		// Permissions are checked on *every* invocation. This fingerprint
		// protects against moves, touches and direct edits between subprocesses.
		// It is not a replacement for the serving path's per-request ACL.
		return hash('sha256', json_encode([
			$pageId, (int)$page->page_latest, (int)$page->page_namespace,
			(string)$page->page_title, (string)$page->page_touched,
			(int)$page->page_is_redirect
		], JSON_THROW_ON_ERROR));
	}
}
