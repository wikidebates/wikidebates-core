<?php

namespace MediaWiki\Extension\DebatePHP;

/** Shared markup for stored and contextual hover cards; callers own the link. */
final class ArgumentMapMarkup {
	public static function card(string $wikitext): string {
		return '<div class="argument-title--map wk-icon-before wk-icon-argument">' . $wikitext . '</div>';
	}
}
