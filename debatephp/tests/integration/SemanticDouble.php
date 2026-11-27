<?php
// Test-only write recorder for a disposable MediaWiki without real SMW.
namespace {
	final class SMWQueryProcessor {}
}
namespace SMW {
	final class ParameterProcessorFactory {
		public static function newFromArray(array $raw): array { return $raw; }
	}
}
namespace SMW\Services {
	final class ServicesFactory {
		public static string $failure = '';
		public static function getInstance() { return new self; }
		public function newParserFunctionFactory() {
			return new class {
				public function newSetParserFunction($parser) {
					return new class {
						public function parse($raw) {
							if (ServicesFactory::$failure === 'exception') { throw new \RuntimeException('SENSITIVE semantic value'); }
							\MediaWiki\Extension\DebatePHP\Tests\CaptureLibrary::$semantic[] = $raw;
							return ServicesFactory::$failure === 'result' ? '<span>SENSITIVE semantic error</span>' : '';
						}
					};
				}
			};
		}
	}
}
