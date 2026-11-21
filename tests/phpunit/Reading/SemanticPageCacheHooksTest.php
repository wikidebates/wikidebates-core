<?php

namespace MediaWiki\Extension\Wikidebates\Tests\Reading;

use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
use PHPUnit\Framework\TestCase;

/** The semantic ParserCache fence is bypassed only inside reading-fragment lookups. */
class SemanticPageCacheHooksTest extends TestCase {
	public function testReadingScopeRestoredAfterSuccessAndException(): void {
		$this->assertSame(false, self::readingScope());
		$result = SemanticPageCacheHooks::forReadingFragment(static function () {
			self::assertTrue(self::readingScope());
			SemanticPageCacheHooks::forReadingFragment(static function () {
				self::assertTrue(self::readingScope());
			});
			self::assertTrue(self::readingScope());
			return 'ok';
		});
		$this->assertSame('ok', $result);
		$this->assertFalse(self::readingScope());

		try {
			SemanticPageCacheHooks::forReadingFragment(static function () {
				throw new \RuntimeException('scope-test');
			});
			$this->fail('Expected exception');
		} catch (\RuntimeException $e) {
			$this->assertSame('scope-test', $e->getMessage());
		}
		$this->assertFalse(self::readingScope());
	}

	public function testReadingScopeBypassesOnlyRegisteredSemanticFence(): void {
		$output = new \stdClass();
		$page = new \stdClass();
		$options = new \stdClass();
		SemanticPageCacheHooks::forReadingFragment(static function () use ($output, $page, $options) {
			self::assertTrue(SemanticPageCacheHooks::onRejectParserCacheValue($output, $page, $options));
		});
	}

	private static function readingScope(): bool {
		$property = new \ReflectionProperty(SemanticPageCacheHooks::class, 'readingFragment');
		return $property->getValue();
	}
}
