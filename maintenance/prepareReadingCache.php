<?php

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
		$this->addOption('limit', 'Maximum number of pages to inspect', false, true);
		$this->addOption('json', 'Print one machine-readable final report', false, false);
		$this->addOption('progress-every', 'Report every N inspected pages (default 100, 0 disables)', false, true);
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
		if ($size < 1 || $size > 1000 || $pause < 0 || $pause > 60000 || $after < 0 || $limit < 1 ||
			$progressEvery < 0 || $progressEvery > 100000) {
			$this->fatalError('Invalid batch, delay, cursor or limit.');
		}
		// A single worker per wiki; web fills also use the existing nonblocking page lock.
		$scope = \MediaWiki\Extension\Wikidebates\Reading\FragmentService::store()->startFill("\0daily-preparation");
		if (!$scope) { $this->fatalError('Another preparation is running.'); }
		$started = microtime(true);
		$db = $services->getDBLoadBalancer()->getConnection(DB_REPLICA);
		$count = 0;
		$statuses = array_fill_keys(['already-valid', 'created', 'not-cacheable', 'not-stored', 'busy', 'skipped', 'failed'], 0);
		$sources = array_fill_keys(['fragment-cache', 'vector-file', 'minerva-file', 'parser', 'page-output', 'other'], 0);
		try {
			do {
				$rows = $db->newSelectQueryBuilder()->select(['page_id', 'page_namespace', 'page_title'])
					->from('page')->join('categorylinks', null, 'cl_from = page_id')
					->where(['cl_to' => str_replace(' ', '_', $config->get('WikidebatesReadingArgumentCategory')),
						'page_namespace' => NS_MAIN, 'page_is_redirect' => 0, $db->expr('page_id', '>', $after)])
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
						$this->fatalError('Preparation failed for page ' . $row->page_id . '; resume with --after=' . $after);
					}
					$after = (int)$row->page_id;
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
				}
			} while ($found && $count < $limit);
		} finally {
			fclose($scope);
		}
		$report = [
			'type' => 'wikidebates-reading-preparation',
			'wiki' => \MediaWiki\WikiMap\WikiMap::getCurrentWikiId(),
			'inspected' => $count,
			'last_page_id' => $after,
			'statuses' => $statuses,
			'sources' => $sources,
			'duration_seconds' => round(microtime(true) - $started, 3)
		];
		if ($json) {
			$this->output(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		} else {
			$this->output('Preparation report: ' . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}
	}
}
$maintClass = PrepareReadingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
