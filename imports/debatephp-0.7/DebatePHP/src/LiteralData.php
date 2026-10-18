<?php
namespace MediaWiki\Extension\DebatePHP;

/** Reads only literal Lua data tables (strings, tables, numbers, booleans, concatenation).
 * No eval, require, function calls, variables or executable Lua accepted.
 */
final class LiteralData {
	private string $text;
	private int $offset = 0;
	public function read(string $text): array {
		$this->text = $text; $this->offset = 0;
		$this->skip(); $this->expect('return'); $v = $this->value(); $this->skip();
		if ($this->offset !== strlen($text) || !is_array($v)) { throw new \RuntimeException('Expected a literal return table'); }
		return $v;
	}
	private function skip(): void {
		while ($this->offset < strlen($this->text)) {
			if (preg_match('/\G\s+/A', $this->text, $m, 0, $this->offset)) { $this->offset += strlen($m[0]); continue; }
			if (substr($this->text, $this->offset, 2) !== '--') { break; }
			$this->offset += 2;
			if (preg_match('/\G\[(=*)\[/A', $this->text, $m, 0, $this->offset)) {
				$this->longString();
			} else {
				$p = strpos($this->text, "\n", $this->offset); $this->offset = $p === false ? strlen($this->text) : $p + 1;
			}
		}
	}
	private function expect(string $s): void {
		$this->skip(); if (substr($this->text, $this->offset, strlen($s)) !== $s) { throw new \RuntimeException('Unexpected literal data at byte ' . $this->offset); }
		$this->offset += strlen($s);
	}
	private function value() {
		$this->skip(); $c = $this->text[$this->offset] ?? '';
		if ($c === '{') { $v = $this->table(); }
		elseif ($c === '"' || $c === "'") { $v = $this->string(); }
		elseif ($c === '[') { $v = $this->longString(); }
		elseif (preg_match('/\G(true|false|nil|-?\d+(?:\.\d+)?)(?![\w])/A', $this->text, $m, 0, $this->offset)) {
			$this->offset += strlen($m[0]); $v = ['true' => true, 'false' => false, 'nil' => null][$m[1]] ?? (is_numeric($m[1]) ? $m[1] + 0 : null);
		} else { throw new \RuntimeException('Executable or unsupported Lua data at byte ' . $this->offset); }
		$this->skip();
		while (substr($this->text, $this->offset, 2) === '..') {
			$this->offset += 2; $next = $this->value();
			if (!is_string($v) || !is_string($next)) { throw new \RuntimeException('Non-string data concatenation'); }
			$v .= $next; $this->skip();
		}
		return $v;
	}
	private function table(): array {
		$this->expect('{'); $out = []; $index = 1;
		while (true) {
			$this->skip(); if (($this->text[$this->offset] ?? '') === '}') { $this->offset++; return $out; }
			if (preg_match('/\G([a-zA-Z_][a-zA-Z_0-9]*)\s*=/A', $this->text, $m, 0, $this->offset)) {
				$key = $m[1]; $this->offset += strlen($m[0]);
			} elseif (($this->text[$this->offset] ?? '') === '[' && !preg_match('/\G\[=*\[/A', $this->text, $m, 0, $this->offset)) {
				$this->offset++; $key = $this->value(); $this->expect(']'); $this->expect('=');
			} else { $key = $index++; }
			$v = $this->value(); if ($v !== null) { $out[$key] = $v; }
			$this->skip(); $c = $this->text[$this->offset] ?? '';
			if ($c === ',' || $c === ';') { $this->offset++; }
			elseif ($c !== '}') { throw new \RuntimeException('Missing literal field delimiter'); }
		}
	}
	private function longString(): string {
		if (!preg_match('/\G\[(=*)\[/A', $this->text, $m, 0, $this->offset)) { throw new \RuntimeException('Invalid long string'); }
		$this->offset += strlen($m[0]); $close = ']' . $m[1] . ']'; $end = strpos($this->text, $close, $this->offset);
		if ($end === false) { throw new \RuntimeException('Unterminated long string'); }
		$s = substr($this->text, $this->offset, $end - $this->offset); $this->offset = $end + strlen($close);
		return preg_replace('/^\r?\n/', '', str_replace(["\r\n", "\r"], "\n", $s));
	}
	private function string(): string {
		$q = $this->text[$this->offset++]; $out = '';
		while ($this->offset < strlen($this->text)) {
			$c = $this->text[$this->offset++]; if ($c === $q) { return $out; }
			if ($c !== '\\') { $out .= $c; continue; }
			$c = $this->text[$this->offset++];
			if (ctype_digit($c)) {
				$n = $c;
				for ($i = 0; $i < 2 && ctype_digit($this->text[$this->offset] ?? ''); $i++) { $n .= $this->text[$this->offset++]; }
				if ((int)$n > 255) { throw new \RuntimeException('Invalid byte escape'); } $out .= chr((int)$n);
			} else { $out .= ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"][$c] ?? $c; }
		}
		throw new \RuntimeException('Unterminated string');
	}
}
