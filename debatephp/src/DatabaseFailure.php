<?php
namespace MediaWiki\Extension\DebatePHP;

/** Do not turn a failed database/transaction into a successful empty render. */
final class DatabaseFailure {
	public static function rethrow(\Throwable $exception): void {
		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof \Wikimedia\Rdbms\DBError || $cause instanceof \PDOException || $cause instanceof \mysqli_sql_exception) {
				throw $exception;
			}
			// Transaction-section failures remain untyped in modern SMW.
			// Match the throwing boundary, never exception-message text.
			foreach ($cause->getTrace() as $frame) {
				if (($frame['class'] ?? '') === 'SMW\\MediaWiki\\Connection\\TransactionHandler'
					&& in_array($frame['function'] ?? '', ['markSectionTransaction', 'detachSectionTransaction'], true)) {
					throw $exception;
				}
			}
		}
	}
}
