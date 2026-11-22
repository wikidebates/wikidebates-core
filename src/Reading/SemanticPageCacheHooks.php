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
	private static bool $vectorDryRun = false;
	private static ?\WeakMap $vectorCandidates = null;
	private static ?\WeakMap $parserOutputs = null;

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
		// An opt-in desktop file may carry an in-file signed SMW watermark.
		// IsFileCacheable checks that watermark before Article serves a HIT.
		if (self::vectorEnabled() && self::isAnonymousVectorFileCandidate($context)) {
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


	/** Only use this opt-in on a genuine anonymous Vector view. */
	private static function isAnonymousVectorFileCandidate($context): bool {
		$request = $context->getRequest();
		return in_array($context->getSkin()->getSkinName(), ['vector', 'vector-2022'], true) &&
			$request->getMethod() === 'GET' &&
			!$context->getUser()->isRegistered() &&
			!$request->getSession()->isPersistent() &&
			!$request->getHeader('Cookie') &&
			!$request->getHeader('Authorization') &&
			!$request->getHeader('Range') &&
			empty($_SERVER['REMOTE_USER']) && empty($_SERVER['PHP_AUTH_USER']);
	}

	private static function vectorEnabled(): bool {
		return (bool)MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesVectorSemanticFileCache');
	}

	/**
	 * Preserve the maintenance --dry-run contract: the validation may inspect
	 * an old file, but must never evict it during a read-only preview.
	 */
	public static function withoutVectorEviction(callable $callback) {
		$previous = self::$vectorDryRun;
		self::$vectorDryRun = true;
		try { return $callback(); }
		finally { self::$vectorDryRun = $previous; }
	}

	/**
	 * IsFileCacheable runs before Article::tryFileCache opens a native HIT.
	 * With a known, tracked _ASK graph, a stale file is evicted so that
	 * Article rebuilds it. A missing graph, invalid secret or SQL failure
	 * refuses shared file caching rather than trusting unmarked HTML.
	 */
	public static function nativeVectorCacheSafe($article): bool {
		if (!self::enabled() || !self::vectorEnabled()) {
			return true;
		}
		$context = $article->getContext();
		if (!self::isAnonymousVectorFileCandidate($context)) {
			return true; // The HTMLFileCache hook still enforces the other paths.
		}
		try {
			$title = $article->getTitle();
			if (!self::isQueryPage($title, $article->getPage())) {
				return true;
			}
			// Native HTMLFileCache HITs bypass BeforePageDisplay. Set the
			// private HTTP policy now, before loadFromFileCache() emits headers.
			// Otherwise a CDN could outlive subsequent PHP-level SMW checks.
			$context->getOutput()->disableClientCache();
			$subject = WikiPage::newFromTitle($title);
			$store = SemanticServices::getInstance()->getStore();
			if ($store->getPropertyValues($subject, new Property('_ASK')) === []) {
				return false;
			}
			$fingerprint = SemanticRevisionStore::fingerprint($store, $subject);
			$secret = (string)$context->getConfig()->get('SecretKey');
			if ($secret === '') {
				return false;
			}
			$revision = (int)$article->getPage()->getLatest();
			$touched = (string)$article->getPage()->getTouched();
			$pageId = (int)$article->getPage()->getId();
			$cache = new \HTMLFileCache($title, 'view');
			if ($cache->isCached()) {
				$html = $cache->fetchText();
				if (!is_string($html) || !SemanticVectorFileGuard::matches(
					$html, $fingerprint, $pageId, $revision, $touched, $secret
				)) {
					if (self::$vectorDryRun) {
						return false;
					}
					$cache->clearCache();
					// The new instance checks the filesystem, not the old cached isCached() flag.
					if ((new \HTMLFileCache($title, 'view'))->isCached()) {
						return false;
					}
				}
			}
			self::$vectorCandidates ??= new \WeakMap();
			self::$vectorCandidates[$context] = [
				'pageId' => $pageId, 'revision' => $revision, 'touched' => $touched
			];
			return true;
		} catch (\Throwable $e) {
			FragmentService::log($e);
			return false;
		}
	}

	/** Capture the precise ParserOutput associated with the rendered HTML. */
	public static function onOutputPageParserOutput($out, $parserOutput): void {
		if (!self::enabled() || !self::vectorEnabled()) {
			return;
		}
		if (!is_string($parserOutput->getExtensionData(self::OUTPUT_REVISION))) {
			return;
		}
		self::$parserOutputs ??= new \WeakMap();
		$candidates = self::$parserOutputs[$out] ?? [];
		$candidates[] = $parserOutput;
		self::$parserOutputs[$out] = $candidates;
	}

	/**
	 * OutputPage invokes this inside the native HTMLFileCache output buffer.
	 * The marker is appended to the SAME HTML bytes that MediaWiki saves.
	 * A parser mismatch deliberately leaves the file unmarked and unreadable
	 * on future HITs; it must never be stamped with a newer graph.
	 */
	public static function onAfterFinalPageOutput($out): void {
		if (!self::enabled() || !self::vectorEnabled()) {
			return;
		}
		$context = $out->getContext();
		$candidate = self::$vectorCandidates[$context] ?? null;
		if (self::$vectorCandidates !== null && isset(self::$vectorCandidates[$context])) {
			unset(self::$vectorCandidates[$context]);
		}
		$outputs = self::$parserOutputs[$out] ?? [];
		if (self::$parserOutputs !== null && isset(self::$parserOutputs[$out])) {
			unset(self::$parserOutputs[$out]);
		}
		if (!$candidate || $out->getRedirect() !== '' || ob_get_level() < 1 ||
			(PHP_SAPI !== 'cli' && http_response_code() !== 200)) {
			return;
		}
		try {
			$title = $out->getTitle();
			$public = $title ? AnonymousCacheSafety::forTitle($title) : null;
			if (!$public || $public['pageId'] !== $candidate['pageId'] ||
				$public['revisionId'] !== $candidate['revision'] ||
				$public['touched'] !== $candidate['touched']) {
				return;
			}
			$subject = WikiPage::newFromTitle($title);
			$store = SemanticServices::getInstance()->getStore();
			if ($store->getPropertyValues($subject, new Property('_ASK')) === []) {
				return;
			}
			$now = SemanticRevisionStore::fingerprint($store, $subject);
			foreach ($outputs as $output) {
				$observed = $output->getExtensionData(self::OUTPUT_REVISION);
				if ($output->getCacheRevisionId() === $public['revisionId'] &&
					$output->isCacheable() && !$output->expired($public['touched']) &&
					is_string($observed) && hash_equals($observed, $now)) {
					echo SemanticVectorFileGuard::marker($now, $public['pageId'],
						$public['revisionId'], $public['touched'],
						(string)$context->getConfig()->get('SecretKey'));
					return;
				}
			}
		} catch (\Throwable $e) {
			FragmentService::log($e);
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
			\MediaWiki\Extension\MinervaFileCache\Hooks::isCandidateOutput($out)) {
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
		// ApiParse is suppressed from ParserCache during a reading-only parse;
		// there is no full-page ParserOutput to bind to a semantic signature.
		if (self::$readingFragment) { return true; }
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
		return class_exists(\MediaWiki\Extension\MinervaFileCache\Hooks::class) &&
			class_exists(\MobileContext::class) &&
			$context->getConfig()->get('MinervaFileCacheEnabled') &&
			!$context->getUser()->isRegistered() &&
			$context->getSkin()->getSkinName() === 'minerva' &&
			\MobileContext::singleton()->shouldDisplayMobileView();
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
