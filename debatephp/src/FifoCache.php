<?php
namespace MediaWiki\Extension\DebatePHP;

/** Shared FIFO identity, with constant-time eviction and Lua nil compatibility. */
final class FifoCache {
	private array $data = [];
	private \SplQueue $order;
	public readonly mixed $max;

	public function __construct($max = null) {
		$this->max = $max === null || $max === false ? 200 : $max;
		$this->order = new \SplQueue;
	}

	public function get($key) {
		return $this->data[$key] ?? null;
	}

	public function put($key, $value): void {
		$present = isset($this->data[$key]);
		if ($value === null) { unset($this->data[$key]); }
		else { $this->data[$key] = $value; }
		if ($present) { return; }
		// In the source Table, inserting nil does not add an order entry.
		if ($key !== null) { $this->order->enqueue($key); }
		if ($this->order->count() > $this->max) {
			$old = $this->order->isEmpty() ? null : $this->order->dequeue();
			if ($old !== null) { unset($this->data[$old]); }
		}
	}
}
