<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Extension\Wikidebates\Reading\SemanticCacheGuard;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\SQLStore;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Read-only timings for query-page classification, semantic fingerprints and
 * the cache-hit guard. No parsing, invalidation, schema writes or job execution.
 */
class MeasureSemanticCacheGuard extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Measure semantic cache guard SQL cost on one existing page, without any write or purge.');
		$this->requireExtension('Wikidebates');
		$this->requireExtension('SemanticMediaWiki');
		$this->addOption('title', 'Existing Argument or Debate title to inspect.', true, true);
		$this->addOption('samples', 'Measured iterations (default 12, maximum 30).', false, true);
		$this->addOption('warmups', 'Warm-up iterations, not included in results (default 2, maximum 5).', false, true);
		$this->addOption('json', 'Emit machine-readable JSON.');
	}

	/** Nearest-rank p95; report wall-clock timings, not artificial QPS. */
	public static function statistics(array $measurements): array {
		if ($measurements === []) {
			throw new InvalidArgumentException('At least one measurement is required.');
		}
		sort($measurements, SORT_NUMERIC);
		$count = count($measurements);
		$round = static fn(float $value): float => round($value, 3);
		$median = $count % 2
			? (float)$measurements[intdiv($count, 2)]
			: ((float)$measurements[intdiv($count, 2) - 1] +
				(float)$measurements[intdiv($count, 2)]) / 2;
		return [
			'count' => $count,
			'median_ms' => $round($median),
			'p95_ms' => $round((float)$measurements[(int)ceil($count * 0.95) - 1]),
			'max_ms' => $round((float)$measurements[$count - 1]),
		];
	}

	public function execute() {
		$samples = filter_var($this->getOption('samples', 12), FILTER_VALIDATE_INT);
		$warmups = filter_var($this->getOption('warmups', 2), FILTER_VALIDATE_INT);
		if ($samples === false || $samples < 1 || $samples > 30 ||
			$warmups === false || $warmups < 0 || $warmups > 5) {
			$this->fatalError('Expected --samples 1..30 and --warmups 0..5.');
		}
		$title = Title::newFromText((string)$this->getOption('title'));
		if (!$title || !$title->canExist()) {
			$this->fatalError('Invalid title for semantic measurement.');
		}
		$services = MediaWikiServices::getInstance();
		$page = $services->getWikiPageFactory()->newFromTitle($title);
		if (!$page->exists()) {
			$this->fatalError('This page does not exist. Nothing was measured.');
		}
		$semantic = SemanticServices::getInstance();
		$store = $semantic->getStore();
		if (!$store instanceof SQLStore) {
			$this->fatalError('Semantic cache measurement requires SMW SQLStore.');
		}
		$db = $store->getConnection('mw.db');
		if (!$db->tableExists(SemanticRevisionStore::TABLE, __METHOD__)) {
			$this->fatalError('Semantic revision table missing. Run the standard MediaWiki schema update first.');
		}
		$subject = SemanticWikiPage::newFromTitle($title);
		$profiles = count($store->getPropertyValues($subject, new Property('_ASK')));
		$timings = [
			'query_page_classification' => [],
			'semantic_fingerprint' => [],
			'cache_read_guard' => [],
		];
		$fingerprints = [];
		$isQueryPage = false;
		$guardSafe = false;
		try {
			for ($i = -$warmups; $i < $samples; $i++) {
				$start = hrtime(true);
				$isQueryPage = SemanticPageCacheHooks::isQueryPage($title, $page);
				$classificationMs = (hrtime(true) - $start) / 1000000;

				$start = hrtime(true);
				$fingerprint = SemanticRevisionStore::fingerprint($store, $subject);
				$fingerprintMs = (hrtime(true) - $start) / 1000000;

				$start = hrtime(true);
				$revisionFromGuard = null;
				$guardSafe = SemanticCacheGuard::safeForRead($title, $page, $revisionFromGuard);
				$guardMs = (hrtime(true) - $start) / 1000000;

				if ($i >= 0) {
					$timings['query_page_classification'][] = $classificationMs;
					$timings['semantic_fingerprint'][] = $fingerprintMs;
					$timings['cache_read_guard'][] = $guardMs;
					$fingerprints[$fingerprint] = true;
					if ($guardSafe && $revisionFromGuard !== $fingerprint) {
						throw new RuntimeException('Semantic guard and fingerprint disagree in the same sample.');
					}
				}
			}
		} catch (Throwable $e) {
			// A read failure should never trigger an invalidation or leak SQL
			// connection strings, credentials, or page content.
			$this->fatalError('Read-only semantic measurement failed: ' . get_class($e));
		}
		$stats = [];
		foreach ($timings as $stage => $samplesMs) {
			$stats[$stage] = self::statistics($samplesMs);
		}
		$config = $services->getMainConfig();
		$report = [
			'title' => $title->getPrefixedText(),
			'type' => 'semantic_cache_guard_timings',
			'unit' => 'milliseconds',
			'samples' => $samples,
			'warmups' => $warmups,
			'tracking_enabled' => (bool)$config->get('WikidebatesSemanticCacheTracking'),
			'eager_purge_enabled' => (bool)$config->get('WikidebatesSemanticCacheEagerPurge'),
			'ask_profile_count' => $profiles,
			'is_query_page' => $isQueryPage,
			'guard_safe_to_read' => $guardSafe,
			'fingerprints_stable' => count($fingerprints) === 1,
			'stages' => $stats,
			'scope' => 'Read-only in-process timings of individual SQL/SMW checks. Not total HTTP latency, CDN overhead or a full parser benchmark.'
		];
		if ($this->hasOption('json')) {
			$this->output(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
		} else {
			$this->output('Semantic cache guard timing: ' . $title->getPrefixedText() . "\n");
			foreach ($stats as $stage => $measurement) {
				$this->output($stage . ': median ' . $measurement['median_ms'] .
					' ms, p95 ' . $measurement['p95_ms'] . ' ms' . "\n");
			}
			$this->output('Note: these timings exclude page rendering and the CDN.' . "\n");
		}
	}
}

$maintClass = MeasureSemanticCacheGuard::class;
require_once RUN_MAINTENANCE_IF_MAIN;
