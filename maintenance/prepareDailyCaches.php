<?php

use MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\WikiMap\WikiMap;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * One bounded daily worker for the direct-edit queue and historical pages.
 * Preview is read-only. Execution shares the queue's per-wiki worker lock and
 * persists one cursor only after a page has completed its safe cache phases.
 */
class PrepareDailyCaches extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Preview or run a bounded, resumable anonymous cache maintenance cycle.');
		$this->requireExtension('Wikidebates');
		$this->addOption('execute', 'Allow cache creation, queue acknowledgement and cursor writes.');
		$this->addOption('queue-only', 'Process direct edits only, without scanning historical pages or moving the cursor.');
		$this->addOption('limit', 'Maximum pages per invocation (default 20; maximum 100).', false, true);
		$this->addOption('queue-limit', 'Maximum direct edits to prioritize (default 10).', false, true);
		$this->addOption('batch-size', 'Maximum pages fetched per scan query (default 25).', false, true);
		$this->addOption('max-seconds', 'Wall-clock budget (default 1200; 480-14400).', false, true);
		$this->addOption('delay-ms', 'Pacing after actual cache work (default 1000).', false, true);
		$this->addOption('minerva-delay-ms', 'Minerva HTTP pacing (default 6000).', false, true);
		$this->addOption('minerva-devices', 'Mobile variants: android (default) or android,iphone.', false, true);
		$this->addOption('max-load', 'Optional absolute 1-minute load average ceiling; 0 disables.', false, true);
		$this->addOption('json', 'Produce one machine-readable report (always enabled).');
	}

	private function bounded(string $name, int $default, int $min, int $max): int {
		$value = (string)$this->getOption($name, $default);
		if ($value === '' || !ctype_digit($value) || (int)$value < $min || (int)$value > $max) {
			$this->fatalError('Invalid --' . $name . ': expected ' . $min . '..' . $max);
		}
		return (int)$value;
	}

	/** @return bool */
	public function execute() {
		$limit = $this->bounded('limit', 20, 1, 100);
		$queueLimit = min($limit, $this->bounded('queue-limit', 10, 0, 100));
		$batchSize = $this->bounded('batch-size', 25, 1, 100);
		$budget = $this->bounded('max-seconds', 1200, 480, 14400);
		$delay = $this->bounded('delay-ms', 1000, 0, 60000);
		$minervaDelay = $this->bounded('minerva-delay-ms', 6000, 250, 60000);

		$devicesRaw = (string)$this->getOption('minerva-devices', 'android');
		$devices = explode(',', $devicesRaw);
		if (!in_array($devices, [['android'], ['iphone'], ['android', 'iphone']], true)) {
			$this->fatalError('Invalid --minerva-devices; use android, iphone or android,iphone.');
		}
		$loadRaw = (string)$this->getOption('max-load', '0');
		if (!preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?\z/D', $loadRaw) ||
			(float)$loadRaw > 256) {
			$this->fatalError('Invalid --max-load; use 0 or a positive load threshold <= 256.');
		}
		$maxLoad = (float)$loadRaw;
		$execute = $this->hasOption('execute');
		$queueOnly = $this->hasOption('queue-only');
		if ($queueOnly && $queueLimit === 0) {
			$this->fatalError('--queue-only requires a positive --queue-limit.');
		}

		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$directory = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') {
			$this->fatalError('The private direct-edit cache queue is not configured.');
		}
		$wiki = WikiMap::getCurrentWikiId();
		$queue = new CachePreparationQueue($directory, $wiki);
		if ($execute && (!$config->get('WikidebatesReadingFragments') ||
			!$config->get('WikidebatesReadingPrewarm') ||
			!$config->get('MinervaFileCacheEnabled') ||
			!$config->get('MinervaFileCacheDebugHeaders') ||
			!$config->get('UseFileCache'))) {
			$this->fatalError('All three cache preparation services must be enabled.');
		}

		$lock = null;
		$started = microtime(true);
		$overloaded = static function () use ($maxLoad): bool {
			if ($maxLoad === 0.0) { return false; }
			$load = sys_getloadavg();
			// If an explicitly configured overload guard cannot be measured,
			// do not launch expensive cache renders without its protection.
			return !is_array($load) || !isset($load[0]) || $load[0] > $maxLoad;
		};
		$report = [
			'type' => 'wikidebates-daily-cache-preparation',
			'wiki' => $wiki, 'mode' => $execute ? 'execute' : 'preview',
			'limit' => $limit, 'queue_processed' => 0, 'queue_acknowledged' => 0,
			'scan_processed' => 0, 'scan_skipped_queue' => 0,
			'stop_reason' => 'complete', 'pages' => []
		];
		try {
			if ($execute) {
				$lock = $queue->tryLockWorker();
				if (!$lock) {
					$this->fatalError('Another preparation worker owns the wiki lock.');
				}
			}
			$cursor = $queue->dailyCursor();
			$report['start_after'] = $cursor;
			$processedIds = [];
			$pending = $queueLimit > 0 ? $queue->pending($queueLimit, true) : [];
			$primary = $services->getConnectionProvider()->getPrimaryDatabase();
			$latest = static function (int $id) use ($primary): ?int {
				$row = $primary->selectRow('page', ['page_latest'], ['page_id' => $id], __METHOD__);
				return $row ? (int)$row->page_latest : null;
			};
			$identity = static fn(int $id): ?string => AnonymousCacheSafety::fingerprint($id);
			$coordinator = new CachePreparationCoordinator($queue);
			$commands = null;
			if ($execute) {
				$root = defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : dirname(__DIR__, 3);
				$commands = new CachePreparationCommands($root, $wiki, $minervaDelay, 100, $devices);
			}
			$prepare = static fn(string $phase, int $id): array => $commands->prepare($phase, $id);
			$remaining = static function () use ($started, $budget, $devices): bool {
				// Vector, Reading and each selected mobile variant have bounded children.
				return microtime(true) - $started < $budget - (230 + 100 * count($devices));
			};
			$needsPacing = static function (array $result): bool {
				foreach ($result['phases'] ?? [] as $status) {
					// An explicit policy BYPASS still made an HTTP request. Space
					// such requests just like MISS/HIT, without pacing LOCAL_HIT.
					if (preg_match('/(?:\A|[=;])(CREATED|MISS|HIT|created|BYPASS-[a-z0-9-]+)(?:;|\z)/D', $status)) {
						return true;
					}
				}
				return false;
			};

			foreach ($pending as $record) {
				if (count($report['pages']) >= $limit) { break; }
				if ($execute && $overloaded()) {
					$report['stop_reason'] = 'system-load';
					break;
				}
				if ($execute && !$remaining()) {
					$report['stop_reason'] = 'time-budget';
					break;
				}
				$id = $record['pageId'];
				$processedIds[$id] = true;
				$result = $execute ? $coordinator->process($record, $latest, $prepare, $identity) :
					['pageId' => $id, 'revisionId' => $record['revisionId'], 'state' => 'preview-queue'];
				$report['pages'][] = $result;
				$report['queue_processed']++;
				if ($result['state'] === 'acknowledged') { $report['queue_acknowledged']++; }
				if ($result['state'] === 'retry') {
					$report['stop_reason'] = 'retry-required';
					break;
				}
				if ($execute && $delay > 0 && $needsPacing($result)) { usleep($delay * 1000); }
			}

			if ($report['stop_reason'] === 'complete' && !$queueOnly) {
				$replica = $services->getConnectionProvider()->getReplicaDatabase();
				while (count($report['pages']) < $limit) {
					if ($execute && $overloaded()) {
						$report['stop_reason'] = 'system-load';
						break;
					}
					if ($execute && !$remaining()) {
						$report['stop_reason'] = 'time-budget';
						break;
					}
					$rows = $replica->newSelectQueryBuilder()
						->select(['page_id', 'page_latest'])
						->from('page')
						->where(['page_is_redirect' => 0, $replica->expr('page_id', '>', $cursor)])
						->orderBy('page_id')
						->limit(min($batchSize, $limit - count($report['pages'])))
						->caller(__METHOD__)->fetchResultSet();
					$found = 0;
					foreach ($rows as $row) {
						$found++;
						if ($execute && $overloaded()) {
							$report['stop_reason'] = 'system-load';
							break 2;
						}
						$id = (int)$row->page_id;
						if ($execute && !$remaining()) {
							$report['stop_reason'] = 'time-budget';
							break 2;
						}
						if (isset($processedIds[$id])) {
							$result = ['pageId' => $id, 'state' => 'already-processed-from-queue'];
							$report['scan_skipped_queue']++;
						} elseif ($execute) {
							$result = $coordinator->processUnqueued(
								$id, (int)$row->page_latest, $latest, $prepare, $identity
							);
							if (!in_array($result['state'], ['complete', 'skipped-nonpublic'], true)) {
								$report['stop_reason'] = 'retry-required';
								$report['pages'][] = $result;
								break 2;
							}
						} else {
							$result = ['pageId' => $id, 'revisionId' => (int)$row->page_latest,
								'state' => 'preview-scan'];
						}
						$report['pages'][] = $result;
						$report['scan_processed']++;
						$cursor = $id;
						if ($execute) { $queue->saveDailyCursor($cursor); }
						if ($execute && $delay > 0 && $needsPacing($result)) { usleep($delay * 1000); }
					}
					if ($found === 0) {
						$report['stop_reason'] = 'cycle-complete';
						if ($execute && $cursor !== 0) {
							$queue->saveDailyCursor(0);
							$cursor = 0;
						}
						break;
					}
				}
				if ($report['stop_reason'] === 'complete' &&
					count($report['pages']) >= $limit) {
					$report['stop_reason'] = 'limit';
				}
			}
			if ($report['stop_reason'] === 'complete' && $queueOnly) {
				$report['stop_reason'] = 'queue-only';
			}
			$report['last_page_id'] = $cursor;
		} finally {
			if (is_resource($lock)) { fclose($lock); }
		}
		$report['duration_seconds'] = round(microtime(true) - $started, 3);
		$this->output(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
		return $report['stop_reason'] !== 'retry-required';
	}
}

$maintClass = PrepareDailyCaches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
