<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Compatibility shim for historic persisted jobs.
 *
 * Semantic dependent-page purges have been retired. Old queue entries must
 * complete harmlessly instead of invalidating another page's HTML or failing
 * repeatedly in the background. The job type remains registered so MediaWiki
 * can deserialize and acknowledge previously enqueued entries.
 */
final class SemanticCachePurgeJob extends \Job {
	public function __construct($title, array $params = []) {
		parent::__construct('wikidebatesSemanticCachePurge', $title, $params);
		$this->removeDuplicates = true;
	}

	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if (isset($this->params['semanticRevision'])) {
			unset($info['params']['namespace'], $info['params']['title']);
		}
		return $info;
	}

	public function getMetrics(): array {
		return ['status' => 'retired', 'invalidations' => 0];
	}

	public function run(): bool {
		return true;
	}
}
