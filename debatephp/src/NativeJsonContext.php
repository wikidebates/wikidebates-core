<?php
namespace MediaWiki\Extension\DebatePHP;

/** Mandatory renderer boundary: encode native schema data and insert it safely. */
interface NativeJsonContext {
	public function jsonLdData(array $data): string;
}
