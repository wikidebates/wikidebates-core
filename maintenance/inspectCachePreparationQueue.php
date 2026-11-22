<?php

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * A read-only view of pages awaiting coordinated anonymous cache preparation.
 * This command does not generate HTML, acknowledge records or start a job.
 */
class InspectCachePreparationQueue extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Inspect directly modified pages pending anonymous cache preparation.');
		$this->requireExtension('Wikidebates');
		$this->addOption('limit', 'Maximum pending entries to display (1-10000, default 100)', false, true);
		$this->addOption('json', 'Emit a machine-readable JSON report');
	}

	public function execute() {
		$limit = (int)$this->getOption('limit', 100);
		if ($limit < 1 || $limit > 10000) {
			$this->fatalError('Invalid preparation queue limit; expected 1..10000.');
		}
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$directory = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') {
			$this->fatalError('The preparation journal is disabled; no queue was opened.');
		}
		try {
			$wiki = WikiMap::getCurrentWikiId();
			$queue = new CachePreparationQueue($directory, $wiki);
			$pending = $queue->pending($limit);
		} catch (\Throwable $e) {
			$this->fatalError('The preparation queue is unavailable (' . get_class($e) . ').');
		}
		$report = [
			'wiki' => $wiki,
			'sampled' => count($pending),
			'limit' => $limit,
			'truncated' => count($pending) === $limit,
			'pending' => $pending,
			'note' => 'Read-only snapshot: no HTML was generated and no item was acknowledged.'
		];
		if ($this->hasOption('json')) {
			$this->output(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
			return;
		}
		$this->output('Wiki: ' . $wiki . '; sampled pending pages: ' . count($pending) . "\n");
		foreach ($pending as $item) {
			$this->output($item['pageId'] . "\t" . $item['revisionId'] . "\t" .
				gmdate(DATE_ATOM, $item['queuedAt']) . "\n");
		}
	}
}

$maintClass = InspectCachePreparationQueue::class;
require_once RUN_MAINTENANCE_IF_MAIN;
