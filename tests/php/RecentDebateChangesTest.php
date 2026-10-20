<?php

use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Extension\Wikidebates\RecentDebateChanges;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

require_once dirname( __DIR__, 4 ) . '/maintenance/Maintenance.php';

/** Integration smoke test for a disposable SQLite wiki, using minimal SMW table fixtures. */
class RecentDebateChangesTest extends Maintenance {
	public function execute() {
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		if ( $db->getType() !== 'sqlite' ||
			!str_ends_with( $services->getMainConfig()->get( 'DBname' ), '_performance_test' ) ||
			$db->tableExists( 'smw_object_ids', __METHOD__ )
		) {
			$this->fatalError( 'Use a disposable SQLite wiki named *_performance_test without SMW tables.' );
		}
		if ( !defined( 'SMW_NS_PROPERTY' ) ) {
			define( 'SMW_NS_PROPERTY', 102 );
		}
		foreach ( [
			'CREATE TABLE smw_object_ids (smw_id INTEGER PRIMARY KEY, smw_title TEXT, smw_namespace INTEGER)',
			'CREATE TABLE smw_di_wikipage (s_id INTEGER, p_id INTEGER, o_id INTEGER)',
			'CREATE TABLE smw_di_blob (s_id INTEGER, p_id INTEGER, o_blob TEXT, o_hash TEXT)',
		] as $sql ) {
			$db->query( $sql, __METHOD__ );
		}
		$db->newInsertQueryBuilder()->insertInto( 'smw_object_ids' )->row( [
			'smw_id' => 1, 'smw_title' => 'Performance_debate', 'smw_namespace' => 0,
		] )->caller( __METHOD__ )->execute();
		$siteNames = \MediaWiki\Extension\DebatePHP\MessageCatalog::siteNames( $services->getContentLanguage()->getCode() );
		foreach ( [ 2 => 'wikidebates-recent-changes-parent-property', 3 => 'wikidebates-recent-changes-breadcrumb-property' ] as $id => $key ) {
			$label = $siteNames[$key];
			$title = Title::newFromText( $label, SMW_NS_PROPERTY );
			$db->newInsertQueryBuilder()->insertInto( 'smw_object_ids' )->row( [
				'smw_id' => $id, 'smw_title' => $title->getDBkey(), 'smw_namespace' => SMW_NS_PROPERTY,
			] )->caller( __METHOD__ )->execute();
		}

		$authors = [ 'Wikirobot', 'ChatGPT', 'Human editor', 'Other bot', '192.0.2.1' ];
		foreach ( $authors as $index => $name ) {
			$user = $name === '192.0.2.1'
				? $services->getUserFactory()->newAnonymous( $name )
				: $services->getUserFactory()->newFromName( $name );
			if ( $name !== '192.0.2.1' && !$user->isRegistered() ) $user->addToDatabase();
			if ( $name === 'Other bot' ) $services->getUserGroupManager()->addUserToGroup( $user, 'bot' );
			$title = Title::newFromText( 'Performance argument ' . $index );
			$page = $services->getWikiPageFactory()->newFromTitle( $title );
			$updater = $page->newPageUpdater( $user );
			$updater->setContent( 'main', new WikitextContent( 'Performance fixture' ) );
			$revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Fixture' ) );
			$this->check( (bool)$revision, 'fixture revision saved' );
			$db->newInsertQueryBuilder()->insertInto( 'smw_object_ids' )->row( [
				'smw_id' => 10 + $index, 'smw_title' => $title->getDBkey(), 'smw_namespace' => 0,
			] )->caller( __METHOD__ )->execute();
			$db->newInsertQueryBuilder()->insertInto( 'smw_di_wikipage' )->row( [
				's_id' => 10 + $index, 'p_id' => 2, 'o_id' => 1,
			] )->caller( __METHOD__ )->execute();
		}

		$fetch = new ReflectionMethod( RecentDebateChanges::class, 'fetchRecentChanges' );
		$cache = $services->getMainWANObjectCache();
		$now = microtime( true );
		$cache->setMockTime( $now );
		$first = $fetch->invoke( null, 'Performance debate', 3 );
		$this->check( is_array( $first ) && count( $first ) === 3, 'SQL exclusion happens before LIMIT' );
		$names = array_column( $first, 'user_label' );
		$this->check( !in_array( 'Wikirobot', $names, true ) && !in_array( 'ChatGPT', $names, true ), 'both named accounts excluded' );
		$this->check( in_array( 'Other bot', $names, true ) && in_array( '192.0.2.1', $names, true ), 'other accounts and anonymous edits retained' );
		$this->output( "PASS SQL filtering and LIMIT\n" );

		$db->newDeleteQueryBuilder()->deleteFrom( 'smw_di_wikipage' )->where( [ 'o_id' => 1 ] )->caller( __METHOD__ )->execute();
		$this->check( $fetch->invoke( null, 'Performance debate', 3 ) === $first, 'warm cache reuses data' );
		$now += 61;
		$this->check( $fetch->invoke( null, 'Performance debate', 3 ) === [], 'data expires after 60 seconds' );
		$this->output( "PASS 60-second cache expiry (simulated clock)\n" );

		$missing = $fetch->invoke( null, 'Missing performance debate', 3 );
		$this->check( is_string( $missing ), 'missing semantic ID reports an error' );
		$db->newInsertQueryBuilder()->insertInto( 'smw_object_ids' )->row( [
			'smw_id' => 4, 'smw_title' => 'Missing_performance_debate', 'smw_namespace' => 0,
		] )->caller( __METHOD__ )->execute();
		$this->check( $fetch->invoke( null, 'Missing performance debate', 3 ) === [], 'errors are not cached' );
		$this->output( "PASS errors do not persist in cache\n" );

		$parser = $services->getParserFactory()->create();
		$output = $parser->parse(
			'{{#recentdebatechanges:page=Performance debate|limit=3}}',
			Title::newFromText( 'Performance parser test' ),
			ParserOptions::newFromAnon()
		);
		$this->check( $output->getCacheExpiry() === 0, 'parser cache cannot extend data cache lifetime' );
		$this->output( "PASS parser cache freshness\n" );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) $this->fatalError( 'FAIL: ' . $message );
	}
}

$maintClass = RecentDebateChangesTest::class;
require_once RUN_MAINTENANCE_IF_MAIN;
