<?php

/**
 * Observe *current* native semantic-query cache behavior on disposable data.
 * A freshness warning is a finding, not a passing freshness guarantee.
 * The test never enables experimental tracking or changes production settings.
 */
define( 'WD_FULLSTACK_TEST', true );
putenv( 'WD_TEST_LANGUAGE=en' );
putenv( 'WD_TEST_SEMANTIC_BASELINE=1' );
putenv( 'WD_TEST_EXTENSIONS=Scribunto,SemanticMediaWiki,SemanticScribunto' );
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
use SMW\Services\ServicesFactory;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\DataItems\Property as SemanticProperty;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

check( str_starts_with( MW_VERSION, '1.43.' ), 'MediaWiki 1.43 required' );
check( SMW_VERSION === '7.3.1', 'Exact Semantic MediaWiki version' );
check( SMW_SCRIBUNTO_VERSION === '2.3.3', 'Exact SemanticScribunto version' );
$services = MediaWikiServices::getInstance();
$store = ServicesFactory::getInstance()->getStore();
check( $store instanceof \SMW\SQLStore\SQLStore, 'Real SMW SQLStore' );
check( $store->setup( false ), 'Disposable SMW schema is initialized' );
$schema = $GLOBALS;
( new \SMW\SetupFile() )->loadSchema( $schema );
\SMW\Globals::replace( $schema );
check( \SMW\Site::isReady(), 'SMW is ready for isolated observations' );
require __DIR__ . '/fullstackSupport.php';
$fsUser = \MediaWiki\User\User::newSystemUser( 'Wikidebates baseline audit', [ 'steal' => false ] );
check( $fsUser !== null, 'Disposable user created' );

$register = static function ( $parser ): void {
	$parser->setHook( 'wd-baseline-parent', static function ( $input, $args, $parser, $frame ): string {
		$context = new MediaWikiContext( $parser, $frame, [], false );
		$rows = $context->readSemantic( Table::wrap( [
			'[[WD Baseline Parent::' . trim( $input ) . ']]',
			'limit=200', 'link=none'
		] ), 'parent-audit' );
		return 'WD_BASELINE_MATCHES=' . count( $rows->rows ?? [] );
	} );
};
$services->getHookContainer()->register( 'ParserFirstCallInit', $register );
$register( $services->getParser() );

fsSave( 'Property:WD Baseline Parent', '[[Has type::Page]]' );
fsSave( 'WD Baseline A', 'Parent A' );
fsSave( 'WD Baseline B', 'Parent B' );
fsSave( 'WD Baseline child', 'No relationship yet' );
fsSave( 'WD Baseline query A', 'Initial stub' );
fsSave( 'WD Baseline query B', 'Initial stub' );
fsFlush();
fsSave( 'WD Baseline query A', "== Summary ==\n<wd-baseline-parent>WD Baseline A</wd-baseline-parent>\n== Parent debates ==\nA" );
fsSave( 'WD Baseline query B', "== Summary ==\n<wd-baseline-parent>WD Baseline B</wd-baseline-parent>\n== Parent debates ==\nB" );
fsSave( 'WD Baseline actual argument', '{{#argument:render|lang=en|summary=Real argument baseline}}' );
fsSave( 'WD Baseline actual debate', '{{#debate:render|lang=en|topic=Real debate baseline}}' );
fsFlush();

$linkStore = ( new QueryDependencyLinksStoreFactory() )->newQueryDependencyLinksStore( $store );
$profiles = static function ( string $name ) use ( $store, $linkStore ): array {
	$store->clear();
	$subject = SemanticWikiPage::newFromTitle( Title::newFromText( $name ) );
	return [
		'ask_profiles' => count( $store->getPropertyValues( $subject, new SemanticProperty( '_ASK' ) ) ),
		'query_links' => count( $linkStore->findEmbeddedQueryIdListBySubject( $subject ) ),
	];
};

$directMembers = static function ( string $parent ) use ( $store ): array {
	$store->clear();
	$result = ( new \SMW\Scribunto\LibraryFactory( $store ) )->newQueryResultFrom( [
			'[[WD Baseline Parent::' . $parent . ']]', 'limit=200', 'link=none'
	] );
	check( $result instanceof \SMW\Query\QueryResult, 'Direct semantic query works' );
	$names = [];
	foreach ( $result->getResults() as $item ) {
		if ( $item instanceof SemanticWikiPage && $item->getTitle() ) {
			$names[] = $item->getTitle()->getPrefixedText();
		}
	}
	sort( $names );
	return $names;
};

