<?php
namespace MediaWiki\Extension\DebatePHP;

/** Encode internal renderer data and insert it in one operation; external JSON stays validated. */
interface NativeJsonContext {
	public function jsonLdData(array $data): string;
}
