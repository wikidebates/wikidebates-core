<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;

class ReadingHooks {
	public static bool $warming = false;
	private static array $pending = [];
	/** Temporary OutputPage-scoped candidates, discarded with the OutputPage itself. */
	private static ?\WeakMap $parserOutputs = null;

	/** Reuse the ParserOutput already supplied to this OutputPage; never fetch it a second time. */
	public static function onOutputPageParserOutput($out, $parserOutput): void {
		self::$parserOutputs ??= new \WeakMap();
		$candidates = self::$parserOutputs[$out] ?? [];
		$candidates[] = $parserOutput;
		self::$parserOutputs[$out] = $candidates;
	}

	/**
	 * Article::view authorizes read before native HTMLFileCache HIT, but does
	 * not consult rev_deleted. RevDel may change that bit before its deferred
	 * purge reaches a shared file. Deny the existing file at read time.
	 */
	public static function onIsFileCacheable($article): ?bool {
		try {
			return AnonymousCacheSafety::matchesView($article->getTitle(),
				(int)$article->getPage()->getLatest(),
				(string)$article->getPage()->getTouched()) ? null : false;
		} catch (\Throwable $e) {
			FragmentService::log($e);
			return false;
		}
	}

	public static function onTitleSquidURLs($title, &$urls): void {
		if (!$title->canExist()) { return; }
		FragmentService::invalidate($title);
	}

	/**
	 * RevDel fires after committing visibility changes. Preserve the normal
	 * TitleSquidURLs purge and also evict reading files directly so a disabled
	 * CDN invalidation cannot leave old fragment generations readable.
	 */
	public static function onArticleRevisionVisibilitySet($title, $ids, $changes): void {
		FragmentService::invalidate($title);
	}

	/** Opportunistic extraction while an ordinary file-cacheable view is rendered. */
	public static function onBeforePageDisplay($out, $skin): void {
		$context = $out->getContext();
		$request = $context->getRequest();
		if (!$context->getConfig()->get('WikidebatesReadingFragments') ||
			!$context->getConfig()->get('UseFileCache') || $request->getMethod() !== 'GET' ||
			$context->getUser()->isRegistered() || $request->getHeader('Cookie') ||
			array_diff(array_keys($request->getValues()), ['title', 'curid', 'action']) ||
			$request->getVal('action', 'view') !== 'view') { return; }
		try {
			$services = MediaWikiServices::getInstance();
			$title = $out->getTitle();
			if (!$title || !$title->canExist()) { return; }
			$page = $services->getWikiPageFactory()->newFromTitle($title);
			$touched = $page->getTouched();
			$revision = (int)$page->getLatest();
			$po = null;
			foreach (self::$parserOutputs[$out] ?? [] as $candidate) {
				if ($candidate->getCacheRevisionId() === $revision && $candidate->isCacheable() &&
					!$candidate->expired($touched)) { $po = $candidate; break; }
			}
			if (!$po) { return; }
			// The full public rendering is eligible; parser TTL must not cap fragment retention.
			$expires = PHP_INT_MAX;
			$html = $out->getHTML();
			$generation = FragmentService::store()->read($out->getTitle()->getPrefixedDBkey(), '', [])['generation'];
			self::$pending[spl_object_id($out)] = [$html, $expires, $revision, $touched, $generation];
		} catch (\Throwable $e) { FragmentService::log($e); }
	}

	public static function onAfterFinalPageOutput($out): void {
		$key = spl_object_id($out);
		$data = self::$pending[$key] ?? null;
		unset(self::$pending[$key]);
		if (self::$parserOutputs !== null && isset(self::$parserOutputs[$out])) {
			unset(self::$parserOutputs[$out]);
		}
		if (!$data || http_response_code() !== 200 || $out->getRedirect() !== '' || !$out->couldBePublicCached()) { return; }
		try { FragmentService::capture($out->getContext(), ...$data); }
		catch (\Throwable $e) { FragmentService::log($e); }
	}
}
