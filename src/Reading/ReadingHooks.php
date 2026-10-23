<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;

class ReadingHooks {
	public static bool $warming = false;

	public static function onTitleSquidURLs($title, &$urls): void {
		if (!$title->canExist()) { return; }
		FragmentService::invalidate($title);
		// Known readers are warmed again after any dependency invalidation.
		try { if (FragmentService::store()->known($title->getPrefixedDBkey())) { self::queue($title); } }
		catch (\Throwable $e) { FragmentService::log($e); }
	}

	public static function onLinksUpdateComplete($update, $ticket): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$category = str_replace(' ', '_', $config->get('WikidebatesReadingArgumentCategory'));
		if (in_array($category, $update->getParserOutput()->getCategoryNames(), true)) {
			self::queue($update->getTitle());
		}
	}

	public static function queue($title): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if (self::$warming || !$config->get('WikidebatesReadingFragments') ||
			!$config->get('WikidebatesReadingPrewarm') || $title->getNamespace() !== NS_MAIN) { return; }
		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(new WarmFragmentsJob($title));
	}
}
