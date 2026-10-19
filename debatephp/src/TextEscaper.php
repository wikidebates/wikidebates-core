<?php
namespace MediaWiki\Extension\DebatePHP;

/** mw.text.nowiki's ASCII rules, including configured colon protocols. */
final class TextEscaper {
	public static function nowiki(string $s, array $protocols = []): string {
		$s = strtr($s, ['"' => '&#34;', '&' => '&#38;', "'" => '&#39;', '<' => '&#60;', '=' => '&#61;', '>' => '&#62;', '[' => '&#91;', ']' => '&#93;', '{' => '&#123;', '|' => '&#124;', '}' => '&#125;', ';' => '&#59;']);
		$s = "\n" . $s;
		$map = ["\n!" => "\n&#33;", "\r!" => "\r&#33;", "\n#" => "\n&#35;", "\r#" => "\r&#35;", "\n*" => "\n&#42;", "\r*" => "\r&#42;", "\n:" => "\n&#58;", "\r:" => "\r&#58;", "\n " => "\n&#32;", "\r " => "\r&#32;", "\n\n" => "\n&#10;", "\r\n" => "&#13;\n", "\n\r" => "\n&#13;", "\r\r" => "\r&#13;", "\n\t" => "\n&#9;", "\r\t" => "\r&#9;"];
		$s = preg_replace_callback('/[\r\n][!#*: \n\r\t]/', static fn($m) => $map[$m[0]], $s);
		$s = preg_replace('/([\r\n])----/', '$1&#45;---', $s); $s = substr($s, 1);
		$s = str_replace(['!!', '__', '://', '~~~'], ['&#33;!', '_&#95;', '&#58;//', '~~&#126;'], $s);
		$edge = ['+' => '&#43;', '-' => '&#45;', '_' => '&#95;', '~' => '&#126;', "\n" => '&#10;', "\r" => '&#13;', "\t" => '&#9;'];
		$s = preg_replace_callback('/^[+_~\-]/', static fn($m) => $edge[$m[0]], $s);
		$s = preg_replace_callback('/[_~\r\n\t]$/D', static fn($m) => $edge[$m[0]], $s);
		$s = preg_replace_callback('/(?:ISBN|RFC|PMID)[\x09-\x0d ]/', static function($m) {
			$v = $m[0]; $last = substr($v, -1); $map = [' ' => '&#32;', "\t" => '&#9;', "\r" => '&#13;', "\n" => '&#10;', "\f" => '&#12;'];
			return isset($map[$last]) ? substr($v, 0, -1) . $map[$last] : $v;
		}, $s);
		foreach ($protocols as $p) {
			if (str_ends_with($p, ':')) { $s = preg_replace('/(' . preg_quote(substr($p, 0, -1), '/') . '):/i', '$1&#58;', $s); }
		} return $s;
	}
	public static function uriEncode(string $s, string $mode): string {
		if ($mode === 'WIKI') { return preg_replace_callback('/[^a-zA-Z0-9!$()*,.\/:;@~_\-]/', static fn($m) => $m[0] === ' ' ? '_' : sprintf('%%%02X', ord($m[0])), $s); }
		return $mode === 'QUERY' ? urlencode($s) : rawurlencode($s);
	}
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
