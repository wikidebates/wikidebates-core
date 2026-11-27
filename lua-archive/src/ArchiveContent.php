<?php

namespace MediaWiki\Extension\WikidebatesLuaArchive;

use MediaWiki\Content\TextContent;

/** The old storage model, without any execution or transclusion semantics. */
final class ArchiveContent extends TextContent {
	public function __construct($text, $modelId = 'Scribunto') {
		parent::__construct($text, $modelId);
	}

	public function getWikitextForTransclusion() {
		return false;
	}
}
