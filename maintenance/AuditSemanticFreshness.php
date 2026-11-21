<?php

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

require_once ( getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 ) ) . '/maintenance/Maintenance.php';

/**
 * Read-only inventory. Never parses pages or alters revisions, jobs or caches.
 * Direct source markers are hints, not proof of executed semantic queries.
 */
class AuditSemanticFreshness extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Read-only audit of actual Argument/Debate pages, SMW _ASK profiles and query dependencies.' );
		$this->requireExtension( 'Wikidebates' );
		$this->requireExtension( 'SemanticMediaWiki' );
		$this->addOption( 'after-id', 'Resume strictly after this page ID (default 0)', false, true );
		$this->addOption( 'limit', 'Maximum pages (default 200, maximum 2000)', false, true );
		$this->addOption( 'batch-size', 'Rows per SQL select (default 100, maximum 200)', false, true );
		$this->addOption( 'namespaces', 'Comma-separated namespace numbers (default 0; use 0,10 to inspect templates separately)', false, true );
		$this->addOption( 'ids', 'Optional comma-separated page IDs to inspect', false, true );
		$this->addOption( 'summary-only', 'Show only progress and aggregate findings', false, false );
	}

	private static function numericList( string $value, bool $allowZero ): array {
		if ( !preg_match( '/^[0-9]+(?:,[0-9]+)*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Expected comma-separated numeric IDs.' );
		}
		$ids = array_values( array_unique( array_map( 'intval', explode( ',', $value ) ) ) );
		if ( !$allowZero && in_array( 0, $ids, true ) ) {
			throw new InvalidArgumentException( 'Page IDs must be positive.' );
		}
		return $ids;
	}

	/** Distinguish an actual main-namespace Argument from its reusable template source. */
	public static function sourceMarkers( int $namespace, string $source ): array {
		$argumentMarker = preg_match( '/\{\{\s*(?:Argument\s*[|}]|#argument\s*:\s*render\s*[|}]|#invoke\s*:\s*Argument\s*\|\s*render\s*[|}])/iu', $source ) === 1;
		$debateMarker = preg_match( '/\{\{\s*(?:D[e\x{00E9}]bat\s*[|}]|Debate\s*[|}]|#debate\s*:\s*render\s*[|}]|#invoke\s*:\s*Debate\s*\|\s*render\s*[|}])/iu', $source ) === 1;
		return [
			'argument' => $namespace === NS_MAIN && $argumentMarker,
			'debate' => $namespace === NS_MAIN && $debateMarker,
			'template_source' => $namespace === NS_TEMPLATE && ( $argumentMarker || $debateMarker ),
			'nocache' => stripos( $source, '__NOCACHE__' ) !== false,
		];
	}

	private function emit( array $record ): void {
		$this->output( json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
	}

	public function execute() {
		$after = (int)$this->getOption( 'after-id', 0 );
		$limit = (int)$this->getOption( 'limit', 200 );
		$batch = (int)$this->getOption( 'batch-size', 100 );
		if ( $after < 0 || $limit < 1 || $limit > 2000 || $batch < 1 || $batch > 200 ) {
			$this->fatalError( 'Invalid --after-id, --limit (1-2000), or --batch-size (1-200).' );
		}
		try {
			$namespaces = self::numericList( trim( (string)$this->getOption( 'namespaces', '0' ) ), true );
			$idValue = trim( (string)$this->getOption( 'ids', '' ) );
			$ids = $idValue === '' ? [] : self::numericList( $idValue, false );
		} catch ( InvalidArgumentException $e ) {
			$this->fatalError( $e->getMessage() );
		}
		$summaryOnly = (bool)$this->getOption( 'summary-only', false );
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$revisions = $services->getRevisionLookup();
		$store = SemanticServices::getInstance()->getStore();
		$links = ( new QueryDependencyLinksStoreFactory() )->newQueryDependencyLinksStore( $store );
		$summary = [
			'type' => 'summary', 'scanned' => 0, 'arguments' => 0, 'debates_only' => 0,
			'argument_without_ask_profile' => 0, 'profile_without_query_links' => 0,
			'with_ask_profiles' => 0, 'explicit_nocache' => 0, 'nontext' => 0,
			'template_source_markers' => 0,
			'missing' => 0, 'errors' => 0, 'resume_after' => $after,
			'dependency_links_enabled' => (bool)( $GLOBALS['smwgEnabledQueryDependencyLinksStore'] ?? false ),
			'query_profiler' => $GLOBALS['smwgQueryProfiler'] ?? null,
			'query_result_cache_type' => $GLOBALS['smwgQueryResultCacheType'] ?? null,
		];
		while ( $summary['scanned'] < $limit ) {
			$where = [ 'page_namespace' => $namespaces, $db->expr( 'page_id', '>', $after ) ];
			if ( $ids !== [] ) {
				$where['page_id'] = $ids;
			}
			$result = $db->newSelectQueryBuilder()
				->select( [ 'page_id', 'page_namespace', 'page_title' ] )
				->from( 'page' )->where( $where )->orderBy( 'page_id' )
				->limit( min( $batch, $limit - $summary['scanned'] ) )
				->caller( __METHOD__ )->fetchResultSet();
			$found = 0;
			foreach ( $result as $row ) {
				$found++;
				$after = (int)$row->page_id;
				$summary['scanned']++;
				$title = Title::makeTitle( (int)$row->page_namespace, (string)$row->page_title );
				try {
					$revision = $revisions->getRevisionByTitle( $title );
					$content = $revision?->getContent( SlotRecord::MAIN );
					if ( !$content ) {
						$summary['missing']++;
						continue;
					}
					if ( !$content instanceof TextContent ) {
						$summary['nontext']++;
						continue;
					}
					$source = $content->getText();
					$markers = self::sourceMarkers( $title->getNamespace(), $source );
					$argument = $markers['argument'];
					$debate = $markers['debate'];
					$nocache = $markers['nocache'];
					$subject = SemanticWikiPage::newFromTitle( $title );
					$profiles = $store->getPropertyValues( $subject, new Property( '_ASK' ) );
					$registered = $links->findEmbeddedQueryIdListBySubject( $subject );
					$hasProfiles = $profiles !== [];
					$hasLinks = $registered !== [];
					$summary['arguments'] += (int)$argument;
					$summary['template_source_markers'] += (int)$markers['template_source'];
					$summary['debates_only'] += (int)( $debate && !$argument );
					$summary['argument_without_ask_profile'] += (int)( $argument && !$hasProfiles );
					$summary['profile_without_query_links'] += (int)( $hasProfiles && !$hasLinks );
					$summary['with_ask_profiles'] += (int)$hasProfiles;
					$summary['explicit_nocache'] += (int)$nocache;
					if ( !$summaryOnly && ( $argument || $debate || $markers['template_source'] || $nocache || $hasProfiles || $hasLinks ) ) {
						$this->emit( [
							'type' => 'page', 'page_id' => $after,
							'title' => $title->getPrefixedText(),
							'argument_source_marker' => $argument, 'debate_source_marker' => $debate,
							'template_source_marker' => $markers['template_source'],
							'raw_nocache_marker' => $nocache, 'ask_profiles' => count( $profiles ),
							'query_links' => count( $registered ),
						] );
					}
				} catch ( Throwable $e ) {
					$summary['errors']++;
					if ( !$summaryOnly ) {
						$this->emit( [ 'type' => 'error', 'page_id' => $after,
							'exception_class' => get_class( $e ) ] );
					}
				}
			}
			$summary['resume_after'] = $after;
			if ( $found === 0 ) {
				break;
			}
			$store->clear();
		}
		$this->emit( $summary );
		if ( $summary['errors'] || $summary['missing'] ) {
			$this->fatalError( 'Audit incomplete; inspect errors and retry without --summary-only.' );
		}
	}
}

$maintClass = AuditSemanticFreshness::class;
require_once RUN_MAINTENANCE_IF_MAIN;
