<?php

namespace MediaWiki\Extension\Wikidebates;

/**
 * Byte-preserving boundaries for the literal templates edited by AutoParentLink.
 * This is not a wikitext evaluator: ambiguous or unbalanced brace syntax is
 * refused instead of guessing where an automatic edit should be inserted.
 */
final class TemplateSyntax {
	public static function parse( string $text ): ?array {
		$masked = self::maskProtectedText( $text );
		$start = strpos( $masked, '{{' );
		return $start === false ? null : self::parseAt( $text, $masked, $start );
	}

	/** Only direct templates, never nested templates or commented examples. */
	public static function templates( string $text ): array {
		$masked = self::maskProtectedText( $text );
		$templates = [];
		$offset = 0;
		while ( ( $start = strpos( $masked, '{{', $offset ) ) !== false ) {
			$template = self::parseAt( $text, $masked, $start );
			if ( $template === null ) {
				return [];
			}
			$templates[] = $template;
			$offset = $template['end'];
		}
		return $templates;
	}

	public static function equalsOffset( string $text ): ?int {
		return self::scan( self::maskProtectedText( $text ), 0, false )['equals'] ?? null;
	}

	private static function parseAt( string $text, string $masked, int $start ): ?array {
		if ( strspn( $masked, '{', $start ) !== 2 ) {
			return null;
		}
		$scan = self::scan( $masked, $start + 2, true );
		if ( $scan === null ) {
			return null;
		}
		$parts = [];
		$position = $start;
		foreach ( $scan['pipes'] as $pipe ) {
			$parts[] = substr( $text, $position, $pipe - $position );
			$position = $pipe + 1;
		}
		$parts[] = substr( $text, $position, $scan['end'] - 2 - $position );
		$name = trim( substr( $parts[0], 2 ) );
		if ( $name === '' ) {
			return null;
		}
		return [ 'start' => $start, 'end' => $scan['end'], 'parts' => $parts, 'name' => $name ];
	}

	private static function scan( string $text, int $start, bool $root ): ?array {
		$stack = $root ? [ 2 ] : [];
		$level = count( $stack );
		$links = 0;
		$result = [ 'pipes' => [], 'equals' => null ];
		$length = strlen( $text );
		for ( $i = $start; $i < $length; ) {
			$pair = substr( $text, $i, 2 );
			if ( $pair === '{{' ) {
				$width = strspn( $text, '{', $i );
				if ( $width > 3 ) {
					// Dynamic template names need the full MediaWiki preprocessor.
					return null;
				}
				$stack[] = $width;
				$i += $width;
				continue;
			}
			if ( $pair === '}}' ) {
				$width = end( $stack );
				if ( !$width || strspn( $text, '}', $i ) < $width ) {
					return null;
				}
				array_pop( $stack );
				$i += $width;
				if ( $root && !$stack ) {
					return $links === 0 ? $result + [ 'end' => $i ] : null;
				}
				continue;
			}
			if ( $pair === '[[' ) {
				$links++;
				$i += 2;
				continue;
			}
			if ( $pair === ']]' ) {
				$links = max( 0, $links - 1 );
				$i += 2;
				continue;
			}
			if ( count( $stack ) === $level && $links === 0 ) {
				if ( $text[$i] === '|' ) {
					$result['pipes'][] = $i;
				} elseif ( $text[$i] === '=' && $result['equals'] === null ) {
					$result['equals'] = $i;
				}
			}
			$i++;
		}
		return !$root && !$stack && !$links ? $result : null;
	}

	/** Preserve byte offsets, including UTF-8, while ignoring literal examples. */
	private static function maskProtectedText( string $text ): string {
		$pieces = [];
		$position = 0;
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $text[$i] !== '<' ) {
				continue;
			}
			if ( substr_compare( $text, '<!--', $i, 4 ) === 0 ) {
				$close = strpos( $text, '-->', $i + 4 );
				$end = $close === false ? $length : $close + 3;
			} elseif ( preg_match(
				'/\G<nowiki(?=[\s\/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
				$text, $match, 0, $i
			) ) {
				$end = $i + strlen( $match[0] );
				if ( !preg_match( '/\/\s*>$/', $match[0] ) ) {
					$found = preg_match( '/<\/nowiki\s*>/i', $text, $close, PREG_OFFSET_CAPTURE, $end );
					$end = $found ? $close[0][1] + strlen( $close[0][0] ) : $length;
				}
			} else {
				continue;
			}
			$pieces[] = substr( $text, $position, $i - $position );
			$pieces[] = str_repeat( ' ', $end - $i );
			$position = $end;
			$i = $end - 1;
		}
		$pieces[] = substr( $text, $position );
		return implode( '', $pieces );
	}
}
