<?php

/** Live MediaWiki hook integration, guarded by a disposable laboratory marker. */
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab') ||
	!is_dir($root . '/state/preparation-queue')) {
	throw new RuntimeException('An explicitly marked disposable queue lab is required');
}

require __DIR__ . '/bootstrap.php';
define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', $root . '/mediawiki');
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

$queue = new CachePreparationQueue($root . '/state/preparation-queue', WikiMap::getCurrentWikiId());
$services = MediaWikiServices::getInstance();
$template = Title::newFromText('Template:Study dependency');
$argument = Title::newFromText('Cache study argument');
if (!$template || !$argument) { throw new RuntimeException('Missing lab titles'); }

$expected = static function ($title) use ($services): array {
	$page = $services->getWikiPageFactory()->newFromTitle($title);
	return ['pageId' => $page->getId(), 'revisionId' => $page->getLatest()];
};
$assert = static function ($value, string $message): void {
	if (!$value) { throw new RuntimeException($message); }
};
$templateId = $expected($template);
$argumentId = $expected($argument);
if ($templateId['pageId'] < 1 || $argumentId['pageId'] < 1) {
	throw new RuntimeException('Disposable wiki fixture has no article identities');
}

$mode = $argv[1] ?? '';
if ($mode === 'initial') {
	$initial = $queue->pending(1000);
	$assert(count($initial) >= 2, 'Fixture creation must enqueue direct article edits');
	$assert($queue->current($templateId['pageId'])['revisionId'] === $templateId['revisionId'],
		'Template PageSaveComplete queued the latest revision');
	$assert($queue->current($argumentId['pageId'])['revisionId'] === $argumentId['revisionId'],
		'Argument PageSaveComplete queued the latest revision');
	foreach ($initial as $record) {
		$assert($queue->acknowledge($record['pageId'], $record['revisionId']),
			'Fixture queue reset acknowledges only its exact revision');
	}
	$assert($queue->pending() === [], 'Queue is empty before indirect dependency jobs');
} elseif ($mode === 'after-template') {
	$pending = $queue->pending(1000);
	$assert(count($pending) === 1, 'Only a directly edited template enters the preparation queue');
	$assert($pending[0]['pageId'] === $templateId['pageId'] &&
		$pending[0]['revisionId'] === $templateId['revisionId'],
		'Dependency refresh does not enqueue the unchanged argument page');
	$assert($queue->current($argumentId['pageId']) === null,
		'The indirectly touched argument remains absent from the direct-edit queue');
} else {
	throw new InvalidArgumentException('Expected initial or after-template');
}

echo json_encode(['status' => 'PASS', 'mode' => $mode,
	'wiki' => WikiMap::getCurrentWikiId(),
	'templateRevision' => $templateId['revisionId'],
	'argumentRevision' => $argumentId['revisionId']], JSON_THROW_ON_ERROR) . "\n";
