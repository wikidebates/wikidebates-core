<?php

use MediaWiki\Extension\Wikidebates\Reading\ArgumentCategory;
use MediaWiki\Extension\Wikidebates\Reading\WarmFragmentsJob;
use MediaWiki\MediaWikiServices;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

class PrepareReadingCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Prepare missing or invalid argument fragments, sequentially, without rebuilding valid files.');
		$this->requireExtension('Wikidebates');
		$this->addOption('batch-size', 'Database batch size (1-1000)', false, true);
		$this->addOption('sleep-ms', 'Pause between pages (default 100ms)', false, true);
		$this->addOption('after', 'Start after this page ID', false, true);
		$this->addOption('page-id', 'Prepare exactly this argument page_id (no database scan or resume).', false, true);
		$this->addOption('limit', 'Maximum number of pages to inspect', false, true);
		$this->addOption('json', 'Print one machine-readable final report', false, false);
		$this->addOption('progress-every', 'Report every N inspected pages (default 100, 0 disables)', false, true);
		$this->addOption('resume-file', 'Private absolute path to an automatically maintained resume checkpoint', false, true);
		$this->addOption('max-seconds', 'Graceful time limit at page boundaries (0 disables)', false, true);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		if (!$config->get('WikidebatesReadingFragments') || !$config->get('WikidebatesReadingPrewarm')) {
			$this->fatalError('Enable WikidebatesReadingFragments and WikidebatesReadingPrewarm.');
		}
		$size = (int)$this->getOption('batch-size', 100);
		$pause = (int)$this->getOption('sleep-ms', 100);
		$after = (int)$this->getOption('after', 0);
		$limit = (int)$this->getOption('limit', PHP_INT_MAX);
		$json = $this->hasOption('json');
		$progressEvery = (int)$this->getOption('progress-every', 100);
		$resumeFile = (string)$this->getOption('resume-file', '');
		$maxSeconds = (int)$this->getOption('max-seconds', 0);
		$rawPageId = (string)$this->getOption('page-id', '');
		$pageId = null;
		if ($this->hasOption('page-id')) {
			if ($rawPageId === '' || !ctype_digit($rawPageId) || (int)$rawPageId < 1 ||
				(string)(int)$rawPageId !== ltrim($rawPageId, '0')) {
				$this->fatalError('Invalid --page-id (expected positive decimal page ID).');
			}
			$pageId = (int)$rawPageId;
			if ($this->hasOption('after') || $resumeFile !== '' ||
				($this->hasOption('limit') && $limit !== 1)) {
				$this->fatalError('--page-id cannot be combined with --after, --resume-file or multi-page --limit.');
			}
			$limit = 1;
		}
		if ($size < 1 || $size > 1000 || $pause < 0 || $pause > 60000 || $after < 0 || $limit < 1 ||
			$progressEvery < 0 || $progressEvery > 100000 || $maxSeconds < 0 || $maxSeconds > 86400) {
			$this->fatalError('Invalid batch, delay, cursor or limit.');
		}
		// A single worker per wiki; web fills also use the existing nonblocking page lock.
		$scope = \MediaWiki\Extension\Wikidebates\Reading\FragmentService::store()->startFill("\0daily-preparation");
		if (!$scope) { $this->fatalError('Another preparation is running.'); }
		$started = microtime(true);
		$categoryKey = ArgumentCategory::dbKey($config);
		$wiki = \MediaWiki\WikiMap\WikiMap::getCurrentWikiId();
		if ($resumeFile !== '') {
			// A user-controlled checkpoint only resumes the same wiki. Explicit
			// --after is permitted when creating one, never when resuming it.
			try {
				$savedAfter = $this->readCheckpoint($resumeFile, $wiki);
			} catch (\Throwable $e) {
				fclose($scope);
				$this->fatalError('Cannot read reading-cache checkpoint: ' . $e->getMessage());
			}
			if ($savedAfter !== null) {
				if ($this->hasOption('after')) {
					fclose($scope);
					$this->fatalError('Omit --after while a resume checkpoint exists.');
				}
				$after = $savedAfter;
			}
		}
		$initialAfter = $after;
		// Targeted work must not read a stale page_id/title/category mapping
		// from a replica after a move or a visibility change. Bulk scans keep
		// the replica to avoid unnecessary primary database load.
		$db = $pageId !== null ? $services->getConnectionProvider()->getPrimaryDatabase() :
			$services->getDBLoadBalancer()->getConnection(DB_REPLICA);
		$count = 0;
		$statuses = array_fill_keys(['already-valid', 'created', 'not-cacheable', 'not-stored', 'busy', 'skipped', 'deferred-no-html', 'failed'], 0);
		$sources = array_fill_keys(['fragment-cache', 'vector-file', 'minerva-file', 'parser', 'page-output', 'other'], 0);
		$stopReason = 'complete';
		try {
			do {
				$conditions = ['cl_to' => $categoryKey,
					'page_namespace' => NS_MAIN, 'page_is_redirect' => 0];
				if ($pageId !== null) {
					$conditions['page_id'] = $pageId;
				} else {
					$conditions[] = $db->expr('page_id', '>', $after);
				}
				$rows = $db->newSelectQueryBuilder()->select(['page_id', 'page_namespace', 'page_title'])
					->from('page')->join('categorylinks', null, 'cl_from = page_id')
					->where($conditions)
					->orderBy('page_id')->limit(min($size, $limit - $count))->caller(__METHOD__)->fetchResultSet();
				$found = 0;
				foreach ($rows as $row) {
					$title = \MediaWiki\Title\Title::newFromRow($row);
					$job = new WarmFragmentsJob($title);
					$ok = $job->run();
					$result = $job->getPreparation();
					$status = $result['status'] ?? 'failed';
					$statuses[isset($statuses[$status]) ? $status : 'failed']++;
					$source = $result['source'] ?? null;
					if ($source !== null) {
						$sources[isset($sources[$source]) ? $source : 'other']++;
					}
					if (!$ok) {
						// $after still identifies the last successfully inspected page;
						// page IDs are not necessarily contiguous.
						$this->fatalError('Preparation failed for page ' . $row->page_id . '; retry with ' .
							($pageId !== null ? '--page-id=' . $pageId : '--after=' . $after));
					}
					$after = (int)$row->page_id;
					if ($resumeFile !== '') {
						$this->writeCheckpoint($resumeFile, $wiki, $after);
					}
					$count++;
					$found++;
					if ($progressEvery > 0 && $count % $progressEvery === 0) {
						// stderr stays visible and unbuffered with --json | tee, while
						// stdout still contains exactly one JSON object at completion.
						$line = "$count (last_page_id=$after; resume=--after=$after)\n";
						if ($json) {
							fwrite(STDERR, $line);
							fflush(STDERR);
						} else {
							$this->output($line);
						}
					}
					// Sleeping on a valid fragment adds no protection for the server
					// and makes a daily scan of a large wiki unnecessarily slow.
					if ($pause && !in_array($status, ['already-valid', 'skipped'], true)) {
						usleep($pause * 1000);
					}
					if ($maxSeconds > 0 && microtime(true) - $started >= $maxSeconds) {
						$stopReason = 'time-limit';
						break 2;
					}
				}
			} while ($found && $count < $limit);
			if ($stopReason === 'complete' && $count >= $limit && $pageId === null) {
				$stopReason = 'limit';
			}
			if ($resumeFile !== '' && $stopReason === 'complete') {
				// At EOF reset the cycle, so the next run scans earlier arguments
				// for edits and expiry rather than permanently skipping them.
				$this->clearCheckpoint($resumeFile);
			}
		} finally {
			fclose($scope);
		}
		$report = [
			'type' => 'wikidebates-reading-preparation',
			'wiki' => $wiki,
			'start_after' => $initialAfter,
			'complete' => $stopReason === 'complete',
			'stop_reason' => $stopReason,
			'inspected' => $count,
			'last_page_id' => $after,
			'statuses' => $statuses,
			'sources' => $sources,
			'duration_seconds' => round(microtime(true) - $started, 3)
		];
		if ($pageId !== null) {
			$report['target_page_id'] = $pageId;
		}
		if ($json) {
			$this->output(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		} else {
			$this->output('Preparation report: ' . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}
	}

	/** @return int|null Null when no saved cycle exists. */
	private function readCheckpoint(string $path, string $wiki): ?int {
		$this->checkpointDirectory($path);
		if (!file_exists($path)) {
			return null;
		}
		if (filesize($path) > 4096 || ($raw = @file_get_contents($path)) === false) {
			throw new \RuntimeException('Unreadable checkpoint');
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
			($data['wiki'] ?? null) !== $wiki ||
			!is_int($data['after'] ?? null) || $data['after'] < 0) {
			throw new \RuntimeException('Invalid checkpoint or wrong wiki');
		}
		return $data['after'];
	}

	private function checkpointDirectory(string $path): string {
		if ($path === '' || $path[0] !== '/' || str_ends_with($path, '/') ||
			is_link($path) || (file_exists($path) && !is_file($path))) {
			throw new \RuntimeException('Checkpoint must be a regular file at an absolute path');
		}
		$directory = realpath(dirname($path));
		if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
			throw new \RuntimeException('Checkpoint directory must exist and be writable');
		}
		return $directory;
	}

	private function writeCheckpoint(string $path, string $wiki, int $after): void {
		$directory = $this->checkpointDirectory($path);
		$temporary = @tempnam($directory, '.reading-resume-');
		if ($temporary === false || dirname($temporary) !== $directory) {
			throw new \RuntimeException('Cannot create checkpoint temporary file');
		}
		try {
			$payload = json_encode([
				'version' => 1, 'wiki' => $wiki, 'after' => $after
			], JSON_THROW_ON_ERROR) . "\n";
			if (@file_put_contents($temporary, $payload) !== strlen($payload) ||
				!@chmod($temporary, 0600) || !@rename($temporary, $path)) {
				throw new \RuntimeException('Cannot publish reading-cache checkpoint');
			}
		} finally {
			if (is_file($temporary)) {
				@unlink($temporary);
			}
		}
	}

	private function clearCheckpoint(string $path): void {
		$this->checkpointDirectory($path);
		if (is_file($path) && !@unlink($path)) {
			throw new \RuntimeException('Cannot reset reading-cache checkpoint');
		}
	}

}
$maintClass = PrepareReadingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
