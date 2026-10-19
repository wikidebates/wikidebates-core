<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

function fsCanonical($value) {
	if (!is_array($value)) { return $value; }
	$value = array_map('fsCanonical', $value);
	if (!array_is_list($value)) { ksort($value); }
	return $value;
}

function fsFlush(): array {
	$services = MediaWikiServices::getInstance();
	$counts = [];
	for ($round = 0; $round < 20; $round++) {
		DeferredUpdates::doUpdates();
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
		$group = $services->getJobQueueGroup();
		$executed = 0;
		foreach ($group->getQueueTypes() as $type) {
			while ($job = $group->pop($type)) {
				check(++$executed <= 500, 'Isolated job queue converges');
				check($job->run() !== false, 'Job succeeded: ' . $job->getType() . ' ' . $job->getLastError());
				$group->ack($job);
				$counts[$job->getType()] = ($counts[$job->getType()] ?? 0) + 1;
				DeferredUpdates::doUpdates();
				$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
			}
		}
		if (!$executed && DeferredUpdates::pendingUpdatesCount() === 0) {
			check(!$group->queuesHaveJobs(), 'No unprocessed job types remain');
			return $counts;
		}
	}
	throw new RuntimeException('Deferred updates did not converge');
}

function fsSave(string $name, string $text, ?string $model = null): void {
	global $fsUser;
	$services = MediaWikiServices::getInstance();
	$title = Title::newFromText($name);
	check($title !== null, 'Valid synthetic title: ' . $name);
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	$updater = $page->newPageUpdater($fsUser);
	$updater->setContent(SlotRecord::MAIN, ContentHandler::makeContent($text, $title, $model));
	$updater->saveRevision(CommentStoreComment::newUnsavedComment('Isolated Wikidebates integration test'), EDIT_SUPPRESS_RC);
	check($updater->wasSuccessful(), 'Synthetic page saved: ' . $name . ' ' . $updater->getStatus()->getWikiText());
}

function fsValues(string $name, string $property): array {
	$store = \SMW\Services\ServicesFactory::getInstance()->getStore();
	$store->clear();
	$subject = \SMW\DIWikiPage::newFromTitle(Title::newFromText($name));
	$values = array_map(static fn($item): array => [$item->getDIType(), $item->getSerialization()], $store->getPropertyValues($subject, new \SMW\DIProperty(str_replace(' ', '_', $property))));
	sort($values);
	return $values;
}

function fsSnapshot(\SMW\SemanticData $data, bool $includeInternal = false): array {
	$properties = [];
	foreach ($data->getProperties() as $property) {
		$key = $property->getKey();
		if (!$includeInternal && str_starts_with($key, '_')) { continue; }
		$values = array_map(static fn($item): array => [$item->getDIType(), $item->getSerialization()], $data->getPropertyValues($property));
		sort($values);
		$properties[$key] = $values;
	}
	ksort($properties);
	$subobjects = [];
	foreach ($data->getSubSemanticData() as $child) {
		$subobjects[$child->getSubject()->getSubobjectName()] = fsSnapshot($child, $includeInternal);
	}
	ksort($subobjects);
	return ['properties' => $properties, 'subobjects' => $subobjects];
}

function fsStored(string $title): array {
	$store = \SMW\Services\ServicesFactory::getInstance()->getStore();
	$store->clear();
	return fsSnapshot($store->getSemanticData(\SMW\DIWikiPage::newFromTitle(Title::newFromText($title))));
}

function fsPayload(string $variant): array {
	return match ($variant) {
		'initial' => ['WD FS Text' => ['first', 'A|B=C & é', '0'], 'WD FS Number' => 42, 'WD FS Date' => '2026-01-01', 'WD FS Page' => 'WD FS target'],
		'updated' => ['WD FS Text' => 'updated', 'WD FS Number' => 0, 'WD FS Date' => '2026-02-02', 'WD FS Page' => 'WD FS replacement'],
		default => []
	};
}
