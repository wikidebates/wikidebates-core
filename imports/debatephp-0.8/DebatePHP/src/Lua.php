<?php
namespace MediaWiki\Extension\DebatePHP;

/** Small semantic helpers for the reference port, not an interpreter. */
final class Lua {
	public static function truth($v): bool { return $v !== null && $v !== false; }
	public static function str($v): string {
		if ($v === null) { return 'nil'; }
		if ($v === false) { return 'false'; }
		if ($v === true) { return 'true'; }
		return (string)$v;
	}
	public static function type($v): string {
		if ($v === null) { return 'nil'; }
		if ($v instanceof Table) { return 'table'; }
		// A Lua string stays a string even when PHP recognizes a callable name.
		if (is_string($v)) { return 'string'; }
		if ($v instanceof \Closure) { return 'function'; }
		if (is_bool($v)) { return 'boolean'; }
		if (is_numeric($v) && !is_string($v)) { return 'number'; }
		return 'userdata';
	}
	public static function len($v): int { return $v instanceof Table ? count($v) : strlen($v); }
	public static function trim($s): string { return trim($s, " \t\r\n\v\f"); }
	public static function number($s) { return is_numeric($s) ? $s + 0 : null; }
	public static function format($s, ...$args): string {
		// Lua string.format ignores surplus arguments; PHP sprintf does too.
		return sprintf($s, ...$args);
	}
	public static function concat(Table $t, string $sep = ''): string { return implode($sep, $t->values); }
	public static function insert(Table $t, $value): void { $t[count($t) + 1] = $value; }
	public static function sort(Table $t): void {
		$v = array_values($t->values); sort($v, $v && is_int($v[0]) ? SORT_NUMERIC : SORT_STRING);
		$t->values = $v ? array_combine(range(1, count($v)), $v) : [];
	}
	public static function pairs(Table $t): array { return $t->values; }
	public static function ipairs(Table $t): array {
		$v = []; for ($i = 1; isset($t[$i]); $i++) { $v[$i] = $t[$i]; } return $v;
	}
	public static function split(string $s, string $sep, bool $plain = false): array {
		if ($sep === '%s+' && !$plain) { return preg_split('/[\x09-\x0d ]+/', $s); }
		if (!$plain || $sep === '') { throw new \LogicException('Unsupported nonliteral split'); }
		return explode($sep, $s);
	}
	public static function find(string $s, string $needle, int $start = 1, bool $plain = false) {
		if (!$plain) {
			$patterns = ['[%[%]{}|#<>]' => '/[\[\]{}|#<>]/', '[%[%]{}|<>]' => '/[\[\]{}|<>]/', '[%[<&]' => '/[\[<&]/'];
			if (!isset($patterns[$needle])) { throw new \LogicException('Unsupported Lua find: ' . $needle); }
			return preg_match($patterns[$needle], $s, $m, PREG_OFFSET_CAPTURE, max(0, $start - 1)) ? $m[0][1] + 1 : null;
		}
		$p = strpos($s, $needle, max(0, $start - 1)); return $p === false ? null : $p + 1;
	}
	public static function sub(string $s, int $start, ?int $end = null): string {
		$n = strlen($s); $a = $start < 0 ? max(0, $n + $start) : max(0, $start - 1);
		$b = $end === null ? $n : ($end < 0 ? $n + $end + 1 : min($n, $end));
		return substr($s, $a, max(0, $b - $a));
	}
	public static function gsub(string $s, string $pattern, string $replacement): string {
		// Exhaustive patterns present in Module:Debate; never guess Lua->PCRE semantics.
		$regex = [
			'%s+' => '/[\x09-\x0d ]+/', '%s' => '/[\x09-\x0d ]/',
			"^:%s*" => '/^:[\x09-\x0d ]*/', '^%[%[%s*' => '/^\[\[[\x09-\x0d ]*/', '%s*%]%]$' => '/[\x09-\x0d ]*\]\]$/D', '#.*$' => '/#[^\n]*$/D',
			'%s*$' => '/[\x09-\x0d ]*$/D',
			'[%[%]{}|#<>"]' => '/[\[\]{}|#<>"]/',
			"[\r\n\t]" => '/[\r\n\t]/',
		];
		if (isset($regex[$pattern])) { return preg_replace_callback($regex[$pattern], static fn() => str_replace('%%', '%', $replacement), $s); }
		$literal = ['%%' => '%', '%?' => '?'];
		return str_replace($literal[$pattern] ?? $pattern, str_replace('%%', '%', $replacement), $s);
	}
	public static function remove(Table $t, int $index = 1) {
		$value = $t[$index]; $values = array_values($t->values);
		array_splice($values, $index - 1, 1);
		$t->values = $values ? array_combine(range(1, count($values)), $values) : [];
		return $value;
	}
	public static function pcall(callable $function, ...$args): array {
		try { return [true, $function(...$args)]; }
		catch (\Throwable $e) { return [false, $e->getMessage()]; }
	}
	public static function byte(string $s, int $index = 1): ?int {
		$char = self::sub($s, $index, $index); return $char === '' ? null : ord($char);
	}
	public static function match(string $s, string $pattern): ?string {
		$patterns = [
			'^([^%]|]+)%|' => '/^([^\]|]+)\|/',
			'^%s*(.-)%s*$' => '/^[\x09-\x0d ]*(.*?)[\x09-\x0d ]*$/sD',
			'^%[%[([^%]|]+)%|.-%]%]$' => '/^\[\[([^\]|]+)\|[^\n]*?\]\]$/D',
			'^%[%[([^%]]+)%]%]$' => '/^\[\[([^\]]+)\]\]$/D',
			'(%d+)' => '/([0-9]+)/',
		];
		if (!isset($patterns[$pattern])) { throw new \LogicException('Unsupported Lua match: ' . $pattern); }
		return preg_match($patterns[$pattern], $s, $m) ? $m[1] : null;
	}

}
