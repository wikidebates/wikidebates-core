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

	use MediaWiki\Extension\DebatePHP\Lua;
	use MediaWiki\Extension\DebatePHP\MediaWikiContext;
	use Wikimedia\Rdbms\DBError;

	[ $success, $result ] = Lua::pcall( static fn() => 'ok' );
	if ( !$success || $result !== 'ok' ) {
		throw new \RuntimeException( 'Lua::pcall must preserve successful results' );
	}

	[ $success, $result ] = Lua::pcall( static function (): void {
		throw new \RuntimeException( 'Expected recoverable failure' );
	} );
	if ( $success || $result !== 'Expected recoverable failure' ) {
		throw new \RuntimeException( 'Lua::pcall must preserve non-database fallback' );
	}

	$rethrows = false;
	try {
		Lua::pcall( static function (): void {
			throw new DBError( 'Simulated SQL failure' );
		} );
	} catch ( DBError $e ) {
		$rethrows = true;
	}
	if ( !$rethrows ) {
		throw new \RuntimeException( 'Lua::pcall swallowed database error' );
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

	echo "Database failures propagate through Lua::pcall and SMW query boundary; ordinary fallback preserved\n";
}
