<?php

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\SemanticTouchRecovery;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Manual, single-page recovery of a stuck semantic cache preparation barrier.
 *
 * Preview is the default. A recovery does not render HTML and never deletes
 * queue records, runs SMW jobs or schedules recurring background work.
 */
class RecoverSemanticTouch extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Preview or explicitly retry a blocked semantic cache touch barrier.');
		$this->requireExtension('Wikidebates');
		$this->addOption('execute', 'Explicitly replay the purge and deferred touch for one page ID.');
		$this->addOption('page-id', 'Specific page ID (required with --execute).', false, true);
		$this->addOption('limit', 'Maximum preview rows (1-100, default 20).', false, true);
		$this->addOption('min-age-seconds', 'Minimum age before recovery (60-86400, default 600).', false, true);
		$this->addOption('json', 'Emit a single machine-readable report.');
	}

	private function bounded(string $name, int $default, int $min, int $max): int {
		$raw = (string)$this->getOption($name, (string)$default);
		if ($raw === '' || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
			$this->fatalError('Invalid --' . $name . ' (expected ' . $min . '..' . $max . ').');
		}
		return (int)$raw;
	}

	public function execute() {
		$limit = $this->bounded('limit', 20, 1, 100);
		$minAge = $this->bounded('min-age-seconds', 600, 60, 86400);
		$run = $this->hasOption('execute');
		$pageId = null;
		if ($this->hasOption('page-id')) {
			$raw = (string)$this->getOption('page-id');
			if ($raw === '' || !ctype_digit($raw) || (int)$raw < 1 ||
				(string)(int)$raw !== $raw) {
				$this->fatalError('Invalid --page-id (expected a positive page ID).');
			}
			$pageId = (int)$raw;
		}
		if ($run && $pageId === null) {
			$this->fatalError('Recovery requires an explicit --page-id; batch execution is forbidden.');
		}

		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$directory = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') {
			$this->fatalError('Private preparation journal is disabled; no recovery was attempted.');
		}
		$wiki = WikiMap::getCurrentWikiId();
		try {
			$queue = new CachePreparationQueue($directory, $wiki);
			$records = $pageId !== null ? array_filter([$queue->current($pageId)]) : $queue->pending($limit);
		} catch (\Throwable $e) {
			$this->fatalError('Cannot read the private journal (' . get_class($e) . ').');
		}
		$report = [
			'type' => 'wikidebates-semantic-touch-recovery', 'wiki' => $wiki,
			'mode' => $run ? 'execute' : 'preview', 'page_id' => $pageId,
			'minimum_age_seconds' => $minAge, 'sampled' => count($records),
			'recovered' => 0, 'pages' => []
		];

		if (!$run) {
			foreach ($records as $record) {
				$blocked = ($record['schema'] ?? null) === 3 && ($record['ready'] ?? null) === false;
				$report['pages'][] = [
					'pageId' => $record['pageId'],
					'revisionId' => $record['revisionId'],
					'state' => !$blocked ? 'not-blocked' :
						($record['queuedAt'] <= time() - $minAge ? 'eligible-for-manual-retry' : 'too-recent')
				];
			}
			$report['note'] = 'Read-only: no SQL, HTML purge, queue replacement or job execution.';
		} else {
			if (!$config->get('WikidebatesSemanticCacheTracking') ||
				!$config->get('WikidebatesSemanticCacheEagerPurge')) {
				$this->fatalError('Semantic cache tracking and eager purge must both be enabled.');
			}
			// Never race a known waiting semantic purge batch: finish its normal
			// jobs before attempting to reconcile an abandoned generation.
			if ($services->getJobQueueGroup()->get('wikidebatesSemanticCachePurge')->getSize() > 0) {
				$this->fatalError('Pending semantic purge jobs must drain before manual recovery.');
			}
			$lock = $queue->tryLockWorker();
			if (!$lock) {
				$this->fatalError('Another preparation or recovery worker owns the wiki lock.');
			}
			try {
				$record = $queue->current($pageId);
				$result = $record ?
					(new SemanticTouchRecovery($queue))->recover($record, $minAge) :
					['pageId' => $pageId, 'state' => 'not-found'];
				$report['pages'][] = $result;
				$report['recovered'] = (int)($result['state'] === 'recovered');
			} finally {
				fclose($lock);
			}
		}
		$this->output(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
		return !$run || $report['recovered'] === 1;
	}
}

$maintClass = RecoverSemanticTouch::class;
require_once RUN_MAINTENANCE_IF_MAIN;
