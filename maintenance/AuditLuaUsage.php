<?php

use MediaWiki\Extension\Wikidebates\LuaUsageAudit;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

// Also supports an isolated extraction outside the webroot, without replacing
// the installed extension or its autoload configuration.
require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';
require_once dirname(__DIR__) . '/src/LuaUsageAudit.php';

class AuditLuaUsage extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Read-only Lua usage and stored content-model inventory (all namespaces and current slots).');
		// This inventory only needs MediaWiki core and its explicitly included scanner.
		$this->addOption('after', 'Resume after this page ID; default 0', false, true);
		$this->addOption('limit', 'Maximum current pages; default all', false, true);
		$this->addOption('batch-size', 'Page IDs per SELECT (1-1000; default 200)', false, true);
	}

	public function execute() {
		$after = (int)$this->getOption('after', 0);
		$limit = (int)$this->getOption('limit', PHP_INT_MAX);
		$batch = (int)$this->getOption('batch-size', 200);
		if ($after < 0 || $limit < 1 || $batch < 1 || $batch > 1000) {
			$this->fatalError('Invalid cursor, limit or batch size');
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$emit = function(array $row): void {
			$this->output(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
		};
		$loaded = [];
		foreach (['Wikidebates', 'Scribunto', 'SemanticScribunto', 'VariablesLua', 'SemanticMediaWiki', 'Variables', 'WikiSEO'] as $extension) {
			$loaded[$extension] = ExtensionRegistry::getInstance()->isLoaded($extension);
		}
		$emit(['type' => 'environment', 'wiki' => $db->getDBname(),
			'language' => $services->getContentLanguage()->getCode(), 'extensions' => $loaded,
			'php' => PHP_VERSION, 'mediawiki' => MW_VERSION, 'luasandbox_cli' => extension_loaded('luasandbox')]);
		$summary = LuaUsageAudit::scan($db, $services->getRevisionLookup(), $after, $limit, $batch, $emit);
		$summary += LuaUsageAudit::storedEvidence($db, $emit);
		// No removal decision is inferred from a lexical scan or cached links.
		$summary['requires_manual_review'] = true;
		$emit($summary);
		if ($summary['errors'] || $summary['unreadable']) {
			$this->fatalError('Unreadable content: inventory incomplete; do not disable Lua extensions.');
		}
	}
}

$maintClass = AuditLuaUsage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
