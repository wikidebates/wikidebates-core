<?php
namespace MediaWiki\Extension\DebatePHP;

/** Literal separators used by the wiki templates. No parser or regular expression. */
final class SerializedData {
	private const WHITESPACE = " \t\r\n\v\f";

	public static function csvValues(?string $text, ?string $separator = null): array {
		$out = [];
		foreach (explode($separator ?? ',', $text ?? '') as $part) {
			$part = trim($part, self::WHITESPACE);
			if ($part !== '') { $out[count($out) + 1] = $part; }
		}
		return $out;
	}
	public static function split(?string $text, ?string $separator, $maxSplits = null): array {
		$text ??= ''; $separator ??= '';
		// The original limit counts splits, and zero still permits the first split.
		$parts = $text === '' || $separator === '' ? [$text]
			: explode($separator, $text, $maxSplits !== null && $maxSplits !== false ? max(2, $maxSplits + 1) : PHP_INT_MAX);
		return array_combine(range(1, count($parts)), $parts);
	}
	/** @return list<array{page:string,title:string,warnings:string}> */
	public static function argumentItems(?string $text, bool $requireItemSeparator): array {
		$text = trim($text ?? '', self::WHITESPACE); $out = [];
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, '⟭')
			: (!str_contains($text, '⟭') && !str_contains($text, '⟬')))) { return []; }
		foreach (explode('⟭', $text) as $block) {
			$block = trim($block, self::WHITESPACE); if ($block === '') { continue; }
			$fields = explode('⟬', $block, 3);
			$page = trim($fields[0], self::WHITESPACE); if ($page === '') { continue; }
			$title = trim($fields[1] ?? '', self::WHITESPACE);
			$out[] = ['page' => $page, 'title' => $title === '' ? $page : $title,
				'warnings' => trim($fields[2] ?? '', self::WHITESPACE)];
		}
		return $out;
	}
	/** Map storage omits the label when it is identical to the page title. */
	public static function mapItem(string $page, string $title, string $fieldSeparator = '⟬'): string {
		return $page === $title ? $page : $page . $fieldSeparator . $title;
	}

	/** @return iterable<array{page:string,title:string}>
	 * The map projection splits only on the first field separator. Unlike the
	 * template input format, a single bare title is valid and has no warnings.
	 */
	public static function mapItems(string $text, string $separator): iterable {
		$text = trim($text, self::WHITESPACE);
		if ($text === '') {
			return;
		}
		foreach (explode($separator, $text) as $item) {
			[$page, $label] = NativeText::splitOnce($item, '⟬');
			$page = trim($page, self::WHITESPACE);
			if ($page === '') {
				continue;
			}
			$label = trim($label, self::WHITESPACE);
			yield ['page' => $page, 'title' => $label === '' ? $page : $label];
		}
	}

	public static function recordValues(string $block, string $fieldSeparator, array $fields): array {
		$parts = explode($fieldSeparator, $block); $record = [];
		foreach ($fields as $index => $name) { $record[$name] = trim($parts[$index] ?? '', self::WHITESPACE); }
		return $record;
	}
	/** @return array<int, array<string, string>> Dense one-based records. */
	public static function recordsValues(?string $text, string $separator, string $fieldSeparator, array $fields, bool $requireItemSeparator = false): array {
		$text = trim($text ?? '', self::WHITESPACE); $out = [];
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, $separator)
			: (!str_contains($text, $separator) && !str_contains($text, $fieldSeparator)))) { return []; }
		foreach (explode($separator, $text) as $block) {
			$block = trim($block, self::WHITESPACE); if ($block === '') { continue; }
			$out[count($out) + 1] = self::recordValues($block, $fieldSeparator, $fields);
		}
		return $out;
	}
	public static function quotes(?string $text): array {
		// Quotes require a field marker, whereas the other record formats also accept
		// an item marker alone. Missing fields are empty; excess fields are ignored.
		$text = trim($text ?? '', self::WHITESPACE);
		if (!str_contains($text, '⟬⟬⟬')) { return []; }
		return self::recordsValues($text, '⟭⟭⟭', '⟬⟬⟬', [
			'quote', 'authors', 'article', 'work', 'volume', 'number', 'location',
			'page', 'publisher', 'place', 'date', 'link', 'warnings'
		]);
	}
}
