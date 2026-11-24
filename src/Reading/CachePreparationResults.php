<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Parse explicit success states from the three maintenance commands.
 * Process exit code 0 is necessary but NOT sufficient for acknowledgement:
 * Minerva can return zero even when HTTP is not cacheable.
 */
final class CachePreparationResults {
	/**
	 * Keep useful bounded diagnostics from failed Vector child processes.
	 * Only report our known internal codes, never stderr, raw URLs, titles,
	 * exception messages or arbitrary page output.
	 */
	public static function knownVectorFailure(int $pageId, string $stdout): ?string {
		$report = json_decode(trim($stdout), true);
		if (!is_array($report) || ($report['pageId'] ?? null) !== $pageId) {
			return null;
		}
		$status = $report['state'] ?? null;
		return is_string($status) && in_array($status, [
			'REJECTED_SHORT_OUTPUT',
			'RETRY_PUBLIC_IDENTITY_CHANGED',
			'RETRY_PAGE_CHANGED',
			'WRITE_FAILED',
			'RETRY_MISSING_TOC',
			'RETRY_LEGACY_SHORTDESC'
		], true) ? $status : null;
	}

	/**
	 * @return array{ok:bool,status:string}
	 */
	public static function interpret(string $phase, int $pageId, int $exit, string $stdout): array {
		if ($exit !== 0) {
			return ['ok' => false, 'status' => 'command-exit-' . $exit];
		}
		if ($phase === 'vector') {
			$report = json_decode(trim($stdout), true);
			if (!is_array($report) || ($report['pageId'] ?? null) !== $pageId) {
				return ['ok' => false, 'status' => 'invalid-vector-report'];
			}
			$status = (string)($report['state'] ?? '');
			return [
				'ok' => in_array($status, ['CREATED', 'REUSED', 'SKIPPED_NONCONTENT', 'SKIPPED_NONPUBLIC',
					'SKIPPED_CACHE_POLICY', 'SKIPPED_REDIRECT_OR_INVALID', 'SKIPPED_SMW_ARTICLE',
					'SKIPPED_FILENAME_TOO_LONG', 'SKIPPED_MISSING_VIEW'], true),
				'status' => $status ?: 'empty-vector-status'
			];
		}
		if ($phase === 'reading') {
			$report = json_decode(trim($stdout), true);
			if (!is_array($report) || ($report['target_page_id'] ?? null) !== $pageId ||
				($report['complete'] ?? null) !== true ||
				!in_array($report['inspected'] ?? null, [0, 1], true)) {
				return ['ok' => false, 'status' => 'invalid-reading-report'];
			}
			if ($report['inspected'] === 0) {
				return ['ok' => true, 'status' => 'not-an-argument'];
			}
			$statuses = (array)($report['statuses'] ?? []);
			// Deferred preparation is a deliberate no-op, not a cache failure.
			$accepted = ['created', 'already-valid', 'skipped', 'not-cacheable', 'deferred-no-html'];
			$ok = 0;
			$status = '';
			foreach ($statuses as $name => $count) {
				if (!is_int($count) || $count < 0) {
					return ['ok' => false, 'status' => 'invalid-reading-count'];
				}
				if ($count === 0) { continue; }
				if ($count !== 1 || !in_array($name, $accepted, true) || $ok !== 0) {
					return ['ok' => false, 'status' => 'reading-not-prepared'];
				}
				$ok++;
				$status = $name;
			}
			return ['ok' => $ok === 1, 'status' => $status ?: 'reading-not-prepared'];
		}
		if ($phase === 'minerva') {
			if (!preg_match('/^Completed ([0-9]+) pages; (\{[^\r\n]*\})$/mD',
				trim($stdout), $match) || (int)$match[1] !== 1) {
				return ['ok' => false, 'status' => 'invalid-minerva-report'];
			}
			$stats = json_decode($match[2], true);
			if (!is_array($stats)) {
				return ['ok' => false, 'status' => 'invalid-minerva-json'];
			}
			$nonzero = [];
			foreach ($stats as $name => $count) {
				if ($name === 'bypassReasons') { continue; }
				if (!is_int($count) || $count < 0) {
					return ['ok' => false, 'status' => 'invalid-minerva-count'];
				}
				if ($count > 0) { $nonzero[$name] = $count; }
			}
			if ($nonzero === ['HIT' => 1] || $nonzero === ['MISS' => 1] ||
				$nonzero === ['LOCAL_HIT' => 1]) {
				return ['ok' => true, 'status' => (string)array_key_first($nonzero)];
			}
			// SMW's own ArticleFromTitle handler replaces our mobile adapter on
			// Property and Concept pages. The Minerva CLI verifies this locally
			// without HTTP; only this explicit safe skip may advance the cursor.
			if ($nonzero === ['SKIPPED_SMW_ARTICLE' => 1]) {
				return ['ok' => true, 'status' => 'SKIPPED_SMW_ARTICLE'];
			}
			// Flow boards deliberately have zero parser-cache lifetime and cannot
			// produce a persistent anonymous Minerva snapshot. The CLI verifies
			// their exact page content model without issuing a mobile HTTP GET.
			if ($nonzero === ['SKIPPED_FLOW_BOARD' => 1]) {
				return ['ok' => true, 'status' => 'SKIPPED_FLOW_BOARD'];
			}
			if ($nonzero === ['BYPASS' => 1]) {
				$reasons = $stats['bypassReasons'] ?? [];
				// Only a single well-formed diagnostic reason is safe to include in reports.
				if (is_array($reasons) && count($reasons) === 1) {
					$reason = (string)array_key_first($reasons);
					if (($reasons[$reason] ?? null) === 1 &&
						preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $reason)) {
						// Explicit policy exclusions can complete. A Set-Cookie response
						// cannot be stored in a shared anonymous HTML cache, so treating
						// that refusal as a safe skip avoids pinning the daily cursor.
						// Never accept storage errors, busy locks or unknown headers.
						$safe = in_array($reason, [
							'not-public', 'parser-expiry', 'extension-veto', 'page', 'redirect',
							'header-set-cookie'
						], true);
						return ['ok' => $safe, 'status' => 'BYPASS-' . $reason];
					}
				}
				return ['ok' => false, 'status' => 'BYPASS-invalid-reason'];
			}
			// A successful prewarm process can still receive an HTTP error, a
			// CDN response without the diagnostic header, or an unknown header.
			// Preserve only a bounded, known state; never leak response bodies or URLs.
			if (count($nonzero) === 1) {
				$state = (string)array_key_first($nonzero);
				if ($nonzero[$state] === 1 && (
					in_array($state, ['NO_HEADER', 'UNKNOWN_HEADER'], true) ||
					preg_match('/\AHTTP_[1-5][0-9]{2}\z/D', $state)
				)) {
					return ['ok' => false, 'status' => $state];
				}
			}
			return ['ok' => false, 'status' => 'minerva-not-prepared'];
		}
		throw new \InvalidArgumentException('Unknown cache preparation phase');
	}
}
