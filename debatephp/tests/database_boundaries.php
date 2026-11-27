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
		const MARKER_PREFIX = "\x7f'\"`UNIQ-";
		public function getOutput(): object {
			return new class {
				public function updateCacheExpiry(int $seconds): void {}
				public function getExtensionData(string $key): ?array {
					// The date was already computed; exercise the SEO write failure.
					return $key === \MediaWiki\Extension\Wikidebates\Seo\PublicationDate::DATA_KEY
						? ['timestamp' => null] : null;
				}
				public function setExtensionData(string $key, array $data): void { \boundaryFailure(); }
			};
		}
		public function getPreprocessor(): self { return $this; }
		public function newFrame(): PPFrame { return new PPFrame; }
	}
	class PPFrame {
		public function expand($value): void { \boundaryFailure(); }
		public function getArguments(): array { return []; }
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
	define('WD_SEMANTIC_DOUBLE_NO_MESSAGES', true);
	require __DIR__ . '/semantic_api_double.php';
	\MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble::$onQuery = static fn() => boundaryFailure();
	\MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble::$onWrite = static fn() => boundaryFailure();
	use MediaWiki\Extension\DebatePHP\Lua;
	use MediaWiki\Extension\DebatePHP\MediaWikiContext;
	use MediaWiki\Extension\DebatePHP\Telemetry;
	use MediaWiki\Extension\Wikidebates\RecentDebateChanges;

	$reflection = new ReflectionClass(MediaWikiContext::class);
	$context = $reflection->newInstanceWithoutConstructor();
	$parser = new Parser;
	$reflection->getProperty('parser')->setValue($context, $parser);
	$reflection->getProperty('caller')->setValue($context, new PPFrame);
	$reflection->getProperty('module')->setValue($context, new PPFrame);
	$reflection->getProperty('argumentMode')->setValue($context, true);
	$events = [];
	$telemetry = new Telemetry(true, true, [], static function($event) use (&$events): void { $events[] = $event; });
	$reflection->getProperty('telemetry')->setValue($context, $telemetry);
	$renderer = new \MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer($context);
	$rendererReflection = new ReflectionClass($renderer);
	$read = $rendererReflection->getMethod('readSemantic');
	$operations = [
		'initialization read' => static fn() => $context->readSemantic([1 => '[[SECRET]]'], 'initialization'),
		'native read' => static fn() => $context->readSemantic([1 => '[[SECRET]]'], 'cards'),
		'cached native read' => static fn() => $read->invoke($renderer, [1 => '[[SECRET]]'], 'cards'),
		'set' => static fn() => $context->smwSet(['SECRET' => 'VALUE']),
		'seo' => static fn() => $context->seoSet([]),
		'variable write' => static fn() => $context->variableSet('SECRET', 'VALUE'),
		'variable read' => static fn() => $context->variableGet('SECRET'),
		'pcall' => static fn() => Lua::pcall('boundaryFailure'),
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
	$key = $rendererReflection->getMethod('smwKeyFromArray')->invoke($renderer, [1 => '[[SECRET]]']);
	same(null, $rendererReflection->getProperty('nativeSemanticCache')->getValue($renderer)->get($key), 'SQL exceptions cannot poison the native result cache');
	$GLOBALS['boundaryFailure'] = new RuntimeException('Recoverable');
	foreach ($operations as $name => $operation) {
		$value = $operation();
		if ($name === 'pcall') { same([false, 'Recoverable'], $value, 'pcall fallback'); }
		elseif ($name === 'recent changes') {
			check(str_contains($value, 'class="error"') && !str_contains($value, 'Recoverable'),
				'Recoverable error is generic and does not reveal exception text');
		}
		elseif (in_array($name, ['initialization read', 'native read', 'cached native read'], true)) {
			same(null, $value->rows, 'Recoverable native read retains null rows');
			same(false, $value->completeEmpty, 'An exception never certifies empty parents');
		}
		else { same(null, $value, 'Non SQL fallback at ' . $name); }
		$count++;
	}
	$telemetry->report();
	check(!str_contains(json_encode($events), 'SECRET'), 'Diagnostics omit SQL, query and values');
	echo "$count SQL boundary checks: original exceptions/causes, lost SMW types, transactions, ordinary recovery and redacted logs\n";
}
