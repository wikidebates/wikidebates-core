<?php

/** Real SMW error wrappers, after all successful persistence/job assertions. */
use MediaWiki\Extension\DebatePHP\DatabaseFailure;
use SMW\MediaWiki\Connection\TransactionHandler;
use Wikimedia\Rdbms\DBError;

$failure = null;
try {
	$store->getConnection()->select('wd_deliberately_missing_table', ['missing'], [], __FILE__);
} catch (Throwable $e) {
	$failure = $e;
}
check($failure instanceof RuntimeException, 'Real SMW SELECT failure propagates');
check(!$failure instanceof DBError && $failure->getPrevious() === null, 'SMW 6.0.1 discards the original SQL exception type/cause');
try { DatabaseFailure::rethrow($failure); throw new LogicException('Lost real SMW SQL failure'); }
catch (Throwable $e) { same($failure, $e, 'Original SMW wrapper preserved by native guard'); }
$report['sql_failures'] = ['smw_select_exception' => get_class($failure), 'original_type_lost_by_smw' => true];
$transaction = new TransactionHandler($services->getDBLoadBalancerFactory());
$transaction->markSectionTransaction('isolated-section');
foreach ([static fn() => $transaction->markSectionTransaction('nested'), static fn() => $transaction->detachSectionTransaction('wrong-section')] as $operation) {
	$caught = null;
	try { $operation(); } catch (RuntimeException $e) { $caught = $e; }
	check($caught !== null, 'Real SMW transaction-section failure occurs');
	try { DatabaseFailure::rethrow($caught); throw new LogicException('Lost transaction failure'); }
	catch (Throwable $e) { same($caught, $e, 'Original transaction failure preserved by native guard'); }
}
$transaction->detachSectionTransaction('isolated-section');
echo "Real SMW SQL type loss and transaction-section failures propagate: passed\n";
