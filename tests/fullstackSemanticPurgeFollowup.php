<?php

/** Run all genuine purge/cache regressions before the additional checks. */
require __DIR__ . '/fullstackSemanticPurge.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob;
use MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeState;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logger\Spi;

same(2, $first->getMetrics()['generation_reads'], 'Real cold purge omits two unnecessary generation reads');
same(2, $overlap->getMetrics()['generation_reads'], 'Real overlap still checks both local generations');
same(null, $first->getMetrics()['next_cursor'], 'Real final batch advertises no successor');

// Only the semantic purge logger is faulty; core and unrelated channels keep
// their genuine providers. Test both a failing sink and a failing factory.
$originalProvider = LoggerFactory::getProvider();
$faultyProvider = new class($originalProvider) implements Spi {
	public bool $failFactory = false;
	public int $calls = 0;

	public function __construct(private Spi $fallback) {}

	public function getLogger($channel) {
		if ($channel !== 'WikidebatesSemanticCache') {
			return $this->fallback->getLogger($channel);
		}
		$this->calls++;
		if ($this->failFactory) {
			throw new RuntimeException('Injected logger factory failure');
		}
		return new class extends \Psr\Log\AbstractLogger {
			public function log($level, $message, array $context = []): void {
				throw new RuntimeException('Injected logger sink failure');
			}
		};
	}
};
$logBefore = $GLOBALS['wgWikidebatesSemanticCachePurgeLog'];
$GLOBALS['wgWikidebatesSemanticCachePurgeLog'] = true;
$throwing = null;
try {
	LoggerFactory::registerProvider($faultyProvider);
	SemanticRevisionStore::record($store, [$depId]);
	$successful = $run([$depId]);
	same(2, $successful->getMetrics()['invalidations'], 'Real invalidations complete with a broken log sink');
	same(1, $successful->getMetrics()['logging_failures'], 'Sink failure cannot replace a successful result');
	$faultyProvider->failFactory = true;
	SemanticRevisionStore::record($store, [$depId]);
	$successful = $run([$depId]);
	same(1, $successful->getMetrics()['logging_failures'], 'Factory failure cannot replace a successful result');

	SemanticRevisionStore::record($store, [$depId]);
	$throwing = $services->getHookContainer()->scopedRegister('TitleSquidURLs',
		static function(): void { throw new LogicException('Injected purge failure, not logger failure'); });
	$failed = new SemanticCachePurgeJob($owners[0], ['dependencyIds' => [$depId], 'cursor' => 0]);
	check(!$failed->run(), 'Purge failure remains false even when both error/info logs fail');
	same(LogicException::class, $failed->getLastError(), 'Original purge exception class is preserved');
	same(2, $failed->getMetrics()['logging_failures'], 'Both diagnostic failures are contained');
	same(null, $failed->getMetrics()['next_cursor'], 'No progress is advertised after a failed purge');
} finally {
	$throwing = null;
	LoggerFactory::registerProvider($originalProvider);
	$GLOBALS['wgWikidebatesSemanticCachePurgeLog'] = $logBefore;
}
same(2, $run([$depId])->getMetrics()['invalidations'], 'Retry after combined purge/logger failure still works');

// The new maintenance inspector uses real primary table-existence reads and
// preserves the production convention that profiler integer 0 is enabled.
require_once __DIR__ . '/../maintenance/CheckSemanticCache.php';
$inspector = new CheckSemanticCache;
$queue = $services->getJobQueueGroup()->get('wikidebatesSemanticCachePurge');
$queueBefore = $queue->getSize();
$ledgerBefore = SemanticPurgeState::revisions($store, [$depId]);
$report = $inspector->inspectConfiguration();
check($report['job_class_configured'] && $report['job_factory_ready'],
	'Real MediaWiki JobFactory can construct the registered semantic purge job');
check($report['purge_preconditions_met'] && $report['coalescing_table_available'],
	'Installed real database and enabled configuration meet the limited preconditions');
same(0, $report['settings']['smwgQueryProfiler'], 'Integer zero is not strict boolean false');
same(1000, $report['effective_batch_size'], 'Inspector matches the worker batch size');
$jobClassesBefore = $GLOBALS['wgJobClasses'];
try {
	unset($GLOBALS['wgJobClasses']['wikidebatesSemanticCachePurge']);
	$missing = $inspector->inspectConfiguration();
	check(!$missing['job_class_configured'] && !$missing['purge_preconditions_met'],
		'Missing effective job registration cannot pass the purge preflight');
} finally {
	$GLOBALS['wgJobClasses'] = $jobClassesBefore;
}
$eagerBefore = $GLOBALS['wgWikidebatesSemanticCacheEagerPurge'];
$trackingBefore = $GLOBALS['wgWikidebatesSemanticCacheTracking'];
try {
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = false;
	$disabledReport = $inspector->inspectConfiguration();
	check(!$disabledReport['purge_preconditions_met'] && $disabledReport['blockers'] !== [],
		'Disabled EagerPurge prevents a ready result');
	$disabledJob = new SemanticCachePurgeJob($owners[0], [
		'dependencyIds' => [$depId], 'cursor' => 0
	]);
	check(!$disabledJob->run() && $disabledJob->allowRetries() &&
		$disabledJob->getMetrics()['status'] === 'disabled',
		'Actual MediaWiki must not acknowledge disabled semantic purges');
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = true;
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = false;
	check(!$inspector->inspectConfiguration()['purge_preconditions_met'],
		'Disabled tracking prevents a ready result');
} finally {
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = $eagerBefore;
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = $trackingBefore;
}
same($queueBefore, $queue->getSize(), 'Inspection never consumes or creates jobs');
same($ledgerBefore, SemanticPurgeState::revisions($store, [$depId]), 'Inspection never advances semantic revisions');

echo json_encode(['backend' => $backend, 'cold_generation_reads' => $first->getMetrics()['generation_reads'],
	'overlap_generation_reads' => $overlap->getMetrics()['generation_reads'],
	'logger_failure_checks' => 'PASS', 'configuration_inspector_checks' => 'PASS'], JSON_THROW_ON_ERROR) . "\n";
echo "OK: actual logger failures, unchanged purge retries and read-only configuration inspection\n";
