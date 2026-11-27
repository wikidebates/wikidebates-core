<?php

require_once __DIR__ . '/argument_context.php';

use MediaWiki\Extension\DebatePHP\SemanticReadResult;

class NativeReadTestContext extends ArgumentTestContext {
	public bool $certify = false;
	public bool $failJson = false;
	public array $skipped = [];
	public function readSemantic(array $query, string $origin): SemanticReadResult {
		$rows = parent::readSemantic($query, $origin)->rows;
		return new SemanticReadResult($rows, $this->certify && $rows === null && in_array($origin, ['debate-parents', 'argument-parents'], true) && str_starts_with($query[1], '('));
	}
	public function skipEmptyParentFallback(string $origin): void { $this->skipped[$origin] = ($this->skipped[$origin] ?? 0) + 2; }
	public function jsonLdData(array $data): string {
		if ($this->failJson) { throw new RuntimeException('Synthetic JSON insertion failure'); }
		return parent::jsonLdData($data);
	}
}
