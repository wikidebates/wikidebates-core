<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

// Exercise the real PageSaveComplete integration on a disposable MediaWiki DB.
$parentUser = User::newSystemUser( 'Wikidebates parent fixture', [ 'steal' => false ] );
check( $parentUser !== null, 'Disposable parent-edit test user created' );
$parentTitle = Title::newFromText( 'Wikidebates parent fixture' );
$parentPage = $services->getWikiPageFactory()->newFromTitle( $parentTitle );
$updater = $parentPage->newPageUpdater( $parentUser );
$updater->setContent( SlotRecord::MAIN, new WikitextContent( '{{Debate|topic=Fixture}}' ) );
$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Create fixture parent' ) );
check( $updater->wasSuccessful(), 'Parent fixture created' );
$parentId = $parentTitle->getArticleID();
check( $parentId > 0, 'Parent ID available for child initialization' );
foreach ( [ 'First', 'Second' ] as $label ) {
	$childTitle = Title::newFromText( 'Wikidebates ' . $label . ' fixture child' );
	$childPage = $services->getWikiPageFactory()->newFromTitle( $childTitle );
	$child = $childPage->newPageUpdater( $parentUser );
	$child->setContent( SlotRecord::MAIN, new WikitextContent(
		'{{Argument|initialization=Pro argument@' . $parentId . '|name=' . $label . '}}'
	) );
	$child->saveRevision( CommentStoreComment::newUnsavedComment( 'Create fixture child' ) );
	check( $child->wasSuccessful(), 'Child fixture created: ' . $label );
}
$parentPage = $services->getWikiPageFactory()->newFromTitle( $parentTitle );
$parentText = $parentPage->getContent( SlotRecord::MAIN )->getText();
check( str_contains( $parentText, 'Wikidebates First fixture child' ), 'First child linked automatically' );
check( str_contains( $parentText, 'Wikidebates Second fixture child' ), 'Second child linked automatically' );

// Reproduce a stale edit without affecting a production page. The CAS guard
// must reject a write based on an older revision rather than lose new content.
$stale = $parentPage->newPageUpdater( $parentUser );
$base = $stale->grabParentRevision();
check( $base !== null, 'Parent revision is captured before transforming content' );
$other = $parentPage->newPageUpdater( $parentUser );
$other->grabParentRevision();
$other->setContent( SlotRecord::MAIN, new WikitextContent(
	$base->getContent( SlotRecord::MAIN )->getText() . "\n<!-- New concurrent edit -->"
) );
$other->saveRevision( CommentStoreComment::newUnsavedComment( 'Concurrent parent edit' ) );
check( $other->wasSuccessful(), 'Concurrent parent edit succeeded' );
$stale->setContent( SlotRecord::MAIN, new WikitextContent(
	$base->getContent( SlotRecord::MAIN )->getText() . "\n<!-- Stale write -->"
) );
$stale->saveRevision( CommentStoreComment::newUnsavedComment( 'Stale edit must fail' ) );
check( !$stale->wasSuccessful() && $stale->getStatus()->failedBecauseOfConflict(),
	'PageUpdater rejects the stale parent edit without overwriting content' );
$latestParent = $services->getWikiPageFactory()->newFromTitle( $parentTitle )->getContent( SlotRecord::MAIN )->getText();
check( str_contains( $latestParent, '<!-- New concurrent edit -->' ) && !str_contains( $latestParent, '<!-- Stale write -->' ),
	'Latest parent text preserves the winning concurrent modification' );
echo "OK: automatic parent links and PageUpdater conflict detection\n";
