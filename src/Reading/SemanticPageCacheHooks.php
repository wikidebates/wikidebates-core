<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;

/**
 * Protect ordinary HTML page views, which may bypass the reading API.
 *
 * File-cache hits happen before ArticleViewHeader, so a semantic revision
 * check in FragmentService alone cannot protect the desktop/mobile article
 * view. Temporarily veto full-page file caches for query-owning pages and
 * use MediaWiki's parser cache with an explicit semantic revision fence.
 */
final class SemanticPageCacheHooks {
	public const OUTPUT_REVISION = 'wikidebates-semantic-revision';

	public static function onHTMLFileCache__useFileCache($context): bool {
		if (!self::enabled()) {
			return true;
		}
		$title = $context->getTitle();
		if (!$title || !$title->canExist()) {
			return false;
		}
		try {
			return !self::isQueryPage($title);
		} catch (\Throwable $e) {
			FragmentService::log($e);
			// A missing SMW store must not expose a stale full HTML file.
			return false;
		}
	}

	/**
	 * Bind ParserOutput to the semantic graph observed during the parse.
	 * Only the canonical traced PHP queries need this additional marker.
	 */
	public static function onParserAfterParse($parser, &$text, $stripState): bool {
		if (!self::enabled() ||
			$parser->getOutput()->getExtensionData(\MediaWiki\Extension\DebatePHP\TrackedSemanticQuery::OUTPUT_MARKER) !== true) {
			return true;
		}

		$output = $parser->getOutput();
		$title = $parser->getTitle();
		try {
			$subject = WikiPage::newFromTitle($title);
			$store = SemanticServices::getInstance()->getStore();
			// Before the first successful semantic update, the source may
			// already contain an _ASK profile, but the store does not.
			// Never cache HTML that cannot yet be linked to its dependencies.
			if ($store->getPropertyValues($subject, new Property('_ASK')) === []) {
				$output->updateCacheExpiry(0);
				return true;
			}
			$output->setExtensionData(self::OUTPUT_REVISION, SemanticRevisionStore::fingerprint($store, $subject));
		} catch (\Throwable $e) {
			FragmentService::log($e);
			$output->updateCacheExpiry(0);
		}
		return true;
	}

	/**
	 * ParserCache invokes this for cached ParserOutput. Reject old entries
	 * which are missing the revision marker or whose dependencies changed,
	 * even if SMW's smw_touched timestamps are equal in the same second.
	 */
	public static function onRejectParserCacheValue($output, $page, $options): bool {
		if (!self::enabled()) {
			return true;
		}
		try {
			$title = $page->getTitle();
			if (!self::isQueryPage($title, $page)) {
				return true;
			}

			$embedded = $output->getExtensionData(self::OUTPUT_REVISION);
			if (!is_string($embedded) || $embedded === '') {
				return false;
			}
			$subject = WikiPage::newFromTitle($title);
			return hash_equals(
				$embedded,
				SemanticRevisionStore::fingerprint(SemanticServices::getInstance()->getStore(), $subject)
			);
		} catch (\Throwable $e) {
			FragmentService::log($e);
			return false;
		}
	}

	private static function enabled(): bool {
		return (bool)MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesSemanticCacheTracking');
	}

	/** Semantic data first; template identification covers pre-migration pages. */
	public static function isQueryPage($title, $page = null): bool {
		$subject = WikiPage::newFromTitle($title);
		$store = SemanticServices::getInstance()->getStore();
		if ($store->getPropertyValues($subject, new Property('_ASK')) !== []) {
			return true;
		}
		if ($title->getNamespace() !== NS_MAIN) {
			return false;
		}
		$page ??= MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($title);
		$content = $page->getContent();
		if (!$content instanceof \TextContent) {
			return false;
		}
		// Templates that invoke the DebatePHP semantic readers. This also
		// works if __NOCACHE__ precedes the transclusion in the source.
		return preg_match('/\{\{\s*(?:Argument|D[eé]bat|Debate)\s*(?:[|}])/iu',
			$content->getText()) === 1;
	}
}