$parserText = static function ( string $name ) use ( $services ): array {
	$store = ServicesFactory::getInstance()->getStore();
	$store->clear();
	// Simulate a new HTTP request's request-local ParserOutputAccess cache.
	$access = $services->getParserOutputAccess();
	$local = ( new ReflectionObject( $access ) )->getProperty( 'localCache' );
	$local->setValue( $access, new \MapCacheLRU( 10 ) );
	$title = Title::newFromText( $name );
	$page = $services->getWikiPageFactory()->newFromTitle( $title );
	$output = $page->getParserOutput( ParserOptions::newFromAnon() );
	check( $output instanceof \MediaWiki\Parser\ParserOutput, 'ParserOutput available for ' . $name );
	return [
		'matches_0' => str_contains( $output->getText(), 'WD_BASELINE_MATCHES=0' ),
		'matches_1' => str_contains( $output->getText(), 'WD_BASELINE_MATCHES=1' ),
		'cacheable' => $output->isCacheable(),
		'expiry' => $output->getCacheExpiry(),
		'revision' => $page->getLatest(),
		'touched' => $page->getTouched(),
	];
};

$readFragment = static function ( string $name ) use ( $services, $store ): array {
	$store->clear();
	$access = $services->getParserOutputAccess();
	$local = ( new ReflectionObject( $access ) )->getProperty( 'localCache' );
	$local->setValue( $access, new \MapCacheLRU( 10 ) );
	$context = new RequestContext();
	$context->setRequest( new FauxRequest( [
		'action' => 'wikidebatesreading', 'page' => $name, 'useskin' => 'vector',
		'format' => 'json', 'formatversion' => 2,
	], false ) );
	$context->setUser( $services->getUserFactory()->newAnonymous() );
	$context->setLanguage( $services->getContentLanguage() );
	$context->setTitle( Title::newFromText( $name ) );
	$result = FragmentService::read( $context, $name, 'vector' );
	return [
		'source' => $result['source'] ?? '',
		'matches_0' => str_contains( $result['html'] ?? '', 'WD_BASELINE_MATCHES=0' ),
		'matches_1' => str_contains( $result['html'] ?? '', 'WD_BASELINE_MATCHES=1' ),
	];
};

check( $directMembers( 'WD Baseline A' ) === [], 'Initial empty semantic relation A' );
check( $directMembers( 'WD Baseline B' ) === [], 'Initial empty semantic relation B' );
$report = [
	'mediawiki' => MW_VERSION,
	'smw' => SMW_VERSION,
	'semantic_scribunto' => SMW_SCRIBUNTO_VERSION,
	'php' => PHP_VERSION,
	'configuration' => [
		'query_dependency_links' => $GLOBALS['smwgEnabledQueryDependencyLinksStore'] ?? null,
		'query_profiler' => $GLOBALS['smwgQueryProfiler'] ?? null,
		'query_result_cache_type' => $GLOBALS['smwgQueryResultCacheType'] ?? null,
	],
	'profiles' => [
		'native_query_a' => $profiles( 'WD Baseline query A' ),
		'native_argument' => $profiles( 'WD Baseline actual argument' ),
		'native_debate' => $profiles( 'WD Baseline actual debate' ),
	],
	'events' => [],
];
$report['events']['empty'] = [
	'parser_a' => $parserText( 'WD Baseline query A' ),
	'fragment_a_first' => $readFragment( 'WD Baseline query A' ),
	'fragment_a_second' => $readFragment( 'WD Baseline query A' ),
];
check( $report['events']['empty']['parser_a']['matches_0'], 'Initial query page displays zero relations' );

fsSave( 'WD Baseline child', '{{#set:WD Baseline Parent=WD Baseline A}}' );
fsFlush();
same( [ 'WD Baseline child' ], $directMembers( 'WD Baseline A' ), 'Empty to one semantic relation' );
$report['events']['insert'] = [
	'parser_a' => $parserText( 'WD Baseline query A' ),
	'fragment_a' => $readFragment( 'WD Baseline query A' ),
];

