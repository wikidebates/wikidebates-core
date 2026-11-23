<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Only a small explicit set of transient HTTP failures may be retried.
 * Each attempt must repeat all three phases and all final identity checks;
 * the failed page is never marked complete while waiting.
 */
final class ParallelBackfillRetryPolicy {
	public static function delay(array $result, int $attempt): ?int {
		if (($result['state'] ?? null) !== 'retry' || $attempt < 0 || $attempt >= 2) {
			return null;
		}
		$phases = $result['phases'] ?? null;
		if (!is_array($phases) || $phases === []) { return null; }
		$reason = end($phases);
		if (!is_string($reason)) { return null; }
		if ($reason === 'HTTP_429') {
			return 30 * ($attempt + 1);
		}
		if (in_array($reason, ['HTTP_500', 'HTTP_502', 'HTTP_503', 'HTTP_504'], true)) {
			return 10 * ($attempt + 1);
		}
		return null;
	}
	/**
	 * A page touched or edited during the render is neither a cache success
	 * nor an HTTP failure. Briefly retry after discarding the stale initial
	 * revision, keeping all existing anonymous visibility/ACL checks.
	 *
	 * Never retry arbitrary runtime errors, access denials or policy vetoes.
	 * Returning null leaves the page uncheckpointed for safe intervention.
	 */
	public static function identityDelayMicroseconds(array $result, int $attempt): ?int {
		if ($attempt < 0 || $attempt >= 2 ||
			!in_array($result['state'] ?? null, [
				'retry-page-changed', 'queue-changed-after-preparation'
			], true)) {
			return null;
		}
		return $attempt === 0 ? 250000 : 750000;
	}

}
