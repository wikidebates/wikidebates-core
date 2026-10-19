<?php

/** Every broad recovery boundary, including exceptions whose SQL type is lost. */
namespace Wikimedia\Rdbms {
	class DBError extends \RuntimeException {}
	class DBTransactionStateError extends DBError {}
}
namespace SMW\MediaWiki\Connection {
	class Database {
		public static function select(): void {
			throw new \RuntimeException('SECRET SQL discarded by SMW');
		}
	}
	class TransactionHandler {
		public static function markSectionTransaction(): void {
			throw new \RuntimeException('Invalid transaction section');
		}
	}
}
namespace SMW\Services {
	class ServicesFactory {
		public static function getInstance(): self { return new self; }
		public function getStore(): object { return new \stdClass; }
	}
}
namespace SMW\Scribunto {
	class LibraryFactory {
		public function __construct(object $store) {}
		public function newQueryResultFrom(array $query): void { \boundaryFailure(); }
		public function newSetParserFunction($parser): self { return $this; }
		public function newParserParameterProcessorFrom($raw): array { return $raw; }
		public function parse($params): void { \boundaryFailure(); }
	}
}
namespace MediaWiki\Extension\WikiSEO {
	class Validator {
		public static function validateParams($params): void { \boundaryFailure(); }
	}
}
namespace MediaWiki\Registration {
	class ExtensionRegistry {
		public static function getInstance(): self { return new self; }
		public function isLoaded($name): bool { return true; }
	}
}
namespace MediaWiki\Parser {
	class Parser {
		public function getPreprocessor(): self { return $this; }
		public function newFrame(): PPFrame { return new PPFrame; }
	}
	class PPFrame {
		public function expand($value): void { \boundaryFailure(); }
	}
}
namespace {
	class ExtVariables {
		public static function pfObj_vardefine(...$args): void { boundaryFailure(); }
		public static function pfObj_var(...$args): void { boundaryFailure(); }
	}
	function boundaryFailure(): void { throw $GLOBALS['boundaryFailure']; }
	class_alias(\MediaWiki\Parser\Parser::class, 'Parser');
	class_alias(\MediaWiki\Parser\PPFrame::class, 'PPFrame');
	require __DIR__ . '/../../tests/bootstrap.php';
	use MediaWiki\Extension\DebatePHP\Lua;
	use MediaWiki\Extension\DebatePHP\MediaWikiContext;
	use MediaWiki\Extension\DebatePHP\Table;
	use MediaWiki\Extension\DebatePHP\Telemetry;
	use MediaWiki\Extension\Wikidebates\RecentDebateChanges;

	$reflection = new ReflectionClass(MediaWikiContext::class);
	$context = $reflection->newInstanceWithoutConstructor();
	$parser = new Parser;
	$reflection->getProperty('parser')->setValue($context, $parser);
	$events = [];
	$telemetry = new Telemetry(true, true, [], static function($event) use (&$events): void { $events[] = $event; });
	$reflection->getProperty('telemetry')->setValue($context, $telemetry);
	$renderer = new \MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer($context);
	$rendererReflection = new ReflectionClass($renderer);
	$queryCache = $rendererReflection->getMethod('cacheMake')->invoke($renderer, 250);
	$rendererReflection->getProperty('SMW_ASK_CACHE')->setValue($renderer, $queryCache);
	$ask = $rendererReflection->getMethod('smwAskSafe');
	$operations = [
		'query' => static fn() => $context->smwAsk(new Table([1 => '[[SECRET]]'])),
		'set' => static fn() => $context->smwSet(new Table(['SECRET' => 'VALUE'])),
		'seo' => static fn() => $context->seoSet(new Table),
		'variable write' => static fn() => $context->variableSet('SECRET', 'VALUE'),
		'variable read' => static fn() => $context->variableGet('SECRET'),
		'pcall' => static fn() => Lua::pcall('boundaryFailure'),
		'cached query' => static fn() => $ask->invoke($renderer, new Table([1 => '[[SECRET]]']), 'cards'),
		'recent changes' => static fn() => RecentDebateChanges::render($parser, new PPFrame, ['test'])
	];
	$failures = [new \Wikimedia\Rdbms\DBError('SECRET SQL'), new \Wikimedia\Rdbms\DBTransactionStateError('SECRET SQL'), new PDOException('SECRET SQL')];
	if (class_exists('mysqli_sql_exception')) { $failures[] = new mysqli_sql_exception('SECRET SQL'); }
	$failures[] = new RuntimeException('Wrapper', 0, $failures[0]);
	foreach ([['SMW\\MediaWiki\\Connection\\Database', 'select'], ['SMW\\MediaWiki\\Connection\\TransactionHandler', 'markSectionTransaction']] as $thrower) {
		try { $thrower(); } catch (RuntimeException $e) { $failures[] = $e; }
	}
	$count = 0;
	foreach ($failures as $failure) {
		$GLOBALS['boundaryFailure'] = $failure;
		foreach ($operations as $name => $operation) {
			try { $operation(); throw new LogicException('Swallowed database failure at ' . $name); }
			catch (Throwable $caught) { same($failure, $caught, 'Original exception identity at ' . $name); }
			$count++;
		}
	}
	same([], $queryCache['data']->values, 'SQL exceptions are not memoized as empty query results');
	$GLOBALS['boundaryFailure'] = new RuntimeException('Recoverable');
	foreach ($operations as $name => $operation) {
		$value = $operation();
		if ($name === 'pcall') { same([false, 'Recoverable'], $value, 'pcall fallback'); }
		elseif ($name === 'recent changes') { check(str_contains($value, 'Recoverable'), 'Existing non SQL fallback'); }
		else { same(null, $value, 'Non SQL fallback at ' . $name); }
		$count++;
	}
	$telemetry->report();
	check(!str_contains(json_encode($events), 'SECRET'), 'Diagnostics omit SQL, query and values');
	echo "$count SQL boundary checks: original exceptions/causes, lost SMW types, transactions, ordinary recovery and redacted logs\n";
}
