<?php

/** Capture exact, ordered, typed case inputs/outputs in blocks for reviewable frozen contracts. */
final class FrozenNativeContract {
	private array $blocks = [];
	private int $count = 0;
	private ?\HashContext $context = null;

	public function __construct(private string $name) {}

	public function add(mixed $input, mixed $output): void {
		$this->context ??= hash_init('sha256');
		hash_update($this->context, serialize([$input, $output]));
		$this->count++;
		if ($this->count % 100 === 0) {
			$this->blocks[] = hash_final($this->context);
			$this->context = null;
		}
	}

	public function report(): void {
		if ($this->context !== null) {
			$this->blocks[] = hash_final($this->context);
			$this->context = null;
		}
		echo 'FROZEN_NATIVE_CONTRACT ' . json_encode([
			'name' => $this->name, 'cases' => $this->count, 'blocks' => $this->blocks
		], JSON_THROW_ON_ERROR) . "\n";
	}
}
