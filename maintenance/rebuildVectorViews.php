<?php

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillJournal;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Opt-in, sequential, resumable migration of pre-existing native Vector view
 * HTML. Does not touch history, Minerva, Reading, edit-queue records, or the
 * nightly scan checkpoint. Every renderer is isolated and revision guarded.
 */
class RebuildVectorViews extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension('Wikidebates');
		$this->addDescription('Rebuild only pre-existing native Vector view files in bounded resumable batches.');
		$this->addOption('execute', 'Permit bounded rebuild and own checkpoint writes (default: preview).');
		$this->addOption('status', 'Read the independent Vector checkpoint without writing files.');
		$this->addOption('new-cycle', 'Start another pass only after a previous Vector cycle is complete.');
		$this->addOption('limit', 'Maximum pages in this call (default 20, max 100).', false, true);
		$this->addOption('max-seconds', 'Wall-clock budget (default 480, maximum 1200).', false, true);
		$this->addOption('delay-ms', 'Delay between rebuilt views (default 1000, max 60000).', false, true);
		$this->addOption('max-load', 'Maximum 1-minute load (default 6; fail closed).', false, true);
		$this->addOption('json', 'Machine-readable JSON report (always enabled).');
	}

	private function bounded(string $option, int $default, int $min, int $max): int {
		$raw = (string)$this->getOption($option, (string)$default);
		if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) ||
			strlen($raw) > 8 || (int)$raw < $min || (int)$raw > $max) {
			$this->fatalError('Invalid --' . $option . ' (expected ' . $min . '-' . $max . ').');
		}
		return (int)$raw;
	}

	private function report(array $data): void {
		$this->output(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
	}

	public function execute() {
		$execute = $this->hasOption('execute');
		$status = $this->hasOption('status');
		$newCycle = $this->hasOption('new-cycle');
		if (($status && ($execute || $newCycle)) || ($newCycle && !$execute)) {
			$this->fatalError('--status is read-only; --new-cycle requires --execute.');
		}
		$limit = $this->bounded('limit', 20, 1, 100);
		$budget = $this->bounded('max-seconds', 480, 30, 1200);
		$delay = $this->bounded('delay-ms', 1000, 0, 60000);
		$loadRaw = (string)$this->getOption('max-load', '6');
		if (!preg_match('/\A[1-9][0-9]{0,2}(?:\.[0-9]{1,2})?\z/D', $loadRaw) ||
			(float)$loadRaw > 256) {
			$this->fatalError('Invalid --max-load (must be a positive threshold <= 256).');
		}
		$maxLoad = (float)$loadRaw;
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		if (!$config->get(MainConfigNames::UseFileCache) ||
			!in_array((string)$config->get('DefaultSkin'), ['vector', 'vector-2022'], true)) {
			$this->fatalError('Native Vector file caching must be configured.');
		}
		$root = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($root) || $root === '') {
			$this->fatalError('Private cache preparation directory is not configured.');
		}
		$wiki = WikiMap::getCurrentWikiId();
		$queue = new CachePreparationQueue($root, $wiki);
		$checkpoint = $queue->vectorRebuildCheckpoint();
		$report = [
			'type' => 'wikidebates-vector-view-rebuild',
			'wiki' => $wiki, 'mode' => $status ? 'status' : ($execute ? 'execute' : 'preview'),
			'checkpoint' => $checkpoint, 'pages' => [],
			'limit' => $limit, 'rebuilt' => 0, 'skipped' => 0,
			'stop_reason' => 'preview'
		];
		if ($status) {
			$report['stop_reason'] = $checkpoint === null ? 'not-started' : $checkpoint['state'];
			$this->report($report);
			return true;
		}
		$primary = $services->getConnectionProvider()->getPrimaryDatabase();
		$high = static function () use ($primary): int {
			return (int)$primary->newSelectQueryBuilder()
				->select('MAX(page_id)')->from('page')
				->caller(__METHOD__)->fetchField();
		};
		$select = static function (int $after, int $last, int $count) use ($primary): array {
			$res = $primary->newSelectQueryBuilder()
				->select(['page_id'])->from('page')
				->where(['page_is_redirect' => 0,
					$primary->expr('page_id', '>', $after),
					$primary->expr('page_id', '<=', $last)])
				->orderBy('page_id')->limit($count)
				->caller(__METHOD__)->fetchResultSet();
			$ids = [];
			foreach ($res as $row) { $ids[] = (int)$row->page_id; }
			return $ids;
		};
		if (!$execute) {
			if ($newCycle) { $this->fatalError('--new-cycle requires --execute.'); }
			$after = $checkpoint['after'] ?? 0;
			$last = $checkpoint['high_watermark'] ?? $high();
			$report['pages'] = array_map(
				static fn(int $id): array => ['pageId' => $id, 'state' => 'would-check-existing-view'],
				$last > 0 ? $select($after, $last, $limit) : []
			);
			$report['high_watermark'] = $last;
			$report['start_after'] = $after;
			$this->report($report);
			return true;
		}

		// Only one wiki worker: shared with parallel backfill and hourly/nightly
		// three-cache cron. This lock is released after a bounded invocation.
		$lock = $queue->tryLockWorker();
		if (!$lock) {
			$report['stop_reason'] = 'worker-busy';
			$this->report($report);
			return true;
		}
		$started = microtime(true);
		$overloaded = static function () use ($maxLoad): bool {
			$avg = sys_getloadavg();
			return !is_array($avg) || !isset($avg[0]) || $avg[0] > $maxLoad;
		};
		try {
			$parallel = (new ParallelBackfillJournal($queue, $wiki))->snapshot();
			if ($parallel !== null && $parallel['state'] !== 'complete') {
				$report['stop_reason'] = 'unfinished-parallel-backfill';
				$this->report($report);
				return true;
			}
			if ($checkpoint === null || $newCycle) {
				if ($newCycle && ($checkpoint === null || $checkpoint['state'] !== 'complete')) {
					$this->fatalError('A new Vector cycle is allowed only after the previous one completes.');
				}
				if ($queue->pending(1, true) !== []) {
					$report['stop_reason'] = 'pending-edits';
					$this->report($report);
					return true;
				}
				$last = $high();
				if ($last < 1) {
					$report['stop_reason'] = 'no-pages';
					$this->report($report);
					return true;
				}
				$checkpoint = [
					'version' => 1, 'high_watermark' => $last, 'after' => 0,
					'created_at' => time(), 'state' => 'running'
				];
				$queue->saveVectorRebuildCheckpoint($checkpoint);
			}
			$report['start_after'] = $checkpoint['after'];
			if ($checkpoint['state'] === 'complete') {
				$report['checkpoint'] = $checkpoint;
				$report['stop_reason'] = 'cycle-complete';
				$this->report($report);
				return true;
			}
			$runner = new CachePreparationCommands(MW_INSTALL_PATH, $wiki, 6000, 100, ['android']);
			$reason = 'limit';
			while (count($report['pages']) < $limit) {
				if ($overloaded()) { $reason = 'overload'; break; }
				if (microtime(true) - $started >= $budget) { $reason = 'time-budget'; break; }
				if ($queue->pending(1, true) !== []) { $reason = 'pending-edits'; break; }
				$ids = $select($checkpoint['after'], $checkpoint['high_watermark'],
					min(10, $limit - count($report['pages'])));
				if ($ids === []) {
					$checkpoint['after'] = $checkpoint['high_watermark'];
					$checkpoint['state'] = 'complete';
					$queue->saveVectorRebuildCheckpoint($checkpoint);
					$reason = 'cycle-complete';
					break;
				}
				foreach ($ids as $id) {
					if ($overloaded()) { $reason = 'overload'; break 2; }
					if (microtime(true) - $started >= $budget) { $reason = 'time-budget'; break 2; }
					if ($queue->pending(1, true) !== []) { $reason = 'pending-edits'; break 2; }
					$pageLock = $queue->lockBuildPage($id);
					try {
						// A new pending edit may have appeared after checking
						// the queue. Never compete with its urgent preparation.
						if ($queue->current($id) !== null) {
							$reason = 'pending-edit-for-page';
							break 2;
						}
						$result = $runner->prepare('vector', $id, true);
					} finally {
						fclose($pageLock);
					}
					if (!$result['ok']) {
						$report['pages'][] = ['pageId' => $id,
							'state' => 'retry', 'reason' => $result['status']];
						$reason = 'page-failed';
						break 2;
					}
					$state = $result['status'];
					$report['pages'][] = ['pageId' => $id, 'state' => $state];
					if ($state === 'CREATED') { $report['rebuilt']++; }
					else { $report['skipped']++; }
					$checkpoint['after'] = $id;
					$queue->saveVectorRebuildCheckpoint($checkpoint);
					if ($state === 'CREATED' && $delay > 0) {
						usleep($delay * 1000);
					}
				}
			}
			$report['checkpoint'] = $checkpoint;
			$report['stop_reason'] = $reason;
			$report['duration_seconds'] = round(microtime(true) - $started, 3);
			$this->report($report);
			return true;
		} finally {
			fclose($lock);
		}
	}
}

$maintClass = RebuildVectorViews::class;
require_once RUN_MAINTENANCE_IF_MAIN;
