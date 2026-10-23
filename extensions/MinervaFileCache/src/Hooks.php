<?php

namespace MediaWiki\Extension\MinervaFileCache;

use MediaWiki\MediaWikiServices;

class Hooks {
	public static function onArticleFromTitle($title, &$article, $context): void {
		if ($article || !$context->getConfig()->get('MinervaFileCacheEnabled')) {
			return;
		}
		// Leave the normal Article class untouched when the cache cannot be used.
		try {
			self::store($context->getConfig());
		} catch (\Throwable $e) {
			return;
		}
		switch ($title->getNamespace()) {
			case NS_FILE: $article = new CachedImagePage($title); break;
			case NS_CATEGORY: $article = new CachedCategoryPage($title); break;
			default: $article = new CachedArticle($title);
		}
	}

	public static function store($config = null): Store {
		$config ??= MediaWikiServices::getInstance()->getMainConfig();
		$directory = $config->get('MinervaFileCacheDirectory');
		if (!is_string($directory) || $directory === '' || $directory[0] !== '/') {
			throw new \RuntimeException('An absolute private cache directory is required');
		}
		$real = @realpath($directory);
		if ($real === false || !is_dir($real) || !is_writable($real)) {
			throw new \RuntimeException('Cache directory is missing or not writable');
		}
		foreach ([MW_INSTALL_PATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $webRoot) {
			$base = $webRoot === '' ? false : realpath($webRoot);
			if ($base && ($real === $base || str_starts_with($real . '/', $base . '/'))) {
				throw new \RuntimeException('Cache storage must be outside the web root');
			}
		}
		return new Store($real, \MediaWiki\WikiMap\WikiMap::getCurrentWikiId(),
			(int)$config->get('MinervaFileCacheTTL'), (int)$config->get('MinervaFileCacheMaxBytes'));
	}

	public static function onTitleSquidURLs($title, &$urls): void {
		// Invalidate even while serving is disabled. This hook includes dependent pages.
		if (MediaWikiServices::getInstance()->getMainConfig()->get('MinervaFileCacheDirectory')) {
			try { self::store()->invalidate($title->getPrefixedDBkey()); }
			catch (\Throwable $e) { self::logFailure('invalidation', $e); }
		}
	}

	public static function logFailure(string $operation, \Throwable $e): void {
		\MediaWiki\Logger\LoggerFactory::getInstance('MinervaFileCache')->warning(
			'Minerva file cache {operation} failed ({type}); normal rendering remains available.',
			['operation' => $operation, 'type' => get_class($e)]);
	}
}
