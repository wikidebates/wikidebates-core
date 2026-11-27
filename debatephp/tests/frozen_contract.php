<?php

/** Verify exact, ordered, typed case inputs/outputs against independently captured contracts. */
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

	public function verify(): void {
		if ($this->context !== null) {
			$this->blocks[] = hash_final($this->context);
			$this->context = null;
		}
		$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/native-boundary-contract.json'), true, 512, JSON_THROW_ON_ERROR);
		same(1, $fixture['format'], 'Frozen boundary contract format');
		same(100, $fixture['block_size'], 'Frozen boundary contract block size');
		$expected = $fixture['contracts'][$this->name];
		same($expected['cases'], $this->count, $this->name . ': case count');
		same(count($expected['blocks']), count($this->blocks), $this->name . ': block count');
		foreach ($this->blocks as $index => $digest) {
			same($expected['blocks'][$index], $digest, $this->name . ': block ' . ($index + 1));
		}
		echo "PASS: {$this->name}: {$this->count} frozen cases\n";
	}
}
