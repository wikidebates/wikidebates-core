<?php

/** Real SMW error wrappers, after all successful persistence/job assertions. */
use MediaWiki\Extension\DebatePHP\Lua;
use SMW\MediaWiki\Connection\TransactionHandler;
use Wikimedia\Rdbms\DBError;

$failure = null;
try {
	Lua::pcall(static fn() => $store->getConnection()->select('wd_deliberately_missing_table', ['missing'], [], __FILE__));
} catch (Throwable $e) {
	$failure = $e;
}
check($failure instanceof RuntimeException, 'Real SMW SELECT failure propagates');
check(!$failure instanceof DBError && $failure->getPrevious() === null, 'SMW 6.0.1 discards the original SQL exception type/cause');
$report['sql_failures'] = ['smw_select_exception' => get_class($failure), 'original_type_lost_by_smw' => true];
$transaction = new TransactionHandler($services->getDBLoadBalancerFactory());
$transaction->markSectionTransaction('isolated-section');
foreach ([static fn() => $transaction->markSectionTransaction('nested'), static fn() => $transaction->detachSectionTransaction('wrong-section')] as $operation) {
	$caught = null;
	try { Lua::pcall($operation); } catch (RuntimeException $e) { $caught = $e; }
	check($caught !== null, 'Real SMW transaction-section failure propagates through pcall');
}
$transaction->detachSectionTransaction('isolated-section');
echo "Real SMW SQL type loss and transaction-section failures propagate: passed\n";
