<?php
namespace MediaWiki\Extension\DebatePHP;

/** Literal separators used by the wiki templates. No parser or regular expression. */
final class SerializedData {
	public static function csv(?string $text, ?string $separator = null): Table {
		return new Table(self::csvValues($text, $separator));
	}
	public static function csvValues(?string $text, ?string $separator = null): array {
		$out = [];
		foreach (explode($separator ?? ',', $text ?? '') as $part) {
			$part = Lua::trim($part);
			if ($part !== '') { $out[count($out) + 1] = $part; }
		}
		return $out;
	}
	public static function split(?string $text, ?string $separator, $maxSplits = null): Table {
		$text ??= ''; $separator ??= '';
		// The original limit counts splits, and zero still permits the first split.
		$parts = $text === '' || $separator === '' ? [$text]
			: explode($separator, $text, Lua::truth($maxSplits) ? max(2, $maxSplits + 1) : PHP_INT_MAX);
		return new Table(array_combine(range(1, count($parts)), $parts));
	}
	public static function argumentItems(?string $text, bool $requireItemSeparator): Table {
		$text = Lua::trim($text ?? ''); $out = [];
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, '⟭')
			: (!str_contains($text, '⟭') && !str_contains($text, '⟬')))) { return new Table; }
		foreach (explode('⟭', $text) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$fields = explode('⟬', $block, 3);
			$page = Lua::trim($fields[0]); if ($page === '') { continue; }
			$title = Lua::trim($fields[1] ?? '');
			$out[count($out) + 1] = new Table(['page' => $page, 'title' => $title === '' ? $page : $title,
				'warnings' => Lua::trim($fields[2] ?? '')]);
		}
		return new Table($out);
	}
	public static function record(string $block, string $fieldSeparator, array $fields): Table {
		return new Table(self::recordValues($block, $fieldSeparator, $fields));
	}
	public static function recordValues(string $block, string $fieldSeparator, array $fields): array {
		$parts = explode($fieldSeparator, $block); $record = [];
		foreach ($fields as $index => $name) { $record[$name] = trim($parts[$index] ?? '', " \t\r\n\v\f"); }
		return $record;
	}
	public static function records(?string $text, string $separator, string $fieldSeparator, array $fields, bool $requireItemSeparator = false): Table {
		$text = Lua::trim($text ?? ''); $out = [];
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, $separator)
			: (!str_contains($text, $separator) && !str_contains($text, $fieldSeparator)))) { return new Table; }
		foreach (explode($separator, $text) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$out[count($out) + 1] = self::record($block, $fieldSeparator, $fields);
		}
		return new Table($out);
	}
	public static function quotes(?string $text): Table {
		// Quotes require a field marker, whereas the other record formats also accept
		// an item marker alone. Missing fields are empty; excess fields are ignored.
		$text = Lua::trim($text ?? '');
		if (!str_contains($text, '⟬⟬⟬')) { return new Table; }
		return self::records($text, '⟭⟭⟭', '⟬⟬⟬', [
			'quote', 'authors', 'article', 'work', 'volume', 'number', 'location',
			'page', 'publisher', 'place', 'date', 'link', 'warnings'
		]);
	}
}
