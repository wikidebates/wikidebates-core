<?php

namespace MediaWiki\Parser {
	class Parser {}
}

namespace SMW {
	class Store {}
}

namespace SMW\Query {
	class QueryResult {}
}

namespace SMW\Scribunto {
	class LibraryFactory {
		public function __construct(\SMW\Store $store) {}
		public function newQueryResultFrom(array $params): string {
			return json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		}
	}
}

namespace {
	require dirname(__DIR__, 2) . '/debatephp/src/TrackedSemanticQuery.php';

	use MediaWiki\Extension\DebatePHP\TrackedSemanticQuery;
	use MediaWiki\Parser\Parser;

	$params = ['[[Some relation::A]]', 'format=count', 'limit=200', 'link=none'];
	$actual = TrackedSemanticQuery::execute(new Parser(), $params, new \SMW\Store());
	if ($actual !== json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) {
		throw new \RuntimeException('Native SemanticScribunto parameters were altered');
	}
	echo "OK: query results use the original SemanticScribunto renderer\n";
}
