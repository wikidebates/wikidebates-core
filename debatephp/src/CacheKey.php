<?php
namespace MediaWiki\Extension\DebatePHP;

/** Byte-for-byte compatibility with the former query-key builder. */
final class CacheKey {
	public static function semantic(array $values): string {
		$indices = [];
		foreach ($values as $key => $_) { if (is_int($key)) { $indices[] = $key; } }
		sort($indices, SORT_NUMERIC);
		$parts = [];
		foreach ($indices as $key) {
			$value = $values[$key];
			$text = ($value === true ? 'true' : ($value === null || $value === false ? '' : (string)$value));
			$parts[] = strlen($text) . ':' . $text;
		}
		return implode("\n", $parts);
	}
}
