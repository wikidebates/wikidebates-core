<?php

namespace MediaWiki\Extension\Wikidebates\Seo;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutputFlags;

/**
 * One indexed timestamp read per parsed page, shared by all native SEO
 * consumers. No cross-page lookup and no persistent cache beyond ParserCache.
 */
final class PublicationDate {
	public const DATA_KEY = 'wikidebates-publication-date';

	public static function forParser( Parser $parser ): ?string {
		$output = $parser->getOutput();
		$existing = $output->getExtensionData( self::DATA_KEY );
		if ( is_array( $existing ) && array_key_exists( 'timestamp', $existing ) ) {
			return $existing['timestamp'];
		}

		$timestamp = null;
		// Do not borrow the current revision's date for an unsaved preview.
		// A preparatory render must be revisited once its revision is stored.
		if ( $parser->getRevisionId() > 0 ) {
			$revision = $parser->getRevisionRecordObject();
			if ( $revision && $revision->getId() && $revision->getPageId() > 0 ) {
				$db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
				$first = $db->newSelectQueryBuilder()
					->select( 'rev_timestamp' )
					->from( 'revision' )
					->where( [ 'rev_page' => $revision->getPageId() ] )
					->orderBy( 'rev_timestamp' )
					->limit( 1 )
					->caller( __METHOD__ )
					->fetchField();
				// Reject missing/inconsistent history rather than substituting
				// the clock, the editorial parameter or a later revision.
				if ( is_string( $first ) && preg_match( '/^\d{14}$/D', $first ) &&
					$first <= $revision->getTimestamp() ) {
					$timestamp = wfTimestamp( TS_ISO_8601, $first );
				}
			}
		}
		if ( $timestamp === null ) {
			$output->setOutputFlag( ParserOutputFlags::VARY_REVISION );
		}
		// The array distinguishes an unsuccessful lookup from an absent one.
		$output->setExtensionData( self::DATA_KEY, [ 'timestamp' => $timestamp ] );
		return $timestamp;
	}
}
