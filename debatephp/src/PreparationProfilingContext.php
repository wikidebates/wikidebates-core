<?php
namespace MediaWiki\Extension\DebatePHP;

/** Optional, numeric-only observations of pure preprocessing. */
interface PreparationProfilingContext {
	public function profilePreparation(array $statistics): void;
}
