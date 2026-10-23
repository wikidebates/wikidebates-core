<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/** Same byte boundaries as wkReadingExtractFragment; no HTML reserialization. */
class FragmentExtractor {
	public static function extract(string $html): string {
		$a = strpos($html, 'id="Summary"');
		if ($a === false) { return ''; }
		$a = strrpos(substr($html, 0, $a), '<h2');
		if ($a === false) { return ''; }
		$end = strpos($html, 'id="Parent_debates"', $a + 1);
		if ($end === false) { return ''; }
		$b = strrpos(substr($html, 0, $end), '<h2');
		if ($b === false) { $b = strrpos(substr($html, 0, $end), '<div'); }
		return $b === false || $b <= $a ? '' : substr($html, $a, $b - $a);
	}
}
