<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Pure checkpoint state for a fixed set of disjoint, ordered page-ID ranges.
 * Each lane processes its own range strictly in ascending page-ID order.
 * This class does not acquire locks or write files: the caller MUST serialize
 * updates, durably persist each returned snapshot, and lock out daily sweeps.
 */
final class ParallelBackfillState {
	/** @param array<int,array{start:int,end:int,after:int,done:bool}> $lanes */
	public function __construct(private array $lanes) {
		if ($lanes === []) {
			throw new \InvalidArgumentException('At least one lane is required');
		}
		$previousEnd = null;
		foreach ($lanes as $index => $lane) {
			if (!is_int($index) || !isset($lane['start'], $lane['end'], $lane['after'], $lane['done']) ||
				!is_int($lane['start']) || !is_int($lane['end']) || !is_int($lane['after']) ||
				!is_bool($lane['done']) || $lane['start'] < 0 || $lane['end'] <= $lane['start'] ||
				$lane['after'] < $lane['start'] || $lane['after'] > $lane['end'] ||
				($lane['done'] && $lane['after'] !== $lane['end']) ||
				($previousEnd !== null && $lane['start'] !== $previousEnd)) {
				throw new \InvalidArgumentException('Invalid or non-contiguous lane interval');
			}
			$previousEnd = $lane['end'];
		}
		if (array_keys($lanes) !== range(0, count($lanes) - 1)) {
			throw new \InvalidArgumentException('Lane indices must be contiguous');
		}
	}

	/** Evenly partition a fixed ID interval; no page ID may belong to two lanes. */
	public static function create(int $after, int $last, int $workers): self {
		if ($after < 0 || $last <= $after || $workers < 1 || $workers > 64 ||
			$workers > $last - $after) {
			throw new \InvalidArgumentException('Invalid backfill bounds or worker count');
		}
		$size = intdiv($last - $after, $workers);
		$extra = ($last - $after) % $workers;
		$lanes = [];
		$start = $after;
		for ($i = 0; $i < $workers; $i++) {
			$end = $start + $size + ($i < $extra ? 1 : 0);
			$lanes[] = ['start' => $start, 'end' => $end, 'after' => $start, 'done' => false];
			$start = $end;
		}
		return new self($lanes);
	}

	/** Call only after the named page has fully passed every cache phase. */
	public function completed(int $lane, int $pageId): self {
		$lanes = $this->lanes;
		if (!isset($lanes[$lane]) || $lanes[$lane]['done'] ||
			$pageId <= $lanes[$lane]['after'] || $pageId > $lanes[$lane]['end']) {
			throw new \InvalidArgumentException('Completion is outside the lane or is not increasing');
		}
		$lanes[$lane]['after'] = $pageId;
		return new self($lanes);
	}

	/** Call only after an authoritative query proves no unprocessed IDs remain. */
	public function exhausted(int $lane): self {
		$lanes = $this->lanes;
		if (!isset($lanes[$lane])) {
			throw new \InvalidArgumentException('Unknown lane');
		}
		$lanes[$lane]['done'] = true;
		$lanes[$lane]['after'] = $lanes[$lane]['end'];
		return new self($lanes);
	}

	/** A global daily cursor must never pass a non-exhausted earlier range. */
	public function contiguousAfter(): int {
		foreach ($this->lanes as $lane) {
			if (!$lane['done']) {
				return $lane['after'];
			}
		}
		return $this->lanes[array_key_last($this->lanes)]['end'];
	}

	public function finished(): bool {
		foreach ($this->lanes as $lane) {
			if (!$lane['done']) {
				return false;
			}
		}
		return true;
	}

	/** @return array<int,array{start:int,end:int,after:int,done:bool}> */
	public function lanes(): array {
		return $this->lanes;
	}
}
