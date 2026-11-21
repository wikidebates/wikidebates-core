<?php

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

use MediaWiki\Extension\MinervaFileCache\Hooks;
use MediaWiki\Extension\MinervaFileCache\Prewarm;
use MediaWiki\WikiMap\WikiMap;

/**
 * Read-only comparison of cached HTML/header fingerprints.
 * It never changes file contents or the live cache policy.
 */
class VariantsMinervaFileCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Sample exact duplicate mobile cache variants without displaying HTML.');
		$this->requireExtension('MinervaFileCache');
		$this->addOption('limit-pages', 'Maximum page directories to scan (1-50000, default 100).', false, true);
		$this->addOption('max-variants', 'Maximum cache files per page to decode (2-250, default 20).', false, true);
	}

	public function execute() {
		try {
			$limit = Prewarm::number($this->getOption('limit-pages', 100), '--limit-pages', 1, 50000);
			$maxVariants = Prewarm::number($this->getOption('max-variants', 20), '--max-variants', 2, 250);
			$report = Hooks::store()->analyzeVariants($limit, $maxVariants);
			$this->output(json_encode(['wiki' => WikiMap::getCurrentWikiId()] + $report,
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
			return true;
		} catch (\Throwable $e) {
			$this->fatalError('MinervaFileCache variant audit failed: ' . $e->getMessage());
			return false;
		}
	}
}

$maintClass = VariantsMinervaFileCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
