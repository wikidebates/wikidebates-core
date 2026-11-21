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
	private static bool $readingFragment = false;

	/** Temporarily bypass semantic freshness checks only for a reader fragment lookup. */
	public static function forReadingFragment(callable $callback) {
		$previous = self::$readingFragment;
		self::$readingFragment = true;
		try { return $callback(); }
		finally { self::$readingFragment = $previous; }
	}


	public static function onHTMLFileCache__useFileCache($context): bool {
		if (self::$readingFragment) { return true; }
		if (!self::enabled()) {
			return true;
		}
		// MinervaFileCache independently validates the anonymous request before its
		// file lookup. Do not query SMW on a valid mobile HTML-file HIT.
		if (self::isAnonymousMobileFileCandidate($context)) {
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
	 * Full HTML output may also pass through a reverse proxy or browser cache.
	 * Until those caches understand semantic revisions, never advertise
	 * query-owning pages as publicly cacheable in the opt-in tracking mode.
	 */
	public static function onBeforePageDisplay($out, $skin): void {
		if (!self::enabled()) {
			return;
		}
		// ApiParse builds a temporary OutputPage when preparing a private
		// fragment. Denying HTTP caching on that INTERNAL parse would set its
		// expiry to zero and prevent any anonymous fragment publication.
		// Restrict this veto to an actual action=view HTML response.
		if ($out->getContext()->getRequest()->getVal('action', 'view') !== 'view') {
			return;
		}
		// A Minerva cache MISS has passed the other extension vetoes and
		// will store only if the final HTML/headers are public-safe. Its own
		// output handler prevents upstream HTTP caches on success or failure.
		if (self::isAnonymousMobileFileCandidate($out->getContext()) &&
			\\MediaWiki\\Extension\\MinervaFileCache\\Hooks::isCandidateOutput($out)) {
			return;
		}
		try {
			$title = $out->getTitle();
			if ($title && $title->canExist() && !self::isQueryPage($title)) {
				return;
			}
		} catch (\Throwable $e) {
			FragmentService::log($e);
		}
		$out->disableClientCache();
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
		if (self::$readingFragment) { return true; }
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

	/**
	 * This short-circuit is only for Minerva's own guarded file-cache pipeline.
	 * A desktop view and a registered mobile view keep semantic validation.
	 */
	private static function isAnonymousMobileFileCandidate($context): bool {
		return class_exists(\\MediaWiki\\Extension\\MinervaFileCache\\Hooks::class) &&
			class_exists(\\MobileContext::class) &&
			$context->getConfig()->get('MinervaFileCacheEnabled') &&
			!$context->getUser()->isRegistered() &&
			$context->getSkin()->getSkinName() === 'minerva' &&
			\\MobileContext::singleton()->shouldDisplayMobileView();
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
		// A standalone Debate renderer writes semantic properties; unlike
		// Argument, it does not read semantic parent queries. Do not
		// disable full-page caching merely because a page transcludes
		// Debate or calls #debate:render. Existing _ASK profiles above
		// still protect debates with genuine semantic reads.
		// Before the first _ASK profile, recognize Argument and its
		// legacy Lua reader, including invocations nested in a debate.
		return preg_match(
			'/\{\{\s*(?:Argument\s*(?:[|}])|#argument\s*:\s*render\s*(?:[|}])|#invoke\s*:\s*Argument\s*\|\s*render\s*(?:[|}]))/iu',
			$content->getText()
		) === 1;
	}
}
