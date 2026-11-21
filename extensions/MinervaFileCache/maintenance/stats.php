<?php

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

use MediaWiki\Extension\MinervaFileCache\Hooks;
use MediaWiki\WikiMap\WikiMap;

/** Read-only storage inventory; do not scan cache files during page requests. */
class StatsMinervaFileCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Report MinervaFileCache storage size and variant counts without reading cached HTML.');
		$this->requireExtension('MinervaFileCache');
	}

	public function execute() {
		try {
			$stats = Hooks::store()->statistics();
		} catch (\Throwable $e) {
			$this->fatalError('MinervaFileCache statistics failed: ' . $e->getMessage());
			return false;
		}
		$this->output(json_encode(['wiki' => WikiMap::getCurrentWikiId()] + $stats,
			JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		return true;
	}
}

$maintClass = StatsMinervaFileCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
