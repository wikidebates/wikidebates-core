<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Api\ApiMain;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

class FragmentService {
	public static function store(): FragmentStore {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$directory = $config->get('WikidebatesReadingCacheDirectory');
		if (!$directory) {
			$directory = sys_get_temp_dir() . '/wikidebates-reading-' . hash('sha256', MW_INSTALL_PATH . WikiMap::getCurrentWikiId());
			if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
				throw new \RuntimeException('Cannot initialize reading cache');
			}
			if (is_link($directory) || (fileperms($directory) & 0077) !== 0) {
				throw new \RuntimeException('Temporary reading cache must be private');
			}
		}
		return new FragmentStore($directory, WikiMap::getCurrentWikiId(),
			(int)$config->get('WikidebatesReadingCacheTTL'), 4194304);
	}

	public static function read(IContextSource $context, string $pageName, string $skin, ?array $prepared = null): array {
		$services = MediaWikiServices::getInstance();
		$title = Title::newFromText($pageName);
		if (!$title || !$title->canExist()) { throw new \InvalidArgumentException('Invalid page title'); }
		// Resolve every link in a redirect chain, checking authority before reading it.
		$seen = [];
		for ($i = 0; $i < 10; $i++) {
			if (!$context->getAuthority()->authorizeRead('read', $title)) {
				throw new \PermissionsError('read');
			}
			$page = $services->getWikiPageFactory()->newFromTitle($title);
			$revision = $page->getRevisionRecord();
			if (!$revision || !$revision->userCan(RevisionRecord::DELETED_TEXT, $context->getAuthority())) {
				// Let ApiParse produce the same missing/suppressed revision error as before.
				return self::render($context, $pageName, $skin);
			}
			if (!$title->isRedirect()) { break; }
			if (isset($seen[$title->getPrefixedDBkey()])) { return self::render($context, $pageName, $skin); }
			$seen[$title->getPrefixedDBkey()] = true;
			$target = $page->getRedirectTarget();
			if (!$target || $target->getInterwiki() !== '') { return self::render($context, $pageName, $skin); }
			$title = $target;
		}
		if ($title->isRedirect()) { return self::render($context, $pageName, $skin); }
		$config = $context->getConfig();
		$request = $context->getRequest();
		// Same-origin mw.Api AJAX requests may include X-Requested-With; that header
		// does not indicate personalization. Keep the cookie/session/rights guards.
		$eligible = $config->get('WikidebatesReadingFragments') && $request->getMethod() === 'GET' && !$context->getUser()->isRegistered() &&
			!$request->getSession()->isPersistent() && !$request->getHeader('Cookie') &&
			!$request->getHeader('Authorization') && !$request->getHeader('Range') && empty($_SERVER['REMOTE_USER']) &&
			empty($_SERVER['PHP_AUTH_USER']) &&
			!$services->getTalkPageNotificationManager()->userHasNewMessages($context->getUser());
		$eligible = $eligible && !array_diff(array_keys($request->getValues()), ['action', 'page', 'useskin',
			'uselang', 'format', 'formatversion', 'maxlag', 'requestid', 'errorformat', 'errorlang', 'errorsuselocal', 'origin']) &&
			!preg_match('/no-cache|no-store/i', (string)$request->getHeader('Cache-Control'));
		$semanticTracking = (bool)$config->get('WikidebatesSemanticCacheTracking');
		$semanticRevision = null;
		$forceFreshSemanticParse = $semanticTracking && !$eligible;
		if ($semanticTracking) {
			// Check the semantic graph even on a cookie-bearing fallback.
			// When the guard fails, no parser HTML cache may be consulted.
			$safe = SemanticCacheGuard::safeForRead($title, $page);
			if ($safe) {
				try {
					$semanticRevision = SemanticRevisionStore::fingerprint(
						\SMW\Services\ServicesFactory::getInstance()->getStore(),
						\SMW\DataItems\WikiPage::newFromTitle($title)
					);
				} catch (\Throwable $e) {
					self::log($e);
					$safe = false;
				}
			}
			$eligible = $eligible && $safe;
			$forceFreshSemanticParse = $forceFreshSemanticParse || !$safe;
		}
		// Temporary isolated HTTP-lab diagnostics. Not enabled in production.
		if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') === '1') {
			$root = (string)getenv('WD_SEMANTIC_HTTP_ROOT');
			@file_put_contents($root . '/semantic-http-eligibility.jsonl',
				json_encode([
					'pid' => getmypid(),
					'registered' => $context->getUser()->isRegistered(),
					'persistentSession' => $request->getSession()->isPersistent(),
					'cookie' => $request->getHeader('Cookie') !== '',
					'method' => $request->getMethod(),
					'params' => array_keys($request->getValues()),
					'tracking' => $semanticTracking,
					'safe' => $safe ?? null,
					'eligible' => $eligible,
					'semanticRevision' => $semanticRevision
				]) . "\n", FILE_APPEND | LOCK_EX);
		}
		$identity = ['pageid' => $page->getId(), 'revision' => $page->getLatest(), 'touched' => $page->getTouched(),
			'epoch' => $config->get('WikidebatesReadingCacheEpoch'), 'coreEpoch' => $config->get('CacheEpoch'), 'format' => 4,
			'semanticTracking' => $semanticTracking, 'semanticRevision' => $semanticRevision,
			'sourceEpoch' => class_exists(\MediaWiki\Extension\MinervaFileCache\Hooks::class) ? $config->get('MinervaFileCacheEpoch') : null];
		$server = parse_url($config->get('Server'));
		$host = $request->getHeader('Host') ?: (($server['host'] ?? '') . (isset($server['port']) ? ':' . $server['port'] : ''));
		$variant = json_encode([$context->getLanguage()->getCode(),
			$config->get('Server'), $request->getProtocol(), strtolower($host), $request->getHeader('X-Subdomain') ?: '']);
		$store = null; $snapshot = null; $fill = false;
		if ($eligible) {
			try {
				$store = self::store();
				$snapshot = $store->read($title->getPrefixedDBkey(), $variant, $identity);
				if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') === '1') {
					@file_put_contents((string)getenv('WD_SEMANTIC_HTTP_ROOT') . '/semantic-http-publication.jsonl',
						json_encode(['event' => 'snapshot', 'pid' => getmypid(),
							'wikiId' => \MediaWiki\WikiMap\WikiMap::getCurrentWikiId(),
							'variant' => $variant, 'identity' => $identity,
							'entry' => $snapshot['entry'] !== null,
							'generation' => $snapshot['generation']]) . "\n",
						FILE_APPEND | LOCK_EX);
				}
				if ($prepared && ($prepared['_generation'] !== $snapshot['generation'] ||
					$prepared['revid'] !== (int)$identity['revision'] || $prepared['_touched'] !== $identity['touched'])) { return $prepared; }
				if ($snapshot['entry']) {
					return $snapshot['entry']['metadata'] + ['html' => $snapshot['entry']['body'], 'source' => 'fragment-cache'];
				}
				// A cache miss in tracking mode must not fall back to a stale
				// HTML file or to ApiParse's request-local parser cache.
				if ($semanticTracking) { $forceFreshSemanticParse = true; }
				$fill = $store->startFill($title->getPrefixedDBkey());
				// Another request may have finished between the first read and this lock.
				if ($fill) {
					$snapshot = $store->read($title->getPrefixedDBkey(), $variant, $identity);
					if ($prepared && $prepared['_generation'] !== $snapshot['generation']) { fclose($fill); return $prepared; }
					if ($snapshot['entry']) {
						fclose($fill);
						return $snapshot['entry']['metadata'] + ['html' => $snapshot['entry']['body'], 'source' => 'fragment-cache'];
					}
				} elseif (ReadingHooks::$warming) { return ['source' => 'busy']; }
			} catch (\Throwable $e) {
				self::log($e);
				if ($semanticTracking) { $forceFreshSemanticParse = true; }
			}
		}
		try {
			$result = ($eligible && !$forceFreshSemanticParse ? FileSources::read($context, $title, $page) : null) ??
				($semanticTracking ? null : $prepared);
			if ($result) { unset($result['_generation'], $result['_touched']); }
			$result ??= self::render($context, $title->getPrefixedText(), $eligible ? 'vector' : $skin,
				$forceFreshSemanticParse);
			if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') === '1') {
				@file_put_contents((string)getenv('WD_SEMANTIC_HTTP_ROOT') . '/semantic-http-publication.jsonl',
					json_encode(['event' => 'render-complete', 'pid' => getmypid(),
						'eligible' => $eligible, 'fill' => is_resource($fill),
						'forceFresh' => $forceFreshSemanticParse,
						'source' => $result['source'] ?? null,
						'expires' => $result['_expires'] ?? null, 'now' => time(),
						'htmlBytes' => strlen($result['html'] ?? '')]) . "\n",
					FILE_APPEND | LOCK_EX);
			}
			if ($fill && $result['_expires'] > time() && $result['html'] !== '') {
				try {
					if ($semanticTracking && SemanticRevisionStore::fingerprint(
						\SMW\Services\ServicesFactory::getInstance()->getStore(),
						\SMW\DataItems\WikiPage::newFromTitle($title)
					) !== $semanticRevision) {
						// The graph changed while this HTML was being rendered.
						return $result;
					}
					$published = $store->publish($title->getPrefixedDBkey(), $variant, $identity, $snapshot['generation'],
						$result['html'], array_diff_key($result, array_flip(['html', 'source', '_expires'])), $result['_expires']);
					if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') === '1') {
						@file_put_contents((string)getenv('WD_SEMANTIC_HTTP_ROOT') . '/semantic-http-publication.jsonl',
							json_encode(['event' => 'publish', 'pid' => getmypid(), 'published' => $published]) . "\n",
							FILE_APPEND | LOCK_EX);
					}
				} catch (\Throwable $e) { self::log($e); }
			}
			return $result;
		} finally { if (is_resource($fill)) { fclose($fill); } }
	}

	/** Run the actual ApiParse pipeline, including parser cache and third-party transforms. */
	private static function render(IContextSource $context, string $page, string $skin, bool $forceFresh = false): array {
		$derived = new DerivativeContext($context);
		$derived->setRequest(new DerivativeRequest($context->getRequest(), ['action' => 'parse', 'page' => $page,
			'prop' => 'text|revid', 'usearticle' => 1, 'redirects' => 1, 'useskin' => $skin,
			'uselang' => $context->getLanguage()->getCode(), 'formatversion' => 2]));
		$expires = 0;
		$outputPage = null;
		$expectedRevision = Title::newFromText($page)->getLatestRevID();
		$scope = MediaWikiServices::getInstance()->getHookContainer()->scopedRegister('ParserOutputPostCacheTransform',
			static function ($output, &$text, &$options) use (&$expires, $expectedRevision) {
				if ($output->getCacheRevisionId() !== $expectedRevision) { return; }
				$expires = (int)wfTimestamp(TS_UNIX, $output->getCacheTime()) + $output->getCacheExpiry();
				if (!$output->isCacheable()) { $expires = 0; }
			});
		$outputScope = MediaWikiServices::getInstance()->getHookContainer()->scopedRegister('ApiParseMakeOutputPage',
			static function ($module, $output) use (&$outputPage) { $outputPage = $output; });
		// MediaWiki 1.43's ApiParse honors the 'ApiMakeParserOptions' hook for
		// $suppressCache. A failed SMW freshness check must never reuse an
		// existing ParserOutput, even when page_touched has second resolution.
		$parseScope = $forceFresh ? MediaWikiServices::getInstance()->getHookContainer()->scopedRegister(
			'ApiMakeParserOptions',
			static function ($options, $title, $params, $api, &$reset, &$suppressCache) use ($page): void {
				if ($title->getPrefixedDBkey() === Title::newFromText($page)->getPrefixedDBkey()) {
					$suppressCache = true;
				}
			}
		) : null;
		// MobileFrontend consults the main request's action while formatting HTML.
		// Expose the same parse request for this synchronous call, then restore it.
		$mainContext = RequestContext::getMain();
		$originalRequest = $mainContext->getRequest();
		$mainContext->setRequest($derived->getRequest());
		try {
			$api = new ApiMain($derived, false, true);
			$api->execute();
			$data = $api->getResult()->getResultData(['parse'], ['Strip' => 'all']);
		} finally { $mainContext->setRequest($originalRequest); $scope = null; $outputScope = null; $parseScope = null; }
		$html = $data['text'] ?? '';
		if (is_array($html)) { $html = $html['*'] ?? ''; }
		$fragment = $context->getUser()->isRegistered() ? FragmentExtractor::extract($html) : FragmentExtractor::canonical($html);
		if ($outputPage) {
			$outputPage->considerCacheSettingsFinal();
			if (!$outputPage->couldBePublicCached()) { $expires = 0; }
		}
		if (preg_match('/<[^>]+\bnonce\s*=/i', $fragment)) { $expires = 0; }
		return ['html' => $fragment, 'title' => $data['title'] ?? $page, 'pageid' => (int)($data['pageid'] ?? 0),
			'revid' => (int)($data['revid'] ?? 0), 'source' => 'parser', '_expires' => $expires];
	}

	/** Called only after the complete anonymous response passed public-cache checks. */
	public static function capture($context, string $html, int $expires, int $revision, string $touched, string $generation): void {
		// The full-page pipeline can serve an earlier ParserOutput. Until the
		// HTML-file caches are integrated with the transactional semantic ledger,
		// populate tracked fragments only from a forced-fresh ApiParse.
		if ($context->getConfig()->get('WikidebatesSemanticCacheTracking')) { return; }
		if ($expires <= time() || $context->getUser()->isRegistered()) { return; }
		$title = $context->getTitle();
		$fragment = FragmentExtractor::canonical($html);
		if (!$title || $fragment === '' || preg_match('/<[^>]+\\bnonce\\s*=/i', $html)) { return; }
		$derived = new DerivativeContext($context);
		$derived->setRequest(new DerivativeRequest($context->getRequest(), [
			'action' => 'wikidebatesreading', 'page' => $title->getPrefixedText()]));
		self::read($derived, $title->getPrefixedText(), 'vector', [
			'html' => $fragment, 'title' => $title->getPrefixedText(), 'pageid' => $title->getArticleID(),
			'revid' => $revision, 'source' => 'page-output', '_expires' => $expires,
			'_touched' => $touched, '_generation' => $generation]);
	}

	public static function invalidate($title): void {
		try { self::store()->invalidate($title->getPrefixedDBkey()); }
		catch (\Throwable $e) { self::log($e); }
	}

	public static function log(\Throwable $e): void {
		\MediaWiki\Logger\LoggerFactory::getInstance('WikidebatesReading')->warning(
			'Reading cache unavailable ({type}); parser cache remains available.', ['type' => get_class($e)]);
	}
}
