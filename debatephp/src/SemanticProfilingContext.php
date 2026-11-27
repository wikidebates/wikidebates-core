<?php
namespace MediaWiki\Extension\DebatePHP;

/** Optional observations; existing standalone Context implementations stay valid. */
interface SemanticProfilingContext {
	public function smwCacheHit(string $origin, string $layer): void;
}
