<?php

// Isolated stubs: this script runs in its own process without MediaWiki.
namespace Wikimedia\Rdbms {
	class DBError extends \RuntimeException {}
}

namespace {
	require __DIR__ . '/bootstrap.php';
	require __DIR__ . '/semantic_api_double.php';
	\MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble::$onQuery = static function(): void {
		throw new \Wikimedia\Rdbms\DBError('Simulated SQL failure');
	};

	use MediaWiki\Extension\DebatePHP\DatabaseFailure;
	use MediaWiki\Extension\DebatePHP\MediaWikiContext;
	use Wikimedia\Rdbms\DBError;

	DatabaseFailure::rethrow(new RuntimeException('Expected recoverable failure'));
	$failure = new DBError('Simulated SQL failure');
	try {
		DatabaseFailure::rethrow($failure);
		throw new LogicException('Database guard swallowed SQL failure');
	} catch (DBError $e) {
		if ($e !== $failure) {
			throw new LogicException('Database guard replaced the original SQL failure');
		}
	}

	// Construct without MediaWiki in order to exercise the SMW exception boundary.
	$reflection = new \ReflectionClass( MediaWikiContext::class );
	$context = $reflection->newInstanceWithoutConstructor();
	$telemetry = $reflection->getProperty( 'telemetry' );
	$telemetry->setValue( $context, null );

	$rethrows = false;
	try {
		$context->readSemantic( [ 1 => '[[Test::value]]' ], 'other' );
	} catch ( DBError $e ) {
		$rethrows = true;
	}
	if ( !$rethrows ) {
		throw new \RuntimeException( 'MediaWikiContext::readSemantic swallowed database error' );
	}

	echo "Database failures propagate through the native guard and SMW query boundary; ordinary fallback preserved\n";
}
