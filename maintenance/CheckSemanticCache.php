<?php

use MediaWiki\MediaWikiServices;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\SQLStore;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/** Configuration/schema inspection only; never reads or consumes the job queue. */
class CheckSemanticCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Check semantic cache configuration and tables without parsing, purging or consuming jobs.');
		$this->requireExtension('Wikidebates');
		$this->requireExtension('SemanticMediaWiki');
		$this->addOption('require-purge-ready', 'Exit nonzero if manual purge prerequisites are not met.');
		$this->addOption('json', 'Emit a machine-readable JSON report.');
	}

	/** Public for integration tests against the real MediaWiki configuration/database. */
	public function inspectConfiguration(): array {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$semantic = SemanticServices::getInstance();
		$settings = [];
		foreach ([
			'WikidebatesSemanticCacheTracking',
			'WikidebatesSemanticCacheEagerPurge',
			'WikidebatesSemanticCachePurgeBatchSize',
			'WikidebatesSemanticCachePurgeLog'
		] as $key) {
			$settings[$key] = $config->get($key);
		}
		$settings['smwgEnabledQueryDependencyLinksStore'] =
			$semantic->getSettings()->get('smwgEnabledQueryDependencyLinksStore');
		$settings['smwgQueryProfiler'] = $semantic->getSettings()->get('smwgQueryProfiler');
		// These extension tables are installed in the wiki's primary database.
		// Do not report a just-installed table absent due to replica lag.
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$tables = [];
		foreach (['wd_smw_entity_revisions', 'wd_smw_purge_state', 'smw_query_links', 'smw_object_ids'] as $table) {
			$tables[$table] = $db->tableExists($table, __METHOD__);
		}
		$blockers = [];
		if (!$settings['WikidebatesSemanticCacheTracking']) {
			$blockers[] = 'Semantic tracking is disabled.';
		}
		if (!$settings['WikidebatesSemanticCacheEagerPurge']) {
			$blockers[] = 'EagerPurge is disabled; running purge jobs would acknowledge them without purging.';
		}
		if (!$semantic->getStore() instanceof SQLStore) {
			$blockers[] = 'SMW SQLStore is required.';
		}
		if (!$settings['smwgEnabledQueryDependencyLinksStore']) {
			$blockers[] = 'SMW query dependency tracking is disabled.';
		}
		// Integer 0 is valid; only boolean false disables SMW query profiling.
		if ($settings['smwgQueryProfiler'] === false) {
			$blockers[] = 'SMW query profiling is disabled.';
		}
		foreach (['wd_smw_entity_revisions', 'smw_query_links', 'smw_object_ids'] as $table) {
			if (!$tables[$table]) {
				$blockers[] = 'Required table missing: ' . $table;
			}
		}
		return [
			'settings' => $settings,
			'tables' => $tables,
			'effective_batch_size' => max(1, min(5000, (int)$settings['WikidebatesSemanticCachePurgeBatchSize'])),
			'purge_preconditions_met' => $blockers === [],
			'coalescing_table_available' => $tables['wd_smw_purge_state'],
			'blockers' => $blockers,
			'warnings' => $tables['wd_smw_purge_state'] ? [] : [
				'Optional receipt table missing: jobs remain executable, but completed purges cannot be shared between batches.'
			],
			'scope' => 'Configuration and table existence only; not an audit of page freshness, filesystem permissions, workers or CDN.'
		];
	}

	public function execute() {
		try {
			$report = $this->inspectConfiguration();
		} catch (Throwable $e) {
			// Never dump SQL, credentials or filesystem paths in a diagnostic.
			$this->fatalError('Semantic cache check failed (' . get_class($e) . ').');
		}
		if ($this->hasOption('json')) {
			$this->output(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		} else {
			foreach ($report['settings'] as $key => $value) {
				$this->output($key . ' = ' . json_encode($value) . "\n");
			}
			foreach ($report['tables'] as $table => $exists) {
				$this->output($table . ' = ' . ($exists ? 'OK' : 'ABSENTE') . "\n");
			}
			$this->output('effective_batch_size = ' . $report['effective_batch_size'] . "\n");
			$this->output('purge_preconditions_met = ' . json_encode($report['purge_preconditions_met']) . "\n");
			foreach (array_merge($report['blockers'], $report['warnings'], [$report['scope']]) as $message) {
				$this->output($message . "\n");
			}
		}
		if ($this->hasOption('require-purge-ready') && !$report['purge_preconditions_met']) {
			$this->fatalError('Purge prerequisites are not met. No jobs were consumed.');
		}
	}
}

$maintClass = CheckSemanticCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
