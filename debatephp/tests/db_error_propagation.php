<?php

// Isolated stubs: this script runs in its own process without MediaWiki.
namespace Wikimedia\Rdbms {
	class DBError extends \RuntimeException {}
}

namespace SMW\Services {
	class ServicesFactory {
		public static function getInstance(): self {
			return new self();
		}

		public function getStore(): object {
			return new \stdClass();
		}
	}
}

namespace SMW\Scribunto {
	class LibraryFactory {
		public function __construct( object $store ) {}

		public function newQueryResultFrom( array $query ): void {
			throw new \Wikimedia\Rdbms\DBError( 'Simulated SQL failure' );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use MediaWiki\Extension\DebatePHP\Lua;
	use MediaWiki\Extension\DebatePHP\MediaWikiContext;
	use MediaWiki\Extension\DebatePHP\Table;
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
		$context->smwAsk( new Table( [ 1 => '[[Test::value]]' ] ) );
	} catch ( DBError $e ) {
		$rethrows = true;
	}
	if ( !$rethrows ) {
		throw new \RuntimeException( 'MediaWikiContext::smwAsk swallowed database error' );
	}

	echo "Database failures propagate through Lua::pcall and SMW query boundary; ordinary fallback preserved\n";
}