fsSave( 'WD Baseline child', '{{#set:WD Baseline Parent=WD Baseline B}}' );
fsFlush();
same( [], $directMembers( 'WD Baseline A' ), 'Old parent no longer matches' );
same( [ 'WD Baseline child' ], $directMembers( 'WD Baseline B' ), 'New parent is available' );
$report['events']['change_parent'] = [
	'parser_a' => $parserText( 'WD Baseline query A' ),
	'parser_b' => $parserText( 'WD Baseline query B' ),
];

$move = $services->getMovePageFactory()->newMovePage(
	Title::newFromText( 'WD Baseline child' ), Title::newFromText( 'WD Baseline child renamed' )
)->moveIfAllowed( $fsUser, 'Disposable baseline rename' );
check( $move->isOK(), 'Subject title can be renamed' );
fsFlush();
same( [ 'WD Baseline child renamed' ], $directMembers( 'WD Baseline B' ),
	'Semantic result reflects page rename' );
$report['events']['rename'] = [ 'parser_b' => $parserText( 'WD Baseline query B' ) ];

fsSave( 'WD Baseline child renamed', 'Parent relation removed' );
fsFlush();
same( [], $directMembers( 'WD Baseline B' ), 'Removing final relation is visible' );
$report['events']['remove'] = [ 'parser_b' => $parserText( 'WD Baseline query B' ) ];

// Reintroduce a matching subject, then explicitly refresh ONLY the query page.
// Deletion now tests a previously correct cached result (1 -> 0), instead
// of accidentally considering an older zero-match result fresh.
fsSave( 'WD Baseline child renamed', '{{#set:WD Baseline Parent=WD Baseline B}}' );
fsFlush();
same( [ 'WD Baseline child renamed' ], $directMembers( 'WD Baseline B' ),
	'Pre-deletion relationship is present' );
fsSave( 'WD Baseline query B', "== Summary ==\n<wd-baseline-parent>WD Baseline B</wd-baseline-parent>\n== Parent debates ==\nB<!-- refresh baseline -->" );
fsFlush();
$beforeDelete = $parserText( 'WD Baseline query B' );
check( $beforeDelete['matches_1'], 'Pre-deletion parser cache genuinely contains one result' );
$report['events']['before_delete'] = [ 'parser_b' => $beforeDelete ];

$services->getUserGroupManager()->addUserToGroup( $fsUser, 'sysop' );
$deletedPage = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( 'WD Baseline child renamed' ) );
$deleted = $services->getDeletePageFactory()->newDeletePage( $deletedPage, $fsUser )
	->deleteIfAllowed( 'Disposable baseline deletion' );
check( $deleted->isOK(), 'Synthetic subject can be deleted' );
fsFlush();
same( [], $directMembers( 'WD Baseline B' ), 'Deletion keeps the semantic query empty' );
$report['events']['delete'] = [ 'parser_b' => $parserText( 'WD Baseline query B' ) ];

$report['safety'] = [
	'parser_after_insert_fresh' => $report['events']['insert']['parser_a']['matches_1'],
	'fragment_after_insert_fresh' => $report['events']['insert']['fragment_a']['matches_1'],
	'parser_old_parent_after_move_fresh' => $report['events']['change_parent']['parser_a']['matches_0'],
	'parser_new_parent_after_move_fresh' => $report['events']['change_parent']['parser_b']['matches_1'],
	'parser_after_rename_fresh' => $report['events']['rename']['parser_b']['matches_1'],
	'parser_after_removal_fresh' => $report['events']['remove']['parser_b']['matches_0'],
	'parser_after_delete_fresh' => $report['events']['delete']['parser_b']['matches_0'],
];
$report['safety']['all_checked_layers_fresh'] = !in_array( false, $report['safety'], true );

$reportJson = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
$reportPath = getenv( 'WD_SEMANTIC_BASELINE_REPORT' );
if ( $reportPath !== false && $reportPath !== '' ) {
	check( file_put_contents( $reportPath, $reportJson . "\n" ) !== false, 'Report written' );
}
echo $reportJson . "\n";
if ( !$report['safety']['all_checked_layers_fresh'] ) {
	fwrite( STDERR, "WARNING: at least one cached semantic dependency was not refreshed. This is an observed risk, not a passed freshness guarantee.\n" );
}
echo "OK: disposable SMW direct mutations verified; parser/fragment freshness reported without modifying their policies\n";
