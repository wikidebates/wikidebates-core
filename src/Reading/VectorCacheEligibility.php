<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * SMW installs custom Article handlers for Property and Concept namespaces.
 * They append live semantic lists that cannot be prepared as dependable
 * anonymous Vector HTML snapshots by the generic CLI file-cache rebuilder.
 */
final class VectorCacheEligibility {
	/**
	 * MediaWiki 1.43 FileCacheBase::cachePath() encodes the whole page key
	 * as ONE filename component, and adds .html[.gz]. The hash-depth
	 * setting only adds directories; it cannot shorten that filename.
	 *
	 * Linux filesystems such as ext4 accept at most 255 bytes in one
	 * filename component. A wiki title can fit MediaWiki's title limit
	 * yet exceed this after URL encoding. Native HTMLFileCache cannot
	 * read files renamed under a custom hash, so avoid an impossible
	 * write rather than changing the core cache format.
	 *
	 * @param string $pageKey Exact CacheKeyHelper::getKeyForPage() value
	 */
	public static function nativeViewFilenameTooLong(string $pageKey, bool $gzip): bool {
		$encoded = str_replace('.', '%2E', urlencode($pageKey));
		$filename = $encoded . '.html' . ($gzip ? '.gz' : '');
		return strlen($filename) > 255;
	}

	/** Detect the former ShortDescription JS configuration key in the HTML head. */
	public static function hasLegacyShortDescription(string $html): bool {
		$headEnd = strpos($html, '</head>');
		$key = strpos($html, '"wgShortDesc":');
		return $headEnd !== false && $key !== false && $key < $headEnd;
	}

	public static function hasSmwSpecialArticle(int $namespace): bool {
		foreach (['SMW_NS_PROPERTY', 'SMW_NS_CONCEPT'] as $constant) {
			if (defined($constant) && constant($constant) === $namespace) {
				return true;
			}
		}
		return false;
	}
}
