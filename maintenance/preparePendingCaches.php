<?php

use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * A bounded, opt-in queue consumer. No crons are installed by this command.
 * Preview is the default and cannot mutate the queue or generate HTML.
 */
class PreparePendingCaches extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Preview or prepare a bounded batch of directly edited anonymous HTML caches.');
		$this->requireExtension('Wikidebates');
		$this->addOption('execute', 'Explicitly generate caches and acknowledge successfully prepared revisions.');
		$this->addOption('limit', 'Maximum pending pages in one run (default 10, maximum 100).', false, true);
		$this->addOption('max-seconds', 'Graceful wall-clock budget (default 600, max 7200).', false, true);
		$this->addOption('delay-ms', 'Idle pause after a completed page (default 1000, 0-60000).', false, true);
		$this->addOption('minerva-delay-ms', 'Minerva HTTP pacing (default 6000, min 250).', false, true);
		$this->addOption('json', 'Emit a single JSON report.');
	}

	private function bounded(string $name, int $default, int $min, int $max): int {
		$raw = (string)$this->getOption($name, (string)$default);
		if ($raw === '' || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
			$this->fatalError('Invalid --' . $name . ' (expected ' . $min . '..' . $max . ').');
		}
		return (int)$raw;
	}

	public function execute() {
		$limit = $this->bounded('limit', 10, 1, 100);
		$maxSeconds = $this->bounded('max-seconds', 600, 180, 7200);
		$delay = $this->bounded('delay-ms', 1000, 0, 60000);
		$minervaDelay = $this->bounded('minerva-delay-ms', 6000, 250, 60000);
		$execute = $this->hasOption('execute');
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$directory = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') {
			$this->fatalError('The private direct-edit queue is not configured. No preparation was attempted.');
		}
		$wiki = WikiMap::getCurrentWikiId();
		try {
			$queue = new CachePreparationQueue($directory, $wiki);
			$pending = $queue->pending($limit);
		} catch (\Throwable $e) {
			$this->fatalError('Cannot read the private preparation queue (' . get_class($e) . ').');
		}
		$report = [
			'type' => 'wikidebates-coordinated-preparation', 'wiki' => $wiki,
			'mode' => $execute ? 'execute' : 'preview', 'max_seconds' => $maxSeconds,
			'limit' => $limit, 'sampled' => count($pending),
			'acknowledged' => 0, 'retries' => 0, 'stop_reason' => 'complete',
			'pages' => []
		];
		if (!$execute) {
			foreach ($pending as $record) {
				$report['pages'][] = ['pageId' => $record['pageId'],
					'revisionId' => $record['revisionId'], 'state' => 'preview'];
			}
			$this->output(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
			return true;
		}
		if (!$config->get('WikidebatesReadingFragments') || !$config->get('WikidebatesReadingPrewarm') ||
			!$config->get('MinervaFileCacheEnabled') || !$config->get('MinervaFileCacheDebugHeaders') ||
			!$config->get('UseFileCache')) {
			$this->fatalError('Vector, Minerva diagnostics and reading preparation must all be enabled.');
		}
		$lock = $queue->tryLockWorker();
		if (!$lock) {
			$this->fatalError('Another preparation worker already holds this wiki lock.');
		}
		$started = microtime(true);
		try {
			// Use PRIMARY to avoid acknowledging a newer edit based on replica lag.
			// Only one tiny revision lookup is made at each phase boundary.
			$db = $services->getConnectionProvider()->getPrimaryDatabase();
			$latest = static function (int $pageId) use ($db): ?int {
				$row = $db->selectRow('page', ['page_latest'], ['page_id' => $pageId], __METHOD__);
				return $row ? (int)$row->page_latest : null;
			};
			$root = defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : dirname(__DIR__, 3);
			$commands = new CachePreparationCommands($root, $wiki, $minervaDelay);
			$coordinator = new CachePreparationCoordinator($queue);
			foreach ($pending as $record) {
				// Do not initiate a potentially slow subprocess near the budget.
				if (microtime(true) - $started >= $maxSeconds - 120) {
					$report['stop_reason'] = 'time-budget';
					break;
				}
				$result = $coordinator->process($record, $latest,
					static fn(string $phase, int $pageId): array => $commands->prepare($phase, $pageId),
					static fn(int $pageId): ?string => AnonymousCacheSafety::fingerprint($pageId));
				$report['pages'][] = $result;
				if ($result['state'] === 'acknowledged') {
					$report['acknowledged']++;
				} elseif ($result['state'] === 'retry') {
					// Stop on a storage failure, HTTP 429, invalid result, or
					// disabled cache rather than generating a burst of requests.
					$report['retries']++;
					$report['stop_reason'] = 'retry-required';
					break;
				}
				if ($delay > 0) { usleep($delay * 1000); }
			}
		} finally {
			fclose($lock);
		}
		$report['duration_seconds'] = round(microtime(true) - $started, 3);
		$this->output(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
		return $report['retries'] === 0;
	}
}

$maintClass = PreparePendingCaches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
