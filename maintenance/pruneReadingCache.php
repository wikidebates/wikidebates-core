<?php

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

class PruneWikidebatesReadingCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension('Wikidebates');
		$this->addDescription('Remove expired or damaged Wikidebates reading fragments.');
	}
	public function execute() {
		$count = \MediaWiki\Extension\Wikidebates\Reading\FragmentService::store()->prune();
		$this->output("Removed $count reading cache files.\n");
	}
}
$maintClass = PruneWikidebatesReadingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
