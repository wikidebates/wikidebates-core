<?php
namespace MediaWiki\Extension\DebatePHP;

/** Native semantic reads; results carry completeness metadata and ordered rows. */
interface NativeSemanticContext {
	public function readSemantic(array $query, string $origin): SemanticReadResult;
	public function skipEmptyParentFallback(string $origin): void;
}
