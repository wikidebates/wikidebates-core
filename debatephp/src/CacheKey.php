<?php
namespace MediaWiki\Extension\DebatePHP;

/** Byte-for-byte compatibility with the former query-key builder. */
final class CacheKey {
	public static function semantic($query): string {
		if (!$query instanceof Table) {
			$text = Lua::str($query === null || $query === false ? '' : $query);
			return strlen($text) . ':' . $text;
		}
		$values = $query->values;
		$indices = [];
		foreach ($values as $key => $_) { if (is_int($key)) { $indices[] = $key; } }
		sort($indices, SORT_NUMERIC);
		$parts = [];
		foreach ($indices as $key) {
			$value = $values[$key];
			$text = Lua::str($value === null || $value === false ? '' : $value);
			$parts[] = strlen($text) . ':' . $text;
		}
		return implode("\n", $parts);
	}
}
