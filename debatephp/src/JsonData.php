<?php
namespace MediaWiki\Extension\DebatePHP;

/** Explicit internal tree conversion; TextEscaper::jsonValue's external array contract is unchanged. */
final class JsonData {
	public static function value($value) {
		if ($value instanceof Table) { $value = $value->values; }
		if (!is_array($value)) { return $value; }
		$out = []; $sequence = true; $zeroKeys = true; $next = 1;
		foreach ($value as $key => $item) {
			$out[$key] = self::value($item);
			$sequence = $sequence && $key === $next++;
			$zeroKeys = $zeroKeys && is_string($key) && (float)$key === 0.0;
		}
		if ($sequence) { return array_values($out); }
		if ($zeroKeys) { return $out; }
		ksort($out, SORT_NUMERIC); $sequence = true; $next = 1;
		foreach ($out as $key => $_) {
			if ($sequence) { $sequence = (is_int($key) || (is_string($key) && ctype_digit($key))) && $next++ === (int)$key; }
		}
		return $sequence ? array_values($out) : $out;
	}
}
