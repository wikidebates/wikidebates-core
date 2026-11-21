<?php

namespace MediaWiki {
	class MediaWikiServices {
		public static array $settings = [];
		public static function getInstance(): self { return new self; }
		public function getMainConfig(): object {
			return new class {
				public function get( string $name ): mixed {
					return MediaWikiServices::$settings[$name] ?? null;
				}
			};
		}
	}
}
namespace MediaWiki\WikiMap {
	class WikiMap {
		public static function getCurrentWikiId(): string { return 'isolated-cache-invalidation'; }
	}
}
namespace MediaWiki\Logger {
	class LoggerFactory {
		public static array $warnings = [];
		public static function getInstance( string $channel ): object {
			return new class {
				public function warning( string $message, array $context ): void {
					LoggerFactory::$warnings[] = $context;
				}
			};
		}
	}
}
namespace {
	use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
	use MediaWiki\Extension\Wikidebates\Reading\FragmentStore;
	use MediaWiki\Extension\Wikidebates\Reading\ReadingHooks;
	use MediaWiki\Logger\LoggerFactory;
	use MediaWiki\MediaWikiServices;
	use MediaWiki\WikiMap\WikiMap;

	require dirname( __DIR__ ) . '/bootstrap.php';
	$root = sys_get_temp_dir() . '/wikidebates-invalidation-test-' . bin2hex( random_bytes( 8 ) );
	mkdir( $root, 0700 );
	define( 'MW_INSTALL_PATH', $root . '/isolated-installation' );
	$temporary = sys_get_temp_dir() . '/wikidebates-reading-' . hash( 'sha256', MW_INSTALL_PATH . WikiMap::getCurrentWikiId() );
	$title = new class {
		public function canExist(): bool { return true; }
		public function getPrefixedDBkey(): string { return 'A'; }
	};
	MediaWikiServices::$settings = [ 'WikidebatesReadingCacheTTL' => 300, 'WikidebatesReadingFragments' => false ];
	$urls = [];
	try {
		ReadingHooks::onTitleSquidURLs( $title, $urls );
		check( !is_dir( $temporary ), 'A disabled, never-used temporary cache is not initialized by a purge' );
		MediaWikiServices::$settings['WikidebatesReadingFragments'] = true;
		ReadingHooks::onTitleSquidURLs( $title, $urls );
		check( !is_dir( $temporary ), 'Purging an enabled but unused cache does not create it either' );
		MediaWikiServices::$settings['WikidebatesReadingCacheDirectory'] = $root . '/nonexistent';
		ReadingHooks::onTitleSquidURLs( $title, $urls );
		check( !is_dir( $root . '/nonexistent' ), 'A configured missing directory is left alone by invalidation' );
		MediaWikiServices::$settings['WikidebatesReadingCacheDirectory'] = $root;
		$store = FragmentService::store();
		check( !$store->known( 'Never cached' ), 'Unknown pages are not known' );
		$store->invalidate( 'Never cached' );
		same( [], glob( $root . '/*' ), 'Lookup and purge create no wiki or page directories' );

		$identity = [ 'revision' => 1, 'touched' => '20261121000000' ];
		$snapshot = $store->read( 'A', 'variant', $identity );
		$fill = $store->startFill( 'A' );
		check( is_resource( $fill ), 'A fill is active before invalidation' );
		check( $store->publish( 'A', 'variant', $identity, $snapshot['generation'], 'old', [], time() + 300 ), 'Initial entry published' );
		$lockFile = glob( $root . '/*/*/*/page.lock' )[0];
		$inode = fileinode( $lockFile );
		MediaWikiServices::$settings['WikidebatesReadingFragments'] = false;
		ReadingHooks::onTitleSquidURLs( $title, $urls );
		check( !$store->publish( 'A', 'variant', $identity, $snapshot['generation'], 'stale', [], time() + 300 ),
			'An in-flight writer cannot republish after a disabled-cache purge' );
		check( $store->startFill( 'A' ) === false, 'Invalidation keeps the active fill lock domain' );
		fclose( $fill );
		clearstatcache( true, $lockFile );
		same( $inode, fileinode( $lockFile ), 'Invalidation does not replace the page lock file' );
		MediaWikiServices::$settings['WikidebatesReadingFragments'] = true;
		same( null, $store->read( 'A', 'variant', $identity )['entry'], 'Re-enabling cannot revive the purged entry' );
		$new = $store->read( 'A', 'variant', $identity );
		check( $new['generation'] !== $snapshot['generation'], 'Purge advances the generation' );
		check( $store->publish( 'A', 'variant', $identity, $new['generation'], 'fresh', [], time() + 300 ), 'A fresh fill can publish' );
		check( $store->known( 'A' ), 'A published page remains known' );
		same( 'fresh', $store->read( 'A', 'variant', $identity )['entry']['body'], 'Normal reads remain unchanged' );

		// Creation won the race with invalidation, but no generation was read yet.
		$pendingFill = $store->startFill( 'New page' );
		$store->invalidate( 'New page' );
		$afterPurge = $store->read( 'New page', 'variant', $identity );
		check( $store->publish( 'New page', 'variant', $identity, $afterPurge['generation'], 'new', [], time() + 300 ),
			'A fill taking its snapshot after invalidation can publish' );
		fclose( $pendingFill );
		same( [], LoggerFactory::$warnings, 'Missing optional caches do not generate error logs' );
		echo "OK: non-creating invalidation, disabled/re-enabled cache and in-flight generation guards\n";
	} finally {
		foreach ( [ $root, $temporary ] as $dir ) {
			if ( !is_dir( $dir ) ) { continue; }
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $path ) {
				$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
			}
			rmdir( $dir );
		}
	}
}
