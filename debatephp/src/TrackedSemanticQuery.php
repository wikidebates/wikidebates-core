<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Parser\Parser;
use SMW\Query\QueryResult;
use SMW\Store;

/**
 * Preserve the existing call site and native SemanticScribunto result format.
 *
 * A semantic query may be rendered into a page's anonymous HTML snapshot,
 * but changes to its results must not invalidate that page's cached HTML.
 * No _ASK dependency tracking or parser-expiry veto is needed here.
 */
final class TrackedSemanticQuery {
	/** Legacy constant retained for compatibility with existing integrations. */
	public const OUTPUT_MARKER = 'wikidebates-semantic-query-tracked';

	public static function execute(Parser $parser, array $rawParameters, Store $store): QueryResult|string {
		return (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom($rawParameters);
	}
}
