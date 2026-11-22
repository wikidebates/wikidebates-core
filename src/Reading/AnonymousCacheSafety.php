<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Fail-closed PRIMARY-DB visibility for anonymous files and fragments.
 *
 * A revision can be hidden without changing page_latest or page_touched.
 * A title can move while the replica still resolves an old identity.
 */
final class AnonymousCacheSafety {
	private const PAGE_FIELDS = [
		'page_id', 'page_namespace', 'page_title', 'page_latest',
		'page_touched', 'page_is_redirect'
	];

	/**
	 * @return string|null Hash of an existing publicly readable PRIMARY page.
	 */
	public static function fingerprint(int $pageId): ?string {
		if ($pageId < 1) {
			throw new \InvalidArgumentException('Expected positive page ID');
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$page = self::selectCurrentPage($db, ['page_id' => $pageId]);
		return self::checkedFingerprint($page, $services);
	}

	/**
	 * Resolve the name on PRIMARY, not a possibly stale Title/LinkCache ID.
	 * This is deliberately conservative: metadata-only RevDel also vetoes a
	 * previously generated shared HTML file.
	 *
	 * @return array{fingerprint:string,pageId:int,revisionId:int,touched:string}|null
	 */
	public static function forTitle(Title $title): ?array {
		if (!$title->canExist()) {
			return null;
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$page = self::selectCurrentPage($db, [
			'page_namespace' => $title->getNamespace(),
			'page_title' => $title->getDBkey()
		]);
		$fingerprint = self::checkedFingerprint($page, $services);
		if ($fingerprint === null) {
			return null;
		}
		return [
			'fingerprint' => $fingerprint,
			'pageId' => (int)$page->page_id,
			'revisionId' => (int)$page->page_latest,
			'touched' => (string)$page->page_touched
		];
	}

	/**
	 * The page/revision actually served must match the PRIMARY identity even
	 * if the caller has a stale replica Page or Title object.
	 */
	public static function matchesView(Title $title, int $revisionId, string $touched): bool {
		$public = self::forTitle($title);
		return $public !== null && $public['revisionId'] === $revisionId &&
			$public['touched'] === $touched;
	}

	/**
	 * Fetch page identity and latest revision visibility in one PRIMARY query.
	 * One joined snapshot also avoids mixing metadata from two separate reads.
	 */
	private static function selectCurrentPage($db, array $conditions) {
		return $db->selectRow(
			['page', 'revision'],
			array_merge(self::PAGE_FIELDS, ['rev_deleted']),
			$conditions,
			__METHOD__,
			[],
			['revision' => ['JOIN', 'rev_id = page_latest AND rev_page = page_id']]
		);
	}

	private static function checkedFingerprint($page, $services): ?string {
		if (!$page || (int)$page->page_id < 1 || (int)$page->page_latest < 1 ||
			(int)$page->rev_deleted !== 0) {
			return null;
		}
		$id = (int)$page->page_id;
		$title = Title::newFromRow($page);
		if (!$title || !$title->canExist() || $title->getArticleID() !== $id ||
			!$services->getPermissionManager()->userCan(
				'read', $services->getUserFactory()->newAnonymous(), $title
			)) {
			return null;
		}
		return hash('sha256', json_encode([
			$id, (int)$page->page_latest, (int)$page->page_namespace,
			(string)$page->page_title, (string)$page->page_touched,
			(int)$page->page_is_redirect
		], JSON_THROW_ON_ERROR));
	}
}
