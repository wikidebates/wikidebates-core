<?php

namespace MediaWiki\Extension\MinervaFileCache;

class ResponsePolicy {
	/** Only replay understood end-to-end headers; never store cookies or nonce CSP. */
	public static function headersForStorage(string $html, array $lines, ?string &$reason = null): ?array {
		$reject = static function (string $why) use (&$reason) { $reason = $why; return null; };
		if (strlen($html) < 512 || !preg_match('/<body[^>]*class="[^"]*\bskin-minerva\b/', $html) ||
			!str_contains($html, '</html>')) { return $reject('html'); }
		if (preg_match('/<[^>]+\bnonce\s*=/i', $html)) { return $reject('nonce'); }
		$replay = ['content-type', 'content-language', 'vary', 'link', 'x-frame-options',
			'content-security-policy', 'content-security-policy-report-only', 'referrer-policy',
			'x-content-type-options', 'permissions-policy', 'feature-policy', 'feature-policy-report-only',
			'origin-trial', 'report-to', 'cross-origin-opener-policy', 'cross-origin-resource-policy',
			'cross-origin-embedder-policy'];
		$ignore = ['cache-control', 'expires', 'last-modified', 'etag', 'date', 'server',
			'x-powered-by', 'content-length', 'x-minerva-file-cache', 'x-minerva-file-cache-age'];
		$headers = [];
		foreach ($lines as $line) {
			if (!str_contains($line, ':')) { continue; }
			[$name, $value] = explode(':', $line, 2);
			$name = strtolower(trim($name)); $value = trim($value);
			if ($name === 'cache-control' && preg_match('/no-store|no-cache/i', $value)) { return $reject('cache-control'); }
			if ($name === 'vary') {
				foreach (explode(',', strtolower($value)) as $vary) {
					if (!in_array(trim($vary), ['cookie', 'user-agent', 'accept-encoding', 'accept-language', 'x-subdomain'], true)) {
						return $reject('vary');
					}
				}
			}
			if (str_contains($name, 'content-security-policy') && stripos($value, 'nonce-') !== false) { return $reject('csp-nonce'); }
			if (in_array($name, $replay, true)) { $headers[$name][] = $value; }
			elseif (!in_array($name, $ignore, true)) { return $reject('header-' . preg_replace('/[^a-z0-9-]/', '', $name)); }
		}
		if (!str_starts_with(strtolower($headers['content-type'][0] ?? ''), 'text/html')) { return $reject('content-type'); }
		return $headers;
	}
}
