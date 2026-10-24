<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/** Shared reader content, independent of skin scaffolding. */
class FragmentExtractor {
	/** Normalize only skin scaffolding. Keep reader content, attributes and links. */
	public static function canonical(string $html): string {
		if ($html === '' || preg_match('/<[^>]+\bnonce\s*=/i', $html)) { return ''; }
		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors(true);
		try { $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET); }
		finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
		$xp = new \DOMXPath($doc);
		// Unknown lossy MobileFrontend transforms must fall back to ApiParse.
		if ($xp->query('//*[contains(@class,"lazy-image-placeholder") or contains(@class,"lazy-references-placeholder")]')->length) { return ''; }
		foreach (iterator_to_array($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," mw-editsection ") or (contains(concat(" ",normalize-space(@class)," ")," indicator ") and parent::h2)]')) as $node) {
			$node->parentNode->removeChild($node);
		}
		foreach (iterator_to_array($xp->query('//*[starts-with(@id,"mf-section-") or contains(concat(" ",normalize-space(@class)," ")," mw-heading ")]')) as $node) {
			while ($node->firstChild) { $node->parentNode->insertBefore($node->firstChild, $node); }
			$node->parentNode->removeChild($node);
		}
		foreach ($xp->query('//h2|//h3|//h4|//h5|//h6') as $heading) {
			foreach (iterator_to_array($heading->childNodes) as $child) {
				if ($child instanceof \DOMText && trim($child->textContent) === '') { $heading->removeChild($child); }
			}
			if (str_starts_with($heading->getAttribute('onclick'), 'mfTempOpenSection(')) { $heading->removeAttribute('onclick'); }
			$classes = preg_split('/\\s+/', $heading->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
			$classes = array_diff($classes, ['section-heading', 'in-block']);
			if ($classes) { $heading->setAttribute('class', implode(' ', $classes)); }
			else { $heading->removeAttribute('class'); }
		}
		return self::extract($doc->saveHTML($doc->documentElement));
	}

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
