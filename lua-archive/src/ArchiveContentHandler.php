<?php

namespace MediaWiki\Extension\WikidebatesLuaArchive;

use MediaWiki\Content\CodeContentHandler;
use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\Transform\PreSaveTransformParams;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputFlags;
use MediaWiki\Title\Title;

/** Preserve the stored model and format; source is always rendered as literal text. */
final class ArchiveContentHandler extends CodeContentHandler {
	public function __construct($modelId = 'Scribunto') {
		parent::__construct($modelId, [CONTENT_FORMAT_TEXT]);
	}

	protected function getContentClass() {
		return ArchiveContent::class;
	}

	public function isSupportedFormat($format) {
		return parent::isSupportedFormat($format === 'CONTENT_FORMAT_TEXT' ? CONTENT_FORMAT_TEXT : $format);
	}

	public function canBeUsedOn(Title $title) {
		return $title->getNamespace() === NS_MODULE && parent::canBeUsedOn($title);
	}

	public function preSaveTransform(Content $content, PreSaveTransformParams $pstParams): Content {
		// Never parse, validate as executable code, or normalize archived source.
		return $content;
	}

	protected function fillParserOutput(Content $content, ContentParseParams $params, ParserOutput &$output) {
		// Do not call the parent: TextModelsToParse must never cause a wiki parse.
		$output->clearWrapperDivClass();
		$output->setRawText($params->getGenerateHtml()
			? Html::element('pre', ['class' => 'mw-code mw-script', 'lang' => 'en', 'dir' => 'ltr'],
				$content->getText())
			: '');
		$output->setOutputFlag(ParserOutputFlags::NO_TOC);
		$output->setSections([]);
	}
}
