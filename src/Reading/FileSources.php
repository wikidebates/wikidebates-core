<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;

/** Read-only adapters: never make an HTTP request or build a full file on a miss. */
class FileSources {
	public static function read(IContextSource $context, $title, $page): ?array {
		$config = $context->getConfig();
		if (!$config->get('UseFileCache') ||
			$context->getLanguage()->getCode() !== $config->get('LanguageCode')) { return null; }
		try {
			if (!self::extensionsAllow($context, $title)) { return null; }
			// The native file cache has no dynamic-content expiry. Require a still-valid
			// parser-cache record, without asking WikiPage to render on a miss.
			$output = MediaWikiServices::getInstance()->getParserCache()->get($page, ParserOptions::newFromAnon());
			if (!$output || !$output->isCacheable()) { return null; }
			$expires = (int)wfTimestamp(TS_UNIX, $output->getCacheTime()) + $output->getCacheExpiry();
			if ($expires <= time()) { return null; }
		} catch (\Throwable $e) { FragmentService::log($e); return null; }
		try {
			$cache = new \HTMLFileCache($title, 'view');
			$server = parse_url($config->get('Server'));
			$canonicalHost = ($server['host'] ?? '') . (isset($server['port']) ? ':' . $server['port'] : '');
			$host = $context->getRequest()->getHeader('Host') ?: $canonicalHost;
			if (in_array($config->get('DefaultSkin'), ['vector', 'vector-2022'], true) &&
				strtolower($host) === strtolower($canonicalHost) &&
				$cache->isCacheGood($page->getTouched())) {
				$created = (int)wfTimestamp(TS_UNIX, $cache->cacheTimestamp());
				$limit = min($expires, $created + $output->getCacheExpiry(), $created + min(2592000, (int)$config->get('WikidebatesReadingCacheTTL')));
				if ($limit > time()) {
					$html = FragmentExtractor::canonical($cache->fetchText());
					if ($html !== '') { return self::result($html, $title, $page, 'vector-file', $limit); }
				}
			}
		} catch (\Throwable $e) { FragmentService::log($e); }
		try {
			if (!class_exists(\MediaWiki\Extension\MinervaFileCache\Hooks::class) ||
				!$config->get('MinervaFileCacheEnabled')) { return null; }
			$identity = ['revision' => (int)$page->getLatest(), 'touched' => $page->getTouched(),
				'epoch' => (string)$config->get('MinervaFileCacheEpoch'), 'coreEpoch' => $config->get('CacheEpoch'),
				'format' => 'MinervaFileCache-0.1.0'];
			$entry = \MediaWiki\Extension\MinervaFileCache\Hooks::store()->readForFragment(
				$title->getPrefixedDBkey(), $identity, $context);
			if ($entry) {
				$html = FragmentExtractor::canonical($entry['body']);
				$limit = min($expires, $entry['expires'], $entry['created'] + $output->getCacheExpiry());
				if ($html !== '' && $limit > time()) { return self::result($html, $title, $page, 'minerva-file', $limit); }
			}
		} catch (\Throwable $e) { FragmentService::log($e); }
		return null;
	}

	/** Respect extension vetoes, except the known MF veto against native mobile files. */
	private static function extensionsAllow($context, $title): bool {
		$hooks = MediaWikiServices::getInstance()->getHookContainer();
		$view = new \MediaWiki\Context\DerivativeContext($context);
		$view->setTitle($title);
		$view->setRequest(new \MediaWiki\Request\DerivativeRequest($context->getRequest(), [
			'title' => $title->getPrefixedText(), 'action' => 'view']));
		$previous = null;
		$previous = set_error_handler(static function ($severity, $message, $file, $line) use (&$previous) {
			if ($severity === E_USER_DEPRECATED && str_starts_with($message,
				'Use of MediaWiki\\HookContainer\\HookContainer::getHandlerCallbacks was deprecated in MediaWiki 1.41.')) { return true; }
			return $previous ? $previous($severity, $message, $file, $line) : false;
		});
		try { $callbacks = $hooks->getHandlerCallbacks('HTMLFileCache::useFileCache'); }
		finally { restore_error_handler(); }
		foreach ($callbacks as $callback) {
			if (is_array($callback) && is_object($callback[0]) && get_class($callback[0]) === 'MobileFrontendHooks' &&
				$callback[1] === 'onHTMLFileCache__useFileCache') { continue; }
			$result = $callback($view);
			if ($result !== null && $result !== true) { return false; }
		}
		return $hooks->run('IsFileCacheable', [\Article::newFromTitle($title, $view)]);
	}

	private static function result(string $html, $title, $page, string $source, int $expires): array {
		return ['html' => $html, 'title' => $title->getPrefixedText(), 'pageid' => (int)$page->getId(),
			'revid' => (int)$page->getLatest(), 'source' => $source, '_expires' => $expires];
	}
}
