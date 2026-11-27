<?php

namespace MediaWiki\Parser { class Parser {} }

namespace {
	require dirname(__DIR__) . '/bootstrap.php';
	require dirname(__DIR__, 2) . '/debatephp/tests/semantic_api_double.php';

	use MediaWiki\Extension\DebatePHP\TrackedSemanticQuery;
	use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;
	use MediaWiki\Parser\Parser;

	$params = ['[[Some relation::A]]', 'format=count', 'limit=200', 'link=none'];
	$actual = TrackedSemanticQuery::execute(new Parser(), $params, new \SMW\Store());
	same(0, $actual->getCount(), 'Actual query result returned');
	same($params, SemanticApiDouble::$lastQuery->raw, 'Raw SMW parameters retain exact order');
	same(\SMW\Query\QueryContext::SPECIAL_PAGE, SemanticApiDouble::$lastQuery->context, 'Historical query context');
	same(['main'], SemanticApiDouble::$lastQuery->printouts, 'Main printout retained');
	same([\SMWQuery::PROC_CONTEXT => 'SSC.LibraryFactory'], SemanticApiDouble::$lastQuery->options, 'Query context identity preserved');
	echo "OK: native query preserves page-owned snapshot semantics\n";
}
