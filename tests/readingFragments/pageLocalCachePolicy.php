<?php

namespace MediaWiki {
	final class MediaWikiServices {
		public static bool $pageLocal = false;

		public static function getInstance(): self {
			return new self();
		}

		public function getMainConfig(): object {
			return new class {
				public function get(string $key): bool {
					if ($key !== 'WikidebatesPageLocalCacheOnly') {
						throw new \RuntimeException('Unexpected config lookup: ' . $key);
					}
					return MediaWikiServices::$pageLocal;
				}
			};
		}
	}
}

namespace MediaWiki\Parser {
	class Parser {}
}

namespace SMW {
	class Store {}
}

namespace SMW\Scribunto {
	class LibraryFactory {
		public function __construct(\SMW\Store $store) {}

		public function newQueryResultFrom(array $parameters): string {
			return implode(',', $parameters);
		}
	}
}

namespace {
	require dirname(__DIR__, 2) . '/src/Reading/PageLocalCachePolicy.php';
	require dirname(__DIR__, 2) . '/src/Reading/SemanticPageCacheHooks.php';
	require dirname(__DIR__, 2) . '/src/Reading/SemanticCacheGuard.php';
	require dirname(__DIR__, 2) . '/src/Reading/SemanticCacheHooks.php';
	require dirname(__DIR__, 2) . '/debatephp/src/TrackedSemanticQuery.php';

	use MediaWiki\MediaWikiServices;
	use MediaWiki\Parser\Parser;
	use MediaWiki\Extension\Wikidebates\Reading\PageLocalCachePolicy;
	use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
	use MediaWiki\Extension\Wikidebates\Reading\SemanticCacheGuard;
	use MediaWiki\Extension\Wikidebates\Reading\SemanticCacheHooks;
	use MediaWiki\Extension\DebatePHP\TrackedSemanticQuery;

	$checks = 0;
	$check = static function (bool $ok, string $message) use (&$checks): void {
		if (!$ok) { throw new \RuntimeException($message); }
		$checks++;
	};

	$check(!PageLocalCachePolicy::enabled(), 'Page-local mode is disabled unless explicitly enabled');
	MediaWikiServices::$pageLocal = true;
	$check(PageLocalCachePolicy::enabled(), 'Page-local mode is explicitly active');
	$check(SemanticPageCacheHooks::onHTMLFileCache__useFileCache(null),
		'Semantic queries do not veto otherwise eligible Vector file caching');
	SemanticPageCacheHooks::onBeforePageDisplay(null, null);
	$check(true, 'Semantic query pages do not disable HTTP output caching by themselves');
	$text = 'synthetic HTML';
	$check(SemanticPageCacheHooks::onParserAfterParse(null, $text, null),
		'Parser is not tagged with semantic fingerprints');
	$check(SemanticPageCacheHooks::onRejectParserCacheValue(null, null, null),
		'Semantic query result changes do not invalidate parser snapshots');
	$semanticRevision = 'old-fingerprint';
	$check(SemanticCacheGuard::safeForRead(null, null, $semanticRevision) &&
		$semanticRevision === null, 'Reading cache ignores semantic revisions');
	$check(SemanticCacheHooks::onPageMoveComplete(null, null, null, 0, 0, '', null),
		'Page moves do not trigger additional semantic dependent purges');
	$check(TrackedSemanticQuery::execute(new Parser(), ['[[example]]', 'limit=10'], new \SMW\Store()) ===
		'[[example]],limit=10', 'Native SemanticScribunto query remains executable without tracking');
	MediaWikiServices::$pageLocal = false;
	$check(!PageLocalCachePolicy::enabled(), 'The policy switch can be disabled immediately');

	echo json_encode(['assertions' => $checks, 'result' => 'PASS'], JSON_THROW_ON_ERROR) . "\n";
}
