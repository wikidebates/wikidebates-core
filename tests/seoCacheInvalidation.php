<?php

/**
 * SEO rollout safety test for MediaWiki's real Vector HTMLFileCache and the
 * MinervaFileCache disk store. The entire installation is disposable.
 *
 * The test deliberately demonstrates that upgrading PHP alone does not evict
 * an existing HTML snapshot. It then checks that the explicit, per-page purge
 * invalidates only the intended page and permits publication of fresh HTML.
 */
define('WD_TEST_PAGE_OWNED_CACHE', true);
require __DIR__ . '/mediawikiEnvironment.php';
require_once __DIR__ . '/../extensions/MinervaFileCache/src/Store.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\MinervaFileCache\Store;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;

/**
 * An independent page render that exercises WikiSEO's real page hooks.
 * The native body JSON-LD is a separate parser responsibility.
 */
function seoCacheHead( string $pageName, string $kind ): string {
	$context = new RequestContext();
	$context->setTitle( Title::newFromText( $pageName ) );
	$out = new OutputPage( $context );
	$out->setArticleFlag( true );
	$out->setRevisionTimestamp( '20261123120000' );

	$parser = new ParserOutput();
	$parser->setExtensionData( Metadata::DATA_KEY, [
		'wd_kind' => $kind,
		'title' => 'New Wikidebates title',
		'description' => 'New Wikidebates description',
		'site_name' => 'Wikidebates',
		'robots' => 'index,follow',
	] );
	Metadata::onOutputPageParserOutput( $out, $parser );
	Metadata::beforePageDisplay( $out );

	$hook = new \MediaWiki\Extension\WikiSEO\Hooks\PageHooks(
		\MediaWiki\MediaWikiServices::getInstance()->getMainConfig()
	);
	$skin = new class( [ 'name' => 'vector' ] ) extends \Skin {
		public function outputPage() {
		}
	};
	$hook->onBeforePageDisplay( $out, $skin );
	$headLinks = $out->getHeadLinksArray();
	Metadata::onOutputPageAfterGetHeadLinksArray( $headLinks, $out );

	return '<title>' . htmlspecialchars( $out->getHTMLTitle(), ENT_QUOTES, 'UTF-8' ) . '</title>' .
		implode( "\n", $headLinks ) . implode( "\n", $out->getHeadItemsArray() );
}

function seoCacheDocument( string $head, string $body ): string {
	return '<!DOCTYPE html><html><head>' . $head . '</head><body>' .
		'<main>' . htmlspecialchars( $body, ENT_QUOTES, 'UTF-8' ) . '</main>' .
		str_repeat( '<p>Stable public reading content.</p>', 20 ) .
		'</body></html>';
}

check( str_starts_with( MW_VERSION, '1.43.' ), 'Real MediaWiki 1.43 running' );
check( \MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded( 'WikiSEO' ),
	'The modern WikiSEO checkout is loaded' );

$title = Title::newFromText( 'SEO Cache test debate' );
$other = Title::newFromText( 'SEO Cache unaffected ordinary page' );
$oldHtml = seoCacheDocument(
	'<meta property="og:title" content="STALE SEO FROM OLD WIKISEO">' .
	'<script type="application/ld+json">{"@type":"Article"}</script>',
	'Old body'
);
$freshHead = seoCacheHead( $title->getPrefixedText(), 'debate' );
check( str_contains( $freshHead, 'New Wikidebates title' ),
	'New native title is present in actual output' );
check( str_contains( $freshHead, 'og:type' ),
	'Native Open Graph metadata is present in actual output' );
check( !str_contains( $freshHead, 'application/ld+json' ),
	'No generic WikiSEO JSON-LD is emitted in the head' );
check( !str_contains( $freshHead, 'STALE SEO FROM OLD WIKISEO' ),
	'No stale metadata is produced in a fresh render' );
$freshHtml = seoCacheDocument( $freshHead, 'Current body' );

// The Vector cache remains a HIT across a PHP-only deployment until explicitly purged.
$vector = new \HTMLFileCache( $title, 'view' );
$vectorOther = new \HTMLFileCache( $other, 'view' );
$vector->saveToFileCache( $oldHtml );
$vectorOther->saveToFileCache( seoCacheDocument( 'Unaffected project page', 'Other body' ) );
check( ( new \HTMLFileCache( $title, 'view' ) )->isCacheGood( '20200101000000' ),
	'An old Vector file is still considered valid without an explicit purge' );
check( \HTMLFileCache::clearFileCache( $title ),
	'Targeted Vector purge is enabled and succeeds' );
check( !( new \HTMLFileCache( $title, 'view' ) )->isCached(),
	'Old Vector HTML is physically invalidated' );
check( ( new \HTMLFileCache( $other, 'view' ) )->isCacheGood( '20200101000000' ),
	'An unrelated Vector HTML file remains valid' );
( new \HTMLFileCache( $title, 'view' ) )->saveToFileCache( $freshHtml );
check( ( new \HTMLFileCache( $title, 'view' ) )->isCacheGood( '20200101000000' ),
	'Fresh Vector HTML can be published after purging' );

// Minerva uses a different private disk store, with atomic generation fencing.
// The same page must be invalidated here, independently of Vector.
$minervaPath = $temporary . '/seo-minerva';
check( mkdir( $minervaPath, 0700 ), 'Disposable Minerva cache directory exists' );
$store = new Store( $minervaPath, 'wikidebates-test', 3600, 1024 * 1024 );
$key = $title->getPrefixedDBkey();
$otherKey = $other->getPrefixedDBkey();
$identity = [ 'revision' => 1, 'touched' => '20261123120000' ];
$variant = 'minerva-anonymous-test';
$gen = $store->read( $key, $variant, $identity )['generation'];
$otherGen = $store->read( $otherKey, $variant, $identity )['generation'];
check( $store->publish( $key, $variant, $identity, $gen, $oldHtml, [], PHP_INT_MAX ),
	'Old Minerva HTML snapshot can be published' );
check( $store->publish( $otherKey, $variant, $identity, $otherGen, $oldHtml, [], PHP_INT_MAX ),
	'Unrelated Minerva snapshot can be published' );
check( str_contains( $store->read( $key, $variant, $identity )['entry']['body'] ?? '',
	'STALE SEO FROM OLD WIKISEO' ), 'Old Minerva HTML remains a HIT before purging' );
$store->invalidate( $key );
check( $store->read( $key, $variant, $identity )['entry'] === null,
	'Targeted Minerva invalidation excludes old HTML' );
check( $store->read( $otherKey, $variant, $identity )['entry'] !== null,
	'Targeted Minerva invalidation preserves unrelated pages' );
check( !$store->publish( $key, $variant, $identity, $gen, $oldHtml, [], PHP_INT_MAX ),
	'Concurrent stale Minerva writer cannot republish its old generation' );
$newGeneration = $store->read( $key, $variant, $identity )['generation'];
check( $newGeneration !== $gen &&
	$store->publish( $key, $variant, $identity, $newGeneration, $freshHtml, [], PHP_INT_MAX ),
	'Fresh Minerva HTML replaces the stale snapshot' );
check( str_contains( $store->read( $key, $variant, $identity )['entry']['body'] ?? '',
	'New Wikidebates title' ), 'Minerva reuses newly generated native metadata' );

echo "OK: real Vector and Minerva HTML stores; explicit targeted SEO purge,\n";
echo "    unaffected pages, and stale-generation publication guard\n";
