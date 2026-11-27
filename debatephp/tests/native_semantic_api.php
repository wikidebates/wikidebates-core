<?php

require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/semantic_api_double.php';

use MediaWiki\Extension\DebatePHP\NativeSemanticApi;
use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;
use SMW\Query\QueryResult;

check(NativeSemanticApi::available(), 'Direct PHP API is detected without a Scribunto class');
check(!class_exists(\SMW\Scribunto\LibraryFactory::class), 'No historical bridge class');
same(null, NativeSemanticApi::format(new QueryResult([])), 'Empty result remains null');
SemanticApiDouble::$trueWords = 'vrai,oui';
$result = new QueryResult([
	[
		['text', [['_txt', '0']]],
		['missing', []],
		['', [['_num', 0.0]]],
		['boolean', [['_boo', 'OUI'], ['_boo', 'no'], ['_boo', null]]],
		['numbers', [['_num', 42.0], ['_num', -1.25]]],
		['page', [['_wpg', '[[:A|Label]]']]],
		['duplicate', [['_txt', 'old']]],
		['duplicate', [['_txt', 'last']]]
	],
	[
		['', [['_txt', 'second']]],
		['01', [['_txt', 'numeric string alias']]],
		['0', [['_txt', 'numeric zero alias']]]
	]
]);
same([
	['text' => '0', 'missing' => null, 1 => 0, 'boolean' => [true, false, false],
		'numbers' => [42, -1.25], 'page' => '[[:A|Label]]', 'duplicate' => 'last'],
	[2 => 'second', '01' => 'numeric string alias', 0 => 'numeric zero alias']
], NativeSemanticApi::format($result), 'Formatted values preserve null, types, cardinality, duplicates and cross-row unnamed indices');
echo "OK: native SMW formatter contract\n";
