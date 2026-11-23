<?php

/** Old queued SMW-dependent purges must complete without modifying any caches. */
class Job {
	protected array $params;
	protected bool $removeDuplicates = false;
	public function __construct(string $type, $title, array $params = []) {
		$this->params = $params;
	}
	public function getDeduplicationInfo() {
		return ['params' => ['namespace' => 0, 'title' => 'old'] + $this->params];
	}
	public function ignoreDuplicates(): bool {
		return $this->removeDuplicates;
	}
}

require dirname(__DIR__, 2) . '/src/Reading/SemanticCachePurgeJob.php';

use MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob;

$old = new SemanticCachePurgeJob(null, [
	'dependencyIds' => [10, 20], 'cursor' => 30, 'semanticRevision' => 'old-token'
]);
if (!$old->ignoreDuplicates() || $old->run() !== true ||
	$old->getMetrics()['invalidations'] !== 0 ||
	$old->getMetrics()['status'] !== 'retired') {
	throw new RuntimeException('A historic semantic job could mutate cached HTML');
}
if (isset($old->getDeduplicationInfo()['params']['namespace'])) {
	throw new RuntimeException('Legacy stamped deduplication identity changed');
}
echo "OK: obsolete semantic purge acknowledged without cache invalidation\n";
