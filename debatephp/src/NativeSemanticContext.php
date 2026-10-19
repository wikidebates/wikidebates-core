<?php
namespace MediaWiki\Extension\DebatePHP;

/** Optional read-only path. Existing Context implementations keep the Table API. */
interface NativeSemanticContext {
	public function readSemantic(Table $query, string $origin): SemanticReadResult;
	public function skipEmptyParentFallback(string $origin): void;
}
