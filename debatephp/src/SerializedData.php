<?php
namespace MediaWiki\Extension\DebatePHP;

/** Literal separators used by the wiki templates. No parser or regular expression. */
final class SerializedData {
	public static function csv(?string $text, ?string $separator = null): Table {
		$out = new Table;
		foreach (explode($separator ?? ',', $text ?? '') as $part) {
			$part = Lua::trim($part);
			if ($part !== '') { Lua::insert($out, $part); }
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
		$text = Lua::trim($text ?? ''); $out = new Table;
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, '⟭')
			: (!str_contains($text, '⟭') && !str_contains($text, '⟬')))) { return $out; }
		foreach (explode('⟭', $text) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$fields = explode('⟬', $block, 3);
			$page = Lua::trim($fields[0]); if ($page === '') { continue; }
			$title = Lua::trim($fields[1] ?? '');
			Lua::insert($out, new Table(['page' => $page, 'title' => $title === '' ? $page : $title,
				'warnings' => Lua::trim($fields[2] ?? '')]));
		}
		return $out;
	}
	public static function records(?string $text, string $separator, string $fieldSeparator, array $fields, bool $requireItemSeparator = false): Table {
		$text = Lua::trim($text ?? ''); $out = new Table;
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, $separator)
			: (!str_contains($text, $separator) && !str_contains($text, $fieldSeparator)))) { return $out; }
		foreach (explode($separator, $text) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$parts = explode($fieldSeparator, $block); $record = [];
			foreach ($fields as $index => $name) { $record[$name] = Lua::trim($parts[$index] ?? ''); }
			Lua::insert($out, new Table($record));
		}
		return $out;
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
