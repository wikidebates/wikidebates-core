<?php
namespace MediaWiki\Extension\DebatePHP;

/** Frozen JSON conversion of the former compatibility objects; test oracle only. */
final class LegacyJson {
	public static function jsonValue($v) {
		if (!$v instanceof Table) { return $v; }
		$a = $v->values;
		$sequence = true; $zeroKeys = true; $next = 1;
		$out = [];
		foreach ($a as $key => $value) {
			// Conversion is pure: recurse without references before ordering the result.
			$out[$key] = self::jsonValue($value);
			$sequence = $sequence && $key === $next++;
			// SORT_NUMERIC ties are stable in PHP 8.3. All these keys compare as zero.
			$zeroKeys = $zeroKeys && is_string($key) && (float)$key === 0.0;
		}
		if ($sequence) { return array_values($out); }
		if ($zeroKeys) { return $out; }
		// Sparse, mixed and unusual numeric-string keys retain the general Lua path.
		ksort($out, SORT_NUMERIC); $next = 1; $sequence = true;
		foreach ($out as $k => $_) {
			if ($sequence) { $sequence = (is_int($k) || (is_string($k) && ctype_digit($k))) && $next++ === (int)$k; }
		}
		return $sequence ? array_values($out) : $out;
	}
}
