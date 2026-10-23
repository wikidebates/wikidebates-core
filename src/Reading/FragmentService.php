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

	public static function mobileMode(): bool {
		$services = MediaWikiServices::getInstance();
		return $services->hasService('MobileFrontend.Context') &&
			$services->getService('MobileFrontend.Context')->shouldDisplayMobileView();
	}

	public static function read(IContextSource $context, string $pageName, string $skin): array {
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
		$eligible = $config->get('WikidebatesReadingFragments') && $request->getMethod() === 'GET' && !$context->getUser()->isRegistered() &&
			!$request->getSession()->isPersistent() && !$request->getHeader('Cookie') &&
			!$request->getHeader('Authorization') && empty($_SERVER['REMOTE_USER']) &&
			empty($_SERVER['PHP_AUTH_USER']) &&
			!$services->getTalkPageNotificationManager()->userHasNewMessages($context->getUser());
		$eligible = $eligible && !array_diff(array_keys($request->getValues()), ['action', 'page', 'useskin',
			'uselang', 'format', 'formatversion', 'maxlag', 'requestid', 'errorformat', 'errorlang', 'errorsuselocal', 'origin']) &&
			!preg_match('/no-cache|no-store/i', (string)$request->getHeader('Cache-Control'));
		$identity = ['pageid' => $page->getId(), 'revision' => $page->getLatest(), 'touched' => $page->getTouched(),
			'epoch' => $config->get('WikidebatesReadingCacheEpoch'), 'coreEpoch' => $config->get('CacheEpoch'), 'format' => 2];
		$server = parse_url($config->get('Server'));
		$host = $request->getHeader('Host') ?: (($server['host'] ?? '') . (isset($server['port']) ? ':' . $server['port'] : ''));
		$variant = json_encode([$skin, $context->getLanguage()->getCode(), self::mobileMode(),
			$config->get('Server'), $request->getProtocol(), strtolower($host), $request->getHeader('X-Subdomain') ?: '']);
		$store = null; $snapshot = null; $fill = false;
		if ($eligible) {
			try {
				$store = self::store();
				$snapshot = $store->read($title->getPrefixedDBkey(), $variant, $identity);
				if ($snapshot['entry']) {
					return $snapshot['entry']['metadata'] + ['html' => $snapshot['entry']['body'], 'source' => 'fragment-cache'];
				}
				$fill = $store->startFill($title->getPrefixedDBkey());
			} catch (\Throwable $e) { self::log($e); }
		}
		try {
			$result = self::render($context, $title->getPrefixedText(), $skin);
			if ($fill && $result['_expires'] > time() && $result['html'] !== '') {
				try {
					$store->publish($title->getPrefixedDBkey(), $variant, $identity, $snapshot['generation'],
						$result['html'], array_diff_key($result, array_flip(['html', 'source', '_expires'])), $result['_expires']);
				} catch (\Throwable $e) { self::log($e); }
			}
			return $result;
		} finally { if (is_resource($fill)) { fclose($fill); } }
	}

	/** Run the actual ApiParse pipeline, including parser cache and third-party transforms. */
	private static function render(IContextSource $context, string $page, string $skin): array {
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
		// MobileFrontend consults the main request's action while formatting HTML.
		// Expose the same parse request for this synchronous call, then restore it.
		$mainContext = RequestContext::getMain();
		$originalRequest = $mainContext->getRequest();
		$mainContext->setRequest($derived->getRequest());
		try {
			$api = new ApiMain($derived, false, true);
			$api->execute();
			$data = $api->getResult()->getResultData(['parse'], ['Strip' => 'all']);
		} finally { $mainContext->setRequest($originalRequest); $scope = null; $outputScope = null; }
		$html = $data['text'] ?? '';
		if (is_array($html)) { $html = $html['*'] ?? ''; }
		$fragment = FragmentExtractor::extract($html);
		if ($outputPage) {
			$outputPage->considerCacheSettingsFinal();
			if (!$outputPage->couldBePublicCached()) { $expires = 0; }
		}
		if (preg_match('/<[^>]+\bnonce\s*=/i', $fragment)) { $expires = 0; }
		return ['html' => $fragment, 'title' => $data['title'] ?? $page, 'pageid' => (int)($data['pageid'] ?? 0),
			'revid' => (int)($data['revid'] ?? 0), 'source' => 'parser', '_expires' => $expires];
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
