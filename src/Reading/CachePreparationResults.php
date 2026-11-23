<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Parse explicit success states from the three maintenance commands.
 * Process exit code 0 is necessary but NOT sufficient for acknowledgement:
 * Minerva can return zero even when HTTP is not cacheable.
 */
final class CachePreparationResults {
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
					'SKIPPED_CACHE_POLICY', 'SKIPPED_REDIRECT_OR_INVALID'], true),
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
			if ($nonzero === ['BYPASS' => 1]) {
				$reasons = $stats['bypassReasons'] ?? [];
				// Deliberate public-cache policy vetoes may be skipped. Storage
				// failure, busy locks and unknown bypass reasons must retry.
				if (is_array($reasons) && count($reasons) === 1) {
					$reason = (string)array_key_first($reasons);
					if (($reasons[$reason] ?? null) === 1 &&
						in_array($reason, [
							'not-public', 'parser-expiry', 'extension-veto', 'page', 'redirect'
						], true)) {
						return ['ok' => true, 'status' => 'BYPASS-' . $reason];
					}
				}
			}
			return ['ok' => false, 'status' => 'minerva-not-prepared'];
		}
		throw new \InvalidArgumentException('Unknown cache preparation phase');
	}
}
