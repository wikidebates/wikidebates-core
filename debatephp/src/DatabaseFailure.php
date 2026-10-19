<?php
namespace MediaWiki\Extension\DebatePHP;

/** Do not turn a failed database/transaction into a successful empty render. */
final class DatabaseFailure {
	public static function rethrow(\Throwable $exception): void {
		for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof \Wikimedia\Rdbms\DBError || $cause instanceof \PDOException) {
				throw $exception;
			}
			// SMW 6.0.1 Database::select discards both DBError's type and previous
			// exception. Match the throwing boundary, never SQL/error-message text.
			foreach ($cause->getTrace() as $frame) {
				if (($frame['class'] ?? '') === 'SMW\\MediaWiki\\Connection\\Database'
					&& ($frame['function'] ?? '') === 'select') {
					throw $exception;
				}
				if (($frame['class'] ?? '') === 'SMW\\MediaWiki\\Connection\\TransactionHandler'
					&& in_array($frame['function'] ?? '', ['markSectionTransaction', 'detachSectionTransaction'], true)) {
					throw $exception;
				}
			}
		}
	}
}
