<?php

/** Real MediaWiki exception classes and a failed SELECT in a disposable wiki. */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\DebatePHP\DatabaseFailure;
use MediaWiki\Extension\DebatePHP\Lua;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\DBTransactionStateError;

$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
$failure = null;
try {
	Lua::pcall(static fn() => $db->newSelectQueryBuilder()->select('missing')->from('wd_deliberately_missing_table')->caller(__FILE__)->fetchField());
} catch (DBError $e) {
	$failure = $e;
}
check($failure instanceof DBError, 'Failed real SQLite SELECT propagates through pcall');
foreach ([$failure, new DBTransactionStateError($db, 'Synthetic transaction state failure'), new RuntimeException('Wrapped', 0, $failure)] as $exception) {
	try { DatabaseFailure::rethrow($exception); throw new LogicException('Lost SQL failure'); }
	catch (Throwable $e) { same($exception, $e, 'Original real MediaWiki exception preserved'); }
}
echo "Real SQLite query error, MediaWiki 1.43 DBTransactionStateError and wrapped SQL cause: passed\n";
