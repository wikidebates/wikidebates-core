<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * SMW installs custom Article handlers for Property and Concept namespaces.
 * They append live semantic lists that cannot be prepared as dependable
 * anonymous Vector HTML snapshots by the generic CLI file-cache rebuilder.
 */
final class VectorCacheEligibility {
	public static function hasSmwSpecialArticle(int $namespace): bool {
		foreach (['SMW_NS_PROPERTY', 'SMW_NS_CONCEPT'] as $constant) {
			if (defined($constant) && constant($constant) === $namespace) {
				return true;
			}
		}
		return false;
	}
}
