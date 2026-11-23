<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Persistent incident for a daily scan unable to advance or safely defer a
 * page. Separate from per-page errors and parallel supervisor checkpoints.
 */
final class DailyCacheScanIncident {
	private string $path;
	private string $directory;

	public function __construct(CachePreparationQueue $queue) {
		$this->directory = $queue->parallelDirectory();
		$this->path = $this->directory . '/daily-scan-incident.json';
	}

	/** @return array<string,mixed>|null */
	private function read(): ?array {
		if (is_link($this->path)) {
			throw new \RuntimeException('Symlinked daily cache incident');
		}
		if (!is_file($this->path)) {
			return null;
		}
		clearstatcache(true, $this->path);
		if (filesize($this->path) > 2048) {
			throw new \RuntimeException('Oversized daily cache incident');
		}
		$raw = @file_get_contents($this->path);
		$state = $raw === false ? null : json_decode($raw, true);
		if (!is_array($state) || ($state['version'] ?? null) !== 1 ||
			!in_array($state['state'] ?? null, ['failed', 'resolved'], true) ||
			!in_array($state['reason'] ?? null,
				['retry-required', 'systemic-page-errors'], true) ||
			!is_int($state['page_id'] ?? null) || $state['page_id'] < 0 ||
			!is_int($state['at'] ?? null) || $state['at'] < 1 ||
			!is_int($state['resolved_at'] ?? null) && ($state['resolved_at'] ?? 'invalid') !== null) {
			throw new \RuntimeException('Malformed daily cache incident');
		}
		return $state;
	}

	private function write(array $value): void {
		if (is_link($this->path)) {
			throw new \RuntimeException('Symlinked daily cache incident');
		}
		$payload = json_encode($value, JSON_THROW_ON_ERROR);
		$temp = @tempnam($this->directory, '.daily-scan-');
		if ($temp === false || dirname($temp) !== $this->directory) {
			throw new \RuntimeException('Cannot allocate daily cache incident');
		}
		try {
			if (!@chmod($temp, 0600) ||
				@file_put_contents($temp, $payload) !== strlen($payload) ||
				!@rename($temp, $this->path)) {
				throw new \RuntimeException('Cannot persist daily cache incident');
			}
		} finally {
			if (is_file($temp)) { @unlink($temp); }
		}
	}

	/** Called only by the daily scan worker, after acquiring the per-wiki lock. */
	public function failed(string $reason, int $pageId): void {
		if (!in_array($reason, ['retry-required', 'systemic-page-errors'], true) ||
			$pageId < 0) {
			throw new \InvalidArgumentException('Invalid daily cache failure');
		}
		$old = $this->read();
		if ($old !== null && $old['state'] === 'failed' &&
			$old['reason'] === $reason && $old['page_id'] === $pageId) {
			// Stable signature across hourly checks and nightly retries.
			return;
		}
		$this->write([
			'version' => 1, 'state' => 'failed',
			'reason' => $reason, 'page_id' => $pageId,
			'at' => time(), 'resolved_at' => null
		]);
	}

	/** A later successful scan proves that the blocking page was passed. */
	public function resolved(): void {
		$old = $this->read();
		if ($old !== null && $old['state'] === 'failed') {
			$old['state'] = 'resolved';
			$old['resolved_at'] = time();
			$this->write($old);
		}
	}

	/** @return array<string,int|string>|null Safe incident for existing aggregated mailer. */
	public function incident(): ?array {
		$state = $this->read();
		if ($state === null || $state['state'] !== 'failed') {
			return null;
		}
		return [
			'type' => 'daily-scan-failed',
			'reason' => $state['reason'],
			'lane' => -1,
			'page_id' => $state['page_id'],
			'at' => $state['at'],
			'key' => hash('sha256', 'daily:' . $state['reason'] . ':' .
				$state['page_id'] . ':' . $state['at'])
		];
	}
}
