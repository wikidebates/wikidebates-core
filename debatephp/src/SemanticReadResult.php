<?php
namespace MediaWiki\Extension\DebatePHP;

/** Invocation-local, immutable result metadata; arrays use the historical one-based keys. */
final class SemanticReadResult {
	public function __construct(public readonly ?array $rows, public readonly bool $completeEmpty = false) {
		if ($completeEmpty && $rows !== null && $rows !== []) {
			throw new \InvalidArgumentException('Only empty results can certify an empty union');
		}
	}

	/** Same numeric-key reindexing as array_unshift(..., '') followed by unset(0). */
	public static function values($value) {
		// A single, input-independent callback retains C-level key handling and mapping.
		static $convert = null;
		$convert ??= static function($item) use (&$convert) {
			if (!is_array($item)) { return $item; }
			array_unshift($item, ''); unset($item[0]);
			return array_map($convert, $item);
		};
		return $convert($value);
	}

	/** Match Lua ipairs: named fields and values beyond the first nil/hole are ignored. */
	public static function sequence(array $values): \Generator {
		for ($i = 1; isset($values[$i]); $i++) { yield $i => $values[$i]; }
	}
}
