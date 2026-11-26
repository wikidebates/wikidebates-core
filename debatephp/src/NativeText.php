<?php
namespace MediaWiki\Extension\DebatePHP;

/** Shared text rules used by both renderers, expressed with native PHP operations. */
final class NativeText {
	public const ASCII_WHITESPACE = " \t\r\n\v\f";

	/** Textual wiki field: absent/false is empty, numeric zero is text, true is literal. */
	public static function field(mixed $value): string {
		return $value === null || $value === false ? '' : ($value === true ? 'true' : (string)$value);
	}
	public static function anchorId($value): string {
		$text = str_replace(['&#039;', '&#39;', '&apos;'], "'", self::field($value));
		$text = trim(preg_replace('/[\x09-\x0d ]+/', ' ', $text), self::ASCII_WHITESPACE);
		return str_replace(['[', ']', '{', '}', '|', '#', '<', '>', '"'], '', str_replace(' ', '_', $text));
	}
	public static function tooltip($value): string {
		return strtr(self::field($value), ["'" => '’', '"' => '“']);
	}
	public static function attribute($value): string {
		$text = str_replace(["\r", "\n", "\t"], ' ', self::field($value));
		return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
	}
	public static function pathSegment($value): string {
		return strtr(self::field($value), ['%' => '%25', '?' => '%3F', '#' => '%23', '/' => '%2F']);
	}
	public static function splitOnce($value, $separator): array {
		$text = self::field($value); $separator = self::field($separator);
		$position = $separator === '' ? false : strpos($text, $separator);
		return $position === false ? [$text, ''] : [substr($text, 0, $position), substr($text, $position + strlen($separator))];
	}
	/** Plain SEO text shared by HTML attributes and JSON-LD; no additional expansion. */
	public static function seoPlainText( string $text, ?Context $context = null ): string {
		$text = preg_replace( '/<!--.*?-->/su', ' ', $text ) ?? $text;
		$text = preg_replace( '/<ref\b[^>]*>.*?<\/ref>/isu', ' ', $text ) ?? $text;
		$text = preg_replace( '/<ref\b[^>]*\/>/isu', ' ', $text ) ?? $text;
		$text = preg_replace( '/\[\[(?:[^\]|]*\|)?([^\]]+)\]\]/u', '$1', $text ) ?? $text;
		$text = preg_replace( '/\[https?:\/\/[^\s\]]+\s+([^\]]+)\]/u', '$1', $text ) ?? $text;
		$text = str_replace( [ "'''", "''" ], '', $text );
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Restore nowiki last, so literal brackets and angle brackets are
		// never interpreted as wikitext/HTML a second time.
		if ($context instanceof SeoTextContext) {
			$text = $context->resolveSeoMarkers($text);
		}
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}
}
