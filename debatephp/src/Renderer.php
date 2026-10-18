<?php
namespace MediaWiki\Extension\DebatePHP;

/** Optimizations restricted to pure string/table work. Expansion sequence is inherited. */
final class Renderer extends ReferenceRenderer {
	protected function splitCSV($s = null, $sep = null) {
		if ($s === null || $s === '') { return new Table; }
		$out = []; foreach (explode($sep ?? ',', $s) as $part) { $part = Lua::trim($part); if ($part !== '') { $out[count($out) + 1] = $part; } }
		return new Table($out);
	}
	protected function wkExtractArgumentItemsFromData($raw = null) {
		$raw = Lua::trim($raw ?? ''); $out = [];
		if ($raw === '' || (!str_contains($raw, '⟭') && !str_contains($raw, '⟬'))) { return new Table; }
		foreach (explode('⟭', $raw) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$f = explode('⟬', $block, 3); $page = Lua::trim($f[0]); if ($page === '') { continue; }
			$title = Lua::trim($f[1] ?? '');
			$out[count($out) + 1] = new Table(['page' => $page, 'title' => $title === '' ? $page : $title, 'warnings' => Lua::trim($f[2] ?? '')]);
		} return new Table($out);
	}
	private function records($raw, string $separator, string $fieldSeparator, array $fields, bool $needItemSeparator = false): Table {
		$raw = Lua::trim($raw ?? ''); $out = [];
		if ($raw === '' || ($needItemSeparator ? !str_contains($raw, $separator) : (!str_contains($raw, $separator) && !str_contains($raw, $fieldSeparator)))) { return new Table; }
		foreach (explode($separator, $raw) as $block) {
			$block = Lua::trim($block); if ($block === '') { continue; }
			$parts = explode($fieldSeparator, $block); $record = [];
			foreach ($fields as $i => $name) { $record[$name] = Lua::trim($parts[$i] ?? ''); }
			$out[count($out) + 1] = new Table($record);
		} return new Table($out);
	}
	protected function wkParseIntroSubsections($raw = null) { return $this->records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true); }
	protected function wkParseBiblioMarkers($raw = null) { return $this->records($raw, '⟭', '⟬', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']); }
	protected function wkParseWeblioMarkers($raw = null) { return $this->records($raw, '⟭', '⟬', ['page', 'site', 'link', 'authors', 'date', 'warnings']); }
	protected function wkParseVideoMarkers($raw = null) { return $this->records($raw, '⟭', '⟬', ['title', 'link', 'authors', 'warnings']); }
}
