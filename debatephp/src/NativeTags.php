<?php
namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\Sanitizer;

/**
 * PHP-only internal navigation markup. No public htmltag parser hook or
 * configurable arbitrary tag policy is installed by Wikidebates.
 */
final class NativeTags {
	private static ?Telemetry $telemetry = null;

	public static function observe(?Telemetry $telemetry, callable $operation): string {
		$previous = self::$telemetry;
		self::$telemetry = $telemetry;
		try { return $operation(); }
		finally { self::$telemetry = $previous; }
	}

	private static function expand(string $reason, callable $operation): string {
		return self::$telemetry ? self::$telemetry->preprocess($reason, $operation) : $operation();
	}

	public static function argument(Parser $parser, PPFrame $frame, string $text, string $reason = 'native-markup.argument'): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		if (!str_contains($text, '{{') && !str_contains($text, '<')) { return $text; }
		return self::expand($reason, static function() use ($parser, $frame, $text): string {
			$node = $parser->getPreprocessor()->preprocessToObj($text, $frame->depth ? Parser::PTD_FOR_INCLUSION : 0);
			return $frame->expand($node);
		});
	}

	/**
	 * Reject script/obfuscated URI schemes. Only the literal historical RunQuery
	 * no-op may be used for an anchor href; data-href cannot use that exception.
	 */
	public static function isSafeLinkTarget(string $value, bool $allowInert = false): bool {
		$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($allowInert && $decoded === 'javascript:void(0)') { return true; }
		if ($decoded === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) { return false; }
		if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded)) { return true; }
		return (bool)preg_match('/^(?:https?|mailto):/i', $decoded);
	}

	/** Render the single internal link type used by both PHP renderers. */
	public static function renderLink(Parser $parser, PPFrame $frame, string $content, array $attributes): string {
		$content = str_replace(["\r\n", "\r"], "\n", $content);
		$decoded = [];
		foreach ($attributes as $key => $value) {
			$decoded[strtolower((string)$key)] = Sanitizer::decodeCharReferences(
				trim(preg_replace('/[\t\r\n ]+/', ' ', (string)$value))
			);
		}
		// Reserve the strip marker before nested expansions, as previously.
		$marker = $parser->insertStripItem('');
		if (str_contains($content, '{{') || str_contains($content, '<')) {
			$content = self::expand('native-link.body', static fn() => $parser->replaceVariables($content, $frame));
		}
		$filtered = [];
		foreach ($decoded as $key => $value) {
			if (!in_array($key, ['href', 'data-href', 'class', 'title'], true)) { continue; }
			if (str_contains($value, '{{') || str_contains($value, '<')) {
				$value = self::expand('native-link.attribute', static fn() => $parser->replaceVariables($value, $frame));
			}
			if (($key === 'href' || $key === 'data-href') &&
				!self::isSafeLinkTarget($value, $key === 'href')) {
				continue;
			}
			// Retain the old native anchor's entity treatment for output parity.
			$filtered[$key] = str_replace('&amp;', '&', htmlspecialchars($value, ENT_QUOTES));
		}
		$parser->getStripState()->addGeneral($marker, Html::element('a', $filtered, $content));
		return $marker;
	}
}
