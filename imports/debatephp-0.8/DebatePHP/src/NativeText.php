<?php
namespace MediaWiki\Extension\DebatePHP;

/** Shared text rules used by both renderers, expressed with native PHP operations. */
final class NativeText {
	private static function text($value): string {
		return $value === null || $value === false ? '' : Lua::str($value);
	}
	public static function anchorId($value): string {
		$text = str_replace(['&#039;', '&#39;', '&apos;'], "'", self::text($value));
		$text = Lua::trim(preg_replace('/[\x09-\x0d ]+/', ' ', $text));
		return str_replace(['[', ']', '{', '}', '|', '#', '<', '>', '"'], '', str_replace(' ', '_', $text));
	}
	public static function tooltip($value): string {
		return strtr(self::text($value), ["'" => '’', '"' => '“']);
	}
	public static function attribute($value): string {
		$text = str_replace(["\r", "\n", "\t"], ' ', self::text($value));
		return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
	}
	public static function pathSegment($value): string {
		return strtr(self::text($value), ['%' => '%25', '?' => '%3F', '#' => '%23', '/' => '%2F']);
	}
	public static function splitOnce($value, $separator): array {
		$text = self::text($value); $separator = self::text($separator);
		$position = $separator === '' ? false : strpos($text, $separator);
		return $position === false ? [$text, ''] : [substr($text, 0, $position), substr($text, $position + strlen($separator))];
	}
}
