<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\Wikidebates\RecentDebateChanges;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$privacyTitle = Title::newFromText( 'Wikidebates privacy fixture' );
$privacyUser = User::newSystemUser( 'Wikidebates privacy fixture', [ 'steal' => false ] );
check( $privacyUser !== null, 'Disposable privacy test contributor created' );
$privacyPage = $services->getWikiPageFactory()->newFromTitle( $privacyTitle );
$privacyUpdater = $privacyPage->newPageUpdater( $privacyUser );
$privacyUpdater->setContent( SlotRecord::MAIN, new WikitextContent( 'Disposable privacy fixture' ) );
$privacyUpdater->saveRevision( CommentStoreComment::newUnsavedComment( 'Public fixture summary' ) );
check( $privacyUpdater->wasSuccessful(), 'Disposable revision created for privacy test' );
$privacyId = $privacyUpdater->getNewRevision()->getId();
$source = [ [
	'revision_id' => $privacyId,
	'title' => $privacyTitle->getPrefixedText(),
	'user_page' => 'User:Wikidebates privacy fixture',
	'user_label' => 'Wikidebates privacy fixture',
	'date_text' => 'date',
	'summary' => 'Public fixture summary',
	'argument_concerne' => ''
] ];
$visibility = new ReflectionMethod( RecentDebateChanges::class, 'applyPublicRevisionVisibility' );
$public = $visibility->invoke( null, $source );
same( 'Public fixture summary', $public[0]['summary'], 'Public revision summary is shown' );
same( $source[0]['user_page'], $public[0]['user_page'], 'Public revision author is linked' );

$dbw = $services->getConnectionProvider()->getPrimaryDatabase();
$setDeleted = static function ( int $flags ) use ( $dbw, $privacyId ): void {
	$dbw->newUpdateQueryBuilder()
		->update( 'revision' )
		->set( [ 'rev_deleted' => $flags ] )
		->where( [ 'rev_id' => $privacyId ] )
		->caller( __METHOD__ )
		->execute();
};
$setDeleted( RevisionRecord::DELETED_USER );
$hiddenUser = $visibility->invoke( null, $source );
same( '', $hiddenUser[0]['user_page'], 'Hidden author has no user-page link' );
check( $hiddenUser[0]['user_label'] !== $source[0]['user_label'], 'Hidden author name is not displayed' );
same( 'Public fixture summary', $hiddenUser[0]['summary'], 'Still-public summary remains visible' );

$setDeleted( RevisionRecord::DELETED_COMMENT );
$hiddenComment = $visibility->invoke( null, $source );
same( $source[0]['user_page'], $hiddenComment[0]['user_page'], 'Still-public author remains visible' );
same( '', $hiddenComment[0]['summary'], 'Hidden summary is stripped' );

$setDeleted( RevisionRecord::DELETED_USER | RevisionRecord::DELETED_COMMENT | RevisionRecord::DELETED_RESTRICTED );
$suppressed = $visibility->invoke( null, $source );
same( '', $suppressed[0]['user_page'], 'Suppressed author is stripped from cached data' );
same( '', $suppressed[0]['summary'], 'Suppressed summary is stripped from cached data' );

$absent = $source;
$absent[0]['revision_id'] = PHP_INT_MAX;
$failClosed = $visibility->invoke( null, $absent );
same( '', $failClosed[0]['user_page'], 'Missing revision author fails closed' );
same( '', $failClosed[0]['summary'], 'Missing revision comment fails closed' );

$setDeleted( 0 );
$restored = $visibility->invoke( null, $source );
same( $source[0]['user_page'], $restored[0]['user_page'], 'Public author recovers after unsuppression' );
same( 'Public fixture summary', $restored[0]['summary'], 'Public summary recovers after unsuppression' );
echo "OK: current public RevisionDelete visibility masks cached authors and summaries\n";
