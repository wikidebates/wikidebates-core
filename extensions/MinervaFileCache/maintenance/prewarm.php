<?php

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

use MediaWiki\Extension\MinervaFileCache\Hooks;
use MediaWiki\Extension\MinervaFileCache\Prewarm;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/** Sequentially request eligible wiki pages as a cookie-free mobile visitor. */
class PrewarmMinervaFileCache extends Maintenance {
	private const BATCH_SIZE = 100;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Prewarm MinervaFileCache for all non-redirect pages through anonymous mobile HTTP GETs.');
		$this->requireExtension('MinervaFileCache');
		$this->addOption('limit', 'Maximum pages to visit (0 = all; default 0).', false, true);
		$this->addOption('delay-ms', 'Pause between requests in milliseconds (default 1000, minimum 250).', false, true);
		$this->addOption('timeout', 'HTTP request timeout in seconds (default 30).', false, true);
		$this->addOption('device', 'Mobile User-Agent variant: android (default) or iphone.', false, true);
		$this->addOption('namespace', 'Only warm pages in this numeric namespace ID.', false, true);
		$this->addOption('after-id', 'Resume manually after this page_id (default 0).', false, true);
		$this->addOption('resume', 'Keep a private checkpoint for automatic resumption.');
		$this->addOption('reset', 'Discard a previous checkpoint (only with --resume).');
		$this->addOption('dry-run', 'List URLs without making HTTP requests.');
	}

	public function execute() {
		try {
			$limit = Prewarm::number($this->getOption('limit', 0), '--limit', 0, PHP_INT_MAX);
			$delay = Prewarm::number($this->getOption('delay-ms', 1000), '--delay-ms', 250, 60000);
			$timeout = Prewarm::number($this->getOption('timeout', 30), '--timeout', 1, 120);
			$afterId = Prewarm::number($this->getOption('after-id', 0), '--after-id', 0, PHP_INT_MAX);
			$device = (string)$this->getOption('device', 'android');
			$userAgent = Prewarm::userAgent($device);
			$namespace = $this->hasOption('namespace') ?
				Prewarm::number($this->getOption('namespace'), '--namespace', 0, PHP_INT_MAX) : null;
		} catch (\InvalidArgumentException $e) {
			$this->fatalError($e->getMessage());
			return false;
		}
		$resume = $this->hasOption('resume');
		$reset = $this->hasOption('reset');
		$dryRun = $this->hasOption('dry-run');
		if (($resume && ($dryRun || $afterId !== 0)) || ($reset && !$resume)) {
			$this->fatalError('--resume cannot be combined with --dry-run or --after-id; --reset needs --resume.');
			return false;
		}

		$config = $this->getServiceContainer()->getMainConfig();
		$server = (string)$config->get('Server');
		$origin = parse_url($server);
		if (!is_array($origin) || !in_array($origin['scheme'] ?? '', ['https', 'http'], true) ||
			!isset($origin['host']) || isset($origin['user']) || isset($origin['pass']) ||
			isset($origin['path']) || isset($origin['query']) || isset($origin['fragment'])) {
			$this->fatalError('Configure an absolute $wgServer origin (https://wiki.example.org) for this wiki.');
			return false;
		}
		if (!$dryRun) {
			if (!$config->get('MinervaFileCacheEnabled') || !$config->get('UseFileCache') ||
				!$config->get('MinervaFileCacheDebugHeaders')) {
				$this->fatalError('Enable MinervaFileCache, $wgUseFileCache and diagnostic headers before prewarming.');
				return false;
			}
			try {
				Hooks::store();
			} catch (\Throwable $e) {
				$this->fatalError('Minerva cache storage is unavailable: ' . $e->getMessage());
				return false;
			}
		}

		$services = $this->getServiceContainer();
		$http = $services->getHttpRequestFactory();
		if (!$dryRun && !$http->canMakeRequests()) {
			$this->fatalError('This PHP interpreter cannot send HTTP requests.');
			return false;
		}

		$checkpoint = null;
		$checkpointLock = null;
		if ($resume) {
			$checkpoint = Prewarm::checkpointPath(
				(string)$config->get('MinervaFileCacheDirectory'), WikiMap::getCurrentWikiId(),
				$server, (string)$config->get('LanguageCode'), $device, $namespace
			);
			$checkpointLock = @fopen($checkpoint . '.lock', 'c');
			if (!$checkpointLock || !@flock($checkpointLock, LOCK_EX | LOCK_NB)) {
				$this->fatalError('Cannot lock the checkpoint; another prewarm may be running.');
				return false;
			}
			@chmod($checkpoint . '.lock', 0600);
			try {
				if ($reset) {
					if (is_file($checkpoint) && !@unlink($checkpoint)) {
						throw new \RuntimeException('Cannot reset prewarm checkpoint');
					}
				} else {
					$afterId = Prewarm::readCheckpoint($checkpoint);
				}
			} catch (\Throwable $e) {
				$this->fatalError($e->getMessage());
				return false;
			}
		}

		$count = 0;
		$stats = [];
		$missingHeaders = 0;
		$lastId = $afterId;
		$finished = false;
		$this->output("Starting Minerva prewarm: wiki=" . WikiMap::getCurrentWikiId() .
			" device=$device after-id=$afterId" . ($dryRun ? ' [dry-run]' : '') . "\n");
		try {
			$dbr = $services->getConnectionProvider()->getReplicaDatabase();
			while (true) {
				$conditions = ['page_is_redirect' => 0, 'page_id > ' . (int)$lastId];
				if ($namespace !== null) {
					$conditions['page_namespace'] = $namespace;
				}
				$rows = $dbr->select('page', ['page_id', 'page_namespace', 'page_title'],
					$conditions, __METHOD__, ['ORDER BY' => 'page_id ASC', 'LIMIT' => self::BATCH_SIZE]);
				$inBatch = 0;
				foreach ($rows as $row) {
					$inBatch++;
					$id = (int)$row->page_id;
					$title = Title::makeTitle((int)$row->page_namespace, (string)$row->page_title);
					$url = $title->getFullURL();
					$parts = parse_url($url);
					if (!is_array($parts) || ($parts['scheme'] ?? null) !== $origin['scheme'] ||
						strcasecmp((string)($parts['host'] ?? ''), $origin['host']) !== 0 ||
						($parts['port'] ?? null) !== ($origin['port'] ?? null) ||
						isset($parts['user']) || isset($parts['pass'])) {
						$this->fatalError('Refusing to request a page URL outside $wgServer: ' . $url);
						return false;
					}
					if ($dryRun) {
						$state = 'DRY_RUN';
						$this->output("$id $url\n");
					} else {
						try {
							$request = $http->create($url, [
								'timeout' => $timeout, 'followRedirects' => false,
								'userAgent' => $userAgent,
							], __METHOD__);
							$request->setHeader('Accept-Language', (string)$config->get('LanguageCode'));
							$result = $request->execute();
							$code = (int)$request->getStatus();
							$header = $request->getResponseHeader('X-Minerva-File-Cache');
							$state = Prewarm::responseState($code, $header);
							if (!$result->isOK()) {
								$state = 'HTTP_ERROR';
							}
						} catch (\Throwable $e) {
							$state = 'HTTP_ERROR';
							$this->error("Request failed at page_id=$id: " . $e->getMessage());
						}
						if ($state === 'NO_HEADER' || $state === 'UNKNOWN_HEADER') {
							$missingHeaders++;
						} else {
							$missingHeaders = 0;
						}
						if ((str_starts_with($state, 'HTTP_') && !in_array($state, ['HTTP_404', 'HTTP_410'], true)) ||
							($state === 'BYPASS' && str_contains((string)$header, 'reason=storage-error')) ||
							$missingHeaders >= 5) {
							$this->output("Stopping at page_id=$id state=$state; last completed page_id=$lastId\n");
							$this->output("Retry with --resume, or --after-id=$lastId.\n");
							return false;
						}
					}
					$stats[$state] = ($stats[$state] ?? 0) + 1;
					$count++;
					$lastId = $id;
					if ($checkpoint !== null) {
						Prewarm::saveCheckpoint($checkpoint, $lastId);
					}
					if ($count % 25 === 0) {
						$this->output("Processed $count pages; last page_id=$lastId; " .
							json_encode($stats, JSON_UNESCAPED_SLASHES) . "\n");
					}
					if ($limit > 0 && $count >= $limit) {
						$this->output("Stopped at --limit=$limit; last page_id=$lastId.\n");
						$this->output(json_encode($stats) . "\n");
						return true;
					}
					if (!$dryRun) {
						usleep($delay * 1000);
					}
				}
				if ($inBatch < self::BATCH_SIZE) {
					$finished = true;
					break;
				}
			}
			$this->output("Completed $count pages; " . json_encode($stats) . "\n");
			return true;
		} finally {
			if ($finished && $checkpoint !== null) {
				@unlink($checkpoint);
			}
			if ($checkpointLock) {
				flock($checkpointLock, LOCK_UN);
				fclose($checkpointLock);
			}
		}
	}
}

$maintClass = PrewarmMinervaFileCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
