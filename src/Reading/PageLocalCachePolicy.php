<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;

/**
 * Opt-in policy: cached HTML is tied to the page, not to results of SMW queries
 * embedded in it. Normal page edits and security checks remain authoritative.
 */
final class PageLocalCachePolicy {
	public static function enabled(): bool {
		return (bool)MediaWikiServices::getInstance()->getMainConfig()->get(
			'WikidebatesPageLocalCacheOnly'
		);
	}
}
