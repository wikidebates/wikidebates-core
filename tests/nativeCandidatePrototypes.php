<?php

/** Measurement only: plain record views are not enabled in production renderers. */
final class NativeCandidatePrototypes {
	public static function arguments(?string $text, bool $requireItemSeparator): array {
		$text = trim($text ?? '', " \t\r\n\v\f"); $out = [];
		if ($text === '' || ($requireItemSeparator ? !str_contains($text, '⟭')
			: (!str_contains($text, '⟭') && !str_contains($text, '⟬')))) { return []; }
		foreach (explode('⟭', $text) as $block) {
			$block = trim($block, " \t\r\n\v\f"); if ($block === '') { continue; }
			$fields = explode('⟬', $block, 3);
			$page = trim($fields[0], " \t\r\n\v\f"); if ($page === '') { continue; }
			$title = trim($fields[1] ?? '', " \t\r\n\v\f");
			$out[count($out) + 1] = ['page' => $page, 'title' => $title === '' ? $page : $title,
				'warnings' => trim($fields[2] ?? '', " \t\r\n\v\f")];
		}
		return $out;
	}

	public static function introductions(?string $text): array {
		$text = trim($text ?? '', " \t\r\n\v\f"); $out = [];
		if ($text === '' || !str_contains($text, '⟭⟭⟭')) { return []; }
		foreach (explode('⟭⟭⟭', $text) as $block) {
			$block = trim($block, " \t\r\n\v\f"); if ($block === '') { continue; }
			$out[count($out) + 1] = \MediaWiki\Extension\DebatePHP\SerializedData::recordValues($block, '⟬⟬⟬', ['title', 'content', 'warnings']);
		}
		return $out;
	}
}
