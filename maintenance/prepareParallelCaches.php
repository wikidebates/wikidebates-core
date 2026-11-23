<?php

use MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety;
use MediaWiki\Extension\Wikidebates\Reading\ArgumentCategory;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCommands;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationCoordinator;
use MediaWiki\Extension\Wikidebates\Reading\CachePreparationQueue;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillGovernor;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillJournal;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillState;
use MediaWiki\WikiMap\WikiMap;
use MediaWiki\Title\Title;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Explicit, one-time parallel scan. The supervisor owns the legacy per-wiki
 * worker lock throughout the run. Each child is a long-lived MediaWiki CLI
 * process with its own disjoint scan lane; direct edits use one queue child.
 *
 * No existing daily cron calls this command. Scan cursor publication is
 * all-or-nothing: only after EVERY fixed lane is exhausted is the legacy
 * daily cursor reset. Individual lane checkpoints survive crashes.
 */
class PrepareParallelCaches extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Explicit resumable parallel preparation of anonymous HTML caches.');
		$this->requireExtension('Wikidebates');
		$this->addOption('execute', 'Explicitly enable cache creation and durable state changes.');
		$this->addOption('status', 'Read-only progress report.');
		$this->addOption('stop', 'Request graceful stop at the next page boundary.');
		$this->addOption('new-cycle', 'Start a fresh cycle only after the previous one completed.');
		$this->addOption('workers', 'Number of parallel scan lanes (1-8; default 4).', false, true);
		$this->addOption('minerva-devices', 'android, iphone, or android,iphone.', false, true);
		$this->addOption('minerva-delay-ms', 'Pause between mobile variants (250-60000; default 250).', false, true);
		$this->addOption('max-load', 'Maximum tolerable 1-minute load (default 10).', false, true);
		$this->addOption('reserve-mb', 'Required available RAM (default 2048 MB).', false, true);
		$this->addOption('max-health-ms', 'Maximum public-site GET latency (default 2500 ms).', false, true);
		$this->addOption('health-title', 'Existing public page for health GET; main page by default.', false, true);
		$this->addOption('max-seconds', 'Stop gracefully after this budget (600-86400; default 28800).', false, true);
		$this->addOption('worker-lane', 'Internal: run one reserved lane.', false, true);
		$this->addOption('worker-queue', 'Internal: consume recent direct edits while scanning.');
		$this->addOption('session', 'Internal: supervisor run generation.', false, true);
		$this->addOption('json', 'Emit machine-readable progress reports.');
	}

	private function number(string $name, int $default, int $min, int $max): int {
		$raw = (string)$this->getOption($name, $default);
		if ($raw === '' || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
			$this->fatalError('Invalid --' . $name . ' (expected ' . $min . '..' . $max . ').');
		}
		return (int)$raw;
	}

	private function report(array $data): void {
		$this->output(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$directory = $config->get('WikidebatesCachePreparationDirectory');
		if (!is_string($directory) || $directory === '') {
			$this->fatalError('A private per-wiki preparation directory must be configured.');
		}
		$wiki = WikiMap::getCurrentWikiId();
		$queue = new CachePreparationQueue($directory, $wiki);
		$journal = new ParallelBackfillJournal($queue, $wiki);

		if ($this->hasOption('status')) {
			if ($this->hasOption('execute') || $this->hasOption('stop') ||
				$this->hasOption('worker-lane') || $this->hasOption('worker-queue')) {
				$this->fatalError('Read-only --status cannot be combined with mutations.');
			}
			$snapshot = $journal->snapshot();
			if ($snapshot !== null) {
				$snapshot['contiguous_after'] =
					(new ParallelBackfillState($snapshot['lanes']))->contiguousAfter();
				unset($snapshot['run_id']);
			}
			$this->report(['type' => 'wikidebates-parallel-backfill', 'wiki' => $wiki,
				'mode' => 'status', 'checkpoint' => $snapshot]);
			return true;
		}
		if ($this->hasOption('stop')) {
			if ($this->hasOption('worker-lane') || $this->hasOption('worker-queue')) {
				$this->fatalError('--stop cannot be used by a worker.');
			}
			$journal->stop();
			$this->report(['type' => 'wikidebates-parallel-backfill', 'wiki' => $wiki,
				'mode' => 'stop-requested']);
			return true;
		}
		if (!$this->hasOption('execute')) {
			$this->fatalError('Preparation is opt-in; use --execute, --status or --stop.');
		}
		if (!$config->get('WikidebatesReadingFragments') ||
			!$config->get('WikidebatesReadingPrewarm') ||
			!$config->get('MinervaFileCacheEnabled') ||
			!$config->get('MinervaFileCacheDebugHeaders') ||
			!$config->get('UseFileCache')) {
			$this->fatalError('Vector, Minerva diagnostics and Reading prewarm must all be enabled.');
		}
		$devices = explode(',', (string)$this->getOption('minerva-devices', 'android,iphone'));
		if (!in_array($devices, [['android'], ['iphone'], ['android', 'iphone']], true)) {
			$this->fatalError('Invalid --minerva-devices.');
		}
		$minervaDelay = $this->number('minerva-delay-ms', 250, 250, 60000);
		$session = (string)$this->getOption('session', '');
		if ($this->hasOption('worker-lane') || $this->hasOption('worker-queue')) {
			if ($this->hasOption('new-cycle') || !preg_match('/\A[a-f0-9]{32}\z/D', $session)) {
				$this->fatalError('Invalid internal worker session.');
			}
			$lane = $this->hasOption('worker-lane') ?
				$this->number('worker-lane', 0, 0, 15) : null;
			return $this->runWorker($queue, $journal, $wiki, $session, $lane,
				$devices, $minervaDelay);
		}
		if ($session !== '') {
			$this->fatalError('--session is only valid for internal workers.');
		}
		$workers = $this->number('workers', 4, 1, 8);
		$loadRaw = (string)$this->getOption('max-load', '10');
		if (!preg_match('/\A(?:[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?\z/D', $loadRaw) ||
			(float)$loadRaw > 256) {
			$this->fatalError('Invalid --max-load.');
		}
		$reserveMb = $this->number('reserve-mb', 2048, 128, 65536);
		$healthMs = $this->number('max-health-ms', 2500, 200, 20000);
		$maxSeconds = $this->number('max-seconds', 28800, 600, 86400);
		if (!function_exists('curl_init')) {
			$this->fatalError('The PHP cURL extension is required for public availability checks.');
		}
		$server = (string)$config->get('Server');
		$parts = parse_url($server);
		$loopbackLab = is_array($parts) && ($parts['scheme'] ?? null) === 'http' &&
			in_array($parts['host'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);
		if (!is_array($parts) ||
			(($parts['scheme'] ?? null) !== 'https' && !$loopbackLab) ||
			!isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
			isset($parts['query']) || isset($parts['fragment']) ||
			isset($parts['path'])) {
			$this->fatalError('The wiki needs an HTTPS origin (HTTP only for localhost lab).');
		}
		$healthTitleRaw = (string)$this->getOption('health-title', '');
		$healthTitle = $healthTitleRaw === '' ? Title::newMainPage() :
			Title::newFromText($healthTitleRaw);
		if (!$healthTitle || !$healthTitle->canExist()) {
			$this->fatalError('Invalid public health page title.');
		}
		$healthUrl = $healthTitle->getFullURL();
		$healthParts = parse_url($healthUrl);
		if (!is_array($healthParts) ||
			($healthParts['scheme'] ?? null) !== $parts['scheme'] ||
			($healthParts['host'] ?? null) !== $parts['host'] ||
			($healthParts['port'] ?? null) !== ($parts['port'] ?? null) ||
			isset($healthParts['user']) || isset($healthParts['pass'])) {
			$this->fatalError('Health URL escaped the configured public wiki origin.');
		}
		$lock = $queue->tryLockWorker();
		if (!$lock) {
			$this->fatalError('A normal or parallel cache preparation worker is already active.');
		}
		try {
			$existing = $journal->snapshot();
			$cursor = $queue->dailyCursor();
			if ($existing !== null && $existing['state'] !== 'complete' &&
				$cursor !== $existing['start_after'] &&
				!(new ParallelBackfillState($existing['lanes']))->finished()) {
				$this->fatalError('Legacy cursor changed during unfinished parallel run; refusing resume.');
			}
			$db = $services->getConnectionProvider()->getPrimaryDatabase();
			$highest = (int)$db->selectField('page', 'MAX(page_id)', [], __METHOD__);
			if ($existing === null && $highest <= $cursor) {
				$this->report(['type' => 'wikidebates-parallel-backfill',
					'wiki' => $wiki, 'mode' => 'execute', 'state' => 'nothing-to-scan']);
				return true;
			}
			$run = $journal->activate(
				$existing !== null && $existing['state'] !== 'complete'
					? $existing['start_after'] : $cursor,
				$highest, $workers, $this->cacheSignature($config),
				$this->hasOption('new-cycle')
			);
			return $this->supervise($queue, $journal, $wiki, $run, $workers,
				$devices, $minervaDelay, (float)$loadRaw, $reserveMb,
				$healthMs, $maxSeconds, $healthUrl);
		} finally {
			fclose($lock);
		}
	}

	/**
	 * A bounded number of long-lived children; no shared PHP state or shared
	 * scan cursor. Their journal generation changes atomically on resume.
	 */
	private function supervise(
		CachePreparationQueue $queue, ParallelBackfillJournal $journal, string $wiki,
		array $run, int $workers, array $devices, int $minervaDelay,
		float $maxLoad, int $reserveMb, int $healthMs, int $maxSeconds, string $healthUrl
	): bool {
		$started = microtime(true);
		$runId = $run['run_id'];
		$root = defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : dirname(__DIR__, 3);
		$entrypoint = realpath($root . '/maintenance/run.php');
		if ($entrypoint === false) {
			throw new \RuntimeException('No MediaWiki maintenance runner');
		}
		$children = [];
		$governor = new ParallelBackfillGovernor($workers, $maxLoad, $reserveMb, $healthMs);
		$lastProbe = 0.0;
		$longPressureSince = null;
		$failure = false;
		try {
			foreach ($run['lanes'] as $lane => $checkpoint) {
				if ($checkpoint['done']) { continue; }
				$command = [PHP_BINARY, $entrypoint, 'Wikidebates:prepareParallelCaches',
					'--wiki=' . $wiki, '--execute', '--worker-lane=' . $lane,
					'--session=' . $runId, '--minerva-devices=' . implode(',', $devices),
					'--minerva-delay-ms=' . $minervaDelay];
				$children['lane-' . $lane] = $this->spawn($command);
			}
			if ($children !== []) {
				$command = [PHP_BINARY, $entrypoint, 'Wikidebates:prepareParallelCaches',
					'--wiki=' . $wiki, '--execute', '--worker-queue', '--session=' . $runId,
					'--minerva-devices=' . implode(',', $devices),
					'--minerva-delay-ms=' . $minervaDelay];
				$children['queue'] = $this->spawn($command);
			}
			while ($children !== []) {
				$now = microtime(true);
				$snapshot = $journal->snapshot();
				if ($snapshot['run_id'] !== $runId) {
					throw new \RuntimeException('Parallel backfill session was superseded');
				}
				$finishedLanes = (new ParallelBackfillState($snapshot['lanes']))->finished();
				if ($finishedLanes && !$snapshot['stop_requested']) {
					$journal->stop();
				}
				if ($now - $lastProbe >= 5 && !$snapshot['stop_requested']) {
					$lastProbe = $now;
					$load = sys_getloadavg();
					$available = $this->availableMemoryMb();
					[$httpStatus, $latency] = $this->probePublic($healthUrl);
					$permits = $governor->sample(
						is_array($load) && isset($load[0]) ? (float)$load[0] : null,
						$available, $httpStatus, $latency
					);
					$journal->heartbeat($runId, $permits);
					if ($permits === 0) {
						$longPressureSince ??= $now;
					} else {
						$longPressureSince = null;
					}
					$this->report(['type' => 'wikidebates-parallel-backfill', 'wiki' => $wiki,
						'mode' => 'resources', 'permits' => $permits,
						'load_1m' => is_array($load) ? round((float)$load[0], 2) : null,
						'available_mb' => $available, 'health_http' => $httpStatus,
						'health_ms' => $latency]);
					// Never ignore a publicly visible access denial or throttle.
					if (in_array($httpStatus, [403, 429], true)) {
						$journal->failed($runId, -1, 0, 'public-http-' . $httpStatus);
						$failure = true;
					} elseif ($longPressureSince !== null && $now - $longPressureSince > 180) {
						$journal->failed($runId, -1, 0, 'resource-pressure-timeout');
						$failure = true;
					}
				}
				if ($now - $started >= $maxSeconds && !$snapshot['stop_requested']) {
					$journal->stop();
				}
				foreach ($children as $key => $child) {
					foreach ([1, 2] as $channel) {
						$chunk = @fread($child['pipes'][$channel], 8192);
						if (is_string($chunk) && $chunk !== '') {
							fwrite(STDERR, '[' . $wiki . '/' . $key . '] ' . $chunk);
						}
					}
					$status = proc_get_status($child['process']);
					if ($status['running']) { continue; }
					foreach ([1, 2] as $channel) {
						$remaining = stream_get_contents($child['pipes'][$channel]);
						if (is_string($remaining) && $remaining !== '') {
							fwrite(STDERR, '[' . $wiki . '/' . $key . '] ' . $remaining);
						}
						fclose($child['pipes'][$channel]);
					}
					$exit = (int)$status['exitcode'];
					@proc_close($child['process']);
					unset($children[$key]);
					if ($exit !== 0) {
						$failure = true;
						$current = $journal->snapshot();
						if ($current['state'] === 'running') {
							$journal->failed($runId, -1, 0, 'worker-exit-' . $exit);
						}
					}
				}
				if ($failure && $children === []) { break; }
				if ($children !== []) { usleep(250000); }
			}
			$snapshot = $journal->snapshot();
			$state = new ParallelBackfillState($snapshot['lanes']);
			if (!$failure && $state->finished() && $snapshot['state'] === 'running') {
				// No unfinished page can be hidden behind an optimistic cursor.
				$queue->saveDailyCursor(0);
				$journal->markComplete($runId);
				$snapshot = $journal->snapshot();
			}
			$this->report(['type' => 'wikidebates-parallel-backfill', 'wiki' => $wiki,
				'mode' => 'execute', 'state' => $snapshot['state'],
				'stop_requested' => $snapshot['stop_requested'],
				'contiguous_after' => $state->contiguousAfter(),
				'high_watermark' => $snapshot['high_watermark'],
				'metrics' => $snapshot['metrics'], 'last_failure' => $snapshot['last_failure'],
				'duration_seconds' => round(microtime(true) - $started, 3)]);
			return !$failure && $snapshot['state'] === 'complete';
		} finally {
			if ($children !== []) {
				$journal->stop();
				foreach ($children as $child) {
					@proc_terminate($child['process'], 15);
					fclose($child['pipes'][1]);
					fclose($child['pipes'][2]);
					@proc_close($child['process']);
				}
			}
		}
	}

	/** @return array{process:resource,pipes:array} */
	private function spawn(array $command): array {
		$proc = @proc_open($command, [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		], $pipes);
		if (!is_resource($proc)) {
			throw new \RuntimeException('Could not start parallel backfill worker');
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		return ['process' => $proc, 'pipes' => $pipes];
	}

	/** Each lane processes only its own monotonically increasing ID range. */
	private function runWorker(
		CachePreparationQueue $queue, ParallelBackfillJournal $journal, string $wiki,
		string $session, ?int $lane, array $devices, int $minervaDelay
	): bool {
		$lock = $lane === null ? $journal->tryLockLane(63) : $journal->tryLockLane($lane);
		if (!$lock) {
			$this->fatalError('A worker already owns this parallel lane.');
		}
		try {
			$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
			$config = $this->getServiceContainer()->getMainConfig();
			$categoryKey = ArgumentCategory::dbKey($config);
			$latest = static function (int $id) use ($db): ?int {
				$row = $db->selectRow('page', ['page_latest'], ['page_id' => $id], __METHOD__);
				return $row ? (int)$row->page_latest : null;
			};
			$identity = static fn(int $id): ?string => AnonymousCacheSafety::fingerprint($id);
			$root = defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : dirname(__DIR__, 3);
			$commands = new CachePreparationCommands($root, $wiki, $minervaDelay, 180, $devices);
			$coordinator = new CachePreparationCoordinator($queue);
			$prepare = static fn(string $phase, int $id): array =>
				$phase === 'reading' && !ArgumentCategory::containsPage($db, $id, $categoryKey)
					? ['ok' => true, 'status' => 'not-an-argument']
					: $commands->prepare($phase, $id);
			$processed = 0;
			$deferredQueuePages = [];
			while (true) {
				$snapshot = $journal->snapshot();
				if ($snapshot === null || $snapshot['run_id'] !== $session ||
					!hash_equals($snapshot['cache_signature'], $this->cacheSignature($config)) ||
					$lane !== null && !isset($snapshot['lanes'][$lane])) {
					return false;
				}
				if ($snapshot['stop_requested'] || $snapshot['state'] !== 'running') {
					return true;
				}
				if (time() - $snapshot['heartbeat_at'] > 45) {
					// Never continue a detached orphan after supervisor exit.
					return false;
				}
				if ($snapshot['desired_workers'] === 0 ||
					$lane !== null && $lane >= $snapshot['desired_workers']) {
					usleep(500000);
					continue;
				}
				if ($lane === null) {
					// A private/deleted/superseded direct-edit record must not
					// cause a tight loop that starves newer queued pages.
					$pending = $queue->pending(100, true);
					$attempts = 0;
					foreach ($pending as $sample) {
						if ($attempts >= 10 || $journal->snapshot()['stop_requested']) { break; }
						$id = $sample['pageId'];
						if (($deferredQueuePages[$id] ?? 0) > time()) { continue; }
						$attempts++;
						$build = $queue->lockBuildPage($id);
						try {
							$current = $queue->current($id);
							if ($current === null || $current['schema'] === 3) { continue; }
							$result = $coordinator->process($current, $latest, $prepare, $identity);
						} finally {
							fclose($build);
						}
						if ($result['state'] === 'acknowledged') {
							unset($deferredQueuePages[$id]);
							$journal->acknowledgedQueue($session, $result['phases']);
							$this->report(['mode' => 'queue', 'page_id' => $id,
								'state' => 'acknowledged']);
						} elseif ($result['state'] === 'retry') {
							$this->report(['mode' => 'queue-error', 'page_id' => $id,
								'state' => 'retry', 'phases' => $result['phases']]);
							$journal->failed($session, -1, $id, 'queue-retry');
							return false;
						} else {
							$deferredQueuePages[$id] = time() + 60;
						}
					}
					// Bound the in-memory exception list even for very long runs.
					if (count($deferredQueuePages) > 10000) {
						$deferredQueuePages = array_filter($deferredQueuePages,
							static fn(int $until): bool => $until > time());
					}
					sleep($pending === [] ? 3 : 1);
					continue;
				}
				$laneState = $snapshot['lanes'][$lane];
				if ($laneState['done']) { return true; }
				$after = $laneState['after'];
				$end = $laneState['end'];
				$rows = $db->newSelectQueryBuilder()
					->select(['page_id', 'page_latest'])
					->from('page')
					->where(['page_is_redirect' => 0,
						$db->expr('page_id', '>', $after),
						$db->expr('page_id', '<=', $end)])
					->orderBy('page_id')
					->limit(25)
					->caller(__METHOD__)->fetchResultSet();
				$found = 0;
				foreach ($rows as $row) {
					$found++;
					$id = (int)$row->page_id;
					$snapshot = $journal->snapshot();
					if ($snapshot['run_id'] !== $session || $snapshot['stop_requested'] ||
						$snapshot['state'] !== 'running') {
						return true;
					}
					if ($snapshot['desired_workers'] === 0 ||
						$lane >= $snapshot['desired_workers']) {
						break;
					}
					$build = $queue->lockBuildPage($id);
					try {
						$queued = $queue->current($id);
						if ($queued !== null) {
							$result = $coordinator->process($queued, $latest, $prepare, $identity);
							// A newly enqueued generation must not be skipped.
							if ($result['state'] === 'acknowledged' && $queue->current($id) !== null) {
								$result['state'] = 'queue-changed-after-preparation';
							}
						} else {
							$result = $coordinator->processUnqueued(
								$id, (int)$row->page_latest, $latest, $prepare, $identity
							);
							if ($result['state'] === 'complete' && $queue->current($id) !== null) {
								$result['state'] = 'queue-changed-after-preparation';
							}
						}
					} finally {
						fclose($build);
					}
					if (!in_array($result['state'], ['complete', 'skipped-nonpublic', 'acknowledged'], true)) {
						$reason = (string)($result['phases'][array_key_last($result['phases'])] ??
							$result['state']);
						$journal->failed($session, $lane, $id, $reason);
						$this->report(['mode' => 'scan-error', 'lane' => $lane,
							'page_id' => $id, 'state' => $result['state'],
							'phases' => $result['phases']]);
						return false;
					}
					$journal->completed($session, $lane, $id,
						$result['state'], $result['phases']);
					$processed++;
					if ($processed % 10 === 0) {
						$this->report(['mode' => 'scan', 'lane' => $lane,
							'processed' => $processed, 'last_page_id' => $id]);
					}
				}
				if ($found === 0) {
					$journal->exhausted($session, $lane);
					$this->report(['mode' => 'lane-complete', 'lane' => $lane,
						'processed' => $processed, 'after' => $end]);
					return true;
				}
			}
		} finally {
			fclose($lock);
		}
	}

	/**
	 * Resume may not skip an earlier range when the cache identity/config
	 * changed and all files in that range need regeneration.
	 */
	private function cacheSignature($config): string {
		$keys = [
			'CacheEpoch', 'MinervaFileCacheEpoch', 'WikidebatesReadingCacheEpoch',
			'FileCacheDirectory', 'MinervaFileCacheDirectory',
			'WikidebatesReadingCacheDirectory', 'MinervaFileCacheTTL',
			'WikidebatesReadingCacheTTL', 'WikidebatesReadingArgumentCategory',
			'DefaultSkin', 'LanguageCode', 'Server'
		];
		$values = [];
		foreach ($keys as $key) {
			$values[$key] = $config->get($key);
		}
		return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
	}

	/** @return int|null */
	private function availableMemoryMb(): ?int {
		$raw = @file_get_contents('/proc/meminfo');
		if (!is_string($raw) || !preg_match('/^MemAvailable:\s+([0-9]+)\s+kB/m', $raw, $match)) {
			return null;
		}
		return intdiv((int)$match[1], 1024);
	}

	/** @return array{0:int|null,1:int|null} */
	private function probePublic(string $url): array {
		$handle = curl_init($url);
		if ($handle === false) { return [null, null]; }
		curl_setopt_array($handle, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 2,
			CURLOPT_CONNECTTIMEOUT_MS => 2500,
			CURLOPT_TIMEOUT_MS => 5000,
			CURLOPT_USERAGENT => 'WikidebatesCachePreparationHealth/1',
			CURLOPT_COOKIE => '',
			CURLOPT_WRITEFUNCTION => static fn($ch, string $body): int => strlen($body)
		]);
		$started = microtime(true);
		$ok = curl_exec($handle) !== false;
		$code = $ok ? (int)curl_getinfo($handle, CURLINFO_HTTP_CODE) : null;
		$duration = (int)round((microtime(true) - $started) * 1000);
		curl_close($handle);
		return [$code, $duration];
	}
}

$maintClass = PrepareParallelCaches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
