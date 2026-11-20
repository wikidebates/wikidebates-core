<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use MediaWiki\Context\RequestContext;
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

	/** Request-local classification only for full-page view hooks. */
	private static ?\WeakMap $requestChecks = null;

	/**
	 * Drop a request's classifications after a parse or a semantic write.
	 * A template may add _ASK on the very first parse, and an old "false"
	 * must never survive that transition. No cache crosses HTTP requests.
	 */
	public static function clearRequestChecks(): void {
		self::$requestChecks = null;
	}

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
		// A parse can register the first _ASK profile for this page.
		self::clearRequestChecks();
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

	/**
	 * Full-page hooks can classify the same page repeatedly during one
	 * HTTP request. Cache only those classifications, not direct calls
	 * with a concrete WikiPage (such as the parser-cache fence).
	 * Refresh immediately after every parse/semantic update.
	 */
	public static function isQueryPage($title, $page = null): bool {
		if ($page !== null || PHP_SAPI === 'cli') {
			return self::computeQueryPage($title, $page);
		}
		$request = RequestContext::getMain()->getRequest();
		if (!is_object($request)) {
			return self::computeQueryPage($title, $page);
		}
		self::$requestChecks ??= new \WeakMap();
		$memo = self::$requestChecks[$request] ?? [];
		$key = $title->getPrefixedDBkey();
		if (array_key_exists($key, $memo)) {
			return $memo[$key];
		}
		$result = self::computeQueryPage($title, $page);
		$memo[$key] = $result;
		self::$requestChecks[$request] = $memo;
		return $result;
	}

	/** SMW profiles first; the Argument source covers first-time parses. */
	private static function computeQueryPage($title, $page = null): bool {
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
