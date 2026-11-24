<?php
namespace MediaWiki\Extension\DebatePHP;

/** Parser-local SEO text resolution; never expand arbitrary general strip items. */
interface SeoTextContext {
	public function resolveSeoMarkers(string $text): string;
}
