<?php
namespace MediaWiki\Extension\DebatePHP;

/** Native string operations; all stateful operations retain the reference order. */
final class ArgumentRenderer extends ArgumentReferenceRenderer {
	protected function splitCSV($s = null, $sep = null) {
		$out = new Table;
		foreach (explode($sep ?? ',', $s ?? '') as $part) {
			$part = Lua::trim($part); if ($part !== '') { Lua::insert($out, $part); }
		}
		return $out;
	}
	protected function splitByLiteral($s = null, $sep = null, $maxParts = null) {
		$s = (string)($s ?? ''); $sep = (string)($sep ?? '');
		$parts = $s === '' || $sep === '' ? [$s] : explode($sep, $s, Lua::truth($maxParts) ? max(2, $maxParts + 1) : PHP_INT_MAX);
		return new Table(array_combine(range(1, count($parts)), $parts));
	}
}
