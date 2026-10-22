<?php

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

class PruneMinervaFileCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Remove expired MinervaFileCache payloads for this wiki.');
		$this->requireExtension('MinervaFileCache');
	}
	public function execute() {
		$count = \MediaWiki\Extension\MinervaFileCache\Hooks::store()->prune();
		$this->output("Removed $count expired or damaged Minerva cache payloads.\n");
	}
}
$maintClass = PruneMinervaFileCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
