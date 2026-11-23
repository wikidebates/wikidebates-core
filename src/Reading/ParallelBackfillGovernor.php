<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Additive increase, rapid decrease for an explicitly requested initial fill.
 * Resource sampling is performed by the supervisor, never by queue hooks.
 * A zero permit count temporarily pauses all new pages while in-flight pages
 * finish their identity-checked cache operations.
 */
final class ParallelBackfillGovernor {
	private int $permitted;
	private int $healthyTicks = 0;

	public function __construct(
		private int $maximum,
		private float $maximumLoad,
		private int $minimumAvailableMb,
		private int $maximumResponseMs
	) {
		if ($maximum < 1 || $maximum > 16 || $maximumLoad <= 0 ||
			$minimumAvailableMb < 128 || $maximumResponseMs < 200) {
			throw new \InvalidArgumentException('Invalid parallel backfill resource limits');
		}
		$this->permitted = min(2, $maximum);
	}

	/**
	 * @param float|null $load 1-minute Linux load average.
	 * @param int|null $availableMb MemAvailable from /proc/meminfo.
	 * @param int|null $responseCode HTTP status, null means unavailable.
	 * @param int|null $responseMs End-to-end public site response duration.
	 */
	public function sample(
		?float $load, ?int $availableMb, ?int $responseCode, ?int $responseMs,
		?array $fpm = null, bool $fpmRequired = false
	): int {
		$unavailable = $load === null || $availableMb === null ||
			$responseCode === null || $responseMs === null;
		$publicProblem = $responseCode === null || $responseCode < 200 ||
			$responseCode >= 400 || $responseMs === null ||
			$responseMs > $this->maximumResponseMs;
		// A configured status endpoint becomes a mandatory signal, while sites
		// without one still rely on the public response-time safeguard.
		$fpmUnhealthy = $fpmRequired && ($fpm === null ||
			!is_int($fpm['listen_queue'] ?? null) ||
			!is_int($fpm['active'] ?? null) ||
			!is_int($fpm['idle'] ?? null) ||
			$fpm['listen_queue'] > 0);
		$hardPressure = $unavailable || $publicProblem || $fpmUnhealthy ||
			$availableMb < $this->minimumAvailableMb ||
			$load > $this->maximumLoad * 1.2;
		$softPressure = $load > $this->maximumLoad;
		if ($hardPressure) {
			$this->permitted = 0;
			$this->healthyTicks = 0;
		} elseif ($softPressure) {
			$this->permitted = max(1, intdiv($this->permitted, 2));
			$this->healthyTicks = 0;
		} else {
			$this->healthyTicks++;
			if ($this->healthyTicks >= 3) {
				$this->permitted = min($this->maximum, max(1, $this->permitted + 1));
				$this->healthyTicks = 0;
			}
		}
		return $this->permitted;
	}

	public function permitted(): int {
		return $this->permitted;
	}
}
