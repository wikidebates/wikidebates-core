<?php

/** Standalone test doubles of SMW's direct API; never loaded by the extension. */
namespace MediaWiki\Extension\DebatePHP\Tests {
	final class SemanticApiDouble {
		public static array $query = [];
		public static array $write = [];
		public static array $rows = [];
		public static ?\Closure $onQuery = null;
		public static ?\Closure $onWrite = null;
		public static bool $fail = false;
		public static string $trueWords = 'true,t,yes,y';
		public static ?\SMWQuery $lastQuery = null;
	}
}
namespace {
	use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;

	define('SMW_OUTPUT_WIKI', 1);
	// The SQL-recovery suite also tests RecentDebateChanges without MediaWiki
	// services. Do not make its unrelated message capability appear present.
	if (!defined('WD_SEMANTIC_DOUBLE_NO_MESSAGES')) {
		function wfMessage($key) {
			if ($key !== 'smw_true_words') { throw new \LogicException('Unexpected message'); }
			return new class {
				public function text() { return SemanticApiDouble::$trueWords; }
			};
		}
	}
	final class SMWQuery {
		public const PROC_CONTEXT = 'context';
		public array $options = [];
		public function __construct(public array $raw, public string $context, public string $format, public array $printouts) {}
		public function setOption($key, $value) { $this->options[$key] = $value; }
	}
	final class SMWQueryProcessor {
		public static function getComponentsFromFunctionParams(array $raw, bool $showMode): array {
			if ($showMode) { throw new \LogicException('Unexpected query mode'); }
			SemanticApiDouble::$query = $raw;
			return [$raw, [], []];
		}
		public static function addThisPrintout(array &$printouts, array &$params): void {
			$printouts[] = 'main';
		}
		public static function getProcessedParams(array $params, array $printouts): array { return $params; }
		public static function createQuery($raw, $params, $context, $format, $printouts): SMWQuery {
			return new SMWQuery($raw, $context, $format, $printouts);
		}
	}
}
namespace MediaWiki\Linker {
	final class Linker {}
}
namespace SMW {
	use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;

	class Store {
		public function getQueryResult(\SMWQuery $query): \SMW\Query\QueryResult {
			SemanticApiDouble::$lastQuery = $query;
			if (SemanticApiDouble::$onQuery !== null) { (SemanticApiDouble::$onQuery)(); }
			if (SemanticApiDouble::$fail) { throw new \RuntimeException('test failure'); }
			return new \SMW\Query\QueryResult(SemanticApiDouble::$rows);
		}
	}
	final class ParameterProcessorFactory {
		public static function newFromArray(array $raw): array { return $raw; }
	}
}
namespace SMW\Query {
	final class QueryContext { public const SPECIAL_PAGE = 'special'; }
	final class QueryResult {
		private int $position = 0;
		public function __construct(private array $rows) {}
		public function getCount(): int { return count($this->rows); }
		public function getNext() {
			$row = $this->rows[$this->position++] ?? null;
			if ($row === null) { return false; }
			$out = [];
			foreach ($row as [$label, $values]) {
				$out[] = new class($label, $values) {
					private int $position = 0;
					public function __construct(private string $label, private array $values) {}
					public function getPrintRequest() {
						return new class($this->label) {
							public function __construct(private string $label) {}
							public function getLabel() { return $this->label; }
							public function getText($mode) { return $this->label; }
						};
					}
					public function getNextDataValue() {
						if (!isset($this->values[$this->position])) { return false; }
						[$type, $value] = $this->values[$this->position++];
						return new class($type, $value) {
							public function __construct(private string $type, private mixed $value) {}
							public function getTypeID() { return $this->type; }
							public function getWikiValue() { return $this->value; }
							public function getNumber() { return $this->value; }
							public function getShortText($mode, $linker) { return $this->value; }
						};
					}
				};
			}
			return $out;
		}
	}
}
namespace SMW\Services {
	use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;
	final class ServicesFactory {
		public static function getInstance(): self { return new self; }
		public function getStore(): \SMW\Store { return new \SMW\Store; }
		public function newParserFunctionFactory() {
			return new class {
				public function newSetParserFunction($parser) {
					return new class {
						public function parse($raw) {
							SemanticApiDouble::$write = $raw;
							if (SemanticApiDouble::$onWrite !== null) { return (SemanticApiDouble::$onWrite)(); }
							return '';
						}
					};
				}
			};
		}
	}
}
