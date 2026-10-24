<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;

class ReadingHooks {
	public static bool $warming = false;
	private static array $pending = [];

	public static function onTitleSquidURLs($title, &$urls): void {
		if (!$title->canExist()) { return; }
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
			$page = $services->getWikiPageFactory()->newFromTitle($out->getTitle());
			$po = $services->getParserCache()->get($page, \MediaWiki\Parser\ParserOptions::newFromAnon());
			if (!$po || !$po->isCacheable()) { return; }
			$expires = (int)wfTimestamp(TS_UNIX, $po->getCacheTime()) + $po->getCacheExpiry();
			$html = $out->getHTML();
			$revision = (int)$po->getCacheRevisionId();
			$touched = $page->getTouched();
			$generation = FragmentService::store()->read($out->getTitle()->getPrefixedDBkey(), '', [])['generation'];
			self::$pending[spl_object_id($out)] = [$html, $expires, $revision, $touched, $generation];
		} catch (\Throwable $e) { FragmentService::log($e); }
	}

	public static function onAfterFinalPageOutput($out): void {
		$key = spl_object_id($out);
		$data = self::$pending[$key] ?? null;
		unset(self::$pending[$key]);
		if (!$data || http_response_code() !== 200 || $out->getRedirect() !== '' || !$out->couldBePublicCached()) { return; }
		try { FragmentService::capture($out->getContext(), ...$data); }
		catch (\Throwable $e) { FragmentService::log($e); }
	}
}
