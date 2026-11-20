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
		// Temporary, isolated CI lab instrumentation only. This code is
		// removed after the query cost is characterized.
		$measure = defined('WD_SEMANTIC_HTTP_PROFILE') &&
			WD_SEMANTIC_HTTP_PROFILE &&
			($_SERVER['HTTP_X_WD_SEMANTIC_PROFILE'] ?? '') === '1';
		$started = $measure ? hrtime(true) : 0;

		$subject = WikiPage::newFromTitle($title);
		$store = SemanticServices::getInstance()->getStore();
		$askStart = $measure ? hrtime(true) : 0;
		$hasAsk = $store->getPropertyValues($subject, new Property('_ASK')) !== [];
		$askDuration = $measure ? (hrtime(true) - $askStart) / 1e6 : 0;
		$sourceDuration = 0;
		if ($hasAsk) {
			$result = true;
		} elseif ($title->getNamespace() !== NS_MAIN) {
			$result = false;
		} else {
			$sourceStart = $measure ? hrtime(true) : 0;
			$page ??= MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($title);
			$content = $page->getContent();
			// Before the first _ASK, recognize Argument and its legacy Lua
			// reader. Standalone Debate writes properties, not queries.
			$result = $content instanceof \TextContent && preg_match(
				'/\{\{\s*(?:Argument\s*(?:[|}])|#argument\s*:\s*render\s*(?:[|}])|#invoke\s*:\s*Argument\s*\|\s*render\s*(?:[|}]))/iu',
				$content->getText()
			) === 1;
			$sourceDuration = $measure ? (hrtime(true) - $sourceStart) / 1e6 : 0;
		}
		if ($measure) {
			$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'direct';
			$root = (string)getenv('WD_SEMANTIC_HTTP_ROOT');
			if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') === '1' &&
				str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/wikidebates-')) {
				file_put_contents($root . '/semantic-http-isquery-profile.jsonl', json_encode([
					'pid' => getmypid(),
					'probe' => (string)($_SERVER['HTTP_X_WD_SEMANTIC_PROBE_ID'] ?? ''),
					'hook' => $caller,
					'namespace' => $title->getNamespace(),
					'ask' => $hasAsk,
					'query_page' => $result,
					'ask_ms' => round($askDuration, 4),
					'source_ms' => round($sourceDuration, 4),
					'total_ms' => round((hrtime(true) - $started) / 1e6, 4)
				], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
			}
		}
		return $result;
	}
}
