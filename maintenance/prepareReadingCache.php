<?php

use MediaWiki\Extension\Wikidebates\Reading\WarmFragmentsJob;
use MediaWiki\MediaWikiServices;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

class PrepareReadingCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Prepare missing or invalid argument fragments, sequentially, without rebuilding valid files.');
		$this->requireExtension('Wikidebates');
		$this->addOption('batch-size', 'Database batch size (1–1000)', false, true);
		$this->addOption('sleep-ms', 'Pause between pages (default 100ms)', false, true);
		$this->addOption('after', 'Start after this page ID', false, true);
		$this->addOption('limit', 'Maximum number of pages to inspect', false, true);
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
		if ($size < 1 || $size > 1000 || $pause < 0 || $pause > 60000 || $after < 0 || $limit < 1) {
			$this->fatalError('Invalid batch, delay, cursor or limit.');
		}
		// A single worker per wiki; web fills also use the existing nonblocking page lock.
		$scope = \MediaWiki\Extension\Wikidebates\Reading\FragmentService::store()->startFill("\0daily-preparation");
		if (!$scope) { $this->fatalError('Another preparation is running.'); }
		$db = $services->getDBLoadBalancer()->getConnection(DB_REPLICA);
		$count = 0;
		do {
			$rows = $db->newSelectQueryBuilder()->select(['page_id', 'page_namespace', 'page_title'])
				->from('page')->join('categorylinks', null, 'cl_from = page_id')
				->where(['cl_to' => str_replace(' ', '_', $config->get('WikidebatesReadingArgumentCategory')),
					'page_namespace' => NS_MAIN, 'page_is_redirect' => 0, $db->expr('page_id', '>', $after)])
				->orderBy('page_id')->limit(min($size, $limit - $count))->caller(__METHOD__)->fetchResultSet();
			$found = 0;
			foreach ($rows as $row) {
				$title = \MediaWiki\Title\Title::newFromRow($row);
				if (!(new WarmFragmentsJob($title))->run()) { $this->fatalError('Preparation failed for page ' . $row->page_id); }
				$after = (int)$row->page_id; $count++; $found++;
				if ($pause) { usleep($pause * 1000); }
			}
			$this->output("Inspected $count pages; resume with --after=$after\n");
		} while ($found && $count < $limit);
	}
}
$maintClass = PrepareReadingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
