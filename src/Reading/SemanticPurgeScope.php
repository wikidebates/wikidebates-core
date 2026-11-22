<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Request-local provenance of explicit SMW-dependent HTML purge dispatches.
 *
 * This only labels the synchronous TitleSquidURLs dispatch originating in our
 * SemanticCachePurgeJob. It does not change any purge, security policy, cache
 * identity, or page_touched value.
 */
final class SemanticPurgeScope {
	private static int $depth = 0;

	public static function isActive(): bool {
		return self::$depth > 0;
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function run(callable $callback) {
		self::$depth++;
		try {
			return $callback();
		} finally {
			self::$depth--;
		}
	}
}
