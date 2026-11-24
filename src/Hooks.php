<?php

namespace MediaWiki\Extension\Wikidebates;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\DebatePHP\MessageCatalog;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Parser;
use Skin;

class Hooks {

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$out->addJsConfigVars('wgWikidebatesReadingFragments',
			(bool)MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesReadingFragments'));
		$out->addJsConfigVars( 'wgWikidebatesWikiNames', MessageCatalog::siteNames(
			MediaWikiServices::getInstance()->getContentLanguage()->getCode()
		) );
		Seo\Metadata::beforePageDisplay( $out );
		$skinName = $skin->getSkinName();

		if ( in_array( $skinName, [ 'minerva', 'minervaneue', 'minervaNeue' ], true ) ) {
			$out->addModules( [ 'ext.wikidebates.boot.minerva' ] );
			return;
		}

		if ( $skinName === 'vector-2022' || $skinName === 'vector' ) {
			$out->addModules( [ 'ext.wikidebates.boot.vector' ] );
			return;
		}
	}

	public static function onParserFirstCallInit( Parser $parser ): void {
		RecentDebateChanges::register( $parser );
		\MediaWiki\Extension\DebatePHP\Hooks::onParserFirstCallInit( $parser );
	}

	public static function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		AutoParentLink::onPageSaveComplete(
			$wikiPage,
			$user,
			$summary,
			$flags,
			$revisionRecord,
			$editResult
		);
		// Direct edits alone enter the opt-in preparation journal. Dependent
		// semantic changes and TitleSquidURLs purges never enqueue a page here.
		$directory = MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') { return; }
		try {
			(new Reading\CachePreparationQueue($directory,
				\MediaWiki\WikiMap\WikiMap::getCurrentWikiId()))->enqueue(
				(int)$wikiPage->getId(), (int)$revisionRecord->getId());
		} catch (\Throwable $e) {
			\MediaWiki\Logger\LoggerFactory::getInstance('WikidebatesCachePreparation')->warning(
				'Failed to record edited page for future preparation ({type}).',
				['type' => get_class($e)]);
		}
	}
	
	public static function onAuthChangeFormFields(
		$requests,
		$fieldInfo,
		&$formDescriptor,
		$action
	): void {
		if ( $action !== AuthManager::ACTION_CREATE ) {
			return;
		}

		if ( isset( $formDescriptor['email'] ) ) {
			$formDescriptor['email']['required'] = true;
		}
	}
}
