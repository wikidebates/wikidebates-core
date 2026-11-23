<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Title\Title;

/**
 * One localized category definition for every anonymous reading prewarm path.
 * Use content language, never the current visitor's interface language.
 */
final class ArgumentCategory {
	public static function dbKey($config): string {
		$category = $config->get('WikidebatesReadingArgumentCategory');
		// A nonempty site override is authoritative. Otherwise use a dedicated
		// content-language message: category titles are not interface labels.
		if (!is_string($category) || trim($category) === '') {
			$category = wfMessage('wikidebates-reading-argument-category')
				->inContentLanguage()->plain();
		}
		$title = Title::newFromText(trim($category), NS_CATEGORY);
		if (!$title || $title->getNamespace() !== NS_CATEGORY || $title->getDBkey() === '') {
			throw new \UnexpectedValueException('Invalid localized reading argument category');
		}
		return $title->getDBkey();
	}

	/**
	 * Indexed PRIMARY lookup to avoid an unnecessary PHP/MediaWiki reading
	 * subprocess for every nonargument page in the coordinated sweep.
	 * The caller still checks revision, visibility and public read access.
	 */
	public static function containsPage($db, int $pageId, string $categoryKey): bool {
		if ($pageId < 1) { return false; }
		$row = $db->selectRow(['page', 'categorylinks'], ['page_id'],
			['page_id' => $pageId, 'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0, 'cl_to' => $categoryKey],
			__METHOD__, ['LIMIT' => 1],
			['categorylinks' => ['INNER JOIN', 'cl_from = page_id']]);
		return $row !== false && $row !== null;
	}
}
