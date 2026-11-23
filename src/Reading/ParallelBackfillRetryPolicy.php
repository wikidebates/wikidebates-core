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
}
