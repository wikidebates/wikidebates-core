<?php

require_once __DIR__ . '/argument_context.php';

use MediaWiki\Extension\DebatePHP\{JsonData, NativeJsonContext, NativeSemanticContext, SemanticReadResult, Table};

class NativeReadTestContext extends ArgumentTestContext implements NativeSemanticContext, NativeJsonContext {
	public bool $certify = false;
	public bool $failJson = false;
	public array $skipped = [];
	public function readSemantic(Table $query, string $origin): SemanticReadResult {
		$rows = Table::unwrap($this->smwAsk($query));
		return new SemanticReadResult($rows, $this->certify && $rows === null && in_array($origin, ['debate-parents', 'argument-parents'], true) && str_starts_with($query[1], '('));
	}
	public function skipEmptyParentFallback(string $origin): void { $this->skipped[$origin] = ($this->skipped[$origin] ?? 0) + 2; }
	public function jsonLdData(array $data): string {
		if ($this->failJson) { throw new RuntimeException('Synthetic JSON insertion failure'); }
		return $this->jsonLd(json_encode(JsonData::value($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}
}
