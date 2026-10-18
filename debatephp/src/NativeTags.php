<?php
namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\Sanitizer;

/** HTML Tags 0.3-compatible pipeline, without its hook or a constructed tag invocation. */
final class NativeTags {
	public static function policy(): array {
		return \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get('DebatePHPHTMLAttributes')
			?? ($GLOBALS['wgHTMLTagsAttributes'] ?? ['a'=>['href','data-href','class','title'], 'script'=>['class','type']]);
	}
	/** Compatibility hook for unusual syntax and remaining legacy wikitext. No external extension. */
	public static function hook($input, array $attributes, Parser $parser, PPFrame $frame): string {
		if (!array_key_exists('tagname', $attributes)) { return wfMessage('htmltags-notagname')->text(); }
		$name = $attributes['tagname']; unset($attributes['tagname']);
		return self::body($parser, $frame, $name, $input ?? '', $attributes, self::policy());
	}
	public static function argument(Parser $parser, PPFrame $frame, string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		if (!str_contains($text, '{{') && !str_contains($text, '<')) { return $text; }
		$node = $parser->getPreprocessor()->preprocessToObj($text, $frame->depth ? Parser::PTD_FOR_INCLUSION : 0);
		return $frame->expand($node);
	}
	public static function render(Parser $parser, PPFrame $frame, string $name, string $content, array $attributes, bool $xml, array $policy): string {
		$content = str_replace(["\r\n", "\r"], "\n", $content);
		if (!$xml) {
			// Core #tag expands its body and ALL parameters before calling the hook.
			$content = self::argument($parser, $frame, $content);
			$prepared = [];
			foreach ($attributes as $key => $value) {
				$value = trim(self::argument($parser, $frame, (string)$value));
				if (preg_match('/^(?:["\'](.+)["\']|""|\'\')$/s', $value, $m)) { $value = $m[1] ?? ''; }
				$prepared[trim(str_replace(["\r\n", "\r"], "\n", (string)$key))] = $value;
			} $attributes = $prepared;
		}
		// Reserve the outer marker BEFORE hook-side expansions, as extensionSubstitution does.
		$marker = $parser->insertStripItem('');
		if ($parser->getOutputType() !== Parser::OT_HTML) {
			$raw = '<htmltag tagname="' . htmlspecialchars($name, ENT_COMPAT) . '"';
			foreach ($attributes as $key => $value) {
				$raw .= ' ' . $key . '="' . ($xml ? $value : htmlspecialchars($parser->getStripState()->unstripBoth($value), ENT_COMPAT)) . '"';
			}
			$html = $raw . '>' . $content . '</htmltag>';
		} else {
			if ($xml) {
				$decoded = [];
				foreach ($attributes as $key => $value) { $decoded[strtolower($key)] = Sanitizer::decodeCharReferences(trim(preg_replace('/[\t\r\n ]+/', ' ', $value))); }
				$attributes = $decoded;
			}
			$html = self::body($parser, $frame, $name, $content, $attributes, $policy);
		}
		$parser->getStripState()->addGeneral($marker, $html);
		return $marker;
	}
	private static function body(Parser $parser, PPFrame $frame, string $name, string $content, array $attributes, array $policy): string {
		if (!array_key_exists($name, $policy)) { return wfMessage('htmltags-unsupportedtag', $name)->escaped(); }
		// HTMLTags::render expands the body before its allowed attributes, then Html::element
		// deliberately escapes content (including script content). Preserve that behavior.
		if (str_contains($content, '{{') || str_contains($content, '<')) { $content = $parser->replaceVariables($content, $frame); }
		$filtered = [];
		foreach ($attributes as $key => $value) {
			if (!in_array($key, $policy[$name])) { continue; }
			if (str_contains($value, '{{') || str_contains($value, '<')) { $value = $parser->replaceVariables($value, $frame); }
			$filtered[$key] = str_replace('&amp;', '&', htmlspecialchars($value, ENT_QUOTES));
		}
		return Html::element($name, $filtered, $content);
	}
}
