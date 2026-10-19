<?php
// This double tests the exact native adapter parameter path without an SMW database.
// Real store updates MUST also be verified in a clone of production.
namespace SMW\Services {
	final class ServicesFactory {
		public static function getInstance() { return new self; }
		public function getStore() { return null; }
	}
}
namespace SMW\Scribunto {
	final class LibraryFactory {
		public static string $failure = '';
		public function __construct($store) {}
		public function newParserParameterProcessorFrom($raw) { return $raw; }
		public function newSetParserFunction($parser) {
			return new class {
				public function parse($raw) {
					if (LibraryFactory::$failure === 'exception') { throw new \RuntimeException('SENSITIVE semantic value'); }
					\MediaWiki\Extension\DebatePHP\Tests\CaptureLibrary::$semantic[] = $raw;
					return LibraryFactory::$failure === 'result' ? '<span>SENSITIVE semantic error</span>' : '';
				}
			};
		}
	}
}
