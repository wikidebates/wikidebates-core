<?php

/** Boot the real MediaWiki registry with no site-specific ArgumentMap or archive config. */
$language = $argv[1] ?? '';
if ( !in_array( $language, [ 'fr', 'en', 'de', 'es', 'it', 'pt' ], true ) ) {
	throw new RuntimeException( 'Invalid test language' );
}
$legacy = ( $argv[2] ?? '' ) === 'legacy';
if ( $legacy ) {
	define( 'WD_FULLSTACK_TEST', true );
}
putenv( 'WD_TEST_LANGUAGE=' . $language );
putenv( 'WD_TEST_EXTENSIONS=' . ( $legacy ? 'Scribunto' : '' ) );
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\WikidebatesLuaArchive\ArchiveContentHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
$config = $services->getMainConfig();
$historical = in_array( $language, [ 'fr', 'en' ], true );
check( !$config->has( 'WikidebatesArgumentMapApi' ), 'No manual transport mode' );
check( !$config->has( 'WikidebatesArgumentMapDirectRead' ), 'No manual direct-read mode' );
check( !ExtensionRegistry::getInstance()->isLoaded( 'WikidebatesLuaArchive' ), 'No separate archive loader' );
$handler = $config->get( 'ContentHandlers' )['Scribunto'] ?? null;
if ( $legacy ) {
	check( $handler !== null && $handler !== ArchiveContentHandler::class, 'Real Scribunto remains authoritative' );
} elseif ( $historical ) {
	check( $services->getContentHandlerFactory()->getContentHandler( 'Scribunto' ) instanceof ArchiveContentHandler, 'Automatic inert archive' );
	same( 828, Title::newFromText( 'Module:Archived' )->getNamespace(), 'Historical module namespace' );
	same( 829, Title::newFromText( ( $language === 'fr' ? 'Discussion module' : 'Module talk' ) . ':Archived' )->getNamespace(), 'Historical talk namespace' );
	same( 'Module_talk', $services->getNamespaceInfo()->getCanonicalName( 829 ), 'Canonical talk namespace' );
	same( true, $config->get( 'NamespacesWithSubpages' )[828], 'Module subpages preserved' );
	same( true, $config->get( 'NamespacesWithSubpages' )[829], 'Talk subpages preserved' );
} else {
	same( null, $handler, 'No archived content handler on other languages' );
	same( false, $services->getNamespaceInfo()->getCanonicalName( 828 ), 'No extra module namespace' );
	same( false, $services->getNamespaceInfo()->getCanonicalName( 829 ), 'No extra module talk namespace' );
}
echo "OK: default configuration and archive for $language\n";
