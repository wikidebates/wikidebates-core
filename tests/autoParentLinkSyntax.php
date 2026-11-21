<?php

namespace MediaWiki\Title {
	// Standalone double only. The same checks run with the real Title class in
	// mediawikiParentCAS.php; this double is never loaded by MediaWiki.
	class Title {
		private function __construct( private string $key ) {
		}

		public static function newFromText( string $text, int $namespace = 0 ): ?self {
			$text = trim( preg_replace( '/[ _]+/', ' ', $text ) );
			if ( $text === '' || strpbrk( $text, '{}[]|<>' ) !== false ) {
				return null;
			}
			if ( str_starts_with( $text, 'Template:' ) ) {
				$namespace = 10;
				$text = substr( $text, 9 );
			}
			return new self( $namespace . ':' . ucfirst( $text ) );
		}

		public function equals( self $other ): bool {
			return $this->key === $other->key;
		}
	}
}

namespace {
	define( 'NS_MAIN', 0 );
	define( 'NS_TEMPLATE', 10 );
	require __DIR__ . '/bootstrap.php';
	require __DIR__ . '/autoParentLinkChecks.php';
}
