<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Bind a native Vector HTML file to the exact SMW graph observed by its parser.
 *
 * The signed marker is part of the native HTML file, not an independently
 * published sidecar. A file without this trailing marker is never trusted.
 */
final class SemanticVectorFileGuard {
	private const PREFIX = '<!-- wikidebates-vector-semantic:v1:';

	public static function marker(
		string $fingerprint, int $pageId, int $revisionId, string $touched, string $secret
	): string {
		if (!preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) ||
			$pageId < 1 || $revisionId < 1 ||
			!preg_match('/\A[0-9]{14}\z/D', $touched) ||
			$secret === '') {
			throw new \InvalidArgumentException('Invalid Vector semantic file marker inputs');
		}

		$data = implode("\n", ['vector-semantic-v1', (string)$pageId,
			(string)$revisionId, $touched, $fingerprint]);
		return "\n" . self::PREFIX . hash_hmac('sha256', $data, $secret) . ' -->';
	}

	public static function matches(
		string $html, string $fingerprint, int $pageId, int $revisionId,
		string $touched, string $secret
	): bool {
		$expected = ltrim(self::marker($fingerprint, $pageId, $revisionId, $touched, $secret), "\n");
		// Require the signed marker at the very end of the native file.
		// User-supplied HTML and previous snapshots cannot supply this suffix.
		$tail = substr(rtrim($html), -strlen($expected));
		return hash_equals($expected, $tail);
	}
}
